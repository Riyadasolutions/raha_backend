<?php

namespace Modules\Cases\Services;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use App\Support\Text;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseComment;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FinancialApproval;

/** Audience projections of a case: staff (tab-filtered), provider (service view), beneficiary (portal view). */
class CaseProjector
{
    /** Keys a provider may ever receive (overview, medical, service). Never nationalId, verificationCode, social, workflow, notes, finance. */
    private const PROVIDER_KEYS = [
        'id', 'beneficiaryId', 'beneficiary', 'mobile', 'city', 'service', 'serviceId', 'subservice', 'description', 'medical',
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
        // Same rule as /beneficiaries: identity and contact are masked unless beneficiaries.viewSensitive is granted.
        if (! $this->perms->has($user, 'beneficiaries.viewSensitive')) {
            foreach (['nationalId', 'mobile'] as $k) {
                if (isset($out[$k])) {
                    $out[$k] = Text::mask((string) $out[$k]);
                }
            }
            if (isset($out['applicant']['mobile'])) {
                $out['applicant']['mobile'] = Text::mask((string) $out['applicant']['mobile']);
            }
            $out['masked'] = true;
        }
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

    /**
     * Medical fields an assigned provider may receive. Only the health condition is service-relevant by default;
     * diagnosis, facility, doctor, report date and any other key stay internal (see the open business decision in the Phase 4 report).
     */
    public const PROVIDER_MEDICAL_FIELDS = ['condition'];

    /** Statuses in which the assigned provider delivers the service; medical data and documents are shared only then. */
    public const PROVIDER_EXECUTION_STATUSES = ['assigned', 'scheduled', 'in_progress'];

    public static function providerExecutionWindow(RahaCase $c): bool
    {
        return in_array($c->status, self::PROVIDER_EXECUTION_STATUSES, true);
    }

    public static function providerVisible(CaseDocument $d, ?RahaCase $c = null): bool
    {
        $c ??= $d->case ?? null;
        if ($c && ! self::providerExecutionWindow($c)) {
            return false;
        }
        $types = \Modules\Cases\Services\Requirements\RequirementsEngine::docTypes();
        $type = $d->doc_type ?? collect($types)->firstWhere('label', $d->category)['id'] ?? null;

        return ! empty($types[$type ?? '']['providerVisible']) && $d->visibility !== 'internal';
    }

    public const STAFF_UPLOADER = 'جمعية راحة';

    public const BENEFICIARY_UPLOADER = 'المستفيد';

    /**
     * Document metadata for the assigned provider: everything needed to list and download it, but never the name of an internal employee
     * (or of the beneficiary's account) as the uploader. Staff uploads show "جمعية راحة", beneficiary uploads "المستفيد"; a provider sees its own name.
     */
    private static function forProviderDocument(CaseDocument $d, RahaCase $c, ?User $viewer): array
    {
        $out = $d->toFront();
        $type = $d->uploaded_by_id ? User::query()->whereKey($d->uploaded_by_id)->value('account_type') : null;
        $type = $type instanceof \BackedEnum ? $type->value : $type;
        $label = match (true) {
            $viewer && $d->uploaded_by_id && $d->uploaded_by_id === $viewer->id => $d->uploaded_by,
            $type === 'PROVIDER' => $d->uploaded_by,
            $type === 'BENEFICIARY' => self::BENEFICIARY_UPLOADER,
            $type === 'STAFF' => self::STAFF_UPLOADER,
            // Seeded/legacy rows without an uploader id: a name that matches the request's own people is the beneficiary, anything else is staff.
            default => in_array($d->uploaded_by, array_filter([$c->beneficiary, $c->account_owner_name, $c->applicant['name'] ?? null, $c->recipient['fullName'] ?? null]), true) ? self::BENEFICIARY_UPLOADER : self::STAFF_UPLOADER,
        };
        $out['uploadedBy'] = $label;

        return $out;
    }

    public function forProvider(RahaCase $c, ?User $user = null): array
    {
        $full = $c->toFront();
        $out = array_intersect_key($full, array_flip(self::PROVIDER_KEYS));
        unset($out['medical']);
        if (self::providerExecutionWindow($c) && ($medical = array_intersect_key((array) ($full['medical'] ?? []), array_flip(self::PROVIDER_MEDICAL_FIELDS)))) {
            $out['medical'] = $medical;
        }
        $out['documents'] = $c->documents->filter(fn (CaseDocument $d) => self::providerVisible($d, $c))->map(fn (CaseDocument $d) => self::forProviderDocument($d, $c, $user))->values()->all();
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
