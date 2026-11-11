<?php

namespace Modules\Access\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Security\SecurityPolicy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessTest extends TestCase
{
    private function draft(array $over = []): array
    {
        return array_merge([
            'nameAr' => 'مشرف التدقيق', 'code' => 'AUDIT_SUPERVISOR', 'description' => 'دور تجريبي', 'status' => 'active',
            'permissions' => ['cases.view' => 'ALL', 'reports.view' => 'ALL'], 'workflowRole' => null,
        ], $over);
    }

    /** A user (u7, team) holding a custom role with role-management rights but only a limited permission set. */
    private function makeLimitedRoleManager(): void
    {
        DB::table('roles')->insert([
            'id' => 'r-mgr', 'code' => 'ROLE_MANAGER', 'name_ar' => 'مدير الأدوار المحدود', 'name_en' => 'ROLE_MANAGER', 'type' => 'CUSTOM', 'status' => 'active',
            'permissions' => json_encode(['roles.view' => 'ALL', 'roles.create' => 'ALL', 'roles.edit' => 'ALL', 'roles.managePermissions' => 'ALL', 'cases.view' => 'ASSIGNED', 'users.view' => 'ALL']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_user')->insert(['user_id' => 'u7', 'role_id' => 'r-mgr', 'position' => 1]);
    }

    public function test_permission_catalog_and_roles_list_with_user_count(): void
    {
        $this->asRole('admin')->getJson('/api/v1/permissions')->assertOk()
            ->assertJsonPath('scopeLabels.ALL', 'الكل')->assertJsonStructure(['data' => [['code', 'nameAr', 'sensitivity']], 'groups', 'sensitivityLabels']);
        $res = $this->getJson('/api/v1/roles')->assertOk();
        $admin = collect($res->json('data'))->firstWhere('id', 'admin');
        $this->assertSame(1, $admin['userCount']);
        $this->assertSame(3, collect($res->json('data'))->firstWhere('id', 'provider')['userCount']);
    }

    public function test_non_admin_cannot_view_or_create_roles(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', $this->draft())->assertForbidden()->assertJsonPath('message', 'ليست لديك صلاحية تنفيذ هذا الإجراء.');
        $this->assertDatabaseMissing('roles', ['code' => 'AUDIT_SUPERVISOR']);
    }

    public function test_provider_and_beneficiary_accounts_are_blocked(): void
    {
        $this->asRole('provider')->getJson('/api/v1/roles')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_admin_creates_role_with_logs_and_assignment(): void
    {
        $res = $this->asRole('admin')->postJson('/api/v1/roles', $this->draft(['assignUserIds' => ['u7']]))->assertCreated();
        $id = $res->json('data.id');
        $this->assertStringStartsWith('r-', $id);
        $res->assertJsonPath('data.type', 'CUSTOM')->assertJsonPath('data.nameEn', 'AUDIT_SUPERVISOR')->assertJsonPath('data.userCount', 1);
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_CREATED', 'target' => 'مشرف التدقيق', 'by' => User::find('u1')->name]);
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_ASSIGNED', 'user_id' => 'u7', 'target' => 'مشرف التدقيق']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم إنشاء دور', 'record_type' => 'أمان']);
        $this->assertContains($id, User::find('u7')->roleIds());
    }

    public function test_role_validation_messages(): void
    {
        $this->asRole('admin')->postJson('/api/v1/roles', $this->draft(['nameAr' => 'اب']))->assertStatus(422)->assertJsonPath('message', 'اسم الدور مطلوب (3 أحرف على الأقل).');
        $this->postJson('/api/v1/roles', $this->draft(['code' => 'bad code']))->assertStatus(422)
            ->assertJsonPath('message', 'رمز الدور يجب أن يكون بأحرف إنجليزية كبيرة وشرطة سفلية، مثل AUDIT_SUPERVISOR.');
        $this->postJson('/api/v1/roles', $this->draft(['code' => 'AUDITOR']))->assertStatus(422)->assertJsonPath('message', 'يوجد دور بنفس الاسم أو الرمز.');
        $this->postJson('/api/v1/roles', $this->draft(['permissions' => ['users.create' => 'ALL']]))->assertStatus(422)
            ->assertJsonPath('missingDependencies.0.needs', 'users.view');
        $this->postJson('/api/v1/roles', $this->draft(['permissions' => ['nope.code' => 'ALL']]))->assertStatus(422);
    }

    public function test_cannot_grant_permissions_or_scopes_not_held(): void
    {
        $this->makeLimitedRoleManager();
        $this->asRole('team');
        // reports.view is not held at all.
        $this->postJson('/api/v1/roles', $this->draft(['permissions' => ['reports.view' => 'ALL']]))->assertForbidden()
            ->assertJsonPath('message', 'لا تملك صلاحية منح هذا الإذن. (عرض التقارير)');
        // cases.view is held only at ASSIGNED scope: ALL is an escalation, ASSIGNED is fine.
        $this->postJson('/api/v1/roles', $this->draft(['permissions' => ['cases.view' => 'ALL']]))->assertForbidden();
        $this->postJson('/api/v1/roles', $this->draft(['permissions' => ['cases.view' => 'ASSIGNED']]))->assertCreated();
    }

    public function test_editing_permissions_requires_manage_permissions(): void
    {
        DB::table('roles')->insert([
            'id' => 'r-ed', 'code' => 'ROLE_EDITOR', 'name_ar' => 'محرر الأدوار', 'name_en' => 'ROLE_EDITOR', 'type' => 'CUSTOM', 'status' => 'active',
            'permissions' => json_encode(['roles.view' => 'ALL', 'roles.edit' => 'ALL']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_user')->insert(['user_id' => 'u7', 'role_id' => 'r-ed', 'position' => 1]);
        $role = Role::find('team')->toFront();
        $this->asRole('team')->putJson('/api/v1/roles/team', ['description' => 'وصف جديد'] + $role)->assertOk()->assertJsonPath('data.description', 'وصف جديد');
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_UPDATED', 'target' => $role['nameAr']]);
        $perms = (array) $role['permissions'] + ['reports.view' => 'ALL'];
        $this->putJson('/api/v1/roles/team', ['permissions' => $perms] + $role)->assertForbidden();
    }

    public function test_permission_change_is_logged_and_flushes_cache(): void
    {
        $this->asRole('admin')->getJson('/api/v1/roles/auditor')->assertOk()->assertJsonPath('data.userIds.0', 'u2');
        $role = Role::find('auditor')->toFront();
        $perms = (array) $role['permissions'];
        $perms['audit.view'] = 'ALL';
        unset($perms['cases.reject']);
        $this->putJson('/api/v1/roles/auditor', ['permissions' => $perms] + $role)->assertOk();
        $ev = DB::table('security_events')->where('type', 'PERMISSION_CHANGED')->first();
        $details = json_decode($ev->details, true);
        $this->assertSame(['audit.view'], $details['added']);
        $this->assertSame(['cases.reject'], $details['removed']);
        $this->asRole('auditor')->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_last_admin_guard_on_role_status_and_permissions(): void
    {
        $this->asRole('admin')->patchJson('/api/v1/roles/admin/status', ['status' => 'inactive'])->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إزالة صلاحيات مدير النظام من آخر مدير نشط.');
        $this->assertSame('active', Role::find('admin')->status);
        $role = Role::find('admin')->toFront();
        $perms = (array) $role['permissions'];
        unset($perms['roles.managePermissions']);
        $this->putJson('/api/v1/roles/admin', ['permissions' => $perms] + $role)->assertStatus(422);
        $this->patchJson('/api/v1/roles/team/status', ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_delete_role_rules_and_replacement(): void
    {
        $this->asRole('admin')->deleteJson('/api/v1/roles/auditor')->assertStatus(422)->assertJsonPath('message', 'لا يمكن حذف أدوار النظام.');
        $id = $this->postJson('/api/v1/roles', $this->draft(['assignUserIds' => ['u7', 'u8']]))->json('data.id');
        $this->deleteJson("/api/v1/roles/$id")->assertStatus(422)->assertJsonPath('message', 'لا يمكن حذف هذا الدور لأنه مرتبط بـ 2 مستخدمين.');
        $this->deleteJson("/api/v1/roles/$id?replacementId=$id")->assertStatus(422)->assertJsonPath('message', 'اختر دورًا بديلًا صحيحًا.');
        $this->deleteJson("/api/v1/roles/$id?replacementId=auditor")->assertOk()->assertJsonPath('moved', 2);
        $this->assertDatabaseMissing('roles', ['id' => $id]);
        $this->assertContains('auditor', User::find('u7')->roleIds());
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_DELETED', 'target' => 'مشرف التدقيق']);
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_REMOVED', 'user_id' => 'u8']);
        $this->asRole('auditor')->deleteJson('/api/v1/roles/team')->assertForbidden();
    }

    public function test_compare_roles(): void
    {
        $res = $this->asRole('admin')->getJson('/api/v1/roles/compare?a=admin&b=team')->assertOk();
        $row = collect($res->json('data'))->firstWhere('p.code', 'roles.managePermissions');
        $this->assertSame('ALL', $row['a']);
        $this->assertArrayNotHasKey('b', $row);
    }

    public function test_security_policy_save_validation_and_permission(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/security-policy')->assertForbidden();
        $this->putJson('/api/v1/security-policy', ['minimumPasswordLength' => 14])->assertForbidden();

        $this->asRole('admin')->getJson('/api/v1/security-policy')->assertOk()->assertJsonPath('data.minimumPasswordLength', 12);
        $this->putJson('/api/v1/security-policy', ['idleWarningMinutes' => 20])->assertStatus(422)->assertJsonPath('message', 'وقت التحذير يجب أن يكون أقل من مهلة الخمول.');
        $this->putJson('/api/v1/security-policy', ['minimumPasswordLength' => 12])->assertOk()->assertJsonPath('changed', 0);
        $this->putJson('/api/v1/security-policy', ['minimumPasswordLength' => 14, 'mfaPolicy' => 'all'])->assertOk()->assertJsonPath('changed', 2)
            ->assertJsonPath('data.minimumPasswordLength', 14);
        $this->assertSame(14, SecurityPolicy::get()['minimumPasswordLength']);
        $ev = DB::table('security_events')->where('type', 'SECURITY_POLICY_CHANGED')->first();
        $this->assertSame('الحد الأدنى للطول، سياسة MFA', $ev->target);
        $this->assertSame(['minimumPasswordLength' => 12, 'mfaPolicy' => 'optional'], json_decode($ev->details, true)['before']);
    }

    public function test_security_log_filters_and_permission(): void
    {
        $this->postJson('/api/v1/auth/login', ['identity' => 'medical', 'password' => 'wrong'])->assertStatus(401);
        $this->asRole('admin');
        $res = $this->getJson('/api/v1/security-log?type=LOGIN_FAILED&result=FAILURE')->assertOk();
        $this->assertNotEmpty($res->json('data'));
        $this->assertTrue(collect($res->json('data'))->every(fn ($e) => $e['type'] === 'LOGIN_FAILED'));
        $q = $this->getJson('/api/v1/security-log?q='.urlencode(User::find('u3')->name))->json('data');
        $this->assertTrue(collect($q)->every(fn ($e) => ($e['userId'] ?? null) === 'u3' || str_contains(($e['by'] ?? '').($e['target'] ?? ''), User::find('u3')->name)));
        $this->assertEmpty($this->getJson('/api/v1/security-log?from=2000-01-01&to=2000-01-02')->json('data'));
        $this->asRole('manager')->getJson('/api/v1/security-log')->assertForbidden();
    }

    public function test_sessions_list_and_terminate(): void
    {
        $this->asRole('auditor');
        $this->asRole('admin');
        $sessions = $this->getJson('/api/v1/sessions')->assertOk()->json('data');
        $target = collect($sessions)->firstWhere('userId', 'u2');
        $this->assertNotNull($target);
        $this->postJson('/api/v1/sessions/terminate', ['ids' => [$target['id']]])->assertOk()->assertJsonPath('count', 1);
        $this->assertDatabaseHas('user_sessions', ['id' => $target['id'], 'status' => 'terminated']);
        $this->assertDatabaseHas('security_events', ['type' => 'SESSION_TERMINATED', 'user_id' => 'u2']);
        $this->asRole('manager')->postJson('/api/v1/sessions/terminate', ['ids' => [$target['id']]])->assertForbidden();
        $this->getJson('/api/v1/sessions')->assertForbidden();
    }
}
