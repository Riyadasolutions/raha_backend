<?php

namespace App\Enums;

enum AccountType: string
{
    case Staff = 'STAFF';
    case Provider = 'PROVIDER';
    case Beneficiary = 'BENEFICIARY';
}
