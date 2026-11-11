<?php

namespace Modules\Reports\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\WorkflowConfig;
use Modules\Catalog\Models\Provider;
use Modules\Catalog\Models\Service;
use Modules\Finance\Models\FinanceEvent;
use Modules\Quotations\Models\QuotationRound;

/**
 * Port of front report_service.ts + report_export_data.ts + report_export_service.exportTables (data/permission side).
 * Read-only. Tables use the front ReportTable shape {head, rows: [{key, cells, caseId?}]}; the browser builds xlsx/csv.
 */
class ReportService
{
    public const KINDS = ['requests', 'beneficiaries', 'services', 'providers', 'appointments', 'cases', 'performance', 'ratings', 'lifecycle'];

    public const MASKED = 'محجوب حسب الصلاحية';

    public const NA = 'غير متوفر';

    public const EXPORT_NAMES = [
        'requests' => ['Requests', 'تقرير الطلبات'], 'beneficiaries' => ['Beneficiaries', 'تقرير المستفيدين'], 'services' => ['Services', 'تقرير الخدمات'],
        'providers' => ['Providers', 'تقرير مقدمي الخدمة'], 'appointments' => ['Appointments', 'تقرير المواعيد'], 'cases' => ['Cases', 'تقرير الحالات'],
        'performance' => ['Performance', 'تقرير الأداء'], 'ratings' => ['Ratings', 'التقييمات'], 'lifecycle' => ['Lifecycle', 'دورة الطلب'],
        'analytics' => ['Analytics', 'تحليلات المستفيدين والخدمات'],
    ];

    /** Finance events of the cases being reported, preloaded once per request. */
    private ?Collection $financeEvents = null;

    private ?Collection $openRounds = null;

    public function __construct(private PermissionResolver $perms) {}

    public function canRead(?User $u): bool
    {
        return $u !== null && $u->isStaff() && $this->perms->has($u, 'reports.view');
    }

    public function assertCanRead(?User $u): void
    {
        if (! $this->canRead($u)) {
            throw DomainRuleException::forbidden(self::MASKED);
        }
    }

    private static function assigned(RahaCase $c, User $u): bool
    {
        return $c->assignee === $u->name || $c->tasks->contains(fn (CaseTask $t) => $t->assigned_user_id === $u->id);
    }

    /** Front authorizedReportCases: cases.view + reports.view, each either ALL scope or the case is assigned to the user. */
    public function authorizedCases(?User $u): Collection
    {
        if (! $this->canRead($u) || ! $this->perms->has($u, 'cases.view')) {
            return collect();
        }
        $allCases = $this->perms->scopeOf($u, 'cases.view') === 'ALL';
        $allReports = $this->perms->scopeOf($u, 'reports.view') === 'ALL';

        return RahaCase::query()->with(['activities', 'tasks'])->orderByDesc('created_at')->get()
            ->filter(fn (RahaCase $c) => ($allCases || self::assigned($c, $u)) && ($allReports || self::assigned($c, $u)))->values();
    }

    public static function day(mixed $v): ?string
    {
        $ms = Lifecycle::ms($v);

        return $ms === null ? null : Carbon::createFromTimestampMs($ms, 'UTC')->format('Y-m-d');
    }

    /** @param array{days?: ?string, from?: ?string, to?: ?string, service?: ?string, status?: ?string, city?: ?string, provider?: ?string, caseId?: ?string} $f */
    public static function filter(Collection $cases, array $f, ?int $now = null): Collection
    {
        $now ??= (int) Carbon::now()->getTimestampMs();
        $groups = WorkflowConfig::statusGroups();

        return $cases->filter(function (RahaCase $c) use ($f, $now, $groups) {
            $created = Lifecycle::ms($c->created_at);
            $day = self::day($c->created_at);

            return (empty($f['days']) || ($created <= $now && $now - $created <= ((float) $f['days']) * 86400000))
                && (empty($f['from']) || $day >= $f['from']) && (empty($f['to']) || $day <= $f['to'])
                && (empty($f['service']) || $c->service === $f['service'])
                && (empty($f['status']) || in_array($c->status, $groups[$f['status']]['statuses'] ?? [], true))
                && (empty($f['city']) || $c->city === $f['city'])
                && (empty($f['provider']) || $c->provider_id === $f['provider'])
                && (empty($f['caseId']) || $c->id === $f['caseId']);
        })->values();
    }

    public function cases(?User $u, array $f): Collection
    {
        return self::filter($this->authorizedCases($u), $f);
    }

    public function options(User $u): array
    {
        $cases = $this->authorizedCases($u);

        return [
            'cities' => $cases->pluck('city')->unique()->values()->all(),
            'services' => Service::query()->whereIn('name', $cases->pluck('service')->unique()->all())->orderBy('id')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'providers' => Provider::query()->whereIn('id', $cases->pluck('provider_id')->filter()->unique()->all())->orderBy('id')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
        ];
    }

    /** Reports page KPI tiles (front reports_page metrics). */
    public function kpis(?User $u, array $f): array
    {
        $cases = $this->cases($u, $f);
        $groups = WorkflowConfig::statusGroups();
        $in = fn (string $g) => $cases->filter(fn (RahaCase $c) => in_array($c->status, $groups[$g]['statuses'] ?? [], true))->count();

        return [
            ['key' => 'total', 'label' => 'إجمالي الطلبات', 'value' => $cases->count()], ['key' => 'under-review', 'label' => 'قيد المراجعة', 'value' => $in('under-review')],
            ['key' => 'in-service', 'label' => 'الخدمة جارية', 'value' => $in('in-service')], ['key' => 'completed', 'label' => 'مكتملة', 'value' => $in('completed')],
            ['key' => 'returned', 'label' => 'معادة', 'value' => $cases->where('status', 'returned')->count()], ['key' => 'rejected', 'label' => 'مرفوضة', 'value' => $cases->where('status', 'rejected')->count()],
        ];
    }

    private static function date(mixed $v): string
    {
        $ms = Lifecycle::ms($v);

        return $ms === null ? self::NA : Carbon::createFromTimestampMs($ms, 'UTC')->format('Y-m-d H:i:s').' UTC';
    }

    private function preload(Collection $cases): void
    {
        $ids = $cases->pluck('id')->all();
        $this->financeEvents = FinanceEvent::query()->whereIn('case_id', $ids)->get()->groupBy('case_id');
        $this->openRounds = QuotationRound::query()->whereIn('case_id', $ids)->where('status', 'open')->get()->groupBy('case_id');
    }

    /** Front performanceData. Spans are kept with ms timestamps internally; see serializePerformance() for JSON. */
    public function performanceData(Collection $cases, ?int $now = null): array
    {
        $now ??= (int) Carbon::now()->getTimestampMs();
        $this->preload($cases);
        $details = $cases->map(function (RahaCase $c) use ($now) {
            $spans = Lifecycle::lifecycle($c, $now, $this->financeEvents->get($c->id, collect()));
            $round = $this->openRounds->get($c->id)?->first();
            $completion = Lifecycle::completion($c);

            return ['c' => $c, 'spans' => $spans, 'completion' => $completion, 'delay' => Lifecycle::delay($c, $spans, $round?->deadline, $now),
                'elapsed' => Lifecycle::hoursBetween($c->created_at, $completion['at'] ?? $now)];
        })->values()->all();
        $spans = array_values(array_filter(array_merge(...array_map(fn ($d) => $d['spans'], $details ?: [['spans' => []]])), fn ($s) => ! str_contains($s['source'], Lifecycle::FINANCE_SOURCE)));
        $hoursOf = fn (array $list) => array_values(array_map(fn ($s) => $s['hours'], array_filter($list, fn ($s) => $s['hours'] !== null)));
        $closedOf = fn (array $list) => array_values(array_filter($list, fn ($s) => $s['exitedAt'] !== null && $s['hours'] !== null));
        $names = [...raha('workflow.workflowSteps', []), 'بانتظار تصحيح المستفيد', 'بانتظار عروض الأسعار', 'مرفوضة'];
        $stages = array_map(function (string $name) use ($spans, $cases, $details, $closedOf, $hoursOf) {
            $closed = $closedOf(array_filter($spans, fn ($s) => $s['stage'] === $name));

            return ['name' => $name, 'count' => $cases->filter(fn ($c) => Lifecycle::reportStage($c->status) === $name)->count(), 'average' => Lifecycle::mean($hoursOf($closed)),
                'samples' => count($closed), 'delayed' => count(array_filter($details, fn ($d) => Lifecycle::reportStage($d['c']->status) === $name && $d['delay']['delayed']))];
        }, $names);
        $departments = array_map(function (string $name) use ($spans, $cases, $details, $closedOf, $hoursOf) {
            $own = array_values(array_filter($spans, fn ($s) => Lifecycle::departmentOf($s['status']) === $name));
            $closed = $closedOf($own);
            $entered = $cases->filter(fn (RahaCase $c) => $c->activities->contains(fn (CaseActivity $a) => $a->to_stage && $a->from_stage !== $a->to_stage && Lifecycle::departmentOf($a->to_stage) === $name))->pluck('id')->all();
            $ids = array_unique([...array_column($own, 'caseId'), ...$entered]);

            return [
                'name' => $name, 'received' => count($ids), 'processed' => count(array_unique(array_column($closed, 'caseId'))),
                'pending' => $cases->filter(fn ($c) => Lifecycle::departmentOf($c->status) === $name)->count(), 'average' => Lifecycle::mean($hoursOf($closed)),
                'returned' => $cases->filter(fn (RahaCase $c) => $c->activities->contains(fn (CaseActivity $a) => $a->from_stage && Lifecycle::departmentOf($a->from_stage) === $name && $a->to_stage === 'returned'))->count(),
                'delayed' => count(array_filter($details, fn ($d) => Lifecycle::departmentOf($d['c']->status) === $name && $d['delay']['delayed'])),
            ];
        }, ['التدقيق', 'اللجنة الطبية', 'البحث الاجتماعي', 'مدير الرعاية الصحية', 'الاعتمادات', 'مزودي الخدمة', 'علاقات المرضى', 'المالية']);
        $values = array_values(array_map(fn ($d) => $d['completion']['hours'], array_filter($details, fn ($d) => $d['completion'] !== null)));
        $month = fn ($d) => $d['completion'] ? Carbon::createFromTimestampMs($d['completion']['at'], 'UTC')->format('Y-m') : null;
        $monthNames = array_values(array_unique(array_filter(array_map($month, $details))));
        sort($monthNames);
        $months = array_map(function ($name) use ($details, $month) {
            $rows = array_filter($details, fn ($d) => $month($d) === $name);

            return ['name' => $name, 'count' => count($rows), 'average' => Lifecycle::mean(array_values(array_map(fn ($d) => $d['completion']['hours'], $rows)))];
        }, $monthNames);

        return [
            'details' => $details, 'spans' => $spans, 'stages' => $stages, 'departments' => $departments, 'months' => $months,
            'completed' => $cases->where('status', 'archived')->count(), 'pending' => $cases->filter(fn ($c) => ! in_array($c->status, ['archived', 'rejected'], true))->count(),
            'rejected' => $cases->where('status', 'rejected')->count(), 'average' => Lifecycle::mean($values), 'min' => $values ? min($values) : null, 'max' => $values ? max($values) : null,
            'total' => $values ? array_sum($values) : null, 'samples' => count($values),
            'missing' => count(array_filter($details, fn ($d) => ! array_filter($d['spans'], fn ($s) => $s['hours'] !== null))),
        ];
    }

    public static function spanFront(array $s): array
    {
        return array_merge($s, ['enteredAt' => Lifecycle::isoMs($s['enteredAt']), 'exitedAt' => Lifecycle::isoMs($s['exitedAt'])]);
    }

    /** JSON form of performanceData for the performance panel (charts, delays, waits, longest cycles). */
    public function serializePerformance(array $data, User $u): array
    {
        $finance = $this->perms->has($u, 'finance.ledger.view');
        $data['details'] = array_map(fn ($d) => [
            'caseId' => $d['c']->id, 'status' => $d['c']->status, 'reportStage' => Lifecycle::reportStage($d['c']->status), 'department' => Lifecycle::departmentOf($d['c']->status),
            'completion' => $d['completion'] ? ['at' => Lifecycle::isoMs($d['completion']['at']), 'hours' => $d['completion']['hours']] : null,
            'delay' => $d['delay'], 'elapsed' => $d['elapsed'],
            'spans' => array_map([self::class, 'spanFront'], array_values(array_filter($d['spans'], fn ($s) => $finance || ! str_contains($s['source'], Lifecycle::FINANCE_SOURCE)))),
        ], $data['details']);
        $data['spans'] = array_map([self::class, 'spanFront'], $data['spans']);

        return $data;
    }

    private static function table(array $head, array $cells): array
    {
        return ['head' => $head, 'rows' => array_map(fn ($c, $i) => ['key' => (string) $i, 'cells' => $c], $cells, array_keys($cells))];
    }

    private static function spanKind(array $s): string
    {
        return $s['current'] || str_starts_with($s['source'], 'مدة جارية') ? 'مدة جارية' : ($s['exitedAt'] !== null ? 'مدة مكتملة' : 'خروج غير مسجل');
    }

    /** Front buildReport (masking: beneficiaries.view names, beneficiaries.viewSensitive comments, finance.ledger.view amounts/finance spans). */
    public function build(string $kind, Collection $cases, User $u, ?int $now = null): array
    {
        $now ??= (int) Carbon::now()->getTimestampMs();
        $names = $this->perms->has($u, 'beneficiaries.view');
        $comments = $this->perms->has($u, 'beneficiaries.viewSensitive');
        $history = $this->perms->has($u, 'workflow.history.view');
        $finance = $this->perms->has($u, 'finance.ledger.view');
        $beneficiary = fn (RahaCase $c) => $names ? $c->beneficiary : self::MASKED;
        $stageLabel = fn (RahaCase $c) => WorkflowConfig::stageLabel($c->status) ?? $c->status;

        if ($kind === 'requests' || $kind === 'cases') {
            return ['head' => ['رقم الطلب', 'المستفيد', 'الخدمة', 'المدينة', 'تاريخ الطلب', 'الحالة', 'المرحلة', 'مقدم الخدمة'], 'rows' => $cases->map(fn (RahaCase $c) => [
                'key' => $c->id, 'caseId' => $c->id, 'cells' => [$c->id, $beneficiary($c), $c->service, $c->city, self::date($c->created_at), $stageLabel($c), Lifecycle::reportStage($c->status), $c->provider ?? '—'],
            ])->values()->all()];
        }
        if ($kind === 'beneficiaries') {
            $rows = $cases->pluck('beneficiary_id')->unique()->values()->map(function ($id) use ($cases, $names, $beneficiary) {
                $list = $cases->where('beneficiary_id', $id)->values();
                $c = $list->first();
                $last = $list->sortBy(fn ($x) => Lifecycle::ms($x->created_at))->last();

                return [$names ? $id : self::MASKED, $beneficiary($c), $c->city, $list->count(), self::date($last?->created_at)];
            })->all();

            return self::table(['رقم المستفيد', 'الاسم', 'المدينة', 'عدد الطلبات', 'آخر طلب'], $rows);
        }
        if ($kind === 'services' || $kind === 'providers') {
            $keyOf = fn (RahaCase $c) => $kind === 'services' ? $c->service : $c->provider_id;
            $providerNames = Provider::query()->pluck('name', 'id');
            $rows = $cases->map($keyOf)->filter()->unique()->values()->map(function ($key) use ($cases, $keyOf, $kind, $providerNames, $finance) {
                $list = $cases->filter(fn ($c) => $keyOf($c) === $key)->values();
                $rated = $list->map(fn ($c) => $c->evaluation['rating'] ?? null)->filter(fn ($r) => is_numeric($r) && $r >= 1 && $r <= 5)->values()->all();

                return [
                    $kind === 'services' ? $key : ($providerNames[$key] ?? $list->first()?->provider ?? $key), $list->count(),
                    $list->filter(fn ($c) => in_array($c->status, ['assigned', 'scheduled', 'in_progress'], true))->count(), $list->where('status', 'archived')->count(),
                    $list->where('status', 'rejected')->count(), Lifecycle::duration(Lifecycle::mean($rated)), count($rated),
                    $list->filter(fn ($c) => ($c->invoice['status'] ?? null) === 'pending')->count(),
                    $finance ? number_format($list->sum(fn ($c) => (float) ($c->invoice['amount'] ?? 0)), 2, '.', '') : self::MASKED,
                ];
            })->all();

            return self::table([$kind === 'services' ? 'الخدمة' : 'مقدم الخدمة', 'الطلبات', 'الجارية', 'مؤرشفة', 'المرفوضة', 'متوسط التقييم', 'عدد التقييمات', 'الفواتير المعلقة', 'قيمة الفواتير (ر.س)'], $rows);
        }
        if ($kind === 'appointments') {
            $labels = ['upcoming' => 'قادم', 'visited' => 'تمت الزيارة', 'cancelled' => 'ملغي', 'rescheduled' => 'أعيدت جدولته'];

            return ['head' => ['رقم الطلب', 'المستفيد', 'الخدمة', 'التاريخ', 'الوقت', 'مقدم الخدمة', 'حالة الموعد'], 'rows' => $cases->filter(fn ($c) => ! empty($c->appointment))->map(fn (RahaCase $c) => [
                'key' => $c->id, 'caseId' => $c->id, 'cells' => [$c->id, $beneficiary($c), $c->service, $c->appointment['date'] ?? '', $c->appointment['time'] ?? '', $c->provider ?? '—', $labels[$c->appointment['status'] ?? ''] ?? ''],
            ])->values()->all()];
        }
        if ($kind === 'ratings') {
            $rows = [];
            foreach ($cases as $c) {
                $r = $c->evaluation['rating'] ?? null;
                if (is_numeric($r) && $r >= 1 && $r <= 5) {
                    $rows[] = ['key' => $c->id.'service', 'caseId' => $c->id, 'cells' => [$c->id, 'تقييم الخدمة', $c->provider ?? '—', $c->service, $r, '—', self::date($c->evaluation['at'] ?? null), $comments ? ($c->evaluation['comment'] ?? '') : self::MASKED]];
                }
                if ($s = $c->request_survey) {
                    $rows[] = ['key' => $c->id.'request', 'caseId' => $c->id, 'cells' => [$c->id, 'تجربة تقديم الطلب', '—', $c->service, $s['ease'] ?? '', $s['clarity'] ?? '', self::date($s['at'] ?? null), $comments ? ($s['comment'] ?? '') : self::MASKED]];
                }
            }

            return ['head' => ['رقم الطلب', 'نوع التقييم', 'مقدم الخدمة', 'الخدمة', 'التقييم', 'وضوح الإجراءات', 'تاريخ التقييم', 'تعليق المستفيد'], 'rows' => $rows];
        }
        if (! $history) {
            return self::table(['المؤشر', 'القيمة'], [['سجل دورة الطلب', self::MASKED]]);
        }
        $data = $this->performanceData($cases, $now);
        if ($kind === 'lifecycle') {
            $rows = [];
            foreach ($data['details'] as $d) {
                $c = $d['c'];
                $spans = array_values(array_filter($d['spans'], fn ($s) => $finance || ! str_contains($s['source'], Lifecycle::FINANCE_SOURCE)));
                if (! $spans) {
                    $rows[] = ['key' => $c->id, 'caseId' => $c->id, 'cells' => [$c->id, $c->service, $c->provider ?? '—', $stageLabel($c), Lifecycle::reportStage($c->status), self::NA, self::NA, self::NA, 'سجل تاريخي غير مكتمل', 'لا توجد أحداث انتقال موثقة']];
                }
                foreach ($spans as $i => $s) {
                    $rows[] = ['key' => $c->id.'-'.$i, 'caseId' => $c->id, 'cells' => [$s['caseId'], $c->service, $c->provider ?? '—', $stageLabel($c), $s['stage'],
                        self::date($s['enteredAt']), self::date($s['exitedAt']), Lifecycle::duration($s['hours']), self::spanKind($s), $s['source']]];
                }
            }

            return ['head' => ['رقم الطلب', 'الخدمة', 'مقدم الخدمة', 'الحالة', 'المرحلة', 'وقت الدخول', 'وقت الخروج', 'المدة (ساعة)', 'نوع المدة', 'المصدر'], 'rows' => $rows];
        }
        $delayed = count(array_filter($data['details'], fn ($d) => $d['delay']['delayed']));
        $rows = [
            ['إجمالي الطلبات', $cases->count()], ['مؤرشفة', $data['completed']], ['قيد المعالجة', $data['pending']], ['مرفوضة', $data['rejected']],
            ['مجموع مدد الإكمال الموثقة (ساعة)', Lifecycle::duration($data['total'])], ['متوسط الإكمال حتى الأرشفة (ساعة)', Lifecycle::duration($data['average'])],
            ['أقل مدة إكمال (ساعة)', Lifecycle::duration($data['min'])], ['أطول مدة إكمال (ساعة)', Lifecycle::duration($data['max'])], ['عينات الإكمال الموثقة', $data['samples']],
            ['طلبات متأخرة بموعد محدد', $delayed],
        ];
        foreach ($data['stages'] as $s) {
            array_push($rows, [$s['name'].' — متوسط المرحلة (ساعة)', Lifecycle::duration($s['average'])], [$s['name'].' — الطلبات الحالية', $s['count']], [$s['name'].' — عينات موثقة', $s['samples']], [$s['name'].' — متأخرة بموعد محدد', $s['delayed']]);
        }
        foreach ($data['departments'] as $d) {
            array_push($rows, [$d['name'].' — طلبات مستلمة موثقة', $d['received']], [$d['name'].' — طلبات منجزة موثقة', $d['processed']], [$d['name'].' — طلبات معلقة', $d['pending']],
                [$d['name'].' — متوسط المدة (ساعة)', Lifecycle::duration($d['average'])], [$d['name'].' — معادة للتصحيح', $d['returned']], [$d['name'].' — متأخرة بموعد محدد', $d['delayed']]);
        }

        return self::table(['المؤشر', 'القيمة'], $rows);
    }

    /** Front performanceExport: one row per stage span (or one placeholder row) with request-level context. */
    public function performanceExport(Collection $cases, User $u, ?int $now = null): array
    {
        $history = $this->perms->has($u, 'workflow.history.view');
        $finance = $this->perms->has($u, 'finance.ledger.view');
        $details = $history ? $this->performanceData($cases, $now)['details']
            : $cases->map(fn ($c) => ['c' => $c, 'spans' => [], 'completion' => null, 'delay' => ['delayed' => false, 'deadline' => null, 'elapsed' => null], 'elapsed' => null])->all();
        $rows = [];
        foreach ($details as $d) {
            $c = $d['c'];
            $total = $history ? ($c->status === 'archived' ? ($d['completion']['hours'] ?? null) : ($c->status === 'rejected' ? null : $d['elapsed'])) : null;
            $base = [$c->id, Lifecycle::reportStage($c->status), $c->service, $c->provider ?? '—', WorkflowConfig::stageLabel($c->status) ?? $c->status,
                $c->status === 'archived' ? 'مؤرشفة' : ($c->status === 'rejected' ? 'مرفوضة' : 'قيد المعالجة'), Lifecycle::duration($total), Lifecycle::departmentOf($c->status),
                ! $history ? self::MASKED : (! $d['delay']['deadline'] ? 'غير قابل للقياس' : ($d['delay']['delayed'] ? 'متأخر بموعد محدد' : 'ضمن الموعد')), $d['delay']['deadline'] ?? self::NA];
            $spans = array_values(array_filter($d['spans'], fn ($s) => $finance || ! str_contains($s['source'], Lifecycle::FINANCE_SOURCE)));
            if (! $spans) {
                $rows[] = ['key' => $c->id, 'cells' => [...$base, Lifecycle::reportStage($c->status), self::NA, self::NA, self::NA, $history ? 'سجل تاريخي غير مكتمل' : self::MASKED, self::NA]];
            }
            foreach ($spans as $i => $s) {
                $rows[] = ['key' => $c->id.'-'.$i, 'cells' => [...$base, $s['stage'], Lifecycle::isoMs($s['enteredAt']), Lifecycle::isoMs($s['exitedAt']) ?? self::NA, Lifecycle::duration($s['hours']), self::spanKind($s), $s['source']]];
            }
        }

        return ['head' => ['رقم الطلب', 'المرحلة الحالية', 'الخدمة', 'مقدم الخدمة', 'الحالة', 'حالة الإكمال', 'إجمالي المدة (ساعة)', 'القسم المسؤول', 'حالة التأخير', 'الموعد المحدد', 'مرحلة السجل', 'وقت الدخول', 'وقت الخروج', 'مدة المرحلة (ساعة)', 'نوع المدة', 'المصدر'], 'rows' => $rows];
    }

    /** Front exportWorkbookTables (non-analytics). */
    public function workbook(string $kind, Collection $cases, User $u, array $table, ?int $now = null): array
    {
        if ($kind !== 'performance') {
            return [['name' => self::EXPORT_NAMES[$kind][1], 'table' => $table]];
        }
        $tables = [['name' => 'ملخص الأداء', 'table' => $this->build('performance', $cases, $u, $now)], ['name' => 'تفاصيل أداء الطلبات', 'table' => $table]];
        if ($this->perms->has($u, 'workflow.history.view')) {
            $data = $this->performanceData($cases, $now);
            $tables[] = ['name' => 'أداء الأقسام', 'table' => self::table(['القسم', 'طلبات مستلمة موثقة', 'طلبات منجزة موثقة', 'طلبات معلقة', 'متوسط المدة (ساعة)', 'معادة للتصحيح', 'متأخرة بموعد محدد'],
                array_map(fn ($d) => [$d['name'], $d['received'], $d['processed'], $d['pending'], Lifecycle::duration($d['average']), $d['returned'], $d['delayed']], $data['departments']))];
            $tables[] = ['name' => 'متابعة المراحل', 'table' => self::table(['المرحلة', 'الطلبات الحالية', 'متوسط المدة المكتملة (ساعة)', 'عينات موثقة', 'طلبات متأخرة بموعد محدد'],
                array_map(fn ($s) => [$s['name'], $s['count'], Lifecycle::duration($s['average']), $s['samples'], $s['delayed']], $data['stages']))];
        }

        return $tables;
    }

    public static function filename(string $kind, string $format, ?Carbon $at = null): string
    {
        return 'RAHA_'.self::EXPORT_NAMES[$kind][0].'_'.($at ?? Carbon::now())->copy()->utc()->format('Y-m-d').'.'.$format;
    }

    /** {name, columns, rows: cells[][]} sheet shape returned by the export endpoint. */
    public static function sheet(string $name, array $table): array
    {
        return ['name' => $name, 'columns' => $table['head'], 'rows' => array_map(fn ($r) => $r['cells'], $table['rows'])];
    }

    /** Front exportTables (permission + scope + emptiness rules) for every kind except analytics. */
    public function export(string $kind, ?User $u, array $f, string $format = 'xlsx'): array
    {
        if (! $this->canRead($u) || ! $this->perms->has($u, 'reports.export')) {
            throw DomainRuleException::forbidden('لا تملك صلاحية تصدير التقارير.');
        }
        if ($kind === 'lifecycle' && ! $this->perms->has($u, 'workflow.history.view')) {
            throw DomainRuleException::forbidden('لا تملك صلاحية عرض سجل دورة الطلب.');
        }
        $cases = $this->cases($u, $f);
        if (! $this->perms->has($u, 'reports.export', 'ALL') && $cases->contains(fn (RahaCase $c) => ! self::assigned($c, $u))) {
            throw DomainRuleException::forbidden('نطاق التصدير لا يشمل جميع نتائج التصفية.');
        }
        $now = (int) Carbon::now()->getTimestampMs();
        $table = $kind === 'performance' ? $this->performanceExport($cases, $u, $now) : $this->build($kind, $cases, $u, $now);
        if ($cases->isEmpty() || ! $table['rows']) {
            throw new DomainRuleException('لا توجد بيانات مطابقة للتصفية');
        }

        return [
            'filename' => self::filename($kind, $format), 'table' => self::sheet(self::EXPORT_NAMES[$kind][1], $table),
            'sheets' => array_map(fn ($t) => self::sheet($t['name'], $t['table']), $this->workbook($kind, $cases, $u, $table, $now)),
        ];
    }
}
