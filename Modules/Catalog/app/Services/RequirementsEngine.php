<?php

namespace Modules\Catalog\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\Catalog\Models\Service;

/**
 * Port of front requirements_service.ts + mocks/document_rules.ts: combines the service's configured requirements,
 * the social-condition rules and the report validity window into one checklist. Config: raha('document_rules.*').
 *
 * A requirement result is an array:
 *   ['docType', 'label', 'section' (medical|social|additional), 'required', 'reason', 'satisfied', 'validationErrors' => string[], 'maxAgeDays'?]
 * A document may be a CaseDocument model or a front-shaped array: ['docType', 'reportDate', 'at', 'visibility', 'category', 'stale'?].
 */
class RequirementsEngine
{
    public const RENTAL_CONTRACT_MSG = 'يجب إرفاق عقد الإيجار عند اختيار نوع السكن إيجار.';

    public const STALE_FILE_MSG = 'تم تحديث الصفحة — يرجى إعادة اختيار الملف.';

    public const FILE_TYPE_MSG = 'نوع الملف غير مدعوم. يرجى اختيار PDF أو صورة بصيغة JPG أو PNG.';

    /** @return array<string, array{id: string, label: string, section: string, providerVisible: bool, help: string}> */
    public static function documentTypes(): array
    {
        return raha('document_rules.documentTypes', []);
    }

    public static function socialRules(): array
    {
        return raha('document_rules.socialRules', []);
    }

    public static function uploadRules(): array
    {
        return raha('document_rules.uploadRules', []);
    }

    /** Default config for a service without requirements (front noRequirements). */
    public static function noRequirements(): array
    {
        return raha('document_rules.noRequirements', ['medicalReport' => false, 'prescription' => false, 'needDescription' => false, 'reportMaxAgeDays' => 5, 'extraDocs' => []]);
    }

    public static function docLabel(?string $id, string $fallback = 'مستند'): string
    {
        return ($id !== null ? (self::documentTypes()[$id]['label'] ?? null) : null) ?: $fallback;
    }

    /** YYYY-MM-DD of (today - n days) in the app timezone (front isoDaysAgo). */
    public static function isoDaysAgo(int $n, ?CarbonInterface $from = null): string
    {
        return ($from ?? now())->copy()->startOfDay()->subDays($n)->format('Y-m-d');
    }

    /** '' when valid. Dates are compared as YYYY-MM-DD strings. */
    public static function reportDateError(?string $date, int $maxAgeDays, ?CarbonInterface $today = null): string
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

    /** '' when the file is acceptable (extension + optional MIME + size). */
    public static function fileError(string $name, ?string $mime, int $size): string
    {
        $rules = self::uploadRules();
        $ext = mb_strtolower(str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : '');
        if (! in_array($ext, $rules['extensions'] ?? [], true) || ($mime && ! in_array($mime, $rules['mimes'] ?? [], true))) {
            return self::FILE_TYPE_MSG;
        }
        if ($size > (int) ($rules['maxBytes'] ?? 0)) {
            return 'حجم الملف أكبر من الحد المسموح ('.($rules['maxLabel'] ?? '').').';
        }

        return '';
    }

    public static function missingMessage(string $docType, string $reason): string
    {
        if ($docType === 'medical_report') {
            return 'يرجى إرفاق التقرير الطبي لإكمال الطلب.';
        }
        if ($docType === 'rental_contract' && str_contains($reason, 'إيجار')) {
            return self::RENTAL_CONTRACT_MSG;
        }
        $label = self::documentTypes()[$docType]['label'] ?? 'المستند';
        $tail = preg_replace(['/^مطلوب /u', '/\.$/u'], '', $reason);

        return "يرجى إرفاق {$label} {$tail}.";
    }

    /** Requirement config of a Service model / front ServiceItem array / config array; null when none. */
    public static function configOf(Service|array|null $service): ?array
    {
        if ($service instanceof Service) {
            return $service->requirements;
        }
        if (is_array($service)) {
            return array_key_exists('requirements', $service) ? $service['requirements'] : (array_key_exists('medicalReport', $service) ? $service : null);
        }

        return null;
    }

    /** Applicable requirements without document evaluation. */
    public static function base(Service|array|null $service, array $social): array
    {
        $out = [];
        $types = self::documentTypes();
        $add = function (string $docType, bool $required, string $reason, ?string $label = null, ?int $maxAgeDays = null) use (&$out, $types) {
            if (isset($out[$docType])) {
                return;
            }
            $row = ['docType' => $docType, 'label' => $label ?? ($types[$docType]['label'] ?? $docType), 'section' => $types[$docType]['section'] ?? 'additional', 'required' => $required, 'reason' => $reason];
            if ($maxAgeDays !== null) {
                $row['maxAgeDays'] = $maxAgeDays;
            }
            $out[$docType] = $row;
        };
        $req = self::configOf($service);
        if ($req) {
            if (! empty($req['medicalReport'])) {
                $days = (int) ($req['reportMaxAgeDays'] ?? raha('document_rules.DEFAULT_REPORT_MAX_AGE_DAYS', 5));
                $add('medical_report', true, "مطلوب للخدمة الصحية المحددة. يجب أن يكون بتاريخ خلال آخر {$days} أيام.", null, $days);
            }
            if (! empty($req['prescription'])) {
                $add('prescription', true, 'مطلوبة لخدمة الأدوية المحددة.');
            }
            foreach ($req['extraDocs'] ?? [] as $d) {
                if (! empty($d['active'])) {
                    $add('svc:'.$d['id'], (bool) ($d['required'] ?? false), ($d['explanation'] ?? '') ?: 'مطلوب للخدمة المحددة.', $d['label'] ?? null);
                }
            }
        }
        foreach (self::socialRules() as $r) {
            if (empty($r['active'])) {
                continue;
            }
            $v = (string) ($social[$r['field']] ?? '');
            $hit = $r['operator'] === 'equals' ? $v === $r['value'] : in_array($v, (array) $r['value'], true);
            if ($hit) {
                $add($r['docType'], (bool) $r['required'], $r['reason']);
            }
        }

        return array_values($out);
    }

    /**
     * @param  iterable<Model|array>  $docs
     * @param  array{onlyTypes?: string[]|null, dateCheckAfter?: string|null}  $opts  dateCheckAfter = ISO timestamp; only documents added after it get the report date check.
     * @return array<int, array>
     */
    public static function evaluate(Service|array|null $service, array $social, iterable $docs, ?CarbonInterface $today = null, array $opts = []): array
    {
        $docs = self::normalizeDocs($docs);
        $only = $opts['onlyTypes'] ?? null;
        $after = $opts['dateCheckAfter'] ?? null;
        $results = [];
        foreach (self::base($service, $social) as $r) {
            if ($only !== null && ! in_array($r['docType'], $only, true)) {
                continue;
            }
            $mine = array_values(array_filter($docs, fn ($d) => $d['docType'] === $r['docType']));
            $usable = array_values(array_filter($mine, fn ($d) => empty($d['stale'])));
            $errs = [];
            if (! $usable) {
                if ($r['required']) {
                    $errs[] = $mine ? self::STALE_FILE_MSG : self::missingMessage($r['docType'], $r['reason']);
                }
            } elseif (isset($r['maxAgeDays']) && (! $after || (string) ($usable[0]['at'] ?? '') > $after)) {
                $e = self::reportDateError($usable[0]['reportDate'] ?? null, (int) $r['maxAgeDays'], $today);
                if ($e !== '') {
                    $errs[] = $e;
                }
            }
            $results[] = $r + ['satisfied' => (bool) $usable && ! $errs, 'validationErrors' => $errs];
        }

        return $results;
    }

    /** Alias with the front name. */
    public static function evaluateRequirements(Service|array|null $service, array $social, iterable $docs, ?CarbonInterface $today = null, array $opts = []): array
    {
        return self::evaluate($service, $social, $docs, $today, $opts);
    }

    /** Housing-driven attachment rule (rental contract), evaluated by the same engine. Internal documents are ignored. */
    public static function housingDocumentErrors(array $social, iterable $docs, ?CarbonInterface $today = null): array
    {
        $byLabel = [];
        foreach (self::documentTypes() as $t) {
            $byLabel[$t['label']] ??= $t['id'];
        }
        $normalized = [];
        foreach (self::normalizeDocs($docs) as $d) {
            if (($d['visibility'] ?? null) === 'internal') {
                continue;
            }
            $d['docType'] ??= $byLabel[$d['category'] ?? ''] ?? null;
            $normalized[] = $d;
        }
        $out = [];
        foreach (self::evaluate(null, $social, $normalized, $today, ['onlyTypes' => ['rental_contract']]) as $r) {
            if ($r['required'] && ! $r['satisfied']) {
                $out[] = $r['validationErrors'][0] ?? self::RENTAL_CONTRACT_MSG;
            }
        }

        return $out;
    }

    public static function missingRequired(array $results): array
    {
        return array_values(array_filter($results, fn ($r) => $r['required'] && ! $r['satisfied']));
    }

    /** @return array{required: int, complete: int, missing: int} */
    public static function summary(array $results): array
    {
        $req = array_filter($results, fn ($r) => $r['required']);
        $done = count(array_filter($req, fn ($r) => $r['satisfied']));

        return ['required' => count($req), 'complete' => $done, 'missing' => count($req) - $done];
    }

    /** Documents whose requirement no longer applies (conditions changed). Returns the normalized document arrays. */
    public static function notRequiredDocs(array $results, iterable $docs): array
    {
        $types = array_column($results, 'docType');

        return array_values(array_filter(self::normalizeDocs($docs), fn ($d) => $d['docType'] && $d['docType'] !== 'additional' && ! in_array($d['docType'], $types, true)));
    }

    /** RequirementSnapshot[] stored on the case at submission. */
    public static function snapshot(array $results): array
    {
        return array_map(fn ($r) => ['docType' => $r['docType'], 'label' => $r['label'], 'required' => $r['required'], 'reason' => $r['reason']], array_values($results));
    }

    /** Social document types that did not apply ("غير مطلوب" in the checklist). */
    public static function inactiveSocialTypes(array $results): array
    {
        $applied = array_column($results, 'docType');
        $types = array_unique(array_column(array_filter(self::socialRules(), fn ($r) => ! empty($r['active'])), 'docType'));

        return array_values(array_filter($types, fn ($t) => ! in_array($t, $applied, true)));
    }

    /** @return array<int, array{docType: ?string, reportDate: ?string, at: string, visibility: ?string, category: ?string, stale: bool}> */
    public static function normalizeDocs(iterable $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            if ($d instanceof Model) {
                $out[] = ['id' => $d->getAttribute('id'), 'docType' => $d->getAttribute('doc_type'), 'reportDate' => $d->getAttribute('report_date'),
                    'at' => (string) iso($d->getAttribute('at')), 'visibility' => $d->getAttribute('visibility'), 'category' => $d->getAttribute('category'), 'stale' => false];
            } else {
                $d = (array) $d;
                $out[] = ['id' => $d['id'] ?? null, 'docType' => $d['docType'] ?? null, 'reportDate' => $d['reportDate'] ?? null, 'at' => (string) ($d['at'] ?? ''),
                    'visibility' => $d['visibility'] ?? null, 'category' => $d['category'] ?? null, 'stale' => ! empty($d['stale'])];
            }
        }

        return $out;
    }
}
