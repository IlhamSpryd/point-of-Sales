<?php

use App\Http\Middleware\EnsureTableSession;
use App\Http\Middleware\ResolveTableFromToken;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyWebhookSignature;
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
        // PATCH FOR S-04: jangan percaya semua proxy. Isi TRUSTED_PROXIES=IP_LB di produksi.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                   | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // PATCH FOR P-11: security headers global.
        $middleware->append(SecurityHeaders::class);

        // Daftarkan alias middleware 'role' untuk RoleMiddleware RBAC UjiKom
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'table.token' => ResolveTableFromToken::class,
            'table.session' => EnsureTableSession::class,
            'verify.webhook' => VerifyWebhookSignature::class,
        ]);

        // Midtrans mengirim notifikasi webhook server-to-server TANPA cookie
        // sesi browser, sehingga TIDAK PERNAH bisa menyertakan CSRF token.
        // Keamanan endpoint ini dijamin oleh verifikasi signature_key (SHA512)
        // di MidtransNotificationController, bukan oleh CSRF.
        $middleware->validateCsrfTokens(except: [
            'midtrans/notification',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
