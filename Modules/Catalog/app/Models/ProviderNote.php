<?php

namespace Modules\Catalog\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Internal note on a provider (front ProviderNote). */
class ProviderNote extends Model
{
    use FrontSerializable, HasUlids;

    protected $table = 'provider_notes';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
