<?php

namespace Modules\Quotations\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Catalog\Services\ServiceDelivery;
use Modules\Quotations\Models\ProviderAssessment;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;

/**
 * Port of front provider_assessment_service.ts (Task 16). A provider may read the minimum beneficiary contact data of an OPEN
 * invitation and record one non-medical needs assessment per invitation. Contact data is read from the request at view
 * time and never copied into the assessment. Saving never submits a quotation or changes any workflow state.
 */
class ProviderAssessmentService
{
    public const NO_ACCESS = 'لا تملك صلاحية الوصول إلى هذه الدعوة.';

    public const CONTACT_STATUSES = ['not_contacted', 'contacted', 'unreachable'];

    public const METHODS = ['phone', 'visit'];

    public const MAX_TEXT = 2000;

    private const FIELDS = ['contactStatus', 'assessedAt', 'method', 'needsNotes', 'recommendedScope', 'recommendedVisits', 'comments'];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access) {}

    /** @return array{0: QuotationRound, 1: QuotationInvitation} */
    private function ownInvitation(string $invitationId, ?User $u, string $perm, bool $lock = false): array
    {
        if (! $u || ! $u->isProvider() || ! $u->provider_id || ! $this->perms->has($u, $perm)) {
            throw DomainRuleException::forbidden(self::NO_ACCESS);
        }
        $inv = QuotationInvitation::query()->find($invitationId);
        $q = QuotationRound::query();
        $r = $inv ? ($lock ? $q->lockForUpdate() : $q)->find($inv->quotation_round_id) : null;
        if (! $inv || ! $r || $inv->provider_id !== $u->provider_id || $r->status === 'draft') {
            throw DomainRuleException::forbidden(self::NO_ACCESS);
        }

        return [$r, $inv];
    }

    private function isOpen(QuotationRound $r, QuotationInvitation $inv): bool
    {
        return QuotationRules::invitationOpen($r, $inv, RahaCase::query()->find($r->case_id));
    }

    private static function trim(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /** Name, phone, request number and service for an OPEN invitation of this provider; never the case object. */
    public function contact(string $invitationId, ?User $u): array
    {
        [$r, $inv] = $this->ownInvitation($invitationId, $u, 'provider.quotations.viewOwn');
        if (! $this->isOpen($r, $inv)) {
            throw new DomainRuleException('انتهت المشاركة في هذه الدعوة؛ لا تتوفر بيانات التواصل.');
        }
        $c = RahaCase::query()->find($r->case_id) ?? throw DomainRuleException::forbidden(self::NO_ACCESS);
        $phone = self::trim($c->mobile) ?: (self::trim($c->applicant['mobile'] ?? null) ?: self::trim($c->recipient['mobileNumber'] ?? null));
        $name = self::trim($c->applicant['name'] ?? null) ?: (self::trim($c->recipient['fullName'] ?? null) ?: (self::trim($c->beneficiary) ?: 'غير متوفر'));

        return ['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'requestNumber' => $r->request_number, 'service' => $r->service];
    }

    /** The provider's own assessment (null when none) + whether it is still editable. */
    public function mine(string $invitationId, ?User $u): array
    {
        [$r, $inv] = $this->ownInvitation($invitationId, $u, 'provider.quotations.viewOwn');
        $a = ProviderAssessment::query()->where('invitation_id', $invitationId)->first();
        $canEdit = $this->perms->has($u, 'provider.quotations.editDraftOwn') && $this->isOpen($r, $inv);

        return ['assessment' => $a?->toFront(), 'canEdit' => $canEdit];
    }

    /** @param array{contactStatus?: string, assessedAt?: ?string, method?: ?string, needsNotes?: ?string, recommendedScope?: ?string, recommendedVisits?: mixed, comments?: ?string} $in */
    public function save(string $invitationId, array $in, ?User $u): ProviderAssessment
    {
        return DB::transaction(function () use ($invitationId, $in, $u) {
            [$r, $inv] = $this->ownInvitation($invitationId, $u, 'provider.quotations.editDraftOwn', true);
            if (! $this->isOpen($r, $inv)) {
                throw new DomainRuleException('أُغلقت هذه الدعوة ولا يمكن تعديل التقييم.');
            }
            if (! in_array($in['contactStatus'] ?? null, self::CONTACT_STATUSES, true)) {
                throw new DomainRuleException('حالة التواصل غير صحيحة.');
            }
            $method = $in['method'] ?? null;
            if ($method !== null && ! in_array($method, self::METHODS, true)) {
                throw new DomainRuleException('طريقة التقييم غير صحيحة.');
            }
            $assessedAt = $in['assessedAt'] ?? null;
            if ($assessedAt && ! self::dateOk($assessedAt)) {
                throw new DomainRuleException('تاريخ التقييم غير صحيح أو في المستقبل.');
            }
            $visits = $in['recommendedVisits'] ?? null;
            if ($visits !== null && ! ServiceDelivery::isPositiveInt($visits)) {
                throw new DomainRuleException('عدد الزيارات المقترح يجب أن يكون عددًا صحيحًا موجبًا.');
            }
            foreach (['needsNotes', 'recommendedScope', 'comments'] as $k) {
                if (mb_strlen(self::trim($in[$k] ?? null)) > self::MAX_TEXT) {
                    throw new DomainRuleException('النص أطول من الحد المسموح.');
                }
            }
            $fields = [
                'contactStatus' => $in['contactStatus'], 'assessedAt' => $assessedAt ?: null, 'method' => $method ?: null,
                'needsNotes' => self::trim($in['needsNotes'] ?? null), 'recommendedScope' => self::trim($in['recommendedScope'] ?? null),
                'recommendedVisits' => $visits ? (int) $visits : null, 'comments' => self::trim($in['comments'] ?? null),
            ];
            $existing = ProviderAssessment::query()->where('invitation_id', $invitationId)->lockForUpdate()->first();
            $changed = [];
            if ($existing) {
                $old = $existing->toFront();
                $changed = array_values(array_filter(self::FIELDS, fn ($k) => ($old[$k] ?? null) !== $fields[$k]));
                if (! $changed) {
                    return $existing;
                }
            }
            $at = now();
            $a = $existing ?? new ProviderAssessment([
                'id' => 'PA-'.uid(), 'invitation_id' => $invitationId, 'quotation_round_id' => $r->id, 'case_id' => $r->case_id,
                'provider_id' => $inv->provider_id, 'created_at' => $at,
            ]);
            $a->forceFill([
                'contact_status' => $fields['contactStatus'], 'assessed_at' => $fields['assessedAt'], 'method' => $fields['method'],
                'needs_notes' => $fields['needsNotes'], 'recommended_scope' => $fields['recommendedScope'], 'recommended_visits' => $fields['recommendedVisits'],
                'comments' => $fields['comments'], 'updated_at' => $at,
                'history' => [...($existing?->history ?? []), [
                    'at' => iso($at), 'by' => $u->name, 'summary' => $existing ? 'تعديل: '.implode('، ', $changed) : 'إنشاء التقييم', 'contactStatus' => $fields['contactStatus'],
                ]],
            ])->save();
            Activity::emit($existing ? 'تم تحديث تقييم مقدم الخدمة' : 'تم تسجيل تقييم مقدم الخدمة', 'تقييم مقدم خدمة', $a->id, $u);

            return $a->fresh();
        });
    }

    /** Staff review: only assessments of providers that SUBMITTED a quotation in this round, for staff who may see the round. */
    public function forStaff(string $roundId, User $u): array
    {
        if (! $u->isStaff() || ! $this->perms->has($u, 'quotations.view')) {
            return [];
        }
        $r = QuotationRound::query()->with('invitations')->find($roundId);
        $c = $r ? RahaCase::query()->with('tasks')->find($r->case_id) : null;
        if (! $r || ! $c || ! $this->access->canAccessCase($c, $u)) {
            return [];
        }
        $submitted = $r->invitations->where('status', 'submitted')->keyBy('id');

        return ProviderAssessment::query()->where('quotation_round_id', $roundId)->orderBy('created_at')->get()
            ->filter(fn (ProviderAssessment $a) => $submitted->has($a->invitation_id))
            ->map(fn (ProviderAssessment $a) => $a->toFront() + ['providerName' => $submitted->get($a->invitation_id)->provider_name])
            ->values()->all();
    }

    private static function dateOk(mixed $v): bool
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $v, 'UTC');
        } catch (\Throwable) {
            return false;
        }

        return $d && $d->format('Y-m-d') === $v && $d->lte(now()->addDay());
    }
}
