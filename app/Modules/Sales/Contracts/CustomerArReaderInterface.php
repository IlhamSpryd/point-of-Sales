<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

/**
 * Read contract for Customer Accounts Receivable balances and open invoices.
 * Decouples Customers module from Sales internal models (ADR-111).
 */
interface CustomerArReaderInterface
{
    public function getCustomerOutstandingBalance(int $tenantId, int $customerId): float|string;

    public function hasOverdueInvoices(int $tenantId, int $customerId): bool;
}
