<?php

namespace Modules\Catalog\Tests\Feature;

use Modules\Catalog\Models\Service;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'رعاية تلطيفية', 'category' => 'رعاية طبية', 'description' => 'رعاية منزلية للحالات المزمنة.', 'status' => 'active',
            'requiresMedicalReport' => true, 'requiresDocuments' => true, 'allowInternal' => true, 'allowExternal' => true, 'portalVisible' => true, 'sortOrder' => 10,
            'requirements' => ['medicalReport' => true, 'prescription' => false, 'needDescription' => false, 'reportMaxAgeDays' => 7,
                'extraDocs' => [['id' => 'x1', 'label' => 'خطة علاجية', 'required' => true, 'active' => true]]],
            'deliveryType' => 'multiple_visits', 'defaultVisitCount' => 6,
        ], $over);
    }

    public function test_staff_lists_services_and_beneficiary_cannot(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/services')->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.id', 'SRV-001')->assertJsonPath('data.0.deliveryType', 'multiple_visits');
        $this->getJson('/api/v1/services/SRV-010')->assertOk()->assertJsonPath('data.requirements.prescription', true);
        $this->getJson('/api/v1/catalog/options')->assertOk()->assertJsonPath('data.providerTypes.0', 'مركز رعاية منزلية');
        $this->asRole('beneficiary')->getJson('/api/v1/services')->assertForbidden();
        $this->asRole('provider')->getJson('/api/v1/services')->assertForbidden();
    }

    public function test_requestable_is_active_portal_visible_sorted_for_any_account(): void
    {
        Service::query()->whereKey('SRV-002')->update(['status' => 'inactive']);
        $res = $this->asRole('beneficiary')->getJson('/api/v1/services/requestable')->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertSame(['SRV-006', 'SRV-001', 'SRV-007', 'SRV-008', 'SRV-009', 'SRV-010', 'SRV-011', 'SRV-012'], $ids);
        $this->assertSame('one_time', $res->json('data.3.deliveryType')); // legacy service without delivery type
        $this->assertArrayNotHasKey('allowInternal', $res->json('data.0'));
        $this->assertSame(true, $res->json('data.1.requirements.medicalReport'));
    }

    public function test_manager_creates_and_updates_a_service_with_audit(): void
    {
        $res = $this->asRole('manager')->postJson('/api/v1/services', $this->payload())->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame('SRV-013', $id);
        $res->assertJsonPath('data.defaultVisitCount', 6)->assertJsonPath('data.requirements.reportMaxAgeDays', 7)->assertJsonPath('data.requirements.extraDocs.0.label', 'خطة علاجية');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم إنشاء خدمة', 'record_type' => 'خدمة', 'record_id' => $id, 'user_id' => 'u5']);

        // Switching to one-time drops the stored visit count (sanitizeDelivery).
        $this->putJson("/api/v1/services/$id", ['deliveryType' => 'one_time'])->assertOk()->assertJsonPath('data.deliveryType', 'one_time')->assertJsonMissingPath('data.defaultVisitCount');
        $this->assertNull(Service::find($id)->default_visit_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم تعديل خدمة', 'record_id' => $id]);

        $this->postJson('/api/v1/services', $this->payload())->assertStatus(422)->assertJsonPath('message', 'توجد خدمة بنفس الاسم.');
    }

    public function test_delivery_validation_errors(): void
    {
        $this->asRole('admin');
        $this->postJson('/api/v1/services', $this->payload(['name' => 'أ ب ج د', 'deliveryType' => 'weekly']))->assertStatus(422)->assertJsonPath('message', 'نوع تنفيذ الخدمة غير صحيح.');
        $this->postJson('/api/v1/services', $this->payload(['name' => 'أ ب ج د', 'deliveryType' => 'one_time', 'defaultVisitCount' => 3]))
            ->assertStatus(422)->assertJsonPath('message', 'عدد الزيارات الافتراضي يخص الخدمات متعددة الزيارات فقط.');
        foreach ([0, 2.5, '3', -1] as $bad) {
            $this->postJson('/api/v1/services', $this->payload(['name' => 'أ ب ج د', 'defaultVisitCount' => $bad]))
                ->assertStatus(422)->assertJsonPath('message', 'عدد الزيارات الافتراضي يجب أن يكون عددًا صحيحًا موجبًا.');
        }
        $this->postJson('/api/v1/services', $this->payload(['name' => 'أ ب ج د', 'requirements' => ['extraDocs' => [['label' => '', 'required' => true, 'active' => true]]]]))
            ->assertStatus(422)->assertJsonPath('message', 'أدخل اسم كل مستند إضافي أو احذفه.');
        $this->postJson('/api/v1/services', $this->payload(['name' => 'أب']))->assertStatus(422)->assertJsonPath('message', 'اسم الخدمة مطلوب (٣ أحرف على الأقل).');
        $this->assertSame(12, Service::count());
    }

    public function test_auditor_cannot_manage_services(): void
    {
        $this->asRole('auditor');
        $this->postJson('/api/v1/services', $this->payload())->assertForbidden();
        $this->putJson('/api/v1/services/SRV-001', ['name' => 'تعديل غير مسموح'])->assertForbidden();
        $this->patchJson('/api/v1/services/SRV-001/toggle')->assertForbidden();
        $this->assertSame('active', Service::find('SRV-001')->status);
    }

    public function test_toggle_flips_status_and_audits(): void
    {
        $this->asRole('manager')->patchJson('/api/v1/services/SRV-003/toggle')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم إيقاف خدمة', 'record_id' => 'SRV-003']);
        $this->patchJson('/api/v1/services/SRV-003/toggle')->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم تفعيل خدمة', 'record_id' => 'SRV-003']);
        $this->patchJson('/api/v1/services/SRV-999/toggle')->assertNotFound();
    }

    public function test_public_services_need_no_login(): void
    {
        Service::query()->whereKey('SRV-004')->update(['public_visible' => false]);
        $res = $this->getJson('/api/v1/public/services')->assertOk();
        $this->assertNotContains('SRV-004', array_column($res->json('data'), 'id'));
        $this->assertSame(['id', 'name', 'description'], array_keys($res->json('data.0')));
    }
}
