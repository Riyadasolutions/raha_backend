<?php

namespace Modules\Visits\Tests\Feature;

/** Providers must not receive the identity of RAHA staff or the internal reason behind a staff correction of their visit. */
class VisitProviderPrivacyTest extends VisitsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->visitCase();
    }

    private function correctedVisit(): string
    {
        $v = $this->scheduled('provider', self::ID, -self::DAY);
        $this->postJson("/api/v1/visits/$v/complete", ['completedAt' => now()->subHours(2)->utc()->format('Y-m-d\TH:i:s.v\Z'), 'notes' => 'أصلي'])->assertOk();
        $this->asRole('manager')->postJson("/api/v1/visits/$v/correct", ['status' => 'missed', 'reason' => 'ملاحظة داخلية حساسة'])->assertOk();

        return $v;
    }

    public function test_provider_sees_the_correction_but_not_the_staff_identity_or_internal_reason(): void
    {
        $v = $this->correctedVisit();
        $staff = $this->asRole('manager')->getJson('/api/v1/cases/'.self::ID.'/visits')->assertOk()->json('data.0');
        $this->assertStringContainsString('ملاحظة داخلية حساسة', json_encode($staff, JSON_UNESCAPED_UNICODE), 'staff keep the full history');

        foreach (['/api/v1/cases/'.self::ID.'/visits', '/api/v1/provider/visits'] as $url) {
            $body = $this->asRole('provider')->getJson($url)->assertOk()->json('data');
            $visit = collect($body)->firstWhere('id', $v);
            $this->assertNotNull($visit, $url);
            $raw = json_encode($visit, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('ملاحظة داخلية حساسة', $raw, "$url leaks the internal correction reason");
            $manager = \App\Models\User::query()->find('u5');
            $this->assertStringNotContainsString($manager->name, $raw, "$url leaks the staff member's name");
            $this->assertCount(1, $visit['corrections'], 'the provider still sees that a correction happened');
            $this->assertSame('missed', $visit['status']);
            $this->assertSame('completed', $visit['corrections'][0]['previous']['status'], 'its own previous values stay visible');
        }
    }

    public function test_provider_own_actions_stay_in_its_audit_trail(): void
    {
        $v = $this->correctedVisit();
        $visit = collect($this->asRole('provider')->getJson('/api/v1/provider/visits')->json('data'))->firstWhere('id', $v);
        $actions = array_column($visit['audit'], 'action');
        $this->assertSame(['scheduled', 'completed', 'corrected'], $actions);
        $this->assertStringContainsString('مركز', $visit['audit'][0]['by'].json_encode($visit), 'the provider side keeps its own stamp');
    }
}
