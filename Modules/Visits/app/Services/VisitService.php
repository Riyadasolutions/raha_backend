<?php

namespace Modules\Visits\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Authorizations\Models\ServiceAssignment;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseRecorder;
use Modules\Cases\Services\VisitGuard;
use Modules\Visits\Models\VisitRecord;

/**
 * Port of front visit_service.ts (Task 06 visit records). Every check of the mock (permissions, ownership, caps,
 * sequence uniqueness) is enforced here; writes that check-then-write lock the case row.
 */
class VisitService
{
    /** Request statuses in which visits may be recorded; archived requests are read-only. */
    public const OPEN_STATUSES = ['assigned', 'scheduled', 'in_progress', 'completed'];

    /** Statuses in which the approved count may be adjusted after assignment (before assignment use VisitPlanService). */
    public const ADJUST_STATUSES = ['assigned', 'scheduled', 'in_progress', 'completed'];

    private const CLOCK_SKEW_MS = 5 * 60 * 1000;

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder) {}

    private static function fail(string $m, int $status = 422): never
    {
        throw new DomainRuleException($m, $status);
    }

    public static function stamp(User $u): string
    {
        return "{$u->name} — {$u->role_label}";
    }

    /** Valid ISO-like date-time string (front validDate = Date.parse is finite). */
    public static function parseDate(mixed $v): ?Carbon
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', trim($v))) {
            return null;
        }
        try {
            return Carbon::parse(trim($v))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Who may act: the assigned provider (own request only) or staff holding the given permission. */
    private function actorFor(RahaCase $c, ?User $u, string $staffPerm, bool $allowProvider = true): string
    {
        if (! $u) {
            self::fail('لا تملك صلاحية إدارة الزيارات.', 403);
        }
        if ($u->isProvider()) {
            if ($allowProvider && $u->provider_id && $c->provider_id === $u->provider_id && $this->perms->has($u, 'provider.visits.manageOwn')) {
                return 'provider';
            }
            self::fail('هذا الطلب غير مسند إليك أو لا تملك صلاحية إدارة الزيارات.', 403);
        }
        if ($u->isStaff() && $this->perms->has($u, $staffPerm)) {
            return 'staff';
        }
        self::fail('لا تملك صلاحية إدارة الزيارات.', 403);
    }

    private function loadCase(string $caseId, ?User $u, bool $lock = false): RahaCase
    {
        $q = RahaCase::query()->with('tasks');
        $c = ($lock ? $q->lockForUpdate() : $q)->find($caseId);
        if (! $c || ! $this->access->canSeeVisitCase($c, $u)) {
            self::fail('الطلب غير موجود أو لا تملك صلاحية الوصول إليه.', 404);
        }

        return $c;
    }

    /** @return array{0: VisitRecord, 1: RahaCase} */
    private function visitAndCase(string $visitId, ?User $u, bool $lock = false): array
    {
        $v = VisitRecord::query()->find($visitId) ?? self::fail('الزيارة غير موجودة.', 404);
        $c = $this->loadCase($v->case_id, $u, $lock);
        if ($lock) {
            $v = VisitRecord::query()->lockForUpdate()->find($visitId);
        }

        return [$v, $c];
    }

    private static function planOf(RahaCase $c): array
    {
        $p = $c->visit_plan;
        if (! $p || ($p['deliveryType'] ?? null) !== 'multiple_visits') {
            self::fail('تتبع الزيارات متاح للخدمات متعددة الزيارات فقط.');
        }
        if (! VisitGuard::isPositiveInt($p['approvedVisitCount'] ?? null)) {
            self::fail('لم يُحدد عدد الزيارات المعتمد لهذا الطلب.');
        }

        return $p;
    }

    private static function openCase(RahaCase $c): void
    {
        CaseAccess::assertMutable($c);
        if (! $c->provider_id || ! in_array($c->status, self::OPEN_STATUSES, true)) {
            self::fail('لا يمكن تسجيل زيارات لهذا الطلب في مرحلته الحالية.');
        }
    }

    /** @return Collection<int, VisitRecord> */
    public static function visitsOf(string $caseId): Collection
    {
        return VisitRecord::query()->where('case_id', $caseId)->orderBy('sequence')->orderBy('created_at')->get();
    }

    private static function nextSequence(Collection $visits, int $approved): ?int
    {
        $used = $visits->filter(fn (VisitRecord $v) => VisitProgress::isActive($v->status))->pluck('sequence')->all();
        for ($n = 1; $n <= $approved; $n++) {
            if (! in_array($n, $used, true)) {
                return $n;
            }
        }

        return null;
    }

    private static function log(VisitRecord $v, string $by, string $action, ?string $detail = null): VisitRecord
    {
        $v->audit = [...($v->audit ?? []), ['at' => now_iso(), 'by' => $by, 'action' => $action] + ($detail ? ['detail' => $detail] : [])];
        $v->updated_at = now();

        return $v;
    }

    private static function ownVisit(string $actor, VisitRecord $v, User $u): void
    {
        if ($actor === 'provider' && $v->provider_id !== $u->provider_id) {
            self::fail('هذه الزيارة تخص مقدم خدمة آخر.', 403);
        }
    }

    private function mayRead(?User $u): bool
    {
        if (! $u) {
            return false;
        }
        if ($u->isProvider()) {
            return (bool) $u->provider_id && $this->perms->has($u, 'provider.visits.manageOwn');
        }

        return $u->isStaff() && ($this->perms->has($u, 'visits.monitor') || $this->perms->has($u, 'visits.schedule'));
    }

    /**
     * Read access: the owning provider or staff with a visit permission AND case access. Providers never receive other
     * providers' visits. Returns the list plus the progress computed over exactly those visits (front VisitManager).
     */
    public function forCase(string $caseId, ?User $u): array
    {
        $c = RahaCase::query()->with('tasks')->find($caseId);
        if (! $this->mayRead($u) || ! $c || ! $this->access->canSeeVisitCase($c, $u)) {
            return ['visits' => collect(), 'approvedVisitCount' => null, 'progress' => null];
        }
        $visits = self::visitsOf($caseId)->filter(fn (VisitRecord $v) => ! $u->isProvider() || $v->provider_id === $u->provider_id)->values();
        $approved = $c->visit_plan['approvedVisitCount'] ?? null;
        $multi = ($c->visit_plan['deliveryType'] ?? null) === 'multiple_visits' && VisitGuard::isPositiveInt($approved);

        return ['visits' => $visits, 'approvedVisitCount' => $multi ? $approved : null, 'progress' => $multi ? VisitProgress::compute($approved, $visits) : null];
    }

    /** All of the provider's own visits (history), newest first. */
    public function forProvider(?User $u): Collection
    {
        if (! $u || ! $u->isProvider() || ! $u->provider_id || ! $this->perms->has($u, 'provider.visits.manageOwn')) {
            return collect();
        }

        return VisitRecord::query()->where('provider_id', $u->provider_id)->orderByDesc('scheduled_at')->get();
    }

    /** Summary for an already authorized case; null for one-time services and historical requests. */
    public static function completionState(RahaCase $c, ?int $now = null): ?array
    {
        $p = $c->visit_plan;
        if (! $p || ($p['deliveryType'] ?? null) !== 'multiple_visits' || ! VisitGuard::isPositiveInt($p['approvedVisitCount'] ?? null)) {
            return null;
        }

        return VisitProgress::compute($p['approvedVisitCount'], self::visitsOf($c->id), $now);
    }

    /** @param array{scheduledAt?: mixed, sequence?: mixed} $input */
    public function schedule(string $caseId, array $input, User $u): VisitRecord
    {
        return DB::transaction(function () use ($caseId, $input, $u) {
            $c = $this->loadCase($caseId, $u, true);
            $actor = $this->actorFor($c, $u, 'visits.schedule');
            $plan = self::planOf($c);
            self::openCase($c);
            $approved = $plan['approvedVisitCount'];
            $assignment = ServiceAssignment::query()->where('case_id', $c->id)->where('provider_id', $c->provider_id)->orderByDesc('assigned_at')->first();
            if ($actor === 'provider' && $assignment?->status !== 'ACKNOWLEDGED') {
                self::fail('أكد استلام التعميد أولًا قبل جدولة الزيارات.');
            }
            $at = self::parseDate($input['scheduledAt'] ?? null) ?? self::fail('أدخل موعد زيارة صحيحًا.');
            $existing = self::visitsOf($caseId);
            $active = $existing->filter(fn (VisitRecord $v) => VisitProgress::isActive($v->status));
            if ($active->count() >= $approved) {
                self::fail('بلغ عدد الزيارات المجدولة والمكتملة العدد المعتمد؛ لا يمكن إضافة زيارات إلا بتعديل معتمد للعدد.');
            }
            $sequence = $input['sequence'] ?? null;
            if ($sequence === null) {
                $sequence = self::nextSequence($existing, $approved) ?? self::fail('لا يوجد رقم زيارة شاغر.');
            }
            if (! VisitGuard::isPositiveInt($sequence) || $sequence > $approved) {
                self::fail('رقم الزيارة يجب أن يكون عددًا صحيحًا بين 1 والعدد المعتمد.');
            }
            if ($active->contains(fn (VisitRecord $v) => $v->sequence === $sequence)) {
                self::fail("رقم الزيارة {$sequence} مستخدم لزيارة أخرى لهذا الطلب.");
            }
            $id = 'VIS-'.preg_replace('/\D/', '', $c->id).'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT).'-'.uid();
            while (VisitRecord::query()->whereKey($id)->exists()) {
                $id .= 'x';
            }
            $stamp = self::stamp($u);
            $record = VisitRecord::query()->create([
                'id' => $id, 'case_id' => $c->id, 'assignment_id' => $assignment?->id, 'provider_id' => $c->provider_id, 'sequence' => $sequence,
                'scheduled_at' => $at, 'status' => 'scheduled', 'created_by' => $stamp,
                'audit' => [['at' => now_iso(), 'by' => $stamp, 'action' => 'scheduled', 'detail' => "الزيارة {$sequence} من {$approved}"]], 'corrections' => [],
            ]);
            Activity::emit('تمت جدولة زيارة', 'زيارة', $id, $u, $actor === 'staff' ? [
                'title' => 'تمت جدولة زيارة لطلب مسند إليكم', 'body' => "{$caseId} — الزيارة {$sequence}", 'roles' => ['provider'],
                'providerId' => $c->provider_id, 'caseId' => $c->id, 'link' => ['to' => '/provider/assignments'],
            ] : null);

            return $record->refresh();
        });
    }

    /** @param array{completedAt?: mixed, notes?: ?string, evidence?: ?string} $input */
    public function complete(string $visitId, array $input, User $u): VisitRecord
    {
        return DB::transaction(function () use ($visitId, $input, $u) {
            [$v, $c] = $this->visitAndCase($visitId, $u, true);
            $actor = $this->actorFor($c, $u, 'visits.schedule');
            self::ownVisit($actor, $v, $u);
            self::openCase($c);
            if ($v->status === 'completed') {
                self::fail('تم تسجيل إكمال هذه الزيارة مسبقًا. استخدم التصحيح المعتمد عند الحاجة.');
            }
            if ($v->status !== 'scheduled') {
                self::fail('لا يمكن إكمال زيارة غير مجدولة (ملغاة أو لم تتم).');
            }
            $at = self::parseDate($input['completedAt'] ?? null) ?? self::fail('أدخل تاريخ ووقت إكمال صحيحين.');
            if ($at->getTimestampMs() > VisitProgress::nowMs() + self::CLOCK_SKEW_MS) {
                self::fail('لا يمكن تسجيل وقت إكمال في المستقبل.');
            }
            $notes = trim((string) ($input['notes'] ?? ''));
            $evidence = trim((string) ($input['evidence'] ?? ''));
            $v->fill(['status' => 'completed', 'completed_at' => $at, 'completed_by' => self::stamp($u), 'notes' => $notes !== '' ? $notes : $v->notes, 'evidence' => $evidence !== '' ? $evidence : $v->evidence]);
            self::log($v, self::stamp($u), 'completed')->save();
            Activity::emit('تم إكمال زيارة', 'زيارة', $v->id, $u);

            return $v;
        });
    }

    public function markMissed(string $visitId, ?string $reason, User $u): VisitRecord
    {
        return DB::transaction(function () use ($visitId, $reason, $u) {
            [$v, $c] = $this->visitAndCase($visitId, $u, true);
            $actor = $this->actorFor($c, $u, 'visits.schedule');
            self::ownVisit($actor, $v, $u);
            self::openCase($c);
            if ($v->status !== 'scheduled') {
                self::fail('يمكن تسجيل «لم تتم» لزيارة مجدولة فقط.');
            }
            if ($v->scheduled_at->getTimestampMs() > VisitProgress::nowMs()) {
                self::fail('لا يمكن تسجيل زيارة مستقبلية على أنها لم تتم.');
            }
            $reason = trim((string) $reason);
            $v->fill(['status' => 'missed', 'missed_reason' => $reason !== '' ? $reason : null]);
            self::log($v, self::stamp($u), 'missed', $reason !== '' ? $reason : null)->save();
            Activity::emit('زيارة لم تتم', 'زيارة', $v->id, $u, [
                'title' => 'زيارة لم تتم', 'body' => "{$c->id} — الزيارة {$v->sequence}", 'roles' => ['manager'], 'caseId' => $c->id, 'link' => CaseRecorder::link($c->id),
            ]);

            return $v;
        });
    }

    public function cancel(string $visitId, ?string $reason, User $u): VisitRecord
    {
        return DB::transaction(function () use ($visitId, $reason, $u) {
            [$v, $c] = $this->visitAndCase($visitId, $u, true);
            $actor = $this->actorFor($c, $u, 'visits.schedule');
            self::ownVisit($actor, $v, $u);
            self::openCase($c);
            if ($v->status !== 'scheduled') {
                self::fail('يمكن إلغاء زيارة مجدولة فقط.');
            }
            $reason = trim((string) $reason);
            if ($reason === '') {
                self::fail('اذكر سبب الإلغاء.');
            }
            $v->fill(['status' => 'cancelled', 'cancel_reason' => $reason]);
            self::log($v, self::stamp($u), 'cancelled', $reason)->save();
            Activity::emit('تم إلغاء زيارة', 'زيارة', $v->id, $u);

            return $v;
        });
    }

    /**
     * Permission-controlled correction (staff with visits.correct); previous values are retained in `corrections` and the audit trail.
     *
     * @param array{status?: ?string, completedAt?: ?string, notes?: ?string} $patch
     */
    public function correct(string $visitId, array $patch, ?string $reason, User $u): VisitRecord
    {
        return DB::transaction(function () use ($visitId, $patch, $reason, $u) {
            [$v, $c] = $this->visitAndCase($visitId, $u, true);
            $this->actorFor($c, $u, 'visits.correct', false);
            CaseAccess::assertMutable($c);
            $reason = trim((string) $reason);
            if ($reason === '') {
                self::fail('اذكر سبب التصحيح.');
            }
            $status = $patch['status'] ?? $v->status;
            $completedAt = null;
            if ($status === 'completed') {
                $raw = $patch['completedAt'] ?? null;
                $completedAt = $raw !== null ? self::parseDate($raw) : $v->completed_at?->copy();
                if (! $completedAt) {
                    self::fail('أدخل تاريخ ووقت إكمال صحيحين.');
                }
                if ($completedAt->getTimestampMs() > VisitProgress::nowMs() + self::CLOCK_SKEW_MS) {
                    self::fail('لا يمكن تسجيل وقت إكمال في المستقبل.');
                }
            }
            if (VisitProgress::isActive($status) && ! VisitProgress::isActive($v->status)) {
                $plan = self::planOf($c);
                $others = self::visitsOf($v->case_id)->filter(fn (VisitRecord $x) => $x->id !== $v->id && VisitProgress::isActive($x->status));
                if ($others->count() >= $plan['approvedVisitCount']) {
                    self::fail('لا يمكن إعادة تفعيل الزيارة: بلغ العدد المعتمد.');
                }
                if ($others->contains(fn (VisitRecord $x) => $x->sequence === $v->sequence)) {
                    self::fail("رقم الزيارة {$v->sequence} مستخدم لزيارة أخرى.");
                }
            }
            $stamp = self::stamp($u);
            $previous = array_filter(['status' => $v->status, 'completedAt' => iso($v->completed_at), 'notes' => $v->notes], fn ($x) => $x !== null);
            $notes = array_key_exists('notes', $patch) && $patch['notes'] !== null ? (trim($patch['notes']) !== '' ? trim($patch['notes']) : null) : $v->notes;
            $v->fill([
                'status' => $status, 'completed_at' => $status === 'completed' ? $completedAt : null,
                'completed_by' => $status === 'completed' ? ($v->completed_by ?? $stamp) : null, 'notes' => $notes,
                'corrections' => [...($v->corrections ?? []), ['at' => now_iso(), 'by' => $stamp, 'reason' => $reason, 'previous' => $previous]],
            ]);
            self::log($v, $stamp, 'corrected', $reason)->save();
            Activity::emit('تم تصحيح زيارة', 'زيارة', $v->id, $u);

            return $v;
        });
    }

    /**
     * Changes the approved count after assignment. Increases just allow more visits. A reduction never deletes history:
     * it is rejected below the completed count, and cutting into scheduled visits needs the explicit `cancel_excess_scheduled` resolution.
     * Raising the count after the service was completed never reopens the workflow; it sets visitPlan.reviewFlag instead.
     */
    public function adjustApprovedVisitCount(string $caseId, mixed $newCount, ?string $reason, User $u, ?string $resolution = null): RahaCase
    {
        return DB::transaction(function () use ($caseId, $newCount, $reason, $u, $resolution) {
            $c = $this->loadCase($caseId, $u, true);
            $this->actorFor($c, $u, 'visits.configure', false);
            CaseAccess::assertMutable($c);
            $plan = self::planOf($c);
            if (! in_array($c->status, self::ADJUST_STATUSES, true)) {
                self::fail('لا يمكن تعديل عدد الزيارات في مرحلة الطلب الحالية.');
            }
            if (! VisitGuard::isPositiveInt($newCount)) {
                self::fail('عدد الزيارات يجب أن يكون عددًا صحيحًا موجبًا.');
            }
            $reason = trim((string) $reason);
            if ($reason === '') {
                self::fail('اذكر سبب تعديل عدد الزيارات.');
            }
            $from = $plan['approvedVisitCount'];
            if ($newCount === $from) {
                return $c;
            }
            $visits = self::visitsOf($caseId);
            $active = $visits->filter(fn (VisitRecord $v) => VisitProgress::isActive($v->status));
            $completed = $visits->where('status', 'completed')->count();
            if ($newCount < $completed) {
                self::fail("لا يمكن تخفيض العدد إلى أقل من الزيارات المكتملة ({$completed}).");
            }
            $excess = $active->count() - $newCount;
            if ($excess > 0 && $resolution !== 'cancel_excess_scheduled') {
                self::fail("يوجد {$excess} زيارة مجدولة زائدة عن العدد الجديد؛ ألغِها أولًا أو اختر الإلغاء الصريح.");
            }
            $stamp = self::stamp($u);
            if ($excess > 0) {
                $visits->where('status', 'scheduled')->sortByDesc('sequence')->take($excess)->each(function (VisitRecord $v) use ($reason, $stamp) {
                    $v->fill(['status' => 'cancelled', 'cancel_reason' => "تخفيض العدد المعتمد: {$reason}"]);
                    self::log($v, $stamp, 'cancelled', 'تخفيض العدد المعتمد')->save();
                });
            }
            $at = now_iso();
            $flag = $c->status === 'completed' && $newCount > $from && $newCount > $completed ? ['at' => $at, 'by' => $u->name, 'from' => $from, 'to' => $newCount] : null;
            $c->visit_plan = array_merge($plan, $flag ? ['reviewFlag' => $flag] : [], [
                'approvedVisitCount' => $newCount, 'approvedBy' => $u->name, 'approvedAt' => $at,
                'changes' => [...($plan['changes'] ?? []), ['at' => $at, 'by' => $u->name, 'from' => $from, 'to' => $newCount]],
            ]);
            $c->save();
            $this->recorder->addActivity($c, "عدّل {$u->name} عدد الزيارات المعتمد من {$from} إلى {$newCount}: {$reason}", $stamp, 'internal', ['action' => 'visit_count', 'actorId' => $u->id]);
            Activity::emit('تم تعديل عدد الزيارات المعتمد بعد الإسناد', 'طلب', $caseId, $u);

            return $c;
        });
    }
}
