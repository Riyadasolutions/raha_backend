<?php

namespace Modules\Quotations\Services;

use App\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\Requirements\RequirementsEngine;
use Modules\Catalog\Models\Provider;
use Modules\Quotations\Models\ProviderQuotation;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;

/**
 * Pure rules of front quotation_service.ts / quotation_money.ts (no storage, no permissions):
 * deadline, display status, provider invitation view, eligibility, money in integer halalas, quotation validation.
 */
class QuotationRules
{
    public const MAX_FILE = 5 * 1024 * 1024;

    public const TAX_TREATMENTS = ['exempt', 'zero', 'standard'];

    public const COMPLIANCE = ['compliant', 'noncompliant', 'clarification'];

    public static function fail(string $message, int $status = 422): never
    {
        throw new DomainRuleException($message, $status);
    }

    /** front isDeadlinePassed: Date.parse(deadline) <= now */
    public static function deadlinePassed(QuotationRound $r, ?Carbon $at = null): bool
    {
        return $r->deadline->lte($at ?? now());
    }

    public static function submitted(QuotationRound $r)
    {
        return $r->quotations->where('status', 'submitted')->values();
    }

    /** front roundStatus(): display status (invited / awaiting / partial derived from an open round). */
    public static function roundStatus(QuotationRound $r): string
    {
        if ($r->status !== 'open') {
            return $r->status;
        }
        if (self::submitted($r)->isNotEmpty()) {
            return 'partial';
        }

        return $r->invitations->contains(fn (QuotationInvitation $i) => $i->status !== 'invited') ? 'awaiting' : 'invited';
    }

    /** front invitationView(): what the provider sees for its invitation. */
    public static function invitationView(QuotationRound $r, QuotationInvitation $i, ?Carbon $at = null): string
    {
        if ($r->status === 'cancelled') {
            return 'cancelled';
        }
        if ($r->status === 'selected' && $i->status === 'submitted') {
            $selected = $r->quotations->firstWhere('id', $r->selected_quotation_id);

            return $selected?->invitation_id === $i->id ? 'selected' : 'not_selected';
        }
        if (in_array($i->status, ['invited', 'draft'], true) && ($r->status !== 'open' || self::deadlinePassed($r, $at))) {
            return 'expired';
        }

        return $i->status;
    }

    /** Provider assessment participation window (front invitationOpen). */
    public static function invitationOpen(QuotationRound $r, QuotationInvitation $i, ?RahaCase $c): bool
    {
        return $r->status === 'open' && ! self::deadlinePassed($r) && in_array($i->status, ['invited', 'draft'], true)
            && $c !== null && $c->status !== 'archived';
    }

    public static function latestEvaluation(ProviderQuotation $q): ?array
    {
        $e = $q->evaluations ?? [];

        return $e ? $e[count($e) - 1] : null;
    }

    /** Eligibility uses only configured data: active, supports the service, covers the city. No licence verification is assumed. */
    public static function eligibility(RahaCase|QuotationRound $c, Provider $p): array
    {
        $sid = RequirementsEngine::resolveService($c->service_id, $c->service)?->id;
        $p->loadMissing('services');
        if (! $p->city || $p->services->isEmpty()) {
            return ['eligible' => false, 'incomplete' => true, 'reason' => 'بيانات أهلية مقدم الخدمة غير مكتملة'];
        }
        if ($p->status !== 'active') {
            return ['eligible' => false, 'incomplete' => false, 'reason' => 'مقدم الخدمة غير نشط'];
        }
        if (! $sid || ! $p->offers($sid)) {
            return ['eligible' => false, 'incomplete' => false, 'reason' => 'لا يقدم هذه الخدمة'];
        }
        if ($p->city !== $c->city) {
            return ['eligible' => false, 'incomplete' => false, 'reason' => 'خارج نطاق التغطية الجغرافية'];
        }

        return ['eligible' => true, 'incomplete' => false, 'reason' => ''];
    }

    /** front quotation_money.lineAmounts (floats tolerated for drafts; validated quotations are integer halalas). */
    public static function lineAmounts(array $l): array
    {
        $gross = (int) round((float) ($l['quantity'] ?? 0) * (float) ($l['unitPrice'] ?? 0));
        $discount = min(max(0, (int) round((float) ($l['discountAmount'] ?? 0))), $gross);
        $net = $gross - $discount;
        $tax = (int) round($net * (float) ($l['taxRate'] ?? 0) / 100);

        return ['gross' => $gross, 'discount' => $discount, 'net' => $net, 'tax' => $tax, 'total' => $net + $tax];
    }

    public static function totals(array $lines): array
    {
        $t = ['subtotal' => 0, 'discountTotal' => 0, 'taxTotal' => 0, 'grandTotal' => 0];
        foreach ($lines as $l) {
            $a = self::lineAmounts($l);
            $t['subtotal'] += $a['gross'];
            $t['discountTotal'] += $a['discount'];
            $t['taxTotal'] += $a['tax'];
            $t['grandTotal'] += $a['total'];
        }

        return $t;
    }

    private static function isInt(mixed $n): bool
    {
        return is_int($n) || (is_float($n) && is_finite($n) && floor($n) === $n);
    }

    private static function num(mixed $n): float
    {
        return is_numeric($n) ? (float) $n : NAN;
    }

    /** front validateQuotation (submission only). */
    public static function validateQuotation(array $in): void
    {
        if (trim((string) ($in['description'] ?? '')) === '') {
            self::fail('يرجى إدخال وصف العرض.');
        }
        $lines = $in['lineItems'] ?? [];
        if (! $lines) {
            self::fail('أضف بندًا واحدًا على الأقل.');
        }
        foreach (array_values($lines) as $n => $l) {
            $k = $n + 1;
            if (trim((string) ($l['description'] ?? '')) === '' || ! ($l['unit'] ?? '')) {
                self::fail("البند {$k}: أدخل الوصف والوحدة.");
            }
            $qty = self::num($l['quantity'] ?? null);
            if (! ($qty > 0)) {
                self::fail("البند {$k}: الكمية يجب أن تكون أكبر من صفر.");
            }
            $price = $l['unitPrice'] ?? null;
            if (! self::isInt($price) || $price <= 0) {
                self::fail("البند {$k}: سعر الوحدة غير صالح.");
            }
            $disc = $l['discountAmount'] ?? null;
            if (! self::isInt($disc) || $disc < 0 || $disc > round($qty * $price)) {
                self::fail("البند {$k}: الخصم غير صالح.");
            }
        }
        $rate = self::num($in['taxRate'] ?? null);
        if (! ($rate >= 0 && $rate <= 100)) {
            self::fail('نسبة الضريبة غير صالحة.');
        }
        if (($in['taxTreatment'] ?? null) === 'standard' && ! ($rate > 0)) {
            self::fail('حدد نسبة الضريبة المطبقة.');
        }
        if (! (self::num($in['startWithinDays'] ?? null) >= 0) || trim((string) ($in['deliveryDuration'] ?? '')) === '') {
            self::fail('يرجى تحديد مدة بدء وتقديم الخدمة.');
        }
        $valid = (string) ($in['validUntil'] ?? '');
        $parsed = null;
        try {
            $parsed = $valid !== '' ? Carbon::parse($valid, 'UTC') : null;
        } catch (\Throwable) {
        }
        if (! $parsed || $parsed->lt(now()->utc()->startOfDay())) {
            self::fail('تاريخ انتهاء صلاحية العرض غير صالح.');
        }
        foreach ($in['attachments'] ?? [] as $a) {
            $size = self::num($a['size'] ?? null);
            if (! ($a['name'] ?? '') || ! ($size > 0) || $size > self::MAX_FILE) {
                self::fail('بيانات أحد المرفقات غير صالحة (الحد 5 ميجابايت).');
            }
        }
    }
}
