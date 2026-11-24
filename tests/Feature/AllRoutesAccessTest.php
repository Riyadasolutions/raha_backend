<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every API route, every HTTP method (path parameters filled with dummy values), checked at the middleware layer:
 *  1. the only routes reachable without a token are on the explicit public allowlist below (a new open route fails this test);
 *  2. every other route answers an anonymous caller with 401;
 *  3. every route restricted to an account type answers the other account types with 403, before any controller runs.
 */
class AllRoutesAccessTest extends TestCase
{
    /** Methods + URIs that are intentionally public. */
    private const PUBLIC = [
        'POST api/v1/auth/login', 'POST api/v1/auth/beneficiary/login', 'POST api/v1/auth/beneficiary/register',
        'POST api/v1/auth/forgot-password', 'POST api/v1/auth/reset-password',
        'POST api/v1/auth/beneficiary/forgot-password', 'POST api/v1/auth/beneficiary/reset-password',
        'POST api/v1/auth/dev/quick-login', 'GET api/v1/auth/dev/accounts', // 404 unless dev mode (local/testing only)
        'GET api/v1/health', 'GET api/v1/public/bootstrap', 'GET api/v1/public/services', 'POST api/v1/public/contact', 'GET api/v1/settings/language',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** Path with dummy parameter values; constrained params (whereIn) get their first allowed value so the route still matches. */
    private static function fill(\Illuminate\Routing\Route $route): string
    {
        return preg_replace_callback('/\{([^}?]+)\??\}/', function ($m) use ($route) {
            $where = $route->wheres[$m[1]] ?? null;

            return $where ? explode('|', trim($where, '()^$'))[0] : 'x';
        }, $route->uri());
    }

    /** @return array<int, array{method: string, uri: string, key: string, protected: bool, types: string[]}> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $types = [];
            foreach ($mw as $m) {
                if (is_string($m) && preg_match('/(?:EnsureAccountType|account):([A-Z,]+)$/', $m, $mm)) {
                    $types = explode(',', $mm[1]); // alias (account:STAFF) or resolved class
                }
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $out[] = ['method' => $method, 'uri' => '/'.self::fill($route), 'key' => $method.' '.$route->uri(),
                    'protected' => in_array('raha.auth', $mw, true), 'types' => $types];
            }
        }
        $this->assertGreaterThan(150, count($out), 'route discovery returned too few routes');

        return $out;
    }

    public function test_only_allowlisted_routes_are_reachable_without_a_token(): void
    {
        $open = array_values(array_map(fn ($r) => $r['key'], array_filter($this->routes(), fn ($r) => ! $r['protected'])));
        $unexpected = array_values(array_diff($open, self::PUBLIC));
        $this->assertSame([], $unexpected, 'unauthenticated routes not on the public allowlist: '.implode(', ', $unexpected));
    }

    public function test_every_protected_route_rejects_anonymous_callers_for_every_method(): void
    {
        $bad = [];
        $n = 0;
        foreach ($this->routes() as $r) {
            if (! $r['protected']) {
                continue;
            }
            $n++;
            $status = $this->json($r['method'], $r['uri'])->status();
            if ($status !== 401) {
                $bad[] = "{$r['key']} anonymous => $status";
            }
        }
        $this->assertGreaterThan(120, $n);
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_account_type_restricted_routes_reject_the_other_account_types(): void
    {
        $actors = ['admin' => 'STAFF', 'provider' => 'PROVIDER', 'beneficiary' => 'BENEFICIARY'];
        $bad = [];
        $n = 0;
        foreach ($actors as $role => $type) {
            $this->asRole($role);
            foreach ($this->routes() as $r) {
                if (! $r['protected'] || ! $r['types'] || in_array($type, $r['types'], true)) {
                    continue;
                }
                $n++;
                $status = $this->json($r['method'], $r['uri'])->status();
                if ($status !== 403) {
                    $bad[] = "$role ($type) {$r['key']} => $status (expected 403)";
                }
            }
        }
        $this->assertGreaterThan(200, $n);
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
