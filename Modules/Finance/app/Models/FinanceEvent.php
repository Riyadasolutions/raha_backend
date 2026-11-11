<?php

namespace Modules\Finance\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Front FinanceEvent. Stages are stored as codes; toFront returns the Arabic labels in fromStage/toStage
 * (what the finance log displays) plus fromStageCode/toStageCode for logic.
 */
class FinanceEvent extends Model
{
    use FrontSerializable {
        toFront as baseFront;
    }
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected array $frontHidden = ['actor_id'];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'amount_halalas' => 'integer'];
    }

    public function toFront(): array
    {
        $out = $this->baseFront();
        foreach (['from_stage' => 'fromStage', 'to_stage' => 'toStage'] as $col => $key) {
            if ($this->{$col}) {
                $out[$key.'Code'] = $this->{$col};
                $out[$key] = raha("finance.financeStageLabels.{$this->{$col}}", $this->{$col});
            }
        }

        return $out;
    }
}
