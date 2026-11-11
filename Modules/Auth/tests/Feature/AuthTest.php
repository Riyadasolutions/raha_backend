<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_staff_login_returns_token_and_me_payload(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['identity' => 'auditor', 'password' => 'demo1234', 'type' => 'staff'])->assertOk();
        $res->assertJsonPath('me.user.id', 'u2')->assertJsonPath('me.accountType', 'STAFF');
        $this->assertSame('ALL', $res->json('me.permissions')['cases.review']);
        $this->assertNotEmpty($res->json('token'));
        $this->withToken($res->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.roleIds.0', 'auditor');
        $this->assertDatabaseHas('security_events', ['type' => 'LOGIN_SUCCESS', 'user_id' => 'u2']);
    }

    public function test_login_by_email_is_case_insensitive(): void
    {
        $this->postJson('/api/v1/auth/login', ['identity' => 'ADMIN@raha.sa', 'password' => 'demo1234'])->assertOk()->assertJsonPath('me.user.id', 'u1');
    }

    public function test_wrong_portal_type_is_rejected_without_counting_a_failure(): void
    {
        $this->postJson('/api/v1/auth/login', ['identity' => 'provider', 'password' => 'demo1234', 'type' => 'staff'])->assertStatus(401);
        $this->assertSame(0, User::find('u10')->failed_attempts);
        $this->postJson('/api/v1/auth/login', ['identity' => 'provider', 'password' => 'demo1234', 'type' => 'provider'])->assertOk()->assertJsonPath('me.accountType', 'PROVIDER');
    }

    public function test_lockout_after_max_failed_attempts(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['identity' => 'medical', 'password' => 'wrong'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['identity' => 'medical', 'password' => 'wrong'])->assertStatus(401)
            ->assertJsonPath('message', 'تم قفل الحساب مؤقتًا بسبب تكرار محاولات تسجيل الدخول غير الصحيحة.');
        $this->postJson('/api/v1/auth/login', ['identity' => 'medical', 'password' => 'demo1234'])->assertStatus(423);
        $this->assertDatabaseHas('security_events', ['type' => 'ACCOUNT_LOCKED', 'user_id' => 'u3']);
    }

    public function test_beneficiary_login_register_and_profile(): void
    {
        $this->postJson('/api/v1/auth/beneficiary/login', ['nationalId' => '1087654321', 'password' => 'demo1234'])->assertOk()->assertJsonPath('me.user.beneficiaryId', 'BEN-0001');
        $this->postJson('/api/v1/auth/beneficiary/register', ['firstName' => 'سلمى', 'familyName' => 'العمر', 'idType' => 'هوية وطنية', 'idNumber' => '1234', 'password' => 'Strong#2026'])
            ->assertStatus(422)->assertJsonPath('message', 'رقم الهوية الوطنية ١٠ أرقام ويبدأ بـ 1');
        $res = $this->postJson('/api/v1/auth/beneficiary/register', ['firstName' => 'سلمى', 'familyName' => 'العمر', 'idType' => 'هوية وطنية', 'idNumber' => '1122334455', 'password' => 'Strong#2026'])->assertCreated();
        $benId = $res->json('me.user.beneficiaryId');
        $this->assertMatchesRegularExpression('/^BEN-\d{4}$/', $benId);
        $this->postJson('/api/v1/auth/beneficiary/register', ['firstName' => 'x', 'familyName' => 'y', 'idType' => 'هوية وطنية', 'idNumber' => '1122334455', 'password' => 'Strong#2026'])
            ->assertStatus(422)->assertJsonPath('message', 'يوجد حساب مسجل بهذا الرقم.');
        $this->withToken($res->json('token'))->putJson('/api/v1/me/profile', ['firstName' => 'سلمى', 'fatherName' => 'محمد', 'familyName' => 'العمر', 'mobile' => '0551112223'])
            ->assertOk()->assertJsonPath('data.nationalId', '1122334455')->assertJsonPath('user.name', 'سلمى محمد');
    }

    public function test_staff_cannot_open_beneficiary_profile(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/me/profile')->assertForbidden();
    }

    public function test_change_password_enforces_policy_and_history(): void
    {
        DB::table('users')->where('id', 'u2')->update(['must_change_password' => true]);
        $this->asRole('auditor')->putJson('/api/v1/auth/password', ['current' => 'demo1234', 'next' => 'short'])->assertStatus(422);
        $this->putJson('/api/v1/auth/password', ['current' => 'demo1234', 'next' => 'Very$trongPass1'])->assertOk();
        $this->assertFalse(User::find('u2')->must_change_password);
        $this->postJson('/api/v1/auth/login', ['identity' => 'auditor', 'password' => 'Very$trongPass1'])->assertOk();
    }

    public function test_logout_ends_session_and_token(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['identity' => 'social', 'password' => 'demo1234'])->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_idle_session_expires(): void
    {
        $this->asRole('finance')->getJson('/api/v1/auth/me')->assertOk();
        DB::table('user_sessions')->where('user_id', 'u6')->update(['last_active_at' => now()->subHour()]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_ENDED');
    }

    public function test_staff_password_reset_with_token(): void
    {
        $token = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@raha.sa'])->assertOk()->json('debug');
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'admin@raha.sa', 'token' => $token, 'password' => 'N3w$ecurePass!', 'password_confirmation' => 'N3w$ecurePass!'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['identity' => 'admin', 'password' => 'N3w$ecurePass!'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@raha.sa'])->assertOk()->assertJsonMissingPath('debug');
    }

    public function test_beneficiary_password_reset_with_otp(): void
    {
        $code = $this->postJson('/api/v1/auth/beneficiary/forgot-password', ['nationalId' => '1087654321'])->assertOk()->json('debug');
        $this->postJson('/api/v1/auth/beneficiary/reset-password', ['nationalId' => '1087654321', 'code' => '000000', 'password' => 'Abc#12345', 'password_confirmation' => 'Abc#12345'])->assertStatus(422);
        $this->postJson('/api/v1/auth/beneficiary/reset-password', ['nationalId' => '1087654321', 'code' => $code, 'password' => 'Abc#12345', 'password_confirmation' => 'Abc#12345'])->assertOk();
        $this->postJson('/api/v1/auth/beneficiary/login', ['nationalId' => '1087654321', 'password' => 'Abc#12345'])->assertOk();
    }

    public function test_concurrent_session_limit_terminates_oldest(): void
    {
        $tokens = [];
        for ($i = 0; $i < 4; $i++) {
            $tokens[] = $this->postJson('/api/v1/auth/login', ['identity' => 'relations', 'password' => 'demo1234'])->json('token');
        }
        $this->assertSame(3, DB::table('user_sessions')->where('user_id', 'u8')->where('status', 'active')->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens[0])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_quick_login_in_dev(): void
    {
        $this->postJson('/api/v1/auth/dev/quick-login', ['role' => 'manager'])->assertOk()->assertJsonPath('me.user.id', 'u5');
        $this->postJson('/api/v1/auth/dev/quick-login', ['role' => 'beneficiary'])->assertOk()->assertJsonPath('me.accountType', 'BENEFICIARY');
    }
}
