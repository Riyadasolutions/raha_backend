<?php

namespace Modules\Visits\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front VisitRecord. Overdue is derived (scheduled + scheduledAt in the past), never stored. */
class VisitRecord extends Model
{
    use FrontSerializable;

    protected $table = 'visit_records';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'audit' => 'array', 'corrections' => 'array', 'sequence' => 'integer'];
    }
}
