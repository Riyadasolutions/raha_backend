<?php

namespace Modules\Users\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Permissions\PermissionResolver;
use App\Support\Security\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Access\Services\AccessGuard;
use Modules\Users\Services\UserService;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users, private readonly AccessGuard $guard) {}

    public function index(Request $request): JsonResponse
    {
        $this->guard->need($request->user(), 'users.view');
        $rows = User::query()->whereIn('account_type', ['STAFF', 'PROVIDER'])->with('roles')->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (User $u) => UserService::present($u))->values()]);
    }

    /** GET /users/{id} — account + sessions + recent security events + audit rows by this user + effective grants. */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard->need($request->user(), 'users.view');
        $u = $this->users->find($id);
        $roleNames = Role::query()->pluck('name_ar', 'id');
        $grants = collect(PermissionResolver::grantsFor($u->roles))->map(fn ($g, $code) => [
            'code' => $code, 'scope' => $g['scope'], 'sources' => array_map(fn ($rid) => $roleNames[$rid] ?? $rid, $g['sources']),
        ])->values();

        return response()->json([
            'data' => UserService::present($u),
            'grants' => $grants,
            'sessions' => $this->sessionsOf($u->id),
            'securityEvents' => SecurityEvent::query()->where('user_id', $u->id)->orderByDesc('at')->orderByDesc('id')->limit(200)->get()->map->toFront()->values(),
            'audit' => AuditLog::query()->where(fn ($q) => $q->where('user_id', $u->id)->orWhere(fn ($q) => $q->whereNull('user_id')->where('user', $u->name)))
                ->orderByDesc('at')->orderByDesc('id')->limit(50)->get()->map->toFront()->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$user, $temporary] = $this->users->save($this->validated($request), $request->user());

        return response()->json(['data' => UserService::present($user), 'temporaryPassword' => $temporary], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$user] = $this->users->save($this->validated($request), $request->user(), $id);

        return response()->json(['data' => UserService::present($user)]);
    }

    public function removeRole(Request $request, string $id, string $roleId): JsonResponse
    {
        return response()->json(['data' => UserService::present($this->users->removeRole($id, $roleId, $request->user()))]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        return $this->done($this->users->setSuspended($id, true, $request->user()), 'إيقاف الحساب');
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->done($this->users->setSuspended($id, false, $request->user()), 'تفعيل الحساب');
    }

    public function lock(Request $request, string $id): JsonResponse
    {
        return $this->done($this->users->lock($id, $request->user()), 'قفل الحساب');
    }

    public function unlock(Request $request, string $id): JsonResponse
    {
        return $this->done($this->users->unlock($id, $request->user()), 'إلغاء القفل');
    }

    public function forcePasswordChange(Request $request, string $id): JsonResponse
    {
        return $this->done($this->users->forcePasswordChange($id, $request->user()), 'إجبار تغيير كلمة المرور');
    }

    public function resetMfa(Request $request, string $id): JsonResponse
    {
        return $this->done($this->users->resetMfa($id, $request->user()), 'إعادة تعيين إعدادات المصادقة');
    }

    public function endSessions(Request $request, string $id): JsonResponse
    {
        $count = $this->users->endAllSessions($id, $request->user());

        return response()->json(['count' => $count, 'data' => UserService::present($this->users->find($id)), 'message' => 'تم: إنهاء جميع الجلسات']);
    }

    public function sessions(Request $request, string $id): JsonResponse
    {
        $this->guard->need($request->user(), 'users.view');
        $u = $this->users->find($id);

        return response()->json(['data' => $this->sessionsOf($u->id), 'currentId' => Sessions::current()?->id]);
    }

    private function sessionsOf(string $userId)
    {
        return UserSession::query()->where('user_id', $userId)->where('status', 'active')->orderByDesc('started_at')->get()->map->toFront()->values();
    }

    private function done(User $user, string $label): JsonResponse
    {
        return response()->json(['data' => UserService::present($user), 'message' => "تم: $label"]);
    }

    private function validated(Request $request): array
    {
        // Same rules as the front user_form.tsx validation.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,30}$/i'],
            'email' => ['required', 'string', 'max:190', 'regex:/^\S+@\S+\.\S+$/'], 'mobile' => ['required', 'string', 'regex:/^05\d{8}$/'],
            'department' => ['required', 'string', 'max:120'], 'jobTitle' => ['nullable', 'string', 'max:120'],
            'roleIds' => ['required', 'array', 'min:1'], 'roleIds.*' => ['string', 'exists:roles,id'],
            'active' => ['required', 'boolean'], 'mustChangePassword' => ['required', 'boolean'], 'expiresAt' => ['nullable', 'date', 'after:now'],
            'providerId' => ['nullable', 'string', 'exists:providers,id'],
        ], [
            'name.required' => 'الاسم الكامل مطلوب.', 'username.required' => 'اسم المستخدم بأحرف إنجليزية وأرقام (3–30).',
            'username.regex' => 'اسم المستخدم بأحرف إنجليزية وأرقام (3–30).', 'email.required' => 'أدخل بريدًا إلكترونيًا صحيحًا.',
            'email.regex' => 'أدخل بريدًا إلكترونيًا صحيحًا.', 'mobile.required' => 'رقم الجوال يبدأ بـ 05 ويتكون من 10 أرقام.',
            'mobile.regex' => 'رقم الجوال يبدأ بـ 05 ويتكون من 10 أرقام.', 'department.required' => 'القسم مطلوب.',
            'roleIds.required' => 'اختر دورًا واحدًا على الأقل.', 'roleIds.min' => 'اختر دورًا واحدًا على الأقل.',
            'roleIds.*.exists' => 'الدور غير موجود.', 'providerId.exists' => 'مقدم الخدمة غير موجود.',
            'expiresAt.date' => 'تاريخ الانتهاء يجب أن يكون في المستقبل.', 'expiresAt.after' => 'تاريخ الانتهاء يجب أن يكون في المستقبل.',
        ]);
        if (mb_strlen(trim($data['name'])) < 3) {
            throw new \App\Exceptions\DomainRuleException('الاسم الكامل مطلوب.');
        }
        if (in_array('provider', $data['roleIds'], true) && empty($data['providerId'])) {
            throw new \App\Exceptions\DomainRuleException('اختر مقدم الخدمة المرتبط.');
        }

        return $data;
    }
}
