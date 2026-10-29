<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

class UnresolvableAccountMappingException extends RuntimeException
{
    public static function forSystemKey(string $systemAccountKey): self
    {
        return new self("FIN-M7-07: Tidak dapat menyelesaikan pemetaan akun untuk system_account_key '{$systemAccountKey}'. Akun belum terdaftar atau belum dipetakan di Bagan Akun.");
    }

    public static function forDynamicStrategy(string $strategy, string $contextKey): self
    {
        return new self("FIN-M7-07: Strategi resolusi akun '{$strategy}' gagal menemukan akun untuk kunci '{$contextKey}'.");
    }
}
