<?php

namespace Modules\Finance\Tests\Feature;

use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FinanceEvent;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;

/**
 * Payment confirmation (finance_complete) is only allowed when the provider invoice reconciles under the APPROVED rule:
 * invoice == approved total cost (RAHA wallet commitments + the approved beneficiary contribution). Anything else is refused with a
 * business error BEFORE any transition: no archive, no partial/incorrect deduction, no state change. How to resolve a variance is an
 * open business decision (see docs/FINANCE_LEDGER_AUDIT.md section 10).
 */
class PaymentReconciliationTest extends FinanceTestCase
{
    /** Approved cost SAR 3,000 = WAL-001 1,000 + WAL-004 1,400 (two wallets) + beneficiary contribution 600. */
    private function payable(?float $invoiceAmount = 3000.0, ?array $split = null, string $id = self::ID): void
    {
        $this->toIssued(self::LETTER, $id, $split);
        $asg = $this->fin('assign', [], 'manager', $id)->assertCreated()->json('data');
        $this->asRole('provider')->postJson("/api/v1/provider/assignments/{$asg['id']}/acknowledge")->assertOk();
        $this->setInvoice($invoiceAmount, $id);
        RahaCase::query()->whereKey($id)->update(['status' => 'finance_review', 'service_confirmed' => true]);
    }

    private function setInvoice(?float $amount, string $id = self::ID): void
    {
        RahaCase::query()->whereKey($id)->update(['invoice' => $amount === null ? null : json_encode(['number' => 'INV-R', 'amount' => $amount, 'at' => now_iso(), 'status' => 'pending'])]);
    }

    private function pay(string $id = self::ID)
    {
        return $this->asRole('finance')->postJson("/api/v1/cases/$id/actions/finance_complete");
    }

    /** Everything a refused confirmation must leave untouched. */
    private function state(string $id = self::ID): array
    {
        $c = RahaCase::query()->findOrFail($id);

        return [
            'status' => $c->status, 'invoice' => $c->invoice, 'finance' => $c->finance,
            'reservations' => FundingReservation::query()->where('case_id', $id)->orderBy('id')->get()->toArray(),
            'ledger' => LedgerEntry::query()->orderBy('id')->get()->toArray(),
            'events' => FinanceEvent::query()->where('case_id', $id)->count(),
            'activities' => CaseActivity::query()->where('case_id', $id)->count(),
            'balances' => ['WAL-001' => $this->balance('WAL-001'), 'WAL-004' => $this->balance('WAL-004')],
        ];
    }

    private function assertRefused(float $invoice, string $messagePart): void
    {
        $this->payable($invoice);
        $before = $this->state();
        $res = $this->pay()->assertStatus(422);
        $this->assertStringContainsString($messagePart, $res->json('message'));
        $this->assertStringContainsString('مراجعة مالية', $res->json('message'));
        $this->assertSame($before, $this->state(), 'a refused payment confirmation changes nothing');
        $this->assertSame('finance_review', RahaCase::query()->find(self::ID)->status, 'the request is NOT archived');
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count(), 'no deduction of any amount');
        $this->assertSame(['CONVERTED', 'CONVERTED'], FundingReservation::query()->where('case_id', self::ID)->orderBy('wallet_id')->pluck('status')->all());
    }

    public function test_invoice_equal_to_the_approved_cost_settles_and_archives(): void
    {
        $this->payable(3000.0);
        $this->pay()->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.invoice.status', 'paid');
        $this->assertSame(['SETTLED', 'SETTLED'], FundingReservation::query()->where('case_id', self::ID)->orderBy('wallet_id')->pluck('status')->all());
        $this->assertSame(-240000, (int) LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->sum('amount_halalas'));
    }

    public function test_invoice_lower_than_the_commitment_is_refused_and_nothing_changes(): void
    {
        $this->assertRefused(500.0, 'لا يطابق التكلفة المعتمدة');
    }

    public function test_invoice_equal_to_the_commitment_but_below_the_approved_cost_is_refused(): void
    {
        // 2,400 = the RAHA commitments only; the approved total (3,000) includes the beneficiary contribution, so the variance is unreconciled.
        $this->assertRefused(2400.0, 'لا يطابق التكلفة المعتمدة');
    }

    public function test_invoice_higher_than_the_approved_cost_is_refused(): void
    {
        $this->assertRefused(3500.0, 'لا يطابق التكلفة المعتمدة');
    }

    public function test_missing_invoice_with_outstanding_commitments_is_refused(): void
    {
        $this->payable(null);
        $before = $this->state();
        $res = $this->pay()->assertStatus(422);
        $this->assertStringContainsString('لا توجد فاتورة', $res->json('message'));
        $this->assertSame($before, $this->state());
        $this->assertSame('finance_review', RahaCase::query()->find(self::ID)->status);
    }

    public function test_approved_beneficiary_contribution_is_part_of_the_reconciliation(): void
    {
        // Without a beneficiary allocation the approved cost equals the commitments, so an invoice of the commitments only is the match.
        $this->payable(3000.0, [
            ['id' => 'a1', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-001', 'amountHalalas' => 100000],
            ['id' => 'a2', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-004', 'amountHalalas' => 200000],
        ]);
        $this->pay()->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame(-300000, (int) LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->sum('amount_halalas'));
    }

    public function test_multi_wallet_settlement_deducts_each_wallet_once(): void
    {
        $this->payable(3000.0);
        $before = ['WAL-001' => $this->balance('WAL-001'), 'WAL-004' => $this->balance('WAL-004')];
        $this->pay()->assertOk();
        foreach (['WAL-001' => 100000, 'WAL-004' => 140000] as $w => $amount) {
            $this->assertSame([-$amount], LedgerEntry::query()->where('wallet_id', $w)->where('type', 'DEDUCTION')->where('case_id', self::ID)->pluck('amount_halalas')->map(fn ($x) => (int) $x)->all());
            $after = $this->balance($w);
            $this->assertSame($before[$w]['ledger'] - $amount, $after['ledger']);
            $this->assertSame($before[$w]['committed'] - $amount, $after['committed']);
            $this->assertSame($before[$w]['available'], $after['available'], 'available is unchanged: the money was already encumbered');
        }
    }

    public function test_duplicate_confirmation_is_refused_and_does_not_deduct_twice(): void
    {
        $this->payable(3000.0);
        $this->pay()->assertOk();
        $after = $this->state();
        $this->pay()->assertStatus(422);
        $this->assertSame($after, $this->state());
        $this->assertSame(2, LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->count());
    }

    public function test_a_refused_confirmation_can_be_repeated_after_the_invoice_is_corrected(): void
    {
        $this->payable(500.0);
        $this->pay()->assertStatus(422);
        $this->setInvoice(3000.0);
        $this->pay()->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame(2, LedgerEntry::query()->where('type', 'DEDUCTION')->where('case_id', self::ID)->count());
    }

    public function test_requests_without_outstanding_commitments_and_legacy_archived_cases_are_not_affected(): void
    {
        $id = 'REQ-000112';
        RahaCase::query()->whereKey($id)->update(['status' => 'finance_review', 'service_confirmed' => true, 'invoice' => json_encode(['number' => 'INV-X', 'amount' => 100, 'at' => now_iso(), 'status' => 'pending'])]);
        $this->pay($id)->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame(0, LedgerEntry::query()->where('type', 'DEDUCTION')->count());
        $legacy = RahaCase::query()->where('status', 'archived')->where('id', '!=', $id)->first();
        $this->assertNotNull($legacy);
        $this->pay($legacy->id)->assertStatus(422);
    }
}
