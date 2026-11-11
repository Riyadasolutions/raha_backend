<?php

namespace Modules\Access\Services;

use App\Enums\PermissionScope;
use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\DB;

/** Port of front rbac_service.ts roleService (save / setStatus / remove / compare) with server-side guards. */
class RoleService
{
    public const WORKFLOW_ROLES = ['admin', 'executive', 'auditor', 'medical', 'social', 'manager', 'finance', 'team', 'provider', 'relations'];

    public function __construct(private readonly AccessGuard $guard) {}

    public static function permissionByCode(): array
    {
        return collect(PermissionResolver::catalog())->keyBy('code')->all();
    }

    /** @return array{added: string[], removed: string[], rescoped: string[]} */
    public static function diffPermissions(array $before, array $after): array
    {
        $on = fn (array $m, string $c) => ! empty($m[$c]) && $m[$c] !== 'NONE';
        $added = $removed = $rescoped = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $c) {
            if ($on($after, $c) && ! $on($before, $c)) {
                $added[] = $c;
            } elseif (! $on($after, $c) && $on($before, $c)) {
                $removed[] = $c;
            } elseif ($on($after, $c) && $before[$c] !== $after[$c]) {
                $rescoped[] = $c;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'rescoped' => $rescoped];
    }

    /** @return array<int, array{code: string, needs: string}> */
    public static function missingDependencies(array $perms): array
    {
        $catalog = self::permissionByCode();
        $out = [];
        foreach ($perms as $code => $scope) {
            if ($scope === 'NONE') {
                continue;
            }
            foreach ($catalog[$code]['dependsOn'] ?? [] as $dep) {
                if (empty($perms[$dep]) || $perms[$dep] === 'NONE') {
                    $out[] = ['code' => $code, 'needs' => $dep];
                }
            }
        }

        return $out;
    }

    /**
     * Create (id = null) or update a role. $draft = {nameAr, nameEn?, code, description, status, permissions, workflowRole?}.
     * Returns the saved role.
     */
    public function save(array $draft, User $actor, ?string $id = null, array $assignUserIds = []): Role
    {
        $prev = $id ? Role::query()->find($id) : null;
        if ($id && ! $prev) {
            throw DomainRuleException::notFound('الدور غير موجود.');
        }
        $this->guard->need($actor, $prev ? 'roles.edit' : 'roles.create');
        $nameAr = trim((string) ($draft['nameAr'] ?? ''));
        $code = (string) ($draft['code'] ?? '');
        if (mb_strlen($nameAr) < 3) {
            throw new DomainRuleException('اسم الدور مطلوب (3 أحرف على الأقل).');
        }
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,40}$/', $code)) {
            throw new DomainRuleException('رمز الدور يجب أن يكون بأحرف إنجليزية كبيرة وشرطة سفلية، مثل AUDIT_SUPERVISOR.');
        }
        $duplicate = Role::query()->when($id, fn ($q) => $q->where('id', '!=', $id))
            ->where(fn ($q) => $q->where('code', $code)->orWhere('name_ar', $nameAr))->exists();
        if ($duplicate) {
            throw new DomainRuleException('يوجد دور بنفس الاسم أو الرمز.');
        }
        $status = $draft['status'] ?? ($prev->status ?? 'active');
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new DomainRuleException('حالة الدور غير صحيحة.');
        }
        $workflowRole = $draft['workflowRole'] ?? null;
        if ($workflowRole !== null && $workflowRole !== '' && ! in_array($workflowRole, self::WORKFLOW_ROLES, true)) {
            throw new DomainRuleException('مرحلة سير العمل المرتبطة غير صحيحة.');
        }
        $workflowRole = $workflowRole ?: null;

        $catalog = self::permissionByCode();
        $permissions = [];
        foreach (($draft['permissions'] ?? []) as $c => $scope) {
            if (! isset($catalog[$c])) {
                throw new DomainRuleException("صلاحية غير معروفة: $c");
            }
            if (! is_string($scope) || ! PermissionScope::tryFrom($scope)) {
                throw new DomainRuleException('نطاق الصلاحية غير صحيح.');
            }
            if ($scope !== 'NONE') {
                $permissions[$c] = $scope;
            }
        }
        $before = $prev?->permissions ?? [];
        $d = self::diffPermissions($before, $permissions);
        $changed = $d['added'] || $d['removed'] || $d['rescoped'];
        if ($changed) {
            $this->guard->need($actor, 'roles.managePermissions');
        }
        foreach (array_merge($d['added'], $d['rescoped']) as $c) {
            $options = $catalog[$c]['scopeOptions'] ?? null;
            if ($options && ! in_array($permissions[$c], $options, true)) {
                throw new DomainRuleException('نطاق غير مسموح لهذه الصلاحية ('.$catalog[$c]['nameAr'].').');
            }
            if (! $this->guard->canGrant($actor, $c, $permissions[$c])) {
                throw DomainRuleException::forbidden(AccessGuard::ESCALATION_MSG.' ('.($catalog[$c]['nameAr'] ?? $c).')');
            }
        }
        if ($changed && ($missing = self::missingDependencies($permissions))) {
            $m = $missing[0];
            throw new DomainRuleException('«'.$catalog[$m['code']]['nameAr'].'» مفعلة بينما «'.$catalog[$m['needs']]['nameAr'].'» غير مفعلة.', 422, ['missingDependencies' => $missing]);
        }
        $assignUserIds = array_values(array_unique($assignUserIds));
        if ($assignUserIds) {
            // Assigning a role grants all of its permissions to the users, so the actor must be able to grant them.
            foreach ($permissions as $c => $scope) {
                if (! $this->guard->canGrant($actor, $c, $scope)) {
                    throw DomainRuleException::forbidden(AccessGuard::ESCALATION_MSG.' — أحد الأدوار المختارة يمنح صلاحيات لا تملكها.');
                }
            }
        }

        return DB::transaction(function () use ($prev, $draft, $actor, $nameAr, $code, $status, $workflowRole, $permissions, $before, $d, $assignUserIds) {
            $role = $prev ? Role::query()->lockForUpdate()->findOrFail($prev->id) : new Role(['id' => 'r-'.uid(), 'type' => 'CUSTOM', 'migrations' => []]);
            $old = ['name' => $role->name_ar, 'description' => (string) $role->description, 'status' => $role->status];
            $role->fill([
                'name_ar' => $nameAr, 'name_en' => trim((string) ($draft['nameEn'] ?? '')) ?: $code, 'code' => $code,
                'description' => trim((string) ($draft['description'] ?? '')), 'status' => $status, 'permissions' => (object) $permissions,
                'workflow_role' => $workflowRole, 'updated_by' => $actor->name,
            ])->save();

            $assigned = [];
            if ($assignUserIds) {
                $users = User::query()->whereIn('id', $assignUserIds)->whereIn('account_type', ['STAFF', 'PROVIDER'])->with('roles')->get();
                foreach ($users as $u) {
                    if (! in_array($role->id, $u->roleIds(), true)) {
                        $u->roles()->attach($role->id, ['position' => $u->roles->count()]);
                        $assigned[] = $u;
                    }
                }
            }
            $this->guard->assertAdminRemains();

            if (! $prev) {
                SecurityLog::log('ROLE_CREATED', userName: $actor->name, actor: $actor, target: $role->name_ar, details: ['added' => $d['added']]);
            } else {
                $new = ['name' => $role->name_ar, 'description' => (string) $role->description, 'status' => $role->status];
                if ($old !== $new) {
                    SecurityLog::log('ROLE_UPDATED', userName: $actor->name, actor: $actor, target: $role->name_ar, details: ['before' => $old, 'after' => $new]);
                }
                if ($d['added'] || $d['removed'] || $d['rescoped']) {
                    SecurityLog::log('PERMISSION_CHANGED', userName: $actor->name, actor: $actor, target: $role->name_ar, details: array_filter([
                        'added' => $d['added'], 'removed' => $d['removed'], 'before' => (object) $before, 'after' => (object) $permissions,
                        'note' => $d['rescoped'] ? 'تغيير النطاق: '.implode(', ', $d['rescoped']) : null,
                    ], fn ($v) => $v !== null));
                }
            }
            foreach ($assigned as $u) {
                $this->syncDerived($u);
                SecurityLog::log('ROLE_ASSIGNED', $u, actor: $actor, target: $role->name_ar);
            }
            if ($prev && ($old['name'] !== $role->name_ar || $prev->workflow_role !== $role->workflow_role)) {
                foreach ($role->users()->get() as $u) {
                    $this->syncDerived($u);
                }
            }
            $this->guard->flush();

            return $role->fresh();
        });
    }

    public function setStatus(string $id, string $status, User $actor): Role
    {
        $role = Role::query()->find($id) ?? throw DomainRuleException::notFound('الدور غير موجود.');

        return $this->save([
            'nameAr' => $role->name_ar, 'nameEn' => $role->name_en, 'code' => $role->code, 'description' => (string) $role->description,
            'status' => $status, 'permissions' => $role->permissions ?? [], 'workflowRole' => $role->workflow_role,
        ], $actor, $id);
    }

    /** Returns the number of users moved to the replacement role. */
    public function remove(string $id, User $actor, ?string $replacementId = null): int
    {
        $this->guard->need($actor, 'roles.delete');
        $role = Role::query()->find($id) ?? throw DomainRuleException::notFound('الدور غير موجود.');
        if ($role->type === 'SYSTEM') {
            throw new DomainRuleException('لا يمكن حذف أدوار النظام.');
        }
        $assigned = $role->users()->with('roles')->get();
        if ($assigned->isNotEmpty() && ! $replacementId) {
            throw new DomainRuleException("لا يمكن حذف هذا الدور لأنه مرتبط بـ {$assigned->count()} مستخدمين.");
        }
        $rep = $replacementId ? Role::query()->find($replacementId) : null;
        if ($replacementId && (! $rep || $rep->id === $id)) {
            throw new DomainRuleException('اختر دورًا بديلًا صحيحًا.');
        }
        if ($rep && $assigned->isNotEmpty()) {
            $this->guard->assertCanAssign($actor, [$rep->id]);
        }

        return DB::transaction(function () use ($role, $rep, $assigned, $actor) {
            Role::query()->lockForUpdate()->findOrFail($role->id);
            foreach ($assigned as $u) {
                $u->roles()->detach($role->id);
                if ($rep && ! in_array($rep->id, $u->roleIds(), true)) {
                    $u->roles()->attach($rep->id, ['position' => $u->roles->count()]);
                }
            }
            $role->delete();
            $this->guard->assertAdminRemains();
            foreach ($assigned as $u) {
                $this->syncDerived($u->fresh());
                SecurityLog::log('ROLE_REMOVED', $u, actor: $actor, target: $role->name_ar);
                if ($rep) {
                    SecurityLog::log('ROLE_ASSIGNED', $u, actor: $actor, target: $rep->name_ar);
                }
            }
            SecurityLog::log('ROLE_DELETED', userName: $actor->name, actor: $actor, target: $role->name_ar, details: array_filter([
                'before' => (object) ($role->permissions ?? []), 'note' => $rep ? "نُقل {$assigned->count()} مستخدمين إلى {$rep->name_ar}" : null,
            ], fn ($v) => $v !== null));
            $this->guard->flush();

            return $assigned->count();
        });
    }

    /** front roleService.compare: one row per catalog permission with each role's scope (absent = not granted). */
    public static function compare(Role $a, Role $b): array
    {
        $on = fn (Role $r, string $c) => ! empty($r->permissions[$c]) && $r->permissions[$c] !== 'NONE';

        return array_map(fn ($p) => array_filter([
            'p' => $p, 'a' => $on($a, $p['code']) ? $a->permissions[$p['code']] : null, 'b' => $on($b, $p['code']) ? $b->permissions[$p['code']] : null,
        ], fn ($v) => $v !== null), PermissionResolver::catalog());
    }

    /** Keeps the user's denormalised role / roleLabel (front primaryRole / roleNames) in sync with their roles. */
    public static function syncDerived(User $user): void
    {
        $roles = $user->roles()->get();
        if ($user->isBeneficiary()) {
            return;
        }
        $user->forceFill([
            'role' => $roles->first(fn ($r) => $r->workflow_role)?->workflow_role ?? 'team',
            'role_label' => $roles->pluck('name_ar')->implode('، ') ?: 'بدون دور',
        ])->save();
    }
}
