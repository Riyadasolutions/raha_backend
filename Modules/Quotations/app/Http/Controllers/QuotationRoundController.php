<?php

namespace Modules\Quotations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cases\Models\RahaCase;
use Modules\Quotations\Models\QuotationRound;
use Modules\Quotations\Services\ProviderAssessmentService;
use Modules\Quotations\Services\QuotationPresenter;
use Modules\Quotations\Services\QuotationService;

/** Staff quotation rounds (account:STAFF). Port of the staff half of front quotation_service.ts. */
class QuotationRoundController extends Controller
{
    public const MESSAGES = [
        'required' => 'يرجى استكمال الحقول الإلزامية.', 'string' => 'قيمة غير صالحة.', 'array' => 'قيمة غير صالحة.', 'boolean' => 'قيمة غير صالحة.',
        'numeric' => 'قيمة غير صالحة.', 'in' => 'قيمة غير صالحة.', 'max' => 'النص أطول من المسموح.',
    ];

    public function __construct(private QuotationService $quotations, private ProviderAssessmentService $assessments) {}

    private function respond(QuotationRound $r, int $status = 200): JsonResponse
    {
        return response()->json(['data' => QuotationPresenter::staff($r, RahaCase::query()->find($r->case_id))], $status);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quotations->forStaff($request->user())]);
    }

    public function forCase(Request $request, string $caseId): JsonResponse
    {
        return response()->json($this->quotations->forCase($caseId, $request->user()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->quotations->showForStaff($id, $request->user())]);
    }

    public function eligibleProviders(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->quotations->eligibleProviders($id, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'caseId' => ['required', 'string', 'max:32'], 'subservice' => ['nullable', 'string', 'max:255'], 'specifications' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'], 'quantity' => ['nullable', 'numeric'], 'unit' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'], 'area' => ['nullable', 'string', 'max:255'], 'expectedStart' => ['nullable', 'string', 'max:32'],
            'deadline' => ['nullable', 'string', 'max:40'], 'providerNotes' => ['nullable', 'string', 'max:5000'],
            'sharedDocIds' => ['nullable', 'array'], 'sharedDocIds.*' => ['string'], 'providerIds' => ['nullable', 'array'], 'providerIds.*' => ['string'],
        ], self::MESSAGES);

        return $this->respond($this->quotations->createRound($d, $d['providerIds'] ?? [], $request->user()), 201);
    }

    public function invite(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['providerIds' => ['nullable', 'array'], 'providerIds.*' => ['string']], self::MESSAGES);

        return $this->respond($this->quotations->invite($id, $d['providerIds'] ?? [], $request->user()));
    }

    public function extendDeadline(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['deadline' => ['nullable', 'string', 'max:40'], 'reason' => ['nullable', 'string', 'max:2000']], self::MESSAGES);

        return $this->respond($this->quotations->extendDeadline($id, $d['deadline'] ?? '', (string) ($d['reason'] ?? ''), $request->user()));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->quotations->close($id, $request->user()));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:2000']], self::MESSAGES);

        return $this->respond($this->quotations->cancel($id, (string) ($d['reason'] ?? ''), $request->user()));
    }

    public function evaluate(Request $request, string $id, string $qid): JsonResponse
    {
        $d = $request->validate([
            'compliance' => ['required', 'in:compliant,noncompliant,clarification'], 'notes' => ['nullable', 'string', 'max:5000'],
            'reasons' => ['nullable', 'string', 'max:5000'], 'recommendation' => ['nullable', 'string', 'max:5000'],
        ], self::MESSAGES);

        return $this->respond($this->quotations->evaluate($id, $qid, $d, $request->user()));
    }

    public function select(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['quotationId' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:2000'], 'confirmed' => ['nullable', 'boolean']], self::MESSAGES);

        return $this->respond($this->quotations->select($id, $d['quotationId'], (string) ($d['reason'] ?? ''), (bool) ($d['confirmed'] ?? false), $request->user()));
    }

    public function assessments(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->assessments->forStaff($id, $request->user())]);
    }
}
