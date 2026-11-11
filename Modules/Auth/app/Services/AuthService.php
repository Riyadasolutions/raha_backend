<?php

namespace Modules\Auth\Services;

use App\Enums\AccountType;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityPolicy;
use App\Support\Security\Sessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Beneficiaries\Models\Beneficiary;

/**
 * Sign-in for the three portals (port of front auth_service.ts + user_service lockout rules).
 * Unlike the mock, beneficiaries get the same lockout, security log and session handling as staff.
 */
class AuthService
{
    public const LOCKED_MSG = 'تم قفل الحساب مؤقتًا بسبب تكرار محاولات تسجيل الدخول غير الصحيحة.';

    public const GENERIC_MSG = 'تعذر تسجيل الدخول. تحقق من بيانات الدخول وحاول مرة أخرى.';

    public const BENEFICIARY_MSG = 'رقم الهوية أو كلمة المرور غير صحيحة.';

    public const UNAVAILABLE_MSG = 'الحساب غير متاح. تواصل مع مدير النظام.';

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** Staff / provider login by username or email. $type = STAFF | PROVIDER (portal selected on /login). */
    public function login(string $identity, string $password, ?AccountType $type): array
    {
        $needle = mb_strtolower(trim($identity));
        $user = User::query()->whereIn('account_type', ['STAFF', 'PROVIDER'])
            ->where(fn ($q) => $q->whereRaw('lower(username) = ?', [$needle])->orWhereRaw('lower(email) = ?', [$needle]))->first();
        if (! $user) {
            SecurityLog::log('LOGIN_FAILED', userName: mb_substr(trim($identity), 0, 40), result: 'FAILURE');
            throw new DomainRuleException(self::GENERIC_MSG, 401);
        }

        return $this->attempt($user, $password, self::GENERIC_MSG, $type);
    }

    public function beneficiaryLogin(string $nationalId, string $password): array
    {
        $user = User::query()->where('account_type', 'BENEFICIARY')->where('national_id', mb_strtoupper(trim($nationalId)))->first();
        if (! $user) {
            SecurityLog::log('LOGIN_FAILED', userName: 'مستفيد', result: 'FAILURE', details: ['note' => 'رقم هوية غير مسجل']);
            throw new DomainRuleException(self::BENEFICIARY_MSG, 401);
        }

        return $this->attempt($user, $password, self::BENEFICIARY_MSG, AccountType::Beneficiary);
    }

    private function attempt(User $user, string $password, string $genericMsg, ?AccountType $expected): array
    {
        if ($user->isLocked()) {
            return $this->signIn($user); // throws LOCKED_MSG (checked before the password)
        }
        if (! Hash::check($password, $user->password)) {
            throw new DomainRuleException($this->registerFailed($user) ? self::LOCKED_MSG : $genericMsg, 401);
        }
        if ($expected && $user->account_type !== $expected) {
            SecurityLog::log('LOGIN_FAILED', $user, result: 'FAILURE', details: ['note' => 'نوع الحساب غير مطابق']);
            $hint = config('raha.dev_accounts') ? ' (للتجربة: نوع الحساب المختار لا يطابق هذا الحساب.)' : '';
            throw new DomainRuleException($genericMsg.$hint, 401);
        }

        return $this->signIn($user);
    }

    /** Lock / availability checks, then issue a token + session (front staffSignIn). */
    public function signIn(User $user): array
    {
        if ($user->locked_at && ! $user->isLocked()) {
            $user->forceFill(['locked_at' => null, 'locked_until' => null, 'failed_attempts' => 0])->save();
        }
        if ($user->isLocked()) {
            SecurityLog::log('LOGIN_FAILED', $user, result: 'FAILURE', details: ['note' => 'الحساب مقفل']);
            throw new DomainRuleException(self::LOCKED_MSG, 423);
        }
        if (! $user->active || $user->suspended || ($user->expires_at && $user->expires_at->isPast())) {
            SecurityLog::log('LOGIN_FAILED', $user, result: 'FAILURE', details: ['note' => 'الحساب غير متاح']);
            throw new DomainRuleException(self::UNAVAILABLE_MSG, 403);
        }

        return DB::transaction(function () use ($user) {
            $user->forceFill(['failed_attempts' => 0, 'last_login_at' => now()])->save();
            SecurityLog::log('LOGIN_SUCCESS', $user);
            if (SecurityPolicy::passwordStatus($user)['expired']) {
                SecurityLog::log('PASSWORD_EXPIRED', $user);
            }
            [$token, $session] = Sessions::start($user);

            return ['token' => $token, 'sessionId' => $session->id, 'me' => $this->me($user->fresh(), $session->id)];
        });
    }

    /** Returns true when this failure locked the account. */
    public function registerFailed(User $user): bool
    {
        $policy = SecurityPolicy::get();
        $attempts = $user->failed_attempts + 1;
        SecurityLog::log('LOGIN_FAILED', $user, result: 'FAILURE');
        if ($attempts >= (int) $policy['maxFailedLoginAttempts']) {
            $minutes = (int) $policy['lockoutDuration'];
            $user->forceFill(['failed_attempts' => 0, 'locked_at' => now(), 'locked_until' => $minutes > 0 ? now()->addMinutes($minutes) : null])->save();
            SecurityLog::log('ACCOUNT_LOCKED', $user, details: ['note' => 'تجاوز عدد المحاولات المسموح']);

            return true;
        }
        $user->forceFill(['failed_attempts' => $attempts])->save();

        return false;
    }

    /** Beneficiary self-registration (front authService.register) with server-side ID and password rules. */
    public function register(array $input): array
    {
        $idNumber = mb_strtoupper(trim($input['idNumber']));
        if (User::query()->where('national_id', $idNumber)->exists() || Beneficiary::query()->where('national_id', $idNumber)->where('has_portal_account', true)->exists()) {
            throw new DomainRuleException('يوجد حساب مسجل بهذا الرقم.');
        }
        $user = DB::transaction(function () use ($input, $idNumber) {
            $beneficiary = Beneficiary::query()->where('national_id', $idNumber)->first();
            if (! $beneficiary) {
                $beneficiary = Beneficiary::query()->create([
                    'id' => Sequence::beneficiaryId(), 'first_name' => trim($input['firstName']), 'family_name' => trim($input['familyName']),
                    'national_id' => $idNumber, 'id_type' => $input['idType'], 'nationality' => $input['idType'] === 'هوية وطنية' ? 'سعودي' : '',
                ])->refresh();
            }
            $beneficiary->update(['has_portal_account' => true]);

            return self::createBeneficiaryUser($beneficiary, $input['password']);
        });

        return $this->signIn($user);
    }

    public static function createBeneficiaryUser(Beneficiary $b, string $password): User
    {
        $second = $b->father_name ?: $b->family_name;

        return User::query()->create([
            'id' => 'u-'.$b->id, 'account_type' => AccountType::Beneficiary, 'name' => trim("{$b->first_name} {$second}"), 'role' => 'beneficiary',
            'role_label' => 'المستفيد', 'department' => 'بوابة المستفيد', 'initials' => mb_substr($b->first_name, 0, 1).mb_substr($second, 0, 1),
            'beneficiary_id' => $b->id, 'national_id' => $b->national_id, 'mobile' => (string) $b->mobile, 'password' => $password,
            'password_history' => [], 'password_changed_at' => now(),
        ])->refresh();
    }

    /** Front beneficiary password strength: length ≥ 8 and at least 3 of 4 classes. */
    public static function beneficiaryPasswordOk(string $password): bool
    {
        $classes = (int) (bool) preg_match('/[A-Za-z\x{0600}-\x{06FF}]/u', $password) + (int) (bool) preg_match('/\d/', $password)
            + (int) (bool) preg_match('/[^A-Za-z0-9\x{0600}-\x{06FF}]/u', $password) + (int) (mb_strlen($password) >= 8);

        return mb_strlen($password) >= 8 && $classes >= 3;
    }

    /** Payload for GET /auth/me and the login response. */
    public function me(User $user, ?string $sessionId = null): array
    {
        $user->loadMissing('roles');
        $base = $user->toDemoUser();
        if (! $user->isBeneficiary()) {
            $base += [
                'email' => (string) $user->email, 'mobile' => $user->mobile, 'username' => (string) $user->username, 'jobTitle' => $user->job_title,
                'roleIds' => $user->roleIds(), 'active' => $user->active, 'suspended' => $user->suspended, 'mfaEnabled' => $user->mfa_enabled,
                'mustChangePassword' => $user->must_change_password, 'passwordChangedAt' => iso($user->password_changed_at),
                'lastLoginAt' => iso($user->last_login_at), 'expiresAt' => iso($user->expires_at),
            ];
        }

        return [
            'user' => $base,
            'accountType' => $user->account_type->value,
            'permissions' => (object) $this->permissions->effective($user),
            'accountState' => SecurityPolicy::accountState($user),
            'passwordStatus' => SecurityPolicy::passwordStatus($user),
            'mfaRequired' => SecurityPolicy::mfaRequired($user),
            'sessionId' => $sessionId ?? Sessions::current()?->id,
            'policy' => SecurityPolicy::get(),
        ];
    }

    /** Own password change (front userService.changePassword) — policy is enforced here, not only in the UI. */
    public function changePassword(User $user, string $current, string $next): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new DomainRuleException('كلمة المرور الحالية غير صحيحة.');
        }
        $state = SecurityPolicy::accountState($user);
        $forced = in_array($state, ['expired', 'mustChange'], true);
        $policy = SecurityPolicy::get();
        if (! $user->isBeneficiary() && $policy['minimumPasswordAge'] > 0 && ! $forced && $user->password_changed_at
            && $user->password_changed_at->gt(now()->subDays((int) $policy['minimumPasswordAge']))) {
            throw new DomainRuleException('لا يمكن تغيير كلمة المرور قبل مرور الحد الأدنى لعمرها.');
        }
        $this->setPassword($user, $next);
        SecurityLog::log('PASSWORD_CHANGED', $user);
    }

    /** Validates against policy + history and stores the new password. */
    public function setPassword(User $user, string $next): void
    {
        if ($user->isBeneficiary()) {
            if (! self::beneficiaryPasswordOk($next)) {
                throw new DomainRuleException('كلمة المرور ضعيفة. استخدم 8 أحرف على الأقل تتضمن حروفًا وأرقامًا ورموزًا.');
            }
        } elseif (! SecurityPolicy::passwordValid($next)) {
            $failed = collect(SecurityPolicy::passwordRules($next))->firstWhere('ok', false);
            throw new DomainRuleException('كلمة المرور لا تحقق سياسة الأمان: '.($failed['label'] ?? ''));
        }
        if (Hash::check($next, $user->password)) {
            throw new DomainRuleException('كلمة المرور الجديدة يجب أن تختلف عن الحالية.');
        }
        $policy = SecurityPolicy::get();
        $history = $user->password_history ?? [];
        if ($policy['passwordHistoryEnabled']) {
            foreach (array_slice($history, 0, (int) $policy['passwordHistoryCount']) as $old) {
                if (Hash::check($next, $old)) {
                    throw new DomainRuleException('لا يمكن إعادة استخدام كلمة مرور سابقة.');
                }
            }
        }
        $user->forceFill([
            'password_history' => array_slice([$user->password, ...$history], 0, 24),
            'password' => $next, 'password_changed_at' => now(), 'must_change_password' => false,
        ])->save();
    }
}
