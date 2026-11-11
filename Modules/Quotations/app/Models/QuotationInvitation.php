<?php

namespace Modules\Quotations\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front QuotationInvitation. */
class QuotationInvitation extends Model
{
    use FrontSerializable;

    protected $table = 'quotation_invitations';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }
}
