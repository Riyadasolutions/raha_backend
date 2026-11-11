# RAHA — backend API

REST API for the RAHA home-healthcare request platform. It owns all domain data and enforces every business rule
(permissions, workflow, quotations, finance, authorizations, visits, reports). The React app in [`../front`](../front/README.md) is the UI.

## Stack
- PHP 8.3+, Laravel 13, Laravel Sanctum (bearer tokens + server-side session policy), `nwidart/laravel-modules` (one module per domain).
- MySQL in development/production (XAMPP locally); in-memory SQLite for tests.
- All routes under `/api/v1`; JSON field names match the frontend TypeScript types (camelCase); errors are `{"message": "<Arabic>"}` with 401/403/404/422.

## Modules (`Modules/<Name>`)
| Module | Responsibility |
|---|---|
| Auth | Staff/provider and beneficiary sign-in, registration, password change/recovery, `auth/me`, sessions, MFA, dev quick-login |
| Access | Roles and permission catalog, default-deny RBAC with scopes, escalation and last-admin guards |
| Users | Staff/provider accounts, role assignment, suspend/activate, per-user sessions |
| Audit | Audit log and security event queries |
| Notifications | In-app notifications (targeted by user/role/provider/beneficiary), unread counts, read state |
| Settings | Bootstrap data, language default, organization identity, Fast Track policy, public contact form |
| Catalog | Services (delivery type, document requirements), providers, catalog options, public service list |
| Beneficiaries | Beneficiary directory and profiles, staff-assisted requests, documents, financial summary, notes |
| Cases | Requests/cases: submission (portal and staff), document upload/review, workflow actions and task ownership, timeline, comments, archive guards, stage statistics, status lookup, Fast Track routing |
| Quotations | Quotation rounds, provider invitations and submissions, comparison/selection, provider assessments, quotation document model |
| Finance | Wallets and ledger, funding review → split → reservation → executive approval, commitments, finance config and reports |
| Authorizations | Authorization letters (issue/cancel/replace, issuance snapshot, document model), provider assignment and acknowledgement |
| Visits | Visit plan and approved visit count, visit scheduling/completion/missed/cancel/corrections, completion guard, visit monitoring |
| Reports | Report projections and exports, KPIs, executive analytics, executive and healthcare-manager monitoring |

Shared core (not a module) lives in `app/`: models, `Support/Permissions/PermissionResolver`, security policy and sessions, activity (audit + notifications), id sequences, money/text helpers and the frontend config export in `database/seed-data/*.json`.

## Setup
```sh
# MySQL running (XAMPP) with an empty database `raha`
composer install
cp .env.example .env            # DB_DATABASE=raha, DB_USERNAME=root, DB_PASSWORD=
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve               # http://127.0.0.1:8000/api/v1
```
Relevant `.env` keys: `RAHA_FRONTEND_ORIGINS` (CORS, default `http://localhost:8080,http://127.0.0.1:8080`) and
`RAHA_DEV_ACCOUNTS` (dev-only quick sign-in and echoed reset tokens/OTP; never enable in production).

## Testing
```sh
php artisan test                    # all modules
php artisan test Modules/Cases      # one module
```
Tests run against in-memory SQLite (`phpunit.xml`), migrated and seeded per test. Feature tests live in
`Modules/*/tests/Feature` and authenticate with `$this->asRole('auditor')` / `$this->as('u5')`.

## Demo accounts (password `demo1234`)
| User | Username | Role |
|---|---|---|
| u1 | admin | System administrator |
| u2 | auditor | Request auditor |
| u3 | medical | Medical committee |
| u4 | social | Social researcher |
| u5 | manager | Healthcare manager |
| u6 | finance | Finance |
| u13 | executive | Executive director |
| u7 | team01 | Internal medical team |
| u8 | relations | Patient relations |
| u10 / u11 / u12 | provider / provider2 / provider3 | Providers PRV-0005 / PRV-0001 / PRV-0006 |
| u-BEN-0001 | national id `1087654321` | Beneficiary (`auth/beneficiary/login`) |

The seed also creates 15 requests (REQ-000110…123 and REQ-DEMO-0001 with open quotation round QR-DEMO-0001), 12 services, 7 providers and 5 wallets.

## Postman
A collection and local environment are in [`postman/`](postman/) (`raha.postman_collection.json`, `raha.local.postman_environment.json`, request fixtures in `postman/fixtures`). Run them with the Postman app or the Postman CLI against a seeded local server.

## Conventions
Module layout, API conventions, permission checks, transactions/locking, audit/notification rules and parallel-work rules are in [`AGENTS.md`](AGENTS.md) — read it before changing code.
