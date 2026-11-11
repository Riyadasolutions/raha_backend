<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FinanceConfig;
use Modules\Finance\Services\FinanceQueries;
use Modules\Finance\Services\FinanceService;

/** Financial review, executive decisions, finance lists and configuration (account:STAFF). */
class FinanceController extends Controller
{
    public const MESSAGES = [
        'required' => 'أكمل الحقول المطلوبة.', 'string' => 'قيمة غير صالحة.', 'array' => 'قيمة غير صالحة.', 'boolean' => 'قيمة غير صالحة.',
        'in' => 'قيمة غير صالحة.', 'integer' => 'قيمة غير صالحة.', 'numeric' => 'أدخل مبلغًا صحيحًا.', 'min' => 'قيمة غير صالحة.', 'max' => 'النص أطول من المسموح.',
    ];

    public function __construct(private FinanceService $finance, private FinanceQueries $queries) {}

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->dashboard($request->user())]);
    }

    public function requests(Request $request): JsonResponse
    {
        $d = $request->validate(['stage' => ['nullable', 'string', 'max:40']], self::MESSAGES);

        return response()->json(['data' => $this->queries->requests($request->user(), $d['stage'] ?? null)]);
    }

    public function show(Request $request, string $caseId): JsonResponse
    {
        return response()->json(['data' => $this->queries->caseSummary($caseId, $request->user())]);
    }

    /** Write actions answer with the refreshed case finance summary (the user just acted on it, so no second visibility check). */
    private function summary(Request $request, string $caseId): JsonResponse
    {
        return response()->json(['data' => $this->queries->caseSummary($caseId, $request->user(), RahaCase::query()->findOrFail($caseId))]);
    }

    private function reasonOf(Request $request): ?string
    {
        return $request->validate(['reason' => ['nullable', 'string', 'max:2000']], self::MESSAGES)['reason'] ?? null;
    }

    public function start(Request $request, string $caseId): JsonResponse
    {
        $this->finance->start($caseId, $request->user());

        return $this->summary($request, $caseId);
    }

    public function allocations(Request $request, string $caseId): JsonResponse
    {
        $d = $request->validate([
            'totalCostHalalas' => ['required', 'numeric'], 'costingBasis' => ['nullable', 'string', 'max:2000'], 'allocations' => ['present', 'array'],
            'allocations.*.id' => ['nullable', 'string', 'max:64'], 'allocations.*.sourceType' => ['required', Rule::in(FinanceService::SOURCE_TYPES)],
            'allocations.*.walletId' => ['nullable', 'string', 'max:32'], 'allocations.*.label' => ['nullable', 'string', 'max:200'],
            'allocations.*.amountHalalas' => ['required', 'numeric'], 'providerId' => ['nullable', 'string', 'max:32'], 'providerName' => ['nullable', 'string', 'max:255'],
        ], self::MESSAGES);
        $int = fn ($v) => is_string($v) && preg_match('/^-?\d+$/', $v) ? (int) $v : $v;
        $d['totalCostHalalas'] = $int($d['totalCostHalalas']);
        $d['allocations'] = array_map(fn ($x) => ['amountHalalas' => $int($x['amountHalalas'])] + $x, $d['allocations']);
        $this->finance->setAllocations($caseId, $d, $request->user());

        return $this->summary($request, $caseId);
    }

    public function approveSplit(Request $request, string $caseId): JsonResponse
    {
        $this->finance->approveSplit($caseId, $request->user());

        return $this->summary($request, $caseId);
    }

    public function reserve(Request $request, string $caseId): JsonResponse
    {
        $this->finance->reserve($caseId, $request->user());

        return $this->summary($request, $caseId);
    }

    public function release(Request $request, string $caseId): JsonResponse
    {
        $this->finance->release($caseId, $request->user(), $this->reasonOf($request));

        return $this->summary($request, $caseId);
    }

    public function returnToCare(Request $request, string $caseId): JsonResponse
    {
        $this->finance->returnToCare($caseId, $request->user(), $this->reasonOf($request));

        return $this->summary($request, $caseId);
    }

    public function reject(Request $request, string $caseId): JsonResponse
    {
        $this->finance->rejectFunding($caseId, $request->user(), $this->reasonOf($request));

        return $this->summary($request, $caseId);
    }

    public function executiveApprove(Request $request, string $caseId): JsonResponse
    {
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:2000'], 'confirmed' => ['nullable', 'boolean']], self::MESSAGES);
        $this->finance->executiveApprove($caseId, $request->user(), $d['comment'] ?? null, (bool) ($d['confirmed'] ?? false));

        return $this->summary($request, $caseId);
    }

    public function executiveReturn(Request $request, string $caseId): JsonResponse
    {
        $this->finance->executiveReturn($caseId, $request->user(), $this->reasonOf($request));

        return $this->summary($request, $caseId);
    }

    public function executiveReject(Request $request, string $caseId): JsonResponse
    {
        $this->finance->executiveReject($caseId, $request->user(), $this->reasonOf($request));

        return $this->summary($request, $caseId);
    }

    public function config(Request $request): JsonResponse
    {
        $this->queries->requireAny($request->user(), ['finance.dashboard.view', 'finance.config.manage', 'finance.funding.review', 'authorization.view', 'executive.approvals.view']);

        return response()->json(['data' => FinanceService::configShape(FinanceConfig::current())]);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $this->finance->need($request->user(), 'finance.config.manage');
        $d = $request->validate([
            'order' => ['required', Rule::in(['RESERVE_THEN_APPROVE', 'APPROVE_THEN_RESERVE'])], 'signatoryName' => ['nullable', 'string', 'max:255'],
            'signatoryTitle' => ['nullable', 'string', 'max:255'], 'letterValidityDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ], self::MESSAGES);
        $input = ['order' => $d['order']];
        foreach (['signatoryName', 'signatoryTitle', 'letterValidityDays'] as $k) {
            if ($request->exists($k)) {
                $input[$k] = $d[$k] ?? null;
            }
        }

        return response()->json(['data' => FinanceService::configShape($this->finance->setConfig($input, $request->user()))]);
    }

    public function reservations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->reservations($request->user())]);
    }

    public function commitments(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->commitments($request->user())]);
    }

    public function events(Request $request): JsonResponse
    {
        $d = $request->validate(['caseId' => ['nullable', 'string', 'max:32']], self::MESSAGES);

        return response()->json(['data' => $this->queries->events($request->user(), $d['caseId'] ?? null)]);
    }

    public function walletBalances(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->walletBalancesReport($request->user())]);
    }
}
