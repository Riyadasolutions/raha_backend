<?php

namespace Modules\Audit\Tests\Feature;

use App\Models\User;
use App\Support\Activity\Activity;
use Tests\TestCase;

class AuditTest extends TestCase
{
    public function test_admin_lists_audit_newest_first_with_filters(): void
    {
        Activity::audit('إجراء اختباري', 'خدمة', 'SRV-001', User::find('u5'));
        $res = $this->asRole('admin')->getJson('/api/v1/audit-logs')->assertOk();
        $rows = $res->json('data');
        $this->assertSame('إجراء اختباري', $rows[0]['action']);
        $this->assertSame('u5', $rows[0]['userId']);
        $ats = array_column($rows, 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats);
        $this->assertTrue(collect($this->getJson('/api/v1/audit-logs?recordType=مقدم خدمة')->json('data'))->every(fn ($a) => $a['recordType'] === 'مقدم خدمة'));
        $this->assertCount(1, $this->getJson('/api/v1/audit-logs?q=اختباري')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/audit-logs?userId=u5')->json('data'));
        $this->assertEmpty($this->getJson('/api/v1/audit-logs?from=2000-01-01&to=2000-01-02')->json('data'));
    }

    public function test_full_audit_requires_audit_view(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->asRole('provider')->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_record_scoped_audit_with_domain_permission(): void
    {
        Activity::audit('تعديل خدمة', 'خدمة', 'SRV-001', User::find('u1'));
        $this->asRole('auditor')->getJson('/api/v1/audit-logs?recordType=خدمة&recordId=SRV-001')->assertOk()->assertJsonPath('data.0.action', 'تعديل خدمة');
        $this->getJson('/api/v1/audit-logs?recordType=طلب&recordId=REQ-000110')->assertForbidden();
        $this->getJson('/api/v1/audit-logs?recordType=خدمة')->assertForbidden();
        $this->asRole('team')->getJson('/api/v1/audit-logs?recordType=خدمة&recordId=SRV-001')->assertForbidden();
    }
}
