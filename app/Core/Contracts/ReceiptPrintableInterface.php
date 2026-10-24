<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Interface for domain entities that can be formatted and sent to physical ESC/POS receipt printers.
 */
interface ReceiptPrintableInterface
{
    public function getReceiptNumber(): string;

    public function getReceiptPayload(): array;
}
