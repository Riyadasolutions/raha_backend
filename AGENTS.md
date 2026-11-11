# RAHA backend (Laravel 13 + Sanctum + nwidart/laravel-modules)

This API replaces the frontend mock services in `../front/src/services/*` (localStorage). Every business rule that
ran in the browser is ported here and enforced server-side. **Read the front service you are porting first** and keep
its behaviour and its Arabic messages (copy them verbatim), unless this file says the mock had a gap to fix.

## Layout
- `app/` = Core (shared by all modules, not a module):
  - `Models/` User (staff/provider/beneficiary accounts, string ids `u1`, `u-BEN-0001`), Role, UserSession, SecurityEvent, AuditLog, Notification(+Read), Setting (key/value), Upload.
  - `Support/Permissions/PermissionResolver` — default-deny RBAC: `has($user,$code,$min='OWN')`, `hasAny`, `scopeOf`, `require()` (throws 403), `canGrant`, `assertAdminRemains()`. Also `Gate::before` so `$user->can('cases.view')` works.
  - `Support/Security/` SecurityPolicy (policy, password rules, accountState), SecurityLog::log(type,…), Sessions (token+session start/terminate), Device.
  - `Support/Activity/Activity` — `audit()`, `notify([...])`, `emit(action, recordType, recordId, actor, notify?)` (port of front activity_service.emit). Notification targeting: `userId` > `roles[]`; `providerId` / `beneficiaryId` restrict further.
  - `Support/Ids/Sequence` — concurrency-safe ids (`caseId()`, `serviceId()`, `providerId()`, `beneficiaryId()`, `walletId()`, `roundId()`, `assignmentId()`, `ledgerId()`, `authorizationNumber()`); never count+1.
  - `Support/Text` (Arabic norm/matches/mask, asciiDigits), `Support/Money` (halalas), `Support/Raha` + `raha('file.path')` helper = frontend config exported to `database/seed-data/*.json` (workflow actions, permission catalog, document rules, form rules, labels…).
  - `Support/Serialization/FrontSerializable` — `toFront()` = camelCase keys, ISO dates, nulls dropped. Models override when they assemble nested shapes.
  - helpers: `iso()`, `now_iso()`, `ar_date()` (same Arabic date string as front fmtDate), `uid()`.
  - `Exceptions/DomainRuleException` — business-rule failure → JSON `{message}` (422 default; `::forbidden()` 403; `::notFound()` 404).
- `Modules/<Name>/` — one per domain: `app/Http/Controllers`, `app/Services` (business logic), `app/Models`, `routes/api.php`, `database/migrations`, `tests/Feature`. Namespace `Modules\<Name>\…`.
- ALL tables/models already exist (migrations in `database/migrations` + `Modules/*/database/migrations`). Add columns only through a NEW migration in your own module.

## API conventions
- Every route is under `/api/v1` (module RouteServiceProvider adds the prefix). Authenticated routes use middleware `raha.auth` (Sanctum bearer + server session policy). Portal isolation: `account:STAFF`, `account:STAFF,PROVIDER`, `account:BENEFICIARY`.
- Responses: lists `{"data": [...]}` (full list, the frontend filters/paginates client-side), single `{"data": {...}}`; write actions return the updated resource as `{"data": ...}` (and anything the screen needs to refresh). Errors: `{"message": "<Arabic>"}` with 401/403/404/422.
- Payload/response field names are the frontend TypeScript names (`front/src/types/*.ts`) — camelCase, same shapes, so the frontend keeps its types.
- Permission checks in services or controllers with `PermissionResolver` (inject it). Account-type checks with `$user->isStaff()/isProvider()/isBeneficiary()`.
- Multi-table writes: `DB::transaction(...)` + `lockForUpdate()` on the rows you check-then-write (case, approval, wallet, round...).
- Audit + notifications through `Activity::emit/notify` with the same Arabic texts/links as the front (`link` = `{"to": "/cases/$caseId", "params": {"caseId": "REQ-…"}}`).
- Dates in JSON are ISO strings (`iso()`); Case `date`/`updated` display strings are `ar_date()`.

## Seed / demo accounts (password `demo1234`)
u1 admin · u2 auditor · u3 medical · u4 social · u5 manager · u6 finance · u13 executive · u7 team · u8 relations ·
u10/u11/u12 providers (PRV-0005/PRV-0001/PRV-0006) · u-BEN-0001 beneficiary (national id 1087654321).
15 seeded requests (REQ-000110…123 + REQ-DEMO-0001 awaiting_quotation with open round QR-DEMO-0001), 12 services, 7 providers, 5 wallets.

## Tests
- `php artisan test Modules/<Name>` — in-memory SQLite, migrated + seeded per test (`Tests\TestCase`).
- Authenticate with `$this->asRole('auditor')` / `$this->as('u5')` (real token + session). Re-call it to switch user.
- Port the scenarios of the matching front tests in `../front/src/test/*.test.ts` plus negative permission/ownership/privacy cases.

## Rules for parallel work
- Only edit files inside the module(s) you own. Do not edit `app/`, `bootstrap/`, `config/`, `database/`, other modules or `tests/TestCase.php`; if you truly need a Core change, write it in your module or describe it in your final report.
- Cross-module reads are fine (use other modules' models/services); keep dependencies pointing "down": Catalog/Beneficiaries ← Cases ← Visits/Quotations/Finance/Authorizations ← Reports.
