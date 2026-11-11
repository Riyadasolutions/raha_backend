<?php

namespace Modules\Catalog\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Front ServiceItem. deliveryType absent = one_time (normalizeDelivery). */
class Service extends Model
{
    use FrontSerializable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_medical_report' => 'boolean', 'requires_documents' => 'boolean', 'allow_internal' => 'boolean', 'allow_external' => 'boolean',
            'public_visible' => 'boolean', 'portal_visible' => 'boolean', 'sort_order' => 'integer', 'subservices' => 'array',
            'requirements' => 'array', 'default_visit_count' => 'integer',
        ];
    }

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'provider_service')->withPivot('added_at');
    }

    public function deliveryType(): string
    {
        return $this->delivery_type ?: 'one_time';
    }

    /** Public-safe projection for the homepage (front publicServices). */
    public function toPublic(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description];
    }
}
