<?php

namespace Modules\Cases\Services;

use App\Models\Setting;
use App\Models\User;
use Modules\Cases\Models\RahaCase;

/** Fast Track eligibility (port of front fast_track_service.checkEligibility). Policy: settings key `fast_track`. */
class FastTrackService
{
    public const APPROVED_STATUSES = ['preliminary_approved', 'assigned', 'scheduled', 'in_progress', 'completed', 'finance_review', 'archived'];

    public static function policy(): array
    {
        $seed = raha('fast_track.fastTrackPolicySeed');
        $p = Setting::read('fast_track');
        if (! is_array($p) || (int) ($p['configVersion'] ?? 1) < (int) raha('fast_track.FAST_TRACK_CONFIG_VERSION', 1)) {
            return $seed;
        }

        return $p;
    }

    public static function activeEntryStage(array $policy): ?string
    {
        return ! empty($policy['enabled']) && ! empty($policy['entryStage']) ? $policy['entryStage'] : null;
    }

    public static function skippedStageLabels(string $entry): array
    {
        $stages = raha('fast_track.skippableStages', []);
        $i = collect($stages)->search(fn ($s) => $s['status'] === $entry);

        return array_column(array_slice($stages, 0, $i === false ? count($stages) : $i), 'label');
    }

    private static function no(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'skippedStages' => []];
    }

    /** @return array{eligible: bool, reason: string, previous?: RahaCase, entryStage?: string, skippedStages: string[]} */
    public function checkEligibility(User $user): array
    {
        if (! $user->isBeneficiary() || ! $user->beneficiary_id) {
            return self::no('المسار السريع متاح لحسابات المستفيدين فقط.');
        }
        $policy = self::policy();
        $entry = self::activeEntryStage($policy);
        if (! $entry) {
            return self::no('المسار السريع غير مفعّل أو إعداداته غير مكتملة؛ سيُعامل الطلب بالمسار المعتاد.');
        }
        $previous = RahaCase::query()->where('beneficiary_id', $user->beneficiary_id)->whereIn('status', self::APPROVED_STATUSES)->orderByDesc('created_at')->first();
        if (! empty($policy['requireApprovedPrevious']) && ! $previous) {
            return self::no('لا يوجد طلب سابق معتمد مكتمل لهذا المستفيد؛ سيُعامل الطلب بالمسار المعتاد.');
        }
        if (! $previous) {
            return self::no('لا يوجد طلب سابق لهذا المستفيد؛ سيُعامل الطلب بالمسار المعتاد.');
        }
        $label = collect(raha('fast_track.skippableStages', []))->firstWhere('status', $entry)['label'] ?? 'الاعتماد المبدئي';

        return [
            'eligible' => true, 'previous' => $previous, 'entryStage' => $entry, 'skippedStages' => self::skippedStageLabels($entry),
            'reason' => "طلب متكرر لمستفيد له طلب سابق معتمد ({$previous->id})؛ أُحيل مباشرة إلى مرحلة {$label} وفق سياسة المسار السريع.",
        ];
    }

    /** Public shape for GET portal/fast-track/eligibility (previous request reduced to its id). */
    public function publicEligibility(User $user): array
    {
        $r = $this->checkEligibility($user);
        if (isset($r['previous'])) {
            $r['previousRequestId'] = $r['previous']->id;
            unset($r['previous']);
        }

        return $r;
    }
}
