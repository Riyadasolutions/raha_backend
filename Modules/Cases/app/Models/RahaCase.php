<?php

namespace Modules\Cases\Models;

use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Front RahaCase (a service request). PHP cannot name a class "Case", hence RahaCase.
 * toFront() assembles the full staff shape; audience projections live in the Cases module resources.
 */
class RahaCase extends Model
{
    use FrontSerializable {
        toFront as baseFront;
    }

    protected $table = 'cases';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Millisecond precision: correction rules compare document and return timestamps. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected array $frontHidden = ['return_history'];

    protected function casts(): array
    {
        return [
            'medical' => 'array', 'applicant' => 'array', 'social' => 'array', 'recipient' => 'array', 'requirements' => 'array',
            'return_info' => 'array', 'return_history' => 'array', 'appointment' => 'array', 'invoice' => 'array', 'evaluation' => 'array',
            'request_survey' => 'array', 'finance' => 'array', 'preferred_quotation' => 'array', 'fast_track' => 'array', 'visit_plan' => 'array',
            'priority' => 'boolean', 'provider_accepted' => 'boolean', 'service_confirmed' => 'boolean', 'survey_pending' => 'boolean',
            'quotation_required' => 'boolean', 'assigned_at' => 'datetime',
        ];
    }

    public const REVIEW_STAGES = ['new', 'medical_review', 'social_review', 'manager_approval'];

    public function activities(): HasMany
    {
        return $this->hasMany(CaseActivity::class, 'case_id')->orderByDesc('at')->orderByDesc('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CaseComment::class, 'case_id')->orderByDesc('at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class, 'case_id')->where('superseded', false)->orderBy('at');
    }

    public function allDocuments(): HasMany
    {
        return $this->hasMany(CaseDocument::class, 'case_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CaseTask::class, 'case_id')->orderBy('created_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CaseReview::class, 'case_id')->orderBy('at');
    }

    public function activeTask(): ?CaseTask
    {
        return $this->tasks->first(fn (CaseTask $t) => $t->stage === $this->status && $t->isActive());
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /** Full staff shape (front RahaCase). Relations are eager-loaded when missing. */
    public function toFront(): array
    {
        $this->loadMissing(['activities', 'comments', 'documents', 'tasks', 'reviews']);
        $out = $this->baseFront();
        $out['activities'] = $this->activities->map->toFront()->values()->all();
        $out['comments'] = $this->comments->map->toFront()->values()->all();
        $out['documents'] = $this->documents->map->toFront()->values()->all();
        $out['tasks'] = $this->tasks->map(fn (CaseTask $t) => $t->toFront())->values()->all();
        $out['reviews'] = $this->reviews->map->toFront()->values()->all();

        return $out;
    }
}
