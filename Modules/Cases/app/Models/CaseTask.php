<?php

namespace Modules\Cases\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front CaseTask: one task per review-stage entry; overdue is derived from due_at, never stored. */
class CaseTask extends Model
{
    use FrontSerializable;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Millisecond precision: correction rules compare document and return timestamps. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected array $frontHidden = ['completed_by_id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime', 'due_at' => 'datetime', 'claimed_at' => 'datetime', 'completed_at' => 'datetime',
            'history' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['new', 'in_progress'], true);
    }

    /** Derived status (front taskStatus). */
    public function viewStatus(): string
    {
        return $this->isActive() && $this->due_at && $this->due_at->isPast() ? 'overdue' : $this->status;
    }
}
