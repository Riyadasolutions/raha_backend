<?php

namespace Modules\Finance\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front SupportWallet. */
class Wallet extends Model
{
    use FrontSerializable;

    protected $table = 'wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected array $frontHidden = ['updated_at'];

    protected function casts(): array
    {
        return ['eligible_service_ids' => 'array', 'admin_ids' => 'array'];
    }
}
