<?php

namespace Modules\Visits\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseRecorder;
use Modules\Cases\Services\VisitGuard;

/** Port of front visit_plan_service.ts (Task 05: approved visit count on the request's single plan). */
class VisitPlanService
{
    /** Stages where the approved visit count may still be finalized (existing review/approval stages; no new step). */
    public const EDITABLE_STATUSES = ['new', 'medical_review', 'social_review', 'manager_approval', 'awaiting_quotation', 'preliminary_approved'];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder) {}

    /** Provider-safe projection: read-only, no catalog defaults or change history. */
    public static function providerView(RahaCase $c): ?array
    {
        $p = $c->visit_plan;
        if (! $p) {
            return null;
        }
        $out = ['deliveryType' => $p['deliveryType']];
        if (! empty($p['approvedVisitCount'])) {
            $out['approvedVisitCount'] = $p['approvedVisitCount'];
        }

        return $out;
    }

    /** Why the count cannot be edited right now, or null when it can. */
    public function lockReason(RahaCase $c): ?string
    {
        $p = $c->visit_plan;

        return match (true) {
            ! $p => 'هذا الطلب لا يحتوي على خطة زيارات.',
            ($p['deliveryType'] ?? null) !== 'multiple_visits' => 'الخدمة تُنفَّذ لمرة واحدة ولا تتطلب عدد زيارات.',
            $c->status === 'archived' => CaseAccess::ARCHIVED_READ_ONLY,
            ! in_array($c->status, self::EDITABLE_STATUSES, true) => 'لا يمكن تعديل عدد الزيارات بعد هذه المرحلة.',
            ServiceAuthorization::query()->where('case_id', $c->id)->where('status', 'ISSUED')->exists() => 'صدر خطاب تعميد لهذا الطلب؛ لا يمكن تعديل عدد الزيارات.',
            default => null,
        };
    }

    private function mayConfigure(?User $u): bool
    {
        return $u !== null && $u->isStaff() && ! $u->provider_id && $this->perms->has($u, 'visits.configure');
    }

    public function canEdit(RahaCase $c, ?User $u): bool
    {
        return $this->mayConfigure($u) && $this->lockReason($c) === null;
    }

    /** GET cases/{id}/visit-plan payload: staff get the full plan + lock state, providers the read-only projection. */
    public function view(string $caseId, User $u): array
    {
        $c = $this->access->findAccessible($caseId, $u);
        if (! $u->isStaff()) {
            return ['providerView' => self::providerView($c)];
        }

        return ['plan' => $c->visit_plan, 'providerView' => self::providerView($c), 'lockReason' => $this->lockReason($c), 'canEdit' => $this->canEdit($c, $u)];
    }

    /** Upserts the single approved count on the request's plan; request status and workflow are untouched. */
    public function setApprovedVisitCount(string $caseId, mixed $count, User $u): array
    {
        if (! $this->mayConfigure($u)) {
            throw DomainRuleException::forbidden('لا تملك صلاحية تحديد عدد الزيارات.');
        }
        DB::transaction(function () use ($caseId, $count, $u) {
            $this->access->findAccessible($caseId, $u);
            $c = RahaCase::query()->with('tasks')->lockForUpdate()->findOrFail($caseId);
            CaseAccess::assertMutable($c);
            if ($lock = $this->lockReason($c)) {
                throw new DomainRuleException($lock);
            }
            if (! VisitGuard::isPositiveInt($count)) {
                throw new DomainRuleException('عدد الزيارات يجب أن يكون عددًا صحيحًا موجبًا.');
            }
            $plan = $c->visit_plan;
            $prev = $plan['approvedVisitCount'] ?? null;
            if ($prev === $count) {
                return;
            }
            $at = now_iso();
            $change = ['at' => $at, 'by' => $u->name] + ($prev ? ['from' => $prev] : []) + ['to' => $count];
            $plan = array_merge($plan, ['approvedVisitCount' => $count, 'approvedBy' => $u->name, 'approvedAt' => $at, 'changes' => [...($plan['changes'] ?? []), $change]]);
            $c->visit_plan = $plan;
            $c->save();
            $text = $prev ? "عدّل {$u->name} عدد الزيارات المعتمد من {$prev} إلى {$count}." : "حدد {$u->name} عدد الزيارات المعتمد: {$count}.";
            $this->recorder->addActivity($c, $text, CaseRecorder::actorLabel($u), 'internal', ['action' => 'visit_count', 'actorId' => $u->id]);
            Activity::emit('تم تحديد عدد الزيارات المعتمد', 'طلب', $c->id, $u);
        });

        return $this->view($caseId, $u);
    }
}
