<?php

namespace Modules\Cases\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;

/** Providers must not learn the names of internal employees (or the beneficiary's account) from document metadata. */
class ProviderDocumentUploaderPrivacyTest extends CasesTestCase
{
    private const ID = 'REQ-000113';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('t/a.pdf', 'pdf');
        RahaCase::whereKey(self::ID)->update(['provider_id' => 'PRV-0005', 'provider' => 'x', 'provider_accepted' => true, 'status' => 'assigned']);
        CaseDocument::query()->where('case_id', self::ID)->delete();
    }

    private function doc(string $id, string $by, ?string $byId, string $type = 'medical_report'): void
    {
        CaseDocument::query()->forceCreate(['id' => $id, 'case_id' => self::ID, 'name' => "$id.pdf", 'category' => 'التقرير الطبي', 'type' => 'PDF', 'uploaded_by' => $by, 'uploaded_by_id' => $byId,
            'at' => now(), 'doc_type' => $type, 'path' => 't/a.pdf', 'mime' => 'application/pdf', 'size' => 3, 'visibility' => 'shared', 'superseded' => false]);
    }

    public function test_provider_never_sees_staff_or_account_names_as_uploaders_but_keeps_the_document_and_its_download(): void
    {
        $staff = \App\Models\User::query()->where('account_type', 'STAFF')->first();
        $this->doc('DOC-STAFF', $staff->name, $staff->id);
        $this->doc('DOC-LEGACY', 'موظفة سابقة بلا معرف', null);
        $this->doc('DOC-BEN', 'اسم صاحب الحساب', \App\Models\User::query()->where('account_type', 'BENEFICIARY')->value('id'));

        foreach (['/api/v1/cases/'.self::ID, '/api/v1/cases'] as $url) {
            $res = $this->asRole('provider')->getJson($url)->assertOk();
            $raw = $res->getContent();
            foreach ([$staff->name, 'موظفة سابقة بلا معرف', 'اسم صاحب الحساب'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode(json_decode($raw), JSON_UNESCAPED_UNICODE), "$url leaks the uploader name $secret");
            }
        }
        $docs = collect($this->asRole('provider')->getJson('/api/v1/cases/'.self::ID)->json('data.documents'))->keyBy('id');
        $this->assertSame('جمعية راحة', $docs['DOC-STAFF']['uploadedBy']);
        $this->assertSame('جمعية راحة', $docs['DOC-LEGACY']['uploadedBy']);
        $this->assertSame('المستفيد', $docs['DOC-BEN']['uploadedBy']);
        // metadata and download still work
        $this->assertSame('DOC-STAFF.pdf', $docs['DOC-STAFF']['name']);
        $this->assertSame('PDF', $docs['DOC-STAFF']['type']);
        $this->getJson('/api/v1/cases/'.self::ID.'/documents/DOC-STAFF/download')->assertOk();
    }

    public function test_staff_keep_the_real_uploader_names_and_the_beneficiary_view_is_unchanged(): void
    {
        $staff = \App\Models\User::query()->where('account_type', 'STAFF')->first();
        $this->doc('DOC-STAFF', $staff->name, $staff->id);
        $rows = collect($this->asRole('admin')->getJson('/api/v1/cases/'.self::ID)->assertOk()->json('data.documents'))->keyBy('id');
        $this->assertSame($staff->name, $rows['DOC-STAFF']['uploadedBy']);
    }

    public function test_provider_medical_access_is_not_broadened(): void
    {
        $this->doc('DOC-STAFF', 'x', null);
        $m = $this->asRole('provider')->getJson('/api/v1/cases/'.self::ID)->assertOk()->json('data');
        foreach (['diagnosis', 'hospital', 'doctor', 'reportDate'] as $k) {
            $this->assertArrayNotHasKey($k, $m['medical'] ?? []);
        }
        // a different provider still cannot open the case or its documents
        $this->asRole('provider2')->getJson('/api/v1/cases/'.self::ID.'/documents/DOC-STAFF/download')->assertStatus(404);
    }
}
