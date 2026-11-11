<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use Modules\Cases\Models\RahaCase;
use Modules\Visits\Models\VisitRecord;

/** Port of front visit_guard.ts + service_delivery.snapshotVisitPlan. */
class VisitGuard
{
    public const VISITS_INCOMPLETE_MSG = 'لا يمكن إكمال الخدمة لوجود زيارات معتمدة لم يتم تنفيذها بعد.';

    public static function isPositiveInt(mixed $n): bool
    {
        return is_int($n) && $n > 0;
    }

    /** Approved/completed counts for a multiple-visit request with a valid approved count; null otherwise (no blocker). */
    public static function completionProgress(RahaCase $c): ?array
    {
        $p = $c->visit_plan;
        if (! $p || ($p['deliveryType'] ?? null) !== 'multiple_visits' || ! self::isPositiveInt($p['approvedVisitCount'] ?? null)) {
            return null;
        }
        $completed = VisitRecord::query()->where('case_id', $c->id)->where('status', 'completed')->count();

        return ['approved' => $p['approvedVisitCount'], 'completed' => $completed];
    }

    /** Only `completed` visits count; scheduled, overdue, missed and cancelled never do. */
    public static function assertVisitsComplete(RahaCase $c): void
    {
        $p = self::completionProgress($c);
        if ($p && $p['completed'] < $p['approved']) {
            throw new DomainRuleException(self::VISITS_INCOMPLETE_MSG);
        }
    }

    /** Per-request delivery snapshot taken at submission; legacy/unknown services snapshot as one_time. */
    public static function snapshotVisitPlan(?\Modules\Catalog\Models\Service $service, string $serviceName, string $at): array
    {
        $type = in_array($service?->delivery_type, ['one_time', 'multiple_visits'], true) ? $service->delivery_type : 'one_time';
        $plan = ['deliveryType' => $type];
        if ($service) {
            $plan['serviceId'] = $service->id;
        }
        $plan['serviceName'] = $serviceName;
        if ($type === 'multiple_visits' && self::isPositiveInt($service?->default_visit_count)) {
            $plan['suggestedVisitCount'] = $service->default_visit_count;
        }
        $plan['snapshotAt'] = $at;
        $plan['changes'] = [];

        return $plan;
    }
}
