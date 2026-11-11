<?php

namespace Modules\Visits\Services;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\VisitGuard;
use Modules\Catalog\Models\Provider;
use Modules\Visits\Models\VisitRecord;

/**
 * Port of front visit_monitoring_service.ts: read-only projections for the executive director and healthcare manager.
 * Everything is derived from actual visit records via VisitProgress; requests without a plan/approved count are never
 * given invented numbers.
 */
class VisitMonitoringService
{
    /** Monitored population (a completed request is kept until closed out so remaining visits stay visible). */
    public const MONITORED = ['assigned', 'scheduled', 'in_progress', 'completed'];

    public const ACTIVE = ['assigned', 'scheduled', 'in_progress'];

    /** A request with remaining visits and no activity for this long is flagged as not progressing. */
    public const NO_PROGRESS_DAYS = 7;

    public function __construct(private PermissionResolver $perms, private CaseAccess $access) {}

    public function canMonitor(?User $u): bool
    {
        return $u !== null && $u->isStaff() && $this->perms->has($u, 'visits.monitor') && $this->perms->has($u, 'cases.view');
    }

    private static function stateOf(array $p, $visits): string
    {
        return match (true) {
            $p['done'] => 'اكتملت الزيارات',
            $p['overdue'] > 0 => 'متأخرة',
            $visits->isEmpty() => 'لم تبدأ',
            default => 'قيد التنفيذ',
        };
    }

    private static function attentionOf(RahaCase $c, array $p, string $lastActivityAt, int $now): array
    {
        $out = [];
        if ($p['done']) {
            return $out;
        }
        $active = in_array($c->status, self::ACTIVE, true);
        if ($p['overdue'] > 0) {
            $out[] = "{$p['overdue']} زيارة متأخرة";
        }
        if ($p['missed'] > 0) {
            $out[] = "{$p['missed']} زيارة لم تتم";
        }
        if ($p['remaining'] > 0 && $p['scheduled'] === 0 && $active) {
            $out[] = 'لا توجد زيارات مجدولة';
        }
        if ($p['remaining'] > 0 && $active && $now - Carbon::parse($lastActivityAt)->getTimestampMs() > self::NO_PROGRESS_DAYS * 86400000) {
            $out[] = 'لا تقدم منذ أكثر من '.self::NO_PROGRESS_DAYS.' أيام';
        }
        if ($c->status === 'completed' && $p['remaining'] > 0) {
            $out[] = ! empty($c->visit_plan['reviewFlag']) ? 'زِيد العدد المعتمد بعد إكمال الخدمة — يلزم مراجعة' : 'اعتُبرت الخدمة مكتملة مع زيارات متبقية';
        }

        return array_values(array_unique($out));
    }

    /** Compact case summary carried by each row (the monitoring screens only read these fields). */
    public static function caseSummary(RahaCase $c): array
    {
        return array_filter([
            'id' => $c->id, 'beneficiary' => $c->beneficiary, 'beneficiaryId' => $c->beneficiary_id, 'service' => $c->service, 'serviceId' => $c->service_id,
            'city' => $c->city, 'status' => $c->status, 'stage' => $c->stage, 'provider' => $c->provider, 'providerId' => $c->provider_id,
            'visitPlan' => $c->visit_plan, 'createdAt' => iso($c->created_at), 'updatedAt' => iso($c->updated_at),
        ], fn ($v) => $v !== null);
    }

    /** @return list<array> rows with internal `_visits` (models) for filtering; use serialize() for output. */
    public function caseRows(?User $u, ?int $now = null): array
    {
        if (! $this->canMonitor($u)) {
            return [];
        }
        $now ??= VisitProgress::nowMs();
        $cases = RahaCase::query()->with('tasks')->whereNotNull('provider_id')->whereIn('status', self::MONITORED)->whereNotNull('visit_plan')->orderBy('id')->get()
            ->filter(fn (RahaCase $c) => ($c->visit_plan['deliveryType'] ?? null) === 'multiple_visits' && VisitGuard::isPositiveInt($c->visit_plan['approvedVisitCount'] ?? null))
            ->filter(fn (RahaCase $c) => $this->access->canSeeVisitCase($c, $u))->values();
        $ids = $cases->pluck('id')->all();
        $visitsBy = VisitRecord::query()->whereIn('case_id', $ids)->orderBy('sequence')->get()->groupBy('case_id');
        $assignments = ServiceAssignment::query()->whereIn('case_id', $ids)->get();
        $providers = Provider::query()->whereIn('id', $cases->pluck('provider_id')->unique()->all())->pluck('name', 'id');

        return $cases->map(function (RahaCase $c) use ($now, $visitsBy, $assignments, $providers) {
            $visits = $visitsBy->get($c->id, collect())->values();
            $progress = VisitProgress::compute($c->visit_plan['approvedVisitCount'], $visits, $now);
            $assignedAt = $assignments->first(fn ($a) => $a->case_id === $c->id && $a->provider_id === $c->provider_id)?->assigned_at ?? $c->assigned_at ?? $c->updated_at;
            $assignedAt = iso($assignedAt);
            $last = collect([$assignedAt])->merge($visits->map(fn (VisitRecord $v) => iso($v->updated_at)))->filter()->sort()->last() ?? $assignedAt;

            return [
                'c' => self::caseSummary($c), 'caseId' => $c->id, 'providerId' => $c->provider_id,
                'providerName' => $c->provider ?: ($providers[$c->provider_id] ?? '—'), 'progress' => $progress, '_visits' => $visits,
                'active' => in_array($c->status, self::ACTIVE, true), 'state' => self::stateOf($progress, $visits),
                'attention' => self::attentionOf($c, $progress, $last, $now), 'lastActivityAt' => $last, '_case' => $c,
            ];
        })->all();
    }

    /** Provider/service/search filters at request level, from/to/status at visit level (by scheduled date). */
    public static function filter(array $rows, array $f, ?int $now = null): array
    {
        $now ??= VisitProgress::nowMs();
        $q = trim((string) ($f['q'] ?? ''));
        $base = array_values(array_filter($rows, fn ($r) => (empty($f['providerId']) || $r['providerId'] === $f['providerId'])
            && (empty($f['serviceId']) || ($r['_case']->visit_plan['serviceId'] ?? null) === $f['serviceId'])
            && ($q === '' || str_contains(mb_strtolower("{$r['caseId']} {$r['_case']->beneficiary} {$r['_case']->service} {$r['providerName']}"), mb_strtolower($q)))));
        $visitFilter = ! empty($f['from']) || ! empty($f['to']) || ! empty($f['status']);
        $fromMs = ! empty($f['from']) ? Carbon::parse($f['from'].'T00:00:00')->getTimestampMs() : null;
        $toMs = ! empty($f['to']) ? Carbon::parse($f['to'].'T23:59:59.999')->getTimestampMs() : null;
        $inRange = function (VisitRecord $v) use ($fromMs, $toMs, $f, $now) {
            $t = $v->scheduled_at->getTimestampMs();

            return ($fromMs === null || $t >= $fromMs) && ($toMs === null || $t <= $toMs)
                && (empty($f['status']) || ($f['status'] === 'overdue' ? VisitProgress::isOverdue($v, $now) : $v->status === $f['status']));
        };
        $visits = [];
        foreach ($base as $r) {
            foreach ($r['_visits']->filter($inRange) as $v) {
                $visits[] = ['v' => $v, 'caseId' => $r['caseId']];
            }
        }
        $caseIds = array_unique(array_column($visits, 'caseId'));

        return ['cases' => $visitFilter ? array_values(array_filter($base, fn ($r) => in_array($r['caseId'], $caseIds, true))) : $base, 'visits' => $visits];
    }

    public static function totals(array $rows): array
    {
        $sum = fn (string $k) => array_sum(array_map(fn ($r) => $r['progress'][$k], $rows));

        return [
            'activeCases' => count(array_filter($rows, fn ($r) => $r['active'])), 'approved' => $sum('approved'), 'completed' => $sum('completed'),
            'remaining' => $sum('remaining'), 'overdue' => $sum('overdue'), 'attentionCases' => count(array_filter($rows, fn ($r) => $r['attention'] !== [])),
        ];
    }

    public static function providerPerformance(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $p = $by[$r['providerId']] ?? ['providerId' => $r['providerId'], 'providerName' => $r['providerName'], 'cases' => 0, 'approved' => 0, 'completed' => 0, 'remaining' => 0, 'overdue' => 0, 'missed' => 0, 'percent' => 0];
            $p['cases']++;
            foreach (['approved', 'completed', 'remaining', 'overdue', 'missed'] as $k) {
                $p[$k] += $r['progress'][$k];
            }
            $by[$r['providerId']] = $p;
        }
        $out = array_map(fn ($p) => ['percent' => $p['approved'] ? VisitProgress::num(round(($p['completed'] / $p['approved']) * 1000) / 10) : 0] + $p, array_values($by));
        usort($out, fn ($a, $b) => ($b['overdue'] <=> $a['overdue']) ?: ($a['percent'] <=> $b['percent']));

        return $out;
    }

    public static function serializeRow(array $r): array
    {
        $out = $r;
        unset($out['_case'], $out['_visits']);
        $out['visits'] = $r['_visits']->map->toFront()->values()->all();

        return $out;
    }

    /** GET monitoring/visits payload. Totals cover every monitored request; the rest follows the filters (front VisitMonitoringPanel). */
    public function report(User $u, array $f): array
    {
        $rows = $this->caseRows($u);
        ['cases' => $cases, 'visits' => $visits] = self::filter($rows, $f);

        return [
            'rows' => array_map([self::class, 'serializeRow'], $cases),
            'visits' => array_map(fn ($x) => ['v' => $x['v']->toFront(), 'caseId' => $x['caseId']], $visits),
            'totals' => self::totals($rows), 'filteredTotals' => self::totals($cases), 'providerPerformance' => self::providerPerformance($cases),
        ];
    }
}
