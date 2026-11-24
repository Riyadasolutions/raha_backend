# RAHA frontend / backend integration report (final)

Scope: `Raha-Front` (React) against `raha_backend` (Laravel 13, Sanctum) on the isolated MySQL database `raha_dev_integration`.
No commit, push, reset, stash, rebase or deployment was performed. **Nothing is classified Production-ready** (see section 8).

## 1. Test results (final runs)
| Suite | Result |
|---|---|
| Backend `php artisan test` (SQLite in-memory) | 303 / 303 passed, 3,047 assertions |
| Frontend `vitest` | 116 / 116 passed (11 files) |
| `tsc --noEmit` | clean |
| Frontend production build | succeeded |
| Contract check (real responses typed against the frontend types) | passed, only documented deviations |
| Browser (Playwright, system Chrome, 21 spec files, 68 tests) | full run 62 passed + 1 failed; the failed test (first of `workflow_variants`) took 3.5 min on a stalled request and passed in 28 s on rerun together with the 5 tests that did not run: all 68 pass. The single-process dev server (`artisan serve`) stalled once during a 1.8 h run. |
| Lint (`npm run lint`) | NOT clean, pre-existing: 17,626 `prettier/prettier` formatting errors (all auto-fixable, 392 files) + 20 warnings (19 dev-only fast-refresh, 1 hook dependency). No real defects. Not reformatted (would touch hundreds of files). |

## 2. Verified, skipped, not verified
Verified through UI + API + MySQL (+ permissions): authentication and sessions, registration ownership proof, the request wizard, every review stage,
finance split / reservation / executive approval / letter / assignment / acknowledgement / visits / completion / payment / archive, **wallet settlement**, quotations
(rounds, drafts, submission, competing providers, comparison, PDF), Fast Track, return/resubmit, rejection, reservation release, letter cancel, round cancel,
uploads and downloads, rental contract, logos / signature / stamp, authorization and quotation PDFs, print layout, Excel and CSV of all report types,
analytics and monitoring numbers, providers, services, users, custom roles, organization settings, audit trail, notifications, storage independence,
authorization and privacy matrix, mobile layout (390 px), English localization, failure handling, role-based navigation (11 roles).

Skipped / not verified: real SMS delivery; production environment, HTTPS, queue worker, backups; browsers other than Chrome; the browser's native print dialog
(the print window is rendered instead); the Lovable-hosted logo images (not available locally); service/provider edit and delete flows beyond creation;
user suspend/unlock screens; the finance wallet funding screen; the "new request on behalf of a beneficiary" staff screen.

## 3. Module status
Scale: Connected / Partially integrated / Fully integrated and tested / Ready for manual UAT / Production-ready.

| Module | Status | Notes |
|---|---|---|
| Auth, Sanctum, sessions | Ready for manual UAT | fully tested; real SMS missing |
| Registration and beneficiary portal (wizard) | Fully integrated and tested | |
| Cases and workflow (19 actions) | Fully integrated and tested | |
| Fast Track | Fully integrated and tested | settings screen not clicked |
| Quotations (staff and provider) | Fully integrated and tested | staff round creation by API |
| Finance and wallets | Fully integrated and tested | settlement implemented; partial payment etc. are open decisions |
| Authorizations / assignments | Fully integrated and tested | |
| Visits | Fully integrated and tested | |
| Reports and exports | Fully integrated and tested | all 8 report types + analytics + lifecycle |
| Analytics and monitoring | Fully integrated and tested | numbers equal API and MySQL |
| Catalog: providers, services | Fully integrated and tested | creation, logo, permissions |
| Users, roles, permissions | Fully integrated and tested | custom role effective permissions enforced |
| Organization settings | Fully integrated and tested | one UI defect fixed |
| Notifications | Fully integrated and tested | |
| Audit trail | Fully integrated and tested | admin-only by seeded permissions |
| Files, uploads, documents, PDFs | Fully integrated and tested | |

## 4. Wallet settlement (approved rule, implemented)
See `FINANCE_LEDGER_AUDIT.md`. At payment confirmation each CONVERTED commitment becomes SETTLED with one negative DEDUCTION, atomically with the archive,
protected by a unique index; not retroactive; reservation workflow unchanged. 11 backend tests + the UI-driven browser test.
Dev database after the runs: 8 SETTLED reservations = 960,000 halalas, matched by 8 DEDUCTION entries = -960,000. The 74 CONVERTED reservations belong to requests
archived before the rule (untouched) or still in flight. Open policy decisions (not implemented): partial payment, invoice differences, multiple wallets, refunds, settlement without invoice, backfill of history.

## 5. Defects found and fixed in this phase (each with a regression test)
Backend: WorkflowService probing of case ids by external accounts (uniform answer); logo/stamp bytes not validated; empty maps serialized as `[]`; `null` in `/auth/me`; settlement missing.
Frontend: organization form lost typed text when an image was uploaded; failed list loads looked like empty lists (now a message); 5xx responses could show raw server text (now generic);
provider catalog requested on every page by roles that may not view it (refused 403 every time); assign-provider modal listed the wrong stage;
missing English entries for connection/server errors; tap targets under 24 px on 8 links (WCAG 2.2 AA).
Test infrastructure issues (not product defects) are noted in the commit-free working tree only.

## 6. Security and privacy
Verified safe: anonymous 401 on all 196 routes and methods; account-type 403 on every restricted route; cross-beneficiary and cross-provider isolation; upload ownership;
authorization document access; quotation privacy; report/export and admin writes limited to permitted roles; provider directory limited to own record; no secrets or SQL in error pages.
Open: visit audit exposure to providers; provider `notes` free text; diagnosis sharing policy; staff-only validation-before-permission ordering (harmless, documented).

## 7. Open business decisions
1. Wallet settlement policies: partial payment, invoice differences, multiple wallets, refunds, no-invoice case, history backfill.
2. Provider access to `diagnosis`.
3. Medical review dialog still asks for a provider and a price (legacy advisory input): keep / optional / remove.

## 8. Production blockers and release checklist
- No SMS gateway (OTPs are not delivered).
- Official logo / emblem / Vision 2030 images are not in the repository (Lovable-hosted pointers; 404 locally). RAHA must supply the six original files.
- No pagination: with 149 requests the case list is already ~0.7 MB (1.2 s), audit log 0.7 MB, executive monitoring 0.8 MB / 1.5 s, performance report 0.5 MB / 2.6 s. At ten times the volume these become seconds-long multi-megabyte responses.
- No HTTPS/security headers in the application: set at the web server (HSTS, nosniff, frame options, CSP); trusted proxies; `X-Powered-By: PHP/x` is exposed (set `expose_php=Off`).
- `.env.example` defaults are development values (`APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`, `RAHA_DEV_ACCOUNTS=true`). Production must override; dev mode is hard-limited to local/testing, but `APP_DEBUG=true` would still leak traces.
- `DatabaseSeeder` seeds demo accounts with a shared dev password: never seed production with it (use a dedicated production seeder).
- Queue, cache and session use the `database` driver; no code queues jobs today, but SMS/e-mail sending will need a worker; private files live on the local disk (back up `storage/app/private`).
- Sanctum tokens have no native expiry; the session policy middleware enforces idle and maximum lifetime (verified).
- Visit audit entries are returned to providers (needs privacy review).
- Lint baseline (formatting) and a single-process dev server are not production concerns but should be addressed in CI.

## 9. Git
`Raha` is not a repository. `Raha-Front`: 21 modified, 6 new source/test files and the new `e2e/` folder (`.env` untracked in the index only).
`raha_backend`: 34 changed or new paths. Nothing committed. Synthetic test records were not deleted.

## 10. Stabilization phase (medical review, settlement safety, privacy, UAT preparation)
(Final numbers for this phase are in the delivery report; this section records the changes and findings.)

- **Medical review**: Provider and Price are now optional advisory fields in `case_review_modal.tsx` (no mandatory check; empty values are not stored; a typed price must be > 0; saved values and history untouched; the backend validator never required them). Tests: `MedicalReviewOptionalFieldsTest` (3), browser `uat_gaps.spec.ts`.
- **Settlement safety** (`FINANCE_LEDGER_AUDIT.md` section 10): a provider invoice below the commitment no longer deducts the whole commitment (nothing deducted, finance event recorded); there is no partial-payment input; multi-wallet payments settle per reservation; settled commitments cannot be reversed; repeats refused; legacy archived requests untouched. 3 new tests in `SettlementTest` (14 total).
- **Provider privacy fixes**: (1) visit `audit`/`corrections` exposed the name+role of the RAHA staff who corrected a visit and the internal reason: now "جمعية راحة" and no reason for providers (`VisitProviderPrivacyTest`); (2) the staff free-text `notes` of a provider record was returned to that provider: now withheld (`ProviderCatalogTest`). Medical projection unchanged (only `condition` during execution; `diagnosis`/`hospital`/`doctor`/`reportDate` withheld; documents only when explicitly shared).
- **Session handling defect fixed**: the beneficiary portal shell never reacted to a session ended by the server (revoked token, termination, idle): the page stayed open. It now re-reads `/auth/me` once per load and returns to the sign-in on a 401 (like the staff shell). Browser test: logout + reload with a revoked token.
- **Production safety**: `DatabaseSeeder` refuses to run when dev mode is off (`DemoSeedGuardTest`); verified that `APP_ENV=production` disables dev accounts and OTP/reset debug values even when `RAHA_DEV_ACCOUNTS=true`.
- **Browser-test infrastructure**: `e2e/serve_api.mjs` supervises the API (log + health restarts); `e2e/README.md`; shared `e2e/tests/wizard.ts`; test synchronization fixes (wizard waits for the report-date field instead of a fixed 800 ms; service forced to "تمريض منزلي" instead of "first option"; providers list searched by name because the list is paginated). None of these were product defects.
- **Not changed (needs a decision)**: no client-side request timeout exists (a request that never answers keeps the spinner until the browser gives up); lists remain unpaginated.
- UAT checklist: `docs/MANUAL_UAT_CHECKLIST.md` (135 cases, results blank).

### Final results of the stabilization phase
- Backend `php artisan test`: **314/314** (3,149 assertions). Frontend vitest: **116/116** (11 files). `tsc --noEmit`: clean. Production build: OK. Contract check: passed (documented deviations only).
- Playwright, **one complete execution of all 75 tests in 22 files: 75 passed, 0 failed, 0 skipped (1.4 h)**, log `Raha-Front/e2e/logs/full_run.txt`.
  Earlier complete-execution attempts that did NOT finish clean (kept as logs, not hidden): attempt 1 aborted by me after two test-design defects (providers list paginated; wizard fixed 800 ms wait), attempt 2/3 interrupted because the host laptop was suspended on battery (tests with 2.2 h / 27.6 min / 4.2 h "durations", timeouts only), attempt 4 failed 20 tests after the Vite dev server crashed (FSWatcher `scandir .output/server`, caused by the build folder produced by `npm run build`; log `front-dev_crash_watcher_output_dir.log`), attempt 5 is the clean run above.


## 11. UAT safety corrections
- **Finance**: unreconciled payment confirmation is refused before any transition (see `FINANCE_LEDGER_AUDIT.md` section 10); 10 new tests in `PaymentReconciliationTest`.
- **Provider document privacy**: `uploadedBy` masked for providers in the API (`ProviderDocumentUploaderPrivacyTest`).
- **API timeout** (`src/lib/api_client.ts`): configurable timeouts (`VITE_API_TIMEOUT_MS` default 30 s; `VITE_API_LONG_TIMEOUT_MS` default 120 s for uploads/downloads; per-request `timeoutMs`, 0 disables); localized messages; caller aborts reported as `ABORTED` without a toast; a timed-out WRITE is flagged `outcomeUnknown`, is never retried, and shows a warning with a read-only "تحقق من الحالة" action that re-reads loaded data. 10 unit tests (`api_timeout.test.ts`) + 1 browser test.
