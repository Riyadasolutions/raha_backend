<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\Requirements\RequirementsEngine;

/** Decision-form validation and document gates (port of front review_service.ts). Messages are verbatim. */
class ReviewValidator
{
    public const CORRECTION_FIELD_OPTIONS = ['وصف الحالة', 'بيانات المستفيد', 'المعلومات الطبية', 'البيانات الاجتماعية'];

    private static function fail(string $msg): never
    {
        throw new DomainRuleException($msg);
    }

    private static function required(mixed $v, string $label): void
    {
        if (! is_string($v) || trim($v) === '') {
            self::fail("يرجى إدخال {$label}.");
        }
    }

    /** CaseDocument models → plain arrays for the requirements engine. */
    public static function docArrays(iterable $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            $out[] = $d instanceof CaseDocument ? [
                'id' => $d->id, 'docType' => $d->doc_type, 'category' => $d->category, 'visibility' => $d->visibility, 'at' => iso($d->at),
                'reportDate' => $d->report_date, 'review' => $d->review,
            ] : $d;
        }

        return $out;
    }

    public function validateDecision(array $a, RahaCase $c, array $p): void
    {
        $form = $a['form'] ?? null;
        if ($form === 'confirm' && empty($p['confirmed'])) {
            self::fail('يرجى تأكيد مراجعة البيانات والمستندات.');
        }
        $f = is_array($p['fields'] ?? null) ? $p['fields'] : [];
        if ($form === 'medical') {
            self::required($f['opinion'] ?? null, 'الرأي الطبي');
            self::required($f['recommendation'] ?? null, 'التوصية الطبية');
            $d = collect(raha('workflow.medicalDecisions', []))->firstWhere('value', $f['decision'] ?? null);
            if (! $d || $d['outcome'] !== 'advance') {
                self::fail('اختر قرارًا طبيًا متوافقًا مع الإجراء.');
            }
            if (! empty($d['quotation'])) {
                self::required($f['quotationContext'] ?? null, 'مبرر طلب عروض الأسعار');
            }
        }
        if ($form === 'social') {
            self::required($f['summary'] ?? null, 'ملخص الحالة الاجتماعية');
            self::required($f['assessment'] ?? null, 'التقييم الاجتماعي');
            self::required($f['justification'] ?? null, 'مبررات التوصية');
            if (! in_array($f['recommendation'] ?? '', raha('workflow.socialRecommendations', []), true)) {
                self::fail('اختر التوصية الاجتماعية.');
            }
            if (($f['recommendation'] ?? '') === raha('workflow.PARTIAL')) {
                $pct = trim((string) ($f['percentage'] ?? ''));
                if ($pct === '' || ! is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100) {
                    self::fail('نسبة المساهمة المقترحة يجب أن تكون بين 0 و100%.');
                }
            }
        }
        if ($form === 'care' && empty($p['confirmed'])) {
            self::fail('يرجى تأكيد مراجعة ملخص القرارات.');
        }
        $kind = $a['kind'];
        if ($kind !== 'advance') {
            if ($kind === 'send_back') {
                if (! in_array($p['reason'] ?? '', raha('workflow.sendBackReasons', []), true)) {
                    self::fail('اختر سبب الإجراء.');
                }
                if (($p['reason'] ?? '') === raha('workflow.OTHER')) {
                    self::required($p['notes'] ?? null, 'شرح السبب');
                }
            } else {
                self::required($p['reason'] ?? null, 'السبب');
            }
            if ($kind === 'return') {
                if (empty($p['showToBeneficiary'])) {
                    self::fail('يجب إظهار تعليمات التصحيح للمستفيد.');
                }
                $fields = (array) ($p['correctionFields'] ?? []);
                $docIds = (array) ($p['docIds'] ?? []);
                if (! $fields && ! $docIds) {
                    self::fail('حدد بيانات أو مستندات تحتاج إلى تصحيح.');
                }
                $docs = $c->documents;
                $badDoc = collect($docIds)->contains(fn ($id) => ! $docs->contains(fn (CaseDocument $d) => $d->id === $id && $d->visibility !== 'internal'));
                if (array_diff($fields, self::CORRECTION_FIELD_OPTIONS) || $badDoc) {
                    self::fail('وجهة التصحيح غير صالحة.');
                }
            }
            if ($kind === 'send_back') {
                self::required($p['notes'] ?? null, 'مبرر الإعادة للمراجعة');
                if (! in_array($p['targetStage'] ?? '', ['medical_review', 'social_review'], true)) {
                    self::fail('حدد مرحلة المراجعة الداخلية.');
                }
                if (! empty($p['publicNote'])) {
                    self::fail('الإعادة للمراجعة الداخلية لا تظهر للمستفيد.');
                }
            }
        }
        if ($kind === 'advance' && in_array($c->status, ['new', 'medical_review'], true)) {
            $this->assertDocuments($c, self::docArrays($c->documents));
        }
    }

    /** Required documents present, report dates valid, no flagged documents (front assertDocuments). */
    public function assertDocuments(RahaCase $c, array $docs, ?string $correctionAt = null, ?array $social = null): void
    {
        $social ??= $c->social ?? [];
        $svc = RequirementsEngine::resolveService($c->service_id, $c->service);
        $snapshots = $c->requirements;
        $normalized = [];
        foreach ($docs as $d) {
            if (($d['visibility'] ?? 'both') === 'internal') {
                continue;
            }
            if (empty($d['docType'])) {
                $d['docType'] = collect($snapshots ?? [])->firstWhere('label', $d['category'] ?? null)['docType'] ?? null;
            }
            $normalized[] = $d;
        }
        $onlyTypes = $snapshots !== null ? null : [];
        $c->loadMissing('tasks');
        $dateCheckAfter = $correctionAt ?? ($c->tasks->isNotEmpty() ? iso($c->created_at) : null);
        $missing = RequirementsEngine::missingRequired(RequirementsEngine::evaluate($svc, $social, $normalized, null, $onlyTypes, $dateCheckAfter));
        $have = array_column($normalized, 'docType');
        $missingSnapshots = array_values(array_filter($snapshots ?? [], fn ($r) => ! empty($r['required']) && ! in_array($r['docType'], $have, true)));
        $labels = array_values(array_unique(array_merge(array_column($missing, 'label'), array_column($missingSnapshots, 'label'))));
        if ($labels) {
            self::fail('يرجى استكمال المستندات المطلوبة: '.implode('، ', $labels));
        }
        foreach ($normalized as $d) {
            if (! empty($d['review']) && ($d['review']['status'] ?? null) !== 'accepted') {
                self::fail('توجد مستندات تحتاج إلى استبدال أو تصحيح.');
            }
        }
    }
}
