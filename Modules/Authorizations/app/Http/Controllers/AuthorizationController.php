<?php

namespace Modules\Authorizations\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Authorizations\Services\AuthorizationDocument;
use Modules\Authorizations\Services\AuthorizationService;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Finance\Models\FinanceEvent;

/** Authorization letters (staff) + the read-only letter document model (staff and providers). */
class AuthorizationController extends Controller
{
    public const MESSAGES = [
        'required' => 'أكمل الحقول المطلوبة.', 'string' => 'قيمة غير صالحة.', 'numeric' => 'قيمة غير صالحة.', 'in' => 'قيمة غير صالحة.', 'max' => 'النص أطول من المسموح.',
    ];

    public function __construct(
        private AuthorizationService $authorizations, private AuthorizationDocument $documents, private PermissionResolver $perms, private CaseAccess $access,
    ) {}

    private function letterInput(Request $request): array
    {
        return $request->validate([
            'description' => ['nullable', 'string', 'max:5000'], 'quantity' => ['nullable', 'numeric'], 'unit' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'string', 'max:20000'], 'expectedStart' => ['nullable', 'string', 'max:32'], 'reason' => ['nullable', 'string', 'max:2000'],
        ], self::MESSAGES);
    }

    private function present(ServiceAuthorization $l): array
    {
        return $l->toFront() + ['statusLabel' => raha("finance.authorizationStatusLabels.{$l->status}", $l->status)];
    }

    private function requireView(Request $request): void
    {
        if (! $this->perms->has($request->user(), 'authorization.view')) {
            throw DomainRuleException::forbidden();
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireView($request);
        $ids = $this->access->visibleCases($request->user())->pluck('id');
        $rows = ServiceAuthorization::query()->whereIn('case_id', $ids)->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($l) => $this->present($l))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->requireView($request);
        $l = ServiceAuthorization::query()->find($id);
        $c = $l ? RahaCase::query()->find($l->case_id) : null;
        if (! $l || ! $c || ! $this->access->canAccessCase($c, $request->user())) {
            throw DomainRuleException::notFound('السجل غير موجود.');
        }
        $numbers = ServiceAuthorization::query()->whereIn('id', array_filter([$l->replaces_id, $l->replaced_by_id]))->pluck('authorization_number', 'id');

        return response()->json(['data' => [
            'letter' => $this->present($l),
            'assignment' => ServiceAssignment::query()->where('authorization_id', $l->id)->first()?->toFront(),
            'events' => FinanceEvent::query()->where('case_id', $l->case_id)->orderByDesc('at')->orderByDesc('id')->get()->map->toFront()->values(),
            'replacesNumber' => $numbers[$l->replaces_id ?? ''] ?? null, 'replacedByNumber' => $numbers[$l->replaced_by_id ?? ''] ?? null,
            'caseStatus' => $c->status,
        ]]);
    }

    public function blockers(Request $request, string $caseId): JsonResponse
    {
        $this->requireView($request);

        return response()->json(['data' => $this->authorizations->blockers($this->access->findAccessible($caseId, $request->user()))]);
    }

    public function preview(Request $request, string $caseId): JsonResponse
    {
        $lang = $request->validate(['lang' => ['nullable', Rule::in(['ar', 'en'])]], self::MESSAGES)['lang'] ?? 'ar';

        return response()->json(['data' => $this->documents->draftPreview($caseId, $this->letterInput($request), $request->user(), $lang)]);
    }

    public function issue(Request $request, string $caseId): JsonResponse
    {
        $l = $this->authorizations->issue($caseId, $this->letterInput($request), $request->user());

        return response()->json(['data' => $this->present($l)], 201);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $d = $this->letterInput($request);
        $l = $this->authorizations->replace($id, $d, $d['reason'] ?? null, $request->user());

        return response()->json(['data' => $this->present($l)], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:2000']], self::MESSAGES);

        return response()->json(['data' => $this->present($this->authorizations->cancel($id, $d['reason'] ?? null, $request->user()))]);
    }

    public function assign(Request $request, string $caseId): JsonResponse
    {
        return response()->json(['data' => $this->authorizations->assign($caseId, $request->user())->toFront()], 201);
    }

    public function document(Request $request, string $id): JsonResponse
    {
        $lang = $request->validate(['lang' => ['nullable', Rule::in(['ar', 'en'])]], self::MESSAGES)['lang'] ?? 'ar';

        return response()->json(['data' => $this->documents->forLetter($id, $request->user(), $lang)]);
    }
}
