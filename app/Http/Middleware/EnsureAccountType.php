<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Portal isolation: `account:STAFF,PROVIDER` restricts a route group to the listed account types. */
class EnsureAccountType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();
        if (! $user || ! in_array($user->account_type->value, $types, true)) {
            return response()->json(['message' => 'لا تملك صلاحية الوصول إلى هذه الصفحة.'], 403);
        }

        return $next($request);
    }
}
