<?php

namespace Tests;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use App\Support\Security\SecurityPolicy;
use App\Support\Security\Sessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Every test runs against a freshly migrated + seeded in-memory SQLite database (same demo data as the frontend).
 * Seed users: u1 admin, u2 auditor, u3 medical, u4 social, u5 manager, u6 finance, u13 executive, u7 team,
 * u8 relations, u10/u11/u12 providers (PRV-0005/PRV-0001/PRV-0006), u-BEN-0001 beneficiary. Password: demo1234.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SecurityPolicy::forget();
        app(PermissionResolver::class)->flush();
    }

    /** Authenticate as a seeded user through a real Sanctum token + session (what the API middleware expects). */
    protected function as(string $userId): static
    {
        $user = User::query()->findOrFail($userId);
        [$token] = Sessions::start($user);
        app(PermissionResolver::class)->flush();
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** Short aliases for the seeded demo accounts. */
    protected function asRole(string $role): static
    {
        $map = [
            'admin' => 'u1', 'auditor' => 'u2', 'medical' => 'u3', 'social' => 'u4', 'manager' => 'u5', 'finance' => 'u6',
            'executive' => 'u13', 'team' => 'u7', 'relations' => 'u8', 'provider' => 'u10', 'provider2' => 'u11', 'provider3' => 'u12',
            'beneficiary' => 'u-BEN-0001',
        ];

        return $this->as($map[$role] ?? $role);
    }
}
