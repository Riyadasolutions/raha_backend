<?php

namespace Modules\Authorizations\Services;

use App\Models\Setting;

/** Association identity used on letters (front organization_service). Stored in Setting 'organization'; nothing is invented. */
class OrganizationIdentity
{
    public const DEFAULT = [
        'nameAr' => 'جمعية الرعاية الصحية المنزلية – راحة', 'nameEn' => 'Home Healthcare Charity – RAHA', 'address' => '', 'phone' => '', 'email' => '', 'registrationNumber' => '',
    ];

    public const TEXT_KEYS = ['nameAr', 'nameEn', 'address', 'phone', 'email', 'registrationNumber'];

    /** Frozen copy for an issued document (front organizationService.snapshot: identity minus updatedAt/updatedBy). */
    public static function snapshot(): array
    {
        $v = array_merge(self::DEFAULT, array_filter((array) Setting::read('organization', []), fn ($x) => $x !== null));
        unset($v['updatedAt'], $v['updatedBy']);
        foreach (self::TEXT_KEYS as $k) {
            $v[$k] = (string) ($v[$k] ?? '');
        }

        return $v;
    }

    /** front isSafeLogo */
    public static function isSafeLogo(mixed $v): bool
    {
        return is_string($v) && strlen($v) <= 280000 && preg_match('#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/]+={0,2}$#', $v) === 1;
    }
}
