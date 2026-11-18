<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/**
 * Public contract for customer segment, group, and loyalty eligibility lookup.
 * Decouples Pricing domain from direct Customers model dependencies (ADR-111).
 */
interface CustomerSegmentReaderInterface
{
    public function getCustomerGroupId(int $tenantId, int $customerId): ?int;

    public function isLoyaltyEligible(int $tenantId, int $customerId): bool;
}
