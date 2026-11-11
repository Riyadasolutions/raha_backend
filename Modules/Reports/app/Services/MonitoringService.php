<?php

namespace Modules\Reports\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseProjector;
use Modules\Cases\Services\WorkflowConfig;
use Modules\Catalog\Models\Provider;
use Modules\Quotations\Models\QuotationRound;
use Modules\Visits\Services\VisitService;

/**
 * Ports of front care_monitoring_service.ts (healthcare manager) and executive_monitoring_service.ts (executive director).
 * Read-only views over the cases the user can already see (CaseAccess::visibleCases).
 */
class MonitoringService
{
    public const EXECUTION_STATUSES = ['assigned', 'scheduled', 'in_progress'];

    public const PROVIDER_ACTIONS = ['provider_accept', 'schedule', 'start_service', 'complete_service'];

    public const SECTIONS = ['all', 'provider', 'internal', 'active-internal', 'final', 'completed', 'delayed'];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseProjector $projector) {}

    private function staffCanRead(?User $u): bool
    {
        return $u !== null && $u->isStaff() && $this->perms->has($u, 'cases.view');
    }

    // ---- Healthcare manager (care monitoring) ----

    public function approvals(User $u): Collection
    {
        if (! $this->staffCanRead($u) || ! $this->perms->has($u, 'cases.approve')) {
            throw DomainRuleException::forbidden();
        }

        return $this->access->visibleCases($u, true)->filter(fn (RahaCase $c) => $c->status === 'manager_approval')->values();
    }

    public function execution(User $u): Collection
    {
        if (! $this->staffCanRead($u)) {
            throw DomainRuleException::forbidden();
        }

        return $this->access->visibleCases($u, true)->filter(fn (RahaCase $c) => $c->provider_id && in_array($c->status, self::EXECUTION_STATUSES, true))->values();
    }

    public function getExecution(string $id, User $u): RahaCase
    {
        return $this->execution($u)->firstWhere('id', $id) ?? throw DomainRuleException::notFound('الحالة غير متاحة في متابعة التنفيذ');
    }

    /**
     * Execution progress. Session counts come only from actual visit records of a multiple-visit plan with an approved
     * count; otherwise they stay null (never inferred from appointments or authorized quantities).
     */
    public static function progress(RahaCase $c, bool $withActivities = true): array
    {
        $authId = $c->finance['authorizationId'] ?? null;
        $assignment = ServiceAssignment::query()->where('case_id', $c->id)->where('provider_id', $c->provider_id)
            ->when($authId, fn ($q) => $q->where('authorization_id', $authId))->orderByDesc('assigned_at')->first();
        $letter = $assignment ? ServiceAuthorization::query()->find($assignment->authorization_id) : null;
        $visits = VisitService::completionState($c);
        $out = [
            'providerName' => $c->provider ?: ($c->provider_id ? Provider::query()->whereKey($c->provider_id)->value('name') : null),
            'appointment' => $c->appointment, 'expectedStart' => $assignment?->expected_start, 'quantity' => $assignment?->quantity, 'unit' => $letter?->unit,
            'scope' => $assignment?->scope, 'completedSessions' => $visits['completed'] ?? null, 'remainingSessions' => $visits['remaining'] ?? null,
            'visitProgress' => $visits, 'updatedAt' => iso($c->updated_at),
        ];
        if ($withActivities) {
            $out['activities'] = $c->activities->filter(fn (CaseActivity $a) => in_array($a->action, self::PROVIDER_ACTIONS, true))
                ->sortBy(fn (CaseActivity $a) => $a->at?->getTimestampMs())->map->toFront()->values()->all();
        }

        return $out;
    }

    public function careRow(RahaCase $c, User $u, bool $detail = false): array
    {
        return $this->projector->forStaff($c, $u, ! $detail) + ['monitor' => self::progress($c, $detail)];
    }

    // ---- Executive director ----

    public function canMonitorExecutive(?User $u): bool
    {
        return $u !== null && $u->isStaff() && $this->perms->has($u, 'executive.approvals.view') && $this->perms->has($u, 'cases.view');
    }

    public static function withProvider(RahaCase $c): bool
    {
        return match (true) {
            $c->status === 'awaiting_quotation' => true,
            $c->status === 'preliminary_approved' => ($c->finance['stage'] ?? null) === 'authorization_issued',
            default => (bool) $c->provider_id && (in_array($c->status, self::EXECUTION_STATUSES, true) || ($c->status === 'completed' && ! $c->service_confirmed)),
        };
    }

    /** Stage entry from the finance activity / active task / last transition; deadline from the task, SLA or open quotation round. */
    public static function stageTiming(RahaCase $c, ?int $at = null, ?QuotationRound $openRound = null): array
    {
        $at ??= (int) Carbon::now()->getTimestampMs();
        $task = $c->activeTask();
        $transitions = $c->activities->filter(fn (CaseActivity $a) => $a->to_stage === $c->status && $a->from_stage !== $a->to_stage && $a->at)->map(fn ($a) => $a->at->getTimestampMs())->sort()->values();
        $finance = $c->status === 'preliminary_approved' ? $c->activities->filter(fn (CaseActivity $a) => $a->action === 'finance' && $a->at)->map(fn ($a) => $a->at->getTimestampMs())->sort()->values() : collect();
        $entered = $finance->last() ?? Lifecycle::ms($task?->created_at) ?? $transitions->last();
        $sla = WorkflowConfig::slaHours($c->status);
        if ($c->status === 'awaiting_quotation' && ! $openRound) {
            $openRound = QuotationRound::query()->where('case_id', $c->id)->where('status', 'open')->first();
        }
        $deadline = Lifecycle::ms($task?->due_at) ?? ($entered !== null && $sla !== null ? $entered + $sla * 3600000 : null)
            ?? ($c->status === 'awaiting_quotation' ? Lifecycle::ms($openRound?->deadline) : null);

        return [
            'elapsedHours' => $entered !== null ? max(0, intdiv($at - $entered, 3600000)) : null, 'enteredAt' => Lifecycle::isoMs($entered),
            'deadline' => Lifecycle::isoMs($deadline), 'delayed' => $deadline !== null && $deadline < $at,
        ];
    }

    public static function stageLabel(RahaCase $c): string
    {
        return $c->status === 'preliminary_approved' && ! empty($c->finance)
            ? raha('finance.financeStageLabels.'.($c->finance['stage'] ?? ''), $c->finance['stage'] ?? '')
            : WorkflowConfig::currentStageLabel($c->status, (string) $c->stage);
    }

    /** Provider names (case → preferred quotation → provider record → latest round invitations) and last provider activity. */
    public static function providerInfo(RahaCase $c, Collection $rounds, Collection $assignments): array
    {
        $rounds = $rounds->filter(fn (QuotationRound $r) => $r->status !== 'cancelled')->sortByDesc(fn ($r) => $r->created_at?->getTimestampMs())->values();
        $latest = $rounds->first();
        $names = $c->provider ?? ($c->preferred_quotation['providerName'] ?? null) ?? ($c->provider_id ? Provider::query()->whereKey($c->provider_id)->value('name') : null)
            ?? ($latest ? $latest->invitations->pluck('provider_name')->join('، ') : null);
        $updates = collect();
        foreach ($rounds as $r) {
            $updates = $updates->merge($r->invitations->map(fn ($i) => Lifecycle::ms($i->responded_at)))->merge($r->quotations->map(fn ($q) => Lifecycle::ms($q->submitted_at)));
        }
        $updates = $updates->merge($assignments->map(fn ($a) => Lifecycle::ms($a->acknowledged_at)))
            ->merge($c->activities->filter(fn ($a) => in_array($a->action, self::PROVIDER_ACTIONS, true))->map(fn ($a) => Lifecycle::ms($a->at)))->filter(fn ($v) => $v !== null)->sort();
        $status = match ($c->status) {
            'awaiting_quotation' => $latest && $latest->quotations->contains(fn ($q) => $q->status === 'submitted') ? 'عروض مستلمة بانتظار الاختيار' : 'بانتظار عروض الأسعار',
            'completed' => 'مكتمل بانتظار التحقق',
            default => self::stageLabel($c),
        };

        return array_filter(['names' => $names !== '' ? $names : null, 'updatedAt' => Lifecycle::isoMs($updates->last()), 'status' => $status], fn ($v) => $v !== null);
    }

    public static function inSection(RahaCase $c, string $section, array $timing): bool
    {
        return match ($section) {
            'provider' => self::withProvider($c),
            'internal' => ! self::withProvider($c),
            'active-internal' => ! self::withProvider($c) && ! in_array($c->status, ['archived', 'rejected'], true),
            'final' => ($c->finance['stage'] ?? null) === 'pending_executive',
            'completed' => in_array($c->status, ['completed', 'finance_review', 'archived'], true),
            'delayed' => $timing['delayed'],
            default => true,
        };
    }

    /** GET monitoring/executive: the section's rows (light staff case + monitor info) plus every section count from the same list. */
    public function executive(User $u, string $section): array
    {
        if (! $this->canMonitorExecutive($u)) {
            throw DomainRuleException::forbidden();
        }
        $cases = $this->access->visibleCases($u, true);
        $ids = $cases->pluck('id')->all();
        $rounds = QuotationRound::query()->with(['invitations', 'quotations'])->whereIn('case_id', $ids)->get()->groupBy('case_id');
        $assignments = ServiceAssignment::query()->whereIn('case_id', $ids)->get()->groupBy('case_id');
        $now = (int) Carbon::now()->getTimestampMs();
        $timings = $cases->mapWithKeys(fn (RahaCase $c) => [$c->id => self::stageTiming($c, $now, $rounds->get($c->id)?->firstWhere('status', 'open'))]);
        $counts = [];
        foreach (self::SECTIONS as $s) {
            $counts[$s] = $cases->filter(fn ($c) => self::inSection($c, $s, $timings[$c->id]))->count();
        }
        $rows = $cases->filter(fn ($c) => self::inSection($c, $section, $timings[$c->id]))->map(fn (RahaCase $c) => $this->projector->forStaff($c, $u, true) + ['monitor' => [
            'stage' => self::stageLabel($c), 'stageTiming' => $timings[$c->id],
            'provider' => self::providerInfo($c, $rounds->get($c->id, collect()), $assignments->get($c->id, collect())),
        ]])->values()->all();

        return ['rows' => $rows, 'counts' => $counts];
    }
}
