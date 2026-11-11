<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Security policy (single row) + password rules (port of front security_service.ts). */
class SecurityPolicy
{
    private static ?array $cached = null;

    public static function get(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $row = DB::table('security_policies')->orderBy('id')->first();
        $stored = $row ? json_decode($row->policy, true) : [];

        return self::$cached = array_merge(raha('rbac.defaultSecurityPolicy', []), $stored ?: []);
    }

    public static function forget(): void
    {
        self::$cached = null;
    }

    /** @return array<int, array{id: string, label: string, ok: bool}> */
    public static function passwordRules(string $password, ?array $policy = null): array
    {
        $p = $policy ?? self::get();
        $rules = [['id' => 'len', 'label' => "{$p['minimumPasswordLength']} أحرف على الأقل", 'ok' => mb_strlen($password) >= $p['minimumPasswordLength']]];
        if ($p['requireUppercase']) {
            $rules[] = ['id' => 'up', 'label' => 'حرف كبير (A-Z)', 'ok' => (bool) preg_match('/[A-Z]/', $password)];
        }
        if ($p['requireLowercase']) {
            $rules[] = ['id' => 'low', 'label' => 'حرف صغير (a-z)', 'ok' => (bool) preg_match('/[a-z]/', $password)];
        }
        if ($p['requireNumber']) {
            $rules[] = ['id' => 'num', 'label' => 'رقم', 'ok' => (bool) preg_match('/\d/', $password)];
        }
        if ($p['requireSpecialCharacter']) {
            $rules[] = ['id' => 'sym', 'label' => 'رمز خاص', 'ok' => (bool) preg_match('/[^A-Za-z0-9]/', $password)];
        }
        if ($p['preventCommonPasswords']) {
            $rules[] = ['id' => 'common', 'label' => 'ليست كلمة مرور شائعة', 'ok' => ! in_array(mb_strtolower($password), raha('rbac.commonPasswords', []), true)];
        }

        return $rules;
    }

    public static function passwordValid(string $password): bool
    {
        return collect(self::passwordRules($password))->every(fn ($r) => $r['ok']);
    }

    /** @return array{expired: bool, daysLeft: ?int, expiresAt: ?string} */
    public static function passwordStatus(User $user): array
    {
        $p = self::get();
        if (! $p['passwordExpirationEnabled'] || ! $user->password_changed_at) {
            return ['expired' => false, 'daysLeft' => null, 'expiresAt' => null];
        }
        $expires = $user->password_changed_at->copy()->addDays($p['passwordExpirationDays']);
        $daysLeft = (int) ceil(now()->diffInSeconds($expires, false) / 86400);

        return ['expired' => $expires->isPast(), 'daysLeft' => $daysLeft, 'expiresAt' => iso($expires)];
    }

    /** suspended > locked > expired > mustChange > active */
    public static function accountState(User $user): string
    {
        if ($user->suspended || ! $user->active) {
            return 'suspended';
        }
        if ($user->isLocked()) {
            return 'locked';
        }
        if (self::passwordStatus($user)['expired']) {
            return 'expired';
        }

        return $user->must_change_password ? 'mustChange' : 'active';
    }

    public static function mfaRequired(User $user): bool
    {
        $p = self::get();

        return match ($p['mfaPolicy']) {
            'all' => true,
            'roles' => count(array_intersect($user->roleIds(), $p['mfaRequiredRoles'] ?? [])) > 0,
            default => false,
        };
    }

    /** Validation of an edited policy; returns the first Arabic error or null. */
    public static function validate(array $p): ?string
    {
        if ($p['passwordExpirationDays'] < 1 || $p['passwordExpirationDays'] > 730) {
            return 'مدة صلاحية كلمة المرور يجب أن تكون بين 1 و730 يومًا.';
        }
        if ($p['passwordExpirationWarningDays'] >= $p['passwordExpirationDays']) {
            return 'مدة التنبيه يجب أن تكون أقل من مدة الصلاحية.';
        }
        if ($p['minimumPasswordLength'] < 8 || $p['minimumPasswordLength'] > 64) {
            return 'الحد الأدنى لطول كلمة المرور بين 8 و64.';
        }
        if ($p['maxFailedLoginAttempts'] < 1) {
            return 'عدد المحاولات الفاشلة يجب أن يكون 1 على الأقل.';
        }
        if ($p['idleWarningMinutes'] >= $p['idleTimeoutMinutes']) {
            return 'وقت التحذير يجب أن يكون أقل من مهلة الخمول.';
        }
        if ($p['mfaPolicy'] === 'roles' && empty($p['mfaRequiredRoles'])) {
            return 'اختر دورًا واحدًا على الأقل يتطلب المصادقة متعددة العوامل.';
        }

        return null;
    }
}
