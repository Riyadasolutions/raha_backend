<?php

namespace Modules\Authorizations\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front ServiceAssignment. */
class ServiceAssignment extends Model
{
    use FrontSerializable;

    protected $table = 'service_assignments';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'acknowledged_at' => 'datetime', 'quantity' => 'float', 'amount_halalas' => 'integer'];
    }
}
