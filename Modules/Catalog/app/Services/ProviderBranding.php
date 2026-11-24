<?php

namespace Modules\Catalog\Services;

use Modules\Catalog\Models\Provider;

/** Port of front provider_branding.ts. Only small PNG/JPEG data URLs are accepted as logos (SVG can carry scripts). */
class ProviderBranding
{
    public const MAX_LOGO_CHARS = 280000;

    public static function isSafeLogo(mixed $v): bool
    {
        // Same rule as the organization images: size, data-URI shape AND the real image signature.
        return \Modules\Settings\Services\SettingsService::isSafeLogo($v);
    }

    /** Branding captured when a quotation is submitted (front snapshotBranding). */
    public static function snapshot(?Provider $p, string $at): ?array
    {
        if (! $p) {
            return null;
        }
        $out = ['name' => $p->name];
        if (self::isSafeLogo($p->logo)) {
            $out['logo'] = $p->logo;
        }

        return $out + [
            'phone' => $p->phone, 'email' => $p->email, 'address' => implode('، ', array_filter([$p->address, $p->city])),
            'registry' => $p->registry, 'license' => $p->license, 'snapshotAt' => $at,
        ];
    }
}
