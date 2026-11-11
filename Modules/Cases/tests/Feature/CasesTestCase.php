<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Shared helpers for the Cases feature tests. */
abstract class CasesTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->fastTrack(false);
    }

    protected function fastTrack(bool $enabled, string $entry = 'medical_review'): void
    {
        Setting::write('fast_track', ['enabled' => $enabled, 'entryStage' => $enabled ? $entry : '', 'requireApprovedPrevious' => true, 'configVersion' => 2]);
    }

    /** Uploads a fake PDF as the currently authenticated user and returns the upload id. */
    protected function upload(string $name = 'report.pdf'): string
    {
        return $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->create($name, 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
    }

    /** Beneficiary portal submission for SRV-001 (home nursing: medical report required). */
    protected function submitAsBeneficiary(array $overrides = []): string
    {
        $this->asRole('beneficiary');
        $uploadId = $this->upload();

        return $this->postJson('/api/v1/portal/requests', array_merge([
            'serviceId' => 'SRV-001', 'description' => 'حاجة لتمريض منزلي', 'previouslyReceived' => 'لا', 'medical' => ['diagnosis' => 'تشخيص'],
            'requestFor' => 'self', 'patient' => ['fullName' => 'أحمد محمد السبيعي', 'idType' => 'هوية وطنية', 'idNumber' => '1087654321', 'mobile' => '0551234567'],
            'social' => ['city' => 'الرياض', 'district' => 'الياسمين', 'housingType' => 'ملك', 'employmentStatus' => 'عاطل', 'familyMembers' => '4', 'otherIncome' => 'لا', 'hasDisability' => 'لا', 'socialSecurity' => 'لا'],
            'documents' => [['uploadId' => $uploadId, 'docType' => 'medical_report', 'reportDate' => now()->toDateString()]],
        ], $overrides))->assertCreated()->json('data.id');
    }

    protected function act(string $role, string $caseId, string $key, array $payload = [])
    {
        return $this->asRole($role)->postJson("/api/v1/cases/$caseId/actions/$key", $payload);
    }

    protected function claim(string $role, string $caseId)
    {
        return $this->asRole($role)->postJson("/api/v1/cases/$caseId/task/claim");
    }

    protected const MEDICAL = ['fields' => ['opinion' => 'مناسب', 'recommendation' => 'تقديم الخدمة', 'decision' => 'مناسب طبيًا']];

    protected const SOCIAL = ['fields' => ['summary' => 'ملخص', 'assessment' => 'دراسة', 'recommendation' => 'تحمل الجمعية للتكلفة', 'justification' => 'الاحتياج']];
}
