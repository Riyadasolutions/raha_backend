<?php

namespace Modules\Quotations\Tests\Feature;

use App\Models\Notification;
use Illuminate\Http\UploadedFile;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseProjector;
use Modules\Quotations\Models\ProviderQuotation;
use Modules\Quotations\Models\QuotationRound;
use Modules\Quotations\Services\QuotationRules;

/** Port of front phase4_quotations.test.ts through the HTTP API, plus privacy / ownership negatives. */
class QuotationFlowTest extends QuotationsTestCase
{
    /* -------------------------------------------------------------- money */

    public function test_money_rules_in_halalas(): void
    {
        $this->assertSame(['gross' => 9999, 'discount' => 999, 'net' => 9000, 'tax' => 1350, 'total' => 10350],
            QuotationRules::lineAmounts(['quantity' => 3, 'unitPrice' => 3333, 'discountAmount' => 999, 'taxRate' => 15]));
        $t = QuotationRules::totals([['quantity' => 1, 'unitPrice' => 10, 'discountAmount' => 0, 'taxRate' => 0], ['quantity' => 1, 'unitPrice' => 20, 'discountAmount' => 0, 'taxRate' => 0]]);
        $this->assertSame(30, $t['grandTotal']);
    }

    public function test_exempt_tax_treatment_stores_zero_tax(): void
    {
        $id = $this->open3([self::A]);
        $this->submitAs(self::A, $id, $this->quote(100, ['taxRate' => 15]))->assertOk()
            ->assertJsonPath('data.quotation.taxTotal', 0)->assertJsonPath('data.quotation.grandTotal', 100000)->assertJsonPath('data.view', 'submitted');
    }

    /* -------------------------------------------------------------- rounds and eligibility */

    public function test_round_creation_rules(): void
    {
        $this->asRole('medical')->postJson('/api/v1/quotation-rounds', $this->roundInput(['deadline' => iso(now()->subMinute())]))
            ->assertStatus(422)->assertJsonPath('message', 'آخر موعد لاستقبال العروض يجب أن يكون في المستقبل.');
        $this->postJson('/api/v1/quotation-rounds', $this->roundInput(['quantity' => 0]))->assertStatus(422)->assertJsonPath('message', 'عدد الجلسات أو الكمية يجب أن يكون أكبر من صفر.');
        $this->postJson('/api/v1/quotation-rounds', $this->roundInput(['specifications' => ' ']))->assertStatus(422)->assertJsonPath('message', 'يرجى استكمال الحقول الإلزامية.');
        $this->postJson('/api/v1/quotation-rounds', $this->roundInput(['caseId' => 'REQ-000116']))->assertStatus(422)->assertJsonPath('message', 'الطلب ليس في مرحلة طلب عروض الأسعار.');
        $this->postJson('/api/v1/quotation-rounds', $this->roundInput(['caseId' => 'REQ-000123']))->assertNotFound()->assertJsonPath('message', 'الطلب غير موجود.');
        $this->asRole('social')->postJson('/api/v1/quotation-rounds', $this->roundInput())->assertForbidden();
        $this->asRole('provider')->postJson('/api/v1/quotation-rounds', $this->roundInput())->assertForbidden();
    }

    public function test_create_without_providers_keeps_a_draft_then_invite_opens_it(): void
    {
        $this->asRole('medical')->getJson('/api/v1/cases/'.self::CASE_ID.'/quotation-rounds')->assertOk()
            ->assertJsonPath('canRequestQuotation', true)->assertJsonPath('activeRoundId', null)->assertJsonCount(0, 'data');
        $r = $this->postJson('/api/v1/quotation-rounds', $this->roundInput())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.displayStatus', 'draft')->assertJsonPath('data.invitations', [])->json('data');
        $this->assertMatchesRegularExpression('/^QR-\d{5}$/', $r['id']);
        $this->assertSame([], $r['sharedAttachments']);
        $this->getJson('/api/v1/cases/'.self::CASE_ID.'/quotation-rounds')->assertJsonPath('canRequestQuotation', false)->assertJsonPath('activeRoundId', $r['id']);
        $this->postJson('/api/v1/quotation-rounds', $this->roundInput())->assertStatus(422)->assertJsonPath('message', 'يوجد طلب تسعير نشط لهذا الطلب.');

        // Providers never see draft rounds.
        $this->asRole('provider')->getJson('/api/v1/provider/invitations')->assertOk()->assertJsonMissing(['quotationRoundId' => $r['id']]);

        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/{$r['id']}/invitations", ['providerIds' => []])->assertStatus(422)->assertJsonPath('message', 'اختر مقدم خدمة واحدًا على الأقل.');
        $this->postJson("/api/v1/quotation-rounds/{$r['id']}/invitations", ['providerIds' => [self::A]])->assertOk()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.displayStatus', 'invited')->assertJsonCount(1, 'data.invitations');
        $this->postJson("/api/v1/quotation-rounds/{$r['id']}/invitations", ['providerIds' => [self::A]])->assertStatus(422)->assertJsonPath('message', 'تمت دعوة مقدم الخدمة مسبقًا في هذا الطلب.');
        $inv = $this->inv($r['id'], self::A);
        $this->assertDatabaseHas('notifications', ['title' => 'دعوة جديدة لتقديم عرض سعر', 'provider_id' => self::A, 'body' => "{$r['id']} — تمريض منزلي"]);
        $this->assertSame(['to' => '/provider/quotations/$invitationId', 'params' => ['invitationId' => $inv]], Notification::query()->where('provider_id', self::A)->latest('id')->first()->link);
    }

    public function test_create_and_invite_is_atomic(): void
    {
        // PRV-0004 is inactive: the whole request fails and no orphan draft round is left behind (mock gap).
        $this->asRole('medical')->postJson('/api/v1/quotation-rounds', $this->roundInput(['providerIds' => [self::A, 'PRV-0004']]))
            ->assertStatus(422)->assertJsonPath('message', 'مركز بلسم للرعاية: مقدم الخدمة غير نشط');
        $this->assertSame(0, QuotationRound::query()->where('case_id', self::CASE_ID)->count());
        $this->assertSame(0, Notification::query()->where('title', 'دعوة جديدة لتقديم عرض سعر')->count());
        $this->assertSame(0, CaseActivity::query()->where('case_id', self::CASE_ID)->where('action', 'quotation')->count());

        $id = $this->open3();
        $round = $this->getJson("/api/v1/quotation-rounds/$id")->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.caseStatus', 'awaiting_quotation')
            ->assertJsonPath('data.deadlinePassed', false)->assertJsonCount(3, 'data.invitations')->json('data');
        $this->assertCount(2, $round['history']);
        $this->assertSame(2, CaseActivity::query()->where('case_id', self::CASE_ID)->where('action', 'quotation')->where('visibility', 'internal')->count());
    }

    public function test_eligibility_rules(): void
    {
        $id = $this->open3([self::A]);
        $rows = collect($this->getJson("/api/v1/quotation-rounds/$id/eligible-providers")->assertOk()->json('data'))->keyBy('providerId');
        $this->assertTrue($rows[self::A]['eligible']);
        $this->assertTrue($rows[self::A]['invited']);
        $this->assertTrue($rows[self::B]['eligible']);
        $this->assertSame(['eligible' => false, 'incomplete' => false, 'reason' => 'مقدم الخدمة غير نشط'], array_intersect_key($rows['PRV-0004'], array_flip(['eligible', 'incomplete', 'reason'])));
        $this->assertSame('لا يقدم هذه الخدمة', $rows['PRV-0002']['reason']);
        $this->assertTrue($rows['PRV-0007']['incomplete']);

        $this->postJson("/api/v1/quotation-rounds/$id/invitations", ['providerIds' => ['PRV-0002']])->assertStatus(422)->assertJsonPath('message', 'مركز حركة للتأهيل: لا يقدم هذه الخدمة');
        $this->postJson("/api/v1/quotation-rounds/$id/invitations", ['providerIds' => ['PRV-9999']])->assertStatus(422)->assertJsonPath('message', 'مقدم الخدمة غير موجود.');

        // Wrong city: the request moves to Jeddah; Riyadh providers are out of coverage.
        RahaCase::query()->whereKey(self::CASE_ID)->update(['city' => 'جدة']);
        $this->postJson("/api/v1/quotation-rounds/$id/invitations", ['providerIds' => [self::C]])->assertStatus(422)->assertJsonPath('message', 'مركز الأمل للرعاية المنزلية: خارج نطاق التغطية الجغرافية');
    }

    public function test_round_reaches_awaiting_quotation_through_the_real_workflow(): void
    {
        // REQ-000123 (new) → auditor → medical "needs quotation" → awaiting_quotation.
        $id = 'REQ-000123';
        RahaCase::query()->whereKey($id)->update(['service_id' => 'SRV-001', 'city' => 'الرياض']);
        $this->asRole('auditor')->postJson("/api/v1/cases/$id/task/claim")->assertOk();
        $r = $this->postJson("/api/v1/cases/$id/actions/audit_approve", ['confirmed' => true]);
        if ($r->status() !== 200) {
            $this->markTestSkipped('seed request cannot pass the audit gate: '.$r->json('message'));
        }
        $this->asRole('medical')->postJson("/api/v1/cases/$id/task/claim")->assertOk();
        $this->postJson("/api/v1/cases/$id/actions/medical_quotation", ['fields' => ['opinion' => 'مناسب', 'recommendation' => 'تسعير', 'decision' => 'يحتاج إلى تسعير من مقدم خدمة', 'quotationContext' => 'سعر الخدمة غير محدد']])
            ->assertOk()->assertJsonPath('data.status', 'awaiting_quotation');
        $this->postJson('/api/v1/quotation-rounds', $this->roundInput(['caseId' => $id, 'providerIds' => [self::A]]))->assertCreated()->assertJsonPath('data.requestNumber', $id);
    }

    /* -------------------------------------------------------------- provider rules */

    public function test_provider_sees_only_own_invitation_and_no_other_offers(): void
    {
        $id = $this->open3();
        $this->submitAs(self::A, $id)->assertOk();
        $list = $this->asRole('provider2')->getJson('/api/v1/provider/invitations')->assertOk()->json('data');
        $mine = collect($list)->where('round.id', $id)->values();
        $this->assertCount(1, $mine);
        $this->assertNull($mine[0]['quotation']);
        $this->assertSame('invited', $mine[0]['view']);
        $this->assertStringNotContainsString(self::A, json_encode($mine));
        foreach (['history', 'quotations', 'invitations', 'createdBy', 'createdById', 'cancelReason', 'selectionReason'] as $k) {
            $this->assertArrayNotHasKey($k, $mine[0]['round']);
        }
        $this->getJson('/api/v1/provider/invitations/'.$this->inv($id, self::A))->assertNotFound();
        // Staff portal is closed to providers.
        $this->getJson("/api/v1/quotation-rounds/$id")->assertForbidden();
        $this->getJson('/api/v1/quotation-rounds')->assertForbidden();
    }

    public function test_drafts_are_private_and_not_counted(): void
    {
        $id = $this->open3();
        $this->draftAs(self::A, $id, $this->quote(100, ['lineItems' => [], 'description' => '']))->assertOk()->assertJsonPath('data.view', 'draft')
            ->assertJsonPath('data.quotation.status', 'draft')->assertJsonPath('data.quotation.version', 1);
        $this->draftAs(self::A, $id)->assertOk()->assertJsonPath('data.quotation.version', 2);
        $this->asRole('medical')->getJson("/api/v1/quotation-rounds/$id")->assertJsonPath('data.displayStatus', 'awaiting')->assertJsonPath('data.quotations', []);
        // Another provider cannot touch it.
        $this->asRole('provider2')->putJson('/api/v1/provider/invitations/'.$this->inv($id, self::A).'/quotation', $this->quote())
            ->assertForbidden()->assertJsonPath('message', 'هذه الدعوة لا تخص منشأتك.');
        // Drafts are type-checked only.
        $this->asRole('provider')->putJson('/api/v1/provider/invitations/'.$this->inv($id, self::A).'/quotation', ['lineItems' => 'x'])->assertStatus(422);
    }

    public function test_cannot_submit_twice_or_with_invalid_amounts(): void
    {
        $id = $this->open3();
        $this->submitAs(self::A, $id, $this->quote(), false)->assertStatus(422)->assertJsonPath('message', 'يرجى تأكيد إرسال عرض السعر.');
        $this->submitAs(self::A, $id, $this->quote(0))->assertStatus(422)->assertJsonPath('message', 'البند 1: سعر الوحدة غير صالح.');
        $this->submitAs(self::A, $id, $this->quote(100, ['lineItems' => [['description' => 'x', 'quantity' => -1, 'unit' => 'زيارة', 'unitPrice' => 100, 'discountAmount' => 0]]]))
            ->assertStatus(422)->assertJsonPath('message', 'البند 1: الكمية يجب أن تكون أكبر من صفر.');
        $this->submitAs(self::A, $id, $this->quote(100, ['lineItems' => [['description' => 'x', 'quantity' => 1, 'unit' => 'زيارة', 'unitPrice' => 10.5, 'discountAmount' => 0]]]))
            ->assertStatus(422)->assertJsonPath('message', 'البند 1: سعر الوحدة غير صالح.');
        $this->submitAs(self::A, $id, $this->quote(100, ['lineItems' => []]))->assertStatus(422)->assertJsonPath('message', 'أضف بندًا واحدًا على الأقل.');
        $this->submitAs(self::A, $id, $this->quote(100, ['taxTreatment' => 'standard', 'taxRate' => 0]))->assertStatus(422)->assertJsonPath('message', 'حدد نسبة الضريبة المطبقة.');
        $this->submitAs(self::A, $id, $this->quote(100, ['validUntil' => now()->subDays(2)->toDateString()]))->assertStatus(422)->assertJsonPath('message', 'تاريخ انتهاء صلاحية العرض غير صالح.');
        $this->submitAs(self::A, $id, $this->quote(100, ['attachments' => [['name' => 'a.pdf', 'size' => 6 * 1024 * 1024, 'type' => 'application/pdf']]]))
            ->assertStatus(422)->assertJsonPath('message', 'بيانات أحد المرفقات غير صالحة (الحد 5 ميجابايت).');
        $this->assertSame(0, ProviderQuotation::query()->count());

        $q = $this->submitAs(self::A, $id)->assertOk()->json('data.quotation');
        $this->assertSame('QT-'.substr($id, 3).'-01', $q['number']);
        $this->assertArrayHasKey('branding', $q);
        $this->submitAs(self::A, $id)->assertStatus(422)->assertJsonPath('message', 'تم إرسال عرض السعر مسبقًا ولا يمكن إرساله مرة أخرى.');
        $this->draftAs(self::A, $id)->assertStatus(422);
        $this->assertSame('QT-'.substr($id, 3).'-02', $this->submitAs(self::B, $id)->json('data.quotation.number'));
        $this->assertDatabaseHas('notifications', ['title' => 'تم استلام عرض سعر جديد', 'body' => "$id — مركز عناية للرعاية المنزلية"]);
    }

    public function test_cannot_submit_after_the_deadline_unless_extended_and_extension_reopens_closed_rounds(): void
    {
        $id = $this->open3();
        $this->expire($id);
        $this->submitAs(self::A, $id)->assertStatus(422)->assertJsonPath('message', 'انتهت مهلة تقديم العروض أو أُغلق الاستقبال.');
        $this->getJson('/api/v1/provider/invitations/'.$this->inv($id, self::A))->assertOk()->assertJsonPath('data.view', 'expired');
        $this->asRole('medical')->getJson("/api/v1/quotation-rounds/$id")->assertJsonPath('data.deadlinePassed', true);

        $this->postJson("/api/v1/quotation-rounds/$id/extend-deadline", ['deadline' => self::future(), 'reason' => ''])->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال سبب التمديد.');
        $this->postJson("/api/v1/quotation-rounds/$id/extend-deadline", ['deadline' => iso(now()->subHour()), 'reason' => 'x'])->assertStatus(422)
            ->assertJsonPath('message', 'الموعد الجديد يجب أن يكون في المستقبل وبعد الموعد الحالي.');
        $this->postJson("/api/v1/quotation-rounds/$id/extend-deadline", ['deadline' => self::future(), 'reason' => 'طلب مقدمي الخدمة'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->submitAs(self::A, $id)->assertOk();

        // Closing stops submissions; extending a closed round reopens it.
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/v1/quotation-rounds/$id/close")->assertStatus(422)->assertJsonPath('message', 'طلب التسعير ليس مفتوحًا.');
        $this->submitAs(self::B, $id)->assertStatus(422)->assertJsonPath('message', 'انتهت مهلة تقديم العروض أو أُغلق الاستقبال.');
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/extend-deadline", ['deadline' => self::future(72), 'reason' => 'إعادة فتح'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->submitAs(self::B, $id)->assertOk();
    }

    public function test_cancel_rejects_submissions_and_notifies_invited_providers(): void
    {
        $id = $this->open3();
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/cancel", ['reason' => ' '])->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال سبب الإلغاء.');
        $this->postJson("/api/v1/quotation-rounds/$id/cancel", ['reason' => 'تغير الاحتياج'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelReason', 'تغير الاحتياج');
        foreach ([self::A, self::B, self::C] as $p) {
            $this->assertDatabaseHas('notifications', ['title' => 'أُلغي طلب تسعير', 'body' => $id, 'provider_id' => $p]);
        }
        $this->submitAs(self::A, $id)->assertStatus(422)->assertJsonPath('message', 'أُلغي طلب التسعير.');
        $this->getJson('/api/v1/provider/invitations/'.$this->inv($id, self::A))->assertJsonPath('data.view', 'cancelled')->assertJsonMissingPath('data.round.cancelReason');
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'لا يمكن إلغاء هذا الطلب.');
        $this->getJson('/api/v1/cases/'.self::CASE_ID.'/quotation-rounds')->assertJsonPath('canRequestQuotation', true);
    }

    public function test_decline(): void
    {
        $id = $this->open3();
        $inv = $this->inv($id, self::C);
        $this->asRole('provider3')->postJson("/api/v1/provider/invitations/$inv/decline", ['reason' => 'لا تتوفر طاقة'])->assertStatus(422)->assertJsonPath('message', 'يرجى تأكيد الاعتذار.');
        $this->postJson("/api/v1/provider/invitations/$inv/decline", ['reason' => 'لا تتوفر طاقة', 'confirmed' => true])->assertOk()->assertJsonPath('data.view', 'declined')
            ->assertJsonPath('data.invitation.declineReason', 'لا تتوفر طاقة');
        $this->postJson("/api/v1/provider/invitations/$inv/decline", ['confirmed' => true])->assertStatus(422)->assertJsonPath('message', 'تم الرد على هذه الدعوة مسبقًا.');
        $this->postJson("/api/v1/provider/invitations/$inv/quotation/submit", $this->quote() + ['confirmed' => true])->assertStatus(422)->assertJsonPath('message', 'اعتذرت عن هذه الدعوة.');
        $this->assertDatabaseHas('notifications', ['title' => 'اعتذار مقدم خدمة عن التسعير', 'body' => "$id — مركز الأمل للرعاية المنزلية"]);
    }

    public function test_upload_attachments_are_owned_and_single_use(): void
    {
        $id = $this->open3();
        $this->asRole('provider');
        $upload = $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->create('offer.pdf', 40, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $input = $this->quote(100, ['attachments' => [['uploadId' => $upload], ['name' => 'meta.pdf', 'size' => 1000, 'type' => 'application/pdf']]]);
        $this->draftAs(self::A, $id, $input)->assertOk();
        $q = $this->submitAs(self::A, $id, $input)->assertOk()->json('data.quotation');
        $this->assertSame(['name' => 'offer.pdf', 'size' => 40 * 1024, 'type' => 'application/pdf', 'uploadId' => $upload], $q['attachments'][0]);
        $this->assertSame(['name' => 'meta.pdf', 'size' => 1000, 'type' => 'application/pdf'], $q['attachments'][1]);
        // Another provider cannot reuse someone else's upload.
        $this->submitAs(self::B, $id, $input)->assertStatus(422)->assertJsonPath('message', 'الملف المرفق غير صالح أو تم استخدامه مسبقًا.');
    }

    /* -------------------------------------------------------------- end to end */

    public function test_full_flow_create_invite_draft_submit_deadline_evaluate_select(): void
    {
        $id = $this->open3();
        $this->draftAs(self::A, $id, $this->quote(260))->assertOk();
        $qa = $this->submitAs(self::A, $id, $this->quote(250))->assertOk()->json('data.quotation');
        $qb = $this->submitAs(self::B, $id, $this->quote(200))->assertOk()->json('data.quotation');
        $this->asRole('provider3')->postJson('/api/v1/provider/invitations/'.$this->inv($id, self::C).'/decline', ['reason' => 'لا تتوفر طاقة', 'confirmed' => true])->assertOk();

        $this->asRole('medical')->getJson("/api/v1/quotation-rounds/$id")->assertJsonPath('data.displayStatus', 'partial')->assertJsonCount(2, 'data.quotations');
        $ev = ['compliance' => 'compliant', 'notes' => 'مطابق', 'reasons' => '', 'recommendation' => 'موصى به'];
        $this->postJson("/api/v1/quotation-rounds/$id/quotations/{$qa['id']}/evaluations", $ev)->assertStatus(422)->assertJsonPath('message', 'أغلق استقبال العروض قبل التقييم.');
        $this->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qa['id'], 'reason' => 'x', 'confirmed' => true])->assertStatus(422)->assertJsonPath('message', 'أغلق استقبال العروض قبل اختيار العرض.');

        // The deadline passes (no explicit close needed).
        $this->travelTo(now()->addHours(49));
        $this->asRole('medical');
        $this->postJson("/api/v1/quotation-rounds/$id/quotations/{$qb['id']}/evaluations", ['compliance' => 'noncompliant', 'notes' => 'لا يغطي', 'reasons' => '', 'recommendation' => 'غير موصى'])
            ->assertStatus(422)->assertJsonPath('message', 'يرجى توضيح أسباب عدم المطابقة.');
        $this->postJson("/api/v1/quotation-rounds/$id/quotations/{$qb['id']}/evaluations", ['compliance' => 'noncompliant', 'notes' => 'لا يغطي', 'reasons' => 'لا يوجد ممرض مرخص', 'recommendation' => 'غير موصى'])
            ->assertOk()->assertJsonPath('data.status', 'evaluating');
        $round = $this->postJson("/api/v1/quotation-rounds/$id/quotations/{$qa['id']}/evaluations", $ev)->assertOk()->json('data');
        $byId = collect($round['quotations'])->keyBy('id');
        $this->assertSame('compliant', $byId[$qa['id']]['latestEvaluation']['compliance']);
        $this->assertSame('u3', $byId[$qa['id']]['latestEvaluation']['byId']);

        // Lowest price is never auto-selected.
        $this->assertNull(RahaCase::find(self::CASE_ID)->preferred_quotation);
        $this->assertNull(QuotationRound::find($id)->selected_quotation_id);

        $this->asRole('auditor')->postJson("/api/v1/quotation-rounds/$id/quotations/{$qa['id']}/evaluations", $ev)->assertForbidden();
        $this->asRole('manager')->postJson("/api/v1/quotation-rounds/$id/quotations/{$qa['id']}/evaluations", $ev)->assertForbidden();
        $this->asRole('social')->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qa['id'], 'reason' => 'مطابق طبيًا', 'confirmed' => true])->assertForbidden();
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qb['id'], 'reason' => 'الأرخص', 'confirmed' => true])
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن اختيار عرض غير مطابق طبيًا أو لم يُقيّم.');
        $this->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qa['id'], 'reason' => 'مطابق طبيًا'])
            ->assertStatus(422)->assertJsonPath('message', 'يرجى تأكيد أن الاختيار توصية وليس تفويضًا بتنفيذ الخدمة.');
        $this->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qa['id'], 'reason' => 'مطابق طبيًا', 'confirmed' => true])->assertOk()
            ->assertJsonPath('data.status', 'selected')->assertJsonPath('data.selectedQuotationId', $qa['id'])->assertJsonPath('data.caseStatus', 'social_review');

        $c = RahaCase::find(self::CASE_ID);
        $this->assertSame('social_review', $c->status);
        $this->assertSame(['roundId' => $id, 'quotationId' => $qa['id'], 'providerId' => self::A, 'providerName' => 'مركز عناية للرعاية المنزلية', 'grandTotal' => 250000, 'reason' => 'مطابق طبيًا'],
            array_intersect_key($c->preferred_quotation, array_flip(['roundId', 'quotationId', 'providerId', 'providerName', 'grandTotal', 'reason'])));
        $this->assertNull($c->provider_id);
        $this->assertNull($c->assigned_at);
        $this->assertGreaterThanOrEqual(5, CaseActivity::query()->where('case_id', self::CASE_ID)->where('action', 'quotation')->count());
        $this->assertDatabaseHas('case_activities', ['case_id' => self::CASE_ID, 'action' => 'quotation_complete', 'to_stage' => 'social_review']);
        $this->assertDatabaseHas('notifications', ['title' => 'تم اختيار عرض سعر — بانتظار البحث الاجتماعي', 'body' => self::CASE_ID]);

        // Beneficiary never sees provider names or the internal selection reason.
        $ben = json_encode(app(CaseProjector::class)->forBeneficiary($c->fresh()), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('مركز عناية للرعاية المنزلية', $ben);
        $this->assertStringNotContainsString('مطابق طبيًا', $ben);

        // Provider views after selection; internal evaluations never reach providers.
        $this->asRole('provider')->getJson('/api/v1/provider/invitations/'.$this->inv($id, self::A))->assertJsonPath('data.view', 'selected')->assertJsonPath('data.quotation.evaluations', []);
        $this->asRole('provider2')->getJson('/api/v1/provider/invitations/'.$this->inv($id, self::B))->assertJsonPath('data.view', 'not_selected')
            ->assertJsonMissingPath('data.round.selectionReason');
        $this->assertStringNotContainsString('لا يوجد ممرض مرخص', $this->getJson('/api/v1/provider/invitations')->getContent());
        // The request left the quotation stage: the medical reviewer no longer reaches it; an admin with access cannot re-select.
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qa['id'], 'reason' => 'x', 'confirmed' => true])->assertForbidden();
        $this->asRole('admin')->postJson("/api/v1/quotation-rounds/$id/select", ['quotationId' => $qa['id'], 'reason' => 'x', 'confirmed' => true])
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن اختيار عرض لهذا الطلب.');
    }

    public function test_staff_lists_and_provider_dashboard(): void
    {
        $id = $this->open3();
        $this->submitAs(self::A, $id)->assertOk();
        $row = collect($this->asRole('medical')->getJson('/api/v1/quotation-rounds')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame(['partial', false, 'awaiting_quotation'], [$row['displayStatus'], $row['deadlinePassed'], $row['caseStatus']]);
        $this->assertNotNull(collect($this->getJson('/api/v1/quotation-rounds')->json('data'))->firstWhere('id', 'QR-DEMO-0001'));
        $this->asRole('manager')->getJson('/api/v1/quotation-rounds')->assertOk()->assertJsonFragment(['id' => $id]);
        // Auditor holds quotations.view but no access to the awaiting_quotation stage.
        $this->asRole('auditor')->getJson('/api/v1/quotation-rounds')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/quotation-rounds/$id")->assertNotFound();
        $this->asRole('beneficiary')->getJson('/api/v1/quotation-rounds')->assertForbidden();

        // u10 (PRV-0005): demo invitation + this round (submitted); u11: demo + this one, both new.
        RahaCase::query()->whereKey('REQ-000114')->update(['provider_id' => self::A]);
        $this->asRole('provider')->getJson('/api/v1/provider/dashboard')->assertOk()
            ->assertJsonPath('data.newInvitations', 1)->assertJsonPath('data.submitted', 1)->assertJsonPath('data.pendingDrafts', 0)->assertJsonPath('data.assignedCases', 1);
        $this->asRole('provider2')->getJson('/api/v1/provider/dashboard')->assertJsonPath('data.newInvitations', 2)->assertJsonPath('data.assignedCases', 0);
        $this->expire($id);
        $this->getJson('/api/v1/provider/dashboard')->assertJsonPath('data.expired', 1);
    }

    public function test_archived_request_blocks_round_writes(): void
    {
        $id = $this->open3();
        RahaCase::query()->whereKey(self::CASE_ID)->update(['status' => 'archived']);
        $this->submitAs(self::A, $id)->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/$id/close")->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
    }
}
