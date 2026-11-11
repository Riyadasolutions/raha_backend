<?php

namespace App\Support\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Activity\Activity;

/** Single writer for security events (front logSecurity). Also writes an audit row when an actor is given. */
class SecurityLog
{
    public static function log(string $type, ?User $user = null, ?string $userName = null, ?User $actor = null, string $result = 'SUCCESS', ?string $target = null, ?array $details = null): SecurityEvent
    {
        $device = Device::fromRequest();
        $event = SecurityEvent::query()->create([
            'at' => now(), 'type' => $type, 'result' => $result, 'user_id' => $user?->id,
            'user_name' => $user?->name ?? $userName ?? 'النظام', 'ip' => $device['ip'], 'device' => trim("{$device['device']} · {$device['browser']}", ' ·'),
            'by' => $actor?->name ?? $user?->name ?? 'النظام', 'target' => $target, 'details' => $details,
        ]);
        if ($actor) {
            Activity::audit(raha("rbac.securityEventLabels.$type", $type), 'أمان', $user?->id ?? $target ?? $type, $actor);
        }

        return $event;
    }
}
