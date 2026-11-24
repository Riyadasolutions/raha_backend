# RAHA release checklist and open business decisions

Status: working document for the integration verification. Nothing here is approved for production yet.

## Open business decisions (need final RAHA approval)

### Provider access to medical data
Implemented default (`CaseProjector::PROVIDER_MEDICAL_FIELDS`, `PROVIDER_EXECUTION_STATUSES`):
- `condition` is visible to the provider assigned to the case while it is `assigned`, `scheduled` or `in_progress`.
- `hospital`, `doctor`, `reportDate`, `diagnosis` and any other medical key are never sent to providers by default.
- Invited providers (quotation stage) and unrelated providers receive no medical map and cannot open the case.
- Medical report / prescription documents follow the existing provider-sharing rule (`providerVisible`, non-internal) and are only
  visible/downloadable in the same execution window.

Pending decision: **diagnosis sharing.** It must not be derived from the service type. It needs an explicit, narrowly scoped
authorization (who approves, per case, recorded and auditable) that becomes available only after the provider accepted the
assignment. Until RAHA approves that mechanism, diagnosis stays withheld from the generic provider payload.

### Visit audit trail — FIXED (this phase)
Providers used to receive the full `audit` and `corrections` of their visits, including the NAME and ROLE of the RAHA staff member who corrected a visit and the
internal correction reason. `VisitRecord::toFrontFor()` now shows providers their own history, but staff stamps become "جمعية راحة" and the staff reason text is
dropped (the correction itself and the previous values stay visible). Staff are unchanged. Tests: `VisitProviderPrivacyTest`.

### Wallet deduction at payment confirmation — IMPLEMENTED (approved rule)
`SettlementService` (called from `finance_complete`): commitments become SETTLED with one negative DEDUCTION per reservation, atomically, no duplicates, not retroactive.
Payment confirmation now requires the invoice to equal the approved total cost; any other amount (or no invoice) is refused BEFORE archiving (no silent open commitment on a closed request). Open policies (partial payment, invoice differences,
multiple-wallet payments, refunds, no-invoice, backfilling archived requests): see `FINANCE_LEDGER_AUDIT.md` sections 8 and 10.

### Medical review dialog: Provider and Price — DONE (client-approved: optional)
The fields stay as optional advisory input ("مزود الخدمة المقترح (اختياري)", "السعر الاسترشادي (ريال) (اختياري)"). Empty values are not stored; if a price is typed it must be
greater than zero. Existing saved values and history are untouched. Finance still decides the final provider and amounts. Tests: `MedicalReviewOptionalFieldsTest`, browser `uat_gaps.spec.ts`.

### Logo and stamp assets (Lovable)
`src/assets/*.asset.json` are pointers to images hosted by Lovable (`/__l5e/assets-v1/...`); the repository contains no binaries.
Locally the dev proxy only forwards them when `LOVABLE_PREVIEW_HOST` is set, so a 404 is the expected result. No legitimate local
copy of the official logo exists (only the 64-512 px favicon/PWA icons, which were deliberately NOT substituted). For a self-hosted
build RAHA must supply the six original files (raha-logo, raha-emblem x3, vision2030 x2) and import them from `src/assets/`.

### Other privacy notes
- Provider `notes` (staff free text on the provider record) is now withheld from provider accounts (FIXED, `ProviderCatalogTest`). Provider-visible document `uploadedBy` is now masked ('جمعية راحة' for staff, 'المستفيد' for the beneficiary side; FIXED, `ProviderDocumentUploaderPrivacyTest`).
- Foreign-id probing: external accounts now get one uniform answer on workflow actions; staff keep the finer messages (by design).

## Production blockers / release checklist
Verified facts are marked (v); everything else is a task for the production setup. Nothing below was deployed or configured.

### Authentication and notifications
- [ ] SMS/OTP gateway: OTPs are not delivered anywhere (`DevMode::sms` only logs a warning). Choose a provider, secure the sender id, add delivery failure handling and rate limits per mobile.
- (v) Dev OTP/reset `debug` values and `auth/dev/*` routes are impossible unless APP_ENV is `local`/`testing` (`DevMode`; checked with `APP_ENV=production RAHA_DEV_ACCOUNTS=true` -> `raha.dev_accounts=false`).
- (v) Seeded demo accounts (shared dev password): `DatabaseSeeder` now refuses to run when dev mode is off (`DemoSeedGuardTest`). Production needs its own seeder with real accounts and strong, unique passwords.
- (v) Sanctum tokens have no native expiry (`sanctum.expiration = null`); lifetime/idle/termination are enforced by `EnforceSessionPolicy`. Confirm the production policy values in Settings.
- [ ] E-mail notifications (future): need a mail transport (SMTP/API), queued jobs + worker, bounce handling and templates; today notifications are in-app only.

### Branding
- [ ] Six official images are missing from the repository (Lovable `*.asset.json` pointers only): `raha-logo`, `raha-emblem` (3 variants), `vision2030` (2 variants). RAHA must supply the originals; no unofficial replacement was created.

### Backend infrastructure
- [ ] HTTPS only, HSTS, `X-Content-Type-Options`, frame protection, CSP at the web server; trusted proxies configured (`TrustProxies`); hide `X-Powered-By` (`expose_php=Off`).
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL` appropriate (the `.env.example` defaults are development values: `APP_ENV=local`, `APP_DEBUG=true`, `RAHA_DEV_ACCOUNTS=true`).
- [ ] `RAHA_FRONTEND_ORIGINS` = the real origin(s).
- [ ] Queue/cache/session use the `database` driver today; no job is queued by the application yet. Pick production drivers (Redis/db) and run a worker once SMS/e-mail exist.
- [ ] Private document storage: files live on the local private disk (not publicly reachable, v); production needs a persistent volume or object storage with encryption and access logging.
- [ ] Backup and restore: scheduled MySQL dumps + `storage/app/private`, offsite copy, a documented and tested restore, retention policy.
- [ ] Database access: dedicated least-privilege MySQL user, no root, credentials in a secret manager, TLS to the database, `.env` never in Git (v: `.env` is ignored in both repos).

### Performance
- [ ] No server-side pagination/filtering: with 149 requests the case list is ~0.7 MB / 1.2 s, audit log ~0.7 MB, executive monitoring ~0.8 MB / 1.5 s, performance report ~0.5 MB / 2.6 s. Add `page`/`perPage`/filters to cases, audit logs, notifications, beneficiaries, monitoring and the reports before volumes grow.

### Code quality
- [ ] Lint baseline: 17,626 `prettier/prettier` formatting errors (392 files) + 20 warnings pre-date this work; mass formatting was deliberately not applied. Touched files add no functional lint warnings (only the same formatting baseline).
- [ ] Package manager: `bun.lock` exists but `npm` is used locally: choose one and keep a single lockfile in CI.
- [ ] `artisan serve` is single-process; use php-fpm/Octane in any shared environment (the e2e supervisor restarts it automatically during long runs).
