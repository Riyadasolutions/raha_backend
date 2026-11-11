<?php

namespace Modules\Cases\Services;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\RahaCase;

/** Action availability (front mocks/workflow.ts actionsFor / requirementMet / actionsForCase). Default deny. */
class CaseActions
{
    public function __construct(private PermissionResolver $perms, private CaseAccess $access) {}

    /** Actions defined for a status the user holds the permission for. */
    public function actionsFor(string $status, User $user): array
    {
        return array_values(array_filter(WorkflowConfig::actions(), fn ($a) => $a['from'] === $status && $this->perms->has($user, $a['perm'])));
    }

    public static function requirementMet(array $a, RahaCase $c): bool
    {
        return match ($a['requires'] ?? null) {
            'providerPending' => (bool) $c->provider_id && ! $c->provider_accepted,
            'providerReady' => ! $c->provider_id || (bool) $c->provider_accepted,
            // Phase 5: cases in the finance flow (and every quotation case) need an issued authorization letter.
            'noQuotation' => $c->finance ? ($c->finance['stage'] ?? null) === 'authorization_issued' : ! $c->quotation_required,
            'quotationSelected' => (bool) $c->preferred_quotation,
            default => true,
        };
    }

    /** Actions this user can take on this case now (task ownership for review stages, provider ownership, internal team). */
    public function actionsForCase(RahaCase $c, ?User $user): array
    {
        if (! $user || $user->isBeneficiary() || ! $this->access->canAccessCase($c, $user)) {
            return [];
        }
        if (WorkflowConfig::reviewStageOf($c->status)) {
            if (! $user->isStaff()) {
                return [];
            }
            $task = $c->activeTask();
            if (! $task || $task->assigned_user_id !== $user->id || ! $this->perms->has($user, 'requests.claim')) {
                return [];
            }
        }

        return array_values(array_filter($this->actionsFor($c->status, $user), function ($a) use ($c, $user) {
            if (! self::requirementMet($a, $c)) {
                return false;
            }
            if ($user->provider_id) {
                return $c->provider_id === $user->provider_id;
            }
            if ($user->role === 'team' && in_array($a['key'], ['schedule', 'start_service', 'complete_service'], true)) {
                return ! $c->provider_id;
            }

            return true;
        }));
    }

    /** Front-facing metadata for an action. */
    public static function meta(array $a): array
    {
        return array_filter([
            'key' => $a['key'], 'label' => $a['label'], 'kind' => $a['kind'], 'form' => $a['form'] ?? null, 'perm' => $a['perm'],
            'from' => $a['from'], 'to' => $a['to'], 'requires' => $a['requires'] ?? null, 'roles' => $a['roles'] ?? [],
        ], fn ($v) => $v !== null);
    }
}
