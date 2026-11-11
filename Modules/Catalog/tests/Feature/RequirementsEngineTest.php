<?php

namespace Modules\Catalog\Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Services\RequirementsEngine as R;
use Tests\TestCase;

class RequirementsEngineTest extends TestCase
{
    public function test_report_date_and_file_rules(): void
    {
        $today = Carbon::parse('2026-11-09 10:00');
        $this->assertSame('يرجى إدخال تاريخ التقرير الطبي.', R::reportDateError(null, 5, $today));
        $this->assertSame('لا يمكن أن يكون تاريخ التقرير الطبي في المستقبل.', R::reportDateError('2026-11-10', 5, $today));
        $this->assertSame('يجب أن يكون تاريخ التقرير الطبي خلال آخر 5 أيام.', R::reportDateError('2026-11-03', 5, $today));
        $this->assertSame('', R::reportDateError('2026-11-04', 5, $today));
        $this->assertSame('', R::fileError('report.PDF', 'application/pdf', 1000));
        $this->assertSame(R::FILE_TYPE_MSG, R::fileError('x.svg', 'image/svg+xml', 10));
        $this->assertSame(R::FILE_TYPE_MSG, R::fileError('x.pdf', 'text/html', 10));
        $this->assertSame('حجم الملف أكبر من الحد المسموح (٥ ميجابايت).', R::fileError('x.png', 'image/png', 6 * 1024 * 1024));
    }

    public function test_evaluate_combines_service_and_social_rules(): void
    {
        $today = Carbon::parse('2026-11-09');
        $service = Service::find('SRV-001');
        $service->requirements = array_merge($service->requirements, ['extraDocs' => [['id' => 'plan', 'label' => 'خطة علاجية', 'required' => true, 'active' => true]]]);
        $social = ['housingType' => 'إيجار', 'employmentStatus' => 'متقاعد'];

        $rs = R::evaluate($service, $social, [], $today);
        $this->assertSame(['medical_report', 'svc:plan', 'rental_contract'], array_column($rs, 'docType'));
        $this->assertSame('يرجى إرفاق التقرير الطبي لإكمال الطلب.', $rs[0]['validationErrors'][0]);
        $this->assertSame('يرجى إرفاق المستند للخدمة المحددة.', $rs[1]['validationErrors'][0]);
        $this->assertSame(R::RENTAL_CONTRACT_MSG, $rs[2]['validationErrors'][0]);
        $this->assertCount(3, R::missingRequired($rs));
        $this->assertSame(['required' => 3, 'complete' => 0, 'missing' => 3], R::summary($rs));
        $this->assertContains('salary_certificate', R::inactiveSocialTypes($rs));

        $docs = [
            ['docType' => 'medical_report', 'reportDate' => '2026-10-01', 'at' => '2026-11-09T08:00:00.000Z'],
            ['docType' => 'svc:plan', 'at' => '2026-11-09T08:00:00.000Z'],
            ['docType' => 'rental_contract', 'stale' => true, 'at' => '2026-11-09T08:00:00.000Z'],
            ['docType' => 'salary_certificate', 'at' => '2026-11-09T08:00:00.000Z'],
        ];
        $rs = R::evaluate($service, $social, $docs, $today);
        $this->assertSame('يجب أن يكون تاريخ التقرير الطبي خلال آخر 5 أيام.', $rs[0]['validationErrors'][0]);
        $this->assertTrue($rs[1]['satisfied']);
        $this->assertSame(R::STALE_FILE_MSG, $rs[2]['validationErrors'][0]);
        // a report uploaded before dateCheckAfter is not re-validated
        $this->assertTrue(R::evaluate($service, $social, $docs, $today, ['dateCheckAfter' => '2026-11-10T00:00:00.000Z'])[0]['satisfied']);
        $this->assertSame(['salary_certificate'], array_column(R::notRequiredDocs($rs, $docs), 'docType'));
        $this->assertSame(['docType' => 'medical_report', 'label' => 'التقرير الطبي', 'required' => true, 'reason' => 'مطلوب للخدمة الصحية المحددة. يجب أن يكون بتاريخ خلال آخر 5 أيام.'], R::snapshot($rs)[0]);
    }

    public function test_housing_document_errors_ignore_internal_and_map_categories(): void
    {
        $social = ['housingType' => 'إيجار'];
        $this->assertSame([R::RENTAL_CONTRACT_MSG], R::housingDocumentErrors($social, [['category' => 'عقد الإيجار', 'visibility' => 'internal', 'at' => 'x']]));
        $this->assertSame([], R::housingDocumentErrors($social, [['category' => 'عقد الإيجار', 'visibility' => 'both', 'at' => 'x']]));
        $this->assertSame([], R::housingDocumentErrors(['housingType' => 'ملك'], []));
        $this->assertSame([], R::evaluate(null, [], []));
    }
}
