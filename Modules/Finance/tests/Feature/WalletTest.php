<?php

namespace Modules\Finance\Tests\Feature;

use Modules\Finance\Models\LedgerEntry;
use Modules\Finance\Models\Wallet;
use Modules\Finance\Services\FinanceService;

/** Wallets and ledger (front phase5_finance "wallets and ledger" + wallet_service rules). */
class WalletTest extends FinanceTestCase
{
    public function test_balance_formula_and_listing(): void
    {
        $this->assertSame(['ledger' => 5000000, 'reserved' => 0, 'committed' => 0, 'available' => 5000000], $this->balance('WAL-001'));
        $rows = $this->asRole('finance')->getJson('/api/v1/wallets')->assertOk()->json('data');
        $this->assertCount(5, $rows);
        $this->assertSame(['ledger' => 100000, 'reserved' => 0, 'committed' => 0, 'available' => 100000], collect($rows)->firstWhere('id', 'WAL-005')['balance']);
        $this->asRole('executive')->getJson('/api/v1/wallets/WAL-001')->assertOk()->assertJsonPath('data.nameAr', 'محفظة الدعم العام')->assertJsonCount(1, 'data.ledgerEntries');
        $this->asRole('finance')->getJson('/api/v1/finance/reports/wallet-balances')->assertOk()->assertJsonPath('data.totals.ledger', 11600000);
    }

    public function test_demo_funding_adds_a_ledger_entry(): void
    {
        $this->asRole('finance')->postJson('/api/v1/wallets/WAL-005/funds', ['amountHalalas' => 50000, 'note' => 'تبرع تجريبي'])->assertOk()
            ->assertJsonPath('data.balance.ledger', 150000);
        $this->assertSame('FUNDING_ADDED', LedgerEntry::query()->where('wallet_id', 'WAL-005')->orderByDesc('id')->value('type'));
        $this->postJson('/api/v1/wallets/WAL-005/funds', ['amountHalalas' => 0])->assertStatus(422)->assertJsonPath('message', 'أدخل مبلغًا موجبًا.');
        $ledger = $this->getJson('/api/v1/wallets/WAL-005/ledger')->assertOk()->json('data');
        $this->assertSame(['FUNDING_ADDED', 'OPENING'], array_column($ledger, 'type'));
        $this->assertSame('إضافة تمويل', $ledger[0]['typeLabel']);
    }

    public function test_create_update_status_and_delete(): void
    {
        $this->asRole('finance')->postJson('/api/v1/wallets', ['nameAr' => ' ', 'openingHalalas' => 0])->assertStatus(422)->assertJsonPath('message', 'اسم المحفظة مطلوب.');
        $this->postJson('/api/v1/wallets', ['nameAr' => 'x', 'openingHalalas' => -5])->assertStatus(422)->assertJsonPath('message', 'الرصيد الافتتاحي غير صالح.');
        $w = $this->postJson('/api/v1/wallets', ['nameAr' => 'محفظة جديدة', 'description' => 'تجربة', 'category' => '', 'eligibleServiceIds' => ['SRV-001'], 'openingHalalas' => 25000])
            ->assertCreated()->assertJsonPath('data.id', 'WAL-006')->assertJsonPath('data.category', 'عام')->assertJsonPath('data.balance.available', 25000)->json('data');
        $this->assertSame(['u6'], Wallet::query()->find('WAL-006')->admin_ids);
        $this->putJson('/api/v1/wallets/WAL-006', ['nameAr' => 'محفظة معدلة', 'eligibleServiceIds' => []])->assertOk()->assertJsonPath('data.nameAr', 'محفظة معدلة');
        $this->postJson('/api/v1/wallets/WAL-006/status', ['active' => false])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->postJson('/api/v1/wallets/WAL-006/funds', ['amountHalalas' => 100])->assertStatus(422)->assertJsonPath('message', 'المحفظة موقوفة.');
        $this->deleteJson("/api/v1/wallets/{$w['id']}")->assertStatus(422)->assertJsonPath('message', 'لا يمكن حذف محفظة لها سجل عمليات مالية؛ يمكن إيقافها فقط.');
        $this->deleteJson('/api/v1/wallets/WAL-001')->assertStatus(422);
        $empty = $this->postJson('/api/v1/wallets', ['nameAr' => 'فارغة', 'openingHalalas' => 0])->assertCreated()->json('data.id');
        $this->assertSame('WAL-007', $empty);
        $this->deleteJson("/api/v1/wallets/$empty")->assertOk();
        $this->assertNull(Wallet::query()->find($empty));
        $this->getJson('/api/v1/wallets/WAL-999')->assertNotFound()->assertJsonPath('message', 'المحفظة غير موجودة.');
    }

    public function test_adjustment_cannot_push_available_below_zero(): void
    {
        $this->asRole('finance')->postJson('/api/v1/wallets/WAL-005/adjust', ['amountHalalas' => -50000, 'note' => ''])->assertStatus(422)->assertJsonPath('message', 'يجب إدخال سبب التعديل.');
        $this->postJson('/api/v1/wallets/WAL-005/adjust', ['amountHalalas' => 0, 'note' => 'x'])->assertStatus(422)->assertJsonPath('message', 'مبلغ التعديل غير صالح.');
        $this->postJson('/api/v1/wallets/WAL-005/adjust', ['amountHalalas' => -100001, 'note' => 'تصحيح'])->assertStatus(422)->assertJsonPath('message', 'لا يمكن أن يصبح الرصيد المتاح سالبًا.');
        $this->postJson('/api/v1/wallets/WAL-005/adjust', ['amountHalalas' => -40000, 'note' => 'تصحيح'])->assertOk()->assertJsonPath('data.balance.available', 60000);

        // Reserved amounts also count: WAL-001 has 100,000 reserved after a reservation.
        $this->toReserved();
        $this->asRole('finance')->postJson('/api/v1/wallets/WAL-001/adjust', ['amountHalalas' => -4900001, 'note' => 'تصحيح'])->assertStatus(422);
        $this->postJson('/api/v1/wallets/WAL-001/adjust', ['amountHalalas' => -4900000, 'note' => 'تصحيح'])->assertOk()->assertJsonPath('data.balance.available', 0);
    }

    public function test_wallet_permissions(): void
    {
        $this->asRole('social')->getJson('/api/v1/wallets')->assertForbidden();
        $this->asRole('social')->getJson('/api/v1/wallets/WAL-001/ledger')->assertForbidden();
        $this->asRole('manager')->getJson('/api/v1/wallets')->assertForbidden();
        $this->asRole('manager')->getJson('/api/v1/finance/reports/wallet-balances')->assertForbidden();
        $this->asRole('social')->postJson('/api/v1/wallets', ['nameAr' => 'x', 'openingHalalas' => 0])->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية إدارة المحافظ.');
        $this->asRole('executive')->postJson('/api/v1/wallets/WAL-001/funds', ['amountHalalas' => 100, 'note' => 'x'])->assertForbidden();
        $this->asRole('provider')->getJson('/api/v1/wallets')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/wallets')->assertForbidden();
    }

    public function test_percent_split_reconciles_without_silent_rounding(): void
    {
        $r = FinanceService::percentSplit(100001, 80);
        $this->assertSame(100001, $r['part'] + $r['rest']);
        $this->expectExceptionMessage('النسبة يجب أن تكون بين 0 و100.');
        FinanceService::percentSplit(1000, 120);
    }
}
