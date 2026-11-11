<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;

/** Staff/provider case reads and small writes (front caseService list/get/getCaseTimeline/addComment/uploadInvoice). */
class CaseService
{
    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder) {}

    /** @param array{group?: string, q?: string, from?: string, to?: string, includeArchived?: bool} $f */
    public function list(User $user, array $f = []): Collection
    {
        $cases = $this->access->visibleCases($user, true);
        $groups = WorkflowConfig::statusGroups();
        if (! empty($f['group']) && isset($groups[$f['group']])) {
            $cases = $cases->whereIn('status', $groups[$f['group']]['statuses']);
        } elseif (empty($f['includeArchived'])) {
            $cases = $cases->where('status', '!=', 'archived');
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $cases = $cases->filter(fn (RahaCase $c) => str_contains("{$c->id} {$c->beneficiary} {$c->service} {$c->national_id}", $q));
        }
        if (! empty($f['from'])) {
            $cases = $cases->filter(fn (RahaCase $c) => $c->created_at->toDateString() >= $f['from']);
        }
        if (! empty($f['to'])) {
            $cases = $cases->filter(fn (RahaCase $c) => $c->created_at->toDateString() <= $f['to']);
        }

        return $cases->values();
    }

    public function timeline(string $id, User $user): Collection
    {
        $c = $this->access->findAccessible($id, $user);
        $staff = $user->isStaff();

        return $c->activities->filter(fn (CaseActivity $a) => $staff ? $this->perms->has($user, 'workflow.history.view') : $a->visibility !== 'internal')->values();
    }

    public function addComment(string $id, User $user, string $text, string $visibility): RahaCase
    {
        return DB::transaction(function () use ($id, $user, $text, $visibility) {
            $c = RahaCase::query()->lockForUpdate()->with('tasks')->find($id);
            if (! $c || ! $user->isStaff() || ! $this->access->canAccessCase($c, $user)
                || ! $this->perms->has($user, $visibility === 'internal' ? 'comments.internal.create' : 'comments.beneficiary.create')) {
                throw DomainRuleException::forbidden('لا تملك صلاحية إضافة هذه الملاحظة.');
            }
            CaseAccess::assertMutable($c);
            if (trim($text) === '') {
                throw new DomainRuleException('اكتب الملاحظة أولًا.');
            }
            $this->recorder->addComment($c, trim($text), $user->name, $visibility, $user->id);
            $this->recorder->addActivity($c, $visibility === 'internal' ? 'أضيفت ملاحظة داخلية.' : 'أضيفت ملاحظة للمستفيد.', $user->name, $visibility, ['actorId' => $user->id]);
            Activity::emit('أضيفت ملاحظة', 'طلب', $id, $user);

            return $c->fresh();
        });
    }

    /** Gap fix: the mock only checked ownership/status; now also service.deliver, archived and a single invoice. */
    public function uploadInvoice(string $id, User $user, float $amount): RahaCase
    {
        return DB::transaction(function () use ($id, $user, $amount) {
            $c = RahaCase::query()->lockForUpdate()->find($id);
            if (! $c || ! $user->isProvider() || ! $user->provider_id || $c->provider_id !== $user->provider_id || ! in_array($c->status, ['completed', 'finance_review'], true)
                || ! $this->perms->has($user, 'service.deliver')) {
                throw new DomainRuleException('لا يمكن رفع فاتورة لهذا الطلب.');
            }
            if ($c->invoice) {
                throw new DomainRuleException('تم رفع فاتورة لهذا الطلب مسبقًا.');
            }
            if (! ($amount > 0)) {
                throw new DomainRuleException('أدخل مبلغًا صحيحًا.');
            }
            $c->forceFill(['invoice' => ['number' => 'INV-'.substr($id, 4), 'amount' => $amount, 'at' => now_iso(), 'status' => 'pending']])->save();
            $shown = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
            $this->recorder->addActivity($c, "رفع مقدم الخدمة الفاتورة بمبلغ {$shown} ر.س", $user->name, 'internal', ['actorId' => $user->id]);
            Activity::emit('تم رفع فاتورة', 'طلب', $id, $user, ['title' => 'فاتورة جديدة من مقدم خدمة', 'body' => "{$id} — {$shown} ر.س", 'roles' => ['finance'], 'caseId' => $id, 'link' => CaseRecorder::link($id)]);

            return $c->fresh();
        });
    }
}
