<?php

namespace Modules\Cases\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseActions;
use Modules\Cases\Services\CaseProjector;
use Modules\Cases\Services\CaseService;
use Modules\Cases\Services\DocumentService;
use Modules\Cases\Services\StageStatistics;
use Modules\Cases\Services\StatusLookupService;
use Modules\Cases\Services\WorkflowService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff + provider case endpoints (account:STAFF,PROVIDER). */
class CaseController extends Controller
{
    public const MESSAGES = [
        'required' => 'أكمل الحقول المطلوبة.', 'string' => 'قيمة غير صالحة.', 'array' => 'قيمة غير صالحة.', 'boolean' => 'قيمة غير صالحة.',
        'max' => 'النص أطول من المسموح.', 'date_format' => 'صيغة التاريخ غير صحيحة.', 'regex' => 'صيغة الوقت غير صحيحة.', 'in' => 'قيمة غير صالحة.',
        'numeric' => 'أدخل مبلغًا صحيحًا.', 'integer' => 'قيمة غير صالحة.', 'between' => 'قيمة غير صالحة.',
    ];

    public function __construct(
        private CaseAccess $access, private CaseService $cases, private CaseProjector $projector, private CaseActions $actions,
        private WorkflowService $workflow, private DocumentService $documents, private PermissionResolver $perms,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['group' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:200'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'includeArchived' => ['nullable', 'boolean']], self::MESSAGES);
        $user = $request->user();

        return response()->json(['data' => $this->cases->list($user, $f)->map(fn (RahaCase $c) => $this->projector->forUser($c, $user, true))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->projector->forUser($this->access->findAccessible($id, $user), $user)]);
    }

    public function actions(Request $request, string $id): JsonResponse
    {
        $c = $this->access->findAccessible($id, $request->user());

        return response()->json(['data' => array_map([CaseActions::class, 'meta'], $this->actions->actionsForCase($c, $request->user()))]);
    }

    public function perform(Request $request, string $id, string $key): JsonResponse
    {
        $p = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:4000'], 'publicNote' => ['nullable', 'string', 'max:4000'],
            'providerId' => ['nullable', 'string', 'max:32'], 'date' => ['nullable', 'date_format:Y-m-d'], 'time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'fields' => ['nullable', 'array'], 'fields.*' => ['nullable', 'string', 'max:4000'], 'confirmed' => ['nullable', 'boolean'], 'showToBeneficiary' => ['nullable', 'boolean'],
            'correctionFields' => ['nullable', 'array'], 'correctionFields.*' => ['string'], 'docIds' => ['nullable', 'array'], 'docIds.*' => ['string'],
            'targetStage' => ['nullable', 'string', 'max:32'], 'authorizationId' => ['nullable', 'string', 'max:32'],
        ], self::MESSAGES);
        $user = $request->user();
        $c = $this->workflow->perform($id, $key, $user, array_filter($p, fn ($v) => $v !== null));
        if (! $this->access->canAccessCase($c->load('tasks'), $user)) {
            return response()->json(['data' => ['id' => $c->id, 'status' => $c->status], 'actions' => []]);
        }

        return response()->json(['data' => $this->projector->forUser($c, $user), 'actions' => array_map([CaseActions::class, 'meta'], $this->actions->actionsForCase($c, $user))]);
    }

    public function timeline(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->cases->timeline($id, $request->user())->map(fn (CaseActivity $a) => $a->toFront())->values()]);
    }

    public function comment(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['text' => ['required', 'string', 'max:4000'], 'visibility' => ['required', Rule::in(['internal', 'beneficiary'])]], ['text.required' => 'اكتب الملاحظة أولًا.'] + self::MESSAGES);
        $user = $request->user();

        return response()->json(['data' => $this->projector->forUser($this->cases->addComment($id, $user, $d['text'], $d['visibility']), $user)], 201);
    }

    public function uploadDocument(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'uploadId' => ['required', 'string'], 'category' => ['required', 'string', 'max:120'], 'docType' => ['nullable', 'string', 'max:64'],
            'visibility' => ['nullable', Rule::in(['internal', 'beneficiary', 'both'])],
        ], self::MESSAGES);
        $this->staffOnly($request);
        $doc = $this->documents->staffUpload($id, $request->user(), $d);

        return response()->json(['data' => $doc->toFront()], 201);
    }

    public function deleteDocument(Request $request, string $id, string $docId): JsonResponse
    {
        $this->staffOnly($request);
        $this->documents->remove($id, $docId, $request->user());

        return response()->json(['data' => ['id' => $docId, 'deleted' => true]]);
    }

    public function reviewDocument(Request $request, string $id, string $docId): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(array_keys(DocumentService::REVIEW_LABELS))]], self::MESSAGES);

        return response()->json(['data' => $this->documents->review($id, $docId, $d['status'], $request->user())->toFront()]);
    }

    public function download(Request $request, string $id, string $docId): StreamedResponse
    {
        return $this->documents->download($id, $docId, $request->user());
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric']], ['amount.required' => 'أدخل مبلغًا صحيحًا.'] + self::MESSAGES);
        $user = $request->user();

        return response()->json(['data' => $this->projector->forUser($this->cases->uploadInvoice($id, $user, (float) $d['amount']), $user)]);
    }

    public function stageStatistics(Request $request, StageStatistics $stats): JsonResponse
    {
        return response()->json(['data' => $stats->all($request->user())]);
    }

    public function statusLookup(Request $request, StatusLookupService $lookup): JsonResponse
    {
        $r = $lookup->lookup($request->user(), (string) $request->query('number', ''));
        if ($r['outcome'] === 'denied') {
            throw DomainRuleException::forbidden();
        }

        return response()->json(['data' => $r]);
    }

    private function staffOnly(Request $request): void
    {
        if (! $request->user()->isStaff()) {
            throw DomainRuleException::forbidden();
        }
    }
}
