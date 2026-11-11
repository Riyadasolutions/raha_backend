<?php

namespace App\Support\Security;

use App\Models\User;
use App\Models\UserSession;
use Laravel\Sanctum\PersonalAccessToken;

/** Session records bound to Sanctum tokens (front sessionService). */
class Sessions
{
    /** Issue a token + session; enforces the concurrent-session limit. Returns [plainTextToken, session]. */
    public static function start(User $user): array
    {
        $token = $user->createToken('raha');
        $device = Device::fromRequest();
        $session = UserSession::query()->create([
            'id' => 's-'.uid(10), 'user_id' => $user->id, 'token_id' => $token->accessToken->id, 'user_name' => $user->name,
            'device' => $device['device'], 'browser' => $device['browser'], 'ip' => $device['ip'],
            'started_at' => now(), 'last_active_at' => now(), 'status' => 'active',
        ]);
        $limit = (int) SecurityPolicy::get()['concurrentSessionLimit'];
        if ($limit > 0) {
            $extra = UserSession::query()->where('user_id', $user->id)->where('status', 'active')
                ->orderByDesc('token_id')->get()->slice($limit);
            if ($extra->isNotEmpty()) {
                self::terminate($extra->pluck('id')->all(), null, 'تجاوز حد الجلسات المتزامنة');
            }
        }

        return [$token->plainTextToken, $session];
    }

    /** Marks sessions terminated, revokes their tokens and logs SESSION_TERMINATED. Returns the count. */
    public static function terminate(array $ids, ?User $actor = null, ?string $note = null): int
    {
        $count = 0;
        foreach (UserSession::query()->whereIn('id', $ids)->where('status', 'active')->get() as $session) {
            $session->update(['status' => 'terminated', 'ended_reason' => $note]);
            if ($session->token_id) {
                PersonalAccessToken::query()->whereKey($session->token_id)->delete();
            }
            $user = User::query()->find($session->user_id);
            SecurityLog::log('SESSION_TERMINATED', $user, $session->user_name, $actor, details: ['note' => $note ?? 'إنهاء الجلسة']);
            $count++;
        }

        return $count;
    }

    public static function endAllFor(User $user, ?User $actor = null, ?string $note = null, ?string $exceptId = null): int
    {
        $ids = UserSession::query()->where('user_id', $user->id)->where('status', 'active')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->pluck('id')->all();

        return self::terminate($ids, $actor, $note);
    }

    public static function current(): ?UserSession
    {
        $token = request()->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? UserSession::query()->where('token_id', $token->id)->first() : null;
    }
}
