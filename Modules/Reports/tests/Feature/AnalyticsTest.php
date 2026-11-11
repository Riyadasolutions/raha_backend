<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Beneficiaries\Models\Beneficiary;
use Modules\Cases\Models\RahaCase;
use Modules\Reports\Services\AnalyticsService;

/** Port of front analytics.test.tsx (Task 08). */
class AnalyticsTest extends ReportsTestCase
{
    private const NONE = ['from' => '', 'to' => ''];

    private function mk(string $id, string $ben, string $service, ?string $serviceId, string $createdAt, array $extra = [], array $events = []): RahaCase
    {
        return $this->make($id, array_merge(['beneficiary_id' => $ben, 'service' => $service, 'service_id' => $serviceId, 'created_at' => Carbon::parse($createdAt), 'fast_track' => null], $extra), $events);
    }

    private function done(string $at): array
    {
        return [self::ev($at, 'in_progress', 'completed', 'complete_service')];
    }

    private static function reg(string $id, ?string $nat, ?string $birth, ?string $createdAt = null): array
    {
        return ['id' => $id, 'nationality' => $nat, 'birthDate' => $birth, 'createdAt' => $createdAt];
    }

    private function compute(array $range = self::NONE, array $registered = [], ?int $now = null): array
    {
        return AnalyticsService::compute(RahaCase::query()->with(['activities', 'tasks'])->get(), $registered, $range, $now);
    }

    public function test_counts_unique_registered_beneficiaries_from_records_requests_and_portal_accounts(): void
    {
        $a = $this->asRole('executive')->getJson('/api/v1/reports/analytics')->assertOk()->json('data');
        $ids = Beneficiary::query()->pluck('id')->merge(RahaCase::query()->pluck('beneficiary_id'))->unique();
        $this->assertSame($ids->count(), $a['registeredTotal']);
        $this->assertSame(RahaCase::count(), $a['totalRequests']);
        $this->assertNull($a['registeredInPeriod']);
    }

    public function test_deduplicates_active_beneficiaries_and_follows_the_submission_range(): void
    {
        $this->clearCases();
        $this->mk('R1', 'B1', 's', 'SRV-001', '2026-03-01T00:00:00Z');
        $this->mk('R2', 'B1', 's', 'SRV-001', '2026-03-10T23:59:59Z');
        $this->mk('R3', 'B2', 's', 'SRV-001', '2026-03-11T00:00:00Z');
        $r = [self::reg('B1', 'a', ''), self::reg('B2', 'b', ''), self::reg('B3', 'c', '')];
        $a = $this->compute(self::NONE, $r);
        $this->assertSame([2, 3, 3], [$a['activeBeneficiaries'], $a['totalRequests'], $a['registeredTotal']]);
        $this->assertSame(2, $this->compute(['from' => '2026-03-01', 'to' => '2026-03-10'], $r)['totalRequests']);
        $this->assertSame(1, $this->compute(['from' => '2026-03-11', 'to' => ''], $r)['totalRequests']);
        $this->assertSame(1, $this->compute(['from' => '', 'to' => '2026-03-01'], $r)['totalRequests']);
        $this->assertSame(0, $this->compute(['from' => '2026-03-05', 'to' => '2026-03-05'], $r)['totalRequests']);
    }

    public function test_rejects_invalid_ranges(): void
    {
        $this->assertStringContainsString('يسبق', AnalyticsService::validateRange(['from' => '2026-03-10', 'to' => '2026-03-01']));
        $this->assertNotNull(AnalyticsService::validateRange(['from' => 'abc', 'to' => '']));
        $this->assertNotNull(AnalyticsService::validateRange(['from' => '', 'to' => '2026-13-45']));
        $this->assertNull(AnalyticsService::validateRange(['from' => '2026-03-01', 'to' => '2026-03-01']));
        $this->asRole('executive')->getJson('/api/v1/reports/analytics?from=2026-03-10&to=2026-03-01')->assertStatus(422)->assertJsonPath('message', 'تاريخ البداية يجب أن يسبق تاريخ النهاية');
    }

    public function test_completed_services_use_the_actual_completion_record(): void
    {
        $this->clearCases();
        $this->mk('R1', 'B1', 's', 'SRV-001', '2026-01-01T00:00:00Z', ['status' => 'completed'], $this->done('2026-03-05T10:00:00Z'));
        $this->mk('R2', 'B1', 's', 'SRV-001', '2026-01-01T00:00:00Z', ['status' => 'assigned', 'provider_id' => 'PRV-0005']);
        $this->mk('R3', 'B1', 's', 'SRV-001', '2026-01-01T00:00:00Z', ['status' => 'preliminary_approved']);
        $this->mk('R4', 'B1', 's', 'SRV-001', '2026-01-01T00:00:00Z', ['status' => 'archived']); // undated
        $a = $this->compute(['from' => '2026-03-01', 'to' => '2026-03-31'], [self::reg('B1', 'a', '')]);
        $this->assertSame([1, 0, 1], [$a['completedServices'], $a['totalRequests'], $a['completedWithoutDate']]);
        $this->assertSame(0, $this->compute(['from' => '2026-04-01', 'to' => ''], [self::reg('B1', 'a', '')])['completedServices']);
    }

    public function test_service_ranking_uses_ids_legacy_names_and_snapshot_labels(): void
    {
        $this->clearCases();
        foreach ([0, 1, 2] as $i) {
            $this->mk("A$i", 'B1', 'علاج طبيعي منزلي', 'SRV-002', '2026-03-01T00:00:00Z');
        }
        $this->mk('L1', 'B1', 'علاج طبيعي', null, '2026-03-02T00:00:00Z');
        $this->mk('X1', 'B2', 'غسيل كلى منزلي', 'SRV-006', '2026-03-01T00:00:00Z');
        $this->mk('C1', 'B2', 'الأدوية', 'SRV-010', '2026-03-01T00:00:00Z');
        $a = $this->compute(self::NONE, [self::reg('B1', 'a', ''), self::reg('B2', 'a', '')]);
        $this->assertSame(['key' => 'SRV-002', 'label' => 'علاج طبيعي', 'count' => 4], array_intersect_key($a['services'][0], ['key' => 1, 'label' => 1, 'count' => 1]));
        $this->assertSame(['الأدوية', 'غسيل كلى منزلي'], array_column(array_slice($a['services'], 1), 'label'));
        $this->assertSame($a['totalRequests'], array_sum(array_column($a['services'], 'count')));
    }

    public function test_nationality_folds_variants_and_age_groups_use_the_reference_date(): void
    {
        $this->clearCases();
        foreach (['B1', 'B1', 'B2', 'B3', 'B4'] as $i => $b) {
            $this->mk("R$i", $b, 's', 'SRV-001', '2026-03-02T00:00:00Z');
        }
        $this->mk('R9', 'B5', 's', 'SRV-001', '2026-01-02T00:00:00Z');
        $a = $this->compute(['from' => '2026-03-01', 'to' => ''], [self::reg('B1', 'سعودي', ''), self::reg('B2', 'سعودية', ''), self::reg('B3', '', ''), self::reg('B4', null, ''), self::reg('B5', 'يمنية', '')]);
        $this->assertSame([['key' => 'سعودي', 'label' => 'سعودي', 'count' => 2, 'percent' => 50], ['key' => 'غير محدد', 'label' => 'غير محدد', 'count' => 2, 'percent' => 50]], $a['nationality']);
        $this->assertSame('غير محدد', AnalyticsService::normalizeNationality(' غير معروف '));

        $ref = Carbon::parse('2026-06-30T23:59:59Z');
        $this->assertSame([18, 17, 60], [AnalyticsService::ageAt('2008-06-30', $ref), AnalyticsService::ageAt('2008-07-01', $ref), AnalyticsService::ageAt('1966-06-30', $ref)]);
        foreach ([null, '', 'not-a-date', '2027-01-01', '1800-01-01'] as $bad) {
            $this->assertNull(AnalyticsService::ageAt($bad, $ref));
        }
        $this->clearCases();
        foreach (['B1', 'B2', 'B3', 'B4', 'B5'] as $i => $b) {
            $this->mk("R$i", $b, 's', 'SRV-001', '2026-03-01T00:00:00Z');
        }
        $a = $this->compute(['from' => '', 'to' => '2026-03-31'], [self::reg('B1', 'a', '2010-01-01'), self::reg('B2', 'a', '1995-05-05'), self::reg('B3', 'a', '1975-05-05'), self::reg('B4', 'a', '1950-05-05'), self::reg('B5', 'a', '')]);
        $this->assertSame([['0–17', 1], ['18–39', 1], ['40–59', 1], ['60+', 1], ['غير محدد', 1]], array_map(fn ($g) => [$g['label'], $g['count']], $a['age']));
        $this->assertSame('2026-03-31', $a['referenceDate']);
        $this->assertSame('2030-01-01', $this->compute(self::NONE, [], Carbon::parse('2030-01-01T00:00:00Z')->getTimestampMs())['referenceDate']);
    }

    public function test_registration_counts_only_use_known_dates(): void
    {
        $this->clearCases();
        $r = [self::reg('B1', 'a', '', '2026-03-05T00:00:00Z'), self::reg('B2', 'a', '', '2025-01-01T00:00:00Z'), self::reg('B3', 'a', '')];
        $this->assertNull($this->compute(self::NONE, $r)['registeredInPeriod']);
        $a = $this->compute(['from' => '2026-03-01', 'to' => '2026-03-31'], $r);
        $this->assertSame([3, 1, 2, 1], [$a['registeredTotal'], $a['registeredInPeriod'], $a['registrationDatesKnown'], $a['registrationDatesUnknown']]);
    }

    public function test_permissions_and_aggregate_only_payload(): void
    {
        $this->asRole('admin')->getJson('/api/v1/reports/analytics')->assertOk();
        $this->asRole('provider')->getJson('/api/v1/reports/analytics')->assertForbidden();
        $this->asRole('beneficiary')->getJson('/api/v1/reports/analytics')->assertForbidden();
        $this->asRole('team')->getJson('/api/v1/reports/analytics')->assertForbidden();
        $this->grant('manager', ['reports.view' => 'ASSIGNED']);
        $this->asRole('manager')->getJson('/api/v1/reports/analytics')->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية عرض التحليلات.');
        $this->grant('manager', ['reports.view' => 'ALL', 'cases.view' => 'ASSIGNED']);
        $this->asRole('manager')->getJson('/api/v1/reports/analytics')->assertForbidden();

        $res = $this->asRole('executive')->getJson('/api/v1/reports/analytics')->assertOk();
        $this->assertDoesNotMatchRegularExpression('/nationalId|mobile|email|birthDate/', $res->getContent());
        $a = $res->json('data');
        $this->assertSame(['القسم', 'البند', 'العدد', 'النسبة %', 'أساس التاريخ'], $a['table']['head']);
        $this->assertSame(['تحليلات المستفيدين والخدمات', 'الخدمات الأكثر طلبًا', 'توزيع الجنسيات', 'توزيع الأعمار'], array_column($a['sheets'], 'name'));
        $this->assertSame($a['totalRequests'], array_sum(array_column($a['services'], 'count')));
        $this->assertSame($a['activeBeneficiaries'], array_sum(array_column($a['nationality'], 'count')));
        $this->assertSame($a['activeBeneficiaries'], array_sum(array_column($a['age'], 'count')));
    }

    public function test_analytics_export_requires_reports_export_and_returns_four_sheets(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/reports/analytics/export')->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية تصدير التقارير.');
        $this->asRole('provider')->getJson('/api/v1/reports/analytics/export')->assertForbidden();
        $this->asRole('executive')->getJson('/api/v1/reports/analytics/export?from=2026-05-01&to=2026-01-01')->assertStatus(422);
        $d = $this->getJson('/api/v1/reports/analytics/export?format=csv')->assertOk()->json('data');
        $this->assertSame(['تحليلات المستفيدين والخدمات', 'الخدمات الأكثر طلبًا', 'توزيع الجنسيات', 'توزيع الأعمار'], array_column($d['sheets'], 'name'));
        $this->assertGreaterThan(5, count($d['table']['rows']));
        $this->assertStringStartsWith('RAHA_Analytics_', $d['filename']);
        $this->assertStringEndsWith('.csv', $d['filename']);
        foreach (['requests', 'beneficiaries', 'services', 'performance'] as $kind) {
            $this->assertNotEmpty($this->getJson("/api/v1/reports/$kind/export")->assertOk()->json('data.table.rows'));
        }
    }
}
