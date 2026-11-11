<?php

namespace Modules\Finance\Tests\Feature;

use Modules\Cases\Models\CaseTask;
use Modules\Finance\Models\FinanceConfig;
use Modules\Finance\Models\FinanceEvent;
use Modules\Finance\Models\FinancialApproval;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;

/** Accountant review, reservation and executive decisions (front phase5_finance.test.ts). */
class FundingFlowTest extends FinanceTestCase
{
    private const INSUFFICIENT = 'الرصيد المتاح في المحفظة غير كافٍ لتغطية المبلغ المطلوب.';

    public function test_preliminary_approval_enters_pending_review_and_start_captures_config_version(): void
    {
        $this->prelim();
        $this->assertSame(['approvalId' => '', 'stage' => 'pending_review', 'configVersion' => 1], $this->case()->finance);
        $this->fin('start', [], 'social')->assertForbidden();
        $res = $this->fin('start')->assertOk();
        $res->assertJsonPath('data.approval.stage', 'in_review')->assertJsonPath('data.approval.order', 'RESERVE_THEN_APPROVE')
            ->assertJsonPath('data.approval.totalCostHalalas', self::COST)->assertJsonPath('data.approval.costingBasis', 'عرض السعر المختار QT-1')
            ->assertJsonPath('data.case.finance.stage', 'in_review');
        $this->assertNotContains('start', array_column($res->json('data.actions'), 'key'));
        $this->fin('start')->assertStatus(422)->assertJsonPath('message', 'الطلب ليس بانتظار المراجعة المالية.');
    }

    public function test_allocations_must_reconcile_with_the_selected_quotation(): void
    {
        $this->prelim();
        $this->fin('start')->assertOk();
        $this->allocate($this->split(100000, 100000, 60000))->assertStatus(422)
            ->assertJsonPath('message', 'مجموع التوزيع (2,600.00 SAR) لا يساوي تكلفة الخدمة (3,000.00 SAR).');
        $this->fin('allocations', ['totalCostHalalas' => 1000, 'allocations' => [['id' => 'x', 'sourceType' => 'BENEFICIARY', 'amountHalalas' => 1000]]])
            ->assertStatus(422)->assertJsonPath('message', 'تكلفة الخدمة يجب أن تطابق العرض المختار.');
        $this->allocate([...$this->split(), ['id' => 'n', 'sourceType' => 'THIRD_PARTY', 'amountHalalas' => -1]])->assertStatus(422)->assertJsonPath('message', 'لا يمكن إدخال مبالغ سالبة.');
        $this->allocate([['sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-002', 'amountHalalas' => self::COST]])->assertStatus(422)
            ->assertJsonPath('message', 'المحفظة محفظة غسيل الكلى غير مؤهلة لهذه الخدمة أو موقوفة.');
        $this->fin('allocations', ['totalCostHalalas' => self::COST, 'allocations' => $this->split()], 'executive')->assertForbidden();
        $res = $this->allocate()->assertOk()->assertJsonPath('data.approval.version', 1);
        $this->assertCount(3, $res->json('data.allocations'));
        $this->assertSame('محفظة الرعاية المنزلية', $res->json('data.allocations.1.walletName'));
    }

    public function test_multi_wallet_reservation_reduces_available_but_not_ledger(): void
    {
        $this->toReserved();
        $this->assertSame(['ledger' => 5000000, 'reserved' => 100000, 'committed' => 0, 'available' => 4900000], $this->balance('WAL-001'));
        $this->assertSame(2000000 - 140000, $this->balance('WAL-004')['available']);
        $this->assertSame(2, FundingReservation::query()->count());
        $this->assertSame(2, LedgerEntry::query()->where('type', 'RESERVATION')->count());
        $this->assertSame('pending_executive', $this->case()->finance['stage']);
        $this->assertDatabaseHas('notifications', ['title' => 'طلب بانتظار الاعتماد النهائي', 'case_id' => self::ID]);

        $dash = $this->asRole('finance')->getJson('/api/v1/finance/dashboard')->assertOk();
        $dash->assertJsonPath('data.totals.reserved', 240000)->assertJsonPath('data.pendingExecutive', 1);
        $this->asRole('manager')->getJson('/api/v1/finance/dashboard')->assertOk()->assertJsonPath('data.totals', null)->assertJsonPath('data.wallets', []);
        $row = collect($this->asRole('executive')->getJson('/api/v1/finance/requests?stage=pending_executive')->assertOk()->json('data'))->firstWhere('caseId', self::ID);
        $this->assertSame(60000, $row['beneficiaryShareHalalas']);
        $this->assertSame(self::COST, $row['totalCostHalalas']);
        $this->assertSame(240000, $row['associationShareHalalas']);
        $this->assertSame('pending_executive', $row['stage']);
    }

    public function test_insufficient_funds_blocks_approval_and_marks_funding_incomplete(): void
    {
        $this->prelim();
        $this->fin('start')->assertOk();
        $this->allocate([['id' => 'a', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-005', 'amountHalalas' => 240000], ['id' => 'b', 'sourceType' => 'BENEFICIARY', 'amountHalalas' => 60000]])->assertOk();
        $this->fin('approve-split')->assertStatus(422)->assertJsonPath('message', self::INSUFFICIENT)
            ->assertJsonPath('shortfalls.0', ['walletId' => 'WAL-005', 'required' => 240000, 'available' => 100000, 'shortfall' => 140000]);
        $this->assertSame('funding_incomplete', $this->case()->finance['stage']);
        $this->assertSame(100000, $this->balance('WAL-005')['available']);
        $this->assertDatabaseHas('finance_events', ['case_id' => self::ID, 'to_stage' => 'funding_incomplete', 'wallet_id' => 'WAL-005', 'amount_halalas' => 140000]);
    }

    public function test_multi_wallet_reservation_cannot_partially_succeed(): void
    {
        $this->prelim();
        $this->fin('start')->assertOk();
        $this->allocate([
            ['id' => 'a', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-001', 'amountHalalas' => 150000], ['id' => 'b', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-005', 'amountHalalas' => 90000],
            ['id' => 'c', 'sourceType' => 'BENEFICIARY', 'amountHalalas' => 60000],
        ])->assertOk();
        $this->fin('approve-split')->assertOk();
        $this->asRole('finance')->postJson('/api/v1/wallets/WAL-005/adjust', ['amountHalalas' => -50000, 'note' => 'تصحيح'])->assertOk();
        $this->fin('reserve')->assertStatus(422)->assertJsonPath('message', self::INSUFFICIENT);
        $this->assertSame(0, FundingReservation::query()->count());
        $this->assertSame(5000000, $this->balance('WAL-001')['available']);
        $this->assertSame('funding_incomplete', $this->case()->finance['stage']);
        $this->assertNull(FinancialApproval::query()->first()->split_approved);
    }

    public function test_duplicate_reservation_and_double_release_are_blocked(): void
    {
        $this->toReserved();
        $this->fin('reserve')->assertStatus(422);
        $this->assertSame(2, FundingReservation::query()->count());
        $this->fin('release', ['reason' => ' '])->assertStatus(422)->assertJsonPath('message', 'يجب إدخال السبب.');
        $this->fin('release', ['reason' => 'إعادة توزيع'])->assertOk()->assertJsonPath('data.approval.stage', 'in_review')->assertJsonPath('data.approval.reserved', false);
        $this->assertSame(5000000, $this->balance('WAL-001')['available']);
        $this->assertSame(2, LedgerEntry::query()->where('type', 'RESERVATION_RELEASE')->count());
        $this->assertSame(['RELEASED'], FundingReservation::query()->pluck('status')->unique()->values()->all());
        $this->fin('release', ['reason' => 'مرة ثانية'])->assertStatus(422)->assertJsonPath('message', 'لا توجد حجوزات نشطة لتحريرها.');
    }

    public function test_return_to_care_sends_the_case_back_to_manager_approval_with_a_task(): void
    {
        $this->toReserved();
        $this->fin('return-to-care', ['reason' => 'مراجعة التكلفة'])->assertOk();
        $c = $this->case();
        $this->assertSame('manager_approval', $c->status);
        $this->assertNull($c->finance);
        $this->assertTrue(CaseTask::query()->where('case_id', self::ID)->where('stage', 'manager_approval')->where('status', 'new')->exists());
        $this->assertSame(['RELEASED'], FundingReservation::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(5000000, $this->balance('WAL-001')['available']);
        $this->assertSame('funding_rejected', FinancialApproval::query()->first()->stage);
        $this->assertDatabaseHas('notifications', ['title' => 'طلب أعيد من المراجعة المالية', 'case_id' => self::ID]);
    }

    public function test_rejected_funding_cancels_reservations_and_ends_the_request(): void
    {
        $this->toReserved();
        $this->fin('reject', ['reason' => ''])->assertStatus(422)->assertJsonPath('message', 'يجب إدخال السبب.');
        $this->fin('reject', ['reason' => 'غير مؤهل'])->assertOk();
        $this->assertSame('rejected', $this->case()->status);
        $this->assertSame(0, $this->balance('WAL-004')['reserved']);
        $this->assertSame(['CANCELLED'], FundingReservation::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(2, LedgerEntry::query()->where('type', 'RESERVATION_CANCELLED')->count());
        $this->assertSame(0, LedgerEntry::query()->where('type', 'RESERVATION_RELEASE')->count());
        $this->assertDatabaseHas('case_activities', ['case_id' => self::ID, 'visibility' => 'beneficiary', 'text' => 'لم يتم قبول طلبك.']);
        $this->fin('executive/approve', ['confirmed' => true], 'executive')->assertStatus(422);
    }

    public function test_executive_decisions(): void
    {
        $this->toReserved();
        $this->fin('reserve', [], 'executive')->assertForbidden();
        $this->fin('executive/approve', ['confirmed' => true], 'finance')->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        $this->fin('executive/approve', ['confirmed' => false], 'executive')->assertStatus(422)->assertJsonPath('message', 'يجب تأكيد الاعتماد النهائي.');
        $this->fin('executive/return', ['reason' => 'مراجعة'], 'executive')->assertOk()->assertJsonPath('data.approval.stage', 'in_review');
        $this->assertSame(0, $this->balance('WAL-001')['reserved']);
        $this->fin('approve-split')->assertOk();
        $this->fin('reserve')->assertOk();
        $res = $this->fin('executive/approve', ['comment' => 'موافق', 'confirmed' => true], 'executive')->assertOk();
        $res->assertJsonPath('data.approval.executive.decision', 'approved')->assertJsonPath('data.approval.executive.fundingVersion', 1)
            ->assertJsonPath('data.approval.executive.quotationId', 'QT-1')->assertJsonPath('data.case.finance.stage', 'executive_approved');
        $this->assertSame([], $res->json('data.blockers'));
        $this->assertDatabaseHas('notifications', ['title' => 'طلب جاهز لإصدار خطاب التعميد', 'case_id' => self::ID]);
    }

    public function test_executive_reject_cancels_reservations(): void
    {
        $this->toReserved();
        $this->fin('executive/reject', ['reason' => 'غير مبرر'], 'executive')->assertOk();
        $this->assertSame('rejected', $this->case()->status);
        $this->assertSame('executive_rejected', FinancialApproval::query()->first()->stage);
        $this->assertSame(2, LedgerEntry::query()->where('type', 'RESERVATION_CANCELLED')->count());
        $this->assertSame(['CANCELLED'], FundingReservation::query()->pluck('status')->unique()->values()->all());
    }

    public function test_executive_approval_cannot_bypass_required_funding(): void
    {
        $this->prelim();
        $this->fin('start')->assertOk();
        $this->allocate()->assertOk();
        $this->fin('approve-split')->assertOk();
        $this->fin('executive/approve', ['confirmed' => true], 'executive')->assertStatus(422)->assertJsonPath('message', 'الطلب ليس بانتظار الاعتماد النهائي.');
    }

    public function test_config_update_creates_a_version_and_running_approvals_keep_their_order(): void
    {
        $this->prelim();
        $this->fin('start')->assertOk(); // started under v1 = RESERVE_THEN_APPROVE
        $this->asRole('finance')->putJson('/api/v1/finance/config', ['order' => 'APPROVE_THEN_RESERVE'])->assertForbidden();
        $this->asRole('admin')->putJson('/api/v1/finance/config', ['order' => 'APPROVE_THEN_RESERVE', 'signatoryName' => 'أ. سالم', 'signatoryTitle' => ''])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.signatoryTitle', 'المدير التنفيذي');
        $this->assertSame(2, FinanceConfig::query()->count());
        $this->assertSame('RESERVE_THEN_APPROVE', FinanceConfig::ofVersion(1)->order);
        $this->allocate()->assertOk();
        $this->fin('approve-split')->assertOk()->assertJsonPath('data.approval.stage', 'split_approved')->assertJsonPath('data.approval.order', 'RESERVE_THEN_APPROVE');

        // A request entering finance after the change runs APPROVE_THEN_RESERVE.
        $id = 'REQ-000116';
        $this->prelim($id);
        $this->assertSame(2, $this->case($id)->finance['configVersion']);
        $this->fin('start', [], 'finance', $id)->assertOk()->assertJsonPath('data.approval.order', 'APPROVE_THEN_RESERVE')->assertJsonPath('data.config.version', 2);
        $this->allocate(null, $id)->assertOk();
        $this->fin('approve-split', [], 'finance', $id)->assertOk()->assertJsonPath('data.approval.stage', 'pending_executive');
        $this->fin('reserve', [], 'finance', $id)->assertStatus(422)->assertJsonPath('message', 'الحجز يتم بعد الاعتماد النهائي وفق الإعداد الحالي.');
        $this->fin('executive/approve', ['confirmed' => true], 'executive', $id)->assertOk()->assertJsonPath('data.approval.stage', 'executive_approved')
            ->assertJsonPath('data.blockers.0', 'التمويل غير محجوز.');
        $this->assertDatabaseHas('notifications', ['title' => 'اعتماد نهائي — بانتظار حجز التمويل', 'case_id' => $id]);
        $this->fin('reserve', [], 'finance', $id)->assertOk()->assertJsonPath('data.approval.stage', 'executive_approved')->assertJsonPath('data.approval.reserved', true)
            ->assertJsonPath('data.blockers', []);
    }

    public function test_finance_events_store_stage_codes_and_need_ledger_permission(): void
    {
        $this->toReserved();
        $this->assertSame(['pending_review', 'in_review'], [FinanceEvent::query()->where('action', 'بدء المراجعة المالية')->value('from_stage'), FinanceEvent::query()->where('action', 'بدء المراجعة المالية')->value('to_stage')]);
        $events = $this->asRole('finance')->getJson('/api/v1/finance/events?caseId='.self::ID)->assertOk()->json('data');
        $this->assertCount(4, $events);
        $last = collect($events)->firstWhere('toStageCode', 'pending_executive');
        $this->assertSame('بانتظار اعتماد المدير التنفيذي', $last['toStage']);
        $this->assertSame('split_approved', $last['fromStageCode']);
        $this->asRole('manager')->getJson('/api/v1/finance/events')->assertForbidden();
        $this->asRole('social')->getJson('/api/v1/finance/dashboard')->assertForbidden();
    }

    public function test_case_summary_lists_actions_per_stage_and_permission(): void
    {
        $this->prelim();
        $keys = fn (string $role) => array_column($this->asRole($role)->getJson('/api/v1/finance/cases/'.self::ID)->assertOk()->json('data.actions'), 'key');
        $this->assertSame(['start'], $keys('finance'));
        $this->assertSame([], $keys('executive'));
        $this->fin('start')->assertOk();
        $this->allocate()->assertOk();
        $this->assertSame(['approve-split', 'return-to-care', 'reject'], $keys('finance'));
        $this->fin('approve-split')->assertOk();
        $this->assertSame(['reserve', 'return-to-care', 'reject'], $keys('finance'));
        $this->fin('reserve')->assertOk();
        $this->assertSame(['release'], $keys('finance'));
        $this->assertSame(['executive/approve', 'executive/return', 'executive/reject'], $keys('executive'));
        $this->assertSame([], $keys('manager'));
        $this->assertSame([], $this->asRole('manager')->getJson('/api/v1/finance/cases/'.self::ID)->json('data.wallets'));
        $this->assertNotEmpty($this->asRole('finance')->getJson('/api/v1/finance/cases/'.self::ID)->json('data.wallets'));
    }
}
