<?php

namespace Modules\Cases\Tests\Feature;

use Modules\Cases\Models\CaseReview;
use Modules\Cases\Models\RahaCase;

/**
 * Contract regressions found by type-checking real API responses against the frontend types (Phase 4):
 * a map the frontend types as Record<string,string> must serialize as a JSON object even when empty (never `[]`),
 * and the signed-in user payload must not contain nulls for optional fields.
 */
class ContractShapeTest extends CasesTestCase
{
    public function test_empty_review_fields_serialize_as_an_object(): void
    {
        $id = $this->submitAsBeneficiary();
        $this->claim('auditor', $id)->assertOk();
        $this->act('auditor', $id, 'audit_approve', ['confirmed' => true])->assertOk();
        $this->assertSame([], CaseReview::query()->where('case_id', $id)->first()->fields ?? []);

        $raw = $this->asRole('admin')->getJson("/api/v1/cases/$id")->assertOk()->getContent();
        $this->assertStringNotContainsString('"fields":[]', $raw);
        $this->assertStringContainsString('"fields":{}', $raw);
        $reviews = $this->getJson("/api/v1/cases/$id")->json('data.reviews');
        $this->assertNotEmpty($reviews);
    }

    public function test_empty_medical_and_social_maps_serialize_as_objects(): void
    {
        RahaCase::whereKey('REQ-000123')->update(['medical' => json_encode([]), 'social' => json_encode([])]);
        $raw = $this->asRole('admin')->getJson('/api/v1/cases/REQ-000123')->assertOk()->getContent();
        $this->assertStringNotContainsString('"medical":[]', $raw);
        $this->assertStringNotContainsString('"social":[]', $raw);
    }

    public function test_signed_in_user_payload_has_no_null_values(): void
    {
        foreach (['admin', 'provider', 'beneficiary'] as $role) {
            $me = $this->asRole($role)->getJson('/api/v1/auth/me')->assertOk()->json('user');
            $nulls = array_keys(array_filter($me, fn ($v) => $v === null));
            $this->assertSame([], $nulls, "$role user payload has null keys");
        }
    }
}
