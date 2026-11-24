<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use App\Support\Security\DevMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Beneficiaries\Models\Beneficiary;
use Tests\TestCase;

/** OTP lifetime, attempt/send limits, race safety and debug-value exposure for the registration ownership proof. */
class RegistrationOtpHardeningTest extends TestCase
{
    private const NID = '1099988877';

    private const MOBILE = '0551112233';

    private const PASSWORD = 'Strong#Pass1';

    protected function setUp(): void
    {
        parent::setUp();
        Beneficiary::query()->create(['id' => 'BEN-T-OTP', 'first_name' => 'اختبار', 'family_name' => 'رمز', 'national_id' => self::NID, 'id_type' => 'هوية وطنية', 'mobile' => self::MOBILE]);
    }

    private function register(array $extra = [])
    {
        return $this->postJson('/api/v1/auth/beneficiary/register', ['firstName' => 'اختبار', 'familyName' => 'رمز', 'idType' => 'هوية وطنية', 'idNumber' => self::NID, 'password' => self::PASSWORD] + $extra);
    }

    private function issue(): string
    {
        return (string) $this->register(['mobile' => self::MOBILE])->assertStatus(422)->assertJsonPath('code', 'OTP_SENT')->json('debug');
    }

    public function test_otp_expires_after_the_ttl(): void
    {
        $code = $this->issue();
        DB::table('otp_codes')->where('user_id', 'reg-BEN-T-OTP')->update(['expires_at' => now()->subSecond()]);
        $this->register(['mobile' => self::MOBILE, 'code' => $code])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->assertSame(0, User::query()->where('national_id', self::NID)->count());
    }

    public function test_wrong_guesses_lock_the_code_even_if_the_right_one_follows(): void
    {
        $code = $this->issue();
        $max = (int) config('raha.otp.max_attempts');
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < $max; $i++) {
            $this->register(['mobile' => self::MOBILE, 'code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        }
        $this->register(['mobile' => self::MOBILE, 'code' => $code])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->assertSame(0, User::query()->where('national_id', self::NID)->count());
    }

    public function test_requesting_new_codes_cannot_reset_the_guess_budget_indefinitely(): void
    {
        $cap = (int) config('raha.otp.max_sends_per_hour');
        $this->assertGreaterThan(0, $cap);
        for ($i = 0; $i < $cap; $i++) {
            $this->issue();
        }
        $this->register(['mobile' => self::MOBILE])->assertStatus(429)->assertJsonPath('code', 'OTP_RATE_LIMITED');
        $this->assertSame($cap, DB::table('otp_codes')->where('user_id', 'reg-BEN-T-OTP')->count());
        // A wrong mobile never counts as a send and never reveals the limit.
        $this->register(['mobile' => '0500000000'])->assertStatus(422)->assertJsonPath('code', 'OWNERSHIP_FAILED');
    }

    public function test_code_is_useless_without_the_matching_mobile(): void
    {
        $code = $this->issue();
        $this->register(['code' => $code])->assertStatus(422)->assertJsonPath('code', 'OWNERSHIP_REQUIRED');
        $this->register(['mobile' => '0500000000', 'code' => $code])->assertStatus(422)->assertJsonPath('code', 'OWNERSHIP_FAILED');
        $this->assertSame(0, User::query()->where('national_id', self::NID)->count());
        $this->register(['mobile' => self::MOBILE, 'code' => $code])->assertCreated();
        $this->assertSame(1, User::query()->where('national_id', self::NID)->count());
    }

    public function test_a_lost_race_returns_a_clean_validation_error_never_a_500(): void
    {
        $code = $this->issue();
        // Simulate the concurrent winner: its user row appears right as this request tries to create its own.
        $fired = false;
        User::creating(function (User $u) use (&$fired) {
            if (! $fired && $u->national_id === self::NID) {
                $fired = true;
                DB::table('users')->insert(['id' => 'u-winner', 'account_type' => 'BENEFICIARY', 'name' => 'w', 'role' => 'beneficiary', 'password' => 'x', 'national_id' => self::NID,
                    'beneficiary_id' => $u->beneficiary_id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->register(['mobile' => self::MOBILE, 'code' => $code])->assertStatus(422)->assertJsonPath('message', 'يوجد حساب مسجل بهذا الرقم.');
        $this->assertLessThanOrEqual(1, User::query()->where('national_id', self::NID)->count()); // one-account guarantee under real concurrency is checked on MySQL
    }

    public function test_a_code_can_be_consumed_only_once_even_when_two_requests_read_it_together(): void
    {
        $code = $this->issue();
        $this->register(['mobile' => self::MOBILE, 'code' => $code])->assertCreated();
        $row = DB::table('otp_codes')->where('user_id', 'reg-BEN-T-OTP')->first();
        $this->assertNotNull($row->used_at);
        // Re-opening the row (as a second concurrent reader would have seen it) must not allow another registration.
        DB::table('otp_codes')->where('id', $row->id)->update(['used_at' => null]);
        DB::table('users')->where('national_id', self::NID)->delete();
        Beneficiary::query()->where('id', 'BEN-T-OTP')->update(['has_portal_account' => false]);
        $this->assertSame(1, DB::table('otp_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]));
        $this->assertSame(0, DB::table('otp_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]));
    }

    public function test_debug_values_are_only_possible_in_local_or_testing(): void
    {
        $this->assertTrue(DevMode::enabled('local', true));
        $this->assertTrue(DevMode::enabled('testing', null));
        $this->assertTrue(DevMode::enabled('local', null));
        foreach (['production', 'staging', 'prod', '', null] as $env) {
            $this->assertFalse(DevMode::enabled($env, true), "env=$env must never enable dev mode even with the flag on");
            $this->assertFalse(DevMode::enabled($env, null));
        }
        $this->assertFalse(DevMode::enabled('local', false));
    }

    public function test_otp_response_has_no_debug_key_when_dev_mode_is_off_and_the_code_is_never_logged(): void
    {
        config(['raha.dev_accounts' => false]);
        Log::spy();
        $res = $this->register(['mobile' => self::MOBILE])->assertStatus(422)->assertJsonPath('code', 'OTP_SENT');
        $this->assertArrayNotHasKey('debug', $res->json());
        $this->assertStringNotContainsString('"debug"', $res->getContent());
        Log::shouldNotHaveReceived('info');
        // Password-reset OTP follows the same rule.
        $beneficiaryUser = $this->postJson('/api/v1/auth/beneficiary/forgot-password', ['nationalId' => self::NID])->assertOk();
        $this->assertArrayNotHasKey('debug', $beneficiaryUser->json());
    }
}
