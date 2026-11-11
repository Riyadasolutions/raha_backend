<?php

namespace Modules\Quotations\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Upload;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseRecorder;
use Modules\Cases\Services\WorkflowService;
use Modules\Catalog\Models\Provider;
use Modules\Catalog\Services\ProviderBranding;
use Modules\Quotations\Models\ProviderQuotation;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;

/**
 * Port of front quotation_service.ts. Staff manage rounds (create + invite, extend, close, cancel, evaluate, select);
 * providers see only their own invitations and quotations (draft, submit, decline). Every write runs in a transaction
 * with the round row locked.
 */
class QuotationService
{
    public const NO_PERMISSION = 'لا تملك صلاحية تنفيذ هذا الإجراء.';

    public const ROUND_NOT_FOUND = 'طلب التسعير غير موجود.';

    public const INVITATION_NOT_FOUND = 'دعوة التسعير غير موجودة.';

    public const RECORD_TYPE = 'طلب تسعير';

    public function __construct(
        private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder, private WorkflowService $workflow,
    ) {}

    /* ---------------------------------------------------------------- helpers */

    private function staffNeed(User $u, string $perm): void
    {
        if (! $u->isStaff() || ! $this->perms->has($u, $perm)) {
            throw DomainRuleException::forbidden(self::NO_PERMISSION);
        }
    }

    public static function staffLink(QuotationRound $r): array
    {
        return ['to' => '/quotations/$roundId', 'params' => ['roundId' => $r->id]];
    }

    public static function providerLink(QuotationInvitation $i): array
    {
        return ['to' => '/provider/quotations/$invitationId', 'params' => ['invitationId' => $i->id]];
    }

    private function caseOf(QuotationRound $r): RahaCase
    {
        return RahaCase::query()->with('tasks')->find($r->case_id) ?? QuotationRules::fail('الطلب غير موجود.', 404);
    }

    /** front getRound(): exists, case accessible, case not archived. Locks the round row. */
    private function lockRound(string $id, User $u): QuotationRound
    {
        $r = QuotationRound::query()->lockForUpdate()->find($id) ?? QuotationRules::fail(self::ROUND_NOT_FOUND, 404);
        $c = $this->caseOf($r);
        if (! $this->access->canAccessCase($c, $u)) {
            throw DomainRuleException::forbidden('لا تملك صلاحية الوصول إلى هذا الطلب.');
        }
        CaseAccess::assertMutable($c);
        $r->load(['invitations', 'quotations']);

        return $r;
    }

    private function log(QuotationRound $r, User $u, string $text): void
    {
        $r->history = [...($r->history ?? []), ['at' => now_iso(), 'by' => CaseRecorder::actorLabel($u), 'text' => $text]];
        $r->updated_at = now();
        $r->save();
    }

    private function caseNote(string $caseId, User $u, string $text): void
    {
        $c = RahaCase::query()->find($caseId);
        if ($c) {
            $this->recorder->addActivity($c, $text, CaseRecorder::actorLabel($u), 'internal', ['action' => 'quotation', 'actorId' => $u->id]);
        }
    }

    private static function activeRound(string $caseId): ?QuotationRound
    {
        return QuotationRound::query()->where('case_id', $caseId)->whereNotIn('status', ['cancelled', 'selected'])->first();
    }

    private static function parseDate(mixed $v): ?Carbon
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    /* ---------------------------------------------------------------- staff reads */

    /** front quotationService.forStaff: rounds whose request the user may see. */
    public function forStaff(User $u): Collection
    {
        if (! $u->isStaff() || ! $this->perms->has($u, 'quotations.view')) {
            return collect();
        }
        $rounds = QuotationRound::query()->with(['invitations', 'quotations'])->orderByDesc('created_at')->get();
        $cases = RahaCase::query()->with('tasks')->whereIn('id', $rounds->pluck('case_id')->unique())->get()->keyBy('id');

        return $rounds->filter(fn (QuotationRound $r) => ($c = $cases->get($r->case_id)) && $this->access->canAccessCase($c, $u))
            ->map(fn (QuotationRound $r) => QuotationPresenter::staff($r, $cases->get($r->case_id)))->values();
    }

    /** One round (staff shape); 404 when missing or not visible. */
    public function showForStaff(string $id, User $u): array
    {
        [$r, $c] = $this->readable($id, $u);

        return QuotationPresenter::staff($r, $c);
    }

    /** @return array{0: QuotationRound, 1: RahaCase} */
    public function readable(string $id, User $u): array
    {
        if (! $u->isStaff() || ! $this->perms->has($u, 'quotations.view')) {
            throw DomainRuleException::forbidden(self::NO_PERMISSION);
        }
        $r = QuotationRound::query()->with(['invitations', 'quotations'])->find($id);
        $c = $r ? RahaCase::query()->with('tasks')->find($r->case_id) : null;
        if (! $r || ! $c || ! $this->access->canAccessCase($c, $u)) {
            throw DomainRuleException::notFound(self::ROUND_NOT_FOUND);
        }

        return [$r, $c];
    }

    /** Rounds of one request + whether a new round may be requested (front roundsForCase / canRequestQuotation / activeRound). */
    public function forCase(string $caseId, User $u): array
    {
        $c = $this->access->findAccessible($caseId, $u);
        $rounds = $this->perms->has($u, 'quotations.view')
            ? QuotationRound::query()->with(['invitations', 'quotations'])->where('case_id', $c->id)->orderByDesc('created_at')->get()
            : collect();
        $active = self::activeRound($c->id);
        $can = $u->isStaff() && $c->status === 'awaiting_quotation' && $this->perms->has($u, 'quotations.create') && ! $active;

        return [
            'data' => $rounds->map(fn (QuotationRound $r) => QuotationPresenter::staff($r, $c))->values(),
            'canRequestQuotation' => $can,
            'activeRoundId' => $active?->id,
            'eligibleProviders' => $can ? $this->providerRows($c, collect()) : [],
        ];
    }

    /** Every provider with its eligibility for the round's request (front quotation dialogs). */
    public function eligibleProviders(string $roundId, User $u): array
    {
        [$r, $c] = $this->readable($roundId, $u);

        return $this->providerRows($c, $r->invitations->pluck('provider_id'));
    }

    private function providerRows(RahaCase $c, Collection $invited): array
    {
        return Provider::query()->with('services')->orderBy('id')->get()->map(fn (Provider $p) => [
            'providerId' => $p->id, 'name' => $p->name, 'city' => (string) $p->city, 'status' => $p->status,
            ...QuotationRules::eligibility($c, $p), 'invited' => $invited->contains($p->id),
        ])->values()->all();
    }

    /* ---------------------------------------------------------------- staff writes */

    /**
     * front createRound + invite in ONE transaction (gap fix: the mock could leave an orphan draft round when the
     * invitation step failed). Without providerIds the round stays a draft.
     *
     * @param  array{caseId: string, subservice?: string, specifications?: string, description?: string, quantity?: mixed, unit?: string, city?: string, area?: string, expectedStart?: string, deadline?: string, providerNotes?: string, sharedDocIds?: string[]}  $in
     */
    public function createRound(array $in, array $providerIds, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.create');

        return DB::transaction(function () use ($in, $providerIds, $u) {
            $c = RahaCase::query()->lockForUpdate()->find((string) ($in['caseId'] ?? ''));
            if ($c) {
                $c->load(['tasks', 'documents']);
            }
            if (! $c || ! $this->access->canAccessCase($c, $u)) {
                QuotationRules::fail('الطلب غير موجود.', 404);
            }
            if ($c->status !== 'awaiting_quotation') {
                QuotationRules::fail('الطلب ليس في مرحلة طلب عروض الأسعار.');
            }
            if (self::activeRound($c->id)) {
                QuotationRules::fail('يوجد طلب تسعير نشط لهذا الطلب.');
            }
            $str = fn (string $k) => trim((string) ($in[$k] ?? ''));
            if ($str('specifications') === '' || $str('description') === '' || ! ($in['unit'] ?? '') || $str('city') === '') {
                QuotationRules::fail('يرجى استكمال الحقول الإلزامية.');
            }
            if (! (is_numeric($in['quantity'] ?? null) && (float) $in['quantity'] > 0)) {
                QuotationRules::fail('عدد الجلسات أو الكمية يجب أن يكون أكبر من صفر.');
            }
            $deadline = self::parseDate($in['deadline'] ?? null);
            if (! $deadline || $deadline->lte(now())) {
                QuotationRules::fail('آخر موعد لاستقبال العروض يجب أن يكون في المستقبل.');
            }
            if (! ($in['expectedStart'] ?? '')) {
                QuotationRules::fail('يرجى تحديد التاريخ المتوقع لبدء الخدمة.');
            }
            $docIds = array_map('strval', (array) ($in['sharedDocIds'] ?? []));
            $shared = $c->documents->filter(fn ($d) => in_array($d->id, $docIds, true) && $d->visibility !== 'internal')
                ->map(fn ($d) => ['docId' => $d->id, 'name' => $d->name, 'category' => $d->category])->values()->all();
            $at = now();
            $r = QuotationRound::query()->create([
                'id' => Sequence::roundId(), 'case_id' => $c->id, 'request_number' => $c->id, 'service' => $c->service, 'service_id' => $c->service_id,
                'subservice' => (string) ($in['subservice'] ?? ''), 'specifications' => (string) $in['specifications'], 'description' => (string) $in['description'],
                'quantity' => (float) $in['quantity'], 'unit' => (string) $in['unit'], 'city' => (string) $in['city'], 'area' => (string) ($in['area'] ?? ''),
                'expected_start' => (string) $in['expectedStart'], 'deadline' => $deadline, 'provider_notes' => (string) ($in['providerNotes'] ?? ''),
                'shared_attachments' => $shared, 'status' => 'draft', 'created_by' => $u->name, 'created_by_id' => $u->id,
                'history' => [['at' => iso($at), 'by' => CaseRecorder::actorLabel($u), 'text' => 'تم إنشاء طلب عروض الأسعار']],
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $this->caseNote($c->id, $u, "أنشئ طلب عروض أسعار {$r->id}");
            Activity::emit('تم إنشاء طلب عروض أسعار', self::RECORD_TYPE, $r->id, $u);
            if ($providerIds) {
                $this->staffNeed($u, 'quotations.inviteProviders');
                $r = $this->lockRound($r->id, $u);
                $this->inviteInto($r, $c, $providerIds, $u);
            }

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    public function invite(string $roundId, array $providerIds, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.inviteProviders');

        return DB::transaction(function () use ($roundId, $providerIds, $u) {
            $r = $this->lockRound($roundId, $u);
            $this->inviteInto($r, $this->caseOf($r), $providerIds, $u);

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    private function inviteInto(QuotationRound $r, RahaCase $c, array $providerIds, User $u): void
    {
        if ($r->status !== 'draft' && $r->status !== 'open') {
            QuotationRules::fail('لا يمكن إرسال دعوات لطلب تسعير مغلق أو ملغي.');
        }
        if ($r->status === 'open' && QuotationRules::deadlinePassed($r)) {
            QuotationRules::fail('انتهت مهلة العروض؛ مدّد المهلة أولًا.');
        }
        $ids = array_values(array_unique(array_map('strval', $providerIds)));
        if (! $ids) {
            QuotationRules::fail('اختر مقدم خدمة واحدًا على الأقل.');
        }
        $invs = [];
        foreach ($ids as $pid) {
            if ($r->invitations->contains('provider_id', $pid)) {
                QuotationRules::fail('تمت دعوة مقدم الخدمة مسبقًا في هذا الطلب.');
            }
            $p = Provider::query()->with('services')->find($pid) ?? QuotationRules::fail('مقدم الخدمة غير موجود.');
            $e = QuotationRules::eligibility($c, $p);
            if (! $e['eligible']) {
                QuotationRules::fail("{$p->name}: {$e['reason']}");
            }
            $invs[] = ['id' => 'INV-'.uid(), 'quotation_round_id' => $r->id, 'provider_id' => $p->id, 'provider_name' => $p->name, 'status' => 'invited', 'sent_at' => now()];
        }
        $created = array_map(fn ($row) => QuotationInvitation::query()->create($row), $invs);
        $r->status = 'open';
        $this->log($r, $u, 'أرسلت دعوات التسعير إلى: '.implode('، ', array_column($invs, 'provider_name')));
        $this->caseNote($r->case_id, $u, "أرسلت دعوات تسعير ({$r->id}) إلى ".count($invs).' مقدم خدمة');
        foreach ($created as $i) {
            Activity::emit('تم إرسال دعوة تسعير', self::RECORD_TYPE, $r->id, $u, [
                'title' => 'دعوة جديدة لتقديم عرض سعر', 'body' => "{$r->id} — {$r->service}", 'roles' => ['provider'], 'providerId' => $i->provider_id,
                'link' => self::providerLink($i),
            ]);
        }
        $r->unsetRelation('invitations');
    }

    public function extendDeadline(string $roundId, mixed $deadline, string $reason, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.extendDeadline');

        return DB::transaction(function () use ($roundId, $deadline, $reason, $u) {
            $r = $this->lockRound($roundId, $u);
            if ($r->status !== 'open' && $r->status !== 'closed') {
                QuotationRules::fail('لا يمكن تمديد مهلة هذا الطلب.');
            }
            if (trim($reason) === '') {
                QuotationRules::fail('يرجى إدخال سبب التمديد.');
            }
            $d = self::parseDate($deadline);
            if (! $d || $d->lte(now()) || $d->lte($r->deadline)) {
                QuotationRules::fail('الموعد الجديد يجب أن يكون في المستقبل وبعد الموعد الحالي.');
            }
            $r->status = 'open';
            $r->deadline = $d;
            $this->log($r, $u, "مُددت المهلة: {$reason}");
            Activity::emit('تم تمديد مهلة العروض', self::RECORD_TYPE, $r->id, $u);

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    public function close(string $roundId, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.close');

        return DB::transaction(function () use ($roundId, $u) {
            $r = $this->lockRound($roundId, $u);
            if ($r->status !== 'open') {
                QuotationRules::fail('طلب التسعير ليس مفتوحًا.');
            }
            $r->status = 'closed';
            $this->log($r, $u, 'أُغلق استقبال العروض');
            Activity::emit('تم إغلاق استقبال العروض', self::RECORD_TYPE, $r->id, $u);

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    public function cancel(string $roundId, string $reason, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.cancel');

        return DB::transaction(function () use ($roundId, $reason, $u) {
            $r = $this->lockRound($roundId, $u);
            if ($r->status === 'cancelled' || $r->status === 'selected') {
                QuotationRules::fail('لا يمكن إلغاء هذا الطلب.');
            }
            if (trim($reason) === '') {
                QuotationRules::fail('يرجى إدخال سبب الإلغاء.');
            }
            $r->status = 'cancelled';
            $r->cancel_reason = $reason;
            $this->log($r, $u, "أُلغي طلب التسعير: {$reason}");
            $this->caseNote($r->case_id, $u, "أُلغي طلب التسعير {$r->id}: {$reason}");
            foreach ($r->invitations as $i) {
                Activity::emit('تم إلغاء طلب تسعير', self::RECORD_TYPE, $r->id, $u, [
                    'title' => 'أُلغي طلب تسعير', 'body' => $r->id, 'roles' => ['provider'], 'providerId' => $i->provider_id, 'link' => ['to' => '/provider/quotations'],
                ]);
            }

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    /** @param array{compliance?: string, notes?: string, reasons?: string, recommendation?: string} $e */
    public function evaluate(string $roundId, string $quotationId, array $e, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.medicalEvaluate');

        return DB::transaction(function () use ($roundId, $quotationId, $e, $u) {
            $r = $this->lockRound($roundId, $u);
            if (in_array($r->status, ['cancelled', 'selected', 'draft'], true)) {
                QuotationRules::fail('لا يمكن تقييم عروض هذا الطلب.');
            }
            if ($r->status === 'open' && ! QuotationRules::deadlinePassed($r)) {
                QuotationRules::fail('أغلق استقبال العروض قبل التقييم.');
            }
            $q = $r->quotations->first(fn (ProviderQuotation $x) => $x->id === $quotationId && $x->status === 'submitted') ?? QuotationRules::fail('العرض غير موجود.', 404);
            $notes = (string) ($e['notes'] ?? '');
            $reasons = (string) ($e['reasons'] ?? '');
            $rec = (string) ($e['recommendation'] ?? '');
            $compliance = (string) ($e['compliance'] ?? '');
            if (! in_array($compliance, QuotationRules::COMPLIANCE, true)) {
                QuotationRules::fail('قيمة غير صالحة.');
            }
            if (trim($notes) === '' || trim($rec) === '') {
                QuotationRules::fail('يرجى إدخال ملاحظات التقييم والتوصية الطبية.');
            }
            if ($compliance !== 'compliant' && trim($reasons) === '') {
                QuotationRules::fail('يرجى توضيح أسباب عدم المطابقة.');
            }
            $ev = ['id' => uid(), 'compliance' => $compliance, 'notes' => $notes, 'reasons' => $reasons, 'recommendation' => $rec, 'by' => $u->name, 'byId' => $u->id, 'at' => now_iso()];
            $q->evaluations = [...($q->evaluations ?? []), $ev];
            $q->save();
            $r->status = 'evaluating';
            $this->log($r, $u, "تقييم طبي لعرض {$q->number} ({$q->provider_name})");
            $this->caseNote($r->case_id, $u, "قيّم الأخصائي الطبي عرض {$q->provider_name}");
            Activity::emit('تم التقييم الطبي لعرض سعر', 'عرض سعر', $q->number, $u);

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    /** Recommendation only: stores case.preferredQuotation and advances the request with quotation_complete (same transaction). */
    public function select(string $roundId, string $quotationId, string $reason, bool $confirmed, User $u): QuotationRound
    {
        $this->staffNeed($u, 'quotations.selectPreferred');

        return DB::transaction(function () use ($roundId, $quotationId, $reason, $confirmed, $u) {
            $r = $this->lockRound($roundId, $u);
            if (in_array($r->status, ['cancelled', 'selected', 'draft'], true)) {
                QuotationRules::fail('لا يمكن اختيار عرض لهذا الطلب.');
            }
            if ($r->status === 'open' && ! QuotationRules::deadlinePassed($r)) {
                QuotationRules::fail('أغلق استقبال العروض قبل اختيار العرض.');
            }
            $q = $r->quotations->first(fn (ProviderQuotation $x) => $x->id === $quotationId && $x->status === 'submitted') ?? QuotationRules::fail('العرض غير موجود.', 404);
            if ((QuotationRules::latestEvaluation($q)['compliance'] ?? null) !== 'compliant') {
                QuotationRules::fail('لا يمكن اختيار عرض غير مطابق طبيًا أو لم يُقيّم.');
            }
            if (trim($reason) === '') {
                QuotationRules::fail('يرجى إدخال سبب الاختيار.');
            }
            if (! $confirmed) {
                QuotationRules::fail('يرجى تأكيد أن الاختيار توصية وليس تفويضًا بتنفيذ الخدمة.');
            }
            $c = RahaCase::query()->lockForUpdate()->find($r->case_id) ?? QuotationRules::fail('الطلب غير موجود.', 404);
            if ($c->status !== 'awaiting_quotation') {
                QuotationRules::fail('الطلب ليس في مرحلة التسعير.');
            }
            $r->status = 'selected';
            $r->selected_quotation_id = $q->id;
            $r->selection_reason = $reason;
            $this->log($r, $u, "اختير عرض {$q->provider_name} كعرض موصى به: {$reason}");
            $c->preferred_quotation = [
                'roundId' => $r->id, 'quotationId' => $q->id, 'providerId' => $q->provider_id, 'providerName' => $q->provider_name,
                'grandTotal' => $q->grand_total, 'reason' => $reason, 'by' => $u->name, 'at' => now_iso(),
            ];
            $c->save();
            $this->workflow->perform($c->id, 'quotation_complete', $u);
            Activity::emit('تم اختيار العرض المناسب (توصية)', self::RECORD_TYPE, $r->id, $u, [
                'title' => 'تم اختيار عرض سعر — بانتظار البحث الاجتماعي', 'body' => $c->id, 'roles' => ['social', 'manager'], 'caseId' => $c->id,
                'link' => CaseRecorder::link($c->id),
            ]);

            return $r->fresh(['invitations', 'quotations']);
        });
    }

    /* ---------------------------------------------------------------- provider side */

    private function isProviderWith(User $u, string $perm): bool
    {
        return $u->isProvider() && $u->provider_id && $this->perms->has($u, $perm);
    }

    /** front forProvider: own invitations of non-draft rounds, never other providers' offers. */
    public function forProvider(User $u): Collection
    {
        if (! $this->isProviderWith($u, 'provider.quotations.viewOwn')) {
            return collect();
        }
        $invs = QuotationInvitation::query()->where('provider_id', $u->provider_id)->orderByDesc('sent_at')->get();
        $rounds = QuotationRound::query()->with(['invitations', 'quotations'])->whereIn('id', $invs->pluck('quotation_round_id')->unique())
            ->where('status', '!=', 'draft')->get()->keyBy('id');

        return $invs->filter(fn ($i) => $rounds->has($i->quotation_round_id))
            ->map(fn (QuotationInvitation $i) => QuotationPresenter::provider($rounds->get($i->quotation_round_id), $i))->values();
    }

    public function invitation(string $invitationId, User $u): array
    {
        return $this->forProvider($u)->first(fn ($v) => $v['invitation']['id'] === $invitationId)
            ?? QuotationRules::fail(self::INVITATION_NOT_FOUND, 404);
    }

    /** front ownInvitation(): provider permission, ownership, request not archived, round open and before the deadline. Locks the round. */
    private function ownInvitation(string $invitationId, User $u, string $perm): array
    {
        if (! $this->isProviderWith($u, $perm)) {
            throw DomainRuleException::forbidden(self::NO_PERMISSION);
        }
        $inv = QuotationInvitation::query()->find($invitationId) ?? QuotationRules::fail(self::INVITATION_NOT_FOUND, 404);
        $r = QuotationRound::query()->lockForUpdate()->find($inv->quotation_round_id) ?? QuotationRules::fail(self::INVITATION_NOT_FOUND, 404);
        $inv->refresh();
        if ($inv->provider_id !== $u->provider_id) {
            throw DomainRuleException::forbidden('هذه الدعوة لا تخص منشأتك.');
        }
        if ($r->status === 'draft') {
            QuotationRules::fail(self::INVITATION_NOT_FOUND, 404);
        }
        $c = RahaCase::query()->find($r->case_id) ?? QuotationRules::fail('الطلب غير موجود.', 404);
        CaseAccess::assertMutable($c);
        if ($r->status === 'cancelled') {
            QuotationRules::fail('أُلغي طلب التسعير.');
        }
        if ($r->status !== 'open' || QuotationRules::deadlinePassed($r)) {
            QuotationRules::fail('انتهت مهلة تقديم العروض أو أُغلق الاستقبال.');
        }
        $r->load(['invitations', 'quotations']);

        return [$r, $inv];
    }

    public function decline(string $invitationId, string $reason, bool $confirmed, User $u): array
    {
        DB::transaction(function () use ($invitationId, $reason, $confirmed, $u) {
            [$r, $inv] = $this->ownInvitation($invitationId, $u, 'provider.quotations.declineOwn');
            if (! $confirmed) {
                QuotationRules::fail('يرجى تأكيد الاعتذار.');
            }
            if ($inv->status === 'submitted' || $inv->status === 'declined') {
                QuotationRules::fail('تم الرد على هذه الدعوة مسبقًا.');
            }
            $reason = trim($reason);
            $inv->forceFill(['status' => 'declined', 'responded_at' => now(), 'decline_reason' => $reason !== '' ? $reason : null])->save();
            $this->log($r, $u, "اعتذر {$inv->provider_name} عن تقديم عرض".($reason !== '' ? ": {$reason}" : ''));
            $this->caseNote($r->case_id, $u, "اعتذر {$inv->provider_name} عن تقديم عرض سعر ({$r->id})");
            Activity::emit('اعتذر مقدم خدمة عن التسعير', self::RECORD_TYPE, $r->id, $u, [
                'title' => 'اعتذار مقدم خدمة عن التسعير', 'body' => "{$r->id} — {$inv->provider_name}", 'roles' => ['medical', 'manager'], 'link' => self::staffLink($r),
            ]);
        });

        return $this->invitation($invitationId, $u);
    }

    public function saveDraft(string $invitationId, array $input, User $u): ProviderQuotation
    {
        return $this->upsert($invitationId, $input, $u, false);
    }

    public function submit(string $invitationId, array $input, bool $confirmed, User $u): ProviderQuotation
    {
        if (! $confirmed) {
            QuotationRules::fail('يرجى تأكيد إرسال عرض السعر.');
        }

        return $this->upsert($invitationId, $input, $u, true);
    }

    private function upsert(string $invitationId, array $input, User $u, bool $submit): ProviderQuotation
    {
        return DB::transaction(function () use ($invitationId, $input, $u, $submit) {
            [$r, $inv] = $this->ownInvitation($invitationId, $u, $submit ? 'provider.quotations.submitOwn' : 'provider.quotations.editDraftOwn');
            if ($inv->status === 'submitted') {
                QuotationRules::fail('تم إرسال عرض السعر مسبقًا ولا يمكن إرساله مرة أخرى.');
            }
            if ($inv->status === 'declined') {
                QuotationRules::fail('اعتذرت عن هذه الدعوة.');
            }
            /** @var ProviderQuotation|null $existing */
            $existing = $r->quotations->firstWhere('invitation_id', $inv->id);
            if (! $existing && ! $this->perms->has($u, 'provider.quotations.createOwn')) {
                throw DomainRuleException::forbidden('لا تملك صلاحية إنشاء عرض سعر.');
            }
            if ($existing && $existing->provider_id !== $u->provider_id) {
                throw DomainRuleException::forbidden('لا يمكنك تعديل عرض مقدم خدمة آخر.');
            }
            $attachments = $this->attachments($input['attachments'] ?? [], $existing, $u);
            if ($submit) {
                QuotationRules::validateQuotation(['attachments' => $attachments] + $input);
            }
            $treatment = in_array($input['taxTreatment'] ?? null, QuotationRules::TAX_TREATMENTS, true) ? $input['taxTreatment'] : 'exempt';
            $taxRate = $treatment === 'standard' ? (float) ($input['taxRate'] ?? 0) : 0.0;
            $lines = array_map(function ($l) use ($taxRate) {
                $line = [
                    'id' => uid(), 'description' => (string) ($l['description'] ?? ''), 'quantity' => $l['quantity'] ?? 0, 'unit' => (string) ($l['unit'] ?? ''),
                    'unitPrice' => $l['unitPrice'] ?? 0, 'discountAmount' => $l['discountAmount'] ?? 0, 'taxRate' => $taxRate,
                ];
                $line['total'] = QuotationRules::lineAmounts($line)['total'];

                return $line;
            }, array_values($input['lineItems'] ?? []));
            $t = QuotationRules::totals($lines);
            $at = now();
            // Race-safe number: the round row is locked for this transaction; numbers are never reused (quotations are never deleted).
            $number = $existing?->number ?? sprintf('QT-%s-%02d', substr($r->id, 3), ProviderQuotation::query()->where('quotation_round_id', $r->id)->count() + 1);
            $q = $existing ?? new ProviderQuotation(['id' => 'Q-'.uid(), 'quotation_round_id' => $r->id, 'invitation_id' => $inv->id, 'created_at' => $at]);
            $q->forceFill([
                'number' => $number, 'provider_id' => $inv->provider_id, 'provider_name' => $inv->provider_name, 'status' => $submit ? 'submitted' : 'draft',
                'version' => ($existing?->version ?? 0) + 1, 'description' => (string) ($input['description'] ?? ''), 'line_items' => $lines,
                'tax_treatment' => $treatment, 'tax_rate' => $taxRate, 'subtotal' => $t['subtotal'], 'discount_total' => $t['discountTotal'],
                'tax_total' => $t['taxTotal'], 'grand_total' => $t['grandTotal'], 'start_within_days' => max(0, (int) round((float) ($input['startWithinDays'] ?? 0))),
                'delivery_duration' => (string) ($input['deliveryDuration'] ?? ''), 'valid_until' => (string) ($input['validUntil'] ?? ''),
                'notes' => (string) ($input['notes'] ?? ''), 'attachments' => $attachments, 'evaluations' => [], 'updated_at' => $at,
                'submitted_at' => $submit ? $at : null,
                'branding' => $submit ? ProviderBranding::snapshot(Provider::query()->find($inv->provider_id), iso($at)) : null,
            ])->save();
            $inv->forceFill(['status' => $submit ? 'submitted' : 'draft', 'responded_at' => $submit ? $at : $inv->responded_at])->save();
            $this->log($r, $u, $submit ? "قدّم {$inv->provider_name} عرض السعر {$q->number}" : "حفظ {$inv->provider_name} مسودة عرض");
            if ($submit) {
                $this->caseNote($r->case_id, $u, "استُلم عرض سعر {$q->number} من {$inv->provider_name}");
                Activity::emit('تم استلام عرض سعر', 'عرض سعر', $q->number, $u, [
                    'title' => 'تم استلام عرض سعر جديد', 'body' => "{$r->id} — {$inv->provider_name}", 'roles' => ['medical', 'manager'], 'link' => self::staffLink($r),
                ]);
            }

            return $q->fresh();
        });
    }

    /** Metadata-only entries are kept as-is; {uploadId} must be the provider user's own upload (unused, or already on this quotation). */
    private function attachments(array $list, ?ProviderQuotation $existing, User $u): array
    {
        $mine = collect($existing?->attachments ?? [])->pluck('uploadId')->filter()->all();
        $out = [];
        foreach (array_values($list) as $a) {
            $a = is_array($a) ? $a : [];
            if (! empty($a['uploadId'])) {
                $up = Upload::query()->lockForUpdate()->find((string) $a['uploadId']);
                if (! $up || $up->user_id !== $u->id || ($up->attached_at !== null && ! in_array($up->id, $mine, true))) {
                    QuotationRules::fail('الملف المرفق غير صالح أو تم استخدامه مسبقًا.');
                }
                $up->forceFill(['attached_at' => $up->attached_at ?? now()])->save();
                $out[] = ['name' => $up->name, 'size' => $up->size, 'type' => $up->mime, 'uploadId' => $up->id];
            } else {
                $out[] = ['name' => (string) ($a['name'] ?? ''), 'size' => is_numeric($a['size'] ?? null) ? $a['size'] + 0 : 0, 'type' => (string) ($a['type'] ?? '')];
            }
        }

        return $out;
    }

    /** provider_dashboard_page counts + assigned requests of this provider. */
    public function dashboard(User $u): array
    {
        $views = $this->forProvider($u)->pluck('view');
        $count = fn (array $v) => $views->filter(fn ($x) => in_array($x, $v, true))->count();
        $byView = [];
        foreach (['invited', 'draft', 'submitted', 'declined', 'expired', 'selected', 'not_selected', 'cancelled'] as $v) {
            $byView[$v] = $count([$v]);
        }

        return [
            'newInvitations' => $count(['invited']), 'pendingDrafts' => $count(['draft']), 'submitted' => $count(['submitted', 'selected', 'not_selected']),
            'expired' => $count(['expired']), 'selected' => $count(['selected']), 'byView' => $byView,
            'assignedCases' => $u->provider_id ? RahaCase::query()->where('provider_id', $u->provider_id)->count() : 0,
        ];
    }
}
