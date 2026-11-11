<?php

namespace Modules\Reports\Tests\Feature;

use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\RahaCase;
use Modules\Reports\Services\MonitoringService;
use Modules\Visits\Models\VisitRecord;

/** Ports of front care_monitoring.test.ts + executive_monitoring.test.ts. */
class MonitoringTest extends ReportsTestCase
{
    private function ids(string $role, string $url): array
    {
        return array_column($this->asRole($role)->getJson($url)->assertOk()->json('data'), 'id');
    }

    public function test_care_approval_section_contains_only_manager_approval_requests(): void
    {
        $this->clearCases();
        $this->make('approval', ['status' => 'manager_approval']);
        $this->make('approved', ['status' => 'preliminary_approved']);
        $this->make('execution', ['status' => 'in_progress', 'provider_id' => 'PRV-0005']);
        $this->assertSame(['approval'], $this->ids('manager', '/api/v1/monitoring/care/approvals'));
        $this->asRole('auditor')->getJson('/api/v1/monitoring/care/approvals')->assertForbidden(); // no cases.approve
    }

    public function test_care_execution_contains_only_ongoing_externally_assigned_cases(): void
    {
        $this->clearCases();
        foreach (['assigned', 'scheduled', 'in_progress', 'completed', 'archived', 'manager_approval'] as $s) {
            $this->make("c-$s", ['status' => $s, 'provider_id' => 'PRV-0005', 'provider' => 'مركز عناية للرعاية المنزلية']);
        }
        $this->make('internal', ['status' => 'in_progress', 'provider' => 'الفريق الطبي 01']);
        $ids = $this->ids('manager', '/api/v1/monitoring/care/execution');
        sort($ids);
        $this->assertSame(['c-assigned', 'c-in_progress', 'c-scheduled'], $ids);
    }

    public function test_providers_beneficiaries_and_revoked_case_view_cannot_monitor(): void
    {
        $this->asRole('provider')->getJson('/api/v1/monitoring/care/execution')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/monitoring/care/execution')->assertForbidden();
        $this->grant('manager', ['cases.view' => null]);
        $this->asRole('manager')->getJson('/api/v1/monitoring/care/execution')->assertForbidden();
    }

    public function test_execution_detail_reads_appointment_assignment_and_provider_activity(): void
    {
        $this->clearCases();
        $this->make('live', ['status' => 'scheduled', 'provider_id' => 'PRV-0005', 'provider' => 'مركز عناية للرعاية المنزلية', 'provider_accepted' => true,
            'appointment' => ['date' => '2026-10-29', 'time' => '10:30', 'location' => 'الرياض', 'status' => 'upcoming']], [self::ev('2026-10-20T00:00:00Z', 'assigned', 'scheduled', 'schedule')]);
        ServiceAuthorization::query()->create([
            'id' => 'AUL-T1', 'authorization_number' => 'AUTH-2026-99999', 'case_id' => 'live', 'provider_id' => 'PRV-0005', 'provider_name' => 'x', 'financial_approval_id' => 'FA-1',
            'service_name' => 'x', 'description' => 'x', 'quantity' => 4, 'unit' => 'جلسة', 'amount_halalas' => 1000, 'scope' => 'x', 'expected_start' => '2026-12-01', 'approval_ref' => 'x', 'status' => 'ISSUED', 'version' => 1,
        ]);
        ServiceAssignment::query()->create(['id' => 'ASG-T1', 'case_id' => 'live', 'provider_id' => 'PRV-0005', 'authorization_id' => 'AUL-T1', 'scope' => 'نطاق التعميد', 'quantity' => 4,
            'amount_halalas' => 1000, 'assigned_at' => now(), 'expected_start' => '2026-12-01', 'status' => 'ACKNOWLEDGED']);
        $m = $this->asRole('manager')->getJson('/api/v1/monitoring/care/execution/live')->assertOk()->json('data.monitor');
        $this->assertSame(['2026-10-29', 'جلسة', 'نطاق التعميد', 'schedule'], [$m['appointment']['date'], $m['unit'], $m['scope'], end($m['activities'])['action']]);
        $this->assertNull($m['completedSessions']);
        $this->assertNull($m['remainingSessions']);
        $this->getJson('/api/v1/monitoring/care/execution/REQ-404')->assertNotFound()->assertJsonPath('message', 'الحالة غير متاحة في متابعة التنفيذ');
    }

    public function test_sessions_come_from_visit_records_when_a_plan_exists(): void
    {
        $this->clearCases();
        $this->make('multi', ['status' => 'in_progress', 'provider_id' => 'PRV-0005', 'visit_plan' => ['deliveryType' => 'multiple_visits', 'serviceName' => 'x', 'approvedVisitCount' => 3, 'snapshotAt' => now_iso(), 'changes' => []]]);
        VisitRecord::query()->create(['id' => 'VIS-1', 'case_id' => 'multi', 'provider_id' => 'PRV-0005', 'sequence' => 1, 'scheduled_at' => now()->subDay(), 'completed_at' => now()->subHour(),
            'status' => 'completed', 'created_by' => 'x', 'audit' => [], 'corrections' => []]);
        $m = $this->asRole('manager')->getJson('/api/v1/monitoring/care/execution')->assertOk()->json('data.0.monitor');
        $this->assertSame([1, 2], [$m['completedSessions'], $m['remainingSessions']]);
        $this->assertArrayNotHasKey('activities', $m);
    }

    public function test_executive_sees_all_stages_and_sections_partition_the_list(): void
    {
        $res = $this->asRole('executive')->getJson('/api/v1/monitoring/executive')->assertOk();
        $this->assertSame(RahaCase::count(), count($res->json('data')));
        $counts = $res->json('counts');
        $this->assertSame($counts['all'], $counts['internal'] + $counts['provider']);
        $internal = array_column($this->getJson('/api/v1/monitoring/executive?section=internal')->json('data'), 'id');
        $provider = array_column($this->getJson('/api/v1/monitoring/executive?section=provider')->json('data'), 'id');
        $this->assertCount(RahaCase::count(), array_unique([...$internal, ...$provider]));
        $this->assertArrayHasKey('stageTiming', $res->json('data.0.monitor'));
    }

    public function test_denies_providers_beneficiaries_and_revoked_or_narrowed_scopes(): void
    {
        $this->asRole('provider')->getJson('/api/v1/monitoring/executive')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/monitoring/executive')->assertForbidden();
        $this->asRole('manager')->getJson('/api/v1/monitoring/executive')->assertForbidden(); // no executive.approvals.view
        $this->clearCases();
        $this->make('own', ['assignee' => 'سامي العتيبي']);
        $this->make('other', ['assignee' => 'other']);
        $name = \App\Models\User::find('u13')->name;
        RahaCase::whereKey('own')->update(['assignee' => $name]);
        $this->grant('executive', ['cases.view' => 'ASSIGNED']);
        $this->assertSame(['own'], $this->ids('executive', '/api/v1/monitoring/executive'));
        $this->grant('executive', ['cases.view' => null]);
        $this->asRole('executive')->getJson('/api/v1/monitoring/executive')->assertForbidden();
    }

    public function test_provider_work_classification_and_final_queue(): void
    {
        $this->clearCases();
        $this->make('quote', ['status' => 'awaiting_quotation']);
        $this->make('run', ['status' => 'in_progress', 'provider_id' => 'PRV-0005']);
        $this->make('verify', ['status' => 'completed', 'provider_id' => 'PRV-0005', 'service_confirmed' => false]);
        $this->make('verified', ['status' => 'completed', 'provider_id' => 'PRV-0005', 'service_confirmed' => true]);
        $this->make('internal', ['status' => 'in_progress']);
        $this->make('final', ['status' => 'preliminary_approved', 'finance' => ['approvalId' => 'a', 'stage' => 'pending_executive', 'configVersion' => 1]]);
        $this->make('not-final', ['status' => 'preliminary_approved']);
        $provider = $this->ids('executive', '/api/v1/monitoring/executive?section=provider');
        sort($provider);
        $this->assertSame(['quote', 'run', 'verify'], $provider);
        $this->assertSame(['final'], $this->ids('executive', '/api/v1/monitoring/executive?section=final'));
        $row = collect($this->getJson('/api/v1/monitoring/executive?section=final')->json('data'))->first();
        $this->assertSame('بانتظار اعتماد المدير التنفيذي', $row['monitor']['stage']);
        $quote = collect($this->getJson('/api/v1/monitoring/executive?section=provider')->json('data'))->firstWhere('id', 'quote');
        $this->assertSame('بانتظار عروض الأسعار', $quote['monitor']['provider']['status']);
    }

    public function test_stage_timing_uses_actual_deadlines_only(): void
    {
        $this->clearCases();
        $c = $this->make('no-history', ['status' => 'new', 'updated_at' => '2020-01-01']);
        $this->assertSame(['elapsedHours' => null, 'enteredAt' => null, 'deadline' => null, 'delayed' => false], MonitoringService::stageTiming($c->load(['tasks', 'activities'])));
        $t = $this->make('timed', ['status' => 'new'], [], [['stage' => 'new', 'created_at' => '2026-10-27T01:00:00Z', 'due_at' => '2026-10-27T05:00:00Z']]);
        $timing = MonitoringService::stageTiming($t->load(['tasks', 'activities']), \Illuminate\Support\Carbon::parse('2026-10-27T07:00:00Z')->getTimestampMs());
        $this->assertSame(['elapsedHours' => 6, 'enteredAt' => '2026-10-27T01:00:00.000Z', 'deadline' => '2026-10-27T05:00:00.000Z', 'delayed' => true], $timing);
        $this->travelTo('2026-10-27T07:00:00Z');
        $this->assertSame(['timed'], $this->ids('executive', '/api/v1/monitoring/executive?section=delayed'));
    }
}
