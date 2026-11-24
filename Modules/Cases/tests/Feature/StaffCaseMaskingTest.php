<?php

namespace Modules\Cases\Tests\Feature;

use App\Models\Role;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\RahaCase;

/** Case projections follow the same masking rule as /beneficiaries (beneficiaries.viewSensitive). */
class StaffCaseMaskingTest extends CasesTestCase
{
    private function setSensitive(string $roleId, bool $on): void
    {
        $role = Role::find($roleId);
        $perms = $role->permissions ?? [];
        if ($on) {
            $perms['beneficiaries.viewSensitive'] = 'ALL';
        } else {
            unset($perms['beneficiaries.viewSensitive']);
        }
        $role->permissions = $perms;
        $role->save();
        app(PermissionResolver::class)->flush();
    }

    public function test_national_id_and_mobile_are_masked_without_view_sensitive_and_clear_with_it(): void
    {
        $this->setSensitive('auditor', false);
        RahaCase::whereKey('REQ-000123')->update(['national_id' => '1055512345', 'mobile' => '0555512345']);
        $c = RahaCase::find('REQ-000123');
        $row = $this->asRole('auditor')->getJson('/api/v1/cases/REQ-000123')->assertOk()->json('data');
        $this->assertNotSame($c->national_id, $row['nationalId']);
        $this->assertStringContainsString('•', $row['nationalId']);
        $this->assertTrue($row['masked']);
        $this->assertStringNotContainsString($c->national_id, $this->getJson('/api/v1/cases')->getContent());
        // Searching by the raw national id must not reveal the case for a user who cannot see it.
        $ids = array_column($this->getJson('/api/v1/cases?q='.$c->national_id)->assertOk()->json('data'), 'id');
        $this->assertNotContains('REQ-000123', $ids);

        $this->setSensitive('auditor', true);
        $row = $this->asRole('auditor')->getJson('/api/v1/cases/REQ-000123')->assertOk()->json('data');
        $this->assertSame($c->national_id, $row['nationalId']);
        $this->assertArrayNotHasKey('masked', $row);
        $this->assertContains('REQ-000123', array_column($this->getJson('/api/v1/cases?q='.$c->national_id)->json('data'), 'id'));
    }
}
