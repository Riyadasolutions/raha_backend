<?php

namespace Modules\Cases\Services\Requirements;

use Illuminate\Support\Carbon;
use Modules\Catalog\Models\Service;

/**
 * Minimal port of front requirements_service.ts used by the Cases module (submission, resubmission, review gates).
 * Documents are plain arrays: {docType?, category, visibility, at (ISO), reportDate?, review?, stale?}.
 * If Modules/Catalog ships its own RequirementsEngine later, this class can delegate to it.
 */
class RequirementsEngine
{
    public const RENTAL_CONTRACT_MSG = 'يجب إرفاق عقد الإيجار عند اختيار نوع السكن إيجار.';

    public static function resolveService(?string $serviceId, ?string $name = null): ?Service
    {
        if ($serviceId) {
            $id = raha("catalog.legacyServiceIds.$serviceId") ?? $serviceId;
            if ($s = Service::query()->find($id)) {
                return $s;
            }
        }
        if ($name) {
            return Service::query()->where('name', $name)->first()
                ?? (($legacy = raha('catalog.legacyServiceNames')[$name] ?? null) ? Service::query()->find($legacy) : null);
        }

        return null;
    }

    public static function docTypes(): array
    {
        return raha('document_rules.documentTypes', []);
    }

    public static function isoDaysAgo(int $n, ?Carbon $from = null): string
    {
        return ($from ?? now())->copy()->startOfDay()->subDays($n)->toDateString();
    }

    public static function reportDateError(?string $date, int $maxAgeDays, ?Carbon $today = null): string
    {
        if (! $date) {
            return 'يرجى إدخال تاريخ التقرير الطبي.';
        }
        if ($date > self::isoDaysAgo(0, $today)) {
            return 'لا يمكن أن يكون تاريخ التقرير الطبي في المستقبل.';
        }
        if ($date < self::isoDaysAgo($maxAgeDays, $today)) {
            return "يجب أن يكون تاريخ التقرير الطبي خلال آخر {$maxAgeDays} أيام.";
        }

        return '';
    }

    private static function missingMsg(string $docType, string $reason): string
    {
        if ($docType === 'medical_report') {
            return 'يرجى إرفاق التقرير الطبي لإكمال الطلب.';
        }
        if ($docType === 'rental_contract' && str_contains($reason, 'إيجار')) {
            return self::RENTAL_CONTRACT_MSG;
        }
        $label = self::docTypes()[$docType]['label'] ?? 'المستند';
        $r = preg_replace('/\.$/u', '', preg_replace('/^مطلوب /u', '', $reason));

        return "يرجى إرفاق {$label} {$r}.";
    }

    /** @return list<array{docType: string, label: string, section: string, required: bool, reason: string, maxAgeDays: ?int}> */
    private static function base(?Service $service, array $social): array
    {
        $out = [];
        $types = self::docTypes();
        $add = function (string $docType, bool $required, string $reason, ?string $label = null, ?int $maxAgeDays = null) use (&$out, $types) {
            foreach ($out as $x) {
                if ($x['docType'] === $docType) {
                    return;
                }
            }
            $out[] = ['docType' => $docType, 'label' => $label ?? $types[$docType]['label'] ?? $docType, 'section' => $types[$docType]['section'] ?? 'additional', 'required' => $required, 'reason' => $reason, 'maxAgeDays' => $maxAgeDays];
        };
        $req = $service?->requirements;
        if (! empty($req['medicalReport'])) {
            $days = (int) ($req['reportMaxAgeDays'] ?? raha('document_rules.DEFAULT_REPORT_MAX_AGE_DAYS', 5));
            $add('medical_report', true, "مطلوب للخدمة الصحية المحددة. يجب أن يكون بتاريخ خلال آخر {$days} أيام.", null, $days);
        }
        if (! empty($req['prescription'])) {
            $add('prescription', true, 'مطلوبة لخدمة الأدوية المحددة.');
        }
        foreach ($req['extraDocs'] ?? [] as $d) {
            if (! empty($d['active'])) {
                $add("svc:{$d['id']}", (bool) $d['required'], ($d['explanation'] ?? '') ?: 'مطلوب للخدمة المحددة.', $d['label']);
            }
        }
        foreach (raha('document_rules.socialRules', []) as $r) {
            if (empty($r['active'])) {
                continue;
            }
            $v = $social[$r['field']] ?? '';
            $match = $r['operator'] === 'equals' ? $v === $r['value'] : in_array($v, (array) $r['value'], true);
            if ($match) {
                $add($r['docType'], (bool) $r['required'], $r['reason']);
            }
        }

        return $out;
    }

    /**
     * @param  list<array>  $docs
     * @param  string[]|null  $onlyTypes
     * @return list<array{docType: string, label: string, section: string, required: bool, reason: string, maxAgeDays: ?int, satisfied: bool, validationErrors: string[]}>
     */
    public static function evaluate(?Service $service, array $social, array $docs, ?Carbon $today = null, ?array $onlyTypes = null, ?string $dateCheckAfter = null): array
    {
        $results = [];
        foreach (self::base($service, $social) as $r) {
            if ($onlyTypes !== null && ! in_array($r['docType'], $onlyTypes, true)) {
                continue;
            }
            $mine = array_values(array_filter($docs, fn ($d) => ($d['docType'] ?? null) === $r['docType']));
            $usable = array_values(array_filter($mine, fn ($d) => empty($d['stale'])));
            $errs = [];
            if (! $usable) {
                if ($r['required']) {
                    $errs[] = $mine ? 'تم تحديث الصفحة — يرجى إعادة اختيار الملف.' : self::missingMsg($r['docType'], $r['reason']);
                }
            } elseif ($r['maxAgeDays'] !== null && (! $dateCheckAfter || ($usable[0]['at'] ?? '') > $dateCheckAfter)) {
                $e = self::reportDateError($usable[0]['reportDate'] ?? null, $r['maxAgeDays'], $today);
                if ($e) {
                    $errs[] = $e;
                }
            }
            $results[] = $r + ['satisfied' => (bool) $usable && ! $errs, 'validationErrors' => $errs];
        }

        return $results;
    }

    public static function missingRequired(array $results): array
    {
        return array_values(array_filter($results, fn ($r) => $r['required'] && ! $r['satisfied']));
    }

    /** Requirement checklist snapshot stored on the request (front snapshot()). */
    public static function snapshot(array $results): array
    {
        return array_map(fn ($r) => ['docType' => $r['docType'], 'label' => $r['label'], 'required' => $r['required'], 'reason' => $r['reason']], $results);
    }

    /** Housing-driven attachment rule (front housingDocumentErrors). */
    public static function housingDocumentErrors(array $social, array $docs, ?Carbon $today = null): array
    {
        $byLabel = [];
        foreach (self::docTypes() as $t) {
            $byLabel[$t['label']] = $t['id'];
        }
        $normalized = [];
        foreach ($docs as $d) {
            if (($d['visibility'] ?? 'both') === 'internal') {
                continue;
            }
            $d['docType'] = $d['docType'] ?? ($byLabel[$d['category'] ?? ''] ?? null);
            $normalized[] = $d;
        }
        $out = [];
        foreach (self::evaluate(null, $social, $normalized, $today, ['rental_contract']) as $r) {
            if ($r['required'] && ! $r['satisfied']) {
                $out[] = $r['validationErrors'][0] ?? self::RENTAL_CONTRACT_MSG;
            }
        }

        return $out;
    }

    public static function fileError(string $name, string $mime, int $size): string
    {
        $rules = raha('document_rules.uploadRules');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, $rules['extensions'], true) || ($mime && ! in_array($mime, $rules['mimes'], true))) {
            return 'نوع الملف غير مدعوم. يرجى اختيار PDF أو صورة بصيغة JPG أو PNG.';
        }
        if ($size > $rules['maxBytes']) {
            return "حجم الملف أكبر من الحد المسموح ({$rules['maxLabel']}).";
        }

        return '';
    }
}
