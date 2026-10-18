<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TwoFactorAuthService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Menghasilkan kunci rahasia Base32 acak untuk TOTP.
     */
    public function generateSecretKey(int $length = 16): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * Menghasilkan URI otpauth standar untuk aplikasi authenticator.
     */
    public function getQrCodeUri(User $user, string $secret): string
    {
        $issuer = config('app.name', 'Apeiron POS');
        $label = rawurlencode($issuer) . ':' . rawurlencode($user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Menghasilkan daftar kode pemulihan darurat (recovery codes).
     *
     * @return array<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
        }

        return $codes;
    }

    /**
     * Memverifikasi kode TOTP 6 digit dengan toleransi drift waktu ±1 interval (30 detik).
     */
    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $binarySecret = $this->base32Decode($secret);
        if ($binarySecret === '') {
            return false;
        }

        $code = trim($code);
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $currentInterval = (int) floor(time() / 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $timeStep = $currentInterval + $offset;
            $timeBytes = pack('N*', 0, $timeStep); // 64-bit big endian integer
            $hmac = hash_hmac('sha1', $timeBytes, $binarySecret, true);

            $offsetByte = ord($hmac[19]) & 0x0F;
            $binaryCode = (unpack('N', substr($hmac, $offsetByte, 4))[1] & 0x7FFFFFFF) % 1000000;
            $calculatedCode = str_pad((string) $binaryCode, 6, '0', STR_PAD_LEFT);

            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mengonfirmasi aktivasi 2FA pengguna dengan verifikasi kode pertama.
     */
    public function confirmTwoFactor(User $user, string $code): bool
    {
        if (empty($user->two_factor_secret)) {
            return false;
        }

        if (! $this->verifyCode($user->two_factor_secret, $code)) {
            return false;
        }

        $user->two_factor_confirmed_at = Carbon::now();
        $user->save();

        $this->auditTrail->log(
            tenantId: (int) $user->tenant_id,
            action: 'two_factor.enabled',
            userId: $user->id,
            description: "Autentikasi dua faktor (2FA/TOTP) berhasil diaktifkan oleh {$user->name}."
        );

        return true;
    }

    /**
     * Memverifikasi dan menghabiskan salah satu kode pemulihan darurat (sekali-pakai).
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        if (empty($recoveryCodes)) {
            return false;
        }

        $code = strtoupper(trim($code));

        foreach ($recoveryCodes as $index => $savedCode) {
            if (hash_equals($savedCode, $code)) {
                unset($recoveryCodes[$index]);
                $user->two_factor_recovery_codes = array_values($recoveryCodes);
                $user->save();

                $this->auditTrail->log(
                    tenantId: (int) $user->tenant_id,
                    action: 'two_factor.recovery_code_used',
                    userId: $user->id,
                    description: "Kode pemulihan darurat 2FA digunakan oleh {$user->name}. Sisa kode: " . count($recoveryCodes)
                );

                return true;
            }
        }

        return false;
    }

    /**
     * Menonaktifkan 2FA dari akun pengguna.
     */
    public function disableTwoFactor(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        $this->auditTrail->log(
            tenantId: (int) $user->tenant_id,
            action: 'two_factor.disabled',
            userId: $user->id,
            description: "Autentikasi dua faktor (2FA/TOTP) dinonaktifkan untuk {$user->name}."
        );
    }

    /**
     * Helper untuk decode string Base32 menjadi binary string.
     */
    private function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim($b32));
        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';

        for ($i = 0; $i < strlen($b32); $i++) {
            $char = $b32[$i];
            $val = strpos(self::BASE32_ALPHABET, $char);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }
}
