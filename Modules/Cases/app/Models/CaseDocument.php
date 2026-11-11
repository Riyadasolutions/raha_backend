<?php

namespace Modules\Cases\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front CaseDocument. Files live on the private disk (path); legacy seed documents are metadata only. */
class CaseDocument extends Model
{
    use FrontSerializable;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Millisecond precision: correction rules compare document and return timestamps. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected array $frontHidden = ['case_id', 'path', 'uploaded_by_id', 'superseded', 'mime'];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'review' => 'array', 'size' => 'integer', 'superseded' => 'boolean'];
    }

    public function toFront(): array
    {
        $out = [];
        foreach ($this->attributesToArray() as $key => $value) {
            if ($value === null || in_array($key, $this->frontHidden, true)) {
                continue;
            }
            $out[\Illuminate\Support\Str::camel($key)] = $key === 'at' ? iso($this->at) : $value;
        }
        $out['hasFile'] = $this->path !== null;

        return $out;
    }
}
