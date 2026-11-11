<?php

namespace Modules\Quotations\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Front QuotationRound (staff shape includes invitations, quotations and history). */
class QuotationRound extends Model
{
    use FrontSerializable {
        toFront as baseFront;
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deadline' => 'datetime', 'shared_attachments' => 'array', 'history' => 'array', 'quantity' => 'float'];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(QuotationInvitation::class)->orderBy('sent_at');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(ProviderQuotation::class)->orderBy('created_at');
    }

    public function deadlinePassed(): bool
    {
        return $this->deadline->isPast();
    }

    public function toFront(): array
    {
        $this->loadMissing(['invitations', 'quotations']);
        $out = $this->baseFront();
        $out['invitations'] = $this->invitations->map->toFront()->values()->all();
        $out['quotations'] = $this->quotations->map->toFront()->values()->all();

        return $out;
    }
}
