<?php

namespace Modules\Authorizations\Tests\Feature;

use App\Models\Setting;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Finance\Models\FinanceEvent;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Tests\Feature\FinanceTestCase;

/** Letters, assignment and provider acknowledgement (front phase5_finance + authorization_document + e2e finance parts). */
class AuthorizationFlowTest extends FinanceTestCase
{
    public function test_full_journey_from_preliminary_approval_to_provider_acknowledgement(): void
    {
        $this->toExecutiveApproved();
        $this->assertSame([], $this->asRole('manager')->getJson('/api/v1/finance/cases/'.self::ID.'/authorization/blockers')->assertOk()->json('data'));
        $this->fin('authorization', ['quantity' => 0] + self::LETTER, 'manager')->assertStatus(422)->assertJsonPath('message', 'أكمل نطاق التعميد والكمية وموعد البدء المتوقع.');
        $this->fin('authorization', self::LETTER, 'finance')->assertForbidden();

        $l = $this->fin('authorization', self::LETTER, 'manager')->assertCreated()->json('data');
        $year = now()->year;
        $this->assertSame("AUTH-$year-00001", $l['authorizationNumber']);
        $this->assertSame('ISSUED', $l['status']);
        $this->assertSame($this->case()->beneficiary, $l['beneficiaryName']);
        $this->assertSame(self::COST, $l['amountHalalas']);
        $this->assertSame('جمعية راحة', $l['document']['org']['nameAr']);
        $this->assertSame('مركز عناية للرعاية المنزلية', $l['document']['provider']['name']);
        $this->assertArrayNotHasKey('nationalId', $l);
        $this->assertSame(['CONVERTED'], FundingReservation::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(2, LedgerEntry::query()->where('type', 'COMMITMENT_CONVERSION')->count());
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
        $this->assertSame(['ledger' => 5000000, 'reserved' => 0, 'committed' => 100000, 'available' => 4900000], $this->balance('WAL-001'));
        $this->assertSame(['approvalId' => $this->case()->finance['approvalId'], 'stage' => 'authorization_issued', 'configVersion' => 1, 'authorizationId' => $l['id']], $this->case()->finance);
        $this->fin('authorization', self::LETTER, 'manager')->assertStatus(422)->assertJsonPath('message', 'يوجد خطاب تعميد صادر لهذا الطلب.');
        $this->fin('release', ['reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'التمويل محول إلى التزام؛ استخدم إلغاء التعميد لعكسه.');

        // Provider cannot see the letter before assignment.
        $this->asRole('provider')->getJson("/api/v1/authorizations/{$l['id']}/document")->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية عرض خطاب التعميد هذا.');
        $this->assertSame([], $this->asRole('provider')->getJson('/api/v1/provider/assignments')->assertOk()->json('data'));

        $this->fin('assign', [], 'social')->assertForbidden();
        $asg = $this->fin('assign', [], 'manager')->assertCreated()->json('data');
        $this->assertSame('ASG-00001', $asg['id']);
        $c = $this->case();
        $this->assertSame(['assigned', 'PRV-0005', false], [$c->status, $c->provider_id, $c->provider_accepted]);
        $this->assertSame('provider_assigned', $c->finance['stage']);
        $this->assertDatabaseHas('notifications', ['title' => 'تم تعميدكم وإسناد خدمة إليكم', 'provider_id' => 'PRV-0005', 'case_id' => self::ID]);
        $this->fin('assign', [], 'manager')->assertStatus(422);
        $this->assertSame(1, ServiceAssignment::query()->count());

        // Provider portal: own assignment only, with letter + visit info.
        $this->assertSame([], $this->asRole('provider2')->getJson('/api/v1/provider/assignments')->assertOk()->json('data'));
        $this->asRole('provider2')->postJson("/api/v1/provider/assignments/{$asg['id']}/acknowledge")->assertForbidden()->assertJsonPath('message', 'هذا التعميد غير مسند إليك.');
        $this->asRole('provider2')->getJson("/api/v1/authorizations/{$l['id']}/document")->assertForbidden();
        $mine = $this->asRole('provider')->getJson('/api/v1/provider/assignments')->assertOk()->json('data');
        $this->assertSame($l['id'], $mine[0]['letter']['id']);
        $this->assertArrayNotHasKey('financialApprovalId', $mine[0]['letter']);
        $doc = $this->getJson("/api/v1/authorizations/{$l['id']}/document?lang=ar")->assertOk()->json('data');
        $this->assertTrue($doc['model']['official']);
        $this->assertSame("RAHA_Authorization_AUTH-$year-00001.pdf", $doc['filename']);
        $this->assertTrue($doc['exportable']);
        $this->assertStringNotContainsString($c->beneficiary, json_encode($doc, JSON_UNESCAPED_UNICODE));

        $this->postJson("/api/v1/provider/assignments/{$asg['id']}/acknowledge")->assertOk()->assertJsonPath('data.status', 'ACKNOWLEDGED');
        $c = $this->case();
        $this->assertSame(['assigned', true], [$c->status, $c->provider_accepted]);
        $this->postJson("/api/v1/provider/assignments/{$asg['id']}/acknowledge")->assertStatus(422)->assertJsonPath('message', 'تم تأكيد الاستلام مسبقًا.');
        $this->assertDatabaseHas('notifications', ['title' => 'أكد مقدم الخدمة استلام التعميد', 'case_id' => self::ID]);

        // Audit trail: every step with actor and stage codes.
        $events = FinanceEvent::query()->where('case_id', self::ID)->get();
        $this->assertGreaterThanOrEqual(8, $events->count());
        $this->assertTrue($events->every(fn ($e) => $e->actor && $e->at && $e->to_stage));
        $this->assertTrue($events->contains(fn ($e) => $e->wallet_id === null && $e->amount_halalas === self::COST));
    }

    public function test_letter_cannot_be_issued_before_mandatory_approvals(): void
    {
        $this->toReserved();
        $blockers = $this->asRole('manager')->getJson('/api/v1/finance/cases/'.self::ID.'/authorization/blockers')->assertOk()->json('data');
        $this->assertSame(['لا يوجد اعتماد نهائي من المدير التنفيذي.'], $blockers);
        $this->fin('authorization', self::LETTER, 'manager')->assertStatus(422)->assertJsonPath('message', 'لا يوجد اعتماد نهائي من المدير التنفيذي.');
        $this->fin('assign', [], 'manager')->assertStatus(422)->assertJsonPath('message', 'لا يمكن إسناد الخدمة قبل إصدار خطاب التعميد.');
        $this->asRole('social')->getJson('/api/v1/finance/cases/'.self::ID.'/authorization/blockers')->assertForbidden();
    }

    public function test_multiple_visit_requests_need_an_approved_visit_count(): void
    {
        $this->prelim(self::ID, ['visit_plan' => ['deliveryType' => 'multiple_visits', 'serviceId' => 'SRV-001', 'serviceName' => 'تمريض منزلي', 'snapshotAt' => now_iso(), 'changes' => []]]);
        $this->fin('start')->assertOk();
        $this->allocate()->assertOk();
        $this->fin('approve-split')->assertOk();
        $this->fin('reserve')->assertOk();
        $this->fin('executive/approve', ['confirmed' => true], 'executive')->assertOk()
            ->assertJsonPath('data.blockers', ['يجب تحديد عدد الزيارات المعتمد للخدمة متعددة الزيارات قبل إصدار خطاب التعميد.']);
        $c = $this->case();
        $c->visit_plan = array_merge($c->visit_plan, ['approvedVisitCount' => 9]);
        $c->save();
        $l = $this->fin('authorization', self::LETTER, 'manager')->assertCreated()->json('data');
        $this->assertSame(['multiple_visits', 9], [$l['document']['deliveryType'], $l['document']['approvedVisitCount']]);
        $model = $this->asRole('manager')->getJson("/api/v1/authorizations/{$l['id']}/document")->assertOk()->json('data.model');
        $this->assertSame(9, $model['approvedVisitCount']);
    }

    public function test_cancel_reverses_commitments_and_returns_to_review(): void
    {
        $l = $this->toIssued();
        $this->asRole('manager')->postJson("/api/v1/authorizations/{$l['id']}/cancel", ['reason' => ''])->assertStatus(422)->assertJsonPath('message', 'يجب إدخال السبب.');
        $this->asRole('finance')->postJson("/api/v1/authorizations/{$l['id']}/cancel", ['reason' => 'سحب'])->assertForbidden();
        $this->asRole('manager')->postJson("/api/v1/authorizations/{$l['id']}/cancel", ['reason' => 'سحب التعميد'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame(5000000, $this->balance('WAL-001')['available']);
        $this->assertSame(['REVERSED'], FundingReservation::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(2, LedgerEntry::query()->where('type', 'COMMITMENT_REVERSAL')->count());
        $c = $this->case();
        $this->assertSame('in_review', $c->finance['stage']);
        $this->assertArrayNotHasKey('authorizationId', $c->finance);
        $this->fin('release', ['reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'لا توجد حجوزات نشطة لتحريرها.');
        $this->asRole('manager')->postJson("/api/v1/authorizations/{$l['id']}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'يمكن إلغاء خطاب صادر فقط.');
        // Staff still read the cancelled copy, watermarked and not official.
        $this->getJson("/api/v1/authorizations/{$l['id']}/document")->assertOk()->assertJsonPath('data.model.watermark', 'cancelled')->assertJsonPath('data.model.official', false);
        // The funding cycle can run again and a new number is issued.
        $this->fin('approve-split')->assertOk();
        $this->fin('reserve')->assertOk();
        $this->fin('executive/approve', ['confirmed' => true], 'executive')->assertOk();
        $l2 = $this->fin('authorization', self::LETTER, 'manager')->assertCreated()->json('data');
        $this->assertSame('AUTH-'.now()->year.'-00002', $l2['authorizationNumber']);
    }

    public function test_replace_links_versions_and_repoints_the_assignment(): void
    {
        $l = $this->toIssued();
        $this->fin('assign', [], 'manager')->assertCreated();
        Setting::write('organization', ['nameAr' => 'اسم بعد الإصدار', 'nameEn' => '', 'address' => '', 'phone' => '', 'email' => '', 'registrationNumber' => '']);
        $this->asRole('manager')->postJson("/api/v1/authorizations/{$l['id']}/replace", self::LETTER)->assertStatus(422)->assertJsonPath('message', 'يجب إدخال السبب.');
        $n = $this->postJson("/api/v1/authorizations/{$l['id']}/replace", ['quantity' => 12, 'reason' => 'تصحيح الكمية'] + self::LETTER)->assertCreated()->json('data');
        $this->assertSame($l['id'], $n['replacesId']);
        $this->assertSame(2, $n['version']);
        $this->assertSame(12, (int) $n['quantity']);
        $this->assertNotSame($l['authorizationNumber'], $n['authorizationNumber']);
        $old = ServiceAuthorization::query()->find($l['id']);
        $this->assertSame(['REPLACED', $n['id'], 'تصحيح الكمية'], [$old->status, $old->replaced_by_id, $old->closed_reason]);
        $asg = ServiceAssignment::query()->first();
        $this->assertSame([$n['id'], 12.0], [$asg->authorization_id, $asg->quantity]);
        $this->assertSame($n['id'], $this->case()->finance['authorizationId']);
        $this->assertDatabaseHas('notifications', ['title' => 'تم استبدال خطاب التعميد', 'provider_id' => 'PRV-0005']);
        // Snapshots: the old letter keeps its identity, the new one takes today's.
        $this->assertSame('جمعية راحة', $this->getJson("/api/v1/authorizations/{$l['id']}/document")->assertOk()->json('data.model.org.nameAr'));
        $newDoc = $this->getJson("/api/v1/authorizations/{$n['id']}/document")->assertOk()->json('data.model');
        $this->assertSame(['اسم بعد الإصدار', $l['authorizationNumber'], null], [$newDoc['org']['nameAr'], $newDoc['replacesNumber'], $newDoc['watermark']]);
        $this->asRole('provider')->getJson("/api/v1/authorizations/{$n['id']}/document")->assertOk();
        $this->asRole('provider')->getJson("/api/v1/authorizations/{$l['id']}/document")->assertForbidden();
        $this->assertSame($n['id'], $this->getJson('/api/v1/provider/assignments')->json('data.0.letter.id'));
        // Cancelling after assignment is refused.
        $this->asRole('manager')->postJson("/api/v1/authorizations/{$n['id']}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'لا يمكن إلغاء التعميد بعد إسناد الخدمة.');
    }

    public function test_draft_preview_saves_and_numbers_nothing(): void
    {
        $this->toExecutiveApproved();
        $before = [ServiceAuthorization::query()->count(), FinanceEvent::query()->count(), \App\Models\AuditLog::query()->count(), \App\Models\Notification::query()->count()];
        $d = $this->asRole('manager')->postJson('/api/v1/finance/cases/'.self::ID.'/authorization/preview', self::LETTER)->assertOk()->json('data');
        $this->assertSame(['official' => false, 'watermark' => 'draft', 'number' => ''], array_intersect_key($d['model'], array_flip(['official', 'watermark', 'number'])));
        $this->assertFalse($d['exportable']);
        $this->assertSame('لا يمكن تنزيل أو طباعة مسودة على أنها خطاب تعميد رسمي.', $d['exportError']);
        $this->assertSame('RAHA_Authorization_DRAFT.pdf', $d['filename']);
        $this->assertSame($before, [ServiceAuthorization::query()->count(), FinanceEvent::query()->count(), \App\Models\AuditLog::query()->count(), \App\Models\Notification::query()->count()]);
        $this->asRole('finance')->postJson('/api/v1/finance/cases/'.self::ID.'/authorization/preview', self::LETTER)->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية عرض خطاب التعميد هذا.');
    }

    public function test_document_access_rules_for_staff(): void
    {
        $l = $this->toIssued();
        foreach (['auditor', 'social', 'beneficiary'] as $role) {
            $res = $this->asRole($role)->getJson("/api/v1/authorizations/{$l['id']}/document");
            $this->assertContains($res->status(), [403], $role);
        }
        $this->asRole('manager')->getJson('/api/v1/authorizations/AUL-none/document')->assertForbidden();
        $this->asRole('executive')->getJson("/api/v1/authorizations/{$l['id']}/document")->assertOk()->assertJsonPath('data.model.number', $l['authorizationNumber']);
        $this->asRole('executive')->getJson("/api/v1/authorizations/{$l['id']}/document?lang=en")->assertOk()->assertJsonPath('data.lang', 'en');
        $this->asRole('manager')->getJson('/api/v1/authorizations')->assertOk()->assertJsonPath('data.0.id', $l['id'])->assertJsonPath('data.0.statusLabel', 'صادر');
        $this->asRole('manager')->getJson("/api/v1/authorizations/{$l['id']}")->assertOk()->assertJsonPath('data.letter.authorizationNumber', $l['authorizationNumber']);
        $this->asRole('social')->getJson('/api/v1/authorizations')->assertForbidden();
        $this->asRole('provider')->getJson('/api/v1/authorizations')->assertForbidden();
        // Re-reading reuses the same number and record.
        $this->asRole('manager')->getJson("/api/v1/authorizations/{$l['id']}/document")->assertOk()->assertJsonPath('data.filename', "RAHA_Authorization_{$l['authorizationNumber']}.pdf");
        $this->assertSame(1, ServiceAuthorization::query()->count());
    }

    public function test_finance_rejection_blocks_letters_and_unauthorized_roles_cannot_approve(): void
    {
        $this->prelim();
        $this->fin('start')->assertOk();
        foreach (['auditor', 'medical', 'social', 'relations', 'team'] as $role) {
            $this->fin('approve-split', [], $role)->assertForbidden();
            $this->fin('executive/approve', ['confirmed' => true], $role)->assertForbidden();
            $this->fin('authorization', self::LETTER, $role)->assertForbidden();
        }
        $this->fin('approve-split', [], 'provider')->assertForbidden();
        $this->fin('authorization', self::LETTER, 'manager')->assertStatus(422);
        $this->fin('reject', ['reason' => 'غير مؤهل'])->assertOk();
        $this->assertSame('rejected', $this->case()->status);
        $this->assertSame(0, ServiceAuthorization::query()->count());
    }
}
