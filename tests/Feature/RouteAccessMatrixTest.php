<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Actor × route matrix over every parameterless GET route (derived from the router, so new routes are covered automatically):
 *  - anonymous callers get 401 on every protected route;
 *  - an account type the route excludes always gets 403 (authorization lives in the API, not in the UI);
 *  - an allowed account type never gets a 5xx or a non-JSON body (200, or 403/404/422 for permission or state reasons).
 */
class RouteAccessMatrixTest extends TestCase
{
    private const ACTORS = ['admin' => 'STAFF', 'auditor' => 'STAFF', 'medical' => 'STAFF', 'finance' => 'STAFF', 'executive' => 'STAFF', 'relations' => 'STAFF',
        'provider' => 'PROVIDER', 'beneficiary' => 'BENEFICIARY'];

    /** @return array<int, array{uri: string, protected: bool, types: string[]}> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{')) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $types = [];
            foreach ($mw as $m) {
                if (is_string($m) && preg_match('/(?:EnsureAccountType|account):([A-Z,]+)$/', $m, $mm)) {
                    $types = explode(',', $mm[1]); // alias (account:STAFF) or resolved class
                }
            }
            $out[] = ['uri' => '/'.$uri, 'protected' => in_array('raha.auth', $mw, true), 'types' => $types];
        }
        $this->assertGreaterThan(40, count($out), 'route discovery returned too few routes');

        return $out;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_anonymous_callers_are_rejected_on_every_protected_route(): void
    {
        $bad = [];
        foreach ($this->routes() as $r) {
            $res = $this->getJson($r['uri']);
            if ($r['protected'] && $res->status() !== 401) {
                $bad[] = "{$r['uri']} anonymous => {$res->status()} (expected 401)";
            }
            if ($res->status() >= 500) {
                $bad[] = "{$r['uri']} anonymous => {$res->status()}";
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_every_actor_gets_the_documented_status_on_every_route(): void
    {
        $bad = [];
        $checked = 0;
        foreach (self::ACTORS as $role => $type) {
            $this->asRole($role);
            foreach ($this->routes() as $r) {
                if (! $r['protected']) {
                    continue;
                }
                $res = $this->getJson($r['uri']);
                $s = $res->status();
                $checked++;
                if ($r['types'] && ! in_array($type, $r['types'], true)) {
                    if ($s !== 403) {
                        $bad[] = "$role {$r['uri']} => $s (account type $type must get 403)";
                    }

                    continue;
                }
                if ($s >= 500 || $s === 401) {
                    $bad[] = "$role {$r['uri']} => $s";
                } elseif ($s === 200 && json_decode($res->getContent()) === null) {
                    $bad[] = "$role {$r['uri']} => 200 but not JSON";
                }
            }
        }
        $this->assertGreaterThan(200, $checked);
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
