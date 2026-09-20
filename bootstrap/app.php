<?php

use App\Http\Middleware\EnsureTableSession;
use App\Http\Middleware\ResolveTableFromToken;
use App\Http\Middleware\RoleMiddleware;
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
        // Percayakan semua proxy (seperti Ngrok) agar Vite memuat CSS menggunakan HTTPS
        $middleware->trustProxies(at: '*');

        // Daftarkan alias middleware 'role' untuk RoleMiddleware RBAC UjiKom
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'table.token' => ResolveTableFromToken::class,
            'table.session' => EnsureTableSession::class,
            'verify.webhook' => \App\Http\Middleware\VerifyWebhookSignature::class,
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
