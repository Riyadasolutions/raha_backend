<?php

namespace Modules\Cases\Tests\Feature;

use Modules\Cases\Models\CaseReview;

/** Client-approved change: provider and price in the medical review are optional advisory fields (Finance still decides). */
class MedicalReviewOptionalFieldsTest extends CasesTestCase
{
    private function atMedicalReview(): string
    {
        $id = $this->submitAsBeneficiary();
        $this->claim('auditor', $id)->assertOk();
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertOk();
        $this->claim('medical', $id)->assertOk();

        return $id;
    }

    private function medicalFields(string $id): array
    {
        return CaseReview::query()->where('case_id', $id)->get()->first(fn ($r) => isset($r->fields['opinion']))->fields;
    }

    public function test_review_without_provider_and_price_is_accepted(): void
    {
        $id = $this->atMedicalReview();
        $this->act('medical', $id, 'medical_approve', self::MEDICAL)->assertOk()->assertJsonPath('data.status', 'social_review');
        $f = $this->medicalFields($id);
        $this->assertArrayNotHasKey('providerId', $f);
        $this->assertArrayNotHasKey('price', $f);
    }

    public function test_review_with_provider_and_price_still_saves_them(): void
    {
        $id = $this->atMedicalReview();
        $this->act('medical', $id, 'medical_approve', ['fields' => self::MEDICAL['fields'] + ['providerId' => 'PRV-0001', 'providerName' => 'مزود', 'price' => '1200']])
            ->assertOk()->assertJsonPath('data.status', 'social_review');
        $f = $this->medicalFields($id);
        $this->assertSame('PRV-0001', $f['providerId']);
        $this->assertSame('1200', $f['price']);
    }

    public function test_other_required_medical_fields_are_still_required(): void
    {
        $id = $this->atMedicalReview();
        $this->act('medical', $id, 'medical_approve', ['fields' => ['opinion' => 'x', 'providerId' => 'PRV-0001', 'price' => '10']])
            ->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال التوصية الطبية.');
    }
}
