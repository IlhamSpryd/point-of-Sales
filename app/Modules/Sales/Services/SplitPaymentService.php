<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Sales\Exceptions\ShiftClosedException;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SplitPaymentService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext,
        protected ShiftManagementService $shiftManagementService,
        protected FinancialOutboxDispatcherService $financialOutboxDispatcher
    ) {}

    /**
     * Memproses pembayaran multi-tender (split payment) untuk suatu pesanan kasir.
     *
     * Format array $legs:
     * [
     *   [
     *     'payment_method' => 'cash'|'qris'|'card'|'voucher'|'bank_transfer',
     *     'amount' => 50000, // Nominal yang dialokasikan melunasi pesanan
     *     'amount_tendered' => 60000, // Uang fisik yang diterima (wajib >= amount untuk cash)
     *     'reference_number' => 'REF-123456', // Opsional untuk non-cash
     *   ],
     *   ...
     * ]
     *
     * Penegakan:
     * - SUM(amount) == order.order_amount
     * - SUM(amount_tendered) - SUM(change_amount) == order.order_amount (T-POS-03 Parity)
     * - trg_payments_prevent_overpayment di tingkat basis data.
     *
     * @param  array<int, array{payment_method: string, amount: int|float|string, amount_tendered?: int|float|string|null, reference_number?: string|null}>  $legs
     * @return array{order: Order, payments: array<int, Payment>, total_tendered: int, total_change: int, net_paid: int}
     *
     * @throws DomainException|ShiftClosedException|ValidationException
     */
    public function processSplitPayment(
        Order $order,
        array $legs,
        int $userId,
        ?int $shiftId = null,
        ?int $deviceId = null,
        ?string $idempotencyKey = null
    ): array {
        if (empty($legs)) {
            throw new DomainException('Payment legs cannot be empty.');
        }

        $user = User::withoutGlobalScopes()->find($userId);
        if (! $user) {
            throw new DomainException('Processing cashier was not found.');
        }

        $tenantId = (int) ($order->tenant_id ?? $this->tenantContext->getTenantId() ?? $user->tenant_id);
        $storeId = (int) ($order->store_id ?? $this->tenantContext->getStoreId() ?? $user->store_id);

        return DB::transaction(function () use (
            $order, $legs, $userId, $tenantId, $storeId, $shiftId, $deviceId, $idempotencyKey
        ) {
            // 1. Kunci Order secara pesimis (lockForUpdate)
            $lockedOrder = Order::withoutGlobalScopes()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                throw new DomainException('Order not found.');
            }

            if (in_array($lockedOrder->order_status, [OrderStatus::Paid, OrderStatus::Completed], true)) {
                throw new DomainException("Order #{$lockedOrder->order_code} is already fully paid (immutable).");
            }

            if (in_array($lockedOrder->order_status, [OrderStatus::Void, OrderStatus::Cancelled], true)) {
                throw new DomainException("Order #{$lockedOrder->order_code} has been cancelled/voided.");
            }

            // 2. Validasi Sesi Shift Kasir Aktif (T-POS-01)
            $activeShift = null;
            if ($shiftId !== null) {
                $activeShift = Shift::withoutGlobalScopes()
                    ->where('id', $shiftId)
                    ->lockForUpdate()
                    ->first();
            } else {
                $activeShift = $this->shiftManagementService->getActiveShift($tenantId, $storeId, $userId);
            }

            if (! $activeShift || $activeShift->status !== 'open') {
                throw new ShiftClosedException('Cashier cannot transact outside an active register shift. Please open a shift before continuing.');
            }

            $resolvedShiftId = $activeShift->id;
            $resolvedDeviceId = $deviceId ?? $activeShift->device_id ?? $lockedOrder->created_by_device_id;

            // 3. Hitung dan Validasi Split Legs
            $orderAmount = (int) $lockedOrder->order_amount;
            $sumAllocated = 0;
            $sumTendered = 0;
            $sumChange = 0;

            $normalizedLegs = [];
            foreach ($legs as $idx => $leg) {
                $method = strtolower(trim((string) $leg['payment_method']));
                $allocated = (int) round((float) $leg['amount']);
                $tendered = isset($leg['amount_tendered'])
                    ? (int) round((float) $leg['amount_tendered'])
                    : $allocated;

                if ($allocated <= 0) {
                    throw new DomainException('Payment allocation amount on leg #'.($idx + 1).' must be greater than 0.');
                }

                $change = 0;
                if ($method === 'cash') {
                    if ($tendered < $allocated) {
                        throw new DomainException("Cash tendered ({$tendered}) is less than the allocated amount ({$allocated}).");
                    }
                    $change = $tendered - $allocated;
                } else {
                    // Non-cash tidak boleh over-tender / menghasilkan kembalian uang fisik
                    if ($tendered !== $allocated) {
                        $tendered = $allocated;
                    }
                }

                $sumAllocated += $allocated;
                $sumTendered += $tendered;
                $sumChange += $change;

                $normalizedLegs[] = [
                    'method' => $method,
                    'amount' => $allocated,
                    'tendered' => $tendered,
                    'change' => $change,
                    'reference' => $leg['reference_number'] ?? null,
                ];
            }

            // Validasi Keseimbangan Total (T-POS-03)
            if ($sumAllocated !== $orderAmount) {
                throw new DomainException(
                    'Total allocated payment ('.number_format($sumAllocated, 2).
                    ') does not match order total amount ('.number_format($orderAmount, 2).').'
                );
            }

            $netTenderBalance = $sumTendered - $sumChange;
            if ($netTenderBalance !== $orderAmount) {
                throw new DomainException(
                    "Tender balance failure: Total tendered ({$sumTendered}) minus change ({$sumChange}) = {$netTenderBalance}, does not equal order total {$orderAmount}."
                );
            }

            // 4. Buat Record Pembayaran (Payments - Append Only)
            $createdPayments = [];
            $baseIdempotency = $idempotencyKey ?: (string) Str::uuid();

            foreach ($normalizedLegs as $idx => $leg) {
                $legIdempotency = "{$baseIdempotency}-leg-{$idx}";

                $payment = Payment::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'order_id' => $lockedOrder->id,
                    'shift_id' => $resolvedShiftId,
                    'device_id' => $resolvedDeviceId,
                    'payment_method' => $leg['method'],
                    'amount' => $leg['amount'],
                    'amount_tendered' => $leg['tendered'],
                    'change_amount' => $leg['change'],
                    'reference_number' => $leg['reference'],
                    'status' => 'captured',
                    'captured_at' => now(),
                    'processed_by' => $userId,
                    'idempotency_key' => $legIdempotency,
                ]);

                $createdPayments[] = $payment;
            }

            // Cari metode pembayaran dominan untuk pesanan
            $dominantLeg = collect($normalizedLegs)->sortByDesc('amount')->first();
            $dominantMethod = $dominantLeg['method'] ?? 'cash';
            $pmEnum = match ($dominantMethod) {
                'qris' => PaymentMethod::Qris,
                'card', 'debit', 'credit' => PaymentMethod::Card,
                'ewallet' => PaymentMethod::Ewallet,
                'gift_card' => PaymentMethod::GiftCard,
                'store_credit' => PaymentMethod::StoreCredit,
                default => PaymentMethod::Cash,
            };

            // 5. Perbarui Status Pesanan Menjadi Paid
            $lockedOrder->forceFill([
                'order_status' => OrderStatus::Paid,
                'payment_method' => $pmEnum,
                'cash_received' => $sumTendered,
                'order_change' => $sumChange,
                'shift_id' => $resolvedShiftId,
            ])->save();

            // 6. Bebaskan Meja jika meja terkait berstatus aktif
            if (! empty($lockedOrder->table_id)) {
                DB::table('tables')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('id', $lockedOrder->table_id)
                    ->update([
                        'operational_status' => 'available',
                        'updated_at' => now(),
                    ]);
            }

            // 7. Dispatch Event Outbox untuk Keuangan / Akuntansi
            try {
                $this->financialOutboxDispatcher->dispatchPosOrderPaid($lockedOrder);
            } catch (\Throwable $e) {
                Log::warning("[FINANCE OUTBOX] Gagal dispatch outbox order paid #{$lockedOrder->id}: ".$e->getMessage());
            }

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'pos.split_payment_completed',
                userId: $userId,
                storeId: $storeId,
                subjectType: Order::class,
                subjectId: $lockedOrder->id,
                description: "Split-tender payment for order {$lockedOrder->order_code} completed: Total ".number_format($orderAmount, 2)." ({$sumTendered} tendered - {$sumChange} change)",
                metadata: [
                    'order_id' => $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'order_amount' => $orderAmount,
                    'legs_count' => count($normalizedLegs),
                    'total_tendered' => $sumTendered,
                    'total_change' => $sumChange,
                ]
            );

            return [
                'order' => $lockedOrder,
                'payments' => $createdPayments,
                'total_tendered' => $sumTendered,
                'total_change' => $sumChange,
                'net_paid' => $netTenderBalance,
            ];
        });
    }
}
