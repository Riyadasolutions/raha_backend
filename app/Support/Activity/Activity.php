<?php

namespace App\Support\Activity;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;

/**
 * Single writer for audit rows and notifications (port of front activity_service.emit).
 *
 * Notification targeting (front staffNotifications.forUser):
 *  - userId set → only that user; else roles[] → users whose primary workflow role is listed;
 *  - providerId set → additionally restricted to that provider's accounts;
 *  - beneficiaryId set → the beneficiary account (portal notifications).
 */
class Activity
{
    public static function audit(string $action, string $recordType, string $recordId, ?User $actor, ?array $changes = null): AuditLog
    {
        return AuditLog::query()->create([
            'at' => now(), 'user_id' => $actor?->id, 'user' => $actor?->name ?? 'النظام', 'role' => $actor?->role_label ?? '',
            'action' => $action, 'record_type' => $recordType, 'record_id' => $recordId, 'ip' => request()?->ip(), 'changes' => $changes,
        ]);
    }

    /**
     * @param  array{title: string, body?: string, roles?: string[], userId?: string, providerId?: string, beneficiaryId?: string, caseId?: string, link: array}  $n
     */
    public static function notify(array $n): Notification
    {
        return Notification::query()->create([
            'at' => now(), 'title' => $n['title'], 'body' => $n['body'] ?? '', 'roles' => $n['roles'] ?? [],
            'user_id' => $n['userId'] ?? null, 'provider_id' => $n['providerId'] ?? null, 'beneficiary_id' => $n['beneficiaryId'] ?? null,
            'case_id' => $n['caseId'] ?? null, 'link' => $n['link'],
        ]);
    }

    /** emit(): audit + optional notification in one call. */
    public static function emit(string $action, string $recordType, string $recordId, ?User $actor, ?array $notify = null): void
    {
        self::audit($action, $recordType, $recordId, $actor);
        if ($notify) {
            self::notify($notify);
        }
    }
}
