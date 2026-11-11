<?php

namespace Modules\Authorizations\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front ServiceAuthorization (letter). DRAFT is never stored. */
class ServiceAuthorization extends Model
{
    use FrontSerializable;

    protected $table = 'service_authorizations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected array $frontHidden = ['created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['document' => 'array', 'issued_at' => 'datetime', 'quantity' => 'float', 'amount_halalas' => 'integer', 'version' => 'integer'];
    }
}
