<?php

namespace Modules\Cases\Services;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseComment;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FinancialApproval;

/** Audience projections of a case: staff (tab-filtered), provider (service view), beneficiary (portal view). */
class CaseProjector
{
    /** Keys a provider may ever receive (overview, medical, service). Never verificationCode, social, workflow, notes, finance. */
    private const PROVIDER_KEYS = [
        'id', 'beneficiaryId', 'beneficiary', 'nationalId', 'mobile', 'city', 'service', 'serviceId', 'subservice', 'description', 'medical',
        'date', 'createdAt', 'updatedAt', 'updated', 'stage', 'status', 'priority', 'provider', 'providerId', 'providerAccepted', 'assignedAt',
        'assignmentType', 'appointment', 'invoice', 'visitPlan', 'serviceConfirmed',
    ];

    /** Keys the beneficiary portal may receive (own request). */
    private const BENEFICIARY_KEYS = [
        'id', 'beneficiaryId', 'beneficiary', 'nationalId', 'mobile', 'city', 'service', 'serviceId', 'subservice', 'description', 'previouslyReceived',
        'medical', 'applicant', 'social', 'recipient', 'requestFor', 'accountOwnerId', 'accountOwnerName', 'requirements', 'date', 'createdAt', 'updatedAt',
        'updated', 'status', 'returnInfo', 'rejectReason', 'provider', 'appointment', 'verificationCode', 'serviceConfirmed', 'evaluation', 'surveyPending', 'requestSurvey',
    ];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseActions $actions) {}

    public function forUser(RahaCase $c, User $user, bool $light = false): array
    {
        return match (true) {
            $user->isBeneficiary() => $this->forBeneficiary($c),
            $user->isProvider() => $this->forProvider($c, $user),
            default => $this->forStaff($c, $user, $light),
        };
    }

    /** Full staff shape with tabs the user cannot open removed. $light drops heavy children (list rows). */
    public function forStaff(RahaCase $c, User $user, bool $light = false): array
    {
        $out = $c->toFront();
        $out['tasks'] = $c->tasks->map(fn (CaseTask $t) => TaskService::taskFront($t))->values()->all();
        $tab = fn (string $t) => $this->access->canAccessCaseTab($user, $t);
        if (! $tab('المعلومات الطبية')) {
            unset($out['medical']);
        }
        if (! $tab('البحث الاجتماعي')) {
            unset($out['social']);
        }
        if (! $tab('سير العمل')) {
            unset($out['activities'], $out['tasks'], $out['reviews']);
        }
        if (! $tab('المستندات')) {
            unset($out['documents']);
        }
        if (! $tab('الملاحظات')) {
            unset($out['comments']);
        }
        if (! $tab('الخدمة')) {
            unset($out['verificationCode']);
        }
        if ($light) {
            unset($out['activities'], $out['comments'], $out['reviews']);
        }
        $out['activeTask'] = TaskService::taskFront($c->activeTask());
        $out['availableActions'] = array_column($this->actions->actionsForCase($c, $user), 'key');

        return array_filter($out, fn ($v) => $v !== null);
    }

    public static function providerVisible(CaseDocument $d): bool
    {
        $types = \Modules\Cases\Services\Requirements\RequirementsEngine::docTypes();
        $type = $d->doc_type ?? collect($types)->firstWhere('label', $d->category)['id'] ?? null;

        return ! empty($types[$type ?? '']['providerVisible']) && $d->visibility !== 'internal';
    }

    public function forProvider(RahaCase $c, ?User $user = null): array
    {
        $full = $c->toFront();
        $out = array_intersect_key($full, array_flip(self::PROVIDER_KEYS));
        $out['documents'] = $c->documents->filter(fn (CaseDocument $d) => self::providerVisible($d))->map->toFront()->values()->all();
        if ($user) {
            $out['availableActions'] = array_column($this->actions->actionsForCase($c, $user), 'key');
        }

        return $out;
    }

    /** Beneficiary label incl. finance stages (front beneficiaryLabelFor). */
    public static function beneficiaryLabel(RahaCase $c): string
    {
        $stage = $c->finance['stage'] ?? null;
        if ($stage && in_array($c->status, ['preliminary_approved', 'assigned'], true) && ($label = raha("finance.beneficiaryFinanceLabels.$stage"))) {
            return $label;
        }

        return raha('workflow.beneficiaryStatusLabel.'.WorkflowConfig::beneficiaryStatusOf($c->status), '');
    }

    /** Approved, beneficiary-safe finance note (front financeService.beneficiaryView). Amount in halalas. */
    public static function financeNote(RahaCase $c): ?array
    {
        if (! $c->finance) {
            return null;
        }
        $label = raha('finance.beneficiaryFinanceLabels.'.($c->finance['stage'] ?? ''));
        if (! $label) {
            return null;
        }
        $a = FinancialApproval::query()->where('case_id', $c->id)->whereNotIn('stage', ['funding_rejected', 'executive_rejected'])->orderByDesc('created_at')->first()
            ?? FinancialApproval::query()->where('case_id', $c->id)->orderByDesc('created_at')->first();
        $approved = $a && ($a->executive['decision'] ?? null) === 'approved';
        $contribution = $approved ? (int) collect($a->allocations ?? [])->where('sourceType', 'BENEFICIARY')->sum('amountHalalas') : null;

        return ['label' => $label, 'contribution' => $contribution];
    }

    public function forBeneficiary(RahaCase $c): array
    {
        $full = $c->toFront();
        $out = array_intersect_key($full, array_flip(self::BENEFICIARY_KEYS));
        $c->loadMissing(['activities', 'comments', 'documents']);
        $out['activities'] = $c->activities->filter(fn (CaseActivity $a) => $a->visibility !== 'internal')
            ->map(fn (CaseActivity $a) => array_intersect_key($a->toFront(), array_flip(['id', 'at', 'text', 'actor', 'visibility'])))->values()->all();
        $out['comments'] = $c->comments->filter(fn (CaseComment $x) => $x->visibility !== 'internal')
            ->map(fn (CaseComment $x) => array_diff_key($x->toFront(), ['authorId' => 1]))->values()->all();
        $out['documents'] = $c->documents->filter(fn (CaseDocument $d) => $d->visibility !== 'internal')
            ->map(fn (CaseDocument $d) => array_diff_key($d->toFront(), ['review' => 1]) + ($d->review && ($d->review['status'] ?? null) !== 'accepted' && $c->status === 'returned' ? ['review' => ['status' => $d->review['status']]] : []))->values()->all();
        $status = WorkflowConfig::beneficiaryStatusOf($c->status);
        $out['beneficiaryStatus'] = $status;
        $out['beneficiaryStatusLabel'] = self::beneficiaryLabel($c);
        $out['progressIndex'] = raha("workflow.beneficiaryProgressIndex.$status", 1);
        if ($note = self::financeNote($c)) {
            $out['financeNote'] = $note;
        }

        return $out;
    }
}
