<?php

namespace Modules\Finance\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Authorizations\Services\AuthorizationService;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseProjector;
use Modules\Finance\Models\FinanceConfig;
use Modules\Finance\Models\FinanceEvent;
use Modules\Finance\Models\FinancialApproval;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Models\Wallet;

/**
 * Read side of the finance screens (front useFinanceData + pages/finance/*). Everything case-related is limited to the
 * cases the user can access (as the mock did); wallet balances and the ledger need finance.wallets.view / finance.ledger.view
 * (gap fix: the mock exposed them to every finance screen).
 */
class FinanceQueries
{
    public const REVIEW_GROUP = ['pending_review', 'in_review', 'split_approved'];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseProjector $projector, private WalletBalances $balances) {}

    public function requireAny(User $u, array $codes): void
    {
        if (! $this->perms->hasAny($u, $codes)) {
            throw DomainRuleException::forbidden();
        }
    }

    /** @return Collection<int, RahaCase> */
    public function visibleCases(User $u): Collection
    {
        return $this->access->visibleCases($u);
    }

    private function caseIds(User $u): array
    {
        return $this->visibleCases($u)->pluck('id')->all();
    }

    private function walletNames(): array
    {
        return Wallet::query()->pluck('name_ar', 'id')->all();
    }

    public function dashboard(User $u): array
    {
        $this->requireAny($u, ['finance.dashboard.view', 'authorization.view', 'executive.approvals.view']);
        $cases = $this->visibleCases($u);
        $fin = $cases->filter(fn (RahaCase $c) => $c->finance && $c->status !== 'rejected');
        $byStage = [];
        foreach ($fin as $c) {
            $s = $c->finance['stage'] ?? '';
            $byStage[$s] = ($byStage[$s] ?? 0) + 1;
        }
        $count = fn (array $stages) => array_sum(array_intersect_key($byStage, array_flip($stages)));
        $out = [
            'stageCounts' => $byStage,
            'pendingReview' => $count(self::REVIEW_GROUP), 'pendingExecutive' => $count(['pending_executive']), 'fundingIncomplete' => $count(['funding_incomplete']),
            'issuedLetters' => ServiceAuthorization::query()->whereIn('case_id', $cases->pluck('id'))->where('status', 'ISSUED')->count(),
            'config' => FinanceService::configShape(FinanceConfig::current()),
            'totals' => null, 'wallets' => [],
        ];
        if ($this->perms->has($u, 'finance.wallets.view')) {
            $bal = $this->balances->many();
            $out['totals'] = ['ledger' => 0, 'available' => 0, 'reserved' => 0, 'committed' => 0];
            foreach ($bal as $b) {
                foreach ($out['totals'] as $k => $_) {
                    $out['totals'][$k] += $b[$k];
                }
            }
            $out['wallets'] = Wallet::query()->orderBy('id')->get()->map(fn (Wallet $w) => $this->balances->present($w, $bal[$w->id] ?? null))->values()->all();
        }

        return $out;
    }

    /** Rows of the finance / executive request lists (front FinanceRequestsPage). */
    public function requests(User $u, ?string $stage): array
    {
        $this->requireAny($u, ['finance.funding.review', 'executive.approvals.view']);
        $cases = $this->visibleCases($u)->filter(fn (RahaCase $c) => (bool) $c->finance);
        if ($stage) {
            $set = $stage === 'review' ? self::REVIEW_GROUP : [$stage];
            $cases = $cases->filter(fn (RahaCase $c) => in_array($c->finance['stage'] ?? '', $set, true));
        }
        $approvals = FinancialApproval::query()->whereIn('id', $cases->map(fn ($c) => $c->finance['approvalId'] ?? '')->filter()->values())->get()->keyBy('id');
        $users = User::query()->whereIn('id', $approvals->pluck('accountant_id')->filter()->unique())->pluck('name', 'id');

        return $cases->map(function (RahaCase $c) use ($approvals, $users) {
            $a = $approvals[$c->finance['approvalId'] ?? ''] ?? null;
            $allocs = collect($a?->allocations ?? []);
            $pq = $c->preferred_quotation;
            $total = ($a?->total_cost_halalas ?: null) ?? ($pq['grandTotal'] ?? null);

            return [
                'caseId' => $c->id, 'beneficiary' => $c->beneficiary, 'service' => $c->service, 'serviceId' => $c->service_id,
                'providerName' => $a?->provider_name ?? ($pq['providerName'] ?? null), 'totalCostHalalas' => $total,
                'beneficiaryShareHalalas' => $allocs->isNotEmpty() ? (int) $allocs->where('sourceType', 'BENEFICIARY')->sum('amountHalalas') : null,
                'associationShareHalalas' => $allocs->isNotEmpty() ? (int) $allocs->where('sourceType', '!=', 'BENEFICIARY')->sum('amountHalalas') : null,
                'stage' => $c->finance['stage'] ?? null, 'stageLabel' => raha('finance.financeStageLabels.'.($c->finance['stage'] ?? ''), '—'),
                'approvalId' => $a?->id, 'accountantId' => $a?->accountant_id, 'accountant' => $users[$a?->accountant_id ?? ''] ?? 'غير مسند',
                'caseStatus' => $c->status, 'createdAt' => iso($c->created_at), 'updatedAt' => iso($c->updated_at),
            ];
        })->sortByDesc('updatedAt')->values()->all();
    }

    /** Full finance view of one request (front FinanceCasePage + CaseFinanceSummary + FinanceActions + AuthorizationBox). */
    public function caseSummary(string $caseId, User $u, ?RahaCase $c = null): array
    {
        if (! $c) {
            $this->requireAny($u, ['finance.funding.review', 'finance.dashboard.view', 'executive.approvals.view', 'authorization.view']);
            $c = $this->access->findAccessible($caseId, $u);
        }
        $c->unsetRelation('tasks');
        $a = FinanceService::approvalFor($c->id);
        $names = $this->walletNames();
        $seeWallets = $this->perms->has($u, 'finance.wallets.view');
        $allocWallets = collect($a?->allocations ?? [])->pluck('walletId')->filter()->unique()->values()->all();
        $bal = $seeWallets ? $this->balances->many() : [];
        $allocations = array_map(fn ($x) => $x + array_filter([
            'walletName' => isset($x['walletId']) ? ($names[$x['walletId']] ?? null) : null,
            'availableHalalas' => isset($x['walletId']) && $seeWallets ? ($bal[$x['walletId']]['available'] ?? null) : null,
        ], fn ($v) => $v !== null), $a?->allocations ?? []);
        $wallets = $seeWallets
            ? Wallet::query()->orderBy('id')->get()->filter(fn (Wallet $w) => WalletBalances::eligible($w, $c->service_id) || in_array($w->id, $allocWallets, true))
                ->map(fn (Wallet $w) => $this->balances->present($w, $bal[$w->id] ?? null) + ['eligible' => WalletBalances::eligible($w, $c->service_id)])->values()->all()
            : [];
        $auth = app(AuthorizationService::class);
        $letters = ServiceAuthorization::query()->where('case_id', $c->id)->orderByDesc('created_at')->get();

        return [
            'case' => $this->projector->forUser($c, $u),
            'approval' => $a?->toFront(),
            'allocations' => $allocations,
            'wallets' => $wallets,
            'reservations' => FundingReservation::query()->where('case_id', $c->id)->orderByDesc('created_at')->get()
                ->map(fn (FundingReservation $r) => $r->toFront() + ['walletName' => $names[$r->wallet_id] ?? $r->wallet_id, 'statusLabel' => raha("finance.reservationStatusLabels.{$r->status}", $r->status)])->values()->all(),
            'events' => FinanceEvent::query()->where('case_id', $c->id)->orderByDesc('at')->orderByDesc('id')->get()->map->toFront()->values()->all(),
            'letters' => $letters->map(fn (ServiceAuthorization $l) => $l->toFront() + ['statusLabel' => raha("finance.authorizationStatusLabels.{$l->status}", $l->status)])->values()->all(),
            'blockers' => $auth->blockers($c, $a),
            'preferredQuotation' => $c->preferred_quotation,
            'config' => FinanceService::configShape(FinanceConfig::ofVersion((int) ($a?->config_version ?? $c->finance['configVersion'] ?? FinanceConfig::current()->version))),
            'editableAllocations' => ! $c->isArchived() && $a && in_array($a->stage, ['in_review', 'funding_incomplete'], true) && ! $a->reserved && $this->perms->has($u, 'finance.funding.allocate'),
            'actions' => $this->actions($c, $a, $u),
            'authorizationActions' => $auth->caseActions($c, $a, $letters, $u),
        ];
    }

    /** Available finance actions (front finance_actions.tsx `btns`). `key` = POST finance/cases/{id}/<key>. */
    public function actions(RahaCase $c, ?FinancialApproval $a, User $u): array
    {
        if ($c->isArchived()) {
            return [];
        }
        $can = fn (string $p) => $this->perms->has($u, $p);
        $stage = $c->finance['stage'] ?? null;
        $closed = ! $a || in_array($a->stage, FinanceService::CLOSED, true);
        $open = ! $closed && $stage !== 'pending_review' && $stage !== 'pending_executive';
        $rows = [
            ['start', 'بدء المراجعة المالية', $stage === 'pending_review' && $can('finance.funding.review'), 'primary', false],
            ['approve-split', 'اعتماد التوزيع المالي', $a && in_array($a->stage, ['in_review', 'funding_incomplete'], true) && $a->allocations && $can('finance.funding.approve'), 'primary', false],
            ['reserve', 'حجز التمويل', $a && $a->split_approved && ! $a->reserved && ($a->order === 'RESERVE_THEN_APPROVE' || $a->executive) && ! $closed && $can('finance.funding.reserve'), 'primary', false],
            ['release', 'تحرير الحجز', $a && $a->reserved && ! $closed && $can('finance.funding.release'), 'outline', true],
            ['return-to-care', 'إعادة الطلب للمراجعة', $open && $can('finance.funding.reject'), 'outline', true],
            ['reject', 'رفض التمويل', $open && $can('finance.funding.reject'), 'danger', true],
            ['executive/approve', 'اعتماد نهائي', $stage === 'pending_executive' && $can('executive.approvals.approve'), 'primary', false],
            ['executive/return', 'إعادة للمراجعة', $stage === 'pending_executive' && $can('executive.approvals.return'), 'outline', true],
            ['executive/reject', 'رفض الطلب', $stage === 'pending_executive' && $can('executive.approvals.reject'), 'danger', true],
        ];

        return array_values(array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'variant' => $r[3], 'requiresReason' => $r[4]], array_filter($rows, fn ($r) => (bool) $r[2])));
    }

    private function presentReservations(Collection $rows): array
    {
        $names = $this->walletNames();

        return $rows->map(fn (FundingReservation $r) => $r->toFront() + [
            'walletName' => $names[$r->wallet_id] ?? $r->wallet_id, 'statusLabel' => raha("finance.reservationStatusLabels.{$r->status}", $r->status),
        ])->values()->all();
    }

    public function reservations(User $u): array
    {
        $this->requireAny($u, ['finance.ledger.view', 'finance.commitments.view', 'finance.funding.review']);

        return $this->presentReservations(FundingReservation::query()->whereIn('case_id', $this->caseIds($u))->orderByDesc('created_at')->get());
    }

    public function commitments(User $u): array
    {
        $this->requireAny($u, ['finance.commitments.view']);

        return $this->presentReservations(FundingReservation::query()->whereIn('case_id', $this->caseIds($u))->whereIn('status', ['CONVERTED', 'REVERSED'])->orderByDesc('created_at')->get());
    }

    public function events(User $u, ?string $caseId): array
    {
        $this->requireAny($u, ['finance.ledger.view']);
        $ids = $this->caseIds($u);
        $q = FinanceEvent::query()->whereIn('case_id', $caseId ? array_intersect($ids, [$caseId]) : $ids)->orderByDesc('at')->orderByDesc('id');

        return $q->get()->map->toFront()->values()->all();
    }

    public function walletBalancesReport(User $u): array
    {
        $this->requireAny($u, ['finance.ledger.view']);
        $bal = $this->balances->many();
        $rows = Wallet::query()->orderBy('id')->get()->map(fn (Wallet $w) => [
            'walletId' => $w->id, 'nameAr' => $w->name_ar, 'nameEn' => $w->name_en, 'category' => $w->category, 'status' => $w->status,
        ] + ($bal[$w->id] ?? ['ledger' => 0, 'reserved' => 0, 'committed' => 0, 'available' => 0]))->values();
        $totals = [];
        foreach (['ledger', 'reserved', 'committed', 'available'] as $k) {
            $totals[$k] = (int) $rows->sum($k);
        }

        return ['rows' => $rows->all(), 'totals' => $totals];
    }

    public function ledger(string $walletId, User $u): array
    {
        $this->requireAny($u, ['finance.ledger.view']);
        WalletService::get($walletId);

        return LedgerEntry::query()->where('wallet_id', $walletId)->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (LedgerEntry $e) => $e->toFront() + ['typeLabel' => raha("finance.ledgerTypeLabels.{$e->type}", $e->type === 'RESERVATION_CANCELLED' ? 'إلغاء حجز (ملغي)' : $e->type)])->values()->all();
    }

    public function wallet(string $id, User $u): array
    {
        $this->requireAny($u, ['finance.wallets.view']);
        $w = WalletService::get($id);
        $out = $this->balances->present($w);
        if ($this->perms->has($u, 'finance.ledger.view')) {
            $out['ledgerEntries'] = $this->ledger($id, $u);
        }
        if ($this->perms->hasAny($u, ['finance.ledger.view', 'finance.commitments.view'])) {
            $out['reservations'] = $this->presentReservations(FundingReservation::query()->where('wallet_id', $id)->whereIn('case_id', $this->caseIds($u))->orderByDesc('created_at')->get());
        }

        return $out;
    }

    public function wallets(User $u): array
    {
        $this->requireAny($u, ['finance.wallets.view']);
        $bal = $this->balances->many();

        return Wallet::query()->orderBy('id')->get()->map(fn (Wallet $w) => $this->balances->present($w, $bal[$w->id] ?? null))->values()->all();
    }
}
