<?php

namespace Modules\Authorizations\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\VisitGuard;
use Modules\Cases\Services\WorkflowService;
use Modules\Catalog\Models\Provider;
use Modules\Finance\Models\FinanceConfig;
use Modules\Finance\Models\FinancialApproval;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Services\FinanceService;
use Modules\Quotations\Models\ProviderQuotation;

/**
 * Authorization letters, provider assignment and acknowledgement (port of front authorization_service.ts + authorization_snapshot.ts).
 * Every write runs in one transaction with the case (and the letter / approval / assignment) rows locked; numbers come from
 * Sequence::authorizationNumber() (AUTH-<year>-NNNNN, yearly reset) instead of max+1.
 */
class AuthorizationService
{
    public const VISIT_COUNT_REQUIRED_MSG = 'يجب تحديد عدد الزيارات المعتمد للخدمة متعددة الزيارات قبل إصدار خطاب التعميد.';

    public const LETTER_INCOMPLETE = 'أكمل نطاق التعميد والكمية وموعد البدء المتوقع.';

    public function __construct(private PermissionResolver $perms, private FinanceService $finance, private WorkflowService $workflow) {}

    public static function issuedFor(string $caseId): ?ServiceAuthorization
    {
        return ServiceAuthorization::query()->where('case_id', $caseId)->where('status', 'ISSUED')->first();
    }

    /** Every prerequisite of a letter; an empty array means a letter may be issued (front authorizationBlockers). */
    public function blockers(RahaCase $c, ?FinancialApproval $a = null): array
    {
        $a ??= FinanceService::approvalFor($c->id);
        $out = [];
        if ($c->status !== 'preliminary_approved') {
            $out[] = 'الطلب ليس في مرحلة الاعتماد التالي.';
        }
        if (! $c->finance || ! $a) {
            $out[] = 'لم تكتمل المراجعة المالية.';
        }
        if ($c->quotation_required && ! $c->preferred_quotation) {
            $out[] = 'لم يتم اختيار عرض سعر مطابق.';
        }
        if ($a && ! $c->preferred_quotation && ! $a->costing_basis) {
            $out[] = 'لا يوجد أساس تسعير معتمد.';
        }
        if ($a && ! $a->split_approved) {
            $out[] = 'التوزيع المالي غير معتمد.';
        }
        if ($a && ! $a->reserved) {
            $out[] = 'التمويل غير محجوز.';
        }
        if ($a && ($a->executive['decision'] ?? null) !== 'approved') {
            $out[] = 'لا يوجد اعتماد نهائي من المدير التنفيذي.';
        }
        if ($a && $a->executive && ($a->executive['fundingVersion'] ?? null) !== $a->version) {
            $out[] = 'تغير التوزيع المالي بعد الاعتماد النهائي.';
        }
        $p = $a?->provider_id ? Provider::query()->find($a->provider_id) : null;
        if ($a && (! $p || $p->status !== 'active')) {
            $out[] = 'مقدم الخدمة غير مؤهل أو غير نشط.';
        }
        if (self::issuedFor($c->id)) {
            $out[] = 'يوجد خطاب تعميد صادر لهذا الطلب.';
        }
        $plan = $c->visit_plan;
        if (($plan['deliveryType'] ?? null) === 'multiple_visits' && ! VisitGuard::isPositiveInt($plan['approvedVisitCount'] ?? null)) {
            $out[] = self::VISIT_COUNT_REQUIRED_MSG;
        }

        return $out;
    }

    /** Letter defaults taken from the approval (front authorizationService.draft); front camelCase shape, never stored. */
    public function draft(RahaCase $c, ?FinancialApproval $a): ?array
    {
        if (! $a?->provider_id) {
            return null;
        }
        $cfg = FinanceConfig::current();
        $count = $c->visit_plan['approvedVisitCount'] ?? null;

        return array_filter([
            'caseId' => $c->id, 'providerId' => $a->provider_id, 'providerName' => $a->provider_name ?? '', 'quotationId' => $a->selected_quotation_id,
            'financialApprovalId' => $a->id, 'serviceId' => $c->service_id, 'serviceName' => (string) $c->service, 'description' => (string) $c->service,
            'quantity' => $count ?: 1, 'unit' => $count ? 'زيارة' : 'خدمة', 'amountHalalas' => (int) $a->total_cost_halalas, 'scope' => '', 'expectedStart' => '',
            'approvalRef' => "{$a->id} / v{$a->version}", 'signatoryName' => $cfg->signatory_name ?? '', 'signatoryTitle' => $cfg->signatory_title ?? '',
            'validUntil' => $cfg->letter_validity_days ? iso(now()->addDays($cfg->letter_validity_days)) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Issuance-time snapshot (front buildAuthorizationSnapshot): association identity, recipient provider contact, quotation number,
     * delivery type and approved visit count. Later edits never change an issued letter.
     */
    public function snapshot(RahaCase $c, array $l, mixed $at): array
    {
        $p = Provider::query()->find($l['providerId'] ?? '');
        $quotation = ! empty($l['quotationId']) ? ProviderQuotation::query()->where('id', $l['quotationId'])->where('status', 'submitted')->first() : null;
        $plan = $c->visit_plan;
        $multi = ($plan['deliveryType'] ?? null) === 'multiple_visits';

        return array_filter([
            'org' => OrganizationIdentity::snapshot(),
            'provider' => [
                'name' => ($l['providerName'] ?? '') ?: ($p?->name ?? ''), 'address' => implode('، ', array_filter([$p?->address, $p?->city])),
                'phone' => $p?->phone ?? '', 'email' => $p?->email ?? '',
            ],
            'quotationNumber' => $quotation?->number, 'deliveryType' => $plan['deliveryType'] ?? null,
            'approvedVisitCount' => $multi && VisitGuard::isPositiveInt($plan['approvedVisitCount'] ?? null) ? $plan['approvedVisitCount'] : null,
            'snapshotAt' => iso($at),
        ], fn ($v) => $v !== null);
    }

    /** @return array{description: string, quantity: int|float, unit: string, scope: string, expectedStart: string} */
    public static function letterInput(array $i): array
    {
        $q = is_numeric($i['quantity'] ?? null) ? (float) $i['quantity'] : 0.0;

        return [
            'description' => trim((string) ($i['description'] ?? '')), 'quantity' => floor($q) === $q ? (int) $q : $q, 'unit' => trim((string) ($i['unit'] ?? '')),
            'scope' => trim((string) ($i['scope'] ?? '')), 'expectedStart' => trim((string) ($i['expectedStart'] ?? '')),
        ];
    }

    public static function validateLetter(array $i): void
    {
        if ($i['scope'] === '' || $i['expectedStart'] === '' || ! ($i['quantity'] > 0) || $i['unit'] === '') {
            throw new DomainRuleException(self::LETTER_INCOMPLETE);
        }
    }

    private static function row(array $front): array
    {
        $out = [];
        foreach ($front as $k => $v) {
            $out[Str::snake($k)] = $v;
        }

        return $out;
    }

    public function issue(string $caseId, array $input, User $user): ServiceAuthorization
    {
        $u = $this->finance->need($user, 'authorization.issue');

        return DB::transaction(function () use ($caseId, $input, $u) {
            $c = $this->finance->lockCase($caseId, $u);
            $a = FinanceService::approvalFor($caseId, true);
            if ($blockers = $this->blockers($c, $a)) {
                throw new DomainRuleException($blockers[0]);
            }
            $in = self::letterInput($input);
            self::validateLetter($in);
            $base = $this->draft($c, $a);
            if (! $base || ! $a) {
                throw new DomainRuleException('لم تكتمل المراجعة المالية.');
            }
            $at = now();
            $l = ServiceAuthorization::query()->create(self::row(array_merge($base, $in)) + [
                'beneficiary_name' => $c->beneficiary, 'id' => 'AUL-'.uid(), 'authorization_number' => Sequence::authorizationNumber(), 'status' => 'ISSUED',
                'version' => 1, 'issued_at' => $at, 'issued_by' => $u->name, 'document' => $this->snapshot($c, $base, $at),
            ]);
            $active = FundingReservation::query()->where('case_id', $caseId)->where('status', 'ACTIVE')->lockForUpdate()->get();
            foreach ($active as $r) {
                $r->forceFill(['status' => 'CONVERTED', 'closed_at' => $at])->save();
                $this->finance->ledger($r->wallet_id, $caseId, $r->id, $r->amount_halalas, 'COMMITMENT_CONVERSION', "التزام معتمد بموجب {$l->authorization_number} (ليس صرفًا)", $u, $at);
            }
            $this->finance->record($c, $a, 'authorization_issued', $u, "تم إصدار خطاب التعميد {$l->authorization_number}", [
                'amount' => $l->amount_halalas, 'beneficiaryText' => 'تم تعميد مقدم الخدمة لطلبك.', 'notify' => ['title' => 'تم إصدار خطاب تعميد — جاهز للإسناد', 'roles' => ['manager']],
            ]);
            $c->forceFill(['finance' => array_merge($c->finance ?? [], ['authorizationId' => $l->id])])->save();

            return $l;
        });
    }

    public function replace(string $id, array $input, ?string $why, User $user): ServiceAuthorization
    {
        $u = $this->finance->need($user, 'authorization.replace');
        $why = FinanceService::reason($why);
        $in = self::letterInput($input);
        self::validateLetter($in);

        return DB::transaction(function () use ($id, $in, $why, $u) {
            $old = ServiceAuthorization::query()->lockForUpdate()->find($id);
            if (! $old || $old->status !== 'ISSUED') {
                throw new DomainRuleException('يمكن استبدال خطاب صادر فقط.');
            }
            $c = $this->finance->lockCase($old->case_id, $u);
            if (! in_array($c->status, ['preliminary_approved', 'assigned'], true)) {
                throw new DomainRuleException('لا يمكن الاستبدال بعد بدء تقديم الخدمة.');
            }
            $at = now();
            $keep = array_diff_key($old->getAttributes(), array_flip(['id', 'authorization_number', 'created_at', 'updated_at', 'replaces_id', 'replaced_by_id', 'closed_by', 'closed_reason', 'document', 'issued_at']));
            $next = ServiceAuthorization::query()->create(array_merge($keep, self::row($in), [
                'id' => 'AUL-'.uid(), 'authorization_number' => Sequence::authorizationNumber(), 'version' => $old->version + 1, 'replaces_id' => $old->id,
                'issued_at' => $at, 'issued_by' => $u->name, 'status' => 'ISSUED',
                'document' => $this->snapshot($c, ['providerId' => $old->provider_id, 'providerName' => $old->provider_name, 'quotationId' => $old->quotation_id], $at),
            ]));
            $old->forceFill(['status' => 'REPLACED', 'replaced_by_id' => $next->id, 'closed_by' => $u->name, 'closed_reason' => $why])->save();
            ServiceAssignment::query()->where('authorization_id', $old->id)->update([
                'authorization_id' => $next->id, 'scope' => $next->scope, 'quantity' => $next->quantity, 'expected_start' => $next->expected_start,
            ]);
            if ($a = FinanceService::approvalFor($c->id, true)) {
                $this->finance->record($c, $a, $a->stage, $u, "تم استبدال خطاب التعميد {$old->authorization_number} بـ {$next->authorization_number}", ['reason' => $why]
                    + ($c->provider_id ? ['notify' => ['title' => 'تم استبدال خطاب التعميد', 'roles' => ['provider'], 'providerId' => $c->provider_id]] : []));
            }
            if ($c->finance) {
                $c->forceFill(['finance' => array_merge($c->finance, ['authorizationId' => $next->id])])->save();
            }

            return $next;
        });
    }

    /** Withdraws before assignment; commitments are reversed explicitly (CONVERTED → REVERSED + COMMITMENT_REVERSAL), never "released". */
    public function cancel(string $id, ?string $why, User $user): ServiceAuthorization
    {
        $u = $this->finance->need($user, 'authorization.cancel');
        $why = FinanceService::reason($why);

        return DB::transaction(function () use ($id, $why, $u) {
            $l = ServiceAuthorization::query()->lockForUpdate()->find($id);
            if (! $l || $l->status !== 'ISSUED') {
                throw new DomainRuleException('يمكن إلغاء خطاب صادر فقط.');
            }
            $c = $this->finance->lockCase($l->case_id, $u);
            if ($c->status !== 'preliminary_approved') {
                throw new DomainRuleException('لا يمكن إلغاء التعميد بعد إسناد الخدمة.');
            }
            $at = now();
            $l->forceFill(['status' => 'CANCELLED', 'closed_by' => $u->name, 'closed_reason' => $why])->save();
            $conv = FundingReservation::query()->where('case_id', $c->id)->where('status', 'CONVERTED')->lockForUpdate()->get();
            foreach ($conv as $r) {
                $r->forceFill(['status' => 'REVERSED', 'closed_at' => $at, 'closed_reason' => $why])->save();
                $this->finance->ledger($r->wallet_id, $c->id, $r->id, $r->amount_halalas, 'COMMITMENT_REVERSAL', "عكس الالتزام بإلغاء {$l->authorization_number}", $u, $at);
            }
            if ($a = FinanceService::approvalFor($c->id, true)) {
                $a->forceFill(['reserved' => false, 'split_approved' => null, 'executive' => null]);
                $this->finance->record($c, $a, 'in_review', $u, "تم إلغاء خطاب التعميد {$l->authorization_number}", [
                    'reason' => $why, 'notify' => ['title' => 'أُلغي خطاب تعميد — يلزم إعادة المراجعة المالية', 'roles' => ['finance']],
                ]);
            }
            if ($c->finance) {
                $f = $c->finance;
                unset($f['authorizationId']);
                $c->forceFill(['finance' => $f])->save();
            }

            return $l;
        });
    }

    /** Assigns the authorized provider: ServiceAssignment + workflow manager_assign in the same transaction. */
    public function assign(string $caseId, User $user): ServiceAssignment
    {
        $u = $this->finance->need($user, 'provider.assignAuthorized');

        return DB::transaction(function () use ($caseId, $u) {
            $c = $this->finance->lockCase($caseId, $u);
            $l = self::issuedFor($caseId);
            $a = FinanceService::approvalFor($caseId, true);
            if (! $l || ! $a || ($c->finance['stage'] ?? null) !== 'authorization_issued') {
                throw new DomainRuleException('لا يمكن إسناد الخدمة قبل إصدار خطاب التعميد.');
            }
            if ($c->preferred_quotation && ($c->preferred_quotation['providerId'] ?? null) !== $l->provider_id) {
                throw new DomainRuleException('مقدم الخدمة لا يطابق العرض المختار.');
            }
            $this->workflow->perform($caseId, 'manager_assign', $u, ['providerId' => $l->provider_id, 'authorizationId' => $l->id]);
            $asg = ServiceAssignment::query()->create([
                'id' => Sequence::assignmentId(), 'case_id' => $caseId, 'provider_id' => $l->provider_id, 'quotation_id' => $l->quotation_id, 'authorization_id' => $l->id,
                'service_id' => $l->service_id, 'scope' => $l->scope, 'quantity' => $l->quantity, 'amount_halalas' => $l->amount_halalas, 'assigned_at' => now(),
                'expected_start' => $l->expected_start, 'status' => 'ASSIGNED',
            ]);
            $fresh = RahaCase::query()->lockForUpdate()->findOrFail($caseId);
            $this->finance->record($fresh, $a, 'provider_assigned', $u, "تم إسناد الخدمة لمقدم الخدمة {$l->provider_name}", [
                'beneficiaryText' => 'جاري التنسيق لتقديم الخدمة.', 'notify' => ['title' => 'تم تعميدكم وإسناد خدمة إليكم', 'roles' => ['provider'], 'providerId' => $l->provider_id],
            ]);

            return $asg;
        });
    }

    /** The provider's own assignments with their letter and read-only visit info (front authorizationService.forProvider). */
    public function forProvider(User $u): array
    {
        if (! $u->provider_id || ! $this->perms->has($u, 'provider.authorization.viewOwn')) {
            return [];
        }
        $rows = ServiceAssignment::query()->where('provider_id', $u->provider_id)->orderByDesc('assigned_at')->get();
        $letters = ServiceAuthorization::query()->whereIn('id', $rows->pluck('authorization_id'))->where('provider_id', $u->provider_id)->get()->keyBy('id');
        $cases = RahaCase::query()->whereIn('id', $rows->pluck('case_id'))->get(['id', 'service', 'visit_plan'])->keyBy('id');

        return $rows->map(function (ServiceAssignment $s) use ($letters, $cases) {
            $plan = $cases[$s->case_id]->visit_plan ?? null;
            $letter = $letters[$s->authorization_id] ?? null;

            return $s->toFront() + [
                'letter' => $letter ? array_diff_key($letter->toFront(), array_flip(['financialApprovalId', 'approvalRef', 'issuedBy', 'closedBy', 'closedReason'])) : null,
                'visit' => $plan ? array_filter(['deliveryType' => $plan['deliveryType'] ?? null, 'approvedVisitCount' => ($plan['approvedVisitCount'] ?? null) ?: null], fn ($v) => $v !== null) : null,
                'service' => $cases[$s->case_id]->service ?? null,
            ];
        })->values()->all();
    }

    public function acknowledge(string $assignmentId, User $user): ServiceAssignment
    {
        $u = $this->finance->need($user, 'provider.authorization.acknowledgeOwn');

        return DB::transaction(function () use ($assignmentId, $u) {
            $s = ServiceAssignment::query()->lockForUpdate()->find($assignmentId);
            if (! $s || ! $u->provider_id || $s->provider_id !== $u->provider_id) {
                throw DomainRuleException::forbidden('هذا التعميد غير مسند إليك.');
            }
            if ($s->status === 'ACKNOWLEDGED') {
                throw new DomainRuleException('تم تأكيد الاستلام مسبقًا.');
            }
            $c = $this->finance->lockCase($s->case_id, $u);
            if ($c->status === 'assigned' && ! $c->provider_accepted) {
                $this->workflow->perform($c->id, 'provider_accept', $u);
                $c = RahaCase::query()->lockForUpdate()->findOrFail($c->id);
            }
            $s->forceFill(['status' => 'ACKNOWLEDGED', 'acknowledged_at' => now()])->save();
            if ($a = FinanceService::approvalFor($c->id, true)) {
                $this->finance->record($c, $a, $a->stage, $u, 'أكد مقدم الخدمة استلام التعميد', ['notify' => ['title' => 'أكد مقدم الخدمة استلام التعميد', 'roles' => ['manager']]]);
            }

            return $s;
        });
    }

    /** Letter actions of the case finance page (front AuthorizationBox). */
    public function caseActions(RahaCase $c, ?FinancialApproval $a, Collection $letters, User $u): array
    {
        $can = fn (string $p) => ! $c->isArchived() && $this->perms->has($u, $p);
        $issued = $letters->firstWhere('status', 'ISSUED');

        return [
            'canIssue' => ! $issued && $can('authorization.issue'),
            'canPreview' => ! $issued && $can('authorization.create'),
            'canAssign' => $issued && ($c->finance['stage'] ?? null) === 'authorization_issued' && $can('provider.assignAuthorized'),
            'canReplace' => $issued && $can('authorization.replace'),
            'canCancel' => $issued && $c->status === 'preliminary_approved' && $can('authorization.cancel'),
            'issuedId' => $issued?->id,
        ];
    }
}
