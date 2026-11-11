<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'details' => 'array'];
    }

    public function toFront(): array
    {
        return array_filter([
            'id' => $this->id, 'at' => iso($this->at), 'type' => $this->type, 'result' => $this->result, 'userId' => $this->user_id,
            'userName' => $this->user_name, 'ip' => $this->ip, 'device' => $this->device, 'by' => $this->by, 'target' => $this->target,
            'details' => $this->details,
        ], fn ($v) => $v !== null);
    }
}
