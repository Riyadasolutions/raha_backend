<?php

namespace Modules\Visits\Tests\Feature;

use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Cases\Models\RahaCase;
use Modules\Visits\Models\VisitRecord;

/** Port of front visit_tracking.test.tsx (Task 06). */
class VisitTrackingTest extends VisitsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->visitCase();
    }

    public function test_provider_schedules_a_visit_linked_to_request_and_assignment(): void
    {
        $v = $this->schedule('provider', self::ID, 2 * self::DAY)->assertCreated()
            ->assertJsonPath('data.caseId', self::ID)->assertJsonPath('data.providerId', 'PRV-0005')->assertJsonPath('data.assignmentId', 'ASG-119')
            ->assertJsonPath('data.sequence', 1)->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.audit.0.action', 'scheduled')->json('data');
        $this->assertMatchesRegularExpression('/^VIS-000119-01-[0-9a-f]{8}$/', $v['id']);
        $this->assertArrayHasKey('updatedAt', $v);
    }

    public function test_assigns_next_free_sequence_and_unique_ids(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[] = $this->schedule('provider', self::ID, $n * self::DAY)->assertJsonPath('data.sequence', $n)->json('data.id');
        }
        $this->assertCount(3, array_unique($ids));
    }

    public function test_prevents_duplicate_and_out_of_range_sequences(): void
    {
        $this->schedule('provider', self::ID, self::DAY, 2)->assertCreated();
        $this->schedule('provider', self::ID, 2 * self::DAY, 2)->assertStatus(422)->assertJsonPath('message', 'رقم الزيارة 2 مستخدم لزيارة أخرى لهذا الطلب.');
        $this->schedule('provider', self::ID, 2 * self::DAY, 9)->assertStatus(422)->assertJsonPath('message', 'رقم الزيارة يجب أن يكون عددًا صحيحًا بين 1 والعدد المعتمد.');
    }

    public function test_rejects_invalid_dates_and_scheduling_beyond_the_approved_count(): void
    {
        $this->asRole('provider')->postJson('/api/v1/cases/'.self::ID.'/visits', ['scheduledAt' => 'not-a-date'])->assertStatus(422)->assertJsonPath('message', 'أدخل موعد زيارة صحيحًا.');
        foreach ([1, 2, 3, 4] as $n) {
            $this->schedule('provider', self::ID, $n * self::DAY)->assertCreated();
        }
        $this->schedule('provider', self::ID, 9 * self::DAY)->assertStatus(422)
            ->assertJsonPath('message', 'بلغ عدد الزيارات المجدولة والمكتملة العدد المعتمد؛ لا يمكن إضافة زيارات إلا بتعديل معتمد للعدد.');
        $this->assertSame(4, $this->visitsCount());
    }

    public function test_provider_must_acknowledge_the_authorization_before_scheduling(): void
    {
        ServiceAssignment::query()->update(['status' => 'ASSIGNED']);
        $this->schedule('provider', self::ID, self::DAY)->assertStatus(422)->assertJsonPath('message', 'أكد استلام التعميد أولًا قبل جدولة الزيارات.');
        $this->schedule('manager', self::ID, self::DAY)->assertCreated();
        $this->assertDatabaseHas('notifications', ['title' => 'تمت جدولة زيارة لطلب مسند إليكم', 'provider_id' => 'PRV-0005']);
    }

    public function test_rejects_one_time_unapproved_unassigned_and_archived_requests(): void
    {
        RahaCase::whereKey(self::ID)->update(['visit_plan' => json_encode(['deliveryType' => 'one_time', 'serviceName' => 'x', 'snapshotAt' => now_iso(), 'changes' => []])]);
        $this->schedule('provider', self::ID, self::DAY)->assertStatus(422)->assertJsonPath('message', 'تتبع الزيارات متاح للخدمات متعددة الزيارات فقط.');
        $this->visitCase(approved: null);
        $this->schedule('provider', self::ID, self::DAY)->assertStatus(422)->assertJsonPath('message', 'لم يُحدد عدد الزيارات المعتمد لهذا الطلب.');
        $this->visitCase(extra: ['status' => 'archived']);
        $this->schedule('manager', self::ID, self::DAY)->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
        $this->assertSame(0, $this->visitsCount());
    }

    public function test_rejects_unauthorized_users_and_other_providers(): void
    {
        foreach (['auditor', 'social', 'executive'] as $role) {
            $this->assertContains($this->schedule($role, self::ID, self::DAY)->status(), [403, 404], $role);
        }
        $this->schedule('beneficiary', self::ID, self::DAY)->assertForbidden();
        $this->schedule('provider2', self::ID, self::DAY)->assertNotFound();
        $this->assertSame(0, $this->visitsCount());
    }

    public function test_provider_cannot_act_on_or_read_another_providers_visits(): void
    {
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $this->asRole('provider2')->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-1)])->assertNotFound();
        $this->asRole('provider2')->postJson("/api/v1/visits/$v/miss")->assertNotFound();
        $this->asRole('provider2')->postJson("/api/v1/visits/$v/cancel", ['reason' => 'x'])->assertNotFound();
        $this->asRole('provider2')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/provider/visits')->assertOk()->assertJsonCount(0, 'data');
        $this->asRole('provider')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/provider/visits')->assertJsonCount(1, 'data');
        $this->asRole('manager')->getJson('/api/v1/provider/visits')->assertForbidden();
    }

    public function test_providers_cannot_change_the_approved_count_or_correct(): void
    {
        $this->asRole('provider')->postJson('/api/v1/cases/'.self::ID.'/visit-plan/adjust', ['newCount' => 9, 'reason' => 'x'])->assertForbidden();
        $this->putJson('/api/v1/cases/'.self::ID.'/visit-plan/approved-count', ['count' => 9])->assertForbidden();
        $v = $this->doneVisit(self::ID, 1);
        $this->asRole('provider')->postJson("/api/v1/visits/$v/correct", ['status' => 'missed', 'reason' => 'x'])->assertForbidden();
    }

    public function test_executive_is_monitor_only_and_auditor_sees_nothing(): void
    {
        $v = $this->scheduled('provider', self::ID, self::DAY);
        $this->asRole('executive')->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-1)])->assertForbidden();
        $this->getJson('/api/v1/cases/'.self::ID.'/visits')->assertJsonCount(1, 'data');
        $this->asRole('auditor')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertJsonCount(0, 'data');
        $this->asRole('social')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertJsonCount(0, 'data');
    }

    public function test_completes_a_visit_without_changing_the_request_status(): void
    {
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $done = $this->asRole('provider')->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-3600), 'notes' => 'تمت', 'evidence' => 'REP-1'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.notes', 'تمت')->assertJsonPath('data.evidence', 'REP-1')
            ->assertJsonPath('progress.completed', 1)->json('data');
        $this->assertStringContainsString('مركز', $done['completedBy']);
        $this->assertSame('completed', end($done['audit'])['action']);
        $this->assertSame('in_progress', RahaCase::find(self::ID)->status);
    }

    public function test_rejects_invalid_future_and_duplicate_completion(): void
    {
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => 'bad'])->assertStatus(422)->assertJsonPath('message', 'أدخل تاريخ ووقت إكمال صحيحين.');
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(2 * self::DAY)])->assertStatus(422)->assertJsonPath('message', 'لا يمكن تسجيل وقت إكمال في المستقبل.');
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-10)])->assertOk();
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-5)])->assertStatus(422)
            ->assertJsonPath('message', 'تم تسجيل إكمال هذه الزيارة مسبقًا. استخدم التصحيح المعتمد عند الحاجة.');
    }

    public function test_marks_a_past_visit_missed_but_not_a_future_one(): void
    {
        $past = $this->scheduled('provider', self::ID, -self::DAY);
        $future = $this->scheduled('provider', self::ID, self::DAY);
        $this->postJson("/api/v1/visits/$past/miss", ['reason' => 'المريض غير متواجد'])->assertOk()->assertJsonPath('data.status', 'missed')->assertJsonPath('data.missedReason', 'المريض غير متواجد');
        $this->postJson("/api/v1/visits/$future/miss")->assertStatus(422)->assertJsonPath('message', 'لا يمكن تسجيل زيارة مستقبلية على أنها لم تتم.');
        $this->assertDatabaseHas('notifications', ['title' => 'زيارة لم تتم', 'case_id' => self::ID]);
        $this->assertContains('missed', array_column($this->getJson('/api/v1/cases/'.self::ID.'/visits')->json('data'), 'status'));
    }

    public function test_cancelled_visits_need_a_reason_and_free_their_sequence(): void
    {
        $v = $this->scheduled('provider', self::ID, self::DAY);
        $this->postJson("/api/v1/visits/$v/cancel", ['reason' => ' '])->assertStatus(422)->assertJsonPath('message', 'اذكر سبب الإلغاء.');
        $this->postJson("/api/v1/visits/$v/cancel", ['reason' => 'تغيير الموعد'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-1)])->assertStatus(422)->assertJsonPath('message', 'لا يمكن إكمال زيارة غير مجدولة (ملغاة أو لم تتم).');
        $this->schedule('provider', self::ID, 2 * self::DAY)->assertJsonPath('data.sequence', 1);
    }

    public function test_corrections_need_permission_and_reason_and_keep_original_values(): void
    {
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $t = now()->subHours(2)->startOfSecond()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => $t, 'notes' => 'أصلي'])->assertOk();
        $this->asRole('executive')->postJson("/api/v1/visits/$v/correct", ['notes' => 'x', 'reason' => 'سبب'])->assertForbidden();
        $this->asRole('manager')->postJson("/api/v1/visits/$v/correct", ['notes' => 'x', 'reason' => ' '])->assertStatus(422)->assertJsonPath('message', 'اذكر سبب التصحيح.');
        $fixed = $this->postJson("/api/v1/visits/$v/correct", ['status' => 'missed', 'reason' => 'سجل خاطئ'])->assertOk()->assertJsonPath('data.status', 'missed')->json('data');
        $this->assertArrayNotHasKey('completedAt', $fixed);
        $this->assertSame(['status' => 'completed', 'completedAt' => $t, 'notes' => 'أصلي'], $fixed['corrections'][0]['previous']);
        $this->assertSame('سجل خاطئ', $fixed['corrections'][0]['reason']);
        $this->assertSame('corrected', end($fixed['audit'])['action']);
    }

    public function test_correction_cannot_reactivate_beyond_the_approved_count(): void
    {
        $this->visitCase(approved: 1);
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $this->postJson("/api/v1/visits/$v/cancel", ['reason' => 'x'])->assertOk();
        $this->scheduled('provider', self::ID, self::DAY);
        $this->asRole('manager')->postJson("/api/v1/visits/$v/correct", ['status' => 'scheduled', 'reason' => 'خطأ'])->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إعادة تفعيل الزيارة: بلغ العدد المعتمد.');
    }

    public function test_progress_counts_without_double_counting(): void
    {
        $id = self::ID;
        foreach ([2, 3] as $n) {
            $this->doneVisit($id, $n);
        }
        $this->scheduled('provider', $id, -self::DAY);            // overdue
        $this->scheduled('provider', $id, self::DAY);             // upcoming
        $p = $this->getJson("/api/v1/cases/$id/visits")->assertOk()->json('progress');
        $this->assertSame(['approved' => 4, 'completed' => 2, 'remaining' => 2, 'scheduled' => 2, 'upcoming' => 1, 'overdue' => 1, 'missed' => 0, 'cancelled' => 0, 'percent' => 50, 'done' => false],
            array_diff_key($p, ['nextVisitAt' => 1]));
        $this->assertArrayHasKey('nextVisitAt', $p);
    }

    public function test_count_increase_allows_more_visits(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            $this->scheduled('provider', self::ID, $n * self::DAY);
        }
        $this->asRole('manager')->postJson('/api/v1/cases/'.self::ID.'/visit-plan/adjust', ['newCount' => 6, 'reason' => 'توصية طبية'])->assertOk()
            ->assertJsonPath('data.visitPlan.approvedVisitCount', 6);
        $this->assertSame(['from' => 4, 'to' => 6], array_intersect_key(last($this->plan()['changes']), ['from' => 1, 'to' => 1]));
        $this->schedule('provider', self::ID, 9 * self::DAY)->assertJsonPath('data.sequence', 5);
        $this->assertDatabaseHas('case_activities', ['case_id' => self::ID, 'action' => 'visit_count', 'visibility' => 'internal']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم تعديل عدد الزيارات المعتمد بعد الإسناد', 'record_id' => self::ID]);
    }

    public function test_reductions_never_delete_history(): void
    {
        [$a, $b] = [$this->scheduled('provider', self::ID, self::DAY), $this->scheduled('provider', self::ID, 2 * self::DAY)];
        $this->scheduled('provider', self::ID, 3 * self::DAY);
        foreach ([$a, $b] as $v) {
            $this->asRole('provider')->postJson("/api/v1/visits/$v/complete", ['completedAt' => $this->iso(-100)])->assertOk();
        }
        $url = '/api/v1/cases/'.self::ID.'/visit-plan/adjust';
        $this->asRole('manager')->postJson($url, ['newCount' => 1, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'لا يمكن تخفيض العدد إلى أقل من الزيارات المكتملة (2).');
        $this->postJson($url, ['newCount' => 2, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'يوجد 1 زيارة مجدولة زائدة عن العدد الجديد؛ ألغِها أولًا أو اختر الإلغاء الصريح.');
        $this->assertSame(3, $this->visitsCount());
        $this->postJson($url, ['newCount' => 2, 'reason' => 'تخفيض', 'resolution' => 'cancel_excess_scheduled'])->assertOk();
        $this->assertSame(3, $this->visitsCount());
        $this->assertSame(2, VisitRecord::where('status', 'completed')->count());
        $this->assertSame('تخفيض العدد المعتمد: تخفيض', VisitRecord::where('status', 'cancelled')->first()->cancel_reason);
        $this->assertSame(2, $this->plan()['approvedVisitCount']);
    }

    public function test_adjust_requires_permission_reason_positive_integer_and_execution_stage(): void
    {
        $url = '/api/v1/cases/'.self::ID.'/visit-plan/adjust';
        $this->assertContains($this->asRole('auditor')->postJson($url, ['newCount' => 5, 'reason' => 'x'])->status(), [403, 404]);
        $this->asRole('manager')->postJson($url, ['newCount' => 5, 'reason' => ' '])->assertStatus(422)->assertJsonPath('message', 'اذكر سبب تعديل عدد الزيارات.');
        foreach ([0, 1.5, '3'] as $bad) {
            $this->postJson($url, ['newCount' => $bad, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'عدد الزيارات يجب أن يكون عددًا صحيحًا موجبًا.');
        }
        $this->visitCase(extra: ['status' => 'manager_approval', 'provider_id' => null, 'provider' => null]);
        $this->postJson($url, ['newCount' => 5, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'لا يمكن تعديل عدد الزيارات في مرحلة الطلب الحالية.');
    }

    public function test_historical_and_one_time_requests_get_no_visit_records(): void
    {
        RahaCase::whereKey(self::ID)->update(['visit_plan' => json_encode(['deliveryType' => 'one_time', 'serviceName' => 'x', 'snapshotAt' => now_iso(), 'changes' => []])]);
        $this->asRole('manager')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('progress', null);
        $this->asRole('executive')->getJson('/api/v1/monitoring/visits')->assertOk()->assertJsonCount(0, 'data.rows');
        $this->assertSame(0, VisitRecord::count());
    }
}
