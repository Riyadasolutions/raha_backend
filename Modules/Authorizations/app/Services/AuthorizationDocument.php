<?php

namespace Modules\Authorizations\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Finance\Services\FinanceService;

/**
 * Official letter document MODEL + access rules (port of front authorization_document_service.ts minus the HTML/PDF rendering,
 * which stays client-side). Read-only: never issues, numbers, saves or advances anything.
 */
class AuthorizationDocument
{
    public const NO_ACCESS = 'لا تملك صلاحية عرض خطاب التعميد هذا.';

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private AuthorizationService $authorizations) {}

    /** front locate(): providers reach only their own ISSUED letter that has an assignment; staff need authorization.view + case access. */
    public function locate(string $id, User $u): ServiceAuthorization
    {
        $l = ServiceAuthorization::query()->find($id) ?? throw DomainRuleException::forbidden(self::NO_ACCESS);
        if ($u->isProvider()) {
            $own = $u->provider_id && $l->provider_id === $u->provider_id && $this->perms->has($u, 'provider.authorization.viewOwn') && $l->status === 'ISSUED'
                && ServiceAssignment::query()->where('authorization_id', $l->id)->where('provider_id', $u->provider_id)->exists();
            if ($own) {
                return $l;
            }
            throw DomainRuleException::forbidden(self::NO_ACCESS);
        }
        $c = RahaCase::query()->find($l->case_id);
        if ($u->isStaff() && $this->perms->has($u, 'authorization.view') && ($l->status !== 'DRAFT' || $this->perms->has($u, 'authorization.create'))
            && $c && $this->access->canAccessCase($c, $u)) {
            return $l;
        }
        throw DomainRuleException::forbidden(self::NO_ACCESS);
    }

    private static function dateOf(?string $iso): string
    {
        if (! $iso) {
            return '';
        }
        try {
            return Carbon::parse($iso)->utc()->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /** front buildAuthorizationModel — $l is the front ServiceAuthorization shape (stored letter toFront() or an unsaved draft). */
    public function model(array $l): array
    {
        $snap = $l['document'] ?? null;
        $live = OrganizationIdentity::snapshot();
        // Legacy letters keep today's TEXT identity but never get a logo/signature/stamp that was not there when they were issued.
        $org = $snap ? $snap['org'] : array_intersect_key($live, array_flip(OrganizationIdentity::TEXT_KEYS));
        $prov = $snap['provider'] ?? ['name' => $l['providerName'] ?? '', 'address' => '', 'phone' => '', 'email' => ''];
        $status = $l['status'];
        $replaced = ! empty($l['replacesId']) ? ServiceAuthorization::query()->whereKey($l['replacesId'])->value('authorization_number') : null;
        $visits = $snap['approvedVisitCount'] ?? null;
        $quantity = (float) ($l['quantity'] ?? 0);
        $problems = [];
        if (trim((string) ($l['providerName'] ?? '')) === '' && trim((string) ($prov['name'] ?? '')) === '') {
            $problems[] = 'اسم مقدم الخدمة غير متوفر.';
        }
        if (trim((string) ($l['serviceName'] ?? '')) === '') {
            $problems[] = 'الخدمة المعمدة غير محددة.';
        }
        if (! ($quantity > 0)) {
            $problems[] = 'الكمية المعتمدة غير محددة.';
        }
        if (trim((string) ($org['nameAr'] ?? '')) === '') {
            $problems[] = 'اسم الجمعية غير مضبوط في الإعدادات.';
        }
        if ($status !== 'DRAFT' && self::dateOf($l['issuedAt'] ?? null) === '') {
            $problems[] = 'تاريخ إصدار التعميد غير متوفر.';
        }
        $amount = (int) ($l['amountHalalas'] ?? 0);

        return array_filter([
            'official' => $status === 'ISSUED', 'status' => $status,
            'watermark' => match ($status) { 'DRAFT' => 'draft', 'CANCELLED' => 'cancelled', 'REPLACED' => 'replaced', default => null },
            'number' => (string) ($l['authorizationNumber'] ?? ''), 'version' => (int) ($l['version'] ?? 1), 'issueDate' => self::dateOf($l['issuedAt'] ?? null),
            'requestRef' => $l['caseId'], 'replacesNumber' => $replaced, 'quotationNumber' => $snap['quotationNumber'] ?? null,
            'org' => $org, 'source' => $snap ? 'snapshot' : 'current',
            'provider' => ['name' => ($l['providerName'] ?? '') ?: ($prov['name'] ?? ''), 'address' => $prov['address'] ?? '', 'phone' => $prov['phone'] ?? '', 'email' => $prov['email'] ?? ''],
            'service' => ['name' => (string) ($l['serviceName'] ?? ''), 'description' => (string) ($l['description'] ?? ''), 'scope' => (string) ($l['scope'] ?? '')],
            'quantity' => ['value' => floor($quantity) === $quantity ? (int) $quantity : $quantity, 'unit' => (string) ($l['unit'] ?? '')],
            'approvedVisitCount' => is_int($visits) && $visits > 0 ? $visits : null, 'amountHalalas' => $amount > 0 ? $amount : null,
            'expectedStart' => (string) ($l['expectedStart'] ?? ''), 'validUntil' => self::dateOf($l['validUntil'] ?? null),
            'signatory' => array_filter([
                'title' => (string) ($l['signatoryTitle'] ?? ''), 'name' => (string) ($l['signatoryName'] ?? ''),
                'signature' => $snap && OrganizationIdentity::isSafeLogo($org['signatureImage'] ?? null) ? $org['signatureImage'] : null,
                'stamp' => $snap && OrganizationIdentity::isSafeLogo($org['stampImage'] ?? null) ? $org['stampImage'] : null,
            ], fn ($v) => $v !== null),
            'problems' => $problems,
        ], fn ($v, $k) => $v !== null || $k === 'watermark', ARRAY_FILTER_USE_BOTH);
    }

    /** Model + filename + export eligibility (front prepared()/exportable()). The PDF/HTML is rendered by the frontend. */
    public function prepared(array $model, string $lang): array
    {
        $filename = 'RAHA_Authorization_'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $model['number'] ?: 'DRAFT').'.pdf';
        $error = $model['status'] === 'DRAFT' ? 'لا يمكن تنزيل أو طباعة مسودة على أنها خطاب تعميد رسمي.' : ($model['problems'][0] ?? null);

        return array_filter([
            'model' => $model, 'lang' => $lang, 'filename' => $filename, 'title' => substr($filename, 0, -4), 'exportable' => $error === null, 'exportError' => $error,
        ], fn ($v) => $v !== null);
    }

    public function forLetter(string $id, User $u, string $lang): array
    {
        return $this->prepared($this->model($this->locate($id, $u)->toFront()), $lang);
    }

    /** Preview of a not-yet-issued letter for staff who may create it; DRAFT, nothing is saved or numbered. */
    public function draftPreview(string $caseId, array $input, User $u, string $lang): array
    {
        if (! $u->isStaff() || ! $this->perms->has($u, 'authorization.create')) {
            throw DomainRuleException::forbidden(self::NO_ACCESS);
        }
        $c = RahaCase::query()->find($caseId);
        if (! $c || ! $this->access->canAccessCase($c, $u)) {
            throw DomainRuleException::forbidden(self::NO_ACCESS);
        }
        $base = $this->authorizations->draft($c, FinanceService::approvalFor($c->id))
            ?? throw new DomainRuleException('لا يتوفر اعتماد نهائي لمعاينة المسودة.');
        $draft = array_merge($base, AuthorizationService::letterInput($input), [
            'beneficiaryName' => $c->beneficiary, 'id' => 'DRAFT', 'authorizationNumber' => '', 'status' => 'DRAFT', 'version' => 1,
            'document' => $this->authorizations->snapshot($c, $base, now()),
        ]);

        return $this->prepared($this->model($draft), $lang) + ['letter' => $draft];
    }
}
