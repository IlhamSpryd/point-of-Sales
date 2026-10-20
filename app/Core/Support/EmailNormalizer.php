<?php

declare(strict_types=1);

namespace App\Core\Support;

use Normalizer;

class EmailNormalizer
{
    /**
     * Normalisasi email deterministik:
     * 1. Trim spasi awal/akhir.
     * 2. Normalisasi Unicode ke FORM_C (NFC).
     * 3. Pisahkan local-part dan domain pada karakter '@' terakhir.
     * 4. Konversi keduanya ke huruf kecil UTF-8.
     * 5. Konversi domain internasional (IDN) ke ASCII Punycode via idn_to_ascii.
     *
     * Catatan: Sub-addressing (+tag) dan titik pada local-part dipertahankan utuh
     * demi integritas akun multi-tenant B2B dan alamat email yang sah.
     */
    public static function normalize(?string $email): string
    {
        if ($email === null) {
            return '';
        }

        // 1. Trim whitespace
        $trimmed = trim($email);
        if ($trimmed === '') {
            return '';
        }

        // 2. Unicode Normalization FORM_C
        if (class_exists(Normalizer::class) && Normalizer::isNormalized($trimmed, Normalizer::FORM_C) === false) {
            $normalizedUnicode = Normalizer::normalize($trimmed, Normalizer::FORM_C);
            if ($normalizedUnicode !== false) {
                $trimmed = $normalizedUnicode;
            }
        }

        // 3. Pisahkan local-part dan domain pada '@' terakhir
        $lastAtPos = strrpos($trimmed, '@');
        if ($lastAtPos === false || $lastAtPos === 0 || $lastAtPos === strlen($trimmed) - 1) {
            return mb_strtolower($trimmed, 'UTF-8');
        }

        $localPart = substr($trimmed, 0, $lastAtPos);
        $domain = substr($trimmed, $lastAtPos + 1);

        // 4. Huruf kecil UTF-8
        $localPart = mb_strtolower($localPart, 'UTF-8');
        $domain = mb_strtolower($domain, 'UTF-8');

        // 5. IDN ke ASCII Punycode
        if (function_exists('idn_to_ascii')) {
            $asciiDomain = idn_to_ascii(
                $domain,
                IDNA_DEFAULT,
                defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : INTL_IDNA_VARIANT_2003
            );
            if ($asciiDomain !== false && $asciiDomain !== '') {
                $domain = $asciiDomain;
            }
        }

        return $localPart.'@'.$domain;
    }
}
