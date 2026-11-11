<?php

namespace Modules\Finance\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front FinancialApproval. */
class FinancialApproval extends Model
{
    use FrontSerializable;

    protected $table = 'financial_approvals';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected array $frontHidden = ['updated_at'];

    protected function casts(): array
    {
        return ['allocations' => 'array', 'split_approved' => 'array', 'executive' => 'array', 'history' => 'array', 'reserved' => 'boolean', 'version' => 'integer', 'config_version' => 'integer', 'total_cost_halalas' => 'integer'];
    }
}
