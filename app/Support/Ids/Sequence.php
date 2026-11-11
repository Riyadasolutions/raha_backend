<?php

namespace App\Support\Ids;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe business numbers (replaces the front's count+1 / max+1 generators).
 * Must run inside the caller's transaction so a rolled-back write does not consume... (gaps are acceptable).
 */
class Sequence
{
    public static function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('sequences')->where('key', $key)->lockForUpdate()->first();
            if (! $row) {
                DB::table('sequences')->insert(['key' => $key, 'value' => 1]);

                return 1;
            }
            DB::table('sequences')->where('key', $key)->update(['value' => $row->value + 1]);

            return $row->value + 1;
        });
    }

    /** Ensure the sequence is at least $value (used by seeders after inserting fixed ids). */
    public static function bump(string $key, int $value): void
    {
        $current = (int) DB::table('sequences')->where('key', $key)->value('value');
        if ($value > $current) {
            DB::table('sequences')->updateOrInsert(['key' => $key], ['value' => $value]);
        }
    }

    public static function formatted(string $key, string $prefix, int $pad): string
    {
        return $prefix.str_pad((string) self::next($key), $pad, '0', STR_PAD_LEFT);
    }

    public static function caseId(): string
    {
        return self::formatted('case', 'REQ-', 6);
    }

    public static function serviceId(): string
    {
        return self::formatted('service', 'SRV-', 3);
    }

    public static function providerId(): string
    {
        return self::formatted('provider', 'PRV-', 4);
    }

    public static function beneficiaryId(): string
    {
        return self::formatted('beneficiary', 'BEN-', 4);
    }

    public static function walletId(): string
    {
        return self::formatted('wallet', 'WAL-', 3);
    }

    public static function roundId(): string
    {
        return self::formatted('quotation_round', 'QR-', 5);
    }

    public static function assignmentId(): string
    {
        return self::formatted('assignment', 'ASG-', 5);
    }

    public static function ledgerId(): string
    {
        return self::formatted('ledger', 'LED-', 5);
    }

    /** AUTH-<year>-00001, counter resets each year. */
    public static function authorizationNumber(): string
    {
        $year = now()->year;

        return "AUTH-{$year}-".str_pad((string) self::next("authorization_$year"), 5, '0', STR_PAD_LEFT);
    }
}
