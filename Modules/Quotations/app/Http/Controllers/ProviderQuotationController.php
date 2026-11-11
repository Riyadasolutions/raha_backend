<?php

namespace Modules\Quotations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Quotations\Services\ProviderAssessmentService;
use Modules\Quotations\Services\QuotationService;

/** Provider portal (account:PROVIDER): own invitations, quotation draft/submit/decline, beneficiary contact, assessment, dashboard. */
class ProviderQuotationController extends Controller
{
    /** QuotationInput: types only (business validation happens on submit, front validateQuotation). */
    public const QUOTATION_RULES = [
        'description' => ['nullable', 'string', 'max:5000'], 'lineItems' => ['nullable', 'array', 'max:500'], 'lineItems.*' => ['array'],
        'lineItems.*.description' => ['nullable', 'string', 'max:5000'], 'lineItems.*.quantity' => ['nullable', 'numeric'], 'lineItems.*.unit' => ['nullable', 'string', 'max:255'],
        'lineItems.*.unitPrice' => ['nullable', 'numeric'], 'lineItems.*.discountAmount' => ['nullable', 'numeric'],
        'taxTreatment' => ['nullable', 'in:exempt,zero,standard'], 'taxRate' => ['nullable', 'numeric'], 'startWithinDays' => ['nullable', 'numeric'],
        'deliveryDuration' => ['nullable', 'string', 'max:255'], 'validUntil' => ['nullable', 'string', 'max:10'], 'notes' => ['nullable', 'string', 'max:5000'],
        'attachments' => ['nullable', 'array', 'max:50'], 'attachments.*' => ['array'], 'attachments.*.name' => ['nullable', 'string', 'max:255'],
        'attachments.*.size' => ['nullable', 'numeric'], 'attachments.*.type' => ['nullable', 'string', 'max:128'], 'attachments.*.uploadId' => ['nullable', 'string', 'max:64'],
    ];

    public function __construct(private QuotationService $quotations, private ProviderAssessmentService $assessments) {}

    private function input(Request $request, array $extra = []): array
    {
        $d = $request->validate(self::QUOTATION_RULES + $extra, ['max' => 'قيمة غير صالحة.'] + QuotationRoundController::MESSAGES);
        // validated() rebuilds nested arrays rule by rule: restore the client's item order.
        foreach (['lineItems', 'attachments'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) {
                ksort($d[$k]);
            }
        }

        return $d;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quotations->forProvider($request->user())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->quotations->invitation($id, $request->user())]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quotations->dashboard($request->user())]);
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:2000'], 'confirmed' => ['nullable', 'boolean']], QuotationRoundController::MESSAGES);

        return response()->json(['data' => $this->quotations->decline($id, (string) ($d['reason'] ?? ''), (bool) ($d['confirmed'] ?? false), $request->user())]);
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $this->quotations->saveDraft($id, $this->input($request), $request->user());

        return response()->json(['data' => $this->quotations->invitation($id, $request->user())]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $d = $this->input($request, ['confirmed' => ['nullable', 'boolean']]);
        $this->quotations->submit($id, $d, (bool) ($d['confirmed'] ?? false), $request->user());

        return response()->json(['data' => $this->quotations->invitation($id, $request->user())]);
    }

    public function contact(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->assessments->contact($id, $request->user())]);
    }

    public function assessment(Request $request, string $id): JsonResponse
    {
        $m = $this->assessments->mine($id, $request->user());

        return response()->json(['data' => $m['assessment'], 'canEdit' => $m['canEdit']]);
    }

    public function saveAssessment(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'contactStatus' => ['nullable', 'string'], 'assessedAt' => ['nullable', 'string', 'max:32'], 'method' => ['nullable', 'string'],
            'needsNotes' => ['nullable', 'string'], 'recommendedScope' => ['nullable', 'string'], 'recommendedVisits' => ['nullable', 'numeric'], 'comments' => ['nullable', 'string'],
        ], QuotationRoundController::MESSAGES);
        $a = $this->assessments->save($id, $d, $request->user());

        return response()->json(['data' => $a->toFront(), 'canEdit' => $this->assessments->mine($id, $request->user())['canEdit']]);
    }
}
