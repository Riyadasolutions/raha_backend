<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Beneficiaries\Models\Beneficiary;
use Tests\TestCase;

/** Registering with a national id that already belongs to a staff-held record needs mobile + OTP (no takeover by id alone). */
class RegistrationOwnershipTest extends TestCase
{
    private const PW = 'Str0ng#Pass1';

    private function payload(Beneficiary $b, array $extra = []): array
    {
        return ['firstName' => 'س', 'familyName' => 'ع', 'idType' => 'هوية وطنية', 'idNumber' => $b->national_id, 'password' => self::PW] + $extra;
    }

    private function victim(): Beneficiary
    {
        $b = Beneficiary::query()->where('has_portal_account', false)->whereNotNull('mobile')->where('national_id', 'like', '1%')->firstOrFail();
        $b->update(['national_id' => '1011223344', 'mobile' => '0551234567']);

        return $b->refresh();
    }

    public function test_known_national_id_alone_cannot_claim_an_existing_record(): void
    {
        $b = $this->victim();
        $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b))->assertStatus(422)->assertJsonPath('code', 'OWNERSHIP_REQUIRED')->assertJsonMissingPath('token');
        $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b, ['mobile' => '0599999999']))->assertStatus(422)->assertJsonPath('code', 'OWNERSHIP_FAILED');
        $this->assertFalse((bool) $b->refresh()->has_portal_account);
        $this->assertDatabaseMissing('users', ['national_id' => $b->national_id]);
        $this->assertSame(0, DB::table('otp_codes')->where('purpose', 'registration')->count(), 'no code is sent to a wrong mobile');
    }

    public function test_correct_mobile_then_otp_attaches_the_record_and_wrong_codes_do_not(): void
    {
        $b = $this->victim();
        $sent = $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b, ['mobile' => '٠٥٥١٢٣٤٥٦٧']))->assertStatus(422)->assertJsonPath('code', 'OTP_SENT');
        $code = $sent->json('debug');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $bad = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b, ['mobile' => '0551234567', 'code' => $bad]))->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->assertDatabaseMissing('users', ['national_id' => $b->national_id]);

        $ok = $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b, ['mobile' => '0551234567', 'code' => $code]))->assertStatus(201);
        $ok->assertJsonPath('me.accountType', 'BENEFICIARY');
        $this->assertTrue((bool) $b->refresh()->has_portal_account);
        // The code is single-use and the record can no longer be registered again.
        $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b, ['mobile' => '0551234567', 'code' => $code]))->assertStatus(422);
    }

    public function test_a_brand_new_national_id_still_registers_directly(): void
    {
        $this->postJson('/api/v1/auth/beneficiary/register', ['firstName' => 'جديد', 'familyName' => 'مستفيد', 'idType' => 'هوية وطنية', 'idNumber' => '1999888777', 'password' => self::PW])
            ->assertStatus(201)->assertJsonPath('me.accountType', 'BENEFICIARY');
    }

    public function test_a_record_without_a_mobile_on_file_cannot_be_self_claimed(): void
    {
        $b = $this->victim();
        $b->update(['mobile' => '']);
        $this->postJson('/api/v1/auth/beneficiary/register', $this->payload($b, ['mobile' => '0551234567']))->assertStatus(422)->assertJsonPath('code', 'OWNERSHIP_FAILED');
    }
}
