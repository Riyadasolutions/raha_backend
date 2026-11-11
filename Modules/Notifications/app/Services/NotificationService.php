<?php

namespace Modules\Notifications\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Port of front activity_service staffNotifications (forUser / markRead / markAll) and beneficiary_notifications.
 * Staff/provider visibility: primary role admin sees every staff notification; otherwise userId match, else the user's
 * primary workflow role in roles[]; providerId (when set) must equal the user's provider. Beneficiaries see only rows
 * addressed to their beneficiary record.
 */
class NotificationService
{
    public function visibleQuery(User $user): Builder
    {
        $q = Notification::query();
        if ($user->isBeneficiary()) {
            return $user->beneficiary_id ? $q->where('beneficiary_id', $user->beneficiary_id) : $q->whereRaw('1 = 0');
        }
        $q->whereNull('beneficiary_id');
        if ($user->role === 'admin' && $user->isStaff()) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('user_id', $user->id)
            ->orWhere(fn ($r) => $r->whereNull('user_id')->where('roles', 'like', '%"'.str_replace(['%', '_', '"'], '', (string) $user->role).'"%')))
            ->where(fn ($w) => $w->whereNull('provider_id')->orWhere('provider_id', (string) $user->provider_id));
    }

    public function list(User $user): Collection
    {
        return $this->visibleQuery($user)->with('reads')->orderByDesc('at')->orderByDesc('id')->get()
            ->map(fn (Notification $n) => self::present($n, $user))->values();
    }

    /** Front AppNotification + `read` for the viewer. readBy only reveals the viewer's own read mark. */
    public static function present(Notification $n, User $viewer): array
    {
        $read = $n->reads->contains('user_id', $viewer->id);

        return array_merge($n->toFront($viewer), ['read' => $read, 'readBy' => $read ? [$viewer->id] : []]);
    }

    public function unreadCount(User $user): int
    {
        return $this->visibleQuery($user)->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id))->count();
    }

    public function markRead(User $user, string $id): Notification
    {
        $n = $this->visibleQuery($user)->whereKey($id)->first() ?? throw DomainRuleException::notFound('الإشعار غير موجود.');
        NotificationRead::query()->insertOrIgnore(['notification_id' => $n->id, 'user_id' => $user->id, 'read_at' => now()]);

        return $n->load('reads');
    }

    public function markAll(User $user): int
    {
        $ids = $this->visibleQuery($user)->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id))->pluck('id');
        if ($ids->isNotEmpty()) {
            NotificationRead::query()->insertOrIgnore($ids->map(fn ($id) => ['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all());
        }

        return $ids->count();
    }
}
