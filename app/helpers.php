<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

if (! function_exists('iso')) {
    /** ISO-8601 UTC string with milliseconds, matching `new Date().toISOString()` on the frontend. */
    function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $date->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}

if (! function_exists('now_iso')) {
    function now_iso(): string
    {
        return iso(now());
    }
}

if (! function_exists('uid')) {
    /** Short random id, same shape as the frontend uid(). */
    function uid(int $length = 8): string
    {
        return substr(bin2hex(random_bytes(8)), 0, $length);
    }
}

if (! function_exists('raha')) {
    /** Read a value from the shared RAHA configuration JSON (database/seed-data/<file>.json). */
    function raha(string $key, mixed $default = null): mixed
    {
        return \App\Support\Raha::get($key, $default);
    }
}

if (! function_exists('ar_date')) {
    /** "٠٩ نوفمبر ٢٠٢٦" — same output as the frontend fmtDate (ar-SA, gregorian, arabic digits). */
    function ar_date(mixed $value = null): string
    {
        $date = $value === null ? now() : ($value instanceof CarbonInterface ? $value : Carbon::parse($value));
        $fmt = new IntlDateFormatter('ar-SA@calendar=gregorian;numbers=arab', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'dd MMMM y');

        return (string) $fmt->format($date->getTimestamp());
    }
}
