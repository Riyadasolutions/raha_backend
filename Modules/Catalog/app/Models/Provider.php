<?php

namespace Modules\Catalog\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Front ServiceProvider. Provider login accounts are users with provider_id. */
class Provider extends Model
{
    use FrontSerializable {
        toFront as baseFront;
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contact' => 'array'];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'provider_service')->withPivot('added_at');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(ProviderNote::class)->orderByDesc('at');
    }

    public function offers(?string $serviceId): bool
    {
        return $serviceId !== null && $this->services->contains('id', $serviceId);
    }

    /** @param bool $internal include internal notes (staff with providers.view) */
    public function toFront(bool $internal = true): array
    {
        $out = $this->baseFront();
        $out['contact'] = $this->contact ?? ['name' => '', 'mobile' => '', 'email' => ''];
        $out['notes'] = (string) $this->notes;
        $out['services'] = $this->services->map(fn (Service $s) => ['serviceId' => $s->id, 'addedAt' => iso($s->pivot->added_at)])->values()->all();
        $out['internalNotes'] = $internal ? $this->internalNotes->map(fn ($n) => ['id' => $n->id, 'at' => iso($n->at), 'text' => $n->text, 'author' => $n->author])->values()->all() : [];

        return $out;
    }
}
