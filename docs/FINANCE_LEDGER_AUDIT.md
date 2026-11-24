# Wallet ledger audit: reservation, commitment and settlement

Status: **full-payment settlement IMPLEMENTED (approved by RAHA).** Partial payments, invoice differences, multiple wallets and refunds remain PENDING BUSINESS DECISIONS (section 8). Sections 1-7 are the original audit; section 9 describes what was built.
Evidence: code (`Modules/Finance/app/Services/WalletBalances.php`, `FinanceService.php`, `WalletService.php`,
`Modules/Authorizations/app/Services/AuthorizationService.php`) and the isolated dev database `raha_dev_integration`.

## 1. How balances are calculated today

| Quantity | Definition | Source |
|---|---|---|
| Total (cash) balance, `ledger` | Σ ledger entries of type `OPENING`, `FUNDING_ADDED`, `ADJUSTMENT`, `DEDUCTION` (signed) | `WalletBalances::BALANCE_TYPES` |
| Reserved | Σ reservations with status `ACTIVE` | `WalletBalances::many` |
| Committed | Σ reservations with status `CONVERTED` | `WalletBalances::many` |
| Available | `ledger − reserved − committed` | `WalletBalances::many` |

Reservations and commitments are **encumbrances**: they lower *available* but never touch the cash `ledger`.
The other ledger types (`RESERVATION`, `RESERVATION_RELEASE`, `RESERVATION_CANCELLED`, `COMMITMENT_CONVERSION`,
`COMMITMENT_REVERSAL`) are memo/audit lines and are not summed into any balance. `DEDUCTION` ("خصم فعلي", actual deduction)
is defined and summed into `ledger`, but **no code in the backend or frontend ever creates one** (verified by search; seed data has none).

## 2. What happens at each step today

| Step | Reservation row | Ledger line (memo) | ledger | reserved | committed | available |
|---|---|---|---|---|---|---|
| Reserve (`finance/cases/{id}/reserve`) | `ACTIVE` | `RESERVATION` | unchanged | + amount | unchanged | − amount |
| Release (`.../release`) | `RELEASED` | `RESERVATION_RELEASE` | unchanged | − amount | unchanged | + amount |
| Issue letter | `CONVERTED` | `COMMITMENT_CONVERSION` | unchanged | − amount | + amount | unchanged |
| Cancel letter before assignment | `REVERSED` | `COMMITMENT_REVERSAL` | unchanged | unchanged | − amount | + amount |
| **Confirmed payment (`finance_complete`)** | stays `CONVERTED` | **none** | unchanged | unchanged | unchanged | unchanged |

So funds are already subtracted from *available* at reservation and stay subtracted through commitment. Payment
confirmation only flips the case to `archived` and `invoice.status` to `paid`. The commitment is never settled.

## 3. Concrete example (real data, dev DB, request REQ-000128, wallet WAL-001, share 100,000 halalas)

Entries for the request (`wallet_ledger`) and its reservation (`funding_reservations`):

```
RESERVATION            WAL-001  100000   (09:28:14)   reservation RSV-75014cdf  ACTIVE
COMMITMENT_CONVERSION  WAL-001  100000   (09:28:19)   reservation RSV-75014cdf  CONVERTED
(payment confirmed, request archived, invoice paid)  -> no ledger line, reservation still CONVERTED
```

Wallet effect for that request (deltas against the wallet before the request; W = wallet cash balance before):

| Moment | ledger | reserved | committed | available |
|---|---|---|---|---|
| Before reservation | W | 0 | 0 | W |
| After reservation | W | 100,000 | 0 | W − 100,000 |
| After commitment (letter issued) | W | 0 | 100,000 | W − 100,000 |
| After confirmed payment, **today** | W | 0 | **100,000 (forever)** | W − 100,000 |
| After confirmed payment, **proposed** | W − 100,000 | 0 | 0 | W − 100,000 |

Live proof: WAL-001 currently reports `ledger 5,000,000, committed 1,520,000`; the cash balance has never moved although
many requests were paid and archived, and the committed figure can only ever grow (except letter cancellations).

## 4. Would adding a DEDUCTION double-count?

**Yes, if added naively.** A `DEDUCTION` of −100,000 lowers `ledger` to W − 100,000; if the reservation stays `CONVERTED`
the committed 100,000 is still subtracted, giving available = (W − 100,000) − 100,000 = W − 200,000, which is wrong.
The deduction must therefore **replace** the commitment in the same transaction.

## 5. Smallest correct accounting change (proposal, not implemented)

When payment is confirmed (`finance_complete`), inside the same DB transaction and request lock:

1. For each `CONVERTED` reservation of the request: set status to a new terminal status `SETTLED` (+ `closed_at`) and
   write one `DEDUCTION` ledger entry with a **negative** amount equal to the reservation amount (same sign convention as
   `ADJUSTMENT`), linked by `reservation_id`.
2. `WalletBalances` needs no change: it only counts `ACTIVE`/`CONVERTED`, so `SETTLED` leaves committed and the
   deduction lowers `ledger`; available is unchanged at the moment of settlement (no double count).
3. Add `SETTLED: "مسدد"` to `reservationStatusLabels` (backend seed `finance.json` and the frontend copy; the drift test already compares them).

Expected behaviour under the proposal:
- Full payment: every commitment settles, ledger drops by the committed total, committed drops by the same amount.
- Cancellation / reversal: unchanged. Letter cancel is only allowed before assignment (reservation `CONVERTED → REVERSED`);
  an archived request is read-only, so a settled commitment can never be reversed.
- Partial payment: **not modelled today** (one invoice amount, `pending → paid`, compared with nothing). Needs a decision:
  (A) settle the full approved commitment regardless of the invoice amount (simplest), or
  (B) settle the invoice share and release the unused remainder with a new memo type. B needs new rules (tolerance, who approves).
- Beneficiary share (`BENEFICIARY` allocation) never touches a wallet and stays out of the ledger.

Regression tests to add with the change: settlement writes exactly one negative `DEDUCTION` per reservation and sets `SETTLED`;
available is identical before and after settlement; ledger decreases by the committed total; wallet never goes negative;
settlement is idempotent (running `finance_complete` twice cannot deduct twice, already blocked by the status guard);
archived requests cannot be reversed; cancelling a letter before assignment is unaffected.

## 6. Historical-data implications

Already-archived requests keep `CONVERTED` reservations forever, so their committed amounts would still be subtracted.
Options: (1) leave them and accept that pre-change commitments never settle; (2) one-off backfill that writes a `DEDUCTION`
and sets `SETTLED` for every archived request with `CONVERTED` reservations, dated at the archive time (needs backup and
finance sign-off); the dev database holds roughly 36 conversions from synthetic runs that would be affected.

## 7. Decisions needed from RAHA finance
1. Confirm that payment confirmation must deduct from the wallet (and when).
2. Partial or differing invoice amounts: option A or B above.
3. Historical archived requests: leave or backfill.

## 8. Policy recommendations (NOT implemented: need RAHA approval)

| Topic | Today (after the approved change) | Recommendation | Why / risk |
|---|---|---|---|
| **Partial payment** | One payment confirmation settles the whole commitment. There is no partial-payment state. | Add an explicit partial settlement: settle only the paid amount (oldest wallet share first or pro-rata), keep the rest CONVERTED, archive only when fully settled or the remainder is released by an authorized user with a reason. | Without it an early partial payment cannot be recorded; with it the workflow needs a new state between finance_review and archived. |
| **Invoice differs from the commitment** | The full commitment is deducted whatever the invoice says; the invoice number is stored in the ledger note. | Compare the invoice (SAR to halalas) with the commitment at payment time. Within a configured tolerance settle the commitment. Above it, block settlement and require finance-manager approval; below it, settle the invoice amount and release the unused commitment (new ledger memo type, for example COMMITMENT_RELEASE). | Silent over- or under-deduction hides real cost differences. A hard rule needs RAHA to set the tolerance and the approver. |
| **Several wallets** | Each wallet share is settled in full, one DEDUCTION per reservation, so shares stay exact. | Keep per-reservation settlement. For partial payments allocate the paid amount in the same proportion as the approved split. Never move money between wallets at settlement. | Pro-rata keeps every donor wallet charged in proportion to what was approved. |
| **Refunds / reversals after payment** | A settled request is archived and read-only; a letter cannot be cancelled after assignment. No refund path exists. | Model a refund as its own authorized transaction: a positive ledger entry of a new type (for example REFUND) linked to the original DEDUCTION, reason mandatory, finance-manager permission, never an edit of the original entry. | Keeps the ledger append-only and auditable. Needs RAHA to define who may refund and for how long after payment. |
| **Settlement without an invoice** | Nothing is deducted; the skip is written to the finance history. | Either require an invoice before payment confirmation, or allow settlement with an explicit finance override reason. | Today the commitment stays open in that case. |
| **Historical archived requests** | Untouched (approved). Their commitments stay CONVERTED. | If finance wants the history cleaned: a one-off, reviewed backfill that settles them with the archive date, run only after a database backup. | The dev database holds many such synthetic requests. |

## 9. What was implemented (approved rule)

Rule: when RAHA confirms actual payment of a provider invoice (workflow action `finance_complete`) the amount moves from COMMITTED to ACTUAL EXPENSE and is deducted from the wallet.

- `Modules/Finance/app/Services/SettlementService.php` runs inside the same transaction as the archive (`WorkflowService::apply`). It row-locks the request's
  `CONVERTED` reservations, sets each to `SETTLED`, writes one negative `DEDUCTION` ledger entry per reservation (same wallet, same amount, linked by
  reservation id), and records the approval history entry, the finance event, the internal case activity and the audit log through the existing
  `FinanceService::record` funnel.
- Balance effect per wallet: `ledger` -X, `committed` -X; `reserved` and `available` unchanged (no double counting). `WalletBalances` is unchanged.
- Duplicate protection, three layers: only `CONVERTED` reservations are selected and they leave that state in the same step; an archived request no longer
  offers `finance_complete`; migration `2026_11_18_000700` adds a unique index on `wallet_ledger (reservation_id, type)`.
- Atomic: a failure while deducting rolls back the archive and the invoice payment (tested).
- Not retroactive: requests already archived keep their `CONVERTED` reservations and have no deduction (tested).
- No invoice on file: nothing is deducted and a finance event records the skip.
- The reservation workflow (reserve, release, commitment, reversal, authorization cancel) is unchanged and still tested.
- `SETTLED` status label added on both sides (`finance.json` and the frontend); the drift test now compares all finance labels.
- Tests: `Modules/Finance/tests/Feature/SettlementTest.php` (11 tests) plus the browser test that closes a request through the UI and checks MySQL,
  wallet balances and the finance screens.


## 10. Payment reconciliation (supersedes the earlier "skip and archive" behavior)

Previous behavior (reproduced, now removed): when the invoice was lower than the commitment, or missing, `finance_complete` marked the invoice paid and ARCHIVED the request
while the commitments stayed CONVERTED (an outstanding commitment on a financially closed request).

Current rule (`SettlementService::assertReconciled`, called by `WorkflowService::perform` BEFORE any transition, inside the same transaction):
for a request that still has CONVERTED commitments, payment confirmation is accepted only if the provider invoice **equals the approved total cost**
(RAHA wallet commitments + the approved beneficiary contribution, i.e. `financial_approvals.total_cost_halalas`). Otherwise it is refused (HTTP 422, message
"لا يمكن تأكيد الدفع وإغلاق الطلب ماليًا: … يلزم مراجعة مالية وقرار معتمد قبل المتابعة.") and nothing changes: no archive, no paid flag, no deduction, no event.

| Scenario | Behavior (tests in `PaymentReconciliationTest` / `SettlementTest`) |
|---|---|
| Invoice equals approved total (incl. approved beneficiary share) | settles atomically, archives |
| Invoice lower than the approved total (incl. equal to RAHA commitments only) | refused, state unchanged |
| Invoice higher than the approved total | refused, state unchanged |
| No invoice with outstanding commitments | refused |
| Several wallets | one negative DEDUCTION per reservation/wallet; available unchanged |
| Repeated confirmation | refused (status guard) + unique index; no second deduction |
| Failure while settling | whole confirmation rolls back (atomic test) |
| Partial payment | not representable (no amount input) |
| Request with no outstanding commitment / legacy archived | unaffected; archived stay untouched |

Not decided (needs RAHA): how a variance is resolved (adjust the approved cost, credit/debit note, partial payment, beneficiary top-up/refund), what Finance may do
after a refusal (today the invoice must be corrected before confirming again), refunds, and whether legacy archived commitments are ever backfilled.
