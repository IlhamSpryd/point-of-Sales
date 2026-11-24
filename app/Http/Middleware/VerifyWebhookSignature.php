<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        $provider = (string) $request->route('provider');
        $secret = config("services.webhooks.{$provider}_secret");

        if (! $secret) {
            $ofdManager = app(\App\Modules\Integration\Services\OnlineFoodDeliveryManager::class);
            if ($ofdManager->hasDriver($provider)) {
                // OFD providers (GrabFood, GoFood) use tenant-specific signing secrets stored in ofd_channel_configs
                // and are verified per-tenant in WebhookController.
                return $next($request);
            }

            Log::warning("Webhook secret not found for provider: {$provider}");

            return response()->json(['message' => 'Provider not supported'], 400);
        }

        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        // Batasi window waktu (skew) maksimal 5 menit untuk cegah Replay Attack.
        // Validasi tipe ketat untuk mencegah TypeError pada PHP 8.2+.
        if (! is_string($timestamp) || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Timestamp invalid or expired'], 401);
        }

        if (! is_string($signature) || $signature === '') {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $expected = hash_hmac('sha256', $request->getContent().$timestamp, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning("Invalid webhook signature from {$provider}");

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
