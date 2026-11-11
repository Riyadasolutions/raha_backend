<?php

namespace Modules\Settings\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Public contact form message. */
class ContactMessage extends Model
{
    use FrontSerializable, HasUlids;

    protected $table = 'contact_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
