<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityPolicy;
use App\Support\Security\Sessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side session enforcement (front SessionGuard did this in the browser):
 * terminated session, idle timeout, maximum lifetime and account availability.
 * Touches last_active_at at most once a minute.
 */
class EnforceSessionPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        $session = Sessions::current();
        if (! $session || $session->status !== 'active') {
            return $this->expired('انتهت الجلسة. سجّل الدخول مرة أخرى.');
        }
        $policy = SecurityPolicy::get();
        if ($policy['idleTimeoutEnabled'] && $session->last_active_at->lt(now()->subMinutes((int) $policy['idleTimeoutMinutes']))) {
            Sessions::terminate([$session->id], null, 'انتهاء مهلة الخمول');

            return $this->expired('انتهت الجلسة بسبب عدم النشاط.');
        }
        if ($session->started_at->lt(now()->subHours((int) $policy['maximumSessionLifetime']))) {
            Sessions::terminate([$session->id], null, 'انتهاء المدة القصوى للجلسة');

            return $this->expired('انتهت المدة القصوى للجلسة. سجّل الدخول مرة أخرى.');
        }
        if (! $user->active || $user->suspended || $user->isLocked()) {
            Sessions::terminate([$session->id], null, 'الحساب غير متاح');

            return $this->expired('الحساب غير متاح. تواصل مع مدير النظام.');
        }
        // X-Session-Passive: background polling must not extend the idle window.
        if (! $request->headers->has('X-Session-Passive') && $session->last_active_at->lt(now()->subMinute())) {
            $session->update(['last_active_at' => now()]);
        }

        return $next($request);
    }

    private function expired(string $message): Response
    {
        return response()->json(['message' => $message, 'code' => 'SESSION_ENDED'], 401);
    }
}
