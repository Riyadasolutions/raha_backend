<?php

namespace Modules\Visits\Tests\Feature;

use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\RahaCase;

/** Port of the "approved visit count" part of front service_delivery.test.tsx (Task 05). */
class VisitPlanTest extends VisitsTestCase
{
    private const CASE = 'REQ-000120'; // manager_approval

    protected function setUp(): void
    {
        parent::setUp();
        RahaCase::whereKey(self::CASE)->update(['visit_plan' => json_encode([
            'deliveryType' => 'multiple_visits', 'serviceId' => 'SRV-002', 'serviceName' => 'علاج طبيعي منزلي', 'suggestedVisitCount' => 12, 'snapshotAt' => now_iso(), 'changes' => [],
        ], JSON_UNESCAPED_UNICODE)]);
    }

    private function putCount(string $role, mixed $count)
    {
        return $this->asRole($role)->putJson('/api/v1/cases/'.self::CASE.'/visit-plan/approved-count', ['count' => $count]);
    }

    public function test_authorized_staff_save_the_count_the_default_is_only_a_suggestion(): void
    {
        $this->asRole('manager')->getJson('/api/v1/cases/'.self::CASE.'/visit-plan')->assertOk()
            ->assertJsonPath('data.canEdit', true)->assertJsonPath('data.lockReason', null)->assertJsonPath('data.plan.suggestedVisitCount', 12);
        $this->assertArrayNotHasKey('approvedVisitCount', $this->plan(self::CASE));
        $this->putCount('manager', 12)->assertOk()->assertJsonPath('data.plan.approvedVisitCount', 12)->assertJsonPath('data.plan.approvedBy', 'خالد الدوسري');
        $this->assertDatabaseHas('case_activities', ['case_id' => self::CASE, 'action' => 'visit_count', 'visibility' => 'internal', 'text' => 'حدد خالد الدوسري عدد الزيارات المعتمد: 12.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم تحديد عدد الزيارات المعتمد', 'record_id' => self::CASE]);
    }

    public function test_rejects_non_positive_and_non_integer_counts(): void
    {
        foreach ([0, -1, 1.5, '4', null] as $bad) {
            $this->putCount('manager', $bad)->assertStatus(422)->assertJsonPath('message', 'عدد الزيارات يجب أن يكون عددًا صحيحًا موجبًا.');
        }
        $this->assertArrayNotHasKey('approvedVisitCount', $this->plan(self::CASE));
    }

    public function test_unauthorized_users_cannot_edit_it(): void
    {
        foreach (['auditor', 'social', 'finance', 'executive', 'provider', 'beneficiary'] as $role) {
            $this->putCount($role, 5)->assertForbidden();
        }
        $this->assertArrayNotHasKey('approvedVisitCount', $this->plan(self::CASE));
    }

    public function test_keeps_a_single_plan_and_identical_saves_add_no_history(): void
    {
        $this->putCount('manager', 8)->assertOk();
        $this->putCount('manager', 8)->assertOk();
        $this->putCount('manager', 10)->assertOk();
        $p = $this->plan(self::CASE);
        $this->assertSame(10, $p['approvedVisitCount']);
        $this->assertCount(2, $p['changes']);
        $this->assertArrayNotHasKey('from', $p['changes'][0]);
        $this->assertSame(['from' => 8, 'to' => 10], array_intersect_key($p['changes'][1], ['from' => 1, 'to' => 1]));
        $this->assertSame('manager_approval', RahaCase::find(self::CASE)->status);
        $this->assertDatabaseHas('case_activities', ['case_id' => self::CASE, 'text' => 'عدّل خالد الدوسري عدد الزيارات المعتمد من 8 إلى 10.']);
    }

    public function test_blocked_on_archived_requests_after_assignment_and_once_a_letter_is_issued(): void
    {
        RahaCase::whereKey(self::CASE)->update(['status' => 'archived']);
        $this->putCount('manager', 5)->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
        RahaCase::whereKey(self::CASE)->update(['status' => 'assigned']);
        $this->putCount('manager', 5)->assertStatus(422)->assertJsonPath('message', 'لا يمكن تعديل عدد الزيارات بعد هذه المرحلة.');
        RahaCase::whereKey(self::CASE)->update(['status' => 'preliminary_approved']);
        ServiceAuthorization::query()->create([
            'id' => 'AUL-T1', 'authorization_number' => 'AUTH-2026-99999', 'case_id' => self::CASE, 'provider_id' => 'PRV-0005', 'provider_name' => 'x',
            'financial_approval_id' => 'FA-1', 'service_name' => 'x', 'description' => 'x', 'quantity' => 9, 'unit' => 'زيارة', 'amount_halalas' => 10000,
            'scope' => 'x', 'expected_start' => '2026-12-01', 'approval_ref' => 'x', 'status' => 'ISSUED', 'version' => 1,
        ]);
        $this->putCount('manager', 5)->assertStatus(422)->assertJsonPath('message', 'صدر خطاب تعميد لهذا الطلب؛ لا يمكن تعديل عدد الزيارات.');
        $this->getJson('/api/v1/cases/'.self::CASE.'/visit-plan')->assertJsonPath('data.canEdit', false)
            ->assertJsonPath('data.lockReason', 'صدر خطاب تعميد لهذا الطلب؛ لا يمكن تعديل عدد الزيارات.');
    }

    public function test_legacy_and_one_time_requests_cannot_get_a_count(): void
    {
        RahaCase::whereKey(self::CASE)->update(['visit_plan' => null]);
        $this->putCount('manager', 5)->assertStatus(422)->assertJsonPath('message', 'هذا الطلب لا يحتوي على خطة زيارات.');
        RahaCase::whereKey(self::CASE)->update(['visit_plan' => json_encode(['deliveryType' => 'one_time', 'serviceName' => 'x', 'snapshotAt' => now_iso(), 'changes' => []])]);
        $this->putCount('manager', 3)->assertStatus(422)->assertJsonPath('message', 'الخدمة تُنفَّذ لمرة واحدة ولا تتطلب عدد زيارات.');
    }

    public function test_provider_gets_the_read_only_projection_only(): void
    {
        $this->putCount('manager', 9)->assertOk();
        $this->visitCase(self::CASE, ['status' => 'assigned', 'visit_plan' => json_encode(array_merge($this->plan(self::CASE), ['approvedVisitCount' => 9]))]);
        $data = $this->asRole('provider')->getJson('/api/v1/cases/'.self::CASE.'/visit-plan')->assertOk()->json('data');
        $this->assertSame(['providerView' => ['deliveryType' => 'multiple_visits', 'approvedVisitCount' => 9]], $data);
        $this->asRole('provider2')->getJson('/api/v1/cases/'.self::CASE.'/visit-plan')->assertNotFound();
    }
}
