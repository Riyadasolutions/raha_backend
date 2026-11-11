<?php

namespace Modules\Finance\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\TaskService;
use Tests\TestCase;

/**
 * Drives a request to preliminary_approved through the real workflow (manager claims + care_approve) and then through the
 * finance API. Shared by the Finance and Authorizations feature tests (port of front phase5_finance.test.ts helpers).
 */
abstract class FinanceTestCase extends TestCase
{
    protected const ID = 'REQ-000120';

    protected const COST = 300000; // 3,000 SAR

    protected const LETTER = ['description' => 'زيارات تمريض', 'quantity' => 10, 'unit' => 'زيارة', 'scope' => 'زيارات تمريض منزلية لمدة 4 أسابيع', 'expectedStart' => '2026-12-01'];

    protected function split(int $general = 100000, int $homecare = 140000, int $ben = 60000): array
    {
        return [
            ['id' => 'a1', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-001', 'amountHalalas' => $general],
            ['id' => 'a2', 'sourceType' => 'RAHA_WALLET', 'walletId' => 'WAL-004', 'amountHalalas' => $homecare],
            ['id' => 'a3', 'sourceType' => 'BENEFICIARY', 'amountHalalas' => $ben],
        ];
    }

    /** Selected quotation + home-nursing service on the seeded manager_approval request, then care_approve through the API. */
    protected function prelim(string $id = self::ID, array $extra = []): string
    {
        $case = RahaCase::query()->findOrFail($id);
        $case->forceFill(array_merge([
            'service_id' => 'SRV-001', 'service' => 'تمريض منزلي', 'status' => 'manager_approval', 'quotation_required' => true, 'provider_id' => null, 'provider' => null,
            'preferred_quotation' => ['roundId' => 'QR-1', 'quotationId' => 'QT-1', 'providerId' => 'PRV-0005', 'providerName' => 'مركز عناية للرعاية المنزلية', 'grandTotal' => self::COST, 'reason' => 'مطابق', 'by' => 'm', 'at' => now_iso()],
        ], $extra))->save();
        // Seeded task ids use index 1 while TaskService numbers by count; start from a clean task list (see report: id collision).
        CaseTask::query()->where('case_id', $id)->delete();
        app(TaskService::class)->withCurrentTask($case->refresh());
        $this->asRole('manager')->postJson("/api/v1/cases/$id/task/claim")->assertOk();
        $this->postJson("/api/v1/cases/$id/actions/care_approve", ['confirmed' => true, 'fields' => ['conditions' => 'استكمال الاعتماد التالي']])
            ->assertOk()->assertJsonPath('data.finance.stage', 'pending_review');

        return $id;
    }

    protected function fin(string $action, array $payload = [], string $role = 'finance', string $id = self::ID): TestResponse
    {
        return $this->asRole($role)->postJson("/api/v1/finance/cases/$id/$action", $payload);
    }

    protected function allocate(?array $allocs = null, string $id = self::ID): TestResponse
    {
        return $this->fin('allocations', ['totalCostHalalas' => self::COST, 'costingBasis' => '', 'allocations' => $allocs ?? $this->split()], 'finance', $id);
    }

    protected function toReserved(?array $allocs = null, string $id = self::ID): void
    {
        $this->prelim($id);
        $this->fin('start', [], 'finance', $id)->assertOk();
        $this->allocate($allocs, $id)->assertOk();
        $this->fin('approve-split', [], 'finance', $id)->assertOk();
        $this->fin('reserve', [], 'finance', $id)->assertOk();
    }

    protected function toExecutiveApproved(string $id = self::ID): void
    {
        $this->toReserved(null, $id);
        $this->fin('executive/approve', ['comment' => '', 'confirmed' => true], 'executive', $id)->assertOk();
    }

    /** @return array the issued letter (front ServiceAuthorization) */
    protected function toIssued(array $letter = self::LETTER, string $id = self::ID): array
    {
        $this->toExecutiveApproved($id);

        return $this->fin('authorization', $letter, 'manager', $id)->assertCreated()->json('data');
    }

    protected function balance(string $walletId): array
    {
        return app(\Modules\Finance\Services\WalletBalances::class)->of($walletId);
    }

    protected function case(string $id = self::ID): RahaCase
    {
        return RahaCase::query()->findOrFail($id);
    }
}
