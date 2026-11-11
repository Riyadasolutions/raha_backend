<?php

namespace Modules\Catalog\Services;

use Modules\Catalog\Models\Service;

/** Port of front service_delivery.ts: validates and normalizes service delivery settings (no storage, no permissions). */
class ServiceDelivery
{
    public const LABELS = ['one_time' => 'خدمة لمرة واحدة', 'multiple_visits' => 'خدمة متعددة الزيارات'];

    public const MULTI_VISIT_NOTICE = 'قد تتطلب هذه الخدمة عدة جلسات أو زيارات. يحدد الفريق المختص العدد المعتمد بعد مراجعة الطلب، ولا يُطلب منك تحديده.';

    public const VISIT_COUNT_REQUIRED_MSG = 'يجب تحديد عدد الزيارات المعتمد للخدمة متعددة الزيارات قبل إصدار خطاب التعميد.';

    public const ONE_TIME_NOTICE = 'تُنفَّذ هذه الخدمة مرة واحدة.';

    public static function isPositiveInt(mixed $n): bool
    {
        return (is_int($n) || (is_float($n) && floor($n) === $n)) && $n > 0;
    }

    public static function isDeliveryType(mixed $v): bool
    {
        return $v === 'one_time' || $v === 'multiple_visits';
    }

    /**
     * Legacy services without the field are read as one_time; `explicit` tells callers whether it was ever configured.
     *
     * @return array{deliveryType: string, defaultVisitCount?: int, explicit: bool}
     */
    public static function normalize(Service|array|null $s): array
    {
        $type = $s instanceof Service ? $s->delivery_type : ($s['deliveryType'] ?? null);
        $count = $s instanceof Service ? $s->default_visit_count : ($s['defaultVisitCount'] ?? null);
        $explicit = self::isDeliveryType($type);
        $deliveryType = $explicit ? $type : 'one_time';

        return $deliveryType === 'multiple_visits' && self::isPositiveInt($count)
            ? ['deliveryType' => $deliveryType, 'defaultVisitCount' => (int) $count, 'explicit' => $explicit]
            : ['deliveryType' => $deliveryType, 'explicit' => $explicit];
    }

    /** Returns an error message, or null. Absent keys and nulls mean "not provided" (JS undefined). */
    public static function validate(array $input): ?string
    {
        $hasType = array_key_exists('deliveryType', $input) && $input['deliveryType'] !== null;
        if ($hasType && ! self::isDeliveryType($input['deliveryType'])) {
            return 'نوع تنفيذ الخدمة غير صحيح.';
        }
        $count = $input['defaultVisitCount'] ?? null;
        if ($count === null) {
            return null;
        }
        if (($input['deliveryType'] ?? null) !== 'multiple_visits') {
            return 'عدد الزيارات الافتراضي يخص الخدمات متعددة الزيارات فقط.';
        }

        return self::isPositiveInt($count) ? null : 'عدد الزيارات الافتراضي يجب أن يكون عددًا صحيحًا موجبًا.';
    }

    /** Drops obsolete recurring settings when a service is (or becomes) one-time. Works on a snake_case attribute array. */
    public static function sanitize(array $attributes): array
    {
        if (($attributes['delivery_type'] ?? null) === 'one_time') {
            $attributes['default_visit_count'] = null;
        }

        return $attributes;
    }

    /** Per-request snapshot taken at submission (front snapshotVisitPlan). */
    public static function snapshotVisitPlan(?Service $service, string $serviceName, string $at): array
    {
        $d = self::normalize($service);

        return array_filter([
            'deliveryType' => $d['deliveryType'], 'serviceId' => $service?->id, 'serviceName' => $serviceName,
            'suggestedVisitCount' => $d['defaultVisitCount'] ?? null, 'snapshotAt' => $at,
        ], fn ($v) => $v !== null) + ['changes' => []];
    }
}
