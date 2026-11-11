<?php

namespace Modules\Beneficiaries\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Front BeneficiaryNote. */
class BeneficiaryNote extends Model
{
    use FrontSerializable, HasUlids;

    protected $table = 'beneficiary_notes';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
