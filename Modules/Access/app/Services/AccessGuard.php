<?php

namespace Modules\Access\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;

/**
 * Shared RBAC guards for the administration modules (Access / Users), with the front's Arabic messages
 * (rbac_service.ts need / assertAdminRemains / canGrant, user_service.ts assertCanAssign).
 */
class AccessGuard
{
    public const NEED_MSG = 'ليست لديك صلاحية تنفيذ هذا الإجراء.';

    public const LAST_ADMIN_MSG = 'لا يمكن إزالة صلاحيات مدير النظام من آخر مدير نشط.';

    public const ESCALATION_MSG = 'لا تملك صلاحية منح هذا الإذن.';

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** front need()/req(): 403 unless the actor holds the code. */
    public function need(?User $actor, string $code): void
    {
        if (! $this->permissions->has($actor, $code)) {
            throw DomainRuleException::forbidden(self::NEED_MSG);
        }
    }

    public function needAny(?User $actor, array $codes): void
    {
        if (! $this->permissions->hasAny($actor, $codes)) {
            throw DomainRuleException::forbidden(self::NEED_MSG);
        }
    }

    /** Call inside the transaction after the change is applied; throws (and so rolls back) when no active admin remains. */
    public function assertAdminRemains(): void
    {
        try {
            $this->permissions->assertAdminRemains();
        } catch (DomainRuleException $e) {
            throw new DomainRuleException(self::LAST_ADMIN_MSG);
        } finally {
            $this->permissions->flush();
        }
    }

    public function canGrant(User $actor, string $code, string $scope = 'ALL'): bool
    {
        return $this->permissions->canGrant($actor, $code, $scope);
    }

    /** user_service assertCanAssign: every grant of the given roles must be grantable by the actor. */
    public function assertCanAssign(User $actor, array $roleIds): void
    {
        if (! $roleIds) {
            return;
        }
        $roles = Role::query()->whereIn('id', $roleIds)->get();
        foreach (PermissionResolver::grantsFor($roles) as $code => $grant) {
            if (! $this->canGrant($actor, $code, $grant['scope'])) {
                throw DomainRuleException::forbidden(self::ESCALATION_MSG.' — أحد الأدوار المختارة يمنح صلاحيات لا تملكها.');
            }
        }
    }

    public function flush(): void
    {
        $this->permissions->flush();
    }
}
