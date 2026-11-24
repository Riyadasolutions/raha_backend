<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\CaseReview;
use Modules\Cases\Models\RahaCase;
use Modules\Catalog\Models\Provider;
use Modules\Finance\Models\FinanceConfig;
use Modules\Finance\Services\SettlementService;

/**
 * Workflow transitions (port of front caseService.perform). PUBLIC API for other modules:
 *   perform(string $caseId, string $key, User $user, array $payload = []): RahaCase
 * Runs every guard of the mock in order, inside a transaction with the case row locked; throws DomainRuleException
 * (Arabic message, 403/404/422) on failure. Callers: Quotations (quotation_complete), Authorizations (manager_assign with
 * authorizationId+providerId), providers (provider_accept), managers (care_approve → finance pointer).
 */
class WorkflowService
{
    public const NEEDS_QUOTE = 'يحتاج إلى تسعير من مقدم خدمة';

    /** front case_service auditText */
    public const AUDIT_TEXT = [
        'audit_approve' => 'تم اعتماد طلب', 'medical_approve' => 'تم اعتماد المراجعة الطبية', 'social_complete' => 'تم إكمال الدراسة الاجتماعية', 'manager_assign' => 'تم إسناد طلب',
        'provider_accept' => 'أكد مقدم الخدمة استلام الطلب', 'schedule' => 'تم تحديد موعد', 'start_service' => 'بدأت الخدمة', 'complete_service' => 'اكتملت الخدمة',
        'relations_review' => 'تمت مراجعة علاقات المرضى', 'finance_complete' => 'تمت الأرشفة',
    ];

    public function __construct(
        private PermissionResolver $perms, private CaseAccess $access, private CaseActions $actions, private TaskService $tasks,
        private CaseRecorder $recorder, private ReviewValidator $validator,
    ) {}

    /** @param array{reason?: string, notes?: string, publicNote?: string, providerId?: string, date?: string, time?: string, fields?: array, confirmed?: bool, showToBeneficiary?: bool, correctionFields?: string[], docIds?: string[], targetStage?: string, authorizationId?: string} $p */
    public function perform(string $caseId, string $key, User $user, array $p = []): RahaCase
    {
        return DB::transaction(function () use ($caseId, $key, $user, $p) {
            $action = WorkflowConfig::action($key);
            $c = RahaCase::query()->lockForUpdate()->find($caseId);
            if ($c) {
                $c->load(['tasks', 'documents']);
            }
            $hasAccess = $c && $this->access->canAccessCase($c, $user);
            // External accounts (providers, beneficiaries) get ONE answer whether the request exists, is theirs, or is in another
            // state, so the endpoint cannot be used to probe which ids exist or what stage they are in. Staff keep the finer messages
            // (e.g. "action not available for this state" after a double submit).
            if (! $hasAccess && ! $user->isStaff()) {
                throw DomainRuleException::forbidden('لا تملك صلاحية الوصول إلى هذا الطلب.');
            }
            if (! $action || ! $c || $c->status !== $action['from']) {
                throw new DomainRuleException('الإجراء غير متاح لهذه الحالة.');
            }
            if (! $hasAccess) {
                throw DomainRuleException::forbidden('لا تملك صلاحية الوصول إلى هذا الطلب.');
            }
            if ($user->isBeneficiary() || (WorkflowConfig::reviewStageOf($c->status) && ! $user->isStaff())) {
                throw DomainRuleException::forbidden('هذا الإجراء متاح لموظفي المراجعة فقط.');
            }
            if (! $this->perms->has($user, $action['perm'])) {
                throw DomainRuleException::forbidden('لا تملك صلاحية تنفيذ هذا الإجراء.');
            }
            if (! collect($this->actions->actionsForCase($c, $user))->contains('key', $key)) {
                throw DomainRuleException::forbidden('استلم المهمة أولًا؛ يمكن لمالك المهمة فقط اتخاذ القرار.');
            }
            if (($action['requires'] ?? null) === 'serviceConfirmed' && ! $c->service_confirmed) {
                throw new DomainRuleException('بانتظار تأكيد المستفيد لاستلام الخدمة.');
            }
            if (! CaseActions::requirementMet($action, $c)) {
                throw new DomainRuleException('الإجراء غير متاح لهذه الحالة.');
            }
            if ($user->provider_id && $c->provider_id !== $user->provider_id) {
                throw DomainRuleException::forbidden('هذا الطلب غير مسند إليك.');
            }
            if (! empty($p['notes']) && ! $this->perms->has($user, 'comments.internal.create')) {
                throw DomainRuleException::forbidden('لا تملك صلاحية إضافة ملاحظة داخلية.');
            }
            if (! empty($p['publicNote']) && ! $this->perms->has($user, 'comments.beneficiary.create')) {
                throw DomainRuleException::forbidden('لا تملك صلاحية إضافة ملاحظة للمستفيد.');
            }
            CaseAccess::assertMutable($c);
            if ($key === 'complete_service') {
                VisitGuard::assertVisitsComplete($c);
            }
            if ($key === 'finance_complete') {
                app(SettlementService::class)->assertReconciled($c); // before any irreversible transition
            }
            $this->validator->validateDecision($action, $c, $p);
            if ($key === 'care_approve' && $c->quotation_required && ! $c->preferred_quotation) {
                throw new DomainRuleException('لا يمكن الاعتماد المبدئي قبل استكمال عروض الأسعار واختيار العرض المناسب.');
            }
            if ($key === 'manager_assign' && ($c->finance || $c->quotation_required)) {
                $l = ! empty($p['authorizationId']) ? ServiceAuthorization::query()->where('id', $p['authorizationId'])->where('case_id', $c->id)->where('status', 'ISSUED')->first() : null;
                if (! $l || ($c->finance['stage'] ?? null) !== 'authorization_issued' || ($c->finance['authorizationId'] ?? null) !== $l->id) {
                    throw new DomainRuleException('لا يمكن إسناد الخدمة قبل الاعتماد المالي والنهائي وإصدار خطاب التعميد.');
                }
                if (($p['providerId'] ?? null) !== $l->provider_id) {
                    throw new DomainRuleException('مقدم الخدمة يجب أن يطابق خطاب التعميد.');
                }
            }
            $prov = ! empty($p['providerId']) ? Provider::query()->find($p['providerId']) : null;
            if ($key === 'manager_assign' && empty($p['providerId'])) {
                throw new DomainRuleException('اختر مقدم الخدمة.');
            }
            if ($key === 'manager_assign' && ! $prov) {
                throw new DomainRuleException('مقدم الخدمة غير موجود.');
            }

            $this->apply($c, $action, $user, $p, $prov);
            $c->refresh();
            $this->recorder->caseEvent($c, match ($action['kind']) {
                'return' => 'تم إرجاع طلب', 'reject' => 'تم رفض طلب', default => self::AUDIT_TEXT[$key] ?? $action['label'],
            }, $user);

            return $c;
        });
    }

    private function apply(RahaCase $c, array $action, User $user, array $p, ?Provider $prov): void
    {
        $at = now();
        $key = $action['key'];
        $fields = is_array($p['fields'] ?? null) ? array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $p['fields']) : [];
        $reason = isset($p['reason']) && $p['reason'] !== '' ? (string) $p['reason'] : null;
        $from = $c->status;
        $needsQuote = ($action['form'] ?? null) === 'medical' && ($fields['decision'] ?? null) === self::NEEDS_QUOTE;
        $target = $needsQuote ? 'awaiting_quotation' : ($action['kind'] === 'send_back' ? ($p['targetStage'] ?? $action['to']) : $action['to']);

        $this->tasks->finishTask($c, $user, $action['label'], $at);
        $this->recorder->touch($c, $target);
        $actor = CaseRecorder::actorLabel($user);

        $mainText = ($needsQuote ? 'اعتمد الأخصائي الطبي المراجعة وطلب عروض أسعار من مقدمي الخدمة' : $action['activity']).($reason ? ": {$reason}" : '');
        if ($key === 'manager_assign' && $prov) {
            $mainText = "تم إسناد الخدمة لمقدم الخدمة {$prov->name}";
        }
        if ($review = WorkflowConfig::reviewStageOf($from)) {
            CaseReview::query()->create([
                'id' => 'r-'.Str::lower((string) Str::ulid()), 'case_id' => $c->id, 'stage' => $review['review'], 'decision' => ($fields['decision'] ?? '') ?: $action['label'],
                'by' => $user->name, 'by_id' => $user->id, 'at' => $at, 'fields' => $fields + ($reason ? ['reason' => $reason] : []),
            ]);
        }
        if (($action['form'] ?? null) === 'medical') {
            $c->quotation_required = ($fields['decision'] ?? null) === self::NEEDS_QUOTE;
        }
        if ($action['kind'] === 'return') {
            $docIds = array_values((array) ($p['docIds'] ?? []));
            $cats = $c->documents->filter(fn (CaseDocument $d) => in_array($d->id, $docIds, true))->pluck('category')->all();
            $c->return_info = [
                'reason' => $reason ?? '', 'notes' => (string) ($p['publicNote'] ?? ''), 'date' => iso($at), 'fromStatus' => $from, 'byRole' => $user->role_label,
                'docIds' => $docIds, 'fields' => array_values(array_unique(array_merge((array) ($p['correctionFields'] ?? []), $cats))),
            ];
        }
        if ($action['kind'] === 'reject') {
            $c->reject_reason = $reason;
        }
        if ($key === 'manager_assign' && $prov) {
            $c->forceFill(['provider' => $prov->name, 'provider_id' => $prov->id, 'provider_accepted' => false, 'assigned_at' => $at, 'assignee' => $prov->name]);
        }
        if ($key === 'care_approve') {
            $c->finance = ['approvalId' => '', 'stage' => 'pending_review', 'configVersion' => FinanceConfig::current()->version];
        }
        if ($key === 'provider_accept') {
            $c->provider_accepted = true;
        }
        if ($key === 'schedule') {
            $c->verification_code = (string) random_int(100000, 999999);
            $c->appointment = [
                'date' => ($p['date'] ?? '') ?: now()->addDays(3)->toDateString(), 'time' => ($p['time'] ?? '') ?: '10:30',
                'location' => "منزل المستفيد — {$c->city}", 'status' => 'upcoming',
            ];
        }
        if ($key === 'finance_complete' && $c->invoice) {
            $c->invoice = array_merge($c->invoice, ['status' => 'paid']);
        }
        if ($key === 'finance_complete') {
            // Approved settlement rule: confirmed payment turns the request's commitments into actual wallet deductions, in this same transaction.
            app(SettlementService::class)->settle($c, $user);
        }
        if ($key === 'complete_service' && $c->appointment) {
            $c->appointment = array_merge($c->appointment, ['status' => 'visited']);
        }
        $c->save();

        $this->recorder->addActivity($c, $mainText, $actor, 'internal', ['action' => $key, 'fromStage' => $from, 'toStage' => $target, 'reason' => $reason, 'actorId' => $user->id, 'at' => $at]);
        if (! empty($action['beneficiaryText'])) {
            $this->recorder->addActivity($c, $action['beneficiaryText'], 'جمعية راحة', 'beneficiary', ['at' => $at]);
        }
        if (! empty($p['notes'])) {
            $this->recorder->addComment($c, (string) $p['notes'], $actor, 'internal', $user->id);
        }
        if (! empty($p['publicNote'])) {
            $this->recorder->addComment($c, (string) $p['publicNote'], $user->role_label, 'beneficiary', $user->id);
        }
        if (WorkflowConfig::reviewStageOf($target)) {
            $c->unsetRelation('tasks');
            $this->tasks->withCurrentTask($c, $at);
        }
    }
}
