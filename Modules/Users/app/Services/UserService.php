<?php

namespace Modules\Users\Services;

use App\Enums\AccountType;
use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\User;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityPolicy;
use App\Support\Security\Sessions;
use Illuminate\Support\Facades\DB;
use Modules\Access\Services\AccessGuard;

/**
 * Port of front user_service.ts (staff / provider accounts administration).
 * Differences from the mock: new accounts get a random temporary password (returned once) instead of the demo password,
 * and every action is permission-checked server-side.
 */
class UserService
{
    public function __construct(private readonly AccessGuard $guard) {}

    /** Staff / provider account by id (beneficiary accounts are not managed here). */
    public function find(string $id): User
    {
        return User::query()->whereIn('account_type', ['STAFF', 'PROVIDER'])->with('roles')->find($id)
            ?? throw DomainRuleException::notFound('المستخدم غير موجود.');
    }

    /** Front StaffUser shape (never the password or its history). */
    public static function present(User $u): array
    {
        $u->loadMissing('roles');

        return array_filter([
            'id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'roleLabel' => $u->role_label, 'department' => $u->department,
            'initials' => $u->initials, 'providerId' => $u->provider_id, 'accountType' => $u->account_type->value,
            'email' => (string) $u->email, 'mobile' => (string) $u->mobile, 'active' => $u->active, 'username' => (string) $u->username,
            'jobTitle' => (string) $u->job_title, 'roleIds' => $u->roleIds(), 'suspended' => $u->suspended,
            'lockedAt' => iso($u->locked_at), 'lockedUntil' => iso($u->locked_until), 'failedAttempts' => $u->failed_attempts,
            'mustChangePassword' => $u->must_change_password, 'passwordChangedAt' => iso($u->password_changed_at), 'mfaEnabled' => $u->mfa_enabled,
            'lastLoginAt' => iso($u->last_login_at), 'expiresAt' => iso($u->expires_at), 'createdAt' => iso($u->created_at),
            'accountState' => SecurityPolicy::accountState($u), 'passwordStatus' => SecurityPolicy::passwordStatus($u),
            'mfaRequired' => SecurityPolicy::mfaRequired($u),
        ], fn ($v) => $v !== null);
    }

    public static function initials(string $name): string
    {
        $words = array_slice(explode(' ', str_replace('د. ', '', $name)), 0, 2);

        return implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words));
    }

    /** Random temporary password that satisfies the current policy. */
    public static function temporaryPassword(): string
    {
        $len = max(16, (int) SecurityPolicy::get()['minimumPasswordLength']);
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*?-_'];
        do {
            $chars = array_map(fn ($s) => $s[random_int(0, strlen($s) - 1)], $sets);
            $all = implode('', $sets);
            while (count($chars) < $len) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            shuffle($chars);
            $password = implode('', $chars);
        } while (! SecurityPolicy::passwordValid($password));

        return $password;
    }

    /**
     * Create (id = null) or update. Draft = UserDraft {name, username, email, mobile, department, jobTitle, roleIds, active, mustChangePassword, expiresAt?, providerId?}.
     * Returns [User, ?temporaryPassword].
     */
    public function save(array $d, User $actor, ?string $id = null): array
    {
        $this->guard->need($actor, $id ? 'users.edit' : 'users.create');
        $prev = $id ? $this->find($id) : null;
        $email = mb_strtolower(trim($d['email']));
        $username = mb_strtolower(trim($d['username']));
        if (User::query()->when($id, fn ($q) => $q->where('id', '!=', $id))->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new DomainRuleException('البريد الإلكتروني مستخدم لحساب آخر.');
        }
        if (User::query()->when($id, fn ($q) => $q->where('id', '!=', $id))->whereRaw('lower(username) = ?', [$username])->exists()) {
            throw new DomainRuleException('اسم المستخدم مستخدم لحساب آخر.');
        }
        $roleIds = array_values(array_unique($d['roleIds'] ?? []));
        $before = $prev ? $prev->roleIds() : [];
        $added = array_values(array_diff($roleIds, $before));
        $removed = array_values(array_diff($before, $roleIds));
        $this->guard->assertCanAssign($actor, $added);

        $roles = Role::query()->whereIn('id', $roleIds)->get()->sortBy(fn ($r) => array_search($r->id, $roleIds, true))->values();
        $providerId = ($d['providerId'] ?? null) ?: null;
        $base = [
            'name' => trim($d['name']), 'username' => trim($d['username']), 'email' => trim($d['email']), 'mobile' => trim((string) ($d['mobile'] ?? '')),
            'department' => trim((string) ($d['department'] ?? '')), 'job_title' => trim((string) ($d['jobTitle'] ?? '')),
            'active' => (bool) ($d['active'] ?? true), 'must_change_password' => (bool) ($d['mustChangePassword'] ?? false),
            'expires_at' => ($d['expiresAt'] ?? null) ?: null, 'provider_id' => $providerId,
            'account_type' => $providerId ? AccountType::Provider : AccountType::Staff,
            'role' => $roles->first(fn ($r) => $r->workflow_role)?->workflow_role ?? 'team',
            'role_label' => $roles->pluck('name_ar')->implode('، ') ?: 'بدون دور',
            'initials' => self::initials(trim($d['name'])),
        ];
        $temporary = null;

        $user = DB::transaction(function () use ($prev, $base, $roleIds, $actor, &$temporary) {
            if ($prev) {
                $user = User::query()->lockForUpdate()->findOrFail($prev->id);
                $user->forceFill($base)->save();
            } else {
                $temporary = self::temporaryPassword();
                $user = new User;
                // New accounts always start with a one-time temporary password that must be changed.
                $user->forceFill(array_merge($base, [
                    'id' => $this->newId(), 'password' => $temporary, 'password_history' => [], 'must_change_password' => true,
                    'suspended' => false, 'failed_attempts' => 0, 'password_changed_at' => now(), 'mfa_enabled' => false,
                ]))->save();
            }
            $user->roles()->sync(collect($roleIds)->mapWithKeys(fn ($rid, $i) => [$rid => ['position' => $i]])->all());
            $this->guard->assertAdminRemains();

            return $user;
        });
        $user = $user->fresh('roles');
        SecurityLog::log($prev ? 'USER_UPDATED' : 'USER_CREATED', $user, actor: $actor, target: $user->name);
        $names = fn (array $ids) => Role::query()->whereIn('id', $ids)->pluck('name_ar')->all();
        foreach ($names($added) as $n) {
            SecurityLog::log('ROLE_ASSIGNED', $user, actor: $actor, target: $n);
        }
        foreach ($names($removed) as $n) {
            SecurityLog::log('ROLE_REMOVED', $user, actor: $actor, target: $n);
        }
        $this->guard->flush();

        return [$user, $temporary];
    }

    private function newId(): string
    {
        do {
            $id = 'u'.base_convert((string) (int) (microtime(true) * 1000), 10, 36).uid(2);
        } while (User::query()->whereKey($id)->exists());

        return $id;
    }

    public function removeRole(string $id, string $roleId, User $actor): User
    {
        $this->guard->need($actor, 'users.edit');
        $u = $this->find($id);

        return $this->save($this->draftOf($u, array_values(array_filter($u->roleIds(), fn ($r) => $r !== $roleId))), $actor, $id)[0];
    }

    public function draftOf(User $u, ?array $roleIds = null): array
    {
        return [
            'name' => $u->name, 'username' => (string) $u->username, 'email' => (string) $u->email, 'mobile' => $u->mobile, 'department' => $u->department,
            'jobTitle' => $u->job_title, 'roleIds' => $roleIds ?? $u->roleIds(), 'active' => $u->active, 'mustChangePassword' => $u->must_change_password,
            'expiresAt' => iso($u->expires_at), 'providerId' => $u->provider_id,
        ];
    }

    public function setSuspended(string $id, bool $suspended, User $actor): User
    {
        $this->guard->need($actor, 'users.deactivate');
        $u = $this->patch($id, ['suspended' => $suspended]);
        if ($suspended) {
            Sessions::endAllFor($u, $actor, 'إيقاف الحساب');
        }
        SecurityLog::log($suspended ? 'ACCOUNT_SUSPENDED' : 'ACCOUNT_ACTIVATED', $u, actor: $actor, target: $u->name);

        return $u;
    }

    public function lock(string $id, User $actor): User
    {
        $this->guard->need($actor, 'users.manageSecurity');
        $u = $this->patch($id, ['locked_at' => now(), 'locked_until' => null]);
        Sessions::endAllFor($u, $actor, 'قفل الحساب');
        SecurityLog::log('ACCOUNT_LOCKED', $u, actor: $actor, target: $u->name, details: ['note' => 'قفل يدوي بواسطة المدير']);

        return $u;
    }

    public function unlock(string $id, User $actor): User
    {
        $this->guard->need($actor, 'users.manageSecurity');
        $u = $this->patch($id, ['locked_at' => null, 'locked_until' => null, 'failed_attempts' => 0], false);
        SecurityLog::log('ACCOUNT_UNLOCKED', $u, actor: $actor, target: $u->name);

        return $u;
    }

    public function forcePasswordChange(string $id, User $actor): User
    {
        $this->guard->need($actor, 'users.manageSecurity');
        $u = $this->patch($id, ['must_change_password' => true], false);
        SecurityLog::log('FORCE_PASSWORD_CHANGE', $u, actor: $actor, target: $u->name);

        return $u;
    }

    public function resetMfa(string $id, User $actor): User
    {
        $this->guard->need($actor, 'users.manageSecurity');
        $u = $this->patch($id, ['mfa_enabled' => false], false);
        SecurityLog::log('MFA_DISABLED', $u, actor: $actor, target: $u->name, details: ['note' => 'إعادة تعيين بواسطة المدير']);

        return $u;
    }

    public function endAllSessions(string $id, User $actor): int
    {
        $this->guard->need($actor, 'users.manageSecurity');

        return Sessions::endAllFor($this->find($id), $actor, 'إنهاء جميع الجلسات');
    }

    private function patch(string $id, array $attrs, bool $check = true): User
    {
        $this->find($id);

        $user = DB::transaction(function () use ($id, $attrs, $check) {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            $user->forceFill($attrs)->save();
            if ($check) {
                $this->guard->assertAdminRemains();
            }

            return $user;
        });
        $this->guard->flush();

        return $user->fresh('roles');
    }
}
