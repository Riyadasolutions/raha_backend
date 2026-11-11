<?php

namespace Modules\Visits\Services;

use Illuminate\Support\Carbon;
use Modules\Visits\Models\VisitRecord;

/**
 * Port of front visit_progress.ts: the ONLY place visit counts are derived (executive, manager, provider and
 * care monitoring all call this). Pure, no writes. "Overdue" is derived (scheduled + scheduledAt in the past).
 */
final class VisitProgress
{
    public const STATUS_LABELS = ['scheduled' => 'مجدولة', 'completed' => 'مكتملة', 'missed' => 'لم تتم', 'cancelled' => 'ملغاة'];

    public static function nowMs(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }

    public static function isOverdue(VisitRecord $v, ?int $now = null): bool
    {
        return $v->status === 'scheduled' && $v->scheduled_at->getTimestampMs() < ($now ?? self::nowMs());
    }

    public static function isActive(string $status): bool
    {
        return $status === 'scheduled' || $status === 'completed';
    }

    /** Display label: a scheduled visit past its time is shown as delayed, distinct from a missed one. */
    public static function displayStatus(VisitRecord $v, ?int $now = null): string
    {
        return self::isOverdue($v, $now) ? 'مجدولة — متأخرة' : self::STATUS_LABELS[$v->status];
    }

    /** @param iterable<VisitRecord> $visits */
    public static function compute(mixed $approved, iterable $visits, ?int $now = null): array
    {
        $now ??= self::nowMs();
        $visits = collect($visits);
        $count = fn (callable $f) => $visits->filter($f)->count();
        $completed = $count(fn (VisitRecord $v) => $v->status === 'completed');
        $scheduled = $count(fn (VisitRecord $v) => $v->status === 'scheduled');
        $overdue = $count(fn (VisitRecord $v) => self::isOverdue($v, $now));
        $valid = is_int($approved) && $approved > 0;
        $next = $visits->filter(fn (VisitRecord $v) => $v->status === 'scheduled' && ! self::isOverdue($v, $now))
            ->map(fn (VisitRecord $v) => iso($v->scheduled_at))->sort()->first();
        $out = [
            'approved' => $valid ? $approved : 0, 'completed' => $completed, 'remaining' => $valid ? max(0, $approved - $completed) : 0,
            'scheduled' => $scheduled, 'upcoming' => $scheduled - $overdue, 'overdue' => $overdue,
            'missed' => $count(fn (VisitRecord $v) => $v->status === 'missed'), 'cancelled' => $count(fn (VisitRecord $v) => $v->status === 'cancelled'),
            'percent' => $valid ? self::num(min(100, round(($completed / $approved) * 1000) / 10)) : 0, 'done' => $valid && $completed >= $approved,
        ];
        if ($next) {
            $out['nextVisitAt'] = $next;
        }

        return $out;
    }

    /** JS-number friendly: whole floats become ints so JSON shows 100, not 100.0. */
    public static function num(float|int $v): float|int
    {
        return is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX ? (int) $v : $v;
    }
}
