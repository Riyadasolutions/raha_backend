<?php

namespace Modules\Auth\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Security\SecurityLog;
use App\Support\Security\Sessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Real password recovery (the mock only showed screens):
 *  - staff/provider: emailed reset token (password_reset_tokens, 60 min);
 *  - beneficiary: 6-digit OTP to the registered mobile (otp_codes, SMS through the log channel until a gateway exists).
 * Responses are always neutral so they never reveal whether an account exists.
 * In dev (config raha.dev_accounts) the token / code is echoed back as `debug` for testing.
 */
class PasswordRecoveryService
{
    public const NEUTRAL = 'إذا كانت البيانات مرتبطة بحساب، فستصلك تعليمات استعادة كلمة المرور.';

    public function __construct(private readonly AuthService $auth) {}

    public function requestStaff(string $email): array
    {
        $user = User::query()->whereIn('account_type', ['STAFF', 'PROVIDER'])->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        SecurityLog::log('PASSWORD_RESET_REQUESTED', $user, userName: 'طلب استعادة', details: ['note' => $user ? 'تم إرسال رابط الاستعادة' : 'بريد غير مسجل']);
        if (! $user) {
            return ['message' => self::NEUTRAL];
        }
        $token = Str::random(48);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => Hash::make($token), 'created_at' => now()]);
        Mail::raw("رمز استعادة كلمة المرور لحساب راحة: {$token}\nصالح لمدة 60 دقيقة.", fn ($m) => $m->to($user->email)->subject('استعادة كلمة المرور — راحة'));

        return ['message' => self::NEUTRAL] + (config('raha.dev_accounts') ? ['debug' => $token] : []);
    }

    public function resetStaff(string $email, string $token, string $password): void
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $row || ! Hash::check($token, $row->token) || now()->subMinutes(60)->gt($row->created_at)) {
            throw new DomainRuleException('رابط الاستعادة غير صالح أو منتهي الصلاحية.');
        }
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->complete($user);
        $this->auth->setPassword($user, $password);
        Sessions::endAllFor($user, null, "استعادة كلمة المرور");
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        SecurityLog::log('PASSWORD_CHANGED', $user, details: ['note' => 'استعادة كلمة المرور']);
    }

    public function requestBeneficiary(string $nationalId): array
    {
        $user = User::query()->where('account_type', 'BENEFICIARY')->where('national_id', mb_strtoupper(trim($nationalId)))->first();
        SecurityLog::log('PASSWORD_RESET_REQUESTED', $user, userName: 'مستفيد', details: ['note' => $user ? 'تم إرسال رمز التحقق' : 'رقم غير مسجل']);
        if (! $user) {
            return ['message' => self::NEUTRAL];
        }
        if (\App\Support\Security\DevMode::sendLimitReached($user->id, 'password_reset')) {
            return ['message' => self::NEUTRAL]; // neutral on purpose: no code is sent and the limit is not revealed
        }
        $code = (string) random_int(100000, 999999);
        DB::table('otp_codes')->where('user_id', $user->id)->where('purpose', 'password_reset')->whereNull('used_at')->update(['used_at' => now()]);
        DB::table('otp_codes')->insert([
            'user_id' => $user->id, 'purpose' => 'password_reset', 'code_hash' => Hash::make($code), 'attempts' => 0,
            'expires_at' => now()->addMinutes((int) config('raha.otp.ttl_minutes')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\Security\DevMode::sms((string) $user->mobile, 'رمز استعادة كلمة المرور في راحة', $code);

        return ['message' => self::NEUTRAL] + (config('raha.dev_accounts') ? ['debug' => $code] : []);
    }

    public function resetBeneficiary(string $nationalId, string $code, string $password): void
    {
        $user = User::query()->where('account_type', 'BENEFICIARY')->where('national_id', mb_strtoupper(trim($nationalId)))->first();
        $otp = $user ? DB::table('otp_codes')->where('user_id', $user->id)->where('purpose', 'password_reset')->whereNull('used_at')->latest('id')->first() : null;
        if (! $otp || now()->gt($otp->expires_at) || $otp->attempts >= (int) config('raha.otp.max_attempts')) {
            throw new DomainRuleException('رمز التحقق غير صالح أو منتهي الصلاحية.');
        }
        if (! Hash::check($code, $otp->code_hash)) {
            DB::table('otp_codes')->where('id', $otp->id)->increment('attempts');
            throw new DomainRuleException('رمز التحقق غير صحيح.');
        }
        $this->complete($user);
        $this->auth->setPassword($user, $password);
        Sessions::endAllFor($user, null, "استعادة كلمة المرور");
        DB::table('otp_codes')->where('id', $otp->id)->update(['used_at' => now()]);
        SecurityLog::log('PASSWORD_CHANGED', $user, details: ['note' => 'استعادة كلمة المرور']);
    }

    /** A successful recovery also clears a temporary lock. */
    private function complete(User $user): void
    {
        if ($user->locked_at) {
            $user->forceFill(['locked_at' => null, 'locked_until' => null, 'failed_attempts' => 0])->save();
        }
    }
}
