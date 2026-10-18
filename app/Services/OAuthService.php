<?php

namespace App\Services;

use App\Support\EmailNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class OAuthService
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_APPLE = 'apple';

    public const INTENT_LOGIN = 'login';
    public const INTENT_REGISTER_OWNER = 'register_owner';
    public const INTENT_LINK_ACCOUNT = 'link_account';
    public const INTENT_ACCEPT_INVITATION = 'accept_invitation';

    /**
     * @var array<string, callable|array> Test payload fakes for unit/feature tests
     */
    protected static array $testTokenPayloads = [];

    /**
     * @var array<string, string> Custom PEM public keys per provider for testing
     */
    protected static array $testPublicKeys = [];

    /**
     * Set payload palsu untuk pengujian tanpa memanggil endpoint provider eksternal.
     *
     * @param array<string, mixed>|callable $payload
     */
    public static function fakeTokenPayload(string $provider, array|callable $payload): void
    {
        self::$testTokenPayloads[$provider] = $payload;
    }

    /**
     * Bersihkan test fake payload.
     */
    public static function clearFakes(): void
    {
        self::$testTokenPayloads = [];
        self::$testPublicKeys = [];
    }

    /**
     * Tetapkan custom public key PEM untuk pengujian RS256 signature.
     */
    public static function setTestPublicKey(string $provider, string $pemKey): void
    {
        self::$testPublicKeys[$provider] = $pemKey;
    }

    /**
     * Bangkitkan URL redirect otorisasi OIDC ke provider yang dipilih
     * lengkap dengan state signed-HMAC, nonce kriptografis, dan PKCE challenge.
     */
    public function getRedirectUrl(
        string $provider,
        string $intent = self::INTENT_LOGIN,
        ?int $initiatorUserId = null,
        ?string $invitationToken = null,
        ?Request $request = null
    ): string {
        $this->validateProvider($provider);

        // 1. Bangkitkan cryptographic state, nonce, dan PKCE
        $stateRandom = Str::random(32);
        $state = hash_hmac('sha256', $stateRandom, (string) config('app.key'));
        $nonce = hash_hmac('sha256', Str::random(32), (string) config('app.key'));

        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // 2. Simpan paket context state ke server Cache (TTL 10 menit)
        $ipHash = $request ? hash('sha256', (string) $request->ip()) : null;

        Cache::put("oauth_state:{$state}", [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'intent' => $intent,
            'provider' => $provider,
            'initiator_user_id' => $initiatorUserId,
            'invitation_token' => $invitationToken,
            'ip_hash' => $ipHash,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(10));

        // 3. Bangkitkan URL sesuai provider
        if ($provider === self::PROVIDER_GOOGLE) {
            $clientId = config('services.google.client_id');
            $redirectUri = config('services.google.redirect_uri');

            $params = http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'nonce' => $nonce,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'prompt' => 'select_account',
            ]);

            return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
        }

        if ($provider === self::PROVIDER_APPLE) {
            $clientId = config('services.apple.client_id');
            $redirectUri = config('services.apple.redirect_uri');

            $params = http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code id_token',
                'response_mode' => 'form_post',
                'scope' => 'name email',
                'state' => $state,
                'nonce' => $nonce,
            ]);

            return "https://appleid.apple.com/auth/authorize?{$params}";
        }

        throw new InvalidArgumentException("Unsupported provider: {$provider}");
    }

    /**
     * Tarik paket state dari cache secara atomik (single-use anti-replay).
     *
     * @return array<string, mixed>|null
     */
    public function pullState(string $state): ?array
    {
        return Cache::pull("oauth_state:{$state}");
    }

    /**
     * Tukar kode otorisasi OAuth menjadi ID Token (JWT).
     */
    public function exchangeCodeForIdToken(string $provider, string $code, ?string $codeVerifier = null): string
    {
        $this->validateProvider($provider);

        if (isset(self::$testTokenPayloads[$provider])) {
            return $code;
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect_uri'),
                'grant_type' => 'authorization_code',
                'code_verifier' => $codeVerifier,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to exchange Google authorization code: ' . $response->body());
            }

            return (string) $response->json('id_token');
        }

        // Apple form_post sends id_token directly
        return $code;
    }

    /**
     * Verifikasi integritas token ID (JWT) RS256, kecocokan nonce, aud, iss, dan masa berlaku.
     *
     * @return array{sub: string, email: string, email_normalized: string, email_verified: bool, is_private_relay: bool, name: ?string}
     */
    public function verifyIdToken(string $provider, string $idToken, string $expectedNonce): array
    {
        $this->validateProvider($provider);

        // Jika ada test fake payload
        if (isset(self::$testTokenPayloads[$provider])) {
            $fake = self::$testTokenPayloads[$provider];
            $payload = is_callable($fake) ? $fake($idToken, $expectedNonce) : $fake;

            if (($payload['nonce'] ?? '') !== $expectedNonce) {
                throw new RuntimeException("OIDC nonce mismatch. Expected {$expectedNonce}, got " . ($payload['nonce'] ?? 'null'));
            }

            $email = $payload['email'] ?? '';
            $isPrivate = ! empty($payload['is_private_email'])
                || str_ends_with(strtolower($email), '@privaterelay.appleid.com');

            return [
                'sub' => (string) ($payload['sub'] ?? ''),
                'email' => $email,
                'email_normalized' => EmailNormalizer::normalize($email),
                'email_verified' => (bool) ($payload['email_verified'] ?? false),
                'is_private_relay' => $isPrivate,
                'name' => $payload['name'] ?? null,
            ];
        }

        // Parse JWT Token: [header, payload, signature]
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token structure');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        $signature = $this->base64UrlDecode($signatureB64);

        if (! is_array($header) || ! is_array($payload)) {
            throw new RuntimeException('Invalid JSON inside ID token');
        }

        // 1. Validasi Algoritma Header (RS256)
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException("Unsupported JWS algorithm: " . ($header['alg'] ?? 'null'));
        }

        // 2. Validasi Nonce
        if (($payload['nonce'] ?? '') !== $expectedNonce) {
            throw new RuntimeException('OIDC nonce mismatch');
        }

        // 3. Validasi Expiration
        if (isset($payload['exp']) && $payload['exp'] < (time() - 60)) {
            throw new RuntimeException('OIDC token has expired');
        }

        // 4. Validasi Issuer
        $issuer = $payload['iss'] ?? '';
        if ($provider === self::PROVIDER_GOOGLE) {
            if (! in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
                throw new RuntimeException("Invalid Google issuer: {$issuer}");
            }
        } elseif ($provider === self::PROVIDER_APPLE) {
            if ($issuer !== 'https://appleid.apple.com') {
                throw new RuntimeException("Invalid Apple issuer: {$issuer}");
            }
        }

        // 5. Validasi Audience
        $expectedAudience = config("services.{$provider}.client_id");
        $actualAudience = $payload['aud'] ?? '';
        if ($actualAudience !== $expectedAudience) {
            throw new RuntimeException("Audience mismatch: expected {$expectedAudience}, got {$actualAudience}");
        }

        // 6. Verifikasi RS256 Signature
        $kid = $header['kid'] ?? null;
        $dataToVerify = "{$headerB64}.{$payloadB64}";

        $publicKey = $this->resolvePublicKey($provider, $kid);
        if (! $publicKey) {
            throw new RuntimeException("Unable to resolve public key for kid: {$kid}");
        }

        $verifyResult = openssl_verify($dataToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verifyResult !== 1) {
            throw new RuntimeException('OIDC token signature verification failed');
        }

        $email = (string) ($payload['email'] ?? '');
        $isPrivate = ! empty($payload['is_private_email'])
            || str_ends_with(strtolower($email), '@privaterelay.appleid.com');

        return [
            'sub' => (string) ($payload['sub'] ?? ''),
            'email' => $email,
            'email_normalized' => EmailNormalizer::normalize($email),
            'email_verified' => (bool) ($payload['email_verified'] ?? false),
            'is_private_relay' => $isPrivate,
            'name' => $payload['name'] ?? null,
        ];
    }

    /**
     * Dapatkan public key PEM untuk verifikasi tanda tangan RS256.
     */
    protected function resolvePublicKey(string $provider, ?string $kid): ?string
    {
        if (isset(self::$testPublicKeys[$provider])) {
            return self::$testPublicKeys[$provider];
        }

        // Ambil JWKS dari cache atau endpoint resmi
        $jwks = Cache::remember("jwks:{$provider}", 3600, function () use ($provider) {
            $url = $provider === self::PROVIDER_GOOGLE
                ? 'https://www.googleapis.com/oauth2/v3/certs'
                : 'https://appleid.apple.com/auth/keys';

            try {
                $response = Http::timeout(5)->get($url);
                return $response->json('keys') ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        });

        foreach ($jwks as $key) {
            if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA') {
                return $this->jwkToPem($key);
            }
        }

        return null;
    }

    /**
     * Konversi JWK RSA (n, e) ke PEM X.509 RSA public key menggunakan ASN.1 DER encoding.
     */
    public function jwkToPem(array $jwk): ?string
    {
        if (empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        $modulusDer = $this->asn1Integer($modulus);
        $exponentDer = $this->asn1Integer($exponent);

        $rsaPublicKeyDer = $this->asn1Sequence($modulusDer . $exponentDer);

        // AlgorithmIdentifier OID: 1.2.840.113549.1.1.1 (rsaEncryption) with NULL parameter
        $rsaAlgorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // Bit string containing the RSA public key
        $bitString = "\x03" . $this->asn1Length(strlen($rsaPublicKeyDer) + 1) . "\x00" . $rsaPublicKeyDer;

        // SubjectPublicKeyInfo sequence
        $spkiDer = $this->asn1Sequence($rsaAlgorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $bytes): string
    {
        // If highest bit is 1, prefix with 0x00 to mark as positive integer
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Sequence(string $bytes): string
    {
        return "\x30" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($temp)) . $temp;
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/')) ?: '';
    }

    private function validateProvider(string $provider): void
    {
        if (! in_array($provider, [self::PROVIDER_GOOGLE, self::PROVIDER_APPLE], true)) {
            throw new InvalidArgumentException("Invalid OAuth provider: {$provider}");
        }
    }
}
