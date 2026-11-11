<?php

namespace Modules\Quotations\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\CaseRecorder;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;
use Tests\TestCase;

/** Shared helpers: REQ-000117 (home nursing SRV-001, Riyadh) is moved to awaiting_quotation; providers u10/u11/u12 = PRV-0005/0001/0006. */
abstract class QuotationsTestCase extends TestCase
{
    protected const CASE_ID = 'REQ-000117';

    protected const A = 'PRV-0005';

    protected const B = 'PRV-0001';

    protected const C = 'PRV-0006';

    protected const PROVIDER_USER = [self::A => 'provider', self::B => 'provider2', self::C => 'provider3'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->toQuotation(self::CASE_ID);
    }

    protected function toQuotation(string $id, array $extra = []): RahaCase
    {
        $c = RahaCase::query()->findOrFail($id);
        app(CaseRecorder::class)->touch($c, 'awaiting_quotation');
        $c->forceFill(['quotation_required' => true, 'service_id' => 'SRV-001', 'service' => 'تمريض منزلي', 'city' => 'الرياض'] + $extra)->save();

        return $c;
    }

    protected static function future(int $hours = 48): string
    {
        return iso(now()->addHours($hours));
    }

    protected function roundInput(array $extra = []): array
    {
        return array_merge(['caseId' => self::CASE_ID, 'subservice' => '', 'specifications' => 'تمريض لمريض فراش', 'description' => 'زيارات منزلية', 'quantity' => 10,
            'unit' => 'زيارة', 'city' => 'الرياض', 'area' => '', 'expectedStart' => now()->addDays(20)->toDateString(), 'deadline' => self::future(), 'providerNotes' => '', 'sharedDocIds' => []], $extra);
    }

    /** QuotationInput with amounts in halalas. */
    protected function quote(float $priceSar = 200, array $extra = []): array
    {
        return array_merge(['description' => 'زيارات تمريض', 'taxTreatment' => 'exempt', 'taxRate' => 0, 'startWithinDays' => 2, 'deliveryDuration' => '4 أسابيع',
            'validUntil' => now()->addDays(30)->toDateString(), 'notes' => '', 'attachments' => [],
            'lineItems' => [['description' => 'زيارة تمريض', 'quantity' => 10, 'unit' => 'زيارة', 'unitPrice' => (int) round($priceSar * 100), 'discountAmount' => 0]]], $extra);
    }

    /** Medical creates the round and invites the three Riyadh nursing providers in one call. */
    protected function open3(array $providers = [self::A, self::B, self::C]): string
    {
        return $this->asRole('medical')->postJson('/api/v1/quotation-rounds', $this->roundInput(['providerIds' => $providers]))
            ->assertCreated()->json('data.id');
    }

    protected function inv(string $roundId, string $providerId): string
    {
        return QuotationInvitation::query()->where('quotation_round_id', $roundId)->where('provider_id', $providerId)->value('id');
    }

    protected function submitAs(string $providerId, string $roundId, array $input = [], bool $confirmed = true)
    {
        return $this->asRole(self::PROVIDER_USER[$providerId])->postJson('/api/v1/provider/invitations/'.$this->inv($roundId, $providerId).'/quotation/submit', ($input ?: $this->quote()) + ['confirmed' => $confirmed]);
    }

    protected function draftAs(string $providerId, string $roundId, array $input = [])
    {
        return $this->asRole(self::PROVIDER_USER[$providerId])->putJson('/api/v1/provider/invitations/'.$this->inv($roundId, $providerId).'/quotation', $input ?: $this->quote());
    }

    protected function expire(string $roundId): void
    {
        QuotationRound::query()->whereKey($roundId)->update(['deadline' => now()->subSecond()]);
    }
}
