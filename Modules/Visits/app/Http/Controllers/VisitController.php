<?php

namespace Modules\Visits\Http\Controllers;

use App\Exceptions\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cases\Models\RahaCase;
use Modules\Visits\Models\VisitRecord;
use Modules\Visits\Services\VisitMonitoringService;
use Modules\Visits\Services\VisitPlanService;
use Modules\Visits\Services\VisitService;

/**
 * Visit plan (Task 05) + visit records (Task 06) + visit monitoring. Numeric counts are passed through unvalidated so the
 * service applies the front rule (a JSON positive integer) with its exact Arabic message.
 */
class VisitController extends Controller
{
    private const MESSAGES = ['string' => 'قيمة غير صالحة.', 'max' => 'النص أطول من المسموح.', 'in' => 'قيمة غير صالحة.', 'date_format' => 'صيغة التاريخ غير صحيحة.'];

    public function __construct(private VisitPlanService $plans, private VisitService $visits, private VisitMonitoringService $monitoring) {}

    private function withProgress(VisitRecord $v): JsonResponse
    {
        return response()->json(['data' => $v->toFront(), 'progress' => VisitService::completionState(RahaCase::query()->findOrFail($v->case_id))]);
    }

    public function plan(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->plans->view($id, $request->user())]);
    }

    public function setApprovedCount(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->plans->setApprovedVisitCount($id, $request->input('count'), $request->user())]);
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:2000'], 'resolution' => ['nullable', Rule::in(['cancel_excess_scheduled'])]], self::MESSAGES);
        $user = $request->user();
        $c = $this->visits->adjustApprovedVisitCount($id, $request->input('newCount'), $d['reason'] ?? null, $user, $d['resolution'] ?? null);
        $list = $this->visits->forCase($c->id, $user);

        return response()->json(['data' => ['visitPlan' => $c->visit_plan, 'visits' => $list['visits']->map->toFront()->values(), 'progress' => $list['progress']]]);
    }

    public function index(Request $request, string $id): JsonResponse
    {
        $r = $this->visits->forCase($id, $request->user());

        return response()->json(['data' => $r['visits']->map->toFront()->values(), 'approvedVisitCount' => $r['approvedVisitCount'], 'progress' => $r['progress']]);
    }

    public function schedule(Request $request, string $id): JsonResponse
    {
        $request->validate(['scheduledAt' => ['nullable', 'string', 'max:64']], self::MESSAGES);
        $input = ['scheduledAt' => $request->input('scheduledAt')];
        if ($request->has('sequence') && $request->input('sequence') !== null) {
            $input['sequence'] = $request->input('sequence');
        }
        $v = $this->visits->schedule($id, $input, $request->user());

        return $this->withProgress($v)->setStatusCode(201);
    }

    public function complete(Request $request, string $visitId): JsonResponse
    {
        $d = $request->validate(['completedAt' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:4000'], 'evidence' => ['nullable', 'string', 'max:500']], self::MESSAGES);

        return $this->withProgress($this->visits->complete($visitId, $d, $request->user()));
    }

    public function miss(Request $request, string $visitId): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:2000']], self::MESSAGES);

        return $this->withProgress($this->visits->markMissed($visitId, $d['reason'] ?? null, $request->user()));
    }

    public function cancel(Request $request, string $visitId): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:2000']], self::MESSAGES);

        return $this->withProgress($this->visits->cancel($visitId, $d['reason'] ?? null, $request->user()));
    }

    public function correct(Request $request, string $visitId): JsonResponse
    {
        $d = $request->validate([
            'status' => ['nullable', Rule::in(['scheduled', 'completed', 'missed', 'cancelled'])], 'completedAt' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:4000'], 'reason' => ['nullable', 'string', 'max:2000'],
        ], self::MESSAGES);
        $patch = array_filter(array_intersect_key($d, array_flip(['status', 'completedAt'])), fn ($v) => $v !== null);
        if ($request->exists('notes')) {
            $patch['notes'] = (string) ($d['notes'] ?? ''); // present-but-empty clears the notes (front: patch.notes !== undefined)
        }

        return $this->withProgress($this->visits->correct($visitId, $patch, $d['reason'] ?? null, $request->user()));
    }

    public function providerVisits(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->visits->forProvider($request->user())->map->toFront()->values()]);
    }

    public function monitoring(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->monitoring->canMonitor($user)) {
            throw DomainRuleException::forbidden();
        }
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'providerId' => ['nullable', 'string', 'max:32'],
            'serviceId' => ['nullable', 'string', 'max:32'], 'status' => ['nullable', Rule::in(['scheduled', 'completed', 'missed', 'cancelled', 'overdue'])], 'q' => ['nullable', 'string', 'max:200'],
        ], self::MESSAGES);
        if (! empty($f['from']) && ! empty($f['to']) && $f['from'] > $f['to']) {
            throw new DomainRuleException('تاريخ البداية يجب أن يسبق تاريخ النهاية');
        }

        return response()->json(['data' => $this->monitoring->report($user, $f)]);
    }
}
