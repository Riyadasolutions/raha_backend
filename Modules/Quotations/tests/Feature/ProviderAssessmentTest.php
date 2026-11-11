<?php

namespace Modules\Quotations\Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use Modules\Cases\Models\RahaCase;
use Modules\Quotations\Models\ProviderAssessment;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;

/** Port of front provider_assessment.test.tsx (Task 16): beneficiary contact + provider needs assessment. */
class ProviderAssessmentTest extends QuotationsTestCase
{
    private const NID = '1098765432';

    private string $round;

    protected function setUp(): void
    {
        parent::setUp();
        RahaCase::query()->whereKey(self::CASE_ID)->update([
            'beneficiary' => 'سالم العتيبي', 'national_id' => self::NID, 'mobile' => '0551234567',
            'applicant' => json_encode(['name' => 'سالم العتيبي', 'nationalId' => self::NID, 'mobile' => '0551234567', 'birthDate' => '1950-01-01']),
            'social' => json_encode(['housingType' => 'سري-بحث-اجتماعي']), 'recipient' => null,
        ]);
        $this->round = $this->open3();
    }

    private function url(string $pid, string $suffix): string
    {
        return '/api/v1/provider/invitations/'.$this->inv($this->round, $pid).$suffix;
    }

    private function input(array $extra = []): array
    {
        return array_merge(['contactStatus' => 'contacted', 'assessedAt' => now()->toDateString(), 'method' => 'phone', 'needsNotes' => 'يحتاج متابعة أسبوعية',
            'recommendedScope' => 'تمريض منزلي', 'recommendedVisits' => 6, 'comments' => ''], $extra);
    }

    public function test_contact_returns_only_the_four_allowed_fields(): void
    {
        $c = $this->asRole('provider')->getJson($this->url(self::A, '/contact'))->assertOk()->json('data');
        $this->assertSame(['name' => 'سالم العتيبي', 'phone' => '0551234567', 'requestNumber' => self::CASE_ID, 'service' => 'تمريض منزلي'], $c);
        $json = json_encode($c, JSON_UNESCAPED_UNICODE);
        foreach ([self::NID, '1950-01-01', 'سري-بحث-اجتماعي', 'housingType'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        // Viewing the number never marks the beneficiary as contacted.
        $this->getJson($this->url(self::A, '/assessment'))->assertOk()->assertJsonPath('data', null)->assertJsonPath('canEdit', true);
        $this->assertSame(0, ProviderAssessment::query()->count());
    }

    public function test_contact_falls_back_to_recipient_and_never_invents_a_phone(): void
    {
        RahaCase::query()->whereKey(self::CASE_ID)->update(['mobile' => null, 'applicant' => null, 'recipient' => json_encode(['fullName' => 'خالد', 'mobileNumber' => '0507654321'])]);
        $this->asRole('provider')->getJson($this->url(self::A, '/contact'))->assertJsonPath('data.name', 'خالد')->assertJsonPath('data.phone', '0507654321');
        RahaCase::query()->whereKey(self::CASE_ID)->update(['applicant' => json_encode(['name' => 'سالم العتيبي']), 'recipient' => null]);
        $this->getJson($this->url(self::A, '/contact'))->assertJsonPath('data.name', 'سالم العتيبي')->assertJsonPath('data.phone', null);
    }

    public function test_invitation_based_access(): void
    {
        $this->asRole('provider');
        foreach (['/contact', '/assessment'] as $s) {
            $this->getJson($this->url(self::B, $s))->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية الوصول إلى هذه الدعوة.');
        }
        $this->putJson($this->url(self::B, '/assessment'), $this->input())->assertForbidden();
        $this->getJson('/api/v1/provider/invitations/INV-none/contact')->assertForbidden();
        foreach (['medical', 'manager', 'auditor', 'beneficiary'] as $role) {
            $this->asRole($role)->getJson($this->url(self::A, '/contact'))->assertForbidden();
            $this->putJson($this->url(self::A, '/assessment'), $this->input())->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson($this->url(self::A, '/contact'))->assertUnauthorized();
    }

    public function test_contact_ends_with_the_invitation(): void
    {
        $this->submitAs(self::A, $this->round)->assertOk();
        $this->getJson($this->url(self::A, '/contact'))->assertStatus(422)->assertJsonPath('message', 'انتهت المشاركة في هذه الدعوة؛ لا تتوفر بيانات التواصل.');
        $this->putJson($this->url(self::A, '/assessment'), $this->input())->assertStatus(422)->assertJsonPath('message', 'أُغلقت هذه الدعوة ولا يمكن تعديل التقييم.');
        $this->asRole('provider2')->postJson($this->url(self::B, '/decline'), ['confirmed' => true])->assertOk();
        $this->getJson($this->url(self::B, '/contact'))->assertStatus(422);
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/{$this->round}/close")->assertOk();
        $this->asRole('provider3')->getJson($this->url(self::C, '/contact'))->assertStatus(422);
    }

    public function test_contact_denied_after_deadline_cancellation_or_archiving(): void
    {
        $this->asRole('provider');
        $this->expire($this->round);
        $this->getJson($this->url(self::A, '/contact'))->assertStatus(422);
        QuotationRound::query()->whereKey($this->round)->update(['deadline' => now()->addDay(), 'status' => 'cancelled']);
        $this->getJson($this->url(self::A, '/contact'))->assertStatus(422);
        QuotationRound::query()->whereKey($this->round)->update(['status' => 'open']);
        $this->getJson($this->url(self::A, '/contact'))->assertOk()->assertJsonPath('data.name', 'سالم العتيبي');
        RahaCase::query()->whereKey(self::CASE_ID)->update(['status' => 'archived']);
        $this->getJson($this->url(self::A, '/contact'))->assertStatus(422);
    }

    public function test_saves_reloads_and_keeps_one_record_per_invitation(): void
    {
        $a = $this->asRole('provider')->putJson($this->url(self::A, '/assessment'), $this->input(['comments' => 'ملاحظة']))->assertOk()->json('data');
        $this->assertSame(['invitationId' => $this->inv($this->round, self::A), 'quotationRoundId' => $this->round, 'caseId' => self::CASE_ID, 'providerId' => self::A,
            'contactStatus' => 'contacted', 'method' => 'phone', 'needsNotes' => 'يحتاج متابعة أسبوعية', 'recommendedScope' => 'تمريض منزلي', 'recommendedVisits' => 6, 'comments' => 'ملاحظة'],
            array_intersect_key($a, array_flip(['invitationId', 'quotationRoundId', 'caseId', 'providerId', 'contactStatus', 'method', 'needsNotes', 'recommendedScope', 'recommendedVisits', 'comments'])));
        $this->assertSame($a, $this->getJson($this->url(self::A, '/assessment'))->json('data'));
        $this->assertStringNotContainsString('سالم', json_encode($a, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('0551234567', json_encode($a));

        $this->putJson($this->url(self::A, '/assessment'), $this->input(['needsNotes' => 'أ-معدل']))->assertOk();
        $this->asRole('provider2')->putJson($this->url(self::B, '/assessment'), $this->input(['needsNotes' => 'ب', 'contactStatus' => 'unreachable']))->assertOk();
        $this->assertSame(2, ProviderAssessment::query()->count());
        $this->getJson($this->url(self::B, '/assessment'))->assertJsonPath('data.needsNotes', 'ب')->assertJsonPath('data.contactStatus', 'unreachable');
        $this->getJson($this->url(self::A, '/assessment'))->assertForbidden();
        $this->asRole('provider')->getJson($this->url(self::A, '/assessment'))->assertJsonPath('data.needsNotes', 'أ-معدل');
    }

    public function test_validates_the_fields(): void
    {
        $this->asRole('provider');
        $put = fn (array $x) => $this->putJson($this->url(self::A, '/assessment'), $this->input($x));
        $put(['contactStatus' => 'called'])->assertStatus(422)->assertJsonPath('message', 'حالة التواصل غير صحيحة.');
        $put(['method' => 'sms'])->assertStatus(422)->assertJsonPath('message', 'طريقة التقييم غير صحيحة.');
        $put(['assessedAt' => '2999-01-01'])->assertStatus(422)->assertJsonPath('message', 'تاريخ التقييم غير صحيح أو في المستقبل.');
        $put(['assessedAt' => 'abc'])->assertStatus(422)->assertJsonPath('message', 'تاريخ التقييم غير صحيح أو في المستقبل.');
        foreach ([0, -1, 1.5] as $v) {
            $put(['recommendedVisits' => $v])->assertStatus(422)->assertJsonPath('message', 'عدد الزيارات المقترح يجب أن يكون عددًا صحيحًا موجبًا.');
        }
        $put(['needsNotes' => str_repeat('x', 2001)])->assertStatus(422)->assertJsonPath('message', 'النص أطول من الحد المسموح.');
        $this->putJson($this->url(self::A, '/assessment'), ['contactStatus' => 'not_contacted'])->assertOk()->assertJsonPath('data.needsNotes', '')->assertJsonPath('data.contactStatus', 'not_contacted');
    }

    public function test_history_on_real_changes_only_and_no_workflow_side_effects(): void
    {
        $notifications = Notification::query()->count();
        $this->asRole('provider');
        $this->putJson($this->url(self::A, '/assessment'), $this->input())->assertOk();
        $this->putJson($this->url(self::A, '/assessment'), $this->input())->assertOk();
        $a = $this->putJson($this->url(self::A, '/assessment'), $this->input(['contactStatus' => 'unreachable', 'needsNotes' => 'تحديث']))->assertOk()->json('data');
        $this->assertCount(2, $a['history']);
        $this->assertSame(['summary' => 'إنشاء التقييم', 'contactStatus' => 'contacted', 'by' => \App\Models\User::find('u10')->name],
            ['summary' => $a['history'][0]['summary'], 'contactStatus' => $a['history'][0]['contactStatus'], 'by' => $a['history'][0]['by']]);
        $this->assertStringContainsString('contactStatus', $a['history'][1]['summary']);
        $this->assertStringContainsString('needsNotes', $a['history'][1]['summary']);
        $this->assertSame('تم تحديث تقييم مقدم الخدمة', AuditLog::query()->latest('id')->value('action'));

        $this->assertSame($notifications, Notification::query()->count());
        $this->assertSame('invited', QuotationInvitation::query()->find($this->inv($this->round, self::A))->status);
        $this->assertSame('open', QuotationRound::query()->find($this->round)->status);
        $this->assertSame('awaiting_quotation', RahaCase::query()->find(self::CASE_ID)->status);
        $this->assertSame(0, \Modules\Quotations\Models\ProviderQuotation::query()->count());
    }

    public function test_read_only_after_closure_but_still_readable(): void
    {
        $this->asRole('provider')->putJson($this->url(self::A, '/assessment'), $this->input())->assertOk();
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/{$this->round}/close")->assertOk();
        $this->asRole('provider')->putJson($this->url(self::A, '/assessment'), $this->input(['needsNotes' => 'متأخر']))->assertStatus(422)->assertJsonPath('message', 'أُغلقت هذه الدعوة ولا يمكن تعديل التقييم.');
        $this->getJson($this->url(self::A, '/assessment'))->assertJsonPath('data.needsNotes', 'يحتاج متابعة أسبوعية')->assertJsonPath('canEdit', false);
    }

    public function test_staff_review_shows_only_submitted_providers(): void
    {
        $this->asRole('provider2')->putJson($this->url(self::B, '/assessment'), $this->input())->assertOk();
        $this->asRole('provider')->putJson($this->url(self::A, '/assessment'), $this->input(['recommendedVisits' => 8]))->assertOk();
        $this->asRole('medical')->getJson("/api/v1/quotation-rounds/{$this->round}/assessments")->assertOk()->assertJsonPath('data', []);

        // Quotation submission works with an assessment and is unchanged.
        $this->submitAs(self::A, $this->round)->assertOk()->assertJsonPath('data.quotation.version', 1);
        $list = $this->asRole('medical')->getJson("/api/v1/quotation-rounds/{$this->round}/assessments")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame([self::A, 'مركز عناية للرعاية المنزلية', 'contacted', 8], [$list[0]['providerId'], $list[0]['providerName'], $list[0]['contactStatus'], $list[0]['recommendedVisits']]);
        $this->asRole('manager')->getJson("/api/v1/quotation-rounds/{$this->round}/assessments")->assertJsonCount(1, 'data');
        foreach (['auditor', 'social'] as $role) {
            $this->asRole($role)->getJson("/api/v1/quotation-rounds/{$this->round}/assessments")->assertOk()->assertJsonPath('data', []);
        }
        foreach (['provider', 'beneficiary'] as $role) {
            $this->asRole($role)->getJson("/api/v1/quotation-rounds/{$this->round}/assessments")->assertForbidden();
        }
    }
}
