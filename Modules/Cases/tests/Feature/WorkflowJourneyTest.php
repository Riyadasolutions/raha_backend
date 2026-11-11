<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\Notification;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseComment;
use Modules\Cases\Models\RahaCase;

/** Port of front phase3_workflow.test.ts journeys through the HTTP API. */
class WorkflowJourneyTest extends CasesTestCase
{
    public function test_full_same_id_return_correction_review_journey(): void
    {
        $id = $this->submitAsBeneficiary();
        $this->assertMatchesRegularExpression('/^REQ-\d{6}$/', $id);
        $c = RahaCase::find($id);
        $this->assertSame('new', $c->status);
        $this->assertTrue($c->survey_pending);
        $this->assertSame('multiple_visits', $c->visit_plan['deliveryType']);
        $this->assertSame([['docType' => 'medical_report', 'label' => 'التقرير الطبي', 'required' => true, 'reason' => 'مطلوب للخدمة الصحية المحددة. يجب أن يكون بتاريخ خلال آخر 5 أيام.']], $c->requirements);
        $this->assertDatabaseHas('notifications', ['beneficiary_id' => 'BEN-0001', 'title' => 'تم استلام طلبك بنجاح.', 'case_id' => $id]);
        $this->assertDatabaseHas('notifications', ['title' => 'طلب جديد يحتاج إلى مراجعة', 'body' => $id]);

        // Without a claim the auditor cannot decide.
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertForbidden()->assertJsonPath('message', 'استلم المهمة أولًا؛ يمكن لمالك المهمة فقط اتخاذ القرار.');
        $this->getJson('/api/v1/tasks?view=queue')->assertOk()->assertJsonFragment(['id' => $id]);
        $this->claim('auditor', $id)->assertOk()->assertJsonPath('data.activeTask.assignedUserId', 'u2');
        $this->getJson('/api/v1/tasks?view=my')->assertOk()->assertJsonFragment(['id' => $id]);
        $this->claim('auditor', $id)->assertStatus(422)->assertJsonPath('message', 'المهمة مستلمة بالفعل.');

        // Return requires explicit corrections.
        $this->act('auditor', $id, 'audit_return', ['reason' => 'بيانات ناقصة أو غير مطابقة', 'publicNote' => 'صحح الوصف', 'showToBeneficiary' => true])
            ->assertStatus(422)->assertJsonPath('message', 'حدد بيانات أو مستندات تحتاج إلى تصحيح.');
        $this->act('auditor', $id, 'audit_return', ['reason' => 'بيانات ناقصة أو غير مطابقة', 'publicNote' => 'صحح وصف الحالة', 'showToBeneficiary' => true, 'correctionFields' => ['وصف الحالة'], 'notes' => 'خاص'])
            ->assertOk()->assertJsonPath('data.status', 'returned');
        $c->refresh();
        $this->assertSame('new', $c->return_info['fromStatus']);

        // Beneficiary sees the return but never the internal note.
        $this->asRole('beneficiary');
        $detail = $this->getJson("/api/v1/portal/requests/$id")->assertOk()->assertJsonPath('data.beneficiaryStatus', 'needs_edit')
            ->assertJsonPath('data.returnInfo.reason', 'بيانات ناقصة أو غير مطابقة')->json('data');
        $this->assertStringNotContainsString('خاص', json_encode($detail, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('tasks', $detail);
        $this->assertArrayNotHasKey('reviews', $detail);

        // The correction must actually change the flagged field.
        $this->postJson("/api/v1/portal/requests/$id/resubmit", ['description' => 'حاجة لتمريض منزلي'])->assertStatus(422)->assertJsonPath('message', 'يرجى تصحيح البيانات المحددة في سبب الإرجاع.');
        $this->postJson("/api/v1/portal/requests/$id/resubmit", ['description' => 'وصف مصحح'])->assertOk()->assertJsonPath('data.status', 'new');
        $c->refresh();
        $this->assertSame($id, $c->id);
        $this->assertNull($c->return_info);
        $this->assertCount(1, $c->return_history);
        $this->assertSame(1, CaseActivity::where('case_id', $id)->where('action', 'resubmit')->count());

        $this->claim('auditor', $id)->assertOk();
        $this->act('auditor', $id, 'audit_approve')->assertStatus(422)->assertJsonPath('message', 'يرجى تأكيد مراجعة البيانات والمستندات.');
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertOk()->assertJsonPath('data.status', 'medical_review');
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertStatus(422);

        $this->claim('medical', $id)->assertOk();
        $this->act('medical', $id, 'medical_approve', ['fields' => ['opinion' => 'مناسب']])->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال التوصية الطبية.');
        $this->act('medical', $id, 'medical_approve', self::MEDICAL)->assertOk()->assertJsonPath('data.status', 'social_review');

        $this->claim('social', $id)->assertOk();
        $this->act('social', $id, 'social_complete', ['fields' => ['summary' => 'ملخص', 'assessment' => 'دراسة', 'recommendation' => 'مساهمة جزئية من المستفيد', 'justification' => 'x', 'percentage' => '101']])
            ->assertStatus(422)->assertJsonPath('message', 'نسبة المساهمة المقترحة يجب أن تكون بين 0 و100%.');
        $this->act('social', $id, 'social_complete', self::SOCIAL)->assertOk()->assertJsonPath('data.status', 'manager_approval');

        $this->claim('manager', $id)->assertOk();
        $res = $this->act('manager', $id, 'care_approve', ['confirmed' => true])->assertOk()->assertJsonPath('data.status', 'preliminary_approved');
        $res->assertJsonPath('data.finance.stage', 'pending_review')->assertJsonPath('data.finance.approvalId', '')->assertJsonPath('data.finance.configVersion', 1);

        $c->refresh()->load(['reviews', 'tasks']);
        $this->assertCount(5, $c->reviews);
        $this->assertSame(5, $c->tasks->where('status', 'completed')->count());
        $this->assertNull($c->provider_id);
        $this->assertNull($c->invoice);
        $this->assertFalse(CaseComment::where('case_id', $id)->where('text', 'خاص')->where('visibility', '!=', 'internal')->exists());
        $this->assertDatabaseHas('notifications', ['beneficiary_id' => 'BEN-0001', 'title' => 'تمت الموافقة المبدئية على طلبك، وجارٍ استكمال الإجراءات.']);
        $this->assertDatabaseHas('notifications', ['title' => 'تم الاعتماد المبدئي — بانتظار المراجعة المالية', 'body' => $id]);

        $this->asRole('beneficiary')->getJson("/api/v1/portal/requests/$id")->assertJsonPath('data.beneficiaryStatusLabel', 'قيد الاعتماد المالي')
            ->assertJsonPath('data.financeNote.label', 'قيد الاعتماد المالي')->assertJsonPath('data.financeNote.contribution', null)->assertJsonMissingPath('data.finance');
    }

    public function test_resubmission_returns_to_original_medical_stage(): void
    {
        $id = $this->submitAsBeneficiary();
        $this->claim('auditor', $id);
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertOk();
        $this->claim('medical', $id);
        $this->act('medical', $id, 'medical_return', ['reason' => 'بيانات ناقصة أو غير مطابقة', 'publicNote' => 'صحح المعلومات الطبية', 'showToBeneficiary' => true, 'correctionFields' => ['المعلومات الطبية']])->assertOk();
        $this->asRole('beneficiary')->postJson("/api/v1/portal/requests/$id/resubmit", ['medical' => ['diagnosis' => 'تشخيص مصحح']])->assertOk()->assertJsonPath('data.status', 'medical_review');
        $c = RahaCase::find($id);
        $this->assertSame('medical_review', $c->activeTask()->stage);
    }

    public function test_return_with_document_requires_replacement_upload(): void
    {
        $id = $this->submitAsBeneficiary();
        $docId = RahaCase::find($id)->documents->first()->id;
        $this->claim('auditor', $id);
        $this->act('auditor', $id, 'audit_return', ['reason' => 'تقرير طبي غير واضح', 'showToBeneficiary' => true, 'docIds' => [$docId]])->assertOk();
        $this->assertSame(['التقرير الطبي'], RahaCase::find($id)->return_info['fields']);
        $this->travel(2)->seconds();
        $this->asRole('beneficiary')->postJson("/api/v1/portal/requests/$id/resubmit", ['keepDocIds' => [$docId]])->assertStatus(422)->assertJsonPath('message', 'يرجى استبدال المستندات المحددة للتصحيح.');
        $this->postJson("/api/v1/portal/requests/$id/resubmit", ['keepDocIds' => []])->assertStatus(422)->assertJsonPath('message', 'يرجى استكمال المستندات المطلوبة: التقرير الطبي');
        $upload = $this->upload('new-report.pdf');
        $this->postJson("/api/v1/portal/requests/$id/resubmit", ['keepDocIds' => [], 'documents' => [['uploadId' => $upload, 'docType' => 'medical_report', 'reportDate' => now()->toDateString()]]])
            ->assertOk()->assertJsonPath('data.status', 'new');
        $c = RahaCase::find($id);
        $this->assertCount(1, $c->documents);
        $this->assertNotSame($docId, $c->documents->first()->id);
        $this->assertDatabaseHas('case_documents', ['id' => $docId, 'superseded' => true]);
        // The same upload cannot be attached twice.
        $this->assertNotNull(\App\Models\Upload::find($upload)->attached_at);
    }

    public function test_reject_requires_reason_and_blocks_further_actions(): void
    {
        $id = $this->submitAsBeneficiary();
        $this->claim('auditor', $id);
        $this->act('auditor', $id, 'audit_reject')->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال السبب.');
        $this->act('auditor', $id, 'audit_reject', ['reason' => '   '])->assertStatus(422);
        $this->act('auditor', $id, 'audit_reject', ['reason' => 'سبب مكتوب بحرية من المدقق'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('سبب مكتوب بحرية من المدقق', RahaCase::find($id)->reject_reason);
        $this->claim('auditor', $id)->assertForbidden();
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertStatus(422)->assertJsonPath('message', 'الإجراء غير متاح لهذه الحالة.');
        $this->asRole('beneficiary')->getJson("/api/v1/portal/requests/$id")->assertJsonPath('data.beneficiaryStatus', 'not_accepted');
    }

    public function test_manager_send_back_is_internal_only(): void
    {
        $id = 'REQ-000120'; // seeded manager_approval
        $this->claim('manager', $id)->assertOk();
        $this->act('manager', $id, 'care_send_back', ['reason' => 'يحتاج إلى استكمال البحث الاجتماعي', 'targetStage' => 'social_review', 'publicNote' => 'x', 'notes' => 'تفاصيل'])
            ->assertStatus(422)->assertJsonPath('message', 'الإعادة للمراجعة الداخلية لا تظهر للمستفيد.');
        $this->act('manager', $id, 'care_send_back', ['reason' => 'يحتاج إلى استكمال البحث الاجتماعي', 'targetStage' => 'social_review'])
            ->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال مبرر الإعادة للمراجعة.');
        $before = Notification::whereNotNull('beneficiary_id')->count();
        $this->act('manager', $id, 'care_send_back', ['reason' => 'يحتاج إلى استكمال البحث الاجتماعي', 'targetStage' => 'social_review', 'notes' => 'تفاصيل داخلية'])
            ->assertOk()->assertJsonPath('data.status', 'social_review');
        $this->assertSame($before, Notification::whereNotNull('beneficiary_id')->count());
        $this->assertSame('social_review', RahaCase::find($id)->activeTask()->stage);
    }

    public function test_quotation_decision_waits_for_quotations_and_blocks_assignment(): void
    {
        $id = 'REQ-000122'; // seeded medical_review
        $this->claim('medical', $id)->assertOk();
        $this->act('medical', $id, 'medical_approve', ['fields' => array_merge(self::MEDICAL['fields'], ['decision' => 'يحتاج إلى تسعير من مقدم خدمة'])])
            ->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال مبرر طلب عروض الأسعار.');
        $this->act('medical', $id, 'medical_approve', ['fields' => ['opinion' => 'x', 'recommendation' => 'y', 'decision' => 'يحتاج إلى تسعير من مقدم خدمة', 'quotationContext' => 'تكلفة غير محددة']])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_quotation')->assertJsonPath('data.quotationRequired', true);
        RahaCase::whereKey($id)->update(['status' => 'preliminary_approved']);
        $this->act('manager', $id, 'manager_assign', ['providerId' => 'PRV-0005'])->assertForbidden();
    }

    public function test_downstream_service_flow_with_schedule_invoice_and_archive(): void
    {
        $id = 'REQ-000114'; // seeded assigned to internal team, no provider
        RahaCase::whereKey($id)->update(['provider_id' => 'PRV-0005', 'provider' => 'مركز عناية للرعاية المنزلية', 'provider_accepted' => false]);
        $this->act('provider', $id, 'schedule')->assertForbidden();
        $this->act('provider2', $id, 'provider_accept')->assertForbidden();
        $this->act('provider', $id, 'provider_accept')->assertOk()->assertJsonPath('data.providerAccepted', true);
        $res = $this->act('provider', $id, 'schedule', ['date' => '2026-12-01', 'time' => '09:00'])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $res->assertJsonMissingPath('data.verificationCode')->assertJsonPath('data.appointment.time', '09:00');
        $this->assertMatchesRegularExpression('/^\d{6}$/', RahaCase::find($id)->verification_code);
        $this->act('provider', $id, 'start_service')->assertOk();
        $this->act('provider', $id, 'complete_service')->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.appointment.status', 'visited');

        $this->act('relations', $id, 'relations_review')->assertStatus(422)->assertJsonPath('message', 'بانتظار تأكيد المستفيد لاستلام الخدمة.');
        $this->asRole('beneficiary')->postJson("/api/v1/portal/requests/$id/evaluation", ['rating' => 5])->assertStatus(422);
        $this->postJson("/api/v1/portal/requests/$id/confirm-service")->assertOk()->assertJsonPath('data.serviceConfirmed', true);
        $this->postJson("/api/v1/portal/requests/$id/confirm-service")->assertStatus(422);
        $this->postJson("/api/v1/portal/requests/$id/evaluation", ['rating' => 5, 'comment' => 'ممتاز'])->assertOk()->assertJsonPath('data.evaluation.rating', 5);
        $this->postJson("/api/v1/portal/requests/$id/evaluation", ['rating' => 4])->assertStatus(422);
        $this->assertDatabaseHas('notifications', ['title' => 'أكد المستفيد استلام الخدمة', 'case_id' => $id]);

        $this->asRole('provider2')->postJson("/api/v1/cases/$id/invoice", ['amount' => 100])->assertStatus(422);
        $this->asRole('provider')->postJson("/api/v1/cases/$id/invoice", ['amount' => 0])->assertStatus(422)->assertJsonPath('message', 'أدخل مبلغًا صحيحًا.');
        $this->postJson("/api/v1/cases/$id/invoice", ['amount' => 350])->assertOk()->assertJsonPath('data.invoice.status', 'pending');
        $this->postJson("/api/v1/cases/$id/invoice", ['amount' => 350])->assertStatus(422)->assertJsonPath('message', 'تم رفع فاتورة لهذا الطلب مسبقًا.');

        $this->act('relations', $id, 'relations_review')->assertOk()->assertJsonPath('data.status', 'finance_review');
        $this->act('finance', $id, 'finance_complete')->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.invoice.status', 'paid');
        $this->assertSame(1, CaseActivity::where('case_id', $id)->where('action', 'finance_complete')->count());
    }

    public function test_complete_service_requires_all_approved_visits(): void
    {
        $id = 'REQ-000119'; // seeded in_progress, internal team
        RahaCase::whereKey($id)->update(['visit_plan' => json_encode(['deliveryType' => 'multiple_visits', 'serviceName' => 'x', 'approvedVisitCount' => 2, 'snapshotAt' => now_iso(), 'changes' => []])]);
        $this->act('team', $id, 'complete_service')->assertStatus(422)->assertJsonPath('message', 'لا يمكن إكمال الخدمة لوجود زيارات معتمدة لم يتم تنفيذها بعد.');
    }
}
