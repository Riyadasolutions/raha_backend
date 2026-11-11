<?php

namespace Modules\Finance\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseRecorder;
use Modules\Cases\Services\TaskService;
use Modules\Finance\Models\FinanceConfig;
use Modules\Finance\Models\FinanceEvent;
use Modules\Finance\Models\FinancialApproval;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Models\Wallet;

/**
 * Financial review → funding split → reservation → executive decision (port of front finance_service.ts).
 * PUBLIC API for Authorizations: need(), lockCase(), approvalFor(), record(), sar().
 *
 * Gap fixes vs the mock: the approval order comes from the config VERSION captured on the case; every multi-row write runs
 * in one transaction with the case/approval (and wallets when balances are checked) locked; cancelled reservations write
 * RESERVATION_CANCELLED; finance events store stage codes.
 */
class FinanceService
{
    public const NO_PERMISSION = 'لا تملك صلاحية تنفيذ هذا الإجراء.';

    public const REJECTED = ['funding_rejected', 'executive_rejected'];

    public const CLOSED = ['authorization_issued', 'provider_assigned', 'funding_rejected', 'executive_rejected'];

    public const SOURCE_TYPES = ['RAHA_WALLET', 'BENEFICIARY', 'THIRD_PARTY'];

    public function __construct(
        private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder,
        private TaskService $tasks, private WalletBalances $balances,
    ) {}

    /** front formatSar: "1,234.00 SAR" */
    public static function sar(int $h): string
    {
        return number_format($h / 100, 2).' SAR';
    }

    public static function insufficientMsg(): string
    {
        return (string) raha('finance.INSUFFICIENT_MSG', 'الرصيد المتاح في المحفظة غير كافٍ لتغطية المبلغ المطلوب.');
    }

    public function need(User $u, string $perm): User
    {
        if (! $this->perms->has($u, $perm)) {
            throw DomainRuleException::forbidden(self::NO_PERMISSION);
        }

        return $u;
    }

    public static function reason(?string $r): string
    {
        $r = trim((string) $r);
        if ($r === '') {
            throw new DomainRuleException('يجب إدخال السبب.');
        }

        return $r;
    }

    /** Case row locked for the rest of the transaction; 404 when not visible, 422 when archived (front caseOf). */
    public function lockCase(string $id, User $u): RahaCase
    {
        $c = RahaCase::query()->lockForUpdate()->find($id);
        if (! $c || ! $this->access->canAccessCase($c, $u)) {
            throw DomainRuleException::notFound('الطلب غير موجود.');
        }
        CaseAccess::assertMutable($c);

        return $c;
    }

    /** Current approval of a case: the newest non-rejected one, else the newest (front approvalFor). */
    public static function approvalFor(string $caseId, bool $lock = false): ?FinancialApproval
    {
        $q = FinancialApproval::query()->where('case_id', $caseId)->orderByDesc('created_at')->orderByDesc('updated_at');
        $all = ($lock ? $q->lockForUpdate() : $q)->get();

        return $all->first(fn (FinancialApproval $a) => ! in_array($a->stage, self::REJECTED, true)) ?? $all->first();
    }

    private static function mustApproval(string $caseId): FinancialApproval
    {
        return self::approvalFor($caseId, true) ?? throw new DomainRuleException('لم تبدأ المراجعة المالية لهذا الطلب.');
    }

    /** Runs $fn in a transaction; a DomainRuleException RETURNED by $fn is thrown after commit (state recorded, request still fails). */
    private function tx(callable $fn): mixed
    {
        $result = DB::transaction($fn);
        if ($result instanceof DomainRuleException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Single funnel for every finance decision (front record()): approval history + FinanceEvent (stage codes) + case.finance
     * mirror + optional case status + internal/beneficiary activities + audit/notification linking /finance/cases/$caseId.
     *
     * @param  array{reason?: ?string, amount?: ?int, walletId?: ?string, caseStatus?: string, beneficiaryText?: string, notify?: array}  $extra
     */
    public function record(RahaCase $c, FinancialApproval $a, string $stage, User $u, string $action, array $extra = []): FinancialApproval
    {
        $at = now();
        $from = $a->stage ?: 'pending_review';
        $reason = ($extra['reason'] ?? null) ?: null;
        $amount = $extra['amount'] ?? null;
        $actor = CaseRecorder::actorLabel($u);
        $a->forceFill(['stage' => $stage, 'history' => array_merge($a->history ?? [], [array_filter(
            ['by' => $u->name, 'byId' => $u->id, 'at' => iso($at), 'decision' => $action, 'reason' => $reason], fn ($v) => $v !== null,
        )])])->save();
        FinanceEvent::query()->create([
            'at' => $at, 'actor' => $actor, 'actor_id' => $u->id, 'case_id' => $c->id, 'action' => $action, 'amount_halalas' => $amount,
            'wallet_id' => $extra['walletId'] ?? null, 'reason' => $reason, 'from_stage' => $from, 'to_stage' => $stage,
        ]);

        $prev = $c->status;
        $status = $extra['caseStatus'] ?? $prev;
        if ($status !== $prev) {
            $this->recorder->touch($c, $status);
        } else {
            $c->forceFill(['updated' => ar_date($at), 'updated_at' => $at]);
        }
        $c->finance = array_filter([
            'approvalId' => $a->id, 'stage' => $stage, 'configVersion' => $a->config_version, 'authorizationId' => $c->finance['authorizationId'] ?? null,
        ], fn ($v) => $v !== null);
        $c->save();

        $text = $action.($amount !== null ? ' ('.self::sar((int) $amount).')' : '').($reason ? ": {$reason}" : '');
        $this->recorder->addActivity($c, $text, $actor, 'internal', ['action' => 'finance', 'fromStage' => $prev, 'toStage' => $status, 'reason' => $reason, 'actorId' => $u->id, 'at' => $at]);
        if (! empty($extra['beneficiaryText'])) {
            $this->recorder->addActivity($c, $extra['beneficiaryText'], 'جمعية راحة', 'beneficiary', ['action' => 'finance', 'fromStage' => $prev, 'toStage' => $status, 'at' => $at]);
        }
        if ($status === 'manager_approval' && $prev !== $status) {
            $c->unsetRelation('tasks');
            $this->tasks->withCurrentTask($c, $at);
        }
        $notify = isset($extra['notify'])
            ? $extra['notify'] + ['body' => $c->id, 'caseId' => $c->id, 'link' => ['to' => '/finance/cases/$caseId', 'params' => ['caseId' => $c->id]]]
            : null;
        Activity::emit($action, 'اعتماد مالي', $c->id, $u, $notify);

        return $a;
    }

    /**
     * Front validateAllocations: amounts reconcile with the total, wallets exist / are eligible; returns the per-wallet shortfalls
     * (empty when every wallet covers its share, or when $ignoreBalance).
     *
     * @return array<int, array{walletId: string, required: int, available: int, shortfall: int}>
     */
    public function validateAllocations(mixed $totalH, array $allocs, ?string $serviceId, bool $ignoreBalance = false, bool $lockWallets = false): array
    {
        if (! is_int($totalH) || $totalH <= 0) {
            throw new DomainRuleException('تكلفة الخدمة المعتمدة غير صالحة.');
        }
        foreach ($allocs as $x) {
            if (! is_int($x['amountHalalas'] ?? null) || $x['amountHalalas'] < 0) {
                throw new DomainRuleException('لا يمكن إدخال مبالغ سالبة.');
            }
        }
        foreach ($allocs as $x) {
            if ($x['amountHalalas'] > $totalH) {
                throw new DomainRuleException('لا يمكن أن تتجاوز المساهمة تكلفة الخدمة.');
            }
        }
        $sum = array_sum(array_column($allocs, 'amountHalalas'));
        if ($sum !== $totalH) {
            throw new DomainRuleException('مجموع التوزيع ('.self::sar($sum).') لا يساوي تكلفة الخدمة ('.self::sar($totalH).').');
        }
        $perWallet = [];
        $walletIds = array_values(array_unique(array_filter(array_map(fn ($x) => ($x['sourceType'] ?? null) === 'RAHA_WALLET' ? (string) ($x['walletId'] ?? '') : null, $allocs), fn ($v) => $v !== null)));
        $q = Wallet::query()->whereIn('id', $walletIds)->orderBy('id');
        $wallets = ($lockWallets ? $q->lockForUpdate() : $q)->get()->keyBy('id');
        foreach ($allocs as $x) {
            if (($x['sourceType'] ?? null) !== 'RAHA_WALLET') {
                continue;
            }
            $w = $wallets[$x['walletId'] ?? ''] ?? throw new DomainRuleException('المحفظة غير موجودة.');
            if (! WalletBalances::eligible($w, $serviceId)) {
                throw new DomainRuleException("المحفظة {$w->name_ar} غير مؤهلة لهذه الخدمة أو موقوفة.");
            }
            $perWallet[$w->id] = ($perWallet[$w->id] ?? 0) + $x['amountHalalas'];
        }
        if ($ignoreBalance || ! $perWallet) {
            return [];
        }
        $bal = $this->balances->many(array_keys($perWallet));
        $out = [];
        foreach ($perWallet as $walletId => $required) {
            $available = $bal[$walletId]['available'];
            if ($required > $available) {
                $out[] = ['walletId' => $walletId, 'required' => $required, 'available' => $available, 'shortfall' => $required - $available];
            }
        }

        return $out;
    }

    /** Front percentSplit: percent → halalas with one rounding, remainder to the counterpart. */
    public static function percentSplit(int $totalH, float $pct): array
    {
        if (! is_finite($pct) || $pct < 0 || $pct > 100) {
            throw new DomainRuleException('النسبة يجب أن تكون بين 0 و100.');
        }
        $part = (int) round($totalH * $pct / 100);

        return ['part' => $part, 'rest' => $totalH - $part];
    }

    /** ACTIVE → RELEASED (ledger RESERVATION_RELEASE) or CANCELLED (ledger RESERVATION_CANCELLED). Caller holds the case lock. */
    private function closeReservations(string $caseId, User $u, string $to, string $why): Collection
    {
        $active = FundingReservation::query()->where('case_id', $caseId)->where('status', 'ACTIVE')->lockForUpdate()->get();
        $at = now();
        foreach ($active as $r) {
            $r->forceFill(['status' => $to, 'closed_at' => $at, 'closed_reason' => $why])->save();
            $this->ledger($r->wallet_id, $caseId, $r->id, $r->amount_halalas, $to === 'CANCELLED' ? 'RESERVATION_CANCELLED' : 'RESERVATION_RELEASE', $why, $u, $at);
        }

        return $active;
    }

    public function ledger(string $walletId, ?string $caseId, ?string $reservationId, int $amount, string $type, string $note, User $u, mixed $at = null): LedgerEntry
    {
        return LedgerEntry::query()->create([
            'id' => Sequence::ledgerId(), 'wallet_id' => $walletId, 'case_id' => $caseId, 'reservation_id' => $reservationId, 'amount_halalas' => $amount,
            'type' => $type, 'note' => $note, 'created_by' => $u->name, 'created_at' => $at ?? now(),
        ]);
    }

    private function sumAllocations(FinancialApproval $a): int
    {
        return (int) array_sum(array_column($a->allocations ?? [], 'amountHalalas'));
    }

    // ---- configuration -------------------------------------------------------------------------------------------------

    public static function configShape(FinanceConfig $cfg): array
    {
        return [
            'id' => 'finance', 'version' => $cfg->version, 'order' => $cfg->order, 'updatedAt' => iso($cfg->updated_at), 'updatedBy' => $cfg->updated_by,
            'signatoryName' => $cfg->signatory_name ?? '', 'signatoryTitle' => $cfg->signatory_title ?? '', 'letterValidityDays' => $cfg->letter_validity_days,
        ];
    }

    /** New version row; cases keep the configVersion captured when they entered finance, so history is never rewritten. */
    public function setConfig(array $input, User $user): FinanceConfig
    {
        $u = $this->need($user, 'finance.config.manage');

        return DB::transaction(function () use ($input, $u) {
            $cur = FinanceConfig::query()->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $next = FinanceConfig::query()->create([
                'version' => $cur->version + 1, 'order' => $input['order'],
                'signatory_name' => array_key_exists('signatoryName', $input) ? trim((string) $input['signatoryName']) : $cur->signatory_name,
                'signatory_title' => array_key_exists('signatoryTitle', $input) ? (trim((string) $input['signatoryTitle']) ?: 'المدير التنفيذي') : $cur->signatory_title,
                'letter_validity_days' => array_key_exists('letterValidityDays', $input) ? $input['letterValidityDays'] : $cur->letter_validity_days,
                'updated_by' => $u->name,
            ]);
            Activity::emit('تم تعديل إعدادات الاعتماد المالي', 'إعدادات', 'finance', $u);

            return $next;
        });
    }

    // ---- accountant ------------------------------------------------------------------------------------------------------

    public function start(string $caseId, User $user): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.review');

        return $this->tx(function () use ($caseId, $u) {
            $c = $this->lockCase($caseId, $u);
            if ($c->status !== 'preliminary_approved' || ($c->finance['stage'] ?? null) !== 'pending_review') {
                throw new DomainRuleException('الطلب ليس بانتظار المراجعة المالية.');
            }
            // Gap fix: the order of the config version captured on the case (at care_approve), not today's config.
            $cfg = FinanceConfig::ofVersion((int) (($c->finance['configVersion'] ?? 0) ?: FinanceConfig::current()->version));
            $pq = $c->preferred_quotation;
            $a = new FinancialApproval([
                'id' => "FA-{$caseId}-".uid(4), 'case_id' => $caseId, 'selected_quotation_id' => $pq['quotationId'] ?? null,
                'provider_id' => $pq['providerId'] ?? null, 'provider_name' => $pq['providerName'] ?? null, 'total_cost_halalas' => (int) ($pq['grandTotal'] ?? 0),
                'costing_basis' => $pq ? "عرض السعر المختار {$pq['quotationId']}" : '', 'allocations' => [], 'version' => 0, 'config_version' => $cfg->version,
                'order' => $cfg->order, 'stage' => 'pending_review', 'accountant_id' => $u->id, 'reserved' => false, 'history' => [],
            ]);

            return $this->record($c, $a, 'in_review', $u, 'بدء المراجعة المالية');
        });
    }

    /** @param array{totalCostHalalas: int, costingBasis?: ?string, allocations: array, providerId?: ?string, providerName?: ?string} $input */
    public function setAllocations(string $caseId, array $input, User $user): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.allocate');

        return $this->tx(function () use ($caseId, $input, $u) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (! in_array($a->stage, ['in_review', 'funding_incomplete', 'split_approved'], true) || $a->reserved || $a->executive) {
                throw new DomainRuleException('لا يمكن تعديل التوزيع في هذه المرحلة.');
            }
            $pq = $c->preferred_quotation;
            $total = $input['totalCostHalalas'];
            $basis = trim((string) ($input['costingBasis'] ?? ''));
            if ($pq && $total !== (int) ($pq['grandTotal'] ?? 0)) {
                throw new DomainRuleException('تكلفة الخدمة يجب أن تطابق العرض المختار.');
            }
            if (! $pq && ($basis === '' || empty($input['providerId']))) {
                throw new DomainRuleException('يجب تحديد أساس تسعير معتمد ومقدم الخدمة للخدمات دون عرض سعر.');
            }
            $allocs = array_map(fn ($x) => array_filter([
                'id' => ($x['id'] ?? '') ?: uid(), 'sourceType' => $x['sourceType'] ?? null, 'walletId' => ($x['walletId'] ?? null) ?: null,
                'label' => ($x['label'] ?? null) ?: null, 'amountHalalas' => $x['amountHalalas'] ?? null,
            ], fn ($v) => $v !== null), $input['allocations']);
            $this->validateAllocations($total, $allocs, $c->service_id, true);
            $a->forceFill([
                'total_cost_halalas' => $total, 'costing_basis' => $pq ? $a->costing_basis : $basis,
                'provider_id' => $pq ? $a->provider_id : $input['providerId'], 'provider_name' => $pq ? $a->provider_name : ($input['providerName'] ?? null),
                'allocations' => $allocs, 'version' => $a->version + 1, 'split_approved' => null,
            ]);

            return $this->record($c, $a, 'in_review', $u, 'تم اقتراح التوزيع المالي وتحديد مصادر التمويل', ['amount' => $total]);
        });
    }

    public function approveSplit(string $caseId, User $user): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.approve');

        return $this->tx(function () use ($caseId, $u) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (! in_array($a->stage, ['in_review', 'funding_incomplete'], true) || ! $a->allocations) {
                throw new DomainRuleException('حدد التوزيع المالي أولًا.');
            }
            $short = $this->validateAllocations($a->total_cost_halalas, $a->allocations, $c->service_id, false, true);
            if ($short) {
                $this->record($c, $a, 'funding_incomplete', $u, 'التمويل غير مكتمل — رصيد غير كافٍ', [
                    'amount' => $short[0]['shortfall'], 'walletId' => $short[0]['walletId'], 'notify' => ['title' => 'التمويل غير مكتمل لطلب', 'roles' => ['finance']],
                ]);

                return new DomainRuleException(self::insufficientMsg(), 422, ['shortfalls' => $short]);
            }
            $stage = $a->order === 'RESERVE_THEN_APPROVE' ? 'split_approved' : 'pending_executive';
            $a->split_approved = ['by' => $u->name, 'byId' => $u->id, 'at' => now_iso(), 'decision' => 'approved'];

            return $this->record($c, $a, $stage, $u, 'تم اعتماد التوزيع المالي', ['amount' => $a->total_cost_halalas]
                + ($stage === 'pending_executive' ? ['notify' => ['title' => 'طلب بانتظار الاعتماد النهائي', 'roles' => ['executive']]] : []));
        });
    }

    /** All wallet reservations for a case succeed or fail together (validated with the wallets locked before any write). */
    public function reserve(string $caseId, User $user): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.reserve');

        return $this->tx(function () use ($caseId, $u) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (in_array($a->stage, self::CLOSED, true)) {
                throw new DomainRuleException('الإجراء غير متاح لهذه الحالة.'); // gap fix: the mock could reserve on a closed approval
            }
            if (! $a->split_approved) {
                throw new DomainRuleException('يجب اعتماد التوزيع المالي قبل الحجز.');
            }
            if ($a->order === 'APPROVE_THEN_RESERVE' && ! $a->executive) {
                throw new DomainRuleException('الحجز يتم بعد الاعتماد النهائي وفق الإعداد الحالي.');
            }
            if ($a->reserved || FundingReservation::query()->where('approval_id', $a->id)->whereIn('status', ['ACTIVE', 'CONVERTED'])->exists()) {
                throw new DomainRuleException('تم حجز التمويل لهذا التوزيع مسبقًا.');
            }
            $short = $this->validateAllocations($a->total_cost_halalas, $a->allocations, $c->service_id, false, true);
            if ($short) {
                $a->split_approved = null;
                $this->record($c, $a, 'funding_incomplete', $u, 'تعذر الحجز — رصيد غير كافٍ', [
                    'amount' => $short[0]['shortfall'], 'walletId' => $short[0]['walletId'], 'notify' => ['title' => 'التمويل غير مكتمل لطلب', 'roles' => ['finance']],
                ]);

                return new DomainRuleException(self::insufficientMsg(), 422, ['shortfalls' => $short]);
            }
            $at = now();
            $sum = 0;
            foreach ($a->allocations as $x) {
                if (($x['sourceType'] ?? null) !== 'RAHA_WALLET' || $x['amountHalalas'] <= 0) {
                    continue;
                }
                $r = FundingReservation::query()->create([
                    'id' => 'RSV-'.uid(), 'case_id' => $caseId, 'wallet_id' => $x['walletId'], 'allocation_id' => $x['id'], 'approval_id' => $a->id,
                    'amount_halalas' => $x['amountHalalas'], 'status' => 'ACTIVE', 'created_at' => $at, 'created_by' => $u->name,
                ]);
                $this->ledger($r->wallet_id, $caseId, $r->id, $r->amount_halalas, 'RESERVATION', 'حجز تمويل (ليس دفعًا فعليًا)', $u, $at);
                $sum += $r->amount_halalas;
            }
            $stage = $a->order === 'RESERVE_THEN_APPROVE' ? 'pending_executive' : 'executive_approved';
            $a->reserved = true;

            return $this->record($c, $a, $stage, $u, 'تم حجز التمويل', ['amount' => $sum, 'notify' => $stage === 'pending_executive'
                ? ['title' => 'طلب بانتظار الاعتماد النهائي', 'roles' => ['executive']]
                : ['title' => 'طلب جاهز لإصدار التعميد', 'roles' => ['manager']]]);
        });
    }

    public function release(string $caseId, User $user, ?string $why): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.release');
        $r = self::reason($why);

        return $this->tx(function () use ($caseId, $u, $r) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (in_array($a->stage, ['authorization_issued', 'provider_assigned'], true)) {
                throw new DomainRuleException('التمويل محول إلى التزام؛ استخدم إلغاء التعميد لعكسه.');
            }
            $closed = $this->closeReservations($caseId, $u, 'RELEASED', $r);
            if ($closed->isEmpty()) {
                throw new DomainRuleException('لا توجد حجوزات نشطة لتحريرها.');
            }
            $a->forceFill(['reserved' => false, 'split_approved' => null, 'executive' => null]);

            return $this->record($c, $a, 'in_review', $u, 'تم تحرير حجز التمويل', ['reason' => $r, 'amount' => (int) $closed->sum('amount_halalas')]);
        });
    }

    public function returnToCare(string $caseId, User $user, ?string $why): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.reject');
        $r = self::reason($why);

        return $this->tx(function () use ($caseId, $u, $r) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (in_array($a->stage, ['authorization_issued', 'provider_assigned'], true)) {
                throw new DomainRuleException('لا يمكن الإعادة بعد إصدار التعميد.');
            }
            $this->closeReservations($caseId, $u, 'RELEASED', $r);
            $a->forceFill(['reserved' => false, 'split_approved' => null, 'executive' => null]);
            $out = $this->record($c, $a, 'funding_rejected', $u, 'أعاد المحاسب الطلب لمدير الرعاية للمراجعة', [
                'reason' => $r, 'caseStatus' => 'manager_approval', 'notify' => ['title' => 'طلب أعيد من المراجعة المالية', 'roles' => ['manager']],
            ]);
            $c->forceFill(['finance' => null])->save();

            return $out;
        });
    }

    public function rejectFunding(string $caseId, User $user, ?string $why): FinancialApproval
    {
        $u = $this->need($user, 'finance.funding.reject');
        $r = self::reason($why);

        return $this->tx(function () use ($caseId, $u, $r) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (in_array($a->stage, ['authorization_issued', 'provider_assigned'], true)) {
                throw new DomainRuleException('لا يمكن رفض التمويل بعد إصدار التعميد.');
            }
            $this->closeReservations($caseId, $u, 'CANCELLED', $r);
            $a->reserved = false;

            return $this->record($c, $a, 'funding_rejected', $u, 'رُفض التمويل', [
                'reason' => $r, 'caseStatus' => 'rejected', 'beneficiaryText' => 'لم يتم قبول طلبك.', 'notify' => ['title' => 'تم رفض تمويل طلب', 'roles' => ['manager']],
            ]);
        });
    }

    // ---- executive -----------------------------------------------------------------------------------------------------

    public function executiveApprove(string $caseId, User $user, ?string $comment, bool $confirmed): FinancialApproval
    {
        $u = $this->need($user, 'executive.approvals.approve');

        return $this->tx(function () use ($caseId, $u, $comment, $confirmed) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if (! $confirmed) {
                throw new DomainRuleException('يجب تأكيد الاعتماد النهائي.');
            }
            if ($a->stage !== 'pending_executive' || ! $a->split_approved) {
                throw new DomainRuleException('الطلب ليس بانتظار الاعتماد النهائي.');
            }
            if ($a->order === 'RESERVE_THEN_APPROVE' && ! $a->reserved) {
                throw new DomainRuleException('لا يمكن الاعتماد النهائي قبل حجز التمويل المطلوب.');
            }
            if ($this->sumAllocations($a) !== $a->total_cost_halalas) {
                throw new DomainRuleException('التمويل المطلوب غير مكتمل.');
            }
            $note = trim((string) $comment) ?: null;
            $a->executive = array_filter([
                'by' => $u->name, 'byId' => $u->id, 'at' => now_iso(), 'decision' => 'approved', 'reason' => $note, 'fundingVersion' => $a->version,
                'quotationId' => $a->selected_quotation_id,
            ], fn ($v) => $v !== null);

            return $this->record($c, $a, 'executive_approved', $u, 'منح المدير التنفيذي الاعتماد النهائي', [
                'amount' => $a->total_cost_halalas, 'reason' => $note, 'beneficiaryText' => 'تمت الموافقة على الخدمة، وجارٍ إصدار التعميد لمقدم الخدمة.',
                'notify' => $a->reserved ? ['title' => 'طلب جاهز لإصدار خطاب التعميد', 'roles' => ['manager']] : ['title' => 'اعتماد نهائي — بانتظار حجز التمويل', 'roles' => ['finance']],
            ]);
        });
    }

    public function executiveReturn(string $caseId, User $user, ?string $why): FinancialApproval
    {
        $u = $this->need($user, 'executive.approvals.return');
        $r = self::reason($why);

        return $this->tx(function () use ($caseId, $u, $r) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if ($a->stage !== 'pending_executive') {
                throw new DomainRuleException('الطلب ليس بانتظار الاعتماد النهائي.');
            }
            $this->closeReservations($caseId, $u, 'RELEASED', $r);
            $a->forceFill(['reserved' => false, 'split_approved' => null]);

            return $this->record($c, $a, 'in_review', $u, 'أعاد المدير التنفيذي الطلب للمراجعة المالية', [
                'reason' => $r, 'notify' => ['title' => 'طلب أعيد من المدير التنفيذي', 'roles' => ['finance']],
            ]);
        });
    }

    public function executiveReject(string $caseId, User $user, ?string $why): FinancialApproval
    {
        $u = $this->need($user, 'executive.approvals.reject');
        $r = self::reason($why);

        return $this->tx(function () use ($caseId, $u, $r) {
            $c = $this->lockCase($caseId, $u);
            $a = self::mustApproval($caseId);
            if ($a->stage !== 'pending_executive') {
                throw new DomainRuleException('الطلب ليس بانتظار الاعتماد النهائي.');
            }
            $this->closeReservations($caseId, $u, 'CANCELLED', $r);
            $a->forceFill(['reserved' => false, 'executive' => [
                'by' => $u->name, 'byId' => $u->id, 'at' => now_iso(), 'decision' => 'rejected', 'reason' => $r, 'fundingVersion' => $a->version,
            ]]);

            return $this->record($c, $a, 'executive_rejected', $u, 'رفض المدير التنفيذي الطلب', [
                'reason' => $r, 'caseStatus' => 'rejected', 'beneficiaryText' => 'لم يتم قبول طلبك.', 'notify' => ['title' => 'رفض المدير التنفيذي طلبًا', 'roles' => ['finance', 'manager']],
            ]);
        });
    }
}
