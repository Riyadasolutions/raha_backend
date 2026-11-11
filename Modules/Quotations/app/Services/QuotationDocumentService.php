<?php

namespace Modules\Quotations\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Catalog\Models\Provider;
use Modules\Catalog\Services\ProviderBranding;
use Modules\Quotations\Models\ProviderQuotation;
use Modules\Quotations\Models\QuotationRound;

/**
 * Port of front quotation_document_service.ts (Task 13): the read-only document model of an EXISTING quotation.
 * The PDF / print HTML is rendered client-side from this model. Never writes anything (no status, number, audit).
 * Access: providers → own quotations (drafts allowed); staff → quotations.view + submitted + request access.
 */
class QuotationDocumentService
{
    public const NO_ACCESS = 'لا تملك صلاحية عرض عرض السعر هذا.';

    public function __construct(private PermissionResolver $perms, private CaseAccess $access) {}

    /** @return array{0: QuotationRound, 1: ProviderQuotation} */
    private function locate(string $quotationId, User $u): array
    {
        $q = ProviderQuotation::query()->find($quotationId);
        $round = $q ? QuotationRound::query()->with('invitations')->find($q->quotation_round_id) : null;
        if (! $q || ! $round) {
            throw DomainRuleException::forbidden(self::NO_ACCESS);
        }
        if ($u->isProvider()) {
            $inv = $round->invitations->firstWhere('id', $q->invitation_id);
            if ($u->provider_id && $this->perms->has($u, 'provider.quotations.viewOwn') && $q->provider_id === $u->provider_id && $inv?->provider_id === $u->provider_id) {
                return [$round, $q];
            }
        } elseif ($u->isStaff() && $this->perms->has($u, 'quotations.view') && $q->status === 'submitted') {
            $c = RahaCase::query()->with('tasks')->find($round->case_id);
            if ($c && $this->access->canAccessCase($c, $u)) {
                return [$round, $q];
            }
        }
        throw DomainRuleException::forbidden(self::NO_ACCESS);
    }

    private static function dateOf(mixed $v): string
    {
        return $v ? substr((string) iso($v), 0, 10) : '';
    }

    /** front buildModel(): stored data only; branding snapshot, else the current provider profile (and says so). */
    public static function buildModel(QuotationRound $round, ProviderQuotation $q, ?Provider $current): array
    {
        $b = $q->branding ?: ProviderBranding::snapshot($current, (string) iso($q->updated_at));
        $items = [];
        foreach (array_values($q->line_items ?? []) as $i => $l) {
            $a = QuotationRules::lineAmounts($l);
            $items[] = ['n' => $i + 1, 'description' => (string) ($l['description'] ?? ''), 'quantity' => $l['quantity'] ?? 0, 'unit' => (string) ($l['unit'] ?? ''),
                'unitPrice' => $l['unitPrice'] ?? 0, 'discount' => $a['discount'], 'net' => $a['net']];
        }
        $problems = [];
        if (! $items) {
            $problems[] = 'لا توجد بنود تسعير في عرض السعر.';
        }
        if (! ($q->grand_total > 0)) {
            $problems[] = 'إجمالي عرض السعر غير مكتمل.';
        }
        if (trim((string) ($b['name'] ?? '')) === '') {
            $problems[] = 'بيانات مقدم الخدمة غير متوفرة.';
        }
        if (trim((string) $q->description) === '') {
            $problems[] = 'وصف العرض غير مكتمل.';
        }
        $provider = ['name' => $b['name'] ?? $q->provider_name];
        if (! empty($b['logo']) && ProviderBranding::isSafeLogo($b['logo'])) {
            $provider['logo'] = $b['logo'];
        }
        $provider += ['phone' => (string) ($b['phone'] ?? ''), 'email' => (string) ($b['email'] ?? ''), 'address' => (string) ($b['address'] ?? ''),
            'registry' => (string) ($b['registry'] ?? ''), 'license' => (string) ($b['license'] ?? '')];

        return [
            'number' => $q->number, 'status' => $q->status, 'issueDate' => self::dateOf($q->submitted_at ?? $q->updated_at), 'validUntil' => (string) $q->valid_until,
            'version' => $q->version, 'requestRef' => $round->request_number, 'roundRef' => $round->id,
            'service' => $round->service.($round->subservice ? " — {$round->subservice}" : ''), 'description' => (string) $q->description,
            'provider' => $provider, 'brandingSource' => $q->branding ? 'snapshot' : 'current_profile', 'items' => $items,
            'summary' => ['subtotal' => $q->subtotal, 'discountTotal' => $q->discount_total, 'taxTreatment' => $q->tax_treatment, 'taxRate' => $q->tax_rate,
                'taxTotal' => $q->tax_total, 'grandTotal' => $q->grand_total, 'currency' => 'SAR'],
            'startWithinDays' => $q->start_within_days, 'deliveryDuration' => (string) $q->delivery_duration, 'notes' => (string) $q->notes, 'problems' => $problems,
        ];
    }

    /** front prepare() without the HTML: {model, filename, title, lang}. Read-only. */
    public function prepare(string $quotationId, User $u, string $lang = 'ar'): array
    {
        [$round, $q] = $this->locate($quotationId, $u);
        $model = self::buildModel($round, $q, Provider::query()->find($q->provider_id));
        $filename = 'RAHA_Quotation_'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $q->number).'.pdf';

        return ['model' => $model, 'filename' => $filename, 'title' => substr($filename, 0, -4), 'lang' => $lang === 'en' ? 'en' : 'ar'];
    }
}
