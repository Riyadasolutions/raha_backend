<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\Role;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\RahaCase;

/** Port of front role_navigation.test.ts + negative permission / ownership / privacy cases. */
class AccessPrivacyTest extends CasesTestCase
{
    private function grant(string $roleId, array $changes): void
    {
        $role = Role::find($roleId);
        $role->permissions = array_merge($role->permissions ?? [], $changes);
        $role->save();
        app(PermissionResolver::class)->flush();
    }

    public function test_reviewer_reads_review_cases_but_not_medical_by_identifier(): void
    {
        $ids = collect($this->asRole('auditor')->getJson('/api/v1/cases')->assertOk()->json('data'))->pluck('id');
        $this->assertContains('REQ-000123', $ids);
        $this->assertNotContains('REQ-000122', $ids);
        $this->getJson('/api/v1/cases/REQ-000122')->assertNotFound();
        $before = RahaCase::find('REQ-000122')->toArray();
        $this->postJson('/api/v1/cases/REQ-000122/actions/medical_approve', self::MEDICAL)->assertForbidden();
        $this->assertEquals($before, RahaCase::find('REQ-000122')->toArray());
    }

    public function test_staff_detail_omits_tabs_without_grants(): void
    {
        $data = $this->asRole('auditor')->getJson('/api/v1/cases/REQ-000123')->assertOk()->json('data');
        $this->assertArrayNotHasKey('medical', $data);
        $this->assertArrayNotHasKey('social', $data);
        $this->assertArrayHasKey('activities', $data);
        $this->assertArrayHasKey('tasks', $data);

        // Team member: ASSIGNED scope, no documents tab, no notes tab.
        $team = $this->asRole('team')->getJson('/api/v1/cases/REQ-000119')->assertOk()->json('data');
        $this->assertArrayNotHasKey('documents', $team);
        $this->assertArrayNotHasKey('comments', $team);
        $this->asRole('team')->getJson('/api/v1/cases/REQ-000123')->assertNotFound();
    }

    public function test_provider_sees_only_own_cases_and_never_verification_code_or_internal_data(): void
    {
        RahaCase::whereKey('REQ-000113')->update(['provider_id' => 'PRV-0005', 'provider' => 'مركز عناية للرعاية المنزلية', 'provider_accepted' => true]);
        $list = $this->asRole('provider')->getJson('/api/v1/cases')->assertOk()->json('data');
        $this->assertSame(['REQ-000113'], array_column($list, 'id'));
        $data = $this->getJson('/api/v1/cases/REQ-000113')->assertOk()->json('data');
        foreach (['verificationCode', 'social', 'comments', 'activities', 'tasks', 'reviews', 'fastTrack', 'finance', 'returnInfo', 'recipient'] as $k) {
            $this->assertArrayNotHasKey($k, $data, $k);
        }
        $this->assertSame('482913', RahaCase::find('REQ-000113')->verification_code);
        $this->assertStringNotContainsString('482913', $this->getJson('/api/v1/cases')->getContent());
        $this->getJson('/api/v1/cases/REQ-000113/timeline')->assertOk();
        // Another provider cannot open it, act on it or list it.
        $this->asRole('provider2')->getJson('/api/v1/cases/REQ-000113')->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/cases')->json('data'));
        $this->postJson('/api/v1/cases/REQ-000113/actions/start_service')->assertForbidden();
        // Providers cannot reach staff-only task routes or the portal.
        $this->getJson('/api/v1/tasks')->assertForbidden();
        $this->getJson('/api/v1/portal/requests')->assertForbidden();
    }

    public function test_provider_cannot_review_even_with_staff_permissions(): void
    {
        $this->grant('provider', ['cases.review' => 'ALL', 'requests.claim' => 'ALL']);
        $this->asRole('provider')->postJson('/api/v1/cases/REQ-000123/task/claim')->assertForbidden();
        $this->postJson('/api/v1/cases/REQ-000123/actions/audit_approve', ['confirmed' => true])->assertForbidden();
    }

    public function test_beneficiary_sees_only_own_requests_without_internal_data(): void
    {
        RahaCase::find('REQ-000116')->comments()->create(['id' => 'c-int', 'at' => now(), 'text' => 'ملاحظة داخلية سرية', 'author' => 'x', 'visibility' => 'internal']);
        $list = $this->asRole('beneficiary')->getJson('/api/v1/portal/requests')->assertOk()->json('data');
        $this->assertCount(8, $list);
        $this->assertTrue(collect($list)->every(fn ($c) => $c['beneficiaryId'] === 'BEN-0001'));
        $this->getJson('/api/v1/portal/requests/REQ-000123')->assertNotFound();
        $data = $this->getJson('/api/v1/portal/requests/REQ-000116')->assertOk()->json('data');
        foreach (['tasks', 'reviews', 'finance', 'fastTrack', 'assignee', 'stage', 'invoice', 'visitPlan', 'quotationRequired', 'preferredQuotation'] as $k) {
            $this->assertArrayNotHasKey($k, $data, $k);
        }
        $this->assertStringNotContainsString('سرية', json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertTrue(collect($data['activities'])->every(fn ($a) => $a['visibility'] !== 'internal'));
        $this->assertSame('under_review', $data['beneficiaryStatus']);
        $this->assertSame(1, $data['progressIndex']);
        // Beneficiaries cannot use staff endpoints or act on requests.
        $this->getJson('/api/v1/cases/REQ-000116')->assertForbidden();
        $this->postJson('/api/v1/cases/REQ-000117/actions/audit_approve', ['confirmed' => true])->assertForbidden();
        $this->postJson('/api/v1/portal/requests/REQ-000123/survey', ['ease' => 5, 'clarity' => 5])->assertNotFound();
        $this->postJson('/api/v1/portal/requests/REQ-000123/confirm-service')->assertNotFound();
    }

    public function test_multiple_roles_combine_stages_without_social_access(): void
    {
        \App\Models\User::find('u2')->roles()->attach('medical', ['position' => 1]);
        $ids = collect($this->asRole('auditor')->getJson('/api/v1/cases')->json('data'))->pluck('id');
        $this->assertContains('REQ-000123', $ids);
        $this->assertContains('REQ-000122', $ids);
        $this->getJson('/api/v1/cases/REQ-000121')->assertNotFound();
    }

    public function test_assigned_scope_cannot_claim_unrelated_tasks_and_inactive_roles_deny(): void
    {
        RahaCase::query()->update(['assignee' => 'queue']);
        $this->grant('auditor', ['cases.view' => 'ASSIGNED']);
        $this->asRole('auditor')->getJson('/api/v1/tasks?view=queue')->assertOk()->assertExactJson(['data' => []]);
        $this->postJson('/api/v1/cases/REQ-000123/task/claim')->assertForbidden();
        Role::whereKey('auditor')->update(['status' => 'inactive']);
        app(PermissionResolver::class)->flush();
        $this->asRole('auditor')->getJson('/api/v1/cases')->assertExactJson(['data' => []]);
    }

    public function test_task_release_and_reassign_rules(): void
    {
        $this->claim('auditor', 'REQ-000123')->assertOk();
        $this->asRole('medical')->postJson('/api/v1/cases/REQ-000123/task/release')->assertForbidden();
        $this->asRole('auditor')->postJson('/api/v1/cases/REQ-000123/task/release')->assertOk()->assertJsonMissingPath('data.activeTask.assignedUserId');
        $this->asRole('manager')->postJson('/api/v1/cases/REQ-000123/task/reassign', ['userId' => 'u3'])->assertStatus(422)->assertJsonPath('message', 'اختر موظفًا مؤهلًا لهذه المرحلة.');
        $this->postJson('/api/v1/cases/REQ-000123/task/reassign', ['userId' => 'u2'])->assertOk();
        $this->assertSame('u2', RahaCase::find('REQ-000123')->activeTask()->assigned_user_id);
        $this->assertDatabaseHas('notifications', ['title' => 'تمت إعادة إسناد المهمة', 'user_id' => 'u2', 'body' => 'REQ-000123']);
        $users = $this->getJson('/api/v1/cases/REQ-000123/task/eligible-users')->assertOk()->json('data');
        $this->assertContains('u2', array_column($users, 'id'));
        $this->assertNotContains('u3', array_column($users, 'id'));
        $this->claim('social', 'REQ-000117')->assertForbidden();
    }

    public function test_tasks_views_and_notifications_are_personal(): void
    {
        $queue = collect($this->asRole('auditor')->getJson('/api/v1/tasks?view=queue')->assertOk()->json('data'));
        $this->assertTrue($queue->every(fn ($c) => $c['status'] === 'new'));
        $this->claim('auditor', 'REQ-000123')->assertOk();
        $this->assertDatabaseHas('notifications', ['title' => 'تم استلام المهمة', 'user_id' => 'u2', 'body' => 'REQ-000123']);
        $mine = collect($this->getJson('/api/v1/tasks?view=my')->json('data'));
        $row = $mine->firstWhere('id', 'REQ-000123');
        $this->assertSame('in_progress', $row['activeTask']['viewStatus']);
        $this->assertFalse($row['activeTask']['overdue']);
        $this->assertSame(['required' => 1, 'complete' => 0, 'missing' => 1], $row['requirementSummary']);
        $this->assertNotContains('REQ-000123', collect($this->getJson('/api/v1/tasks?view=queue')->json('data'))->pluck('id'));
        $relations = collect($this->asRole('relations')->getJson('/api/v1/tasks')->json('data'));
        $this->assertTrue($relations->every(fn ($c) => ! in_array($c['status'], ['new', 'medical_review', 'social_review'], true)));
    }

    public function test_overdue_is_derived_from_due_at(): void
    {
        $this->claim('auditor', 'REQ-000123')->assertOk();
        RahaCase::find('REQ-000123')->activeTask()->forceFill(['due_at' => now()->subDay()])->save();
        $row = collect($this->asRole('auditor')->getJson('/api/v1/tasks?view=my')->json('data'))->firstWhere('id', 'REQ-000123');
        $this->assertSame('overdue', $row['activeTask']['viewStatus']);
        $this->assertSame('in_progress', RahaCase::find('REQ-000123')->activeTask()->status);
    }

    public function test_stage_statistics_count_only_accessible_cases(): void
    {
        $data = $this->asRole('auditor')->getJson('/api/v1/cases/stage-statistics')->assertOk()->json('data');
        $medical = collect($data['counts'])->firstWhere('key', 'medical');
        $this->assertSame(['key' => 'medical', 'label' => 'اللجنة الطبية', 'count' => 0, 'canOpen' => false], $medical);
        $new = collect($data['counts'])->firstWhere('key', 'new');
        $this->assertSame(2, $new['count']);
        $this->assertTrue($new['canOpen']);
        $this->assertSame(['key' => 'in-service', 'count' => 0, 'canOpen' => false], collect($data['summaries'])->firstWhere('key', 'in-service'));
        $manager = $this->asRole('admin')->getJson('/api/v1/cases/stage-statistics')->json('data');
        $this->assertSame(15, $manager['totals']['total']);
        $this->asRole('provider')->getJson('/api/v1/cases/stage-statistics')->assertOk()->assertJsonPath('data.counts', []);
    }

    public function test_comments_and_timeline_permissions(): void
    {
        $this->asRole('auditor')->postJson('/api/v1/cases/REQ-000123/comments', ['text' => '  ', 'visibility' => 'internal'])->assertStatus(422);
        $this->postJson('/api/v1/cases/REQ-000123/comments', ['text' => 'ملاحظة', 'visibility' => 'internal'])->assertCreated();
        $this->postJson('/api/v1/cases/REQ-000123/comments', ['text' => 'للمستفيد', 'visibility' => 'beneficiary'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['beneficiary_id' => 'BEN-00438', 'title' => 'أضيفت ملاحظة للمستفيد.']);
        $this->asRole('finance')->postJson('/api/v1/cases/REQ-000110/comments', ['text' => 'x', 'visibility' => 'beneficiary'])->assertForbidden();
        $this->asRole('auditor')->getJson('/api/v1/cases/REQ-000123/timeline')->assertOk()->assertJsonFragment(['text' => 'أضيفت ملاحظة داخلية.']);
    }

    public function test_actions_endpoint_lists_metadata_for_task_owner_only(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/cases/REQ-000123/actions')->assertOk()->assertExactJson(['data' => []]);
        $this->claim('auditor', 'REQ-000123');
        $actions = $this->getJson('/api/v1/cases/REQ-000123/actions')->assertOk()->json('data');
        $this->assertSame(['audit_approve', 'audit_return', 'audit_reject'], array_column($actions, 'key'));
        $this->assertSame(['key' => 'audit_approve', 'label' => 'اعتماد التدقيق', 'kind' => 'advance', 'form' => 'confirm', 'perm' => 'cases.review'], array_intersect_key($actions[0], array_flip(['key', 'label', 'kind', 'form', 'perm'])));
    }
}
