<?php

namespace Modules\Reports\Tests\Feature;

use App\Models\Role;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseTask;
use Modules\Cases\Models\RahaCase;
use Tests\TestCase;

/** Fixture helpers: build requests with exact activity histories (front `make()` / `event()`). */
abstract class ReportsTestCase extends TestCase
{
    private array $template = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->template = RahaCase::query()->find('REQ-000123')->getAttributes();
    }

    /** Changes a role's grants; null removes the permission. */
    protected function grant(string $roleId, array $changes): void
    {
        $role = Role::find($roleId);
        $perms = $role->permissions ?? [];
        foreach ($changes as $code => $scope) {
            if ($scope === null) {
                unset($perms[$code]);
            } else {
                $perms[$code] = $scope;
            }
        }
        $role->permissions = $perms;
        $role->save();
        app(PermissionResolver::class)->flush();
    }

    protected function clearCases(): void
    {
        RahaCase::query()->delete();
        CaseActivity::query()->delete();
        CaseTask::query()->delete();
    }

    /** Copy of seed REQ-000123 with no history; $extra overrides model attributes. */
    protected function make(string $id, array $extra = [], array $events = [], array $tasks = []): RahaCase
    {
        $c = (new RahaCase)->setRawAttributes($this->template);
        $c->exists = false;
        $c->id = $id;
        $c->forceFill(array_merge(['created_at' => Carbon::parse('2026-10-01T00:00:00Z'), 'updated_at' => Carbon::parse('2026-10-25T00:00:00Z'), 'status' => 'new', 'provider_id' => null, 'provider' => null], $extra));
        $c->save();
        foreach ($events as [$at, $from, $to, $action]) {
            CaseActivity::query()->create(['id' => 'a-'.Str::lower((string) Str::ulid()), 'case_id' => $id, 'at' => Carbon::parse($at), 'text' => 'event', 'actor' => 'staff',
                'visibility' => 'internal', 'action' => $action, 'from_stage' => $from, 'to_stage' => $to]);
        }
        foreach ($tasks as $t) {
            CaseTask::query()->create(array_merge(['id' => 't-'.Str::lower((string) Str::ulid()), 'case_id' => $id, 'role' => 'auditor', 'status' => 'new', 'priority' => 'normal', 'history' => []], $t));
        }

        return $c;
    }

    protected static function ev(string $at, ?string $from, ?string $to, string $action = 'advance'): array
    {
        return [$at, $from, $to, $action];
    }

    protected function report(string $role, string $kind, array $q = [])
    {
        return $this->asRole($role)->getJson("/api/v1/reports/$kind".($q ? '?'.http_build_query($q) : ''));
    }
}
