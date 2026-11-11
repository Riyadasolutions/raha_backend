<?php

namespace Modules\Cases\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseProjector;
use Modules\Cases\Services\FastTrackService;
use Modules\Cases\Services\PortalService;
use Modules\Cases\Services\SubmissionService;

/** Beneficiary portal requests (account:BENEFICIARY). Only own requests, beneficiary projection only. */
class PortalController extends Controller
{
    public function __construct(private CaseAccess $access, private CaseProjector $projector, private SubmissionService $submission, private PortalService $portal) {}

    /** Shared submit payload rules (portal + staff on behalf). */
    public static function submitRules(): array
    {
        return [
            'serviceId' => ['required', 'string', 'max:32'], 'subservice' => ['nullable', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:4000'],
            'previouslyReceived' => ['nullable', 'string', 'max:40'], 'medical' => ['nullable', 'array'], 'medical.*' => ['nullable', 'string', 'max:2000'],
            'need' => ['nullable', 'string', 'max:2000'], 'requestFor' => ['nullable', Rule::in(['self', 'family'])],
            'relationship' => ['nullable', 'required_if:requestFor,family', Rule::in(raha('request_form.relationships', []))],
            'patient' => ['nullable', 'array'], 'patient.fullName' => ['nullable', 'required_with:patient', 'string', 'max:200'], 'patient.*' => ['nullable', 'string', 'max:200'],
            'social' => ['nullable', 'array'], 'social.*' => ['nullable', 'string', 'max:200'],
            'documents' => ['nullable', 'array', 'max:30'], 'documents.*.uploadId' => ['required', 'string'], 'documents.*.docType' => ['nullable', 'string', 'max:64'],
            'documents.*.reportDate' => ['nullable', 'date_format:Y-m-d'], 'documents.*.label' => ['nullable', 'string', 'max:120'],
        ];
    }

    public static function submitMessages(): array
    {
        return ['serviceId.required' => 'اختر الخدمة.', 'relationship.required_if' => 'يرجى اختيار صلة القرابة', 'relationship.in' => 'يرجى اختيار صلة القرابة'] + CaseController::MESSAGES;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->access->visibleCases($request->user(), true)->map(fn (RahaCase $c) => $this->projector->forBeneficiary($c))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->projector->forBeneficiary($this->access->findAccessible($id, $request->user()))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::submitRules(), self::submitMessages());

        return response()->json(['data' => $this->projector->forBeneficiary($this->submission->submit($request->user(), $data))], 201);
    }

    public function resubmit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:4000'], 'recipient' => ['nullable', 'array'], 'recipient.*' => ['nullable', 'string', 'max:200'],
            'medical' => ['nullable', 'array'], 'medical.*' => ['nullable', 'string', 'max:2000'], 'social' => ['nullable', 'array'], 'social.*' => ['nullable', 'string', 'max:200'],
            'keepDocIds' => ['nullable', 'array'], 'keepDocIds.*' => ['string'], 'documents' => ['nullable', 'array', 'max:30'], 'documents.*.uploadId' => ['required', 'string'],
            'documents.*.docType' => ['nullable', 'string', 'max:64'], 'documents.*.reportDate' => ['nullable', 'date_format:Y-m-d'], 'documents.*.label' => ['nullable', 'string', 'max:120'],
        ], CaseController::MESSAGES);
        $changes = array_filter($data, fn ($v) => $v !== null);

        return response()->json(['data' => $this->projector->forBeneficiary($this->submission->resubmit($id, $request->user(), $changes))]);
    }

    public function pendingSurvey(Request $request): JsonResponse
    {
        $c = $this->submission->pendingSurvey($request->user());

        return response()->json(['data' => $c ? $this->projector->forBeneficiary($c) : null]);
    }

    public function survey(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['ease' => ['required', 'integer', 'between:1,5'], 'clarity' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']], CaseController::MESSAGES);
        $c = $this->portal->submitSurvey($id, $request->user(), (int) $d['ease'], (int) $d['clarity'], (string) ($d['comment'] ?? ''));

        return response()->json(['data' => $this->projector->forBeneficiary($c)]);
    }

    public function confirmService(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->projector->forBeneficiary($this->portal->confirmService($id, $request->user()))]);
    }

    public function evaluate(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']], CaseController::MESSAGES);
        $c = $this->portal->evaluate($id, $request->user(), (int) $d['rating'], (string) ($d['comment'] ?? ''));

        return response()->json(['data' => $this->projector->forBeneficiary($c)]);
    }

    /** Own non-internal document file. */
    public function download(Request $request, string $id, string $docId, \Modules\Cases\Services\DocumentService $documents): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $documents->download($id, $docId, $request->user());
    }

    public function appointments(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->appointments($request->user())]);
    }

    public function fastTrack(Request $request, FastTrackService $fastTrack): JsonResponse
    {
        return response()->json(['data' => $fastTrack->publicEligibility($request->user())]);
    }
}
