# RAHA — Manual UAT handover guide

Status: **ready for Manual UAT. NOT production-ready.** No manual test has been executed or marked as passed.
The 145 test cases live in [MANUAL_UAT_CHECKLIST.md](MANUAL_UAT_CHECKLIST.md) (this guide does not duplicate them).
Related: [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) (production blockers), [FINANCE_LEDGER_AUDIT.md](FINANCE_LEDGER_AUDIT.md) (finance rules), [INTEGRATION_REPORT.md](INTEGRATION_REPORT.md) (evidence).

## 1. Environment

| Item | Value |
|---|---|
| Database | MySQL database **`raha_dev_integration`** only (set in `raha_backend/.env`; never print or commit that file). Do not point UAT at any other database. |
| Backend | Laravel 13, `http://127.0.0.1:8000`, API prefix `/api/v1` |
| Frontend | React / TanStack Start (Vite), `http://localhost:8080` |
| Browser | Current Chrome. Also test one phone-width window (390 px) where a case is marked **[M]**, and English where marked **[EN]**. |
| Prerequisites | XAMPP MySQL running; PHP and Composer; Node.js + npm |

### Start the servers (two terminals)
1. Backend, from `raha_backend`: `php artisan serve` (single process; it can stall in long sessions).
   Sturdier option: from `Raha-Front/e2e` run `node serve_api.mjs` (it restarts the API after repeated failed health probes and logs to `e2e/logs/`).
2. Frontend, from `Raha-Front`: `npm install` (first time only), then `npm run dev`.

Do **not** run `npm run build` while the dev server is running (it can crash Vite's watcher; delete `.output` if you did).

### Verify both are reachable
- API: open `http://127.0.0.1:8000/api/v1/health` → HTTP 200.
- Frontend: open `http://localhost:8080/` → public home page loads in Arabic.
- Sign in with any account below; if the page shows a "cannot reach the server" message, re-check the API terminal and that `VITE_API_URL` (Raha-Front `.env`) is `http://127.0.0.1:8000/api/v1`.
- CORS: the backend only accepts the origins in `RAHA_FRONTEND_ORIGINS`; open the frontend at exactly `http://localhost:8080` (or add your origin there).

### Demo data rules
- All records are synthetic. **Do not delete, reset or re-seed** anything during UAT. The seeder refuses to run unless dev mode is on.
- The shared demo password for seeded accounts is the `demoPassword` value in `raha_backend/database/seed-data/users.json` (dev only). It is intentionally **not** repeated in this document. If it does not work, ask the environment owner; do not reset passwords without approval.
- Those accounts and the dev quick-login endpoint (`dev/quick-login`) must not exist in production.

## 2. Test accounts by role

Staff sign in at `/login?type=staff`, providers at `/login?type=provider`, beneficiaries at `/beneficiary/login` (or `/login?type=beneficiary`; national ID + password). Passwords: see the rule above.

| Role | Account identifier | Login route | Main modules available | Suggested scenarios (checklist IDs) |
|---|---|---|---|---|
| Beneficiary (المستفيد) | National ID of the demo beneficiary — see `demoBeneficiaryProfile.nationalId` in `database/seed-data/users.json` (or register a new synthetic one) | `/beneficiary/login` | Portal: requests, new request, profile, appointments, notifications, help | BEN-01…BEN-23: register, login/lock, new request (self and family member), documents, track, evaluation |
| Auditor (مدقق الطلبات) | `auditor` | `/login?type=staff` | Cases (first review), beneficiaries | AUD-01…03, STF first-review rows |
| Medical reviewer (أخصائي طبي / اللجنة الطبية) | `medical` | `/login?type=staff` | Cases, medical review | STF medical rows (note: provider/price are optional in the review form) |
| Social researcher (باحث اجتماعي) | `social` | `/login?type=staff` | Cases, social review | STF social rows |
| Healthcare manager (مدير الرعاية الصحية) | `manager` | `/login?type=staff` | Cases, approvals, execution/assignment, providers, services | STF approval rows, FT-01…04, PRV, QUO, AUT |
| Finance (المحاسب) | `finance` | `/login?type=staff` | Finance: wallets, requests, reservations, commitments, log, reports, authorizations | FIN-01…FIN-19 |
| Executive director (المدير التنفيذي) | `executive` | `/login?type=staff` | Executive approvals, executive monitoring, reports | EXE-01…04, REP |
| Patient relations (علاقات المرضى) | `relations` | `/login?type=staff` | Cases (service confirmation), beneficiaries, notes | CLS-01…04, VIS |
| Provider (مقدم خدمة) | `provider` (PRV-0005), `provider2` (PRV-0001), `provider3` (PRV-0006) | `/login?type=provider` | Provider: quotation invitations, assignments, submitted quotes | PRV-01…07, QUO, VIS; use two providers to check they cannot see each other's data |
| System administrator (مدير النظام) | `admin` | `/login?type=staff` | Users & roles, organization settings, security, audit, catalogs, all reports | USR, ORG, CAT, SEC, AUD, NOT |
| Team lead (فريق 01), extra | `team01` | `/login?type=staff` | Internal medical team, visits | VIS rows |

Accounts are the ones in the seed (`staffUsers` in `users.json`); none were created or changed for this handover.

## 3. Execution order (one full request lifecycle)

Follow this order so each step produces the data the next one needs. Use one new synthetic request throughout.

1. **Environment check** (section 1), then public home **BEN-01**.
2. **Beneficiary**: BEN-02…BEN-08 (register/login/profile), then create the request BEN-09…BEN-19 (for yourself first; a second request for a family member), including documents.
3. **Auditor → Medical → Social**: STF-01…STF-18 in their numbered order (first review, medical review, social review; return-for-correction and rejection paths last).
4. **Fast Track** (separate request): FT-01…04.
5. **Provider invitation and assessment**: PRV-01…PRV-07 (manager invites, providers respond).
6. **Quotations**: QUO-01…QUO-07 (providers submit, manager compares and selects).
7. **Finance allocation and reservation**: FIN-01…FIN-09 (wallets, split, reserve, release on a scratch request).
8. **Executive approval**: EXE-01…EXE-04.
9. **Authorizations and assignment**: AUT-01…AUT-09.
10. **Visits and service delivery**: VIS-01…VIS-07 (including provider privacy checks).
11. **Closure**: BEN-20 (beneficiary confirmation), CLS-01…CLS-04 (patient relations), then **payment reconciliation, section 4 below** (FIN-10…FIN-18) which closes and archives.
12. **Reports and exports**: REP-01…REP-06.
13. **Administration** (any time, independent): CAT, USR, ORG, SEC, NOT, AUD.
14. **Cross-cutting**: SEC-01…SEC-12 (account security), failure handling (timeout cases in the checklist), **[M]** phone width and **[EN]** English on the key flows.

## 4. Financial reconciliation section (checklist rows FIN-10 … FIN-18)

Rule under test: payment confirmation is allowed only when the provider invoice equals the **approved total cost** (RAHA wallet commitments + the approved beneficiary contribution). Anything else must be refused with a finance-review message, with **no archive, no deduction, no state change**.

| Scenario | Checklist ID |
|---|---|
| Matching invoice and commitment → settles once, archives | FIN-10 |
| Lower invoice → refused, not archived, balances unchanged | FIN-11 |
| Higher invoice → refused | FIN-12 |
| Invoice equals RAHA commitments but not the approved total (beneficiary contribution) → refused | FIN-13 |
| No invoice → refused | FIN-14 |
| Multiple wallets → one deduction per wallet, available unchanged | FIN-15 |
| Retry after the invoice is corrected → settles once | FIN-16 |
| Duplicate confirmation on an archived request → refused, no second deduction | FIN-17 |
| Historical archived request → unchanged | FIN-18 |

"No archive after failed reconciliation" is an expected-result item inside FIN-11…FIN-14: after each refusal, check the request page, the wallet balances and the finance log are exactly as before the attempt.

## 5. Recording evidence

Use the columns already in `MANUAL_UAT_CHECKLIST.md`. Work on a **copy** of the file (or a spreadsheet import) per tester so results do not collide; the tracked file stays Pending.

For each case record:
- **Test ID** (e.g. FIN-11) and **Tester** name, **Date** (put tester and date at the start of *Notes*, e.g. `A. Tester 2026-11-23`).
- **Actual result**: what really happened, in one or two sentences.
- **Pass/Fail**: Pending → Pass only when every part of the expected result is true; otherwise Fail.
- **Evidence**: screenshot file name or link (name files `<TestID>_<n>.png`; show the URL, the message and, for finance, the balances).
- For every Fail, also write in *Notes*: **Severity** (Blocker / Major / Minor / Cosmetic) and a **Suggested fix**.

Failed test vs. deferred feature:
- **Fail** = the system does not do what the checklist expects. It is a defect to fix before sign-off.
- **Deferred** = behavior intentionally not built because the business has not decided it (section 6). Mark the case *Pending* with the note `DEFERRED: <item>`; do not mark it Fail and do not log it as a defect. Example: there is no partial payment or refund screen, so a request to pay a different invoice amount is refused by design (FIN-11/12).

## 6. Known limitations and open business decisions

None of these is implemented; no policy has been invented.

1. **Provider invoice total vs. the beneficiary's contribution.** Currently the invoice must equal the approved total (RAHA commitments + approved beneficiary contribution). Needs confirmation that this is the intended rule.
2. **Partial payments and invoice differences.** Lower or higher invoices are refused (finance review). How a variance is resolved (cost adjustment, credit/debit note, partial payment, beneficiary top-up) and what Finance may do afterwards is undecided.
3. **Refunds and reversals.** Not implemented.
4. **Historical archived commitments.** Requests archived before settlement existed keep their open commitments; no historical settlement was performed.
5. **Diagnosis-sharing policy.** Providers receive only the condition during execution, never diagnosis, hospital, doctor or report date, and no staff names on documents/visits. Whether providers should see the diagnosis is undecided.
6. **Official brand assets.** The six official images are not yet supplied; placeholders are in use.
7. **SMS integration.** No real SMS gateway; OTP codes are shown only in dev mode.
8. Also open (see RELEASE_CHECKLIST.md): list pagination, API timeout defaults (30 s normal / 120 s uploads, configurable), production infrastructure (HTTPS, headers, env/secrets, backups, queues, file storage), removal of dev accounts.

## 7. Beginning Manual UAT — exact steps

1. Start MySQL, then the two servers (section 1) and confirm both URLs respond.
2. Copy `docs/MANUAL_UAT_CHECKLIST.md` to your own results file.
3. Open `http://localhost:8080`, start at BEN-01 and follow the order in section 3.
4. Record results as in section 5. Report Blockers immediately; everything else at the end of each section.
5. Do not delete or reset data, change passwords of seeded accounts, or run any command against another database.

Automated baseline (for reference, not a substitute): backend 324/324, frontend 126/126, TypeScript and build passing, API contract check passing with documented deviations, last complete Playwright run 75/75, targeted browser regressions re-run after the latest changes.
