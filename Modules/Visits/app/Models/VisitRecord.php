<?php

namespace Modules\Visits\Models;

use App\Models\User;
use App\Support\Serialization\FrontSerializable;
use Illuminate\Database\Eloquent\Model;

/** Front VisitRecord. Overdue is derived (scheduled + scheduledAt in the past), never stored. */
class VisitRecord extends Model
{
    use FrontSerializable;

    protected $table = 'visit_records';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const STAFF_LABEL = 'جمعية راحة';

    /**
     * Projection for the viewer. Providers get the history of their own visit but never the identity of RAHA staff or the internal
     * reason behind a staff action (correction, count reduction): those stamps become "جمعية راحة" and the reason text is dropped.
     * Staff receive the record unchanged.
     */
    public function toFrontFor(User $viewer): array
    {
        $out = $this->toFront();
        if (! $viewer->isProvider()) {
            return $out;
        }
        $own = $viewer->provider_id
            ? User::query()->where('provider_id', $viewer->provider_id)->get(['name', 'role_label'])->map(fn ($u) => "{$u->name} — {$u->role_label}")->all()
            : [];
        $mine = fn (?string $by) => $by !== null && in_array($by, $own, true);
        foreach (['createdBy', 'completedBy'] as $k) {
            if (isset($out[$k]) && ! $mine($out[$k])) {
                $out[$k] = self::STAFF_LABEL;
            }
        }
        $out['audit'] = array_map(function ($e) use ($mine) {
            if ($mine($e['by'] ?? null)) {
                return $e;
            }

            return ['by' => self::STAFF_LABEL] + array_diff_key($e, ['detail' => 1, 'by' => 1]);
        }, $out['audit'] ?? []);
        $out['corrections'] = array_map(fn ($c) => ($mine($c['by'] ?? null) ? $c : ['by' => self::STAFF_LABEL, 'reason' => ''] + $c), $out['corrections'] ?? []);

        return $out;
    }

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'audit' => 'array', 'corrections' => 'array', 'sequence' => 'integer'];
    }
}
