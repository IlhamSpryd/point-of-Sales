<?php

namespace App\Http\Middleware;

use App\Models\ProvisioningNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain 1 — HMAC signature verification untuk endpoint provisioning
 * server-to-server dari website marketing.
 *
 * Algoritma (kontrak penuh di docs/architecture/PROVISIONING.md):
 *   canonical = METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n"
 *             + SHA256_HEX(RAW_BODY)
 *   signature = hex( HMAC-SHA256( secret, canonical ) )
 *
 * Query string TIDAK termasuk dalam canonical request dan endpoint
 * provisioning TIDAK mengizinkan query parameter — request dengan query
 * string apapun ditolak (dokumentasi D4/section 4).
 *
 * Kebijakan (config services.provisioning):
 *   - timestamp window ±300 detik (default, konfigurabel).
 *   - nonce sekali pakai, disimpan di tabel provisioning_nonces
 *     (retensi >= 2x window), INSERT unik = atomik pada request konkuren.
 *   - dua secret aktif (current + previous) untuk rotasi tanpa downtime:
 *     signature dianggap valid bila cocok dengan SALAH SATU secret dan
 *     hanya secret current yang boleh dipakai untuk menandatangani.
 *
 * Perbedaan penting dengan VerifyWebhookSignature (Midtrans/kanal):
 * middleware itu TIDAK mempunyai replay store — di sini nonce DIWAJIBKAN.
 */
class VerifyProvisioningSignature
{
    /** Batas ukuran body — payload provisioning sangat kecil; 32 KB longgar. */
    private const MAX_BODY_BYTES = 32768;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getQueryString() !== null && $request->getQueryString() !== '') {
            return $this->reject($request, 'query_string_not_allowed');
        }

        $timestamp = $request->header('X-Timestamp');
        $nonce = $request->header('X-Nonce');
        $signature = $request->header('X-Signature');

        // 1. Header lengkap dan well-formed.
        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            return $this->reject($request, 'missing_or_malformed_timestamp');
        }
        if (! is_string($nonce) || $nonce === '' || strlen($nonce) > 64
            || ! preg_match('/^[A-Za-z0-9\-_.]+$/', $nonce)) {
            return $this->reject($request, 'missing_or_malformed_nonce');
        }
        if (! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/', strtolower($signature))) {
            return $this->reject($request, 'missing_or_malformed_signature');
        }

        $timestamp = (int) $timestamp;

        // 2. Timestamp window.
        $window = (int) config('services.provisioning.timestamp_window', 300);
        if (abs(now()->getTimestamp() - $timestamp) > $window) {
            return $this->reject($request, 'timestamp_out_of_window');
        }

        // 3. Body size guard.
        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->reject($request, 'body_too_large');
        }

        // 4. Canonical signature check — constant-time terhadap SALAH SATU
        //    secret aktif (current atau previous selama masa rotasi).
        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            '/'.$request->path(),
            (string) $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ]);

        $secrets = $this->activeSecrets();
        if ($secrets === []) {
            Log::error('Provisioning secrets are not configured.');

            // Gagal tertutup: jangan ungkap alasan detail.
            return $this->reject($request, 'unauthorized');
        }

        $matchedSecret = null;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $canonical, $secret);
            if (hash_equals($expected, strtolower($signature))) {
                $matchedSecret = $secret;
                break;
            }
        }
        if ($matchedSecret === null) {
            return $this->reject($request, 'invalid_signature');
        }

        // 5. Anti-replay: nonce HARUS belum pernah diterima. INSERT sekali —
        //    unique PK menegakkan atomisitas bahkan pada request konkuren.
        //    Nonce dipakai bersama secret yang dipakai menandatangani agar
        //    secret previous/current tidak saling menutup namespace nonce.
        $retention = max($window, (int) config('services.provisioning.nonce_retention', 1800));
        $expiresAt = max($timestamp, now()->getTimestamp()) + $retention;

        try {
            ProvisioningNonce::create([
                'nonce' => $nonce,
                'expires_at_epoch' => $expiresAt,
            ]);
        } catch (QueryException $e) {
            // PK sudah ada => nonce sudah dipakai sebelumnya = replay.
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->reject($request, 'nonce_replayed');
            }

            throw $e;
        }

        return $next($request);
    }

    /**
     * @return array<int, string> [current, previous?] — previous hanya bila terisi.
     */
    private function activeSecrets(): array
    {
        $secrets = [];
        $current = config('services.provisioning.secret_current');
        $previous = config('services.provisioning.secret_previous');

        if (is_string($current) && $current !== '') {
            $secrets[] = $current;
        }
        if (is_string($previous) && $previous !== '') {
            $secrets[] = $previous;
        }

        return $secrets;
    }

    private function reject(Request $request, string $reason): Response
    {
        // Log internal detail (tanpa secret/signature), response generik.
        Log::warning('Provisioning request rejected.', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'path' => '/'.$request->path(),
        ]);

        $status = match ($reason) {
            'nonce_replayed' => 409,
            'query_string_not_allowed' => 400,
            default => 401,
        };

        return response()->json([
            'success' => false,
            'error' => 'unauthorized_request',
            'message' => 'Provisioning request authentication failed.',
        ], $status);
    }
}
