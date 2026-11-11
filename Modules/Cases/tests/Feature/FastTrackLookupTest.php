<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\RahaCase;

/** Port of front fast_track.test.ts and request_status_lookup.test.tsx. */
class FastTrackLookupTest extends CasesTestCase
{
    public function test_fast_track_fails_safe_when_disabled(): void
    {
        $this->asRole('beneficiary')->getJson('/api/v1/portal/fast-track/eligibility')->assertOk()
            ->assertJsonPath('data.eligible', false)->assertJsonPath('data.reason', 'المسار السريع غير مفعّل أو إعداداته غير مكتملة؛ سيُعامل الطلب بالمسار المعتاد.');
        $id = $this->submitAsBeneficiary();
        $this->assertSame('new', RahaCase::find($id)->status);
        $this->assertNull(RahaCase::find($id)->fast_track);
    }

    public function test_default_seed_policy_is_used_when_no_setting_is_stored(): void
    {
        Setting::query()->whereKey('fast_track')->delete();
        $this->asRole('beneficiary')->getJson('/api/v1/portal/fast-track/eligibility')->assertJsonPath('data.eligible', true)->assertJsonPath('data.entryStage', 'medical_review');
    }

    public function test_eligible_repeat_request_enters_configured_stage_without_touching_previous(): void
    {
        $this->fastTrack(true, 'manager_approval');
        $before = RahaCase::find('REQ-000111')->toArray();
        $this->asRole('beneficiary')->getJson('/api/v1/portal/fast-track/eligibility')->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.skippedStages', ['التدقيق', 'اللجنة الطبية', 'البحث الاجتماعي'])->assertJsonMissingPath('data.previous');
        $id = $this->submitAsBeneficiary();
        $c = RahaCase::find($id);
        $this->assertSame('manager_approval', $c->status);
        $this->assertSame(['التدقيق', 'اللجنة الطبية', 'البحث الاجتماعي'], $c->fast_track['skippedStages']);
        $this->assertNotEmpty($c->fast_track['previousRequestId']);
        $this->assertDatabaseHas('case_activities', ['case_id' => $id, 'action' => 'fast_track', 'to_stage' => 'manager_approval']);
        $this->assertSame('manager_approval', $c->activeTask()->stage);
        $this->assertEquals($before, RahaCase::find('REQ-000111')->toArray());
        $this->asRole('beneficiary')->getJson("/api/v1/portal/requests/$id")->assertJsonMissingPath('data.fastTrack');
    }

    public function test_medical_entry_skips_only_audit(): void
    {
        $this->fastTrack(true, 'medical_review');
        $id = $this->submitAsBeneficiary();
        $this->assertSame('medical_review', RahaCase::find($id)->status);
        $this->assertSame(['التدقيق'], RahaCase::find($id)->fast_track['skippedStages']);
        $this->asRole('relations')->getJson("/api/v1/requests/status-lookup?number=$id")->assertJsonPath('data.summary.stageLabel', 'اللجنة الطبية');
    }

    public function test_beneficiary_without_approved_previous_is_not_eligible(): void
    {
        $this->fastTrack(true, 'medical_review');
        RahaCase::where('beneficiary_id', 'BEN-0001')->whereIn('status', ['preliminary_approved', 'assigned', 'scheduled', 'in_progress', 'completed', 'finance_review', 'archived'])->update(['status' => 'rejected']);
        $this->asRole('beneficiary')->getJson('/api/v1/portal/fast-track/eligibility')->assertJsonPath('data.eligible', false);
        $this->assertSame('new', RahaCase::find($this->submitAsBeneficiary())->status);
    }

    public function test_lookup_normalizes_and_matches_exactly(): void
    {
        $this->asRole('auditor');
        foreach (['REQ-000122', 'req-000122', '  REQ-000122  ', "\u{200F}req-000122", 'REQ-٠٠٠١٢٢'] as $n) {
            $this->getJson('/api/v1/requests/status-lookup?number='.urlencode($n))->assertOk()->assertJsonPath('data.summary.requestNumber', 'REQ-000122');
        }
        $this->getJson('/api/v1/requests/status-lookup?number=req-demo-0001')->assertJsonPath('data.summary.requestNumber', 'REQ-DEMO-0001')->assertJsonPath('data.summary.stageLabel', 'بانتظار عروض الأسعار');
        $this->getJson('/api/v1/requests/status-lookup?number=')->assertJsonPath('data.outcome', 'empty');
        foreach (['000700', 'REQ 000700', 'REQ-', 'أحمد محمد', '1048213482'] as $bad) {
            $this->getJson('/api/v1/requests/status-lookup?number='.urlencode($bad))->assertJsonPath('data.outcome', 'invalid');
        }
        $this->getJson('/api/v1/requests/status-lookup?number=REQ-999999')->assertJsonPath('data.outcome', 'not_found');
        $this->getJson('/api/v1/requests/status-lookup?number=REQ-00012')->assertJsonPath('data.outcome', 'not_found');
    }

    public function test_lookup_permission_and_detail_link(): void
    {
        $this->asRole('medical')->getJson('/api/v1/requests/status-lookup?number=REQ-000122')->assertForbidden();
        $this->asRole('provider')->getJson('/api/v1/requests/status-lookup?number=REQ-000122')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/requests/status-lookup?number=REQ-000122')->assertForbidden();
        $this->asRole('manager')->getJson('/api/v1/requests/status-lookup?number=REQ-000120')->assertJsonPath('data.summary.canOpenDetail', true);
        $this->getJson('/api/v1/requests/status-lookup?number=REQ-000122')->assertJsonPath('data.summary.canOpenDetail', false);
        $this->asRole('admin')->getJson('/api/v1/requests/status-lookup?number=REQ-000122')->assertJsonPath('data.summary.canOpenDetail', true);
        $role = Role::find('auditor');
        $role->permissions = array_diff_key($role->permissions, ['requests.statusLookup' => 1]);
        $role->save();
        app(PermissionResolver::class)->flush();
        $this->asRole('auditor')->getJson('/api/v1/requests/status-lookup?number=REQ-000122')->assertForbidden();
    }

    public function test_lookup_maps_states_and_writes_nothing(): void
    {
        RahaCase::whereKey('REQ-000114')->update(['status' => 'preliminary_approved', 'finance' => json_encode(['approvalId' => 'F1', 'stage' => 'pending_review', 'configVersion' => 1])]);
        RahaCase::whereKey('REQ-000119')->update(['provider_id' => 'PRV-0005', 'provider' => 'مركز عناية']);
        $this->asRole('relations');
        $snapshot = fn () => [RahaCase::orderBy('id')->get()->toArray(), AuditLog::count(), Notification::count()];
        $before = $snapshot();
        $found = fn ($n) => $this->getJson("/api/v1/requests/status-lookup?number=$n")->assertOk()->json('data.summary');
        $this->assertEquals(['stageLabel' => 'اللجنة الطبية', 'category' => 'active', 'statusLabel' => 'قيد المعالجة'], array_intersect_key($found('REQ-000122'), array_flip(['stageLabel', 'category', 'statusLabel'])));
        $this->assertSame('الاعتماد المبدئي', $found('REQ-000120')['stageLabel']);
        $this->assertSame('بانتظار المراجعة المالية', $found('REQ-000114')['stageLabel']);
        $s = $found('REQ-000119');
        $this->assertSame(['تقديم الخدمة', 'in_service', 'الخدمة جارية'], [$s['stageLabel'], $s['category'], $s['statusLabel']]);
        $this->assertStringNotContainsString('عناية', json_encode($s, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['rejected', 'مرفوض'], [$found('REQ-000110')['category'], $found('REQ-000110')['statusLabel']]);
        $this->assertSame(['returned', 'معاد للمستفيد للتصحيح'], [$found('REQ-000115')['category'], $found('REQ-000115')['statusLabel']]);
        $this->assertSame(['completed', 'مكتمل'], [$found('REQ-000112')['category'], $found('REQ-000112')['stageLabel']]);
        $this->assertSame(['archived', 'مؤرشف'], [$found('REQ-000118')['category'], $found('REQ-000118')['stageLabel']]);
        $this->assertSame(['requestNumber', 'statusLabel', 'category', 'stageLabel', 'submittedAt', 'lastUpdatedAt', 'canOpenDetail'], array_keys($s));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $s['submittedAt']);
        $this->assertEquals($before, $snapshot());
    }
}
