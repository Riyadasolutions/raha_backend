<?php

namespace App\Support\Permissions;

use App\Enums\PermissionScope;
use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Default-deny RBAC (port of front rbac_service.ts).
 * A user's grants = union of their ACTIVE roles; when roles overlap the highest scope wins.
 * Beneficiaries use the fixed legacy permission list instead of roles.
 */
class PermissionResolver
{
    public const ADMIN_PERMISSION = 'roles.managePermissions';

    public const LAST_ADMIN_MSG = 'لا يمكن إزالة صلاحيات مدير النظام من آخر مدير نشط.';

    /** @var array<string, array<string, array{scope: string, sources: string[]}>> */
    private array $cache = [];

    public function flush(?User $user = null): void
    {
        if ($user) {
            unset($this->cache[$user->id]);
        } else {
            $this->cache = [];
        }
    }

    /** @return array<string, array{scope: string, sources: string[]}> */
    public function grants(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }
        if ($user->isBeneficiary()) {
            $grants = [];
            foreach (raha('users.legacyPermissions.beneficiary', []) as $code) {
                $grants[$code] = ['scope' => 'OWN', 'sources' => ['beneficiary']];
            }

            return $this->cache[$user->id] = $grants;
        }

        return $this->cache[$user->id] = self::grantsFor($user->roles()->get());
    }

    /** @param Collection<int, Role> $roles */
    public static function grantsFor(Collection $roles): array
    {
        $catalog = array_flip(array_column(raha('rbac.permissionCatalog', []), 'code'));
        $grants = [];
        foreach ($roles as $role) {
            if ($role->status !== 'active') {
                continue;
            }
            foreach (($role->permissions ?? []) as $code => $scope) {
                if ($scope === 'NONE' || ! isset($catalog[$code])) {
                    continue;
                }
                $current = $grants[$code] ?? null;
                if (! $current || PermissionScope::rankOf($scope) > PermissionScope::rankOf($current['scope'])) {
                    $grants[$code] = ['scope' => $scope, 'sources' => array_merge($current['sources'] ?? [], [$role->id])];
                } else {
                    $grants[$code]['sources'][] = $role->id;
                }
            }
        }

        return $grants;
    }

    public function has(?User $user, string $code, string $min = 'OWN'): bool
    {
        if (! $user) {
            return false;
        }
        $grant = $this->grants($user)[$code] ?? null;

        return $grant !== null && PermissionScope::rankOf($grant['scope']) >= PermissionScope::rankOf($min);
    }

    public function hasAny(?User $user, array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->has($user, $code)) {
                return true;
            }
        }

        return false;
    }

    public function scopeOf(?User $user, string $code): string
    {
        return $user ? ($this->grants($user)[$code]['scope'] ?? 'NONE') : 'NONE';
    }

    /** @throws DomainRuleException 403 */
    public function require(?User $user, string $code, string $min = 'OWN'): void
    {
        if (! $this->has($user, $code, $min)) {
            throw DomainRuleException::forbidden();
        }
    }

    /** Effective map code => scope (front effectivePermissions). */
    public function effective(User $user): array
    {
        return array_map(fn ($g) => $g['scope'], $this->grants($user));
    }

    /** Escalation guard: an actor may only grant a code at a scope they hold themselves. */
    public function canGrant(User $actor, string $code, string $scope = 'ALL'): bool
    {
        return PermissionScope::rankOf($this->scopeOf($actor, $code)) >= PermissionScope::rankOf($scope);
    }

    public static function isAdminHolder(User $user, Collection $roles): bool
    {
        return $user->active && ! $user->suspended && ! $user->isLocked()
            && isset(self::grantsFor($roles)[self::ADMIN_PERMISSION]);
    }

    /** Throws when no active administrator would remain. Call inside the transaction after the change is applied. */
    public function assertAdminRemains(): void
    {
        $exists = User::query()->where('account_type', 'STAFF')->with('roles')->get()
            ->contains(fn (User $u) => self::isAdminHolder($u, $u->roles));
        if (! $exists) {
            throw new DomainRuleException(self::LAST_ADMIN_MSG);
        }
        $this->flush();
    }

    public static function catalog(): array
    {
        return raha('rbac.permissionCatalog', []);
    }

    public static function known(string $code): bool
    {
        return in_array($code, array_column(self::catalog(), 'code'), true);
    }
}
