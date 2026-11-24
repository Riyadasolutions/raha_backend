<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Dev-only behaviour (quick sign-in, `debug` OTP/token echo, plaintext SMS in logs) and OTP send limits. */
class DevMode
{
    /** The flag can only enable dev mode on a local/testing environment. Any other APP_ENV is always off. */
    public static function enabled(?string $appEnv, ?bool $flag): bool
    {
        return in_array($appEnv, ['local', 'testing'], true) && ($flag ?? true);
    }

    /** True once `$subject` already received the maximum number of codes of this purpose within the last hour. */
    public static function sendLimitReached(string $subject, string $purpose): bool
    {
        return DB::table('otp_codes')->where('user_id', $subject)->where('purpose', $purpose)->where('created_at', '>=', now()->subHour())->count()
            >= (int) config('raha.otp.max_sends_per_hour');
    }

    /**
     * Stand-in for the SMS gateway. Until a gateway is configured nothing is delivered; the code is written to the log
     * only in dev mode, never in any other environment.
     */
    public static function sms(string $mobile, string $text, string $code): void
    {
        if (config('raha.dev_accounts')) {
            Log::info("[SMS] to {$mobile}: {$text}: {$code}");

            return;
        }
        Log::warning('[SMS] gateway not configured; message not delivered.');
    }
}
