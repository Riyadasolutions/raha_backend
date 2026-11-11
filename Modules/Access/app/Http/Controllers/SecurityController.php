<?php

namespace Modules\Access\Http\Controllers;

use App\Models\Role;
use App\Models\UserSession;
use App\Support\Security\SecurityPolicy;
use App\Support\Security\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Access\Services\AccessGuard;
use Modules\Access\Services\SecurityAdminService;

/** Security policy, security log and active sessions administration (front security_service.ts + users_roles tabs). */
class SecurityController extends Controller
{
    public function __construct(private readonly SecurityAdminService $security, private readonly AccessGuard $guard) {}

    public function policy(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'security.view');

        return response()->json(['data' => SecurityPolicy::get(), 'labels' => SecurityAdminService::POLICY_LABELS]);
    }

    /** PUT /security-policy — the mock had no permission check; security.manage is required here. */
    public function savePolicy(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'security.manage');
        $rules = ['mfaPolicy' => ['sometimes', 'in:off,optional,all,roles'], 'mfaRequiredRoles' => ['sometimes', 'array'],
            'mfaRequiredRoles.*' => ['string', Rule::in(Role::query()->pluck('id')->all())]];
        foreach (SecurityAdminService::BOOL_KEYS as $k) {
            $rules[$k] = ['sometimes', 'boolean'];
        }
        foreach (SecurityAdminService::INT_KEYS as $k) {
            $rules[$k] = ['sometimes', 'integer', 'min:0', 'max:100000'];
        }
        $data = $request->validate($rules, ['boolean' => 'قيمة غير صحيحة في سياسة الأمان.', 'integer' => 'قيمة غير صحيحة في سياسة الأمان.',
            'min' => 'قيمة غير صحيحة في سياسة الأمان.', 'max' => 'قيمة غير صحيحة في سياسة الأمان.', 'in' => 'قيمة غير صحيحة في سياسة الأمان.']);
        [$policy, $changed] = $this->security->savePolicy($data, $request->user());

        return response()->json(['data' => $policy, 'changed' => $changed, 'message' => $changed ? 'تم حفظ سياسة الأمان' : 'لا توجد تغييرات']);
    }

    /** GET /security-log?q=&type=&result=&from=&to=&userId= (audit.view) */
    public function log(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'audit.view');
        $f = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', 'string', 'max:48'], 'result' => ['nullable', 'in:SUCCESS,FAILURE'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'userId' => ['nullable', 'string', 'max:64']]);

        return response()->json(['data' => $this->security->log($f)->map->toFront()->values()]);
    }

    /** GET /sessions — active sessions of all users (users.manageSecurity or security.view). */
    public function sessions(Request $request): JsonResponse
    {
        $this->guard->needAny($request->user(), ['users.manageSecurity', 'security.view']);
        $rows = UserSession::query()->where('status', 'active')
            ->when($request->query('userId'), fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('started_at')->get();

        return response()->json(['data' => $rows->map->toFront()->values(), 'currentId' => Sessions::current()?->id]);
    }

    /** POST /sessions/terminate {ids[]} (users.manageSecurity) */
    public function terminate(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'users.manageSecurity');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['string']], ['required' => 'اختر جلسة واحدة على الأقل.']);
        $count = Sessions::terminate($data['ids'], $request->user());

        return response()->json(['count' => $count, 'message' => 'تم إنهاء الجلسة']);
    }
}
