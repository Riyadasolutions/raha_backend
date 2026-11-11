<?php

namespace Modules\Finance\Services;

use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Models\Wallet;

/**
 * Wallet balance formula (front finance_service walletBalance):
 *   ledger    = Σ OPENING + FUNDING_ADDED + ADJUSTMENT + DEDUCTION (the only cash-affecting ledger types)
 *   reserved  = Σ ACTIVE reservations, committed = Σ CONVERTED reservations
 *   available = ledger − reserved − committed
 * Reservations and commitments are encumbrances, never cash movements.
 */
class WalletBalances
{
    public const BALANCE_TYPES = ['OPENING', 'FUNDING_ADDED', 'ADJUSTMENT', 'DEDUCTION'];

    /** @return array{ledger: int, reserved: int, committed: int, available: int} */
    public function of(string $walletId): array
    {
        return $this->many([$walletId])[$walletId];
    }

    /**
     * @param  string[]|null  $ids  null = every wallet
     * @return array<string, array{ledger: int, reserved: int, committed: int, available: int}>
     */
    public function many(?array $ids = null): array
    {
        $ids ??= Wallet::query()->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $ledger = LedgerEntry::query()->whereIn('wallet_id', $ids)->whereIn('type', self::BALANCE_TYPES)
            ->groupBy('wallet_id')->selectRaw('wallet_id, SUM(amount_halalas) AS total')->pluck('total', 'wallet_id');
        $res = FundingReservation::query()->whereIn('wallet_id', $ids)->whereIn('status', ['ACTIVE', 'CONVERTED'])
            ->groupBy('wallet_id', 'status')->selectRaw('wallet_id, status, SUM(amount_halalas) AS total')->get();
        $out = [];
        foreach ($ids as $id) {
            $l = (int) ($ledger[$id] ?? 0);
            $r = (int) $res->where('wallet_id', $id)->where('status', 'ACTIVE')->sum('total');
            $c = (int) $res->where('wallet_id', $id)->where('status', 'CONVERTED')->sum('total');
            $out[$id] = ['ledger' => $l, 'reserved' => $r, 'committed' => $c, 'available' => $l - $r - $c];
        }

        return $out;
    }

    public static function eligible(Wallet $w, ?string $serviceId): bool
    {
        $ids = $w->eligible_service_ids ?? [];

        return $w->status === 'ACTIVE' && (! $ids || ($serviceId && in_array($serviceId, $ids, true)));
    }

    /** Wallet + balance breakdown (front SupportWallet + walletBalance). */
    public function present(Wallet $w, ?array $balance = null): array
    {
        return $w->toFront() + ['eligibleServiceIds' => $w->eligible_service_ids ?? [], 'balance' => $balance ?? $this->of($w->id)];
    }
}
