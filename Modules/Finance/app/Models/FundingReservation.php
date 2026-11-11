<?php

namespace Modules\Finance\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front FundingReservation. CONVERTED = commitment (authorization issued). */
class FundingReservation extends Model
{
    use FrontSerializable;

    protected $table = 'funding_reservations';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'closed_at' => 'datetime', 'amount_halalas' => 'integer'];
    }
}
