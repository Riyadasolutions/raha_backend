<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'changes' => 'array'];
    }

    /** Front AuditEntry shape (+ userId). */
    public function toFront(): array
    {
        return [
            'id' => $this->id, 'at' => iso($this->at), 'user' => $this->user, 'userId' => $this->user_id, 'role' => $this->role,
            'action' => $this->action, 'recordType' => $this->record_type, 'recordId' => $this->record_id,
        ];
    }
}
