<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'migrations' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** Front RoleDef shape. */
    public function toFront(): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'nameAr' => $this->name_ar, 'nameEn' => $this->name_en,
            'description' => (string) $this->description, 'type' => $this->type, 'status' => $this->status,
            'permissions' => (object) ($this->permissions ?? []), 'migrations' => $this->migrations ?? [],
            'workflowRole' => $this->workflow_role, 'createdAt' => iso($this->created_at), 'updatedAt' => iso($this->updated_at),
            'updatedBy' => $this->updated_by,
        ];
    }
}
