<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\RahaCase;

/** Beneficiary self-service on own requests: survey, service confirmation, evaluation (front caseService portal methods). */
class PortalService
{
    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder) {}

    /** Locks an own request for writing (gap fix: the mock silently ignored writes on foreign requests). */
    private function own(string $id, User $user): RahaCase
    {
        $c = RahaCase::query()->lockForUpdate()->find($id);
        if (! $c || ! $this->access->canAccessCase($c, $user)) {
            throw DomainRuleException::notFound('الطلب غير موجود.');
        }
        CaseAccess::assertMutable($c);

        return $c;
    }

    public function submitSurvey(string $id, User $user, int $ease, int $clarity, string $comment): RahaCase
    {
        return DB::transaction(function () use ($id, $user, $ease, $clarity, $comment) {
            $c = $this->own($id, $user);
            if ($c->request_survey) {
                throw new DomainRuleException('تم إرسال استبيان هذا الطلب مسبقًا.');
            }
            $c->forceFill(['survey_pending' => false, 'request_survey' => ['ease' => $ease, 'clarity' => $clarity, 'comment' => $comment, 'at' => now_iso()]])->save();
            $this->recorder->addActivity($c, "قيّم المستفيد تجربة تقديم الطلب ({$ease}/5).", $user->name, 'both', ['actorId' => $user->id]);
            Activity::emit('تم إرسال استبيان تقديم الطلب', 'طلب', $id, $user);

            return $c->fresh();
        });
    }

    public function confirmService(string $id, User $user): RahaCase
    {
        $this->perms->require($user, 'beneficiary.service.confirmOwn');

        return DB::transaction(function () use ($id, $user) {
            $c = $this->own($id, $user);
            if ($c->status !== 'completed') {
                throw new DomainRuleException('لا يمكن تأكيد استلام الخدمة قبل إنهاء تقديمها.');
            }
            if ($c->service_confirmed) {
                throw new DomainRuleException('تم تأكيد استلام الخدمة مسبقًا.');
            }
            $c->forceFill(['service_confirmed' => true])->save();
            $this->recorder->addActivity($c, 'أكد المستفيد استلام الخدمة.', $user->name, 'both', ['actorId' => $user->id]);
            Activity::emit('أكد المستفيد استلام الخدمة', 'طلب', $id, $user, ['title' => 'أكد المستفيد استلام الخدمة', 'body' => $id, 'roles' => ['relations'], 'caseId' => $id, 'link' => CaseRecorder::link($id)]);

            return $c->fresh();
        });
    }

    public function evaluate(string $id, User $user, int $rating, string $comment): RahaCase
    {
        $this->perms->require($user, 'beneficiary.service.evaluateOwn');

        return DB::transaction(function () use ($id, $user, $rating, $comment) {
            $c = $this->own($id, $user);
            if (! $c->service_confirmed) {
                throw new DomainRuleException('يرجى تأكيد استلام الخدمة قبل تقييمها.');
            }
            if ($c->evaluation) {
                throw new DomainRuleException('تم تقييم الخدمة مسبقًا.');
            }
            $c->forceFill(['evaluation' => ['rating' => $rating, 'comment' => $comment, 'at' => now_iso()]])->save();
            $this->recorder->addActivity($c, "قيّم المستفيد الخدمة ({$rating}/5).", $user->name, 'both', ['actorId' => $user->id]);
            Activity::audit('قيّم المستفيد الخدمة', 'طلب', $id, $user);

            return $c->fresh();
        });
    }

    /** Own requests that have an appointment, latest date first (front AppointmentsPage). */
    public function appointments(User $user): array
    {
        return RahaCase::query()->where('beneficiary_id', $user->beneficiary_id ?? '__none__')->whereNotNull('appointment')->get()
            ->sortByDesc(fn (RahaCase $c) => $c->appointment['date'] ?? '')->values()
            ->map(fn (RahaCase $c) => ['caseId' => $c->id, 'service' => $c->service, 'provider' => $c->provider, 'status' => $c->status, 'appointment' => $c->appointment])->all();
    }
}
