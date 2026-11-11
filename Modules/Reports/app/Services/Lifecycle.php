<?php

namespace Modules\Reports\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\WorkflowConfig;
use Modules\Finance\Models\FinanceEvent;

/**
 * Port of front report_lifecycle_service.ts: stage spans built ONLY from recorded transitions (activities), tasks with a
 * matching transition and finance events. Missing history is never bridged or inferred from `updatedAt`.
 * Finance events store stage CODES (from_stage/to_stage); spans carry the Arabic label (finance.financeStageLabels).
 */
final class Lifecycle
{
    public const FINANCE_SOURCE = 'سجل مالي';

    public static function ms(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if ($v instanceof CarbonInterface) {
            return (int) $v->getTimestampMs();
        }
        try {
            return (int) Carbon::parse((string) $v)->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isoMs(?int $ms): ?string
    {
        return $ms === null ? null : iso(Carbon::createFromTimestampMs($ms, 'UTC'));
    }

    public static function hoursBetween(mixed $a, mixed $b): ?float
    {
        $x = self::ms($a);
        $y = self::ms($b);

        return $x !== null && $y !== null && $y >= $x ? ($y - $x) / 3600000 : null;
    }

    public static function reportStage(string $status): string
    {
        return match ($status) {
            'returned' => 'بانتظار تصحيح المستفيد', 'rejected' => 'مرفوضة', 'awaiting_quotation' => 'بانتظار عروض الأسعار',
            default => raha('workflow.workflowSteps.'.(raha("workflow.stepIndex.$status") ?? -1)) ?? $status,
        };
    }

    public static function departmentOf(string $s): ?string
    {
        return [
            'registration' => 'التسجيل', 'new' => 'التدقيق', 'medical_review' => 'اللجنة الطبية', 'awaiting_quotation' => 'اللجنة الطبية', 'social_review' => 'البحث الاجتماعي',
            'manager_approval' => 'مدير الرعاية الصحية', 'preliminary_approved' => 'الاعتمادات', 'assigned' => 'مزودي الخدمة', 'scheduled' => 'مزودي الخدمة', 'in_progress' => 'مزودي الخدمة',
            'completed' => 'علاقات المرضى', 'finance_review' => 'المالية', 'archived' => 'الأرشفة', 'returned' => 'المستفيد', 'rejected' => 'مرفوضة',
        ][$s] ?? null;
    }

    public static function mean(array $values): ?float
    {
        return $values ? array_sum($values) / count($values) : null;
    }

    /** Front duration(): null → 'غير متوفر', else rounded to 2 decimals (whole numbers as ints, like JS numbers). */
    public static function duration(?float $v): string|int|float
    {
        if ($v === null) {
            return 'غير متوفر';
        }

        return self::num(round($v, 2));
    }

    public static function num(float|int $v): float|int
    {
        return is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX ? (int) $v : $v;
    }

    /** @return Collection<int, CaseActivity> transitions sorted ascending by time (stable) */
    private static function transitions(RahaCase $c): Collection
    {
        return $c->activities->filter(fn (CaseActivity $a) => $a->to_stage && $a->to_stage !== $a->from_stage && $a->at)
            ->sortBy(fn (CaseActivity $a) => $a->at->getTimestampMs())->values();
    }

    /**
     * @param  Collection<int, FinanceEvent>|null  $financeEvents  the case's events (preloaded); fetched when null
     * @return list<array{caseId: string, stage: string, status: string, enteredAt: int, exitedAt: ?int, hours: ?float, current: bool, source: string}>
     */
    public static function lifecycle(RahaCase $c, int $now, ?Collection $financeEvents = null): array
    {
        $events = self::transitions($c)->all();
        $unique = [];
        foreach ($events as $i => $a) {
            $dup = false;
            for ($j = 0; $j < $i; $j++) {
                $b = $events[$j];
                if ($a->at->getTimestampMs() === $b->at->getTimestampMs() && $a->from_stage === $b->from_stage && $a->to_stage === $b->to_stage) {
                    $dup = true;
                    break;
                }
            }
            if (! $dup) {
                $unique[] = $a;
            }
        }
        $spans = [];
        $created = self::ms($c->created_at);
        $submit = collect($unique)->first(fn (CaseActivity $a) => $a->action === 'submit');
        if ($submit && ($h = self::hoursBetween($created, $submit->at->getTimestampMs())) !== null) {
            $spans[] = ['caseId' => $c->id, 'stage' => 'التسجيل', 'status' => 'registration', 'enteredAt' => $created, 'exitedAt' => $submit->at->getTimestampMs(), 'hours' => $h, 'current' => false, 'source' => 'تسجيل الطلب'];
        }
        foreach ($unique as $i => $e) {
            $status = $e->to_stage;
            $next = $unique[$i + 1] ?? null;
            // Only a matching subsequent transition proves the exit of this stage.
            $exit = $next && $next->from_stage === $status ? $next->at->getTimestampMs() : null;
            $current = ! $next && $status === $c->status && ! in_array($status, ['archived', 'rejected'], true);
            $at = $e->at->getTimestampMs();
            $spans[] = ['caseId' => $c->id, 'stage' => self::reportStage($status), 'status' => $status, 'enteredAt' => $at, 'exitedAt' => $exit,
                'hours' => $exit !== null ? self::hoursBetween($at, $exit) : ($current ? self::hoursBetween($at, $now) : null), 'current' => $current, 'source' => 'سجل سير العمل'];
        }
        foreach ($c->tasks as $t) {
            /** @var CaseTask $t */
            $tc = self::ms($t->created_at);
            // Legacy task creation may have been initialized from updatedAt, not stage entry.
            if ($tc === null || ! $c->activities->contains(fn (CaseActivity $a) => $a->to_stage === $t->stage && $a->from_stage !== $t->stage && $a->at && $a->at->getTimestampMs() === $tc)) {
                continue;
            }
            if (collect($spans)->contains(fn ($s) => $s['status'] === $t->stage && $s['enteredAt'] === $tc)) {
                continue;
            }
            $current = $t->stage === $c->status && in_array($t->status, ['new', 'in_progress'], true) && ! collect($spans)->contains(fn ($s) => $s['current']);
            $done = self::ms($t->completed_at);
            if ($done === null && ! $current) {
                continue;
            }
            $end = $done ?? $now;
            // Stored tasks provide their own interval, never the missing earlier workflow.
            if (collect($spans)->contains(fn ($s) => $s['status'] === $t->stage && $s['hours'] !== null && $s['enteredAt'] <= $tc && ($s['exitedAt'] === null || $s['exitedAt'] >= $end))) {
                continue;
            }
            $spans[] = ['caseId' => $c->id, 'stage' => self::reportStage($t->stage), 'status' => $t->stage, 'enteredAt' => $tc, 'exitedAt' => $done,
                'hours' => self::hoursBetween($tc, $end), 'current' => $current, 'source' => 'سجل المهمة'];
        }
        // Financial substage entry/exit is stored separately as stage codes; no parsing of free-text decisions.
        $fe = ($financeEvents ?? FinanceEvent::query()->where('case_id', $c->id)->get())
            ->filter(fn (FinanceEvent $e) => $e->to_stage && $e->from_stage !== $e->to_stage && $e->at)
            ->sortBy(fn (FinanceEvent $e) => $e->at->getTimestampMs())->values();
        foreach ($fe as $i => $e) {
            $next = $fe[$i + 1] ?? null;
            $at = $e->at->getTimestampMs();
            $exit = $next && $next->from_stage === $e->to_stage ? $next->at->getTimestampMs() : null;
            $current = ! $next && $c->status === 'preliminary_approved' && ! empty($c->finance) && ($c->finance['stage'] ?? null) === $e->to_stage;
            $spans[] = ['caseId' => $c->id, 'stage' => raha("finance.financeStageLabels.{$e->to_stage}", $e->to_stage), 'status' => 'preliminary_approved', 'enteredAt' => $at, 'exitedAt' => $exit,
                'hours' => $exit !== null ? self::hoursBetween($at, $exit) : ($current ? self::hoursBetween($at, $now) : null), 'current' => false,
                'source' => $current ? 'مدة جارية — '.self::FINANCE_SOURCE : self::FINANCE_SOURCE];
        }
        usort($spans, fn ($a, $b) => $a['enteredAt'] <=> $b['enteredAt']);

        return $spans;
    }

    /** Complete duration only from an actual archive event. @return array{at: int, hours: float}|null */
    public static function completion(RahaCase $c): ?array
    {
        if ($c->status !== 'archived') {
            return null;
        }
        $terminal = $c->activities->filter(fn (CaseActivity $a) => $a->to_stage === 'archived' && $a->from_stage !== 'archived' && $a->at)
            ->sortByDesc(fn (CaseActivity $a) => $a->at->getTimestampMs())->first();
        if (! $terminal) {
            return null;
        }
        $h = self::hoursBetween($c->created_at, $terminal->at);

        return $h === null ? null : ['at' => $terminal->at->getTimestampMs(), 'hours' => $h];
    }

    /** Delay only against an actual deadline (task due date, quotation deadline or configured SLA). */
    public static function delay(RahaCase $c, array $spans, mixed $quotationDeadline, int $now): array
    {
        if (in_array($c->status, ['archived', 'rejected'], true)) {
            return ['delayed' => false, 'deadline' => null, 'elapsed' => null];
        }
        $current = null;
        foreach ($spans as $s) {
            if ($s['current']) {
                $current = $s;
            }
        }
        $task = $c->tasks->first(fn (CaseTask $t) => $t->stage === $c->status && in_array($t->status, ['new', 'in_progress'], true));
        $sla = WorkflowConfig::slaHours($c->status);
        $deadline = self::ms($task?->due_at)
            ?? ($c->status === 'awaiting_quotation' ? self::ms($quotationDeadline) : null)
            ?? ($current && $sla !== null ? $current['enteredAt'] + $sla * 3600000 : null);

        return ['delayed' => $deadline !== null && $deadline < $now, 'deadline' => self::isoMs($deadline), 'elapsed' => $current['hours'] ?? null];
    }
}
