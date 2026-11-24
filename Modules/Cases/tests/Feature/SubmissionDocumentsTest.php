<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;

/** Uploads, submission rules (phase1/phase2 requirements), survey, documents and archived read-only behaviour. */
class SubmissionDocumentsTest extends CasesTestCase
{
    public function test_upload_checks_real_mime_and_size(): void
    {
        $this->asRole('beneficiary');
        $res = $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('PDF', $res->json('data.type'));
        Storage::disk('local')->assertExists(Upload::find($res->json('data.id'))->path);

        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, 'this is plain text pretending to be a pdf');
        $this->post('/api/v1/uploads', ['file' => new UploadedFile($tmp, 'evil.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'نوع الملف غير مدعوم. يرجى اختيار PDF أو صورة بصيغة JPG أو PNG.');
        $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->create('big.pdf', 6 * 1024, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'حجم الملف أكبر من الحد المسموح (٥ ميجابايت).');
        $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->create('a.docx', 10, 'application/msword')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_submission_enforces_requirements_and_report_date(): void
    {
        $this->asRole('beneficiary');
        $base = ['serviceId' => 'SRV-001', 'description' => 'x', 'requestFor' => 'self', 'patient' => ['fullName' => 'أحمد'], 'social' => ['housingType' => 'ملك']];
        $this->postJson('/api/v1/portal/requests', $base)->assertStatus(422)->assertJsonPath('message', 'يرجى استكمال المستندات المطلوبة: التقرير الطبي');
        $u = $this->upload();
        $this->postJson('/api/v1/portal/requests', $base + ['documents' => [['uploadId' => $u, 'docType' => 'medical_report', 'reportDate' => now()->subDays(6)->toDateString()]]])
            ->assertStatus(422)->assertJsonPath('message', 'يرجى استكمال المستندات المطلوبة: التقرير الطبي');
        $this->assertNull(Upload::find($u)->attached_at);
        // Rent requires a rental contract (housing rule).
        $this->postJson('/api/v1/portal/requests', array_merge($base, ['social' => ['housingType' => 'إيجار', 'rentAmount' => '2000'], 'documents' => [['uploadId' => $u, 'docType' => 'medical_report', 'reportDate' => now()->toDateString()]]]))
            ->assertStatus(422)->assertJsonPath('message', 'يجب إرفاق عقد الإيجار عند اختيار نوع السكن إيجار.');
        // Someone else's upload cannot be attached.
        $other = $this->asRole('auditor')->upload();
        $this->asRole('beneficiary')->postJson('/api/v1/portal/requests', $base + ['documents' => [['uploadId' => $other, 'docType' => 'medical_report', 'reportDate' => now()->toDateString()]]])
            ->assertStatus(422)->assertJsonPath('message', 'الملف المرفق غير صالح أو تم استخدامه مسبقًا.');
        // Hidden social values are dropped; family request keeps the account owner separate.
        $id = $this->postJson('/api/v1/portal/requests', array_merge($base, ['requestFor' => 'family', 'relationship' => 'أب', 'patient' => ['fullName' => 'عبدالله أحمد'], 'social' => ['housingType' => 'ملك', 'rentAmount' => '2000', 'socialSecurity' => 'لا'],
            'documents' => [['uploadId' => $u, 'docType' => 'medical_report', 'reportDate' => now()->toDateString()]]]))->assertCreated()->json('data.id');
        $c = RahaCase::find($id);
        $this->assertArrayNotHasKey('rentAmount', $c->social);
        $this->assertSame('BEN-0001', $c->account_owner_id);
        $this->assertSame('أب', $c->recipient['relationshipToAccountOwner']);
        $this->assertNotSame('BEN-0001', $c->recipient['id']);
        $this->assertSame('عبدالله أحمد', $c->beneficiary);
        $this->assertSame(now()->toDateString(), $c->medical['reportDate']);
        $this->assertNotNull(Upload::find($u)->attached_at);
        $doc = CaseDocument::where('case_id', $id)->first();
        $this->assertSame('التقرير الطبي', $doc->category);
        $this->assertSame(Upload::find($u)->path, $doc->path);
        $this->postJson('/api/v1/portal/requests', $base + ['documents' => [['uploadId' => $u, 'docType' => 'medical_report', 'reportDate' => now()->toDateString()]]])->assertStatus(422);
    }

    public function test_survey_is_required_before_next_request_and_only_once(): void
    {
        $id = $this->submitAsBeneficiary();
        $this->getJson('/api/v1/portal/requests/pending-survey')->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/portal/requests', ['serviceId' => 'SRV-012', 'patient' => ['fullName' => 'x']])->assertStatus(422);
        $this->postJson("/api/v1/portal/requests/$id/survey", ['ease' => 6, 'clarity' => 5])->assertStatus(422);
        $this->postJson("/api/v1/portal/requests/$id/survey", ['ease' => 4, 'clarity' => 5, 'comment' => 'جيد'])->assertOk()->assertJsonPath('data.requestSurvey.ease', 4)->assertJsonPath('data.surveyPending', false);
        $this->postJson("/api/v1/portal/requests/$id/survey", ['ease' => 4, 'clarity' => 5])->assertStatus(422);
        $this->getJson('/api/v1/portal/requests/pending-survey')->assertOk()->assertJsonPath('data', null);
        // Transport needs no medical document.
        $this->postJson('/api/v1/portal/requests', ['serviceId' => 'SRV-012', 'requestFor' => 'self', 'patient' => ['fullName' => 'x'], 'social' => ['housingType' => 'ملك']])->assertCreated();
    }

    public function test_staff_submits_on_behalf_without_survey_or_fast_track(): void
    {
        $this->fastTrack(true);
        $this->asRole('medical')->postJson('/api/v1/beneficiaries/BEN-0001/requests', ['serviceId' => 'SRV-012'])->assertForbidden();
        $res = $this->asRole('auditor')->postJson('/api/v1/beneficiaries/BEN-0001/requests', ['serviceId' => 'SRV-012', 'description' => 'نقل'])->assertCreated();
        $c = RahaCase::find($res->json('data.id'));
        $this->assertSame('new', $c->status);
        $this->assertNull($c->fast_track);
        $this->assertNull($c->survey_pending);
        $this->assertSame('1087654321', $c->national_id);
        $this->assertDatabaseHas('case_activities', ['case_id' => $c->id, 'text' => 'تم إنشاء الطلب نيابة عن المستفيد بواسطة محمد الحربي']);
        $this->postJson('/api/v1/beneficiaries/BEN-9999/requests', ['serviceId' => 'SRV-012'])->assertNotFound();
    }

    public function test_staff_documents_upload_review_delete_and_download(): void
    {
        $this->asRole('auditor');
        $u = $this->upload('lab.pdf');
        $doc = $this->postJson('/api/v1/cases/REQ-000123/documents', ['uploadId' => $u, 'category' => 'تحاليل'])->assertCreated()->assertJsonPath('data.visibility', 'internal')->json('data');
        $this->get("/api/v1/cases/REQ-000123/documents/{$doc['id']}/download")->assertOk();
        // Review needs the task.
        $this->patchJson("/api/v1/cases/REQ-000123/documents/{$doc['id']}/review", ['status' => 'unclear'])->assertForbidden()->assertJsonPath('message', 'استلم مهمة المراجعة أولًا.');
        $this->claim('auditor', 'REQ-000123');
        $this->patchJson("/api/v1/cases/REQ-000123/documents/{$doc['id']}/review", ['status' => 'unclear'])->assertOk()->assertJsonPath('data.review.status', 'unclear');
        // Delete needs beneficiaries.documents.delete (auditor lacks it; admin has it) and is now recorded.
        $this->deleteJson("/api/v1/cases/REQ-000123/documents/{$doc['id']}")->assertForbidden();
        $this->asRole('admin')->deleteJson("/api/v1/cases/REQ-000123/documents/{$doc['id']}")->assertOk();
        $this->assertDatabaseHas('case_activities', ['case_id' => 'REQ-000123', 'text' => 'حذف سارة العتيبي المستند «تحاليل»']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'حذف مستند', 'record_id' => 'REQ-000123']);
        $this->get("/api/v1/cases/REQ-000123/documents/{$doc['id']}/download")->assertOk(); // staff keep audit access to superseded files
        // Beneficiary of another request cannot download; provider sees only provider-visible docs.
        $this->asRole('beneficiary')->get("/api/v1/portal/requests/REQ-000123")->assertNotFound();
    }

    public function test_beneficiary_and_provider_download_rules(): void
    {
        $id = $this->submitAsBeneficiary();
        $docId = CaseDocument::where('case_id', $id)->value('id');
        $this->asRole('auditor');
        $internal = $this->postJson("/api/v1/cases/$id/documents", ['uploadId' => $this->upload('int.pdf'), 'category' => 'داخلي'])->json('data.id');
        $this->asRole('beneficiary')->getJson("/api/v1/portal/requests/$id")->assertOk()->assertJsonCount(1, 'data.documents');
        // Beneficiaries download through the shared document route? No: that route is staff/provider only.
        $this->get("/api/v1/cases/$id/documents/$docId/download")->assertForbidden();
        $this->get("/api/v1/portal/requests/$id/documents/$docId/download")->assertOk();
        $this->get("/api/v1/portal/requests/$id/documents/$internal/download")->assertNotFound();
        $this->get("/api/v1/portal/requests/REQ-000123/documents/REQ-000123-d1/download")->assertNotFound();
        RahaCase::whereKey($id)->update(['provider_id' => 'PRV-0005', 'status' => 'assigned']);
        $this->asRole('provider')->get("/api/v1/cases/$id/documents/$docId/download")->assertOk();
        $this->get("/api/v1/cases/$id/documents/$internal/download")->assertNotFound();
        $this->asRole('provider2')->get("/api/v1/cases/$id/documents/$docId/download")->assertNotFound();
    }

    public function test_archived_request_is_read_only(): void
    {
        $id = 'REQ-000118'; // archived
        $before = RahaCase::find($id)->toArray();
        $docId = CaseDocument::where('case_id', $id)->value('id');
        $this->asRole('admin')->postJson("/api/v1/cases/$id/comments", ['text' => 'x', 'visibility' => 'internal'])->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
        $this->deleteJson("/api/v1/cases/$id/documents/$docId")->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
        $this->postJson("/api/v1/cases/$id/documents", ['uploadId' => $this->upload(), 'category' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/cases/$id/task/claim")->assertStatus(422);
        $this->getJson("/api/v1/cases/$id/actions")->assertExactJson(['data' => []]);
        $this->postJson("/api/v1/cases/$id/actions/finance_complete")->assertStatus(422);
        $this->assertEquals($before, RahaCase::find($id)->toArray());
        $this->assertSame(1, CaseDocument::where('case_id', $id)->where('superseded', false)->count());
        // Archived requests stay listed (group=archived) and readable.
        $this->asRole('finance')->getJson('/api/v1/cases?group=archived')->assertOk()->assertJsonFragment(['id' => $id]);
        $this->assertNotContains($id, collect($this->getJson('/api/v1/cases')->json('data'))->pluck('id'));
        $this->assertContains($id, collect($this->getJson('/api/v1/cases?includeArchived=1')->json('data'))->pluck('id'));
        // Beneficiary writes on an archived own request are rejected too.
        $this->asRole('beneficiary')->postJson('/api/v1/portal/requests/REQ-000111/survey', ['ease' => 5, 'clarity' => 5])->assertStatus(422)->assertJsonPath('message', 'الطلب مؤرشف وللقراءة فقط.');
    }
}
