<?php

namespace Modules\Reports\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cases\Models\RahaCase;
use Modules\Reports\Services\AnalyticsService;
use Modules\Reports\Services\MonitoringService;
use Modules\Reports\Services\ReportService;

/** Reports (read-only, account:STAFF) + care/executive monitoring. */
class ReportController extends Controller
{
    private const MESSAGES = ['date_format' => 'تاريخ غير صالح.', 'in' => 'قيمة غير صالحة.', 'string' => 'قيمة غير صالحة.', 'max' => 'قيمة غير صالحة.', 'numeric' => 'قيمة غير صالحة.'];

    public function __construct(private ReportService $reports, private AnalyticsService $analytics, private MonitoringService $monitoring, private PermissionResolver $perms) {}

    private function filters(Request $request): array
    {
        $f = $request->validate([
            'days' => ['nullable', 'numeric', 'min:1', 'max:3650'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'service' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:32'], 'city' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', 'string', 'max:32'], 'caseId' => ['nullable', 'string', 'max:32'], 'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ], self::MESSAGES);
        if (! empty($f['from']) && ! empty($f['to']) && $f['from'] > $f['to']) {
            throw new DomainRuleException('تاريخ البداية يجب أن يسبق تاريخ النهاية');
        }

        return $f;
    }

    public function options(Request $request): JsonResponse
    {
        $this->reports->assertCanRead($request->user());

        return response()->json(['data' => $this->reports->options($request->user())]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $this->reports->assertCanRead($request->user());

        return response()->json(['data' => $this->reports->kpis($request->user(), $this->filters($request))]);
    }

    public function show(Request $request, string $kind): JsonResponse
    {
        $user = $request->user();
        $this->reports->assertCanRead($user);
        $cases = $this->reports->cases($user, $this->filters($request));
        $table = $this->reports->build($kind, $cases, $user);
        $out = $table + ['total' => $cases->count()];
        if ($kind === 'performance') {
            $out['performance'] = $this->perms->has($user, 'workflow.history.view')
                ? $this->reports->serializePerformance($this->reports->performanceData($cases), $user) : null;
        }

        return response()->json(['data' => $out]);
    }

    public function export(Request $request, string $kind): JsonResponse
    {
        $f = $this->filters($request);
        $format = $f['format'] ?? 'xlsx';
        $data = $kind === 'analytics'
            ? $this->analytics->export($request->user(), ['from' => $f['from'] ?? '', 'to' => $f['to'] ?? ''], $format)
            : $this->reports->export($kind, $request->user(), $f, $format);

        return response()->json(['data' => $data]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $range = ['from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $a = $this->analytics->forUser($request->user(), $range);

        return response()->json(['data' => $a + ['table' => AnalyticsService::table($a), 'sheets' => AnalyticsService::sheets($a)]]);
    }

    public function careApprovals(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => $this->monitoring->approvals($u)->map(fn (RahaCase $c) => $this->monitoring->careRow($c, $u))->values()]);
    }

    public function careExecution(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => $this->monitoring->execution($u)->map(fn (RahaCase $c) => $this->monitoring->careRow($c, $u))->values()]);
    }

    public function careExecutionShow(Request $request, string $caseId): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => $this->monitoring->careRow($this->monitoring->getExecution($caseId, $u), $u, true)]);
    }

    public function executive(Request $request): JsonResponse
    {
        $d = $request->validate(['section' => ['nullable', Rule::in(MonitoringService::SECTIONS)]], self::MESSAGES);
        $r = $this->monitoring->executive($request->user(), $d['section'] ?? 'all');

        return response()->json(['data' => $r['rows'], 'counts' => $r['counts']]);
    }
}
