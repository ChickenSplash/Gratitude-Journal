<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The sign-in page and the journal both live under the app's prefix,
        // so neither of Laravel's default `/login` and `/home` targets exists.
        $middleware->redirectGuestsTo(fn () => route('journal.login'));
        $middleware->redirectUsersTo(fn () => route('journal.home'));

        // Behind Cloudflare Tunnel, so the scheme and client IP arrive in
        // forwarded headers. Without this every request looks like plain HTTP
        // from the tunnel's own address, which breaks secure cookies and makes
        // login throttling global rather than per-visitor.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson(),
        );
    })->create();
