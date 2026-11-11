<?php

namespace Modules\Reports\Tests\Feature;

use Modules\Cases\Models\RahaCase;
use Modules\Finance\Models\FinanceEvent;
use Modules\Quotations\Models\QuotationRound;

/** Ports of front reports_analytics.test.ts + report_export.test.ts + executive_export_permission (report parts). */
class ReportsTest extends ReportsTestCase
{
    private function fixture(string $id = 'REQ-000007', array $extra = []): RahaCase
    {
        return $this->make($id, array_merge([
            'status' => 'archived', 'provider_id' => 'PRV-0005', 'provider' => 'Provider', 'city' => 'Riyadh',
            'appointment' => ['date' => '2026-10-02', 'time' => '10:30', 'location' => 'home', 'status' => 'visited'],
            'evaluation' => ['rating' => 4, 'comment' => '=unsafe', 'at' => '2026-10-03T00:00:00Z'],
        ], $extra), [self::ev('2026-10-01T00:00:00Z', null, 'new', 'submit'), self::ev('2026-10-03T00:00:00Z', 'new', 'archived')]);
    }

    public function test_never_calculates_durations_from_last_update(): void
    {
        $this->clearCases();
        $this->make('REQ-000001', ['status' => 'archived']);
        $row = $this->report('manager', 'lifecycle')->assertOk()->json('data.rows.0.cells');
        $this->assertSame(['غير متوفر', 'غير متوفر', 'غير متوفر', 'سجل تاريخي غير مكتمل'], [$row[5], $row[6], $row[7], $row[8]]);
        $this->report('manager', 'performance')->assertOk()->assertJsonPath('data.performance.average', null)->assertJsonPath('data.performance.missing', 1);
    }

    public function test_rejects_tasks_initialized_from_updated_at_as_stage_entry_evidence(): void
    {
        $this->clearCases();
        $this->travelTo('2026-10-02T00:00:00Z');
        $this->make('REQ-000001', [], [], [['stage' => 'new', 'created_at' => '2026-10-01T00:00:00Z']]);
        $this->report('manager', 'lifecycle')->assertJsonPath('data.rows.0.cells.9', 'لا توجد أحداث انتقال موثقة');
    }

    public function test_financial_substages_come_only_from_finance_events_and_are_hidden_without_finance_access(): void
    {
        $this->clearCases();
        $this->make('REQ-000001');
        foreach ([['2026-10-01T01:00:00Z', 'pending_review', 'in_review'], ['2026-10-01T04:00:00Z', 'in_review', 'pending_executive']] as [$at, $from, $to]) {
            FinanceEvent::query()->create(['at' => $at, 'actor' => 'finance', 'case_id' => 'REQ-000001', 'action' => 'review', 'from_stage' => $from, 'to_stage' => $to]);
        }
        $cells = $this->report('admin', 'lifecycle')->assertOk()->json('data.rows.0.cells');
        $this->assertSame(3, $cells[7]);
        $this->assertSame('قيد المراجعة المالية', $cells[4]);
        $this->assertSame('سجل مالي', $cells[9]);
        $this->report('manager', 'lifecycle')->assertJsonPath('data.rows.0.cells.7', 'غير متوفر');
    }

    public function test_actual_stage_entry_exit_and_returned_cycles(): void
    {
        $this->clearCases();
        $this->travelTo('2026-10-01T10:00:00Z');
        $this->make('REQ-000001', ['status' => 'medical_review'], [
            self::ev('2026-10-01T00:00:00Z', null, 'new', 'submit'), self::ev('2026-10-01T02:00:00Z', 'new', 'returned'),
            self::ev('2026-10-01T05:00:00Z', 'returned', 'new', 'resubmit'), self::ev('2026-10-01T09:00:00Z', 'new', 'medical_review'),
        ]);
        $rows = $this->report('manager', 'lifecycle')->json('data.rows');
        $this->assertSame([0, 2, 3, 4, 1], array_map(fn ($r) => $r['cells'][7], $rows));
        $this->assertSame('مدة جارية', end($rows)['cells'][8]);
    }

    public function test_does_not_bridge_missing_or_inconsistent_transitions(): void
    {
        $this->clearCases();
        $this->make('REQ-000001', [], [self::ev('2026-10-01T01:00:00Z', 'new', 'medical_review'), self::ev('2026-10-01T05:00:00Z', 'social_review', 'manager_approval')]);
        $row = $this->report('manager', 'lifecycle')->json('data.rows.0.cells');
        $this->assertSame(['غير متوفر', 'خروج غير مسجل'], [$row[7], $row[8]]);
    }

    public function test_complete_duration_requires_an_actual_archive_event(): void
    {
        $this->clearCases();
        $this->make('REQ-000001', ['status' => 'archived'], [self::ev('2026-10-03T00:00:00Z', 'finance_review', 'archived')]);
        $this->report('manager', 'performance')->assertJsonPath('data.performance.average', 48)->assertJsonPath('data.performance.samples', 1)
            ->assertJsonPath('data.performance.months.0.name', '2026-10');
    }

    public function test_delay_only_from_actual_deadlines(): void
    {
        $this->clearCases();
        $this->travelTo('2026-10-02T00:00:00Z');
        $this->make('REQ-000001', ['status' => 'new'], [self::ev('2026-10-01T00:00:00Z', null, 'new', 'submit')]);
        $this->make('REQ-000002', ['status' => 'awaiting_quotation']);
        QuotationRound::query()->create([
            'id' => 'QR-T1', 'case_id' => 'REQ-000002', 'request_number' => 'REQ-000002', 'service' => 'x', 'specifications' => 'x', 'description' => 'x', 'quantity' => 1, 'unit' => 'x',
            'city' => 'x', 'expected_start' => '2026-10-10', 'deadline' => '2026-10-01T00:00:00Z', 'shared_attachments' => [], 'status' => 'open', 'created_by' => 'x', 'created_by_id' => 'u1', 'history' => [],
        ]);
        $details = collect($this->report('manager', 'performance')->json('data.performance.details'))->keyBy('caseId');
        $this->assertSame(['delayed' => false, 'deadline' => null, 'elapsed' => 24], $details['REQ-000001']['delay']);
        $this->assertTrue($details['REQ-000002']['delay']['delayed']);
        $rows = collect($this->asRole('manager')->getJson('/api/v1/reports/performance/export')->assertOk()->json('data.sheets.1.rows'))->keyBy(0);
        $this->assertSame('غير قابل للقياس', $rows['REQ-000001'][8]);
        $this->assertSame('متأخر بموعد محدد', $rows['REQ-000002'][8]);
    }

    public function test_date_service_status_city_and_provider_filters_apply_together(): void
    {
        $this->clearCases();
        $this->make('REQ-000001', ['service' => 'nursing', 'city' => 'Riyadh', 'provider_id' => 'PRV-0005', 'status' => 'new']);
        $this->make('REQ-000002', ['city' => 'Jeddah']);
        $q = ['service' => 'nursing', 'city' => 'Riyadh', 'provider' => 'PRV-0005', 'status' => 'new', 'from' => '2026-10-01', 'to' => '2026-10-01'];
        $this->assertSame(['REQ-000001'], array_column($this->report('manager', 'requests', $q)->json('data.rows'), 'caseId'));
        $this->report('manager', 'requests', array_merge($q, ['from' => '2026-10-02', 'to' => '2026-10-03']))->assertJsonCount(0, 'data.rows');
        $this->report('manager', 'requests', ['from' => '2026-10-05', 'to' => '2026-10-01'])->assertStatus(422)->assertJsonPath('message', 'تاريخ البداية يجب أن يسبق تاريخ النهاية');
        $this->report('manager', 'kpis', ['city' => 'Riyadh'])->assertJsonPath('data.0.value', 1);
        $options = $this->report('manager', 'options')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Riyadh', 'Jeddah'], $options['cities']);
        $this->assertSame([['id' => 'PRV-0005', 'name' => 'مركز عناية للرعاية المنزلية']], $options['providers']);
    }

    public function test_no_report_access_for_providers_beneficiaries_or_staff_without_reports_view(): void
    {
        $this->report('provider', 'requests')->assertForbidden();
        $this->report('beneficiary', 'requests')->assertForbidden();
        $this->report('team', 'requests')->assertForbidden()->assertJsonPath('message', 'محجوب حسب الصلاحية');
        $this->report('team', 'options')->assertForbidden();
    }

    public function test_assigned_case_scope_is_enforced(): void
    {
        $this->clearCases();
        $this->grant('manager', ['cases.view' => 'ASSIGNED']);
        $this->make('REQ-000001', ['assignee' => 'خالد الدوسري']);
        $this->make('REQ-000002', ['assignee' => 'other']);
        $this->assertSame(['REQ-000001'], array_column($this->report('manager', 'requests')->json('data.rows'), 'caseId'));
    }

    public function test_masks_beneficiary_names_and_comments_without_permission(): void
    {
        $this->clearCases();
        $c = $this->make('REQ-000001', ['evaluation' => ['rating' => 4, 'comment' => 'private comment', 'at' => '2026-10-01']]);
        $this->assertSame('محجوب حسب الصلاحية', $this->report('executive', 'requests')->json('data.rows.0.cells.1'));
        $this->assertNotContains('private comment', $this->report('executive', 'ratings')->json('data.rows.0.cells'));
        $this->assertSame('محجوب حسب الصلاحية', $this->report('executive', 'beneficiaries')->json('data.rows.0.cells.0'));
        $this->assertSame($c->beneficiary, $this->report('manager', 'requests')->json('data.rows.0.cells.1'));
        $this->assertSame('private comment', $this->report('manager', 'ratings')->json('data.rows.0.cells.7'));
        // Invoice totals need finance.ledger.view; lifecycle needs workflow.history.view.
        $this->assertSame('محجوب حسب الصلاحية', $this->report('manager', 'services')->json('data.rows.0.cells.8'));
        $this->grant('manager', ['workflow.history.view' => null]);
        $this->report('manager', 'lifecycle')->assertJsonPath('data.rows.0.cells', ['سجل دورة الطلب', 'محجوب حسب الصلاحية']);
    }

    public function test_ratings_use_existing_evaluations_only(): void
    {
        $this->clearCases();
        $this->make('REQ-000001', ['provider_id' => 'PRV-0005', 'evaluation' => ['rating' => 4, 'comment' => '', 'at' => '2026-10-01'], 'request_survey' => ['ease' => 1, 'clarity' => 2, 'comment' => '', 'at' => '2026-10-01']]);
        $this->make('REQ-000002', ['provider_id' => 'PRV-0005', 'evaluation' => ['rating' => 2, 'comment' => '', 'at' => '2026-10-01']]);
        $row = $this->report('manager', 'providers')->json('data.rows.0.cells');
        $this->assertSame([3, 2], [$row[5], $row[6]]);
        $this->report('manager', 'ratings')->assertJsonCount(3, 'data.rows');
    }

    public function test_exports_every_kind_with_sheets_and_dated_filename(): void
    {
        $this->clearCases();
        $this->fixture();
        $this->travelTo('2026-10-04T00:00:00Z');
        foreach (['requests', 'beneficiaries', 'services', 'providers', 'appointments', 'cases', 'ratings'] as $kind) {
            $d = $this->asRole('manager')->getJson("/api/v1/reports/$kind/export")->assertOk()->json('data');
            $this->assertCount(1, $d['sheets'], $kind);
            $this->assertSame($d['table'], $d['sheets'][0], $kind);
            $this->assertCount(count($d['table']['rows'][0]), $d['table']['columns'], $kind);
            $this->assertStringStartsWith('RAHA_', $d['filename']);
        }
        $d = $this->getJson('/api/v1/reports/performance/export?format=csv')->assertOk()->json('data');
        $this->assertSame('RAHA_Performance_2026-10-04.csv', $d['filename']);
        $this->assertSame(['ملخص الأداء', 'تفاصيل أداء الطلبات', 'أداء الأقسام', 'متابعة المراحل'], array_column($d['sheets'], 'name'));
        $this->assertSame($d['sheets'][1]['rows'], $d['table']['rows']); // CSV = the detailed table
        $detail = $d['sheets'][1]['rows'];
        $this->assertSame('REQ-000007', $detail[0][0]);
        $this->assertSame(48, $detail[0][6]);
        $this->assertSame(48, collect($detail)->firstWhere(10, 'التدقيق')[13]);
        $this->assertSame('RAHA_Requests_2026-10-04.xlsx', $this->getJson('/api/v1/reports/requests/export')->json('data.filename'));
        $this->assertSame('=unsafe', $this->getJson('/api/v1/reports/ratings/export')->json('data.sheets.0.rows.0.7')); // the browser neutralizes formulas (safeCell)
    }

    public function test_missing_performance_times_are_not_invented(): void
    {
        $this->clearCases();
        $this->make('REQ-000007', ['status' => 'archived']);
        $row = $this->asRole('manager')->getJson('/api/v1/reports/performance/export')->json('data.sheets.1.rows.0');
        $this->assertSame(['غير متوفر', 'غير متوفر'], [$row[6], $row[11]]);
    }

    public function test_exports_all_filtered_rows_not_a_page(): void
    {
        $this->clearCases();
        foreach (range(0, 14) as $i) {
            $this->make(sprintf('REQ-%06d', $i), ['city' => $i < 12 ? 'Riyadh' : 'Jeddah']);
        }
        $d = $this->asRole('manager')->getJson('/api/v1/reports/requests/export?city=Riyadh')->assertOk()->json('data');
        $this->assertCount(12, $d['table']['rows']);
        $this->report('manager', 'requests', ['city' => 'Riyadh'])->assertJsonPath('data.total', 12);
    }

    public function test_export_permissions_scope_and_empty_datasets(): void
    {
        $this->asRole('finance')->getJson('/api/v1/reports/requests/export')->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية تصدير التقارير.');
        $this->asRole('auditor')->getJson('/api/v1/reports/requests/export')->assertForbidden();
        $this->asRole('executive')->getJson('/api/v1/reports/requests/export')->assertOk(); // executive holds reports.export
        $this->grant('manager', ['workflow.history.view' => null]);
        $this->asRole('manager')->getJson('/api/v1/reports/lifecycle/export')->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية عرض سجل دورة الطلب.');
        $this->grant('manager', ['reports.export' => 'ASSIGNED']);
        $this->asRole('manager')->getJson('/api/v1/reports/requests/export')->assertForbidden()->assertJsonPath('message', 'نطاق التصدير لا يشمل جميع نتائج التصفية.');
        $this->clearCases();
        $this->asRole('admin')->getJson('/api/v1/reports/requests/export')->assertStatus(422)->assertJsonPath('message', 'لا توجد بيانات مطابقة للتصفية');
    }

    public function test_reports_are_read_only(): void
    {
        $before = RahaCase::query()->orderBy('id')->get()->map->getAttributes()->all();
        foreach (['requests', 'performance', 'lifecycle'] as $kind) {
            $this->report('manager', $kind)->assertOk();
        }
        $this->assertEquals($before, RahaCase::query()->orderBy('id')->get()->map->getAttributes()->all());
    }
}
