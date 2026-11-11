<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'notification_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
