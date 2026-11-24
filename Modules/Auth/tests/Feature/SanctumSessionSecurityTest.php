<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use App\Support\Security\SecurityPolicy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 integration checks: Sanctum tokens never expire by themselves (sanctum.expiration = null), so
 * expiry and revocation must come from the server session policy. Also proves backend enforcement of account type.
 */
class SanctumSessionSecurityTest extends TestCase
{
    public function test_sanctum_has_no_token_expiry_so_the_session_policy_is_the_only_expiry(): void
    {
        $this->assertNull(config('sanctum.expiration'));
        $this->assertTrue((bool) SecurityPolicy::get()['idleTimeoutEnabled']);
        $this->assertGreaterThan(0, (int) SecurityPolicy::get()['maximumSessionLifetime']);
    }

    public function test_token_dies_after_maximum_session_lifetime(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['identity' => 'finance', 'password' => 'demo1234'])->json('token');
        $hours = (int) SecurityPolicy::get()['maximumSessionLifetime'];
        DB::table('user_sessions')->where('user_id', 'u6')->update(['started_at' => now()->subHours($hours + 1)]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_ENDED');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_suspending_a_user_revokes_a_live_token_on_the_next_request(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['identity' => 'social', 'password' => 'demo1234'])->json('token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        User::query()->where('id', 'u4')->update(['suspended' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_ENDED');
    }

    public function test_admin_can_end_another_users_sessions_and_that_token_is_dead(): void
    {
        $victim = $this->postJson('/api/v1/auth/login', ['identity' => 'relations', 'password' => 'demo1234'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->asRole('admin')->postJson('/api/v1/users/u8/end-sessions')->assertOk();
        $this->app['auth']->forgetGuards();
        // Terminating a session also deletes its Sanctum token, so the token is simply unknown (401).
        $this->withToken($victim)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_anonymous_requests_to_protected_routes_are_401(): void
    {
        foreach (['auth/me', 'cases', 'users', 'beneficiaries', 'finance/dashboard', 'portal/requests', 'provider/dashboard', 'notifications', 'reports/kpis', 'settings/organization'] as $path) {
            $this->getJson("/api/v1/$path")->assertUnauthorized();
        }
    }

    public function test_account_type_is_enforced_by_the_backend_not_the_frontend(): void
    {
        // Provider -> staff-only areas.
        foreach (['users', 'roles', 'beneficiaries', 'finance/dashboard', 'wallets', 'reports/kpis', 'audit-logs', 'quotation-rounds', 'authorizations', 'tasks'] as $path) {
            $this->asRole('provider')->getJson("/api/v1/$path")->assertForbidden();
        }
        // Beneficiary -> staff and provider areas.
        foreach (['users', 'beneficiaries', 'cases', 'finance/dashboard', 'provider/dashboard', 'provider/assignments', 'quotation-rounds', 'reports/kpis'] as $path) {
            $this->asRole('beneficiary')->getJson("/api/v1/$path")->assertForbidden();
        }
        // Staff -> beneficiary / provider portals.
        foreach (['portal/requests', 'me/profile', 'provider/dashboard', 'provider/assignments'] as $path) {
            $this->asRole('admin')->getJson("/api/v1/$path")->assertForbidden();
        }
    }

    public function test_missing_permission_is_403_even_for_the_right_account_type(): void
    {
        // Relations staff has no users.view / roles.view / finance access.
        foreach (['users', 'roles', 'finance/dashboard', 'security-policy'] as $path) {
            $this->asRole('relations')->getJson("/api/v1/$path")->assertForbidden();
        }
        $this->asRole('relations')->putJson('/api/v1/settings/organization', ['nameAr' => 'x'])->assertForbidden();
        $this->asRole('auditor')->postJson('/api/v1/roles', ['nameAr' => 'x', 'code' => 'x', 'permissions' => []])->assertForbidden();
    }

    public function test_wrong_password_and_unknown_user_give_the_same_generic_401(): void
    {
        $a = $this->postJson('/api/v1/auth/login', ['identity' => 'admin', 'password' => 'wrong-password'])->assertUnauthorized();
        $b = $this->postJson('/api/v1/auth/login', ['identity' => 'no-such-user', 'password' => 'wrong-password'])->assertUnauthorized();
        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_dev_quick_login_is_closed_when_dev_accounts_are_disabled(): void
    {
        config(['raha.dev_accounts' => false]);
        $this->postJson('/api/v1/auth/dev/quick-login', ['role' => 'admin'])->assertStatus(404);
        $this->getJson('/api/v1/auth/dev/accounts')->assertStatus(404);
    }

    public function test_password_reset_ends_every_existing_session(): void
    {
        $old = $this->postJson('/api/v1/auth/login', ['identity' => 'admin', 'password' => 'demo1234'])->json('token');
        $reset = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@raha.sa'])->assertOk()->json('debug');
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'admin@raha.sa', 'token' => $reset, 'password' => 'N3w$ecurePass!', 'password_confirmation' => 'N3w$ecurePass!'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['identity' => 'admin', 'password' => 'N3w$ecurePass!'])->assertOk();
    }

    public function test_password_change_ends_other_sessions_but_keeps_the_current_one(): void
    {
        $current = $this->postJson('/api/v1/auth/login', ['identity' => 'manager', 'password' => 'demo1234'])->json('token');
        $this->app['auth']->forgetGuards();
        $other = $this->postJson('/api/v1/auth/login', ['identity' => 'manager', 'password' => 'demo1234'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->putJson('/api/v1/auth/password', ['current' => 'demo1234', 'next' => 'N3w$ecurePass!9'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_changing_own_password_keeps_current_session_and_rejects_wrong_current_without_logout(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['identity' => 'manager', 'password' => 'demo1234'])->json('token');
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current' => 'bad', 'next' => 'N3w$ecurePass!9'])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }
}
