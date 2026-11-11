<?php

namespace Modules\Cases\Services;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;

/**
 * Dashboard stage counts (port of front stage_statistics_service.ts).
 * Gap fix: the mock counted every request; counts now include only requests the user can access.
 */
class StageStatistics
{
    public const STAGES = [
        ['key' => 'new', 'label' => 'التدقيق', 'statuses' => ['new']],
        ['key' => 'medical', 'label' => 'اللجنة الطبية', 'statuses' => ['medical_review', 'awaiting_quotation']],
        ['key' => 'social', 'label' => 'البحث الاجتماعي', 'statuses' => ['social_review']],
        ['key' => 'care', 'label' => 'الاعتماد المبدئي', 'statuses' => ['manager_approval']],
        ['key' => 'prelim', 'label' => 'الاعتماد النهائي', 'statuses' => ['preliminary_approved']],
        ['key' => 'in-service', 'label' => 'تقديم الخدمة', 'statuses' => ['assigned', 'scheduled', 'in_progress']],
        ['key' => 'relations', 'label' => 'علاقات المرضى', 'statuses' => ['completed']],
        ['key' => 'finance', 'label' => 'المالية', 'statuses' => ['finance_review']],
        ['key' => 'archived', 'label' => 'الأرشفة', 'statuses' => ['archived']],
    ];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access) {}

    /** @return array{counts: array, summaries: array, totals: array{total: int, completed: int}} */
    public function all(User $user): array
    {
        if (! $user->isStaff()) {
            return ['counts' => [], 'summaries' => [], 'totals' => ['total' => 0, 'completed' => 0]];
        }
        $cases = $this->access->visibleCases($user);
        $count = fn (array $statuses) => $cases->whereIn('status', $statuses)->count();
        $canOpen = fn (array $statuses) => collect($statuses)->contains(fn ($s) => $this->access->canAccessStage($user, $s));

        $counts = array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'count' => $count($s['statuses']), 'canOpen' => $canOpen($s['statuses'])], self::STAGES);
        $groups = WorkflowConfig::statusGroups();
        $summaries = array_map(fn ($key) => $key === ''
            ? ['key' => '', 'count' => $cases->count(), 'canOpen' => $this->perms->has($user, 'cases.view')]
            : ['key' => $key, 'count' => $count($groups[$key]['statuses'] ?? []), 'canOpen' => $canOpen($groups[$key]['statuses'] ?? [])],
            ['', 'new', 'under-review', 'returned', 'in-service', 'completed']);

        return ['counts' => $counts, 'summaries' => $summaries, 'totals' => $this->totals($cases)];
    }

    private function totals(Collection $cases): array
    {
        return ['total' => $cases->count(), 'completed' => $cases->whereIn('status', ['completed', 'finance_review', 'archived'])->count()];
    }
}
