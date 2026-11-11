<?php

namespace Modules\Cases\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front CaseComment. */
class CaseComment extends Model
{
    use FrontSerializable;

    protected $table = 'case_comments';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /** Millisecond precision: correction rules compare document and return timestamps. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
