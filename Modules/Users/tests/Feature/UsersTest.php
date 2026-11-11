<?php

namespace Modules\Users\Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersTest extends TestCase
{
    private function draft(array $over = []): array
    {
        return array_merge([
            'name' => 'د. هند السالم', 'username' => 'hind', 'email' => 'Hind@Raha.sa', 'mobile' => '0551234567', 'department' => 'التدقيق',
            'jobTitle' => 'مدققة', 'roleIds' => ['auditor'], 'active' => true, 'mustChangePassword' => false,
        ], $over);
    }

    public function test_list_never_exposes_passwords_and_has_staff_shape(): void
    {
        $res = $this->asRole('admin')->getJson('/api/v1/users')->assertOk();
        $rows = collect($res->json('data'));
        $this->assertFalse($rows->contains(fn ($u) => isset($u['password']) || isset($u['passwordHash']) || isset($u['passwordHistory'])));
        $this->assertFalse($rows->contains('id', 'u-BEN-0001'));
        $p = $rows->firstWhere('id', 'u10');
        $this->assertSame('PRV-0005', $p['providerId']);
        $this->assertSame(['provider'], $p['roleIds']);
        $this->assertArrayHasKey('accountState', $p);
        $this->asRole('auditor')->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_create_user_returns_temporary_password_once(): void
    {
        $res = $this->asRole('admin')->postJson('/api/v1/users', $this->draft())->assertCreated();
        $res->assertJsonPath('data.role', 'auditor')->assertJsonPath('data.roleLabel', 'المدقق')->assertJsonPath('data.initials', 'ها')
            ->assertJsonPath('data.mustChangePassword', true)->assertJsonPath('data.accountType', 'STAFF')->assertJsonPath('data.accountState', 'mustChange');
        $temp = $res->json('temporaryPassword');
        $this->assertNotEmpty($temp);
        $id = $res->json('data.id');
        $this->assertTrue(Hash::check($temp, User::find($id)->password));
        $this->assertFalse(Hash::check('demo1234', User::find($id)->password));
        $this->assertDatabaseHas('security_events', ['type' => 'USER_CREATED', 'user_id' => $id]);
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_ASSIGNED', 'user_id' => $id, 'target' => 'المدقق']);
        $this->getJson("/api/v1/users/$id")->assertOk()->assertJsonMissingPath('temporaryPassword')->assertJsonPath('data.username', 'hind');
        $this->postJson('/api/v1/auth/login', ['identity' => 'hind', 'password' => $temp])->assertOk()->assertJsonPath('me.accountState', 'mustChange');
    }

    public function test_provider_user_gets_provider_account_type(): void
    {
        $this->asRole('admin')->postJson('/api/v1/users', $this->draft(['roleIds' => ['provider']]))->assertStatus(422)->assertJsonPath('message', 'اختر مقدم الخدمة المرتبط.');
        $this->postJson('/api/v1/users', $this->draft(['roleIds' => ['provider'], 'providerId' => 'PRV-0001']))->assertCreated()
            ->assertJsonPath('data.accountType', 'PROVIDER')->assertJsonPath('data.role', 'provider');
    }

    public function test_unique_username_and_email_case_insensitive(): void
    {
        $this->asRole('admin')->postJson('/api/v1/users', $this->draft(['email' => 'ADMIN@raha.sa']))->assertStatus(422)->assertJsonPath('message', 'البريد الإلكتروني مستخدم لحساب آخر.');
        $this->postJson('/api/v1/users', $this->draft(['username' => 'AUDITOR']))->assertStatus(422)->assertJsonPath('message', 'اسم المستخدم مستخدم لحساب آخر.');
        $this->postJson('/api/v1/users', $this->draft(['mobile' => '123']))->assertStatus(422)->assertJsonPath('message', 'رقم الجوال يبدأ بـ 05 ويتكون من 10 أرقام.');
    }

    public function test_cannot_assign_role_granting_more_than_actor_holds(): void
    {
        DB::table('roles')->insert([
            'id' => 'r-hr', 'code' => 'HR_ADMIN', 'name_ar' => 'الموارد البشرية', 'name_en' => 'HR', 'type' => 'CUSTOM', 'status' => 'active',
            'permissions' => json_encode(['users.view' => 'ALL', 'users.create' => 'ALL', 'users.edit' => 'ALL']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_user')->insert(['user_id' => 'u7', 'role_id' => 'r-hr', 'position' => 1]);
        $this->asRole('team')->postJson('/api/v1/users', $this->draft(['roleIds' => ['admin']]))->assertForbidden()
            ->assertJsonPath('message', 'لا تملك صلاحية منح هذا الإذن. — أحد الأدوار المختارة يمنح صلاحيات لا تملكها.');
        $this->postJson('/api/v1/users', $this->draft(['roleIds' => ['team']]))->assertCreated();
    }

    public function test_update_and_remove_role_logs_and_last_admin_guard(): void
    {
        $this->asRole('admin');
        $u = $this->getJson('/api/v1/users/u7')->json('data');
        $this->putJson('/api/v1/users/u7', $this->draft(['name' => $u['name'], 'username' => 'team01', 'email' => $u['email'], 'roleIds' => ['team', 'relations']]))
            ->assertOk()->assertJsonPath('data.roleIds', ['team', 'relations'])->assertJsonPath('data.role', 'team');
        $this->assertDatabaseHas('security_events', ['type' => 'USER_UPDATED', 'user_id' => 'u7']);
        $this->deleteJson('/api/v1/users/u7/roles/relations')->assertOk()->assertJsonPath('data.roleIds', ['team']);
        $this->assertDatabaseHas('security_events', ['type' => 'ROLE_REMOVED', 'user_id' => 'u7']);
        $this->deleteJson('/api/v1/users/u1/roles/admin')->assertStatus(422)->assertJsonPath('message', 'لا يمكن إزالة صلاحيات مدير النظام من آخر مدير نشط.');
        $this->assertSame(['admin'], User::find('u1')->roleIds());
        $this->asRole('auditor')->putJson('/api/v1/users/u7', $this->draft(['username' => 'team01']))->assertForbidden();
    }

    public function test_suspend_ends_sessions_and_last_admin_cannot_be_suspended(): void
    {
        $this->asRole('manager');
        $this->asRole('admin')->postJson('/api/v1/users/u1/suspend')->assertStatus(422)->assertJsonPath('message', 'لا يمكن إزالة صلاحيات مدير النظام من آخر مدير نشط.');
        $this->assertFalse(User::find('u1')->suspended);
        $this->postJson('/api/v1/users/u5/suspend')->assertOk()->assertJsonPath('data.accountState', 'suspended');
        $this->assertSame(0, UserSession::query()->where('user_id', 'u5')->where('status', 'active')->count());
        $this->assertDatabaseHas('security_events', ['type' => 'ACCOUNT_SUSPENDED', 'user_id' => 'u5']);
        $this->postJson('/api/v1/users/u5/activate')->assertOk()->assertJsonPath('data.suspended', false);
        $this->asRole('auditor')->postJson('/api/v1/users/u5/suspend')->assertForbidden();
    }

    public function test_security_actions(): void
    {
        $this->asRole('social');
        $this->asRole('admin');
        $this->postJson('/api/v1/users/u4/lock')->assertOk()->assertJsonPath('data.accountState', 'locked');
        $this->assertSame(0, UserSession::query()->where('user_id', 'u4')->where('status', 'active')->count());
        $this->postJson('/api/v1/auth/login', ['identity' => 'social', 'password' => 'demo1234'])->assertStatus(423);
        $this->postJson('/api/v1/users/u4/unlock')->assertOk()->assertJsonPath('data.failedAttempts', 0);
        $this->postJson('/api/v1/users/u4/force-password-change')->assertOk()->assertJsonPath('data.mustChangePassword', true);
        DB::table('users')->where('id', 'u4')->update(['mfa_enabled' => true]);
        $this->postJson('/api/v1/users/u4/reset-mfa')->assertOk()->assertJsonPath('data.mfaEnabled', false);
        $this->asRole('social');
        $this->asRole('admin')->postJson('/api/v1/users/u4/end-sessions')->assertOk()->assertJsonPath('count', 1);
        foreach (['ACCOUNT_LOCKED', 'ACCOUNT_UNLOCKED', 'FORCE_PASSWORD_CHANGE', 'MFA_DISABLED', 'SESSION_TERMINATED'] as $type) {
            $this->assertDatabaseHas('security_events', ['type' => $type, 'user_id' => 'u4']);
        }
        $this->postJson('/api/v1/users/u1/lock')->assertStatus(422);
        $this->asRole('manager')->postJson('/api/v1/users/u4/lock')->assertForbidden();
    }

    public function test_user_detail_includes_sessions_events_audit_and_grants(): void
    {
        $this->asRole('auditor');
        $res = $this->asRole('admin')->getJson('/api/v1/users/u2')->assertOk();
        $this->assertNotEmpty($res->json('sessions'));
        $this->assertTrue(collect($res->json('grants'))->contains(fn ($g) => $g['code'] === 'cases.review' && $g['sources'] === ['المدقق']));
        $this->assertIsArray($res->json('securityEvents'));
        $this->assertIsArray($res->json('audit'));
        $this->getJson('/api/v1/users/u2/sessions')->assertOk()->assertJsonPath('data.0.userId', 'u2');
        $this->getJson('/api/v1/users/u-BEN-0001')->assertNotFound();
    }
}
