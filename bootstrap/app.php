<?php

use App\Exceptions\DomainRuleException;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureAccountType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account' => EnsureAccountType::class,
            'session.policy' => EnforceSessionPolicy::class,
        ]);
        // Authenticated API group: Sanctum bearer token + server-side session policy.
        $middleware->group('raha.auth', ['auth:sanctum', 'session.policy']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(fn (DomainRuleException $e) => response()->json(array_merge(['message' => $e->getMessage()], $e->extra), $e->status));
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'انتهت الجلسة. سجّل الدخول مرة أخرى.', 'code' => 'UNAUTHENTICATED'], 401) : null);
        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'لا تملك صلاحية تنفيذ هذا الإجراء.'], 403) : null);
        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'السجل غير موجود.'], 404) : null);
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'البيانات المدخلة غير صحيحة.', 'errors' => $e->errors()], 422) : null);
    })->create();
