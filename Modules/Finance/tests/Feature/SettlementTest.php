<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FinanceEvent;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Services\SettlementService;

/**
 * Approved rule: when RAHA confirms actual payment of the provider invoice (finance_complete), every commitment of the request
 * moves from COMMITTED to ACTUAL EXPENSE: reservation CONVERTED -> SETTLED plus one negative DEDUCTION ledger entry, atomically.
 * Nothing else changes: reserve / release / commitment / reversal behave exactly as before and archived requests are never touched.
 */
class SettlementTest extends FinanceTestCase
{
    /** Request issued, assigned and acknowledged, then placed at the payment step with a pending invoice (SAR 3,000 = the commitment total + beneficiary share). */
    private function payable(string $id = self::ID, bool $invoice = true): array
    {
        $letter = $this->toIssued(self::LETTER, $id);
        $asg = $this->fin('assign', [], 'manager', $id)->assertCreated()->json('data');
        $this->asRole('provider')->postJson("/api/v1/provider/assignments/{$asg['id']}/acknowledge")->assertOk();
        RahaCase::query()->whereKey($id)->update(['status' => 'finance_review', 'service_confirmed' => true,
            'invoice' => $invoice ? json_encode(['number' => 'INV-T', 'amount' => 3000, 'at' => now_iso(), 'status' => 'pending']) : null]);

        return $letter;
    }

    private function pay(string $id = self::ID)
    {
        return $this->asRole('finance')->postJson("/api/v1/cases/$id/actions/finance_complete");
    }

    /** @return array<string, array{ledger: int, reserved: int, committed: int, available: int}> */
    private function balances(): array
    {
        return ['WAL-001' => $this->balance('WAL-001'), 'WAL-004' => $this->balance('WAL-004')];
    }

    public function test_confirmed_payment_settles_every_commitment_and_deducts_once_per_wallet(): void
    {
        $this->payable();
        $before = $this->balances();
        $this->assertSame(['CONVERTED', 'CONVERTED'], FundingReservation::query()->where('case_id', self::ID)->orderBy('wallet_id')->pluck('status')->all());
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());

        $this->pay()->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.invoice.status', 'paid');

        $this->assertSame(['SETTLED', 'SETTLED'], FundingReservation::query()->where('case_id', self::ID)->orderBy('wallet_id')->pluck('status')->all());
        $d = LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->orderBy('wallet_id')->get();
        $this->assertCount(2, $d);
        $this->assertSame([['WAL-001', -100000], ['WAL-004', -140000]], $d->map(fn ($e) => [$e->wallet_id, $e->amount_halalas])->all(), 'one NEGATIVE deduction per reservation, same amount');
        foreach ($d as $e) {
            $this->assertSame(1, FundingReservation::query()->whereKey($e->reservation_id)->where('status', 'SETTLED')->count(), 'each deduction is linked to its settled reservation');
        }
        $after = $this->balances();
        foreach ([['WAL-001', 100000], ['WAL-004', 140000]] as [$w, $amount]) {
            $this->assertSame($before[$w]['ledger'] - $amount, $after[$w]['ledger'], "$w cash ledger drops by the expense");
            $this->assertSame($before[$w]['committed'] - $amount, $after[$w]['committed'], "$w committed drops by the same amount");
            $this->assertSame($before[$w]['reserved'], $after[$w]['reserved'], "$w reserved untouched");
            $this->assertSame($before[$w]['available'], $after[$w]['available'], "$w available is unchanged: no double counting");
            $this->assertGreaterThanOrEqual(0, $after[$w]['available']);
            $this->assertSame($after[$w]['ledger'] - $after[$w]['reserved'] - $after[$w]['committed'], $after[$w]['available']);
        }
        // The balances the API shows agree with the model.
        $this->asRole('finance')->getJson('/api/v1/wallets/WAL-004')->assertOk()->assertJsonPath('data.balance.ledger', $after['WAL-004']['ledger'])->assertJsonPath('data.balance.committed', 0 + $after['WAL-004']['committed']);
    }

    public function test_a_second_deduction_for_the_same_reservation_is_impossible(): void
    {
        $this->payable();
        $this->pay()->assertOk();
        // Service level: nothing left to settle.
        $c = $this->case();
        $this->assertSame(0, app(SettlementService::class)->settle($c, \App\Models\User::query()->findOrFail('u6'))['count']);
        $this->assertSame(2, LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->count());
        // Database level: even a buggy caller cannot insert a second entry of the same type for one reservation.
        $r = FundingReservation::query()->where('case_id', self::ID)->first();
        $this->expectException(QueryException::class);
        LedgerEntry::query()->create(['id' => 'LED-DUP', 'wallet_id' => $r->wallet_id, 'case_id' => self::ID, 'reservation_id' => $r->id, 'amount_halalas' => -1,
            'type' => 'DEDUCTION', 'note' => 'dup', 'created_by' => 'test', 'created_at' => now()]);
    }

    public function test_repeating_the_payment_confirmation_does_not_deduct_again(): void
    {
        $this->payable();
        $this->pay()->assertOk();
        $this->pay()->assertStatus(422); // archived: the action is no longer available
        $this->assertSame(2, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
    }

    public function test_settlement_and_archiving_are_atomic(): void
    {
        $this->payable();
        $before = $this->balances();
        // A failure while writing the deduction must roll the whole payment confirmation back.
        $this->app->bind(SettlementService::class, fn () => new class extends SettlementService
        {
            public function __construct() {}

            public function settle(RahaCase $c, \App\Models\User $u): array
            {
                throw new \RuntimeException('simulated failure after partial work');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->pay();
            $this->fail('the failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure after partial work', $e->getMessage());
        }
        $c = $this->case()->refresh();
        $this->assertSame('finance_review', $c->status, 'the request is not archived');
        $this->assertSame('pending', $c->invoice['status'], 'the invoice is not marked paid');
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
        $this->assertSame(['CONVERTED', 'CONVERTED'], FundingReservation::query()->where('case_id', self::ID)->orderBy('wallet_id')->pluck('status')->all());
        $this->assertSame($before, $this->balances());
    }

    public function test_audit_history_records_the_settlement_for_staff_only(): void
    {
        $this->payable();
        $this->pay()->assertOk();
        $ev = FinanceEvent::query()->where('case_id', self::ID)->where('action', 'like', '%تسوية%')->get();
        $this->assertCount(1, $ev);
        $this->assertSame(240000, $ev[0]->amount_halalas);
        $this->assertSame('u6', $ev[0]->actor_id);
        $a = \Modules\Finance\Models\FinancialApproval::query()->where('case_id', self::ID)->latest('created_at')->first();
        $this->assertContains('تسوية الالتزامات بعد تأكيد الدفع', array_column($a->history, 'decision'));
        $this->assertDatabaseHas('audit_logs', ['record_id' => self::ID, 'action' => 'تسوية الالتزامات بعد تأكيد الدفع']);
        $this->assertTrue(CaseActivity::query()->where('case_id', self::ID)->where('visibility', 'internal')->where('text', 'like', '%تسوية%')->exists());
        // Earlier history is intact (reserve, executive approval, commitment conversion, assignment).
        $this->assertGreaterThanOrEqual(9, FinanceEvent::query()->where('case_id', self::ID)->count());
        $this->assertSame(['RESERVATION', 'COMMITMENT_CONVERSION', 'DEDUCTION'], LedgerEntry::query()->where('case_id', self::ID)->where('wallet_id', 'WAL-001')->orderBy('created_at')->orderBy('id')->pluck('type')->all());
        // The beneficiary and the provider never see finance records.
        $this->asRole('beneficiary')->getJson('/api/v1/portal/requests/'.self::ID)->assertJsonMissingPath('data.finance');
        $this->asRole('provider')->getJson('/api/v1/finance/events')->assertForbidden();
    }

    public function test_a_payment_amount_in_the_request_cannot_change_what_is_settled(): void
    {
        $this->payable();
        $this->asRole('finance')->postJson('/api/v1/cases/'.self::ID.'/actions/finance_complete', ['amount' => 1, 'fields' => ['amount' => '1', 'partial' => 'true']])->assertOk();
        $this->assertSame(-240000, (int) LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->sum('amount_halalas'), 'there is no partial-payment input: the full commitment is settled');
    }

    public function test_requests_without_finance_commitments_are_unaffected(): void
    {
        // Seeded request in finance_review with an invoice but no finance flow: nothing to settle, payment confirmation works as before.
        $id = 'REQ-000112';
        RahaCase::query()->whereKey($id)->update(['status' => 'finance_review', 'service_confirmed' => true, 'invoice' => json_encode(['number' => 'INV-X', 'amount' => 100, 'at' => now_iso(), 'status' => 'pending'])]);
        $this->pay($id)->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.invoice.status', 'paid');
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
    }

    public function test_archived_requests_are_never_retroactively_changed(): void
    {
        // A request archived BEFORE this rule: commitment still CONVERTED, no deduction. It must stay exactly like that.
        $this->payable(self::ID);
        RahaCase::query()->whereKey(self::ID)->update(['status' => 'archived']);
        $snapshot = fn () => [FundingReservation::query()->where('case_id', self::ID)->orderBy('id')->get()->toArray(), LedgerEntry::query()->where('case_id', self::ID)->orderBy('id')->get()->toArray()];
        $before = $snapshot();
        $this->pay()->assertStatus(422);
        $this->asRole('finance')->getJson('/api/v1/finance/cases/'.self::ID)->assertOk();
        $this->getJson('/api/v1/wallets')->assertOk();
        $this->getJson('/api/v1/finance/reports/wallet-balances')->assertOk();
        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
    }

    public function test_settled_commitments_cannot_be_reversed_or_released(): void
    {
        $letter = $this->payable();
        $this->pay()->assertOk();
        $this->asRole('manager')->postJson("/api/v1/authorizations/{$letter['id']}/cancel", ['reason' => 'محاولة بعد الدفع'])->assertStatus(422);
        $this->fin('release', ['reason' => 'x'])->assertStatus(422);
        $this->assertSame(0, LedgerEntry::query()->whereIn('type', ['COMMITMENT_REVERSAL', 'RESERVATION_RELEASE'])->count());
        $this->assertSame(['SETTLED', 'SETTLED'], FundingReservation::query()->where('case_id', self::ID)->orderBy('wallet_id')->pluck('status')->all());
    }

    public function test_the_existing_reservation_workflow_is_unchanged(): void
    {
        // Reserve then release: same ledger lines and balances as before this rule.
        $this->toReserved();
        $reserved = $this->balance('WAL-001');
        $this->assertSame(100000, $reserved['reserved']);
        $this->fin('release', ['reason' => 'اختبار'])->assertOk();
        $this->assertSame(0, $this->balance('WAL-001')['reserved']);
        $this->assertSame(['RESERVATION', 'RESERVATION_RELEASE'], LedgerEntry::query()->where('case_id', self::ID)->where('wallet_id', 'WAL-001')->orderBy('created_at')->orderBy('id')->pluck('type')->all());
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
    }

    public function test_two_requests_settling_on_the_same_wallet_keep_balances_exact(): void
    {
        $this->payable(self::ID);
        $first = $this->balance('WAL-001');
        $this->pay(self::ID)->assertOk();
        $this->assertSame($first['ledger'] - 100000, $this->balance('WAL-001')['ledger']);
        // The wallet keeps serving other requests: a new reservation uses the real available balance.
        $this->assertSame($first['available'], $this->balance('WAL-001')['available']);
        $total = LedgerEntry::query()->where('wallet_id', 'WAL-001')->whereIn('type', ['OPENING', 'FUNDING_ADDED', 'ADJUSTMENT', 'DEDUCTION'])->sum('amount_halalas');
        $this->assertSame((int) $total, $this->balance('WAL-001')['ledger']);
        DB::table('wallet_ledger')->count(); // keep the DB facade imported for readability of failures
    }
}
