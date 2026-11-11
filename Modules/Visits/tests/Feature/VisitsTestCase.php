<?php

namespace Modules\Visits\Tests\Feature;

use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Cases\Models\RahaCase;
use Modules\Visits\Models\VisitRecord;
use Tests\TestCase;

/** Fixtures: a multiple-visit request in execution with PRV-0005 (u10) and an acknowledged assignment. */
abstract class VisitsTestCase extends TestCase
{
    protected const ID = 'REQ-000119';

    protected const ID2 = 'REQ-000114';

    protected const DAY = 86400;

    protected function iso(int $offsetSeconds): string
    {
        return now()->addSeconds($offsetSeconds)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    protected function visitCase(string $id = self::ID, array $extra = [], ?int $approved = 4, string $provider = 'PRV-0005', string $assignment = 'ACKNOWLEDGED'): void
    {
        $plan = ['deliveryType' => 'multiple_visits', 'serviceId' => 'SRV-002', 'serviceName' => 'علاج طبيعي منزلي', 'snapshotAt' => $this->iso(-10 * self::DAY), 'changes' => []];
        if ($approved) {
            $plan['approvedVisitCount'] = $approved;
        }
        $names = ['PRV-0005' => 'مركز عناية للرعاية المنزلية', 'PRV-0001' => 'مجمع التعافي الطبي'];
        RahaCase::query()->whereKey($id)->update(array_merge([
            'status' => 'in_progress', 'service' => 'علاج طبيعي منزلي', 'service_id' => 'SRV-002', 'provider' => $names[$provider] ?? $provider,
            'provider_id' => $provider, 'provider_accepted' => true, 'visit_plan' => json_encode($plan, JSON_UNESCAPED_UNICODE), 'assigned_at' => now()->subDays(9),
        ], $extra));
        ServiceAssignment::query()->where('case_id', $id)->delete();
        if ($provider && $assignment) {
            ServiceAssignment::query()->create([
                'id' => 'ASG-'.substr($id, -3), 'case_id' => $id, 'provider_id' => $provider, 'authorization_id' => 'AUL-'.substr($id, -3), 'scope' => 'نطاق',
                'quantity' => 4, 'amount_halalas' => 1000, 'assigned_at' => now()->subDays(9), 'expected_start' => '2026-12-01', 'status' => $assignment,
            ]);
        }
    }

    protected function schedule(string $as, string $id, int $offset, ?int $sequence = null)
    {
        return $this->asRole($as)->postJson("/api/v1/cases/$id/visits", array_filter(['scheduledAt' => $this->iso($offset), 'sequence' => $sequence]));
    }

    protected function scheduled(string $as, string $id, int $offset): string
    {
        return $this->schedule($as, $id, $offset)->assertCreated()->json('data.id');
    }

    protected function doneVisit(string $id, int $n): string
    {
        $v = $this->scheduled('provider', $id, -$n * self::DAY);
        $this->asRole('provider')->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-$n * 60)])->assertOk();

        return $v;
    }

    protected function plan(string $id = self::ID): array
    {
        return RahaCase::query()->find($id)->visit_plan;
    }

    protected function visitsCount(string $id = self::ID): int
    {
        return VisitRecord::query()->where('case_id', $id)->count();
    }
}
