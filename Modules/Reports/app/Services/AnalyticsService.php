<?php

namespace Modules\Reports\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Beneficiaries\Models\Beneficiary;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;
use Modules\Catalog\Models\Service;

/**
 * Port of front analytics_service.ts (Task 08): aggregate-only analytics over the authorized report dataset.
 * Date bases (each metric uses exactly one):
 *  - requests, active beneficiaries, services ranking, nationality, age → request submission date (UTC day)
 *  - completed services → date of the activity that moved the request to `completed`; undated ones are disclosed, never counted
 *  - registered beneficiaries in period → beneficiary record registration date (beneficiaries.created_at)
 */
class AnalyticsService
{
    public const UNKNOWN = 'غير محدد';

    public const AGE_GROUPS = ['0–17', '18–39', '40–59', '60+', self::UNKNOWN];

    public const TOP_N = 10;

    private const COMPLETED = ['completed', 'finance_review', 'archived'];

    private const MAX_AGE = 120;

    public function __construct(private PermissionResolver $perms, private ReportService $reports) {}

    /** Staff with report access AND full case/report scope; aggregate population figures must not leak through narrower scopes. */
    public function canView(?User $u): bool
    {
        return $this->reports->canRead($u) && $this->perms->has($u, 'cases.view') && $this->perms->scopeOf($u, 'cases.view') === 'ALL' && $this->perms->scopeOf($u, 'reports.view') === 'ALL';
    }

    public static function validateRange(array $r): ?string
    {
        $bad = fn ($s) => ! empty($s) && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || ! checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4)));
        if ($bad($r['from'] ?? '') || $bad($r['to'] ?? '')) {
            return 'تاريخ غير صالح.';
        }
        if (! empty($r['from']) && ! empty($r['to']) && $r['from'] > $r['to']) {
            return 'تاريخ البداية يجب أن يسبق تاريخ النهاية';
        }

        return null;
    }

    /** Folds gendered/spelling variants of the same stored nationality without inferring it from anything else. */
    public static function normalizeNationality(?string $v): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[أإآ]/u', 'ا', (string) $v)));
        if ($s === '' || in_array(mb_strtolower($s), ['—', '-', 'غير محدد', 'غير معروف', 'unknown', 'n/a'], true)) {
            return self::UNKNOWN;
        }

        return preg_replace('/ية$/u', 'ي', $s);
    }

    /** Whole-year age at the reference date; null for missing/invalid dates, future births and implausible ages. */
    public static function ageAt(?string $birth, Carbon $ref): ?int
    {
        $ms = $birth && preg_match('/^\d{4}-\d{2}-\d{2}/', $birth) ? Lifecycle::ms($birth) : null;
        if ($ms === null) {
            return null;
        }
        $b = Carbon::createFromTimestampMs($ms, 'UTC');
        if ($b->getTimestampMs() > $ref->getTimestampMs()) {
            return null;
        }
        $age = $ref->year - $b->year;
        if ($ref->month < $b->month || ($ref->month === $b->month && $ref->day < $b->day)) {
            $age--;
        }

        return $age >= 0 && $age <= self::MAX_AGE ? $age : null;
    }

    public static function ageGroup(?int $age): string
    {
        return match (true) {
            $age === null => self::UNKNOWN, $age <= 17 => '0–17', $age <= 39 => '18–39', $age <= 59 => '40–59', default => '60+',
        };
    }

    private static function pct(int $n, int $total): float|int
    {
        return $total ? Lifecycle::num(round(($n / $total) * 1000) / 10) : 0;
    }

    private static function inRange(mixed $iso, array $r): bool
    {
        if (empty($r['from']) && empty($r['to'])) {
            return true;
        }
        $day = ReportService::day($iso);

        return $day !== null && (empty($r['from']) || $day >= $r['from']) && (empty($r['to']) || $day <= $r['to']);
    }

    private static function completionAt(RahaCase $c): ?int
    {
        if (! in_array($c->status, self::COMPLETED, true)) {
            return null;
        }

        return $c->activities->filter(fn (CaseActivity $a) => $a->at && ($a->action === 'complete_service' || ($a->to_stage === 'completed' && $a->from_stage !== 'completed')))
            ->map(fn (CaseActivity $a) => $a->at->getTimestampMs())->max();
    }

    private static function compare(string $a, string $b): int
    {
        static $collator = null;
        $collator ??= class_exists(\Collator::class) ? new \Collator('ar') : false;

        return $collator ? $collator->compare($a, $b) : strcmp($a, $b);
    }

    private static function tally(array $labels, ?array $order = null): array
    {
        $m = array_count_values($labels);
        $keys = $order ?? array_keys($m);
        $rows = array_map(fn ($l) => ['key' => (string) $l, 'label' => (string) $l, 'count' => $m[$l] ?? 0, 'percent' => self::pct($m[$l] ?? 0, count($labels))], $keys);
        if (! $order) {
            usort($rows, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: ($a['label'] === self::UNKNOWN ? 1 : ($b['label'] === self::UNKNOWN ? -1 : self::compare($a['label'], $b['label']))));
        }

        return $rows;
    }

    /** Catalog id for a request's service (current id, legacy id, or legacy/current name). */
    private static function serviceKey(RahaCase $c, Collection $services): ?string
    {
        $id = $c->service_id;
        if ($id && $services->has($id)) {
            return $id;
        }
        if ($id && ($legacy = raha("catalog.legacyServiceIds.$id"))) {
            return $legacy;
        }

        return $services->search(fn ($name) => $name === $c->service) ?: raha('catalog.legacyServiceNames')[$c->service] ?? null;
    }

    /**
     * @param  Collection<int, RahaCase>  $cases
     * @param  list<array{id: string, createdAt?: ?string, nationality?: ?string, birthDate?: ?string}>  $registered
     */
    public static function compute(Collection $cases, array $registered, array $range, ?int $now = null): array
    {
        if ($error = self::validateRange($range)) {
            throw new DomainRuleException($error);
        }
        $range = ['from' => $range['from'] ?? '', 'to' => $range['to'] ?? ''];
        $now ??= (int) Carbon::now()->getTimestampMs();
        $unique = $cases->unique('id')->values();
        $period = $unique->filter(fn (RahaCase $c) => self::inRange($c->created_at, $range))->values();
        $ref = $range['to'] ? Carbon::parse($range['to'].'T23:59:59.999Z')->utc() : Carbon::createFromTimestampMs($now, 'UTC');
        $byId = collect($registered)->keyBy('id');
        $activeIds = $period->pluck('beneficiary_id')->unique()->values()->all();
        $completedAll = $unique->filter(fn ($c) => in_array($c->status, self::COMPLETED, true));
        $completedDated = $completedAll->filter(fn ($c) => ($at = self::completionAt($c)) !== null && self::inRange($at, $range));
        $known = array_filter($registered, fn ($r) => Lifecycle::ms($r['createdAt'] ?? null) !== null);
        $catalog = Service::query()->pluck('name', 'id');
        $groups = [];
        foreach ($period as $c) {
            $key = self::serviceKey($c, $catalog) ?? 'name:'.$c->service;
            $t = Lifecycle::ms($c->created_at) ?? 0;
            if (! isset($groups[$key])) {
                $groups[$key] = ['label' => $c->service, 'at' => $t, 'count' => 1];
            } else {
                $groups[$key]['count']++;
                if ($t >= $groups[$key]['at']) {
                    $groups[$key]['at'] = $t;
                    $groups[$key]['label'] = $c->service;
                }
            }
        }
        $services = [];
        foreach ($groups as $key => $g) {
            $services[] = ['key' => (string) $key, 'label' => $g['label'], 'count' => $g['count'], 'percent' => self::pct($g['count'], $period->count())];
        }
        usort($services, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: self::compare($a['label'], $b['label']));

        return [
            'range' => $range, 'referenceDate' => $ref->format('Y-m-d'),
            'registeredTotal' => count($registered), 'registeredInPeriod' => $range['from'] || $range['to'] ? count(array_filter($known, fn ($r) => self::inRange($r['createdAt'], $range))) : null,
            'registrationDatesKnown' => count($known), 'registrationDatesUnknown' => count($registered) - count($known),
            'activeBeneficiaries' => count($activeIds), 'totalRequests' => $period->count(), 'completedServices' => $completedDated->count(),
            'completedWithoutDate' => $completedAll->filter(fn ($c) => self::completionAt($c) === null)->count(),
            'topServices' => array_slice($services, 0, self::TOP_N), 'services' => $services,
            'nationality' => self::tally(array_map(fn ($id) => self::normalizeNationality($byId[$id]['nationality'] ?? null), $activeIds)),
            'age' => self::tally(array_map(fn ($id) => self::ageGroup(self::ageAt($byId[$id]['birthDate'] ?? null, $ref)), $activeIds), self::AGE_GROUPS),
        ];
    }

    /** Beneficiary records ∪ request owners ∪ portal accounts (unique ids); only aggregate fields are read. */
    public static function registered(): array
    {
        $records = Beneficiary::query()->get(['id', 'created_at', 'nationality', 'birth_date'])->keyBy('id');
        $ids = $records->keys()->merge(RahaCase::query()->distinct()->pluck('beneficiary_id'))
            ->merge(User::query()->whereNotNull('beneficiary_id')->pluck('beneficiary_id'))->filter()->unique()->values();

        return $ids->map(fn ($id) => ['id' => $id, 'createdAt' => iso($records[$id]->created_at ?? null), 'nationality' => $records[$id]->nationality ?? null, 'birthDate' => $records[$id]->birth_date ?? null])->all();
    }

    public function forUser(?User $u, array $range): array
    {
        if (! $this->canView($u)) {
            throw DomainRuleException::forbidden('لا تملك صلاحية عرض التحليلات.');
        }

        return self::compute($this->reports->authorizedCases($u), self::registered(), $range);
    }

    /** One long-form table (section / item / count / % / date basis) used by CSV, the Excel summary sheet and print. */
    public static function table(array $a): array
    {
        $period = ($a['range']['from'] ?: 'بدون حد أدنى').' → '.($a['range']['to'] ?: 'بدون حد أعلى');
        [$req, $done, $reg] = ['تاريخ تقديم الطلب', 'تاريخ إكمال الخدمة', 'تاريخ تسجيل المستفيد'];
        $rows = [['مؤشرات', "الفترة المحددة: $period", '', '', 'نطاق الفلتر'], ['مؤشرات', 'إجمالي المستفيدين المسجلين (كل الفترات)', $a['registeredTotal'], '', 'كل الفترات']];
        if ($a['registeredInPeriod'] !== null) {
            $rows[] = ['مؤشرات', 'مستفيدون مسجلون في الفترة (سجلات ذات تاريخ تسجيل معروف فقط)', $a['registeredInPeriod'], '', $reg];
        }
        array_push($rows, ['مؤشرات', 'مستفيدون نشطون في الفترة', $a['activeBeneficiaries'], '', $req], ['مؤشرات', 'إجمالي طلبات الخدمة', $a['totalRequests'], '', $req],
            ['مؤشرات', 'الخدمات المكتملة', $a['completedServices'], '', $done], ['مؤشرات', 'خدمات مكتملة بلا تاريخ إكمال موثق (كل الفترات)', $a['completedWithoutDate'], '', 'غير مؤرخة']);
        foreach ($a['services'] as $s) {
            $rows[] = ['الخدمات الأكثر طلبًا', $s['label'], $s['count'], $s['percent'], $req];
        }
        foreach ($a['nationality'] as $s) {
            $rows[] = ['توزيع الجنسيات (مستفيدون)', $s['label'], $s['count'], $s['percent'], $req];
        }
        foreach ($a['age'] as $s) {
            $rows[] = ['توزيع الأعمار (مستفيدون)', $s['label'], $s['count'], $s['percent'], "$req — عمر بتاريخ {$a['referenceDate']}"];
        }

        return ['head' => ['القسم', 'البند', 'العدد', 'النسبة %', 'أساس التاريخ'], 'rows' => array_map(fn ($c, $i) => ['key' => (string) $i, 'cells' => $c], $rows, array_keys($rows))];
    }

    /** The 4 aggregate Excel sheets (no identifiable data). */
    public static function sheets(array $a): array
    {
        $t = fn (array $head, array $slices) => ['head' => $head, 'rows' => array_map(fn ($x, $i) => ['key' => (string) $i, 'cells' => [$x['label'], $x['count'], $x['percent']]], $slices, array_keys($slices))];

        return [
            ['name' => 'تحليلات المستفيدين والخدمات', 'table' => self::table($a)], ['name' => 'الخدمات الأكثر طلبًا', 'table' => $t(['الخدمة', 'عدد الطلبات', 'النسبة %'], $a['services'])],
            ['name' => 'توزيع الجنسيات', 'table' => $t(['الجنسية', 'عدد المستفيدين', 'النسبة %'], $a['nationality'])], ['name' => 'توزيع الأعمار', 'table' => $t(['الفئة العمرية', 'عدد المستفيدين', 'النسبة %'], $a['age'])],
        ];
    }

    /** Front exportTables('analytics'): reports.export + analytics access, valid range, non-empty. */
    public function export(?User $u, array $range, string $format = 'xlsx'): array
    {
        if (! $this->reports->canRead($u) || ! $this->perms->has($u, 'reports.export')) {
            throw DomainRuleException::forbidden('لا تملك صلاحية تصدير التقارير.');
        }
        $a = $this->forUser($u, $range);
        if (! $a['totalRequests'] && ! $a['completedServices'] && ! $a['registeredTotal']) {
            throw new DomainRuleException('لا توجد بيانات مطابقة للتصفية');
        }

        return [
            'filename' => ReportService::filename('analytics', $format), 'table' => ReportService::sheet('تحليلات المستفيدين والخدمات', self::table($a)),
            'sheets' => array_map(fn ($s) => ReportService::sheet($s['name'], $s['table']), self::sheets($a)),
        ];
    }
}
