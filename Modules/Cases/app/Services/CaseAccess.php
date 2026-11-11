<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Database\Eloquent\Collection;
use Modules\Cases\Models\RahaCase;

/**
 * Case visibility rules (port of front case_access_service.ts + case_guards.ts). PUBLIC API for other modules:
 *  - canAccessStage(user, status): staff stage visibility (default deny).
 *  - canAccessCase(case, user): beneficiary owns it / provider assigned to it / staff by stage + cases.view scope.
 *  - canAccessCaseTab(user, tab): staff detail-tab visibility (Arabic tab names from front case_tabs.tsx).
 *  - findAccessible(id, user): 404 when missing or not accessible (no existence leak).
 *  - assertMutable(case): archived requests are read-only.
 */
class CaseAccess
{
    public const ARCHIVED_READ_ONLY = 'الطلب مؤرشف وللقراءة فقط.';

    public const STAGE_PERMISSIONS = [
        'new' => ['cases.review'], 'medical_review' => ['medical.review', 'medical.approve', 'medical.return'],
        'awaiting_quotation' => ['quotations.create', 'quotations.medicalEvaluate', 'quotations.selectPreferred'],
        'social_review' => ['social.approve', 'requests.social.return'], 'manager_approval' => ['cases.approve', 'requests.care.return'],
        'preliminary_approved' => ['finance.funding.review', 'executive.approvals.view', 'authorization.view', 'cases.assign'],
        'assigned' => ['service.accept', 'service.schedule', 'service.deliver', 'cases.assign'],
        'scheduled' => ['service.schedule', 'service.deliver', 'cases.assign'], 'in_progress' => ['service.deliver', 'cases.assign'],
        'completed' => ['relations.review'], 'finance_review' => ['finance.confirmPayment', 'finance.funding.review'],
    ];

    public function __construct(private PermissionResolver $perms) {}

    public function canAccessStage(?User $user, string $status): bool
    {
        if (! $user || ! $user->isStaff() || ! $this->perms->has($user, 'cases.view')) {
            return false;
        }
        if ($this->perms->has($user, 'executive.approvals.view') || $status === 'archived') {
            return true;
        }

        return $this->perms->hasAny($user, self::STAGE_PERMISSIONS[$status] ?? []);
    }

    public function canAccessCase(RahaCase $c, ?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isBeneficiary()) {
            return $user->beneficiary_id !== null && $c->beneficiary_id === $user->beneficiary_id;
        }
        if ($user->isProvider()) {
            return $user->provider_id !== null && $c->provider_id === $user->provider_id;
        }
        if (! $this->perms->has($user, 'cases.view')) {
            return false;
        }
        $task = $c->activeTask();
        $assigned = $task?->assigned_user_id ? $task->assigned_user_id === $user->id : $c->assignee === $user->name;
        $scope = $this->perms->scopeOf($user, 'cases.view');
        if ($scope === 'OWN' || $scope === 'ASSIGNED') {
            return $assigned;
        }
        $status = $c->status === 'returned' ? ($c->return_info['fromStatus'] ?? $c->status) : $c->status;

        return $this->canAccessStage($user, $status) || ($assigned && in_array($c->status, ['returned', 'rejected'], true));
    }

    public function canAccessCaseTab(?User $user, string $tab): bool
    {
        if (! $user) {
            return false;
        }
        if (! $user->isStaff()) {
            return true;
        }
        $any = fn (string ...$p) => $this->perms->hasAny($user, $p);

        return match (true) {
            $tab === 'المعلومات الطبية' => $any('medical.review', 'medical.approve', 'beneficiaries.medical.view'),
            $tab === 'البحث الاجتماعي' => $any('social.approve', 'requests.social.return', 'cases.approve'),
            in_array($tab, ['سير العمل', 'المهام', 'سجل الإجراءات'], true) => $any('workflow.history.view'),
            $tab === 'المستندات' => $any('beneficiaries.documents.view'),
            $tab === 'الملاحظات' => $any('beneficiaries.notes.view', 'comments.internal.create', 'comments.beneficiary.create'),
            $tab === 'الخدمة' => $any('cases.assign', 'service.schedule', 'service.deliver', 'authorization.view', 'relations.review'),
            $tab === 'علاقات المرضى' => $any('relations.review'),
            default => true,
        };
    }

    /** Visit features: normal access plus staff with visits.monitor on a `completed` request (front canSeeVisitCase). */
    public function canSeeVisitCase(RahaCase $c, ?User $user): bool
    {
        if ($this->canAccessCase($c, $user)) {
            return true;
        }

        return $user?->isStaff() && $c->status === 'completed' && $this->perms->has($user, 'visits.monitor') && $this->perms->has($user, 'cases.view');
    }

    /** All cases the user may see (front caseService.list). */
    public function visibleCases(User $user, bool $withChildren = false): Collection
    {
        $q = RahaCase::query()->with($withChildren ? ['tasks', 'documents', 'activities', 'comments', 'reviews'] : ['tasks']);
        if ($user->isBeneficiary()) {
            $q->where('beneficiary_id', $user->beneficiary_id ?? '__none__');
        } elseif ($user->isProvider()) {
            $q->where('provider_id', $user->provider_id ?? '__none__');
        }

        return $q->orderByDesc('created_at')->get()->filter(fn (RahaCase $c) => $this->canAccessCase($c, $user))->values();
    }

    /** @throws DomainRuleException 404 when missing or not visible to the user */
    public function findAccessible(string $id, User $user): RahaCase
    {
        $c = RahaCase::query()->with('tasks')->find($id);
        if (! $c || ! $this->canAccessCase($c, $user)) {
            throw DomainRuleException::notFound('الطلب غير موجود.');
        }

        return $c;
    }

    public static function assertMutable(RahaCase $c): void
    {
        if ($c->isArchived()) {
            throw new DomainRuleException(self::ARCHIVED_READ_ONLY);
        }
    }
}
