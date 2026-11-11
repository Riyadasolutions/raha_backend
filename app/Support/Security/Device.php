<?php

namespace App\Support\Security;

/** Device / browser / IP of the current request (front deviceInfo()). */
class Device
{
    /** @return array{device: string, browser: string, ip: string} */
    public static function fromRequest(): array
    {
        $request = request();
        $ua = (string) $request?->userAgent();
        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'جهاز غير معروف',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'Postman') => 'Postman',
            default => $ua === '' ? 'غير معروف' : 'متصفح',
        };

        return ['device' => $device, 'browser' => $browser, 'ip' => (string) ($request?->ip() ?? '')];
    }
}
