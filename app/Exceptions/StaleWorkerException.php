<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain 1 (TX-001) — Exception yang dilempar ketika worker provisioning kehilangan
 * fencing token (lease kedaluwarsa atau diklaim worker lain) di dalam batas transaksi bisnis.
 *
 * Memicu rollback total pada DB::transaction sehingga worker stale tidak dapat
 * meng-commit tenant, user, store, outbox, atau memfinalisasi ledger.
 */
class StaleWorkerException extends RuntimeException
{
    public function __construct(
        string $message = 'Provisioning worker is stale: lease expired or fencing token superseded.',
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $fencingToken = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
