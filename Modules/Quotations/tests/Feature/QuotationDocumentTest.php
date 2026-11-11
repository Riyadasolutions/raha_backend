<?php

namespace Modules\Quotations\Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use Modules\Catalog\Models\Provider;
use Modules\Catalog\Services\ProviderBranding;
use Modules\Quotations\Models\ProviderQuotation;

/** Port of front quotation_document.test.tsx (Task 13): document model, branding snapshot, access, read-only. */
class QuotationDocumentTest extends QuotationsTestCase
{
    private const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $round;

    protected function setUp(): void
    {
        parent::setUp();
        $this->round = $this->open3();
    }

    private function docQuote(array $extra = []): array
    {
        return $this->quote(200, array_merge(['description' => 'زيارات تمريض منزلي', 'notes' => 'ملاحظة المزود', 'lineItems' => [
            ['description' => 'زيارة تمريض', 'quantity' => 10, 'unit' => 'زيارة', 'unitPrice' => 20000, 'discountAmount' => 0],
            ['description' => 'مستلزمات', 'quantity' => 1, 'unit' => 'حزمة', 'unitPrice' => 15050, 'discountAmount' => 1000],
        ]], $extra));
    }

    private function doc(string $qid, string $lang = 'ar')
    {
        return $this->getJson("/api/v1/quotations/$qid/document?lang=$lang");
    }

    public function test_model_uses_the_stored_quotation(): void
    {
        $q = $this->submitAs(self::A, $this->round, $this->docQuote())->assertOk()->json('data.quotation');
        $d = $this->doc($q['id'])->assertOk()->json('data');
        $m = $d['model'];
        $this->assertSame([$q['number'], self::CASE_ID, $this->round, 'submitted', substr($q['submittedAt'], 0, 10), $q['validUntil'], 'تمريض منزلي'],
            [$m['number'], $m['requestRef'], $m['roundRef'], $m['status'], $m['issueDate'], $m['validUntil'], $m['service']]);
        $this->assertSame([['زيارة تمريض', 10, 'زيارة', 20000, 200000], ['مستلزمات', 1, 'حزمة', 15050, 14050]],
            array_map(fn ($i) => [$i['description'], $i['quantity'], $i['unit'], $i['unitPrice'], $i['net']], $m['items']));
        $this->assertSame(['subtotal' => 215050, 'discountTotal' => 1000, 'taxTotal' => 0, 'grandTotal' => 214050, 'currency' => 'SAR'],
            array_intersect_key($m['summary'], array_flip(['subtotal', 'discountTotal', 'taxTotal', 'grandTotal', 'currency'])));
        $this->assertSame("RAHA_Quotation_{$q['number']}.pdf", $d['filename']);
        $this->assertSame([], $m['problems']);
        $this->assertSame('en', $this->doc($q['id'], 'en')->json('data.lang'));
    }

    public function test_standard_tax_from_the_stored_rate(): void
    {
        $q = $this->submitAs(self::A, $this->round, $this->quote(100, ['taxTreatment' => 'standard', 'taxRate' => 15]))->json('data.quotation');
        $this->doc($q['id'], 'en')->assertJsonPath('data.model.summary.taxTotal', 15000)->assertJsonPath('data.model.summary.grandTotal', 115000)
            ->assertJsonPath('data.model.summary.taxTreatment', 'standard');
    }

    public function test_branding_snapshot_and_unsafe_logos(): void
    {
        $this->assertFalse(ProviderBranding::isSafeLogo('data:image/svg+xml;base64,PHN2Zz4='));
        $this->assertTrue(ProviderBranding::isSafeLogo(self::LOGO));
        Provider::query()->whereKey(self::A)->update(['logo' => self::LOGO]);
        $old = Provider::find(self::A)->name;
        $q = $this->submitAs(self::A, $this->round)->json('data.quotation');
        $this->assertSame(self::LOGO, ProviderQuotation::find($q['id'])->branding['logo']);
        Provider::query()->whereKey(self::A)->update(['name' => 'اسم جديد للمنشأة', 'phone' => '0111111111', 'logo' => null]);
        $m = $this->doc($q['id'])->json('data.model');
        $this->assertSame(['snapshot', $old, self::LOGO], [$m['brandingSource'], $m['provider']['name'], $m['provider']['logo']]);

        Provider::query()->whereKey(self::B)->update(['logo' => 'data:image/png;base64,AAAA" onerror="alert(1)']);
        $qb = $this->submitAs(self::B, $this->round)->json('data.quotation');
        $this->assertStringNotContainsString('onerror', $this->doc($qb['id'])->getContent());
        $this->assertArrayNotHasKey('logo', $this->doc($qb['id'])->json('data.model.provider'));
    }

    public function test_drafts_and_older_records_use_the_current_profile(): void
    {
        $draft = $this->draftAs(self::A, $this->round, $this->quote(100, ['lineItems' => [], 'description' => '']))->json('data.quotation');
        $m = $this->doc($draft['id'])->assertOk()->json('data.model');
        $this->assertSame(['draft', 'current_profile'], [$m['status'], $m['brandingSource']]);
        $this->assertNotEmpty($m['problems']);
        $this->assertContains('لا توجد بنود تسعير في عرض السعر.', $m['problems']);

        $q = $this->submitAs(self::B, $this->round)->json('data.quotation');
        ProviderQuotation::query()->whereKey($q['id'])->update(['branding' => null]);
        Provider::query()->whereKey(self::B)->update(['phone' => '0122222222']);
        $this->doc($q['id'])->assertJsonPath('data.model.brandingSource', 'current_profile')->assertJsonPath('data.model.provider.phone', '0122222222');
    }

    public function test_excludes_internal_evaluations_and_identity(): void
    {
        $q = $this->submitAs(self::A, $this->round)->json('data.quotation');
        $this->asRole('medical')->postJson("/api/v1/quotation-rounds/{$this->round}/close")->assertOk();
        $this->postJson("/api/v1/quotation-rounds/{$this->round}/quotations/{$q['id']}/evaluations", ['compliance' => 'compliant', 'notes' => 'سري-داخلي', 'reasons' => 'سبب-داخلي', 'recommendation' => 'توصية-داخلية'])->assertOk();
        $c = \Modules\Cases\Models\RahaCase::find(self::CASE_ID);
        foreach (['provider', 'medical'] as $role) {
            $json = $this->asRole($role)->doc($q['id'])->assertOk()->getContent();
            foreach (['سري-داخلي', 'سبب-داخلي', 'توصية-داخلية', $c->beneficiary, $c->national_id] as $secret) {
                $this->assertStringNotContainsString(json_encode($secret), $json);
            }
        }
    }

    public function test_access_control(): void
    {
        $a = $this->submitAs(self::A, $this->round)->json('data.quotation');
        $b = $this->submitAs(self::B, $this->round)->json('data.quotation');
        $draft = $this->draftAs(self::C, $this->round)->json('data.quotation');
        $this->asRole('provider')->doc($a['id'])->assertOk()->assertJsonPath('data.model.number', $a['number']);
        $this->doc($b['id'])->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية عرض عرض السعر هذا.');
        $this->asRole('provider2')->doc($a['id'])->assertForbidden();
        foreach (['medical', 'manager'] as $role) {
            $this->asRole($role)->doc($a['id'])->assertOk()->assertJsonPath('data.model.number', $a['number']);
        }
        $this->asRole('medical')->doc($draft['id'])->assertForbidden();
        $this->doc('Q-nope')->assertForbidden();
        foreach (['auditor', 'social', 'beneficiary'] as $role) {
            $this->asRole($role)->doc($a['id'])->assertForbidden();
        }
    }

    public function test_document_is_read_only(): void
    {
        $q = $this->submitAs(self::A, $this->round)->json('data.quotation');
        $draft = $this->draftAs(self::B, $this->round)->json('data.quotation');
        $before = [AuditLog::query()->count(), Notification::query()->count(), ProviderQuotation::query()->get()->map->toFront()->all()];
        for ($i = 0; $i < 3; $i++) {
            $this->asRole('provider')->doc($q['id']);
            $this->asRole('medical')->doc($q['id'], 'en');
            $this->asRole('provider2')->doc($draft['id']);
        }
        $this->assertSame($before, [AuditLog::query()->count(), Notification::query()->count(), ProviderQuotation::query()->get()->map->toFront()->all()]);
        $this->assertSame('draft', ProviderQuotation::find($draft['id'])->status);
    }
}
