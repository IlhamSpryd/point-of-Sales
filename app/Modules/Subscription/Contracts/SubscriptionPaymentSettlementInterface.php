<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Contracts;

/**
 * Public Contract: Subscription Payment Settlement Interface (EXT-01, ADR-113)
 * Provides boundary decoupling for incoming payment provider webhooks (e.g. Midtrans)
 * to settle and activate SaaS subscription upgrades.
 */
interface SubscriptionPaymentSettlementInterface
{
    /**
     * Determine if this settlement handler manages the given order/payment reference.
     */
    public function handlesOrderId(string $orderId): bool;

    /**
     * Atomically process subscription settlement notification payload from webhook.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function settleFromWebhook(
        string $orderId,
        string $transactionStatus,
        ?string $fraudStatus,
        array $rawPayload
    ): bool;
}
