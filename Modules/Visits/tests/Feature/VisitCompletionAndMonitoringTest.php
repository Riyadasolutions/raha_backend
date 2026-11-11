<?php

namespace Modules\Visits\Tests\Feature;

use Modules\Cases\Models\RahaCase;

/** Port of front visit_completion.test.tsx + the monitoring part of visit_tracking.test.tsx. */
class VisitCompletionAndMonitoringTest extends VisitsTestCase
{
    private const MSG = 'لا يمكن إكمال الخدمة لوجود زيارات معتمدة لم يتم تنفيذها بعد.';

    private function finish()
    {
        return $this->asRole('provider')->postJson('/api/v1/cases/'.self::ID.'/actions/complete_service');
    }

    public function test_complete_service_is_blocked_until_all_approved_visits_are_completed(): void
    {
        $this->visitCase(approved: 2);
        $this->finish()->assertStatus(422)->assertJsonPath('message', self::MSG); // no visit records at all
        $this->doneVisit(self::ID, 1);
        $before = RahaCase::find(self::ID)->toArray();
        $this->finish()->assertStatus(422)->assertJsonPath('message', self::MSG);
        $this->assertSame($before['status'], RahaCase::find(self::ID)->status);
        $this->asRole('manager')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertJsonPath('progress.completed', 1)->assertJsonPath('progress.remaining', 1);
        $this->doneVisit(self::ID, 2);
        $this->finish()->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_scheduled_overdue_missed_and_cancelled_visits_never_count(): void
    {
        $this->visitCase(approved: 2);
        $this->doneVisit(self::ID, 1);
        $overdue = $this->scheduled('provider', self::ID, -self::DAY);
        $this->finish()->assertStatus(422);
        $this->asRole('provider')->postJson("/api/v1/visits/$overdue/miss", ['reason' => 'غياب'])->assertOk();
        $this->finish()->assertStatus(422);
        $extra = $this->scheduled('provider', self::ID, self::DAY);
        $this->asRole('provider')->postJson("/api/v1/visits/$extra/cancel", ['reason' => 'إلغاء'])->assertOk()
            ->assertJsonPath('progress.completed', 1)->assertJsonPath('progress.missed', 1)->assertJsonPath('progress.cancelled', 1)->assertJsonPath('progress.remaining', 1);
        $this->finish()->assertStatus(422)->assertJsonPath('message', self::MSG);
    }

    public function test_one_time_and_historical_requests_complete_normally(): void
    {
        $this->visitCase();
        RahaCase::whereKey(self::ID)->update(['visit_plan' => null]);
        $this->finish()->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_count_raised_after_completion_flags_review_and_surfaces_in_monitoring(): void
    {
        $this->visitCase(approved: 2);
        $this->doneVisit(self::ID, 1);
        $this->doneVisit(self::ID, 2);
        $this->finish()->assertOk();
        $activities = RahaCase::find(self::ID)->activities()->count();
        $this->asRole('manager')->postJson('/api/v1/cases/'.self::ID.'/visit-plan/adjust', ['newCount' => 4, 'reason' => 'توصية إضافية'])->assertOk()
            ->assertJsonPath('data.progress.remaining', 2)->assertJsonPath('data.progress.done', false);
        $c = RahaCase::find(self::ID);
        $this->assertSame('completed', $c->status);
        $this->assertEquals(['from' => 2, 'to' => 4, 'by' => 'خالد الدوسري'], array_intersect_key($c->visit_plan['reviewFlag'], ['from' => 1, 'to' => 1, 'by' => 1]));
        $this->assertSame($activities + 1, $c->activities()->count());
        $row = collect($this->asRole('executive')->getJson('/api/v1/monitoring/visits')->assertOk()->json('data.rows'))->firstWhere('caseId', self::ID);
        $this->assertStringContainsString('يلزم مراجعة', implode(',', $row['attention']));
        // Reduction safeguards still hold.
        $this->asRole('manager')->postJson('/api/v1/cases/'.self::ID.'/visit-plan/adjust', ['newCount' => 1, 'reason' => 'x'])->assertStatus(422);
    }

    public function test_increase_before_completion_is_not_flagged(): void
    {
        $this->visitCase(approved: 2);
        $this->doneVisit(self::ID, 1);
        $this->asRole('manager')->postJson('/api/v1/cases/'.self::ID.'/visit-plan/adjust', ['newCount' => 3, 'reason' => 'x'])->assertOk();
        $this->assertArrayNotHasKey('reviewFlag', $this->plan());
    }

    private function populate(): void
    {
        $this->visitCase(self::ID);
        $this->visitCase(self::ID2, [], 3, 'PRV-0001');
        $this->visitCase('REQ-000113', ['visit_plan' => json_encode(['deliveryType' => 'one_time', 'serviceName' => 'x', 'snapshotAt' => now_iso(), 'changes' => []])]);
        $a1 = $this->scheduled('provider', self::ID, -3 * self::DAY);
        $this->asRole('provider')->postJson("/api/v1/visits/$a1/complete", ['completedAt' => $this->iso(-3 * self::DAY + 3600)])->assertOk();
        $this->scheduled('provider', self::ID, -self::DAY);
        $this->scheduled('provider', self::ID, 2 * self::DAY);
        $b1 = $this->scheduled('provider2', self::ID2, -2 * self::DAY);
        $this->asRole('provider2')->postJson("/api/v1/visits/$b1/miss", ['reason' => 'غياب'])->assertOk();
    }

    public function test_executive_and_manager_see_identical_totals_from_actual_records(): void
    {
        $this->populate();
        foreach (['executive', 'manager'] as $role) {
            $d = $this->asRole($role)->getJson('/api/v1/monitoring/visits')->assertOk()->json('data');
            $ids = array_column($d['rows'], 'caseId');
            sort($ids);
            $this->assertSame([self::ID2, self::ID], $ids, $role);
            $this->assertSame(['activeCases' => 2, 'approved' => 7, 'completed' => 1, 'remaining' => 6, 'overdue' => 1, 'attentionCases' => 2], $d['totals'], $role);
        }
    }

    public function test_attention_reasons_and_provider_performance(): void
    {
        $this->populate();
        $d = $this->asRole('executive')->getJson('/api/v1/monitoring/visits')->json('data');
        $rows = collect($d['rows'])->keyBy('caseId');
        $this->assertStringContainsString('متأخرة', implode(',', $rows[self::ID]['attention']));
        $this->assertStringContainsString('لم تتم', implode(',', $rows[self::ID2]['attention']));
        $this->assertSame('PRV-0005', $d['providerPerformance'][0]['providerId']);
        $this->assertEquals(['approved' => 4, 'completed' => 1, 'overdue' => 1, 'percent' => 25], array_intersect_key($d['providerPerformance'][0], ['approved' => 1, 'completed' => 1, 'overdue' => 1, 'percent' => 1]));
    }

    public function test_flags_a_stalled_request_with_no_scheduled_visits(): void
    {
        $this->visitCase();
        $this->travel(10)->days();
        $row = $this->asRole('executive')->getJson('/api/v1/monitoring/visits')->json('data.rows.0');
        $this->assertSame('لم تبدأ', $row['state']);
        $this->assertStringContainsString('لا تقدم', implode(',', $row['attention']));
        $this->assertStringContainsString('لا توجد زيارات مجدولة', implode(',', $row['attention']));
    }

    public function test_filters_by_provider_service_status_and_dates(): void
    {
        $this->populate();
        $get = fn (array $q) => $this->asRole('executive')->getJson('/api/v1/monitoring/visits?'.http_build_query($q))->assertOk()->json('data');
        $this->assertSame([self::ID2], array_column($get(['providerId' => 'PRV-0001'])['rows'], 'caseId'));
        $this->assertCount(1, $get(['status' => 'overdue'])['visits']);
        $this->assertSame([self::ID2], array_column($get(['status' => 'missed'])['rows'], 'caseId'));
        $d = $get(['from' => now()->toDateString(), 'to' => now()->addDays(3)->toDateString()]);
        $this->assertSame([3], array_map(fn ($x) => $x['v']['sequence'], $d['visits']));
        $this->assertCount(1, $get(['q' => 'REQ-000114'])['rows']);
        $this->assertSame(2, $get(['providerId' => 'PRV-0001'])['totals']['activeCases']); // totals cover the full monitored population
    }

    public function test_monitoring_is_unavailable_without_visits_monitor(): void
    {
        $this->populate();
        foreach (['auditor', 'social'] as $role) {
            $this->asRole($role)->getJson('/api/v1/monitoring/visits')->assertForbidden();
        }
        $this->asRole('provider')->getJson('/api/v1/monitoring/visits')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/monitoring/visits')->assertForbidden();
    }

    public function test_monitoring_reflects_each_visit_change_immediately(): void
    {
        $this->visitCase(approved: 2);
        $totals = fn () => $this->asRole('executive')->getJson('/api/v1/monitoring/visits')->json('data.totals');
        $this->assertSame([0, 2], [$totals()['completed'], $totals()['remaining']]);
        $this->doneVisit(self::ID, 1);
        $this->assertSame([1, 1], [$totals()['completed'], $totals()['remaining']]);
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $this->assertSame(1, $totals()['overdue']);
        $this->asRole('provider')->postJson("/api/v1/visits/$v/miss")->assertOk();
        $this->assertSame(0, $totals()['overdue']);
    }
}
