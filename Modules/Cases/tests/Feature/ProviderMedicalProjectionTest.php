<?php

namespace Modules\Cases\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;

/** Providers receive an explicit, minimal medical projection, only during the assignment's execution window. */
class ProviderMedicalProjectionTest extends CasesTestCase
{
    private const RECORD = [
        'condition' => 'قصور كلوي مزمن', 'diagnosis' => 'N18.5', 'hospital' => 'مستشفى سري', 'doctor' => 'د. فلان', 'reportDate' => '2026-01-01',
        'committeeComment' => 'تعليق اللجنة الداخلي', 'history' => 'تاريخ مرضي غير ذي صلة', 'internalDecision' => 'قرار داخلي',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function assign(string $status = 'assigned', string $provider = 'PRV-0005'): void
    {
        RahaCase::whereKey('REQ-000113')->update(['provider_id' => $provider, 'provider' => 'x', 'provider_accepted' => true, 'status' => $status, 'medical' => json_encode(self::RECORD)]);
    }

    private function doc(): CaseDocument
    {
        Storage::disk('local')->put('t/med.pdf', 'pdf');

        return CaseDocument::query()->forceCreate(['id' => 'DOC-MED-T', 'case_id' => 'REQ-000113', 'name' => 'med.pdf', 'category' => 'التقرير الطبي', 'type' => 'PDF', 'uploaded_by' => 'x', 'at' => now(), 'doc_type' => 'medical_report',
            'path' => 't/med.pdf', 'mime' => 'application/pdf', 'size' => 3, 'visibility' => 'shared', 'superseded' => false]);
    }

    public function test_assigned_provider_gets_only_the_service_relevant_medical_field(): void
    {
        $this->assign('assigned');
        $m = $this->asRole('provider')->getJson('/api/v1/cases/REQ-000113')->assertOk()->json('data.medical');
        $this->assertSame(['condition' => self::RECORD['condition']], $m);
        $body = $this->getJson('/api/v1/cases/REQ-000113')->getContent();
        foreach (['N18.5', 'مستشفى سري', 'د. فلان', 'تعليق اللجنة', 'تاريخ مرضي', 'قرار داخلي'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        // Staff with the medical tab still see the full record.
        $this->asRole('admin')->getJson('/api/v1/cases/REQ-000113')->assertOk()->assertJsonPath('data.medical.diagnosis', 'N18.5');
    }

    public function test_medical_data_and_documents_close_outside_the_execution_window(): void
    {
        $this->doc();
        foreach (['assigned', 'scheduled', 'in_progress'] as $open) {
            $this->assign($open);
            $r = $this->asRole('provider')->getJson('/api/v1/cases/REQ-000113')->assertOk();
            $this->assertArrayHasKey('medical', $r->json('data'), $open);
            $this->assertContains('DOC-MED-T', array_column($r->json('data.documents'), 'id'), $open);
            $this->getJson('/api/v1/cases/REQ-000113/documents/DOC-MED-T/download')->assertOk();
        }
        foreach (['completed', 'finance_review', 'archived'] as $closed) {
            $this->assign($closed);
            $r = $this->asRole('provider')->getJson('/api/v1/cases/REQ-000113')->assertOk();
            $this->assertArrayNotHasKey('medical', $r->json('data'), $closed);
            $this->assertSame([], $r->json('data.documents'), $closed);
            $this->getJson('/api/v1/cases/REQ-000113/documents/DOC-MED-T/download')->assertNotFound();
        }
    }

    public function test_unrelated_provider_cannot_read_the_case_medical_data_or_documents(): void
    {
        $this->assign('assigned', 'PRV-0005');
        $this->doc();
        $this->asRole('provider2')->getJson('/api/v1/cases/REQ-000113')->assertNotFound();
        $this->getJson('/api/v1/cases/REQ-000113/documents/DOC-MED-T/download')->assertNotFound();
    }

    public function test_invited_provider_never_receives_the_medical_record_and_gets_no_case_access(): void
    {
        $this->assign('awaiting_quotation', 'PRV-0005');
        RahaCase::whereKey('REQ-000113')->update(['provider_id' => null, 'provider' => null, 'provider_accepted' => false, 'status' => 'awaiting_quotation']);
        $round = QuotationRound::query()->forceCreate(['id' => 'QR-T1', 'case_id' => 'REQ-000113', 'request_number' => 'REQ-000113', 'service' => 'x', 'expected_start' => '2026-12-01', 'created_by' => 'x', 'created_by_id' => 'u3', 'status' => 'open', 'deadline' => now()->addDay(), 'description' => 'خدمة', 'specifications' => 'مواصفات',
            'quantity' => 1, 'unit' => 'زيارة', 'city' => 'الرياض', 'history' => [], 'shared_attachments' => []]);
        QuotationInvitation::query()->forceCreate(['id' => 'INV-T1', 'quotation_round_id' => $round->id, 'provider_id' => 'PRV-0005', 'provider_name' => 'x', 'status' => 'invited', 'sent_at' => now()]);

        $this->asRole('provider')->getJson('/api/v1/cases/REQ-000113')->assertNotFound();
        $body = $this->getJson('/api/v1/provider/invitations/INV-T1')->assertOk()->getContent();
        foreach (['N18.5', 'قصور كلوي', 'مستشفى سري', 'تعليق اللجنة', 'diagnosis', '"medical"'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        // An expired invitation still exposes nothing and cannot open the case.
        QuotationRound::query()->whereKey('QR-T1')->update(['deadline' => now()->subSecond()]);
        $expired = $this->getJson('/api/v1/provider/invitations/INV-T1')->assertOk();
        $expired->assertJsonPath('data.view', 'expired');
        $this->assertStringNotContainsString('N18.5', $expired->getContent());
        $this->getJson('/api/v1/cases/REQ-000113')->assertNotFound();
    }
}
