<?php

namespace Modules\Cases\Services;

use App\Models\User;
use App\Support\Activity\Activity;
use Illuminate\Support\Str;
use Modules\Cases\Models\CaseActivity;
use Modules\Cases\Models\CaseComment;
use Modules\Cases\Models\RahaCase;

/**
 * Single writer for case history (front case_service touch/act/caseEvent). PUBLIC API for other modules:
 *  - touch(case, status): moves the case to a status (stage label, default assignee, `updated` display date). Caller saves.
 *  - addActivity(case, text, actor, visibility, extra): appends a CaseActivity. Every beneficiary-visible activity
 *    (visibility beneficiary|both) also creates a portal Notification for the case owner.
 *  - addComment(case, text, author, visibility, authorId).
 *  - caseEvent(case, action, user): audit row + staff notification for the case's current status (front caseEvent).
 */
class CaseRecorder
{
    /** front case_service notifyTitle */
    public const NOTIFY_TITLE = [
        'new' => 'طلب جديد يحتاج إلى مراجعة', 'medical_review' => 'مراجعة طبية مطلوبة', 'social_review' => 'دراسة اجتماعية مطلوبة', 'manager_approval' => 'اعتماد المدير مطلوب',
        'assigned' => 'تم إسناد طلب إليك', 'scheduled' => 'تم تحديد موعد خدمة', 'in_progress' => 'الخدمة قيد التنفيذ', 'completed' => 'تم تقديم الخدمة — مراجعة علاقات المرضى',
        'finance_review' => 'طلب بانتظار المعالجة المالية', 'returned' => 'طلب أعيد للمستفيد', 'rejected' => 'تم رفض طلب', 'awaiting_quotation' => 'طلب يحتاج إلى عروض أسعار',
    ];

    public static function link(string $caseId): array
    {
        return ['to' => '/cases/$caseId', 'params' => ['caseId' => $caseId]];
    }

    public static function actorLabel(User $user): string
    {
        return "{$user->name} — {$user->role_label}";
    }

    public function touch(RahaCase $c, string $status): RahaCase
    {
        [$stage, $default] = WorkflowConfig::stageFor($status);
        $assignee = $c->provider_id && in_array($status, ['assigned', 'scheduled', 'in_progress'], true) ? ($c->provider ?? $default) : $default;
        $c->forceFill(['status' => $status, 'stage' => $stage, 'assignee' => $assignee, 'updated' => ar_date(), 'updated_at' => now()]);

        return $c;
    }

    /** @param array{action?: string, fromStage?: string, toStage?: string, reason?: string, actorId?: string, at?: mixed} $extra */
    public function addActivity(RahaCase $c, string $text, string $actor, string $visibility, array $extra = []): CaseActivity
    {
        $activity = CaseActivity::query()->create([
            'id' => 'a-'.Str::lower((string) Str::ulid()), 'case_id' => $c->id, 'at' => $extra['at'] ?? now(), 'text' => $text, 'actor' => $actor,
            'actor_id' => $extra['actorId'] ?? null, 'visibility' => $visibility, 'action' => $extra['action'] ?? null,
            'from_stage' => $extra['fromStage'] ?? null, 'to_stage' => $extra['toStage'] ?? null, 'reason' => ($extra['reason'] ?? '') !== '' ? $extra['reason'] : null,
        ]);
        if (in_array($visibility, ['beneficiary', 'both'], true)) {
            Activity::notify([
                'title' => $text, 'body' => $c->id, 'roles' => [], 'beneficiaryId' => $c->beneficiary_id, 'caseId' => $c->id,
                'link' => ['to' => '/beneficiary/requests/$requestId', 'params' => ['requestId' => $c->id]],
            ]);
        }
        $c->unsetRelation('activities');

        return $activity;
    }

    public function addComment(RahaCase $c, string $text, string $author, string $visibility, ?string $authorId = null): CaseComment
    {
        $comment = CaseComment::query()->create([
            'id' => 'c-'.Str::lower((string) Str::ulid()), 'case_id' => $c->id, 'at' => now(), 'text' => $text, 'author' => $author,
            'author_id' => $authorId, 'visibility' => $visibility,
        ]);
        $c->unsetRelation('comments');

        return $comment;
    }

    /** Audit + staff notification targeted at the roles of the case's current status (front caseEvent). */
    public function caseEvent(RahaCase $c, string $action, ?User $user): void
    {
        $roles = WorkflowConfig::stageRole($c->status) ?: ['admin'];
        $title = $c->status === 'preliminary_approved' ? 'تم الاعتماد المبدئي — بانتظار المراجعة المالية' : (self::NOTIFY_TITLE[$c->status] ?? null);
        $notify = null;
        if ($title) {
            // Gap fix: the mock restricted every notification to the provider's accounts once a provider was set;
            // providerId is now only attached when the provider is the target audience.
            $providerOnly = $c->provider_id && in_array('provider', $roles, true);
            $notify = [
                'title' => $title, 'body' => $c->id, 'roles' => $providerOnly ? ['provider'] : array_values(array_diff($roles, ['provider'])),
                'providerId' => $providerOnly ? $c->provider_id : null, 'caseId' => $c->id, 'link' => self::link($c->id),
            ];
        }
        Activity::emit($action, 'طلب', $c->id, $user, $notify);
    }
}
