<?php

namespace Modules\Beneficiaries\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Beneficiaries\Models\Beneficiary;
use Tests\TestCase;

class BeneficiaryDirectoryTest extends TestCase
{
    private function newBeneficiary(array $over = []): array
    {
        return array_merge([
            'firstName' => 'سلمى', 'fatherName' => 'محمد', 'grandName' => 'علي', 'familyName' => 'العمر', 'nationalId' => '1122334455', 'birthDate' => '1980-01-01',
            'gender' => 'أنثى', 'mobile' => '0551112223', 'email' => 'salma@example.com', 'city' => 'الرياض', 'district' => 'النرجس', 'address' => 'شارع 1',
        ], $over);
    }

    public function test_list_with_sensitive_data_and_request_counts(): void
    {
        $res = $this->asRole('auditor')->getJson('/api/v1/beneficiaries')->assertOk()->assertJsonCount(8, 'data');
        $row = collect($res->json('data'))->firstWhere('id', 'BEN-0001');
        $this->assertSame('1087654321', $row['nationalId']);
        $this->assertSame(8, $row['requests']);
        $this->assertFalse($row['masked']);
        $this->assertNotSame('—', $row['lastRequest']);
    }

    public function test_masked_without_view_sensitive(): void
    {
        $res = $this->asRole('medical')->getJson('/api/v1/beneficiaries')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', 'BEN-0001');
        $this->assertTrue($row['masked']);
        $this->assertStringContainsString('•', $row['nationalId']);
        $this->assertNotSame('1087654321', $row['nationalId']);
        $this->getJson('/api/v1/beneficiaries/BEN-0001')->assertOk()->assertJsonPath('data.masked', true)->assertJsonPath('data.address', '');
    }

    public function test_assigned_scope_only_sees_own_beneficiaries(): void
    {
        $res = $this->asRole('team')->getJson('/api/v1/beneficiaries')->assertOk();
        $this->assertEqualsCanonicalizing(['BEN-0001', 'BEN-00434'], array_column($res->json('data'), 'id'));
        $this->assertSame(2, collect($res->json('data'))->firstWhere('id', 'BEN-0001')['requests']);
        $this->getJson('/api/v1/beneficiaries/BEN-00438')->assertForbidden();
        $requests = $this->getJson('/api/v1/beneficiaries/BEN-0001/requests')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['REQ-000113', 'REQ-000114'], array_column($requests, 'id'));
        // Holding a task also grants access.
        DB::table('case_tasks')->insert(['id' => 'task-x', 'case_id' => 'REQ-000123', 'stage' => 'new', 'role' => 'team', 'assigned_user_id' => 'u7',
            'created_at' => now(), 'status' => 'new', 'priority' => 'normal', 'history' => '[]']);
        $this->getJson('/api/v1/beneficiaries/BEN-00438')->assertOk();
        $this->asRole('provider')->getJson('/api/v1/beneficiaries')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/beneficiaries')->assertForbidden();
        $this->asRole('executive')->getJson('/api/v1/beneficiaries')->assertForbidden();
    }

    public function test_create_beneficiary(): void
    {
        $res = $this->asRole('admin')->postJson('/api/v1/beneficiaries', $this->newBeneficiary())->assertCreated();
        $id = $res->json('data.id');
        $this->assertMatchesRegularExpression('/^BEN-\d{4}$/', $id);
        $res->assertJsonPath('data.name', 'سلمى محمد علي العمر')->assertJsonPath('data.nationalId', '1122334455')->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم إنشاء مستفيد', 'record_type' => 'مستفيد', 'record_id' => $id]);
        $this->postJson('/api/v1/beneficiaries', $this->newBeneficiary())->assertStatus(422)->assertJsonPath('message', 'يوجد مستفيد مسجل بهذا الرقم.');
        $this->postJson('/api/v1/beneficiaries', $this->newBeneficiary(['nationalId' => '3122334455']))->assertStatus(422)->assertJsonPath('message', 'رقم هوية من ١٠ أرقام يبدأ بـ 1 أو 2.');
        $this->postJson('/api/v1/beneficiaries', $this->newBeneficiary(['nationalId' => '1999999999', 'mobile' => '123']))->assertStatus(422)->assertJsonPath('message', 'رقم جوال صحيح يبدأ بـ 05.');
        $this->asRole('auditor')->postJson('/api/v1/beneficiaries', $this->newBeneficiary(['nationalId' => '1999999999']))->assertForbidden();
    }

    public function test_update_renames_cases_portal_account_and_logs(): void
    {
        $this->asRole('auditor')->putJson('/api/v1/beneficiaries/BEN-0001', ['name' => 'أحمد محمد العتيبي', 'mobile' => '0559998887', 'city' => 'الرياض'])
            ->assertOk()->assertJsonPath('data.name', 'أحمد محمد العتيبي')->assertJsonPath('data.mobile', '0559998887');
        $this->assertSame('أحمد محمد العتيبي', DB::table('cases')->where('id', 'REQ-000117')->value('beneficiary'));
        $account = User::find('u-BEN-0001');
        $this->assertSame('أحمد محمد', $account->name);
        $this->assertSame('0559998887', $account->mobile);
        $log = DB::table('beneficiary_activity')->where('beneficiary_id', 'BEN-0001')->first();
        $this->assertMatchesRegularExpression('/^تم تحديث بيانات المستفيد \(\d حقول\)$/u', $log->text);

        // national id change updates the login identifier too
        $this->putJson('/api/v1/beneficiaries/BEN-0001', ['nationalId' => '1000000001'])->assertOk();
        $this->assertSame('1000000001', User::find('u-BEN-0001')->national_id);
        $this->putJson('/api/v1/beneficiaries/BEN-0001', ['nationalId' => '1048213482'])->assertStatus(422)->assertJsonPath('message', 'يوجد مستفيد مسجل بهذا الرقم.');
        $this->putJson('/api/v1/beneficiaries/BEN-0001', ['mobile' => '12'])->assertStatus(422)->assertJsonPath('message', 'رقم الجوال يجب أن يبدأ بـ 05 ويتكون من ١٠ أرقام');
    }

    public function test_edit_permissions(): void
    {
        $this->asRole('medical')->putJson('/api/v1/beneficiaries/BEN-0001', ['city' => 'جدة'])->assertForbidden();
        // edit without viewSensitive cannot touch the national id
        $perms = json_decode(DB::table('roles')->where('id', 'medical')->value('permissions'), true);
        DB::table('roles')->where('id', 'medical')->update(['permissions' => json_encode($perms + ['beneficiaries.edit' => 'ALL'])]);
        $this->asRole('medical')->putJson('/api/v1/beneficiaries/BEN-0001', ['nationalId' => '1000000001'])->assertForbidden()
            ->assertJsonPath('message', 'تعديل رقم الهوية يتطلب صلاحية أعلى.');
        $this->putJson('/api/v1/beneficiaries/BEN-0001', ['city' => 'جدة'])->assertOk()->assertJsonPath('data.city', 'جدة');
        $this->assertSame('1087654321', Beneficiary::find('BEN-0001')->national_id);
    }

    public function test_documents_and_financials(): void
    {
        $docs = $this->asRole('finance')->getJson('/api/v1/beneficiaries/BEN-0001/documents')->assertOk()->json('data');
        $this->assertCount(8, $docs);
        $this->assertSame(['id', 'name', 'category', 'caseId', 'at', 'uploadedBy', 'size', 'type', 'hasFile'], array_keys($docs[0]));
        $this->assertNotContains('التقرير الطبي', array_column($docs, 'category'));

        $rows = $this->getJson('/api/v1/beneficiaries/BEN-0001/financials')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['REQ-000111', 'REQ-000112', 'REQ-000113', 'REQ-000114'], array_column($rows, 'caseId'));
        $visit = collect($rows)->firstWhere('caseId', 'REQ-000114');
        $this->assertSame(['caseId' => 'REQ-000114', 'service' => 'زيارة طبيب', 'approved' => 600, 'charity' => 480, 'beneficiary' => 120, 'paid' => 0, 'paymentStatus' => 'بانتظار السداد'], $visit);
        $this->assertSame('تم السداد', collect($rows)->firstWhere('caseId', 'REQ-000111')['paymentStatus']);

        $this->asRole('auditor')->getJson('/api/v1/beneficiaries/BEN-0001/financials')->assertForbidden();
        $this->asRole('team')->getJson('/api/v1/beneficiaries/BEN-0001/documents')->assertForbidden();
    }

    public function test_notes_are_persisted_and_feed_activity(): void
    {
        $this->asRole('relations')->postJson('/api/v1/beneficiaries/BEN-00438/notes', ['text' => 'يفضل التواصل صباحًا.', 'visibility' => 'beneficiary'])
            ->assertCreated()->assertJsonPath('data.author', 'هدى الزهراني')->assertJsonPath('data.visibility', 'beneficiary');
        $this->postJson('/api/v1/beneficiaries/BEN-00438/notes', ['text' => 'اب'])->assertStatus(422)->assertJsonPath('message', 'يرجى كتابة الملاحظة');
        $this->postJson('/api/v1/beneficiaries/BEN-00438/notes', ['text' => 'نص صحيح', 'visibility' => 'public'])->assertStatus(422);
        $notes = $this->getJson('/api/v1/beneficiaries/BEN-00438/notes')->assertOk()->json('data');
        $this->assertCount(2, $notes);
        $this->assertSame('يفضل التواصل صباحًا.', $notes[0]['text']);

        $activity = $this->getJson('/api/v1/beneficiaries/BEN-00438/activity')->assertOk()->json('data');
        $kinds = array_count_values(array_column($activity, 'kind'));
        $this->assertSame(2, $kinds['note']);
        $this->assertGreaterThan(0, $kinds['request']);
        $this->assertSame('created', end($activity)['id']);
        $ats = array_column($activity, 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats);

        $this->asRole('finance')->postJson('/api/v1/beneficiaries/BEN-00438/notes', ['text' => 'ملاحظة مالية'])->assertForbidden();
        $this->asRole('team')->getJson('/api/v1/beneficiaries/BEN-0001/notes')->assertForbidden();
    }
}
