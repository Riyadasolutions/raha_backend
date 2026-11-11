<?php

namespace Modules\Cases\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseProjector;
use Modules\Cases\Services\Requirements\RequirementsEngine;
use Modules\Cases\Services\ReviewValidator;
use Modules\Cases\Services\TaskService;

/** Review-stage tasks (account:STAFF): views, claim / release / reassign, eligible users. */
class TaskController extends Controller
{
    public function __construct(private TaskService $tasks, private CaseProjector $projector, private CaseAccess $access, private \App\Support\Permissions\PermissionResolver $perms) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['view' => ['nullable', Rule::in(['my', 'queue', 'completed', 'downstream'])]], ['in' => 'قيمة غير صالحة.']);
        $user = $request->user();
        $rows = $this->tasks->view($d['view'] ?? 'my', $user)->map(function (RahaCase $c) use ($user) {
            $row = $this->projector->forStaff($c, $user, true);
            $svc = RequirementsEngine::resolveService($c->service_id, $c->service);
            $results = RequirementsEngine::evaluate($svc, $c->social ?? [], ReviewValidator::docArrays($c->documents), null,
                $c->requirements !== null ? array_column($c->requirements, 'docType') : null, iso($c->created_at));
            $required = array_values(array_filter($results, fn ($r) => $r['required']));
            $complete = count(array_filter($required, fn ($r) => $r['satisfied']));
            $row['requirementSummary'] = ['required' => count($required), 'complete' => $complete, 'missing' => count($required) - $complete];

            return $row;
        });

        return response()->json(['data' => $rows->values()]);
    }

    public function claim(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->tasks->claim($id, $request->user()));
    }

    public function release(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->tasks->release($id, $request->user()));
    }

    public function reassign(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['userId' => ['required', 'string']], ['userId.required' => 'اختر موظفًا مؤهلًا لهذه المرحلة.']);

        return $this->respond($request, $this->tasks->reassign($id, $request->user(), $d['userId']));
    }

    public function eligibleUsers(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $c = RahaCase::query()->with('tasks')->find($id);
        // Same access rule as reassign: case access, or task managers with ALL case scope.
        if (! $c || (! $this->access->canAccessCase($c, $user) && ! ($this->perms->has($user, 'tasks.manage') && $this->perms->scopeOf($user, 'cases.view') === 'ALL'))) {
            throw \App\Exceptions\DomainRuleException::notFound('الطلب غير موجود.');
        }

        return response()->json(['data' => $this->tasks->eligibleUsers($c)->map(fn (User $u) => $u->toDemoUser())->values()]);
    }

    private function respond(Request $request, RahaCase $c): JsonResponse
    {
        $user = $request->user();
        $c->load('tasks');
        $data = $this->access->canAccessCase($c, $user) ? $this->projector->forStaff($c, $user) : ['id' => $c->id, 'status' => $c->status];

        return response()->json(['data' => $data]);
    }
}
