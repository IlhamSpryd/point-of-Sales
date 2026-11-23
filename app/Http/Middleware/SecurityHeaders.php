<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PATCH FOR P-11: Tambah security headers pada semua respons HTML customer.
 * Header ini mencegah clickjacking, MIME-sniffing, dan memberi petunjuk
 * Content-Security-Policy ringan (report-only dulu, supaya tidak langsung
 * memecah CDN/font/inline-script yang sudah ada).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // CSP & HSTS Enforced:
        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://app.sandbox.midtrans.com https://app.midtrans.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self' https://app.sandbox.midtrans.com https://app.midtrans.com wss://app.apeironsystem.web.id; frame-src 'self' https://app.sandbox.midtrans.com https://app.midtrans.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';"
            );
        } else {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://app.sandbox.midtrans.com https://app.midtrans.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self' ws://127.0.0.1:* ws://localhost:* https://app.sandbox.midtrans.com https://app.midtrans.com wss://app.apeironsystem.web.id; frame-src 'self' https://app.sandbox.midtrans.com https://app.midtrans.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';"
            );
        }

        return $response;
    }
}
