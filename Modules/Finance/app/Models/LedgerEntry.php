<?php

namespace Modules\Finance\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front WalletLedgerEntry. Only OPENING, FUNDING_ADDED, ADJUSTMENT, DEDUCTION affect the balance; the rest are memos. */
class LedgerEntry extends Model
{
    use FrontSerializable;

    protected $table = 'wallet_ledger';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'amount_halalas' => 'integer'];
    }
}
