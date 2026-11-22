<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Enums\PaymentMethod;
use App\Modules\Sales\Application\Handlers\CheckoutOrderHandler;
use App\Modules\Sales\Application\Handlers\OnlinePaymentAndWebhookHandler;
use App\Modules\Sales\Application\Handlers\VoidAndRestoreOrderHandler;
use App\Modules\Sales\Application\Services\PosTableAndCustomerSyncService;
use App\Modules\Sales\Domain\Services\OrderTotalsCalculatorService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Payment;
use App\Modules\Sales\Models\PaymentAttempt;

/**
 * Backward-compatible delegating facade for sales and cashier transaction operations (ADR-108 / STR-05).
 * All heavy operational logic is delegated to focused Application Handlers and Domain Services.
 */
class TransactionService
{
    public function __construct(
        protected ?CheckoutOrderHandler $checkoutHandler = null,
        protected ?OnlinePaymentAndWebhookHandler $onlinePaymentHandler = null,
        protected ?VoidAndRestoreOrderHandler $voidHandler = null,
        protected ?OrderTotalsCalculatorService $totalsCalculator = null,
        protected ?PosTableAndCustomerSyncService $posSyncService = null
    ) {
        $this->checkoutHandler ??= app(CheckoutOrderHandler::class);
        $this->onlinePaymentHandler ??= app(OnlinePaymentAndWebhookHandler::class);
        $this->voidHandler ??= app(VoidAndRestoreOrderHandler::class);
        $this->totalsCalculator ??= app(OrderTotalsCalculatorService::class);
        $this->posSyncService ??= app(PosTableAndCustomerSyncService::class);
    }

    /**
     * Memproses checkout pesanan POS secara atomik.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTransaction(array $data, int $userId): Order
    {
        return $this->checkoutHandler->createTransaction($data, $userId);
    }

    /**
     * Menghitung pajak dan total pesanan yang sudah dibulatkan.
     *
     * @return array{tax_amount: int, total_amount: int}
     */
    public function calculateOrderTotals(float $subtotalAmount, float $discountAmount = 0): array
    {
        return $this->totalsCalculator->calculateOrderTotals($subtotalAmount, $discountAmount);
    }

    /**
     * Mengambil metrik transaksi kasir hari ini.
     *
     * @return array{omzet: float, jumlah: int}
     */
    public function getTodayMetrics(): array
    {
        return $this->posSyncService->getTodayMetrics();
    }

    /**
     * Zero-Trust shift guard.
     */
    public function resolveOpenShiftOrFail(int $userId): int
    {
        return $this->posSyncService->resolveOpenShiftOrFail($userId);
    }

    /**
     * Resolusi baris item keranjang (opsi varian, harga katalog/tier, modifier).
     *
     * @param  array<int, int|string>  $modifierIds
     * @return array<string, mixed>
     */
    public function resolveOrderItemLine(
        \App\Modules\Catalog\Models\Product $product,
        array $modifierIds,
        int $qty,
        ?string $notes,
        ?int $variantId = null,
        ?int $customerId = null,
        ?string $channel = 'pos_cashier',
        ?float $customPrice = null,
        ?int $uomId = null
    ): array {
        return $this->totalsCalculator->resolveOrderItemLine(
            product: $product,
            modifierIds: $modifierIds,
            qty: $qty,
            notes: $notes,
            variantId: $variantId,
            customerId: $customerId,
            channel: $channel,
            customPrice: $customPrice,
            uomId: $uomId
        );
    }

    /**
     * Menyelesaikan pembayaran untuk pesanan pending (mis. Self-Order Cash).
     *
     * @param  array<int|string, mixed>  $rawPaymentLegs
     */
    public function payPendingOrder(
        Order $order,
        array $rawPaymentLegs,
        ?int $userId = null,
        ?int $shiftId = null,
        ?int $deviceId = null
    ): Order {
        return $this->checkoutHandler->payPendingOrder($order, $rawPaymentLegs, $userId, $shiftId, $deviceId);
    }

    /**
     * Memperbarui status order berdasarkan notifikasi callback gerbang pembayaran.
     */
    public function updateStatusFromMidtransNotification(
        string $orderCode,
        string $transactionStatus,
        ?string $fraudStatus,
        ?int $grossAmount = null,
        ?int $originalAmount = null,
    ): Order {
        return $this->onlinePaymentHandler->updateStatusFromMidtransNotification(
            $orderCode,
            $transactionStatus,
            $fraudStatus,
            $grossAmount,
            $originalAmount
        );
    }

    /**
     * Menginisialisasi percobaan pembayaran non-tunai / gateway.
     */
    public function initiatePaymentAttempt(
        Order $order,
        PaymentMethod|string $method,
        string|int $amount,
        string $idempotencyKey,
        ?int $deviceId = null,
        ?int $shiftId = null,
        ?int $userId = null,
        ?string $provider = null,
    ): PaymentAttempt {
        return $this->onlinePaymentHandler->initiatePaymentAttempt(
            order: $order,
            method: $method,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            deviceId: $deviceId,
            shiftId: $shiftId,
            userId: $userId,
            provider: $provider
        );
    }

    /**
     * Menyelesaikan percobaan pembayaran yang telah diverifikasi.
     *
     * @param  array<string, mixed>  $providerMeta
     */
    public function settlePaymentAttempt(PaymentAttempt $attempt, array $providerMeta = []): Payment
    {
        return $this->onlinePaymentHandler->settlePaymentAttempt($attempt, $providerMeta);
    }

    /**
     * Memproses webhook notifikasi status dari payment gateway.
     *
     * @param  array<string, mixed>  $verifiedPayload
     */
    public function handleMidtransWebhook(array $verifiedPayload): void
    {
        $this->onlinePaymentHandler->handleMidtransWebhook($verifiedPayload);
    }

    /**
     * Meminta Snap token dari gateway pembayaran.
     *
     * @param  array<string, mixed>  $snapParams
     */
    public function requestMidtransSnapToken(array $snapParams, Order $order): string
    {
        return $this->onlinePaymentHandler->requestMidtransSnapToken($snapParams, $order);
    }

    /**
     * Mendapatkan status transaksi dari API payment gateway.
     */
    public function getMidtransTransactionStatus(string $orderId): object
    {
        return $this->onlinePaymentHandler->getMidtransTransactionStatus($orderId);
    }

    /**
     * Sinkronisasi aktif status pesanan pending ke payment gateway.
     */
    public function syncOrderWithMidtrans(Order $order): Order
    {
        return $this->onlinePaymentHandler->syncOrderWithMidtrans($order);
    }

    /**
     * Melakukan pembatalan (void) pesanan tunai yang sudah Paid.
     */
    public function voidOrder(Order $order, string $reason, int $userId): void
    {
        $this->voidHandler->voidOrder($order, $reason, $userId);
    }

    /**
     * Memulihkan stok untuk pesanan yang dibatalkan/void secara idempoten.
     */
    public function restoreStockForOrder(Order $order): void
    {
        $this->voidHandler->restoreStockForOrder($order);
    }

    /**
     * Sinkronkan status meja saat pesanan difinalisasi.
     */
    public function syncTableStatusOnOrderFinalized(Order $order): void
    {
        $this->posSyncService->syncTableStatusOnOrderFinalized($order);
    }

    /**
     * Validasi & normalisasi struktural leg pembayaran.
     *
     * @param  array<int|string, mixed>  $rawLegs
     * @return array<int, array<string, mixed>>
     */
    public function parsePaymentLegs(array $rawLegs): array
    {
        return $this->checkoutHandler->parsePaymentLegs($rawLegs);
    }

    /**
     * Menjalankan pemrosesan tender leg pembayaran.
     *
     * @param  array<int, array<string, mixed>>  $legs
     */
    public function processPaymentLegTenders(Order $order, array $legs, ?int $userId): void
    {
        $this->checkoutHandler->processPaymentLegTenders($order, $legs, $userId);
    }

    /**
     * Memberikan poin loyalitas kepada pelanggan.
     */
    public function grantLoyaltyPoints(int $customerId, Order $order, float|int $dppAmount): void
    {
        $this->onlinePaymentHandler->grantLoyaltyPoints($customerId, $order, $dppAmount);
    }
}
