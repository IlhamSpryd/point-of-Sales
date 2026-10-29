<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use RuntimeException;

class ReconciliationGateFailedException extends RuntimeException
{
    /**
     * @param  array<string, array<string, mixed>>  $failedGates
     */
    public function __construct(
        string $message,
        protected array $failedGates = []
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, array<string, mixed>>  $failedGates
     */
    public static function withFailedGates(string $periodCode, array $failedGates): self
    {
        $count = count($failedGates);
        $gateList = implode(', ', array_keys($failedGates));

        return new self(
            "FIN-M7-01 / REQ-M7-CLS-01: Penutupan periode {$periodCode} ditolak. Terdapat {$count} gerbang rekonsiliasi yang gagal ({$gateList}).",
            $failedGates
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getFailedGates(): array
    {
        return $this->failedGates;
    }
}
