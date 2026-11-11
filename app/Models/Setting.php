<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key/value system settings (language default, organization identity, fast-track policy, ...). */
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function write(string $key, mixed $value, ?string $by = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by]);
    }
}
