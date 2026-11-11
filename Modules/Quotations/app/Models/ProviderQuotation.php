<?php

namespace Modules\Quotations\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front ProviderQuotation. Money in integer halalas. */
class ProviderQuotation extends Model
{
    use FrontSerializable;

    protected $table = 'provider_quotations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_items' => 'array', 'attachments' => 'array', 'evaluations' => 'array', 'branding' => 'array', 'submitted_at' => 'datetime', 'tax_rate' => 'float', 'version' => 'integer', 'subtotal' => 'integer', 'discount_total' => 'integer', 'tax_total' => 'integer', 'grand_total' => 'integer', 'start_within_days' => 'integer'];
    }
}
