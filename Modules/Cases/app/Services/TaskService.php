<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;

/** Review-stage tasks: claim / release / reassign, queues (port of front task_service.ts). */
class TaskService
{
    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseActions $actions, private CaseRecorder $recorder) {}

    public function queueEligible(RahaCase $c, ?User $u): bool
    {
        return $u !== null && $u->isStaff() && $this->access->canAccessCase($c, $u) && WorkflowConfig::reviewStageOf($c->status) !== null
            && $this->perms->has($u, 'requests.claim') && collect($this->actions->actionsFor($c->status, $u))->contains(fn ($a) => ! empty($a['form']));
    }

    /** Creates the current-stage task when the case sits in a review stage without one (never invents past decisions). */
    public function withCurrentTask(RahaCase $c, mixed $at = null): RahaCase
    {
        $c->loadMissing('tasks');
        $stage = WorkflowConfig::reviewStageOf($c->status);
        if (! $stage || $c->activeTask()) {
            return $c;
        }
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();
        $hours = WorkflowConfig::slaHours($c->status);
        $count = CaseTask::query()->where('case_id', $c->id)->count();
        CaseTask::query()->create([
            'id' => "task-{$c->id}-{$count}-{$c->status}-".uid(4), 'case_id' => $c->id, 'stage' => $c->status, 'role' => $stage['role'], 'created_at' => $at,
            'due_at' => $hours !== null ? $at->copy()->addHours($hours) : null, 'status' => 'new', 'priority' => $c->priority ? 'high' : 'normal', 'history' => [],
        ]);
        $c->unsetRelation('tasks');
        $c->load('tasks');

        return $c;
    }

    public function finishTask(RahaCase $c, User $user, string $outcome, mixed $at): void
    {
        $t = $c->activeTask();
        if (! $t) {
            return;
        }
        $t->forceFill([
            'status' => 'completed', 'completed_at' => $at, 'completed_by' => $user->id, 'completed_by_id' => $user->id, 'outcome' => $outcome,
            'history' => array_merge($t->history ?? [], [['at' => iso($at), 'by' => $user->name, 'text' => $outcome]]),
        ])->save();
        $c->unsetRelation('tasks');
    }

    private function need(User $u, string $perm): void
    {
        if (! $u->isStaff() || ! $this->perms->has($u, $perm)) {
            throw DomainRuleException::forbidden('لا تملك صلاحية إدارة هذه المهمة.');
        }
    }

    public function claim(string $id, User $user): RahaCase
    {
        return $this->change($id, $user, 'claim');
    }

    public function release(string $id, User $user): RahaCase
    {
        return $this->change($id, $user, 'release');
    }

    public function reassign(string $id, User $user, ?string $targetId): RahaCase
    {
        return $this->change($id, $user, 'reassign', $targetId);
    }

    private function change(string $id, User $user, string $kind, ?string $targetId = null): RahaCase
    {
        $this->need($user, match ($kind) { 'claim' => 'requests.claim', 'release' => 'requests.release', default => 'requests.reassign' });

        return DB::transaction(function () use ($id, $user, $kind, $targetId) {
            $c = RahaCase::query()->lockForUpdate()->find($id);
            if (! $c) {
                throw DomainRuleException::notFound('الطلب غير موجود.');
            }
            $c->load('tasks');
            if (! $this->access->canAccessCase($c, $user)
                && ! ($kind === 'reassign' && $this->perms->has($user, 'tasks.manage') && $this->perms->scopeOf($user, 'cases.view') === 'ALL')) {
                throw DomainRuleException::forbidden('لا تملك صلاحية الوصول إلى هذا الطلب.');
            }
            CaseAccess::assertMutable($c);
            $c = $this->withCurrentTask($c);
            $t = $c->activeTask();
            if (! $t) {
                throw new DomainRuleException('لا توجد مهمة نشطة في هذه المرحلة.');
            }
            if ($kind === 'claim' && (! $this->queueEligible($c, $user) || $t->assigned_user_id)) {
                throw new DomainRuleException($t->assigned_user_id ? 'المهمة مستلمة بالفعل.' : 'المهمة خارج طابورك المسموح.');
            }
            if ($kind === 'release' && $t->assigned_user_id !== $user->id) {
                throw new DomainRuleException('يمكنك تحرير مهامك فقط.');
            }
            if ($kind === 'reassign' && $t->assigned_user_id && $t->assigned_user_id !== $user->id && ! $this->perms->has($user, 'tasks.manage')) {
                throw DomainRuleException::forbidden('لا تملك صلاحية إدارة هذه المهمة.');
            }
            $target = match ($kind) {
                'claim' => $user,
                'reassign' => $targetId ? User::query()->find($targetId) : null,
                default => null,
            };
            if ($kind === 'reassign' && (! $target || ! $this->assignable($target) || ! $this->queueEligible($c, $target))) {
                throw new DomainRuleException('اختر موظفًا مؤهلًا لهذه المرحلة.');
            }
            $at = now();
            $label = match ($kind) { 'claim' => 'تم استلام المهمة', 'release' => 'أعيدت المهمة لطابور العمل', default => 'تمت إعادة إسناد المهمة' };
            $text = $label.($target ? " — {$target->name}" : '');
            $t->forceFill([
                'assigned_user_id' => $target?->id, 'assigned_user_name' => $target?->name, 'claimed_at' => $target ? $at : null,
                'status' => $target ? 'in_progress' : 'new', 'history' => array_merge($t->history ?? [], [['at' => iso($at), 'text' => $text, 'by' => $user->name]]),
            ])->save();
            $this->recorder->addActivity($c, $text, $user->name, 'internal', ['action' => $kind, 'fromStage' => $c->status, 'toStage' => $c->status, 'actorId' => $user->id, 'at' => $at]);
            Activity::emit($label, 'طلب', $id, $user, ['title' => $label, 'body' => $id, 'roles' => [$t->role], 'userId' => $target?->id, 'caseId' => $id, 'link' => CaseRecorder::link($id)]);
            $c->unsetRelation('tasks');

            return $c->fresh();
        });
    }

    private function assignable(User $u): bool
    {
        return $u->active && ! $u->suspended && ! $u->isLocked();
    }

    /** Staff who could take the current task (front eligibleUsers). */
    public function eligibleUsers(RahaCase $c): Collection
    {
        $this->withCurrentTask($c);

        return User::query()->where('account_type', 'STAFF')->get()->filter(fn (User $u) => $this->assignable($u) && $this->queueEligible($c, $u))->values();
    }

    /** Unclaimed tasks the user may take. */
    public function workQueue(Collection $cases, User $user): Collection
    {
        return $cases->filter(fn (RahaCase $c) => $this->queueEligible($c, $user) && ! $c->activeTask()?->assigned_user_id)->values();
    }

    public function myTasks(Collection $cases, User $user): Collection
    {
        return $cases->filter(fn (RahaCase $c) => $c->activeTask()?->assigned_user_id === $user->id && $this->queueEligible($c, $user))->values();
    }

    /** Task rows for the front tasks page: ?view=my|queue|completed|downstream. */
    public function view(string $view, User $user): Collection
    {
        $cases = $this->access->visibleCases($user, true);
        $downstream = fn () => $cases->filter(fn (RahaCase $c) => ! $c->activeTask() && $this->actions->actionsForCase($c, $user) !== [])->values();

        return match ($view) {
            'queue' => $this->workQueue($cases, $user),
            'completed' => $cases->filter(fn (RahaCase $c) => $c->tasks->contains(fn (CaseTask $t) => $t->completed_by === $user->id))->values(),
            'downstream' => $downstream(),
            default => $this->myTasks($cases, $user)->concat($downstream())->values(),
        };
    }

    public static function taskFront(?CaseTask $t): ?array
    {
        if (! $t) {
            return null;
        }
        $out = $t->toFront();
        $out['viewStatus'] = $t->viewStatus();
        $out['overdue'] = $t->viewStatus() === 'overdue';

        return $out;
    }

}
