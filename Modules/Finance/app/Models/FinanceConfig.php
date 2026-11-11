<?php

namespace Modules\Finance\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Versioned finance configuration (front FinanceConfig). Current = highest version. */
class FinanceConfig extends Model
{
    use FrontSerializable;

    protected $table = 'finance_configs';

    protected $guarded = [];

    protected array $frontHidden = ['created_at'];

    public static function current(): self
    {
        return static::query()->orderByDesc('version')->firstOrFail();
    }

    public static function ofVersion(int $version): self
    {
        return static::query()->where('version', $version)->first() ?? static::current();
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'letter_validity_days' => 'integer'];
    }
}
