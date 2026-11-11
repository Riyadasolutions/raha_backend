<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Front AppNotification. Beneficiary notifications are stored rows too (per-user read state). */
class Notification extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'roles' => 'array', 'link' => 'array'];
    }

    public function reads(): HasMany
    {
        return $this->hasMany(NotificationRead::class);
    }

    public function toFront(?User $viewer = null): array
    {
        $readBy = $this->reads->pluck('user_id')->all();

        return array_filter([
            'id' => $this->id, 'at' => iso($this->at), 'title' => $this->title, 'body' => $this->body, 'roles' => $this->roles ?? [],
            'providerId' => $this->provider_id, 'userId' => $this->user_id, 'beneficiaryId' => $this->beneficiary_id,
            'caseId' => $this->case_id, 'link' => $this->link, 'readBy' => $readBy,
            'read' => $viewer ? in_array($viewer->id, $readBy, true) : null,
        ], fn ($v) => $v !== null);
    }
}
