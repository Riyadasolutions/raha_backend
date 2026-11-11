<?php

namespace Modules\Auth\Http\Controllers;

use App\Enums\AccountType;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityPolicy;
use App\Support\Security\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Auth\Services\AuthService;
use Modules\Auth\Services\PasswordRecoveryService;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth, private readonly PasswordRecoveryService $recovery) {}

    /** POST /auth/login {identity, password, type?: staff|provider} */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'max:200'],
            'type' => ['nullable', 'in:staff,provider,STAFF,PROVIDER'],
        ], ['identity.required' => 'أدخل اسم المستخدم أو البريد الإلكتروني.', 'password.required' => 'أدخل كلمة المرور.']);
        $type = isset($data['type']) ? AccountType::from(strtoupper($data['type'])) : null;

        return response()->json($this->auth->login($data['identity'], $data['password'], $type));
    }

    /** POST /auth/beneficiary/login {nationalId, password} */
    public function beneficiaryLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nationalId' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6,15}$/'], 'password' => ['required', 'string', 'max:200'],
        ], ['nationalId.required' => 'أدخل رقم الهوية.', 'nationalId.regex' => 'رقم الهوية غير صحيح.', 'password.required' => 'أدخل كلمة المرور.']);

        return response()->json($this->auth->beneficiaryLogin($data['nationalId'], $data['password']));
    }

    /** POST /auth/beneficiary/register {firstName, familyName, idType, idNumber, password} */
    public function register(Request $request): JsonResponse
    {
        $types = raha('request_form.idTypes', []);
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:60'], 'familyName' => ['required', 'string', 'max:60'],
            'idType' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys($types))], 'idNumber' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:200'],
        ], ['required' => 'أكمل الحقول المطلوبة.', 'idType.in' => 'نوع الهوية غير صحيح.']);
        $rule = $types[$data['idType']];
        if (! preg_match('/'.$rule['pattern'].'/', mb_strtoupper(trim($data['idNumber'])))) {
            throw new DomainRuleException($rule['message']);
        }
        if (! AuthService::beneficiaryPasswordOk($data['password'])) {
            throw new DomainRuleException('كلمة المرور ضعيفة. استخدم 8 أحرف على الأقل تتضمن حروفًا وأرقامًا ورموزًا.');
        }

        return response()->json($this->auth->register($data), 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = Sessions::current();
        if ($session) {
            Sessions::terminate([$session->id], null, 'تسجيل الخروج');
        }

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->auth->me($request->user()));
    }

    /** PUT /auth/password {current, next} */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current' => ['required', 'string'], 'next' => ['required', 'string', 'max:200']], ['required' => 'أكمل الحقول المطلوبة.']);
        $this->auth->changePassword($request->user(), $data['current'], $data['next']);

        return response()->json(['message' => 'تم تغيير كلمة المرور.', 'me' => $this->auth->me($request->user()->fresh())]);
    }

    /** PUT /auth/mfa {enabled} — own MFA preference, bounded by the policy. */
    public function mfa(Request $request): JsonResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $user = $request->user();
        if ($user->isBeneficiary()) {
            throw DomainRuleException::forbidden();
        }
        $policy = SecurityPolicy::get();
        if ($enabled && $policy['mfaPolicy'] === 'off') {
            throw new DomainRuleException('المصادقة متعددة العوامل غير مفعلة في سياسة الأمان.');
        }
        if (! $enabled && SecurityPolicy::mfaRequired($user)) {
            throw new DomainRuleException('المصادقة متعددة العوامل إلزامية لحسابك حسب سياسة الأمان.');
        }
        if ($user->mfa_enabled !== $enabled) {
            $user->forceFill(['mfa_enabled' => $enabled])->save();
            SecurityLog::log($enabled ? 'MFA_ENABLED' : 'MFA_DISABLED', $user);
        }

        return response()->json($this->auth->me($user->fresh()));
    }

    public function sessions(Request $request): JsonResponse
    {
        $rows = UserSession::query()->where('user_id', $request->user()->id)->where('status', 'active')->orderByDesc('started_at')->get();

        return response()->json(['data' => $rows->map->toFront(), 'currentId' => Sessions::current()?->id]);
    }

    public function endOtherSessions(Request $request): JsonResponse
    {
        $count = Sessions::endAllFor($request->user(), $request->user(), 'إنهاء الجلسات الأخرى', Sessions::current()?->id);

        return response()->json(['count' => $count]);
    }

    /** DELETE /auth/sessions/{id} — end one of the caller's own sessions. */
    public function endOwnSession(Request $request, string $sessionId): JsonResponse
    {
        $owned = UserSession::query()->where('id', $sessionId)->where('user_id', $request->user()->id)->exists();
        if (! $owned) {
            throw DomainRuleException::notFound('الجلسة غير موجودة.');
        }

        return response()->json(['count' => Sessions::terminate([$sessionId], $request->user(), 'إنهاء جلسة بواسطة المستخدم')]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']], ['email' => 'أدخل بريدًا إلكترونيًا صحيحًا.', 'required' => 'أدخل بريدًا إلكترونيًا صحيحًا.']);

        return response()->json($this->recovery->requestStaff($data['email']));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed']],
            ['required' => 'أكمل الحقول المطلوبة.', 'password.confirmed' => 'كلمتا المرور غير متطابقتين.']);
        $this->recovery->resetStaff($data['email'], $data['token'], $data['password']);

        return response()->json(['message' => 'تم تعيين كلمة المرور الجديدة. يمكنك تسجيل الدخول الآن.']);
    }

    public function beneficiaryForgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['nationalId' => ['required', 'string', 'max:20']], ['required' => 'أدخل رقم الهوية.']);

        return response()->json($this->recovery->requestBeneficiary($data['nationalId']));
    }

    public function beneficiaryResetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['nationalId' => ['required', 'string'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'string', 'confirmed']],
            ['required' => 'أكمل الحقول المطلوبة.', 'code.digits' => 'رمز التحقق مكون من 6 أرقام.', 'password.confirmed' => 'كلمتا المرور غير متطابقتين.']);
        $this->recovery->resetBeneficiary($data['nationalId'], $data['code'], $data['password']);

        return response()->json(['message' => 'تم تعيين كلمة المرور الجديدة. يمكنك تسجيل الدخول الآن.']);
    }

    /** POST /auth/dev/quick-login {role?, userId?} — demo accounts only (config raha.dev_accounts). */
    public function quickLogin(Request $request): JsonResponse
    {
        abort_unless(config('raha.dev_accounts'), 404);
        $data = $request->validate(['role' => ['nullable', 'string'], 'userId' => ['nullable', 'string']]);
        $user = isset($data['userId']) ? User::query()->find($data['userId'])
            : ($data['role'] === 'beneficiary' ? User::query()->where('account_type', 'BENEFICIARY')->orderBy('created_at')->first()
                : User::query()->whereHas('roles', fn ($q) => $q->where('roles.id', $data['role'] ?? ''))->orderBy('id')->first());
        if (! $user) {
            throw new DomainRuleException('الحساب التجريبي غير متاح.');
        }
        // Quick sign-in opens the demo account directly, so seeded password age must not force a change.
        $user->forceFill(['must_change_password' => false, 'password_changed_at' => now()])->save();

        return response()->json($this->auth->signIn($user));
    }

    /** GET /auth/dev/accounts — demo account list for the login page (dev only). */
    public function devAccounts(): JsonResponse
    {
        abort_unless(config('raha.dev_accounts'), 404);
        $users = User::query()->with('roles')->orderBy('created_at')->get()
            ->map(fn (User $u) => $u->toDemoUser() + ['username' => $u->username, 'accountType' => $u->account_type->value]);

        return response()->json(['data' => $users, 'password' => raha('users.demoPassword')]);
    }
}
