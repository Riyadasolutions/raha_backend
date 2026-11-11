# Cases module

Service requests (front `RahaCase`), the review workflow, tasks, documents and the beneficiary portal.
Ports `front/src/services/case_*.ts`, `task_service.ts`, `review_service.ts`, `document_service.ts`,
`stage_statistics_service.ts`, `request_status_service.ts`, `fast_track_service.ts` (eligibility), `visit_guard.ts`.

## Public services for other modules

Resolve through the container (`app(CaseAccess::class)` or constructor injection).

| Service | Method | Semantics |
|---|---|---|
| `CaseAccess` | `canAccessCase(RahaCase $c, ?User $u): bool` | Beneficiary owns it / provider is assigned to it / staff by stage + `cases.view` scope (OWN/ASSIGNED = current task owner or assignee). |
| | `canAccessStage(?User $u, string $status): bool` | Staff stage visibility (default deny; `executive.approvals.view` sees all; archived visible to all with `cases.view`). |
| | `canAccessCaseTab(?User $u, string $tab): bool` | Detail-tab grants (Arabic tab names from front `case_tabs.tsx`). |
| | `canSeeVisitCase(RahaCase $c, ?User $u): bool` | Case access + `visits.monitor` on `completed` requests. |
| | `findAccessible(string $id, User $u): RahaCase` | 404 `الطلب غير موجود.` when missing or not visible. |
| | `CaseAccess::assertMutable(RahaCase $c)` | 422 `الطلب مؤرشف وللقراءة فقط.` for archived requests. Call before any write. |
| `CaseRecorder` | `touch(RahaCase $c, string $status): RahaCase` | Sets status, stage label, default assignee (provider name for assigned/scheduled/in_progress), `updated`. Caller saves. |
| | `addActivity(RahaCase $c, string $text, string $actor, string $visibility, array $extra = [])` | `$extra`: action, fromStage, toStage, reason, actorId, at. Visibility `beneficiary`/`both` also creates the beneficiary portal notification (link `/beneficiary/requests/$requestId`). |
| | `addComment(RahaCase $c, string $text, string $author, string $visibility, ?string $authorId)` | |
| | `caseEvent(RahaCase $c, string $action, ?User $u)` | Audit row + staff notification for the case's current status (titles/roles of front `caseEvent`; provider-only when a provider is set and the stage targets providers). |
| `WorkflowService` | `perform(string $caseId, string $key, User $u, array $payload = []): RahaCase` | Every guard of front `caseService.perform` in order, in a transaction with the case row locked. Payload = front `DecisionPayload`. Used by Quotations (`quotation_complete`), Authorizations (`manager_assign` with `providerId` + `authorizationId` of an ISSUED letter matching `case.finance.authorizationId`), providers (`provider_accept`), managers (`care_approve` sets `finance = {approvalId:'', stage:'pending_review', configVersion}`). Safe to call inside an outer transaction. |
| `CaseActions` | `actionsForCase(RahaCase $c, ?User $u): array`, `CaseActions::requirementMet(array $action, RahaCase $c)` | Action availability (task ownership for review stages, provider ownership). |
| `TaskService` | `withCurrentTask(RahaCase $c, $at = null)`, `finishTask(...)`, `claim/release/reassign`, `queueEligible(RahaCase, ?User)` | Review-stage tasks. |
| `CaseProjector` | `forUser(RahaCase, User, bool $light = false)`, `forStaff`, `forProvider`, `forBeneficiary` | Audience projections (providers never get `verificationCode`, social, notes, workflow, finance). |
| `VisitGuard` | `assertVisitsComplete(RahaCase)`, `snapshotVisitPlan(?Service, string $name, string $at)` | |
| `Requirements\RequirementsEngine` | `evaluate`, `missingRequired`, `housingDocumentErrors`, `snapshot`, `resolveService` | Minimal port of front `requirements_service.ts` (Catalog had none yet). |

## Endpoints (`/api/v1`, `raha.auth`)

- `POST uploads` (any account) — multipart `file`, PDF/JPG/PNG by sniffed MIME, ≤ 5 MB, private disk.
- `account:STAFF,PROVIDER`: `GET cases`, `GET cases/stage-statistics`, `GET cases/{id}`, `GET cases/{id}/actions`,
  `POST cases/{id}/actions/{key}`, `GET cases/{id}/timeline`, `POST cases/{id}/comments`, `POST cases/{id}/documents`,
  `DELETE cases/{id}/documents/{docId}`, `PATCH cases/{id}/documents/{docId}/review`, `GET cases/{id}/documents/{docId}/download`,
  `POST cases/{id}/invoice`, `GET requests/status-lookup?number=`.
- `account:STAFF`: `GET tasks?view=my|queue|completed|downstream`, `POST cases/{id}/task/claim|release|reassign`,
  `GET cases/{id}/task/eligible-users`, `POST beneficiaries/{id}/requests`.
- `account:BENEFICIARY` (`portal/`): `GET requests`, `GET requests/pending-survey`, `POST requests`, `GET requests/{id}`,
  `POST requests/{id}/resubmit|survey|confirm-service|evaluation`, `GET requests/{id}/documents/{docId}/download`,
  `GET appointments`, `GET fast-track/eligibility`.
