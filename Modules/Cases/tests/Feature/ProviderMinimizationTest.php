<?php

namespace Modules\Cases\Tests\Feature;

use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\RahaCase;

/** Provider data minimization enforced in the API payload itself (not only hidden by the frontend). */
class ProviderMinimizationTest extends CasesTestCase
{
    private function assignTo(): void
    {
        RahaCase::whereKey('REQ-000113')->update(['provider_id' => 'PRV-0005', 'provider' => 'مركز عناية للرعاية المنزلية', 'provider_accepted' => true]);
        CaseActivity::query()->where('case_id', 'REQ-000113')->update(['actor_id' => 'u3', 'action' => 'medical_approve', 'from_stage' => 'medical_review', 'to_stage' => 'social_review', 'reason' => 'internal reason']);
    }

    public function test_provider_case_payloads_never_contain_the_national_id(): void
    {
        $this->assignTo();
        RahaCase::whereKey('REQ-000113')->update(['national_id' => '1066612345']);
        $nid = RahaCase::find('REQ-000113')->national_id;
        $this->assertNotEmpty($nid);
        $detail = $this->asRole('provider')->getJson('/api/v1/cases/REQ-000113')->assertOk();
        $this->assertArrayNotHasKey('nationalId', $detail->json('data'));
        $this->assertStringNotContainsString($nid, $detail->getContent());
        $this->assertStringNotContainsString($nid, $this->getJson('/api/v1/cases')->assertOk()->getContent());
        // Staff are unaffected (masking for staff is a separate rule).
        $this->asRole('admin')->getJson('/api/v1/cases/REQ-000113')->assertOk()->assertJsonPath('data.nationalId', $nid);
    }

    public function test_provider_timeline_is_a_whitelist_without_actor_ids_or_stage_transitions(): void
    {
        $this->assignTo();
        $rows = $this->asRole('provider')->getJson('/api/v1/cases/REQ-000113/timeline')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEmpty(array_diff(array_keys($row), ['id', 'at', 'text', 'actor', 'visibility']), json_encode($row));
        }
        $this->assertStringNotContainsString('internal reason', json_encode($rows));
        // Staff still get the full activity rows.
        $staff = $this->asRole('admin')->getJson('/api/v1/cases/REQ-000113/timeline')->assertOk()->json('data');
        $this->assertArrayHasKey('actorId', $staff[0] ?? []);
    }
    public function test_an_unrelated_provider_cannot_tell_whether_a_case_exists_or_what_state_it_is_in(): void
    {
        $this->assignTo(); // REQ-000113 is assigned to PRV-0005
        RahaCase::whereKey('REQ-000113')->update(['status' => 'assigned', 'provider_accepted' => false]);
        // provider_accept is valid from "assigned": a foreign provider hitting the matching state used to get 403 while a missing id got 422.
        $foreign = $this->asRole('provider2')->postJson('/api/v1/cases/REQ-000113/actions/provider_accept');
        $missing = $this->postJson('/api/v1/cases/REQ-999999/actions/provider_accept');
        $this->assertSame($missing->status(), $foreign->status());
        $this->assertSame($missing->json('message'), $foreign->json('message'));
        $this->assertGreaterThanOrEqual(400, $foreign->status());
        // The assigned provider is unaffected.
        $this->asRole('provider')->postJson('/api/v1/cases/REQ-000113/actions/provider_accept')->assertOk();
    }
}
