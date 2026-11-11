<?php

namespace Modules\Quotations\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front ProviderAssessment (Task 16): provider pre-quotation report; holds no beneficiary identity. */
class ProviderAssessment extends Model
{
    use FrontSerializable;

    protected $table = 'provider_assessments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['history' => 'array', 'recommended_visits' => 'integer'];
    }
}
