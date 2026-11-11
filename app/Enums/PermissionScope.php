<?php

namespace App\Enums;

enum PermissionScope: string
{
    case None = 'NONE';
    case Own = 'OWN';
    case Assigned = 'ASSIGNED';
    case Department = 'DEPARTMENT';
    case All = 'ALL';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0, self::Own => 1, self::Assigned => 2, self::Department => 3, self::All => 4,
        };
    }

    public static function rankOf(?string $scope): int
    {
        return $scope ? (self::tryFrom($scope)?->rank() ?? 0) : 0;
    }
}
