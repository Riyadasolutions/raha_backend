<?php

namespace Modules\Access\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Access\Services\AccessGuard;
use Modules\Access\Services\RoleService;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles, private readonly AccessGuard $guard) {}

    /** GET /permissions — catalog + groups + labels (roles.view). */
    public function permissions(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'roles.view');

        return response()->json([
            'data' => PermissionResolver::catalog(),
            'groups' => raha('rbac.permissionGroups', []),
            'scopeLabels' => raha('rbac.scopeLabels', []),
            'sensitivityLabels' => raha('rbac.sensitivityLabels', []),
            'securityEventLabels' => raha('rbac.securityEventLabels', []),
            'adminPermission' => PermissionResolver::ADMIN_PERMISSION,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'roles.view');
        $counts = DB::table('role_user')->select('role_id', DB::raw('count(*) as n'))->groupBy('role_id')->pluck('n', 'role_id');
        $rows = Role::query()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Role $r) => $r->toFront() + ['userCount' => (int) ($counts[$r->id] ?? 0)]);

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard->need($request->user(), 'roles.view');

        return response()->json(['data' => $this->present($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $role = $this->roles->save($data, $request->user(), null, $data['assignUserIds'] ?? []);

        return response()->json(['data' => $this->present($role)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request);
        $role = $this->roles->save($data, $request->user(), $id, $data['assignUserIds'] ?? []);

        return response()->json(['data' => $this->present($role)]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,inactive']], ['status' => 'حالة الدور غير صحيحة.']);

        return response()->json(['data' => $this->present($this->roles->setStatus($id, $data['status'], $request->user()))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $replacement = $request->query('replacementId');
        $moved = $this->roles->remove($id, $request->user(), is_string($replacement) && $replacement !== '' ? $replacement : null);

        return response()->json(['message' => 'تم حذف الدور', 'moved' => $moved]);
    }

    /** GET /roles/compare?a=&b= */
    public function compare(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'roles.view');
        $data = $request->validate(['a' => ['required', 'string'], 'b' => ['required', 'string']], ['required' => 'اختر دورين للمقارنة.']);
        $a = $this->find($data['a']);
        $b = $this->find($data['b']);

        return response()->json(['data' => RoleService::compare($a, $b), 'a' => $a->toFront(), 'b' => $b->toFront()]);
    }

    private function find(string $id): Role
    {
        return Role::query()->find($id) ?? throw DomainRuleException::notFound('الدور غير موجود.');
    }

    private function present(Role $role): array
    {
        $users = $role->users()->get();

        return $role->toFront() + [
            'userCount' => $users->count(),
            'userIds' => $users->pluck('id')->values(),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nameAr' => ['required', 'string', 'max:120'], 'nameEn' => ['nullable', 'string', 'max:120'], 'code' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:1000'], 'status' => ['nullable', 'in:active,inactive'],
            'permissions' => ['present', 'array'], 'workflowRole' => ['nullable', 'string'],
            'assignUserIds' => ['nullable', 'array'], 'assignUserIds.*' => ['string'],
        ], [
            'nameAr.required' => 'اسم الدور مطلوب (3 أحرف على الأقل).', 'code.required' => 'رمز الدور يجب أن يكون بأحرف إنجليزية كبيرة وشرطة سفلية، مثل AUDIT_SUPERVISOR.',
            'permissions.present' => 'الصلاحيات مطلوبة.', 'permissions.array' => 'الصلاحيات غير صحيحة.',
        ]);
    }
}
