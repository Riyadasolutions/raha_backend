<?php

namespace Modules\Finance\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\FundingReservation;
use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Models\Wallet;

/** Support wallets (port of front wallet_service.ts). Ids from Sequence (the mock used count+1). */
class WalletService
{
    public const NO_PERMISSION = 'لا تملك صلاحية إدارة المحافظ.';

    public function __construct(private PermissionResolver $perms, private FinanceService $finance, private WalletBalances $balances) {}

    private function need(User $u): User
    {
        if (! $this->perms->has($u, 'finance.wallets.manage')) {
            throw DomainRuleException::forbidden(self::NO_PERMISSION);
        }

        return $u;
    }

    public static function get(string $id, bool $lock = false): Wallet
    {
        $q = Wallet::query()->whereKey($id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw DomainRuleException::notFound('المحفظة غير موجودة.');
    }

    private static function int(mixed $v): ?int
    {
        return is_int($v) ? $v : (is_string($v) && preg_match('/^-?\d+$/', $v) ? (int) $v : null);
    }

    public function create(array $i, User $user): Wallet
    {
        $u = $this->need($user);
        $name = trim((string) ($i['nameAr'] ?? ''));
        if ($name === '') {
            throw new DomainRuleException('اسم المحفظة مطلوب.');
        }
        $opening = self::int($i['openingHalalas'] ?? 0);
        if ($opening === null || $opening < 0) {
            throw new DomainRuleException('الرصيد الافتتاحي غير صالح.');
        }

        return DB::transaction(function () use ($i, $u, $name, $opening) {
            $w = Wallet::query()->create([
                'id' => Sequence::walletId(), 'name_ar' => $name, 'name_en' => trim((string) ($i['nameEn'] ?? '')) ?: null,
                'description' => trim((string) ($i['description'] ?? '')), 'category' => trim((string) ($i['category'] ?? '')) ?: 'عام',
                'eligible_service_ids' => array_values((array) ($i['eligibleServiceIds'] ?? [])), 'status' => 'ACTIVE', 'admin_ids' => [$u->id],
            ]);
            if ($opening) {
                $this->finance->ledger($w->id, null, null, $opening, 'OPENING', 'رصيد افتتاحي تجريبي', $u);
            }
            Activity::emit('تم إنشاء محفظة دعم', 'محفظة', $w->id, $u);

            return $w;
        });
    }

    public function update(string $id, array $patch, User $user): Wallet
    {
        $u = $this->need($user);
        $w = self::get($id);
        $name = trim((string) ($patch['nameAr'] ?? ''));
        if ($name === '') {
            throw new DomainRuleException('اسم المحفظة مطلوب.');
        }
        $w->forceFill([
            'name_ar' => $name, 'name_en' => trim((string) ($patch['nameEn'] ?? '')) ?: null, 'description' => trim((string) ($patch['description'] ?? '')),
            'category' => trim((string) ($patch['category'] ?? '')) ?: 'عام', 'eligible_service_ids' => array_values((array) ($patch['eligibleServiceIds'] ?? [])),
        ])->save();
        Activity::emit('تم تعديل محفظة دعم', 'محفظة', $id, $u);

        return $w;
    }

    public function addFunds(string $id, mixed $amount, ?string $note, User $user): Wallet
    {
        $u = $this->need($user);
        $w = self::get($id);
        if ($w->status !== 'ACTIVE') {
            throw new DomainRuleException('المحفظة موقوفة.');
        }
        $amount = self::int($amount);
        if ($amount === null || $amount <= 0) {
            throw new DomainRuleException('أدخل مبلغًا موجبًا.');
        }
        DB::transaction(function () use ($id, $amount, $note, $u) {
            $this->finance->ledger($id, null, null, $amount, 'FUNDING_ADDED', trim((string) $note) ?: 'إضافة تمويل تجريبي', $u);
            Activity::emit('تمت إضافة تمويل تجريبي', 'محفظة', $id, $u);
        });

        return $w;
    }

    /** Authorized adjustment; can never push the available balance below zero (wallet row locked while checking). */
    public function adjust(string $id, mixed $amount, ?string $note, User $user): Wallet
    {
        $u = $this->need($user);
        self::get($id);
        $note = trim((string) $note);
        if ($note === '') {
            throw new DomainRuleException('يجب إدخال سبب التعديل.');
        }
        $amount = self::int($amount);
        if ($amount === null || $amount === 0) {
            throw new DomainRuleException('مبلغ التعديل غير صالح.');
        }

        return DB::transaction(function () use ($id, $amount, $note, $u) {
            $w = self::get($id, true);
            if ($this->balances->of($id)['available'] + $amount < 0) {
                throw new DomainRuleException('لا يمكن أن يصبح الرصيد المتاح سالبًا.');
            }
            $this->finance->ledger($id, null, null, $amount, 'ADJUSTMENT', $note, $u);
            Activity::emit('تم تعديل رصيد محفظة', 'محفظة', $id, $u);

            return $w;
        });
    }

    public function setStatus(string $id, bool $active, User $user): Wallet
    {
        $u = $this->need($user);
        $w = self::get($id);
        $w->forceFill(['status' => $active ? 'ACTIVE' : 'INACTIVE'])->save();
        Activity::emit($active ? 'تم تفعيل محفظة' : 'تم إيقاف محفظة', 'محفظة', $id, $u);

        return $w;
    }

    public function remove(string $id, User $user): void
    {
        $u = $this->need($user);
        DB::transaction(function () use ($id, $u) {
            $w = self::get($id, true);
            if (LedgerEntry::query()->where('wallet_id', $id)->exists() || FundingReservation::query()->where('wallet_id', $id)->exists()) {
                throw new DomainRuleException('لا يمكن حذف محفظة لها سجل عمليات مالية؛ يمكن إيقافها فقط.');
            }
            $w->delete();
            Activity::emit('تم حذف محفظة', 'محفظة', $id, $u);
        });
    }
}
