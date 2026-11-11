<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Front SessionRec, bound to one Sanctum token. */
class UserSession extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_active_at' => 'datetime'];
    }

    public function toFront(): array
    {
        return [
            'id' => $this->id, 'userId' => $this->user_id, 'userName' => $this->user_name, 'device' => $this->device,
            'browser' => $this->browser, 'ip' => $this->ip, 'startedAt' => iso($this->started_at),
            'lastActiveAt' => iso($this->last_active_at), 'status' => $this->status,
        ];
    }
}
