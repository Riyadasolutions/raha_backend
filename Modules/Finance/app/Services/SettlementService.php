<?php

namespace Modules\Finance\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FundingReservation;

/**
 * Approved rule: when RAHA confirms actual payment of the provider invoice (workflow action finance_complete), each commitment
 * of the request moves from COMMITTED to ACTUAL EXPENSE:
 *   reservation CONVERTED -> SETTLED  +  one NEGATIVE ledger entry of type DEDUCTION (same amount, same wallet, linked to the reservation).
 * Balance effect per wallet: ledger -X, committed -X, available unchanged (the money was already encumbered), so nothing is counted twice.
 *
 * Must run inside the caller's transaction (WorkflowService::perform) so the deduction and the archive commit or roll back together.
 * Duplicate protection: only CONVERTED reservations are selected (row-locked), they leave that state in the same step, and the database
 * has a unique index on (reservation_id, type) in wallet_ledger.
 *
 * Out of scope until RAHA approves a policy: partial payments, invoice variances, refunds. Such payments are REFUSED by assertReconciled()
 * (before any transition) instead of being closed with an open commitment.
 */
class SettlementService
{
    public function __construct(private readonly FinanceService $finance) {}

    /**
     * Payment confirmation is only allowed when the provider invoice can be reconciled under the APPROVED rule: the invoice equals the
     * approved total cost of the request (RAHA wallet commitments + the approved beneficiary contribution), and every commitment is
     * still CONVERTED. Anything else (no invoice, lower, higher, inconsistent commitments) needs a finance review / a business decision,
     * so the confirmation is refused BEFORE any state change. Requests with no outstanding commitment (no finance flow, legacy) are not affected.
     */
    public function assertReconciled(RahaCase $c): void
    {
        $held = FundingReservation::query()->where('case_id', $c->id)->where('status', 'CONVERTED')->get();
        if ($held->isEmpty()) {
            return;
        }
        $a = FinanceService::approvalFor($c->id);
        $committed = (int) $held->sum('amount_halalas');
        $fail = fn (string $why) => throw new DomainRuleException("لا يمكن تأكيد الدفع وإغلاق الطلب ماليًا: {$why} يلزم مراجعة مالية وقرار معتمد قبل المتابعة.");
        $invoice = $c->invoice;
        if (empty($invoice) || ! is_numeric($invoice['amount'] ?? null)) {
            $fail('لا توجد فاتورة مقدم خدمة يمكن مطابقتها مع الالتزامات القائمة.');
        }
        $invoiceH = (int) round(((float) $invoice['amount']) * 100);
        $approved = $a ? (int) $a->total_cost_halalas : 0;
        $sar = fn (int $h) => number_format($h / 100, 2, '.', ',');
        if ($approved <= 0 || $committed > $approved) {
            $fail('التكلفة المعتمدة لا تتسق مع الالتزامات القائمة.');
        }
        if ($invoiceH !== $approved) {
            $fail("مبلغ الفاتورة ({$sar($invoiceH)} ر.س) لا يطابق التكلفة المعتمدة ({$sar($approved)} ر.س) والالتزامات القائمة ({$sar($committed)} ر.س).");
        }
    }

    /** @return array{count: int, amount: int} */
    public function settle(RahaCase $c, User $u): array
    {
        $held = FundingReservation::query()->where('case_id', $c->id)->where('status', 'CONVERTED')->orderBy('id')->lockForUpdate()->get();
        if ($held->isEmpty()) {
            return ['count' => 0, 'amount' => 0];
        }
        $this->assertReconciled($c); // defensive: the workflow validates first, nothing is deducted for an unreconciled invoice
        $total = (int) $held->sum('amount_halalas');
        $a = FinanceService::approvalFor($c->id, true);
        if (! $a) {
            return ['count' => 0, 'amount' => 0];
        }
        $invoice = $c->invoice;
        $at = now();
        $ref = (string) ($invoice['number'] ?? '');
        foreach ($held as $r) {
            $r->forceFill(['status' => 'SETTLED', 'closed_at' => $at, 'closed_reason' => 'تسوية بعد تأكيد الدفع'])->save();
            $this->finance->ledger($r->wallet_id, $c->id, $r->id, -$r->amount_halalas, 'DEDUCTION', 'خصم فعلي بعد تأكيد دفع الفاتورة'.($ref !== '' ? " {$ref}" : ''), $u, $at);
        }
        $this->finance->record($c, $a, $a->stage ?: 'provider_assigned', $u, 'تسوية الالتزامات بعد تأكيد الدفع', ['amount' => $total, 'reason' => $ref !== '' ? "فاتورة {$ref}" : null]);

        return ['count' => $held->count(), 'amount' => $total];
    }
}
