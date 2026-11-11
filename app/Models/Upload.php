<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A file uploaded by a user before it is attached to a request, document or quotation. */
class Upload extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attached_at' => 'datetime', 'size' => 'integer'];
    }

    public function toFront(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'mime' => $this->mime, 'size' => $this->size, 'at' => iso($this->created_at)];
    }
}
