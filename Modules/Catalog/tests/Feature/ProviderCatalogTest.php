<?php

namespace Modules\Catalog\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Provider;
use Tests\TestCase;

class ProviderCatalogTest extends TestCase
{
    private const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'مركز الشفاء المنزلي', 'type' => 'مركز رعاية منزلية', 'license' => 'LIC-9001', 'registry' => '1010101010', 'phone' => '0112223334',
            'email' => 'info@shifa.example.sa', 'city' => 'الرياض', 'district' => 'الملقا', 'address' => 'طريق أنس بن مالك',
            'contact' => ['name' => 'أحمد', 'mobile' => '0551112223', 'email' => 'a@shifa.example.sa'],
            'services' => [['serviceId' => 'SRV-001'], ['serviceId' => 'SRV-002']], 'status' => 'active', 'notes' => '',
        ], $over);
    }

    public function test_staff_see_all_providers_with_internal_notes(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/providers')->assertOk()->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.services.0.serviceName', 'تمريض منزلي');
        $this->postJson('/api/v1/providers/PRV-0001/notes', ['text' => 'ملاحظة داخلية للتجربة'])->assertCreated()
            ->assertJsonPath('data.internalNotes.0.text', 'ملاحظة داخلية للتجربة')->assertJsonPath('data.internalNotes.0.author', 'محمد الحربي');
        $this->postJson('/api/v1/providers/PRV-0001/notes', ['text' => ''])->assertStatus(422)->assertJsonPath('message', 'اكتب الملاحظة أولًا');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تمت إضافة ملاحظة لمزود', 'record_type' => 'مقدم خدمة', 'record_id' => 'PRV-0001']);
    }

    public function test_provider_sees_only_its_own_record_without_internal_notes(): void
    {
        DB::table('provider_notes')->insert(['id' => '01JTESTNOTE0000000000000000', 'provider_id' => 'PRV-0005', 'at' => now(), 'text' => 'سري', 'author' => 'سارة']);
        $res = $this->asRole('provider')->getJson('/api/v1/providers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'PRV-0005');
        $this->assertSame([], $res->json('data.0.internalNotes'));
        $this->getJson('/api/v1/providers/PRV-0005')->assertOk()->assertJsonPath('data.internalNotes', []);
        $this->getJson('/api/v1/providers/PRV-0001')->assertForbidden();
        $this->putJson('/api/v1/providers/PRV-0005', ['name' => 'اسم جديد'])->assertForbidden();
        $this->postJson('/api/v1/providers/PRV-0005/notes', ['text' => 'محاولة'])->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/providers')->assertForbidden();
    }

    public function test_manager_creates_and_updates_provider(): void
    {
        $res = $this->asRole('manager')->postJson('/api/v1/providers', $this->payload(['logo' => self::LOGO]))->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame('PRV-0008', $id);
        $res->assertJsonCount(2, 'data.services')->assertJsonPath('data.logo', self::LOGO)->assertJsonPath('data.contact.name', 'أحمد');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم إنشاء مزود خدمة', 'record_type' => 'مقدم خدمة', 'record_id' => $id]);

        $this->putJson("/api/v1/providers/$id", ['services' => [['serviceId' => 'SRV-002'], ['serviceId' => 'SRV-006']], 'logo' => null])->assertOk()
            ->assertJsonPath('data.services.0.serviceId', 'SRV-002')->assertJsonCount(2, 'data.services')->assertJsonMissingPath('data.logo');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم تعديل مزود خدمة', 'record_id' => $id]);

        $this->postJson("/api/v1/providers/$id/services", ['serviceId' => 'SRV-003'])->assertOk()->assertJsonCount(3, 'data.services');
        $this->postJson("/api/v1/providers/$id/services", ['serviceId' => 'SRV-003'])->assertOk()->assertJsonCount(3, 'data.services');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تمت إضافة خدمة لمزود', 'record_id' => $id]);
        $this->deleteJson("/api/v1/providers/$id/services/SRV-002")->assertOk()->assertJsonCount(2, 'data.services');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تمت إزالة خدمة من مزود', 'record_id' => $id]);
        $this->postJson("/api/v1/providers/$id/services", ['serviceId' => 'SRV-999'])->assertStatus(422);
    }

    public function test_provider_validation_and_unsafe_logo(): void
    {
        $this->asRole('admin');
        $this->postJson('/api/v1/providers', $this->payload(['logo' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=']))->assertStatus(422)->assertJsonPath('message', 'ملف الشعار غير صالح.');
        $this->postJson('/api/v1/providers', $this->payload(['logo' => 'data:image/png;base64,'.str_repeat('A', 280001)]))->assertStatus(422);
        $this->postJson('/api/v1/providers', $this->payload(['services' => []]))->assertStatus(422)->assertJsonPath('message', 'اختر خدمة واحدة على الأقل.');
        $this->postJson('/api/v1/providers', $this->payload(['phone' => '12345']))->assertStatus(422)->assertJsonPath('message', 'رقم تواصل صحيح يبدأ بـ 0.');
        $this->assertSame(7, Provider::count());
    }

    public function test_auditor_cannot_manage_providers(): void
    {
        $this->asRole('auditor');
        $this->postJson('/api/v1/providers', $this->payload())->assertForbidden();
        $this->putJson('/api/v1/providers/PRV-0001', ['name' => 'تغيير'])->assertForbidden();
        $this->postJson('/api/v1/providers/PRV-0001/services', ['serviceId' => 'SRV-006'])->assertForbidden();
        $this->deleteJson('/api/v1/providers/PRV-0001/services/SRV-001')->assertForbidden();
    }

    public function test_provider_cases_summary_respects_scope(): void
    {
        DB::table('cases')->whereIn('id', ['REQ-000114', 'REQ-000119'])->update(['provider_id' => 'PRV-0001', 'provider' => 'مجمع التعافي الطبي']);
        DB::table('cases')->where('id', 'REQ-000119')->update(['assignee' => 'شخص آخر']);
        $res = $this->asRole('manager')->getJson('/api/v1/providers/PRV-0001/cases')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['REQ-000114', 'REQ-000119'], array_column($res->json('data'), 'id'));
        $this->assertArrayHasKey('beneficiary', $res->json('data.0'));
        // team (cases.view ASSIGNED) only sees the case assigned to them
        $this->asRole('team')->getJson('/api/v1/providers/PRV-0001/cases')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'REQ-000114');
        $this->asRole('provider2')->getJson('/api/v1/providers/PRV-0001/cases')->assertForbidden();
    }
}
