<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\User;
use Modules\Authorizations\Models\ServiceAuthorization;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\WorkflowService;
use Modules\Finance\Models\FinancialApproval;

/** manager_assign authorization guard + beneficiary finance note (Phase 5 pointers). */
class FinanceAssignmentTest extends CasesTestCase
{
    private function letter(string $caseId, string $providerId, string $status = 'ISSUED'): ServiceAuthorization
    {
        return ServiceAuthorization::query()->create([
            'id' => 'AUL-T1', 'authorization_number' => 'AUTH-2026-99999', 'case_id' => $caseId, 'provider_id' => $providerId, 'provider_name' => 'x',
            'financial_approval_id' => 'FA-1', 'service_name' => 'x', 'description' => 'x', 'quantity' => 1, 'unit' => 'x', 'amount_halalas' => 10000,
            'scope' => 'x', 'expected_start' => '2026-12-01', 'approval_ref' => 'x', 'status' => $status, 'version' => 1,
        ]);
    }

    public function test_manager_assign_requires_issued_matching_authorization(): void
    {
        $id = 'REQ-000120';
        RahaCase::whereKey($id)->update(['status' => 'preliminary_approved', 'finance' => json_encode(['approvalId' => 'FA-1', 'stage' => 'executive_approved', 'configVersion' => 1])]);
        $service = app(WorkflowService::class);
        $manager = User::find('u5');
        $this->act('manager', $id, 'manager_assign', ['providerId' => 'PRV-0005'])->assertForbidden(); // requirement noQuotation not met yet

        $this->letter($id, 'PRV-0005');
        RahaCase::whereKey($id)->update(['finance' => json_encode(['approvalId' => 'FA-1', 'stage' => 'authorization_issued', 'configVersion' => 1, 'authorizationId' => 'AUL-T1'])]);
        try {
            $service->perform($id, 'manager_assign', $manager, ['providerId' => 'PRV-0005']);
            $this->fail('expected guard');
        } catch (\App\Exceptions\DomainRuleException $e) {
            $this->assertSame('لا يمكن إسناد الخدمة قبل الاعتماد المالي والنهائي وإصدار خطاب التعميد.', $e->getMessage());
        }
        $this->act('manager', $id, 'manager_assign', ['providerId' => 'PRV-0001', 'authorizationId' => 'AUL-T1'])->assertStatus(422)->assertJsonPath('message', 'مقدم الخدمة يجب أن يطابق خطاب التعميد.');
        $this->act('manager', $id, 'manager_assign', ['providerId' => 'PRV-0005', 'authorizationId' => 'AUL-T1'])->assertOk()
            ->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.providerId', 'PRV-0005')->assertJsonPath('data.providerAccepted', false);
        $c = RahaCase::find($id);
        $this->assertSame($c->provider, $c->assignee);
        $this->assertDatabaseHas('case_activities', ['case_id' => $id, 'action' => 'manager_assign', 'text' => "تم إسناد الخدمة لمقدم الخدمة {$c->provider}"]);
        $this->assertDatabaseHas('notifications', ['title' => 'تم إسناد طلب إليك', 'provider_id' => 'PRV-0005', 'case_id' => $id]);
        // The provider now sees it and can accept.
        $this->act('provider', $id, 'provider_accept')->assertOk();
    }

    public function test_beneficiary_finance_note_shows_contribution_only_after_executive_approval(): void
    {
        $id = 'REQ-000114';
        RahaCase::whereKey($id)->update(['status' => 'preliminary_approved', 'finance' => json_encode(['approvalId' => 'FA-2', 'stage' => 'pending_executive', 'configVersion' => 1])]);
        FinancialApproval::query()->create([
            'id' => 'FA-2', 'case_id' => $id, 'allocations' => [['sourceType' => 'BENEFICIARY', 'amountHalalas' => 5000], ['sourceType' => 'WALLET', 'amountHalalas' => 20000]],
            'config_version' => 1, 'order' => 'RESERVE_THEN_APPROVE', 'stage' => 'pending_executive', 'history' => [],
        ]);
        $this->asRole('beneficiary')->getJson("/api/v1/portal/requests/$id")->assertJsonPath('data.financeNote.label', 'بانتظار الاعتماد النهائي')->assertJsonPath('data.financeNote.contribution', null)
            ->assertJsonPath('data.beneficiaryStatusLabel', 'بانتظار الاعتماد النهائي');
        FinancialApproval::whereKey('FA-2')->update(['stage' => 'executive_approved', 'executive' => json_encode(['decision' => 'approved'])]);
        RahaCase::whereKey($id)->update(['finance' => json_encode(['approvalId' => 'FA-2', 'stage' => 'executive_approved', 'configVersion' => 1])]);
        $data = $this->getJson("/api/v1/portal/requests/$id")->assertJsonPath('data.financeNote.contribution', 5000)->json('data');
        $this->assertStringNotContainsString('WALLET', json_encode($data));
    }
}
