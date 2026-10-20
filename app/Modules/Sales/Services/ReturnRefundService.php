<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Enums\ReturnDispositionEnum;
use App\Enums\StockMovementType;
use App\Models\CashDrawerMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ReturnRefundService: Layanan sentral untuk orkestrasi Retur Barang (OrderReturn),
 * Pengembalian Dana (PaymentRefund), dan Disposisi Inventori (Inventory Disposition).
 *
 * Menegakkan Invariant:
 * - INV-01: Multitenancy & Branch scoping
 * - INV-06: Total payment parity
 * - INV-07: Batas maksimum refund: SUM(refund_amount) <= payment.amount
 * - INV-08: Batas maksimum retur per item: SUM(quantity_returned) <= order_item.qty
 * - INV-10: Penguncian baris kanonis: Order -> Payments -> Shift (jika tunai) -> OrderItems
 * - INV-11: Idempotensi dan transisi status monotonik
 * - INV-12: Otorisasi Role, verifikasi PIN Supervisor/Manager, dan jejak AuditTrail
 */
class ReturnRefundService
{
    public function __construct(
        protected CashierPinService $pinService,
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Memproses permohonan retur dan refund secara atomik dalam satu transaksi database.
     *
     * @param  array<int, array{order_item_id: int, quantity: int, disposition: string|ReturnDispositionEnum, disposition_reason?: ?string}>  $returnItemsWithDisposition
     * @param  array<int, array{payment_id: int, amount: int|float, refund_method?: ?string, is_method_override?: bool, method_override_reason?: ?string}>  $refundAllocations
     *
     * @throws ValidationException|DomainException
     */
    public function createReturnAndRefund(
        Order $order,
        array $returnItemsWithDisposition,
        array $refundAllocations,
        int $shiftId,
        int $deviceId,
        int $actorUserId,
        string $supervisorPin,
        string $reason,
        string $idempotencyKey
    ): OrderReturn {
        $tenantContext = app(TenantContext::class);
        $tenantId = (int) $tenantContext->requireTenantId();

        if ((int) $order->tenant_id !== $tenantId) {
            throw new DomainException('Akses lintas tenant ditolak.');
        }

        return $tenantContext->runAs($order->tenant_id, $order->store_id, function () use (
            $order,
            $returnItemsWithDisposition,
            $refundAllocations,
            $shiftId,
            $deviceId,
            $actorUserId,
            $supervisorPin,
            $reason,
            $idempotencyKey
        ) {
            // 1. Cek Idempotensi
            $existingReturn = OrderReturn::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingReturn) {
                return $existingReturn;
            }

            // 2. Verifikasi PIN Supervisor / Manager
            $actorUser = User::withoutGlobalScopes()->findOrFail($actorUserId);
            $approvedByUserId = $this->resolveApproverUserId($actorUser, $supervisorPin, $order);

            // 3. Eksekusi Atomik dengan Penguncian Baris Kanonis (INV-10)
            return DB::transaction(function () use (
                $order,
                $returnItemsWithDisposition,
                $refundAllocations,
                $shiftId,
                $deviceId,
                $actorUserId,
                $approvedByUserId,
                $reason,
                $idempotencyKey
            ) {
                // 3.1 Lock Order
                $lockedOrder = Order::withoutGlobalScopes()
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 3.2 Lock Payment rows (ORDER BY id ASC untuk cegah deadlock)
                $paymentIds = collect($refundAllocations)->pluck('payment_id')->unique()->sort()->values()->all();
                $lockedPayments = Payment::withoutGlobalScopes()
                    ->whereIn('id', $paymentIds)
                    ->where('order_id', $lockedOrder->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // 3.3 Lock Shift jika ada refund tunai
                $hasCashRefund = collect($refundAllocations)->contains(function ($alloc) use ($lockedPayments) {
                    $payment = $lockedPayments->get($alloc['payment_id'] ?? null);
                    $origMethod = $payment ? (is_string($payment->payment_method) ? $payment->payment_method : $payment->payment_method->value) : null;
                    $targetMethod = $alloc['refund_method'] ?? $origMethod;

                    return $targetMethod === 'cash';
                });

                $lockedShift = null;
                if ($hasCashRefund) {
                    $lockedShift = Shift::withoutGlobalScopes()
                        ->where('id', $shiftId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedShift->status !== 'open') {
                        throw new DomainException("Shift #{$shiftId} tidak dalam status terbuka.");
                    }
                }

                // 3.4 Lock Order Items & Products
                $orderItemIds = collect($returnItemsWithDisposition)->pluck('order_item_id')->unique()->sort()->values()->all();
                $lockedOrderItems = OrderItem::withoutGlobalScopes()
                    ->whereIn('id', $orderItemIds)
                    ->where('order_id', $lockedOrder->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $productIds = $lockedOrderItems->pluck('product_id')->unique()->sort()->values()->all();
                $lockedProducts = Product::withTrashed()
                    ->with('ingredients')
                    ->whereIn('id', $productIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // 4. Validasi Kuantitas Retur & Disposisi (INV-08, AC-3.1)
                if (empty($returnItemsWithDisposition)) {
                    throw ValidationException::withMessages([
                        'items' => 'Minimal satu item pesanan wajib disertakan dalam permohonan retur.',
                    ]);
                }

                $validatedReturnItems = [];
                $subtotalReturned = 0;

                foreach ($returnItemsWithDisposition as $itemInput) {
                    $itemId = (int) ($itemInput['order_item_id'] ?? 0);
                    $qty = (int) ($itemInput['quantity'] ?? 0);
                    $rawDisposition = $itemInput['disposition'] ?? null;
                    $dispositionReason = $itemInput['disposition_reason'] ?? null;

                    $orderItem = $lockedOrderItems->get($itemId);
                    if (! $orderItem) {
                        throw ValidationException::withMessages([
                            'items' => "Item pesanan #{$itemId} tidak ditemukan pada pesanan #{$lockedOrder->order_code}.",
                        ]);
                    }

                    if ($qty <= 0) {
                        throw ValidationException::withMessages([
                            'items' => 'Kuantitas retur per item harus lebih dari nol.',
                        ]);
                    }

                    $disposition = $rawDisposition instanceof ReturnDispositionEnum
                        ? $rawDisposition
                        : ReturnDispositionEnum::tryFrom((string) $rawDisposition);

                    if (! $disposition) {
                        throw ValidationException::withMessages([
                            'disposition' => "Disposisi '{$rawDisposition}' tidak valid. Pilihan: restock, quarantine, write_off_waste.",
                        ]);
                    }

                    // INV-08: SUM(quantity_returned) <= order_item.qty
                    $alreadyReturnedQty = (int) OrderReturnItem::withoutGlobalScopes()
                        ->where('order_item_id', $orderItem->id)
                        ->sum('quantity_returned');

                    $remainingReturnable = (int) $orderItem->qty - $alreadyReturnedQty;
                    if ($qty > $remainingReturnable) {
                        throw new DomainException("Kuantitas retur ({$qty}) melebihi sisa yang dapat diretur ({$remainingReturnable}) untuk item '{$orderItem->product?->product_name}'.");
                    }

                    // AC-3.1: Larangan Restock untuk produk F&B berbasis resep
                    $product = $lockedProducts->get($orderItem->product_id);
                    if ($disposition->isRestock() && $product && $product->ingredients->isNotEmpty()) {
                        throw new DomainException("Larangan Restock: Produk F&B berbasis resep ('{$product->product_name}') tidak boleh direstock ke inventori layak jual.");
                    }

                    $linePrice = (float) $orderItem->order_price;
                    $lineRefundAmount = $linePrice * $qty;
                    $subtotalReturned += $lineRefundAmount;

                    $validatedReturnItems[] = [
                        'order_item' => $orderItem,
                        'product' => $product,
                        'quantity' => $qty,
                        'unit_price' => $linePrice,
                        'line_amount' => $lineRefundAmount,
                        'disposition' => $disposition,
                        'disposition_reason' => $dispositionReason,
                    ];
                }

                // 5. Validasi Refund Finansial (INV-07, AC-3.2, AC-3.3)
                if (empty($refundAllocations)) {
                    throw ValidationException::withMessages([
                        'refunds' => 'Minimal satu alokasi refund pembayaran wajib disertakan.',
                    ]);
                }

                $validatedAllocations = [];
                $totalRefundAmount = 0;

                foreach ($refundAllocations as $alloc) {
                    $paymentId = (int) ($alloc['payment_id'] ?? 0);
                    $amount = (float) ($alloc['amount'] ?? 0);

                    $payment = $lockedPayments->get($paymentId);
                    if (! $payment) {
                        throw ValidationException::withMessages([
                            'refunds' => "Pembayaran #{$paymentId} tidak valid untuk pesanan #{$lockedOrder->order_code}.",
                        ]);
                    }

                    if ($amount <= 0) {
                        throw ValidationException::withMessages([
                            'refunds' => 'Nominal refund harus lebih dari nol.',
                        ]);
                    }

                    // INV-07: SUM(refund_amount) <= payment.amount
                    $alreadyRefunded = (float) PaymentRefund::withoutGlobalScopes()
                        ->where('payment_id', $payment->id)
                        ->whereNotIn('request_status', [PaymentRefund::REQUEST_FAILED, PaymentRefund::REQUEST_CANCELLED])
                        ->sum('amount');

                    $remainingRefundable = (float) $payment->amount - $alreadyRefunded;
                    if ($amount > $remainingRefundable) {
                        throw new DomainException('Nominal refund (Rp'.number_format($amount, 0, ',', '.').') melebihi sisa yang dapat direfund (Rp'.number_format($remainingRefundable, 0, ',', '.').") pada pembayaran #{$payment->id}.");
                    }

                    $origMethod = is_string($payment->payment_method) ? $payment->payment_method : $payment->payment_method->value;
                    $targetMethod = $alloc['refund_method'] ?? $origMethod;
                    $isOverride = ($origMethod !== 'cash' && $targetMethod === 'cash');

                    if ($isOverride && empty($alloc['is_method_override'])) {
                        throw new DomainException("Pengecualian refund tunai untuk metode {$origMethod} membutuhkan konfirmasi otorisasi supervisor.");
                    }

                    // AC-3.3: Jika refund tunai, periksa kecukupan fisik kas di laci shift aktif
                    if ($targetMethod === 'cash') {
                        $opening = (float) $lockedShift->opening_balance;
                        $cashIn = (float) CashDrawerMovement::where('shift_id', $lockedShift->id)->where('type', 'cash_in')->sum('amount');
                        $cashPayments = (float) Payment::where('shift_id', $lockedShift->id)->where('payment_method', 'cash')->where('status', 'captured')->sum('amount');
                        $cashOut = (float) CashDrawerMovement::where('shift_id', $lockedShift->id)->where('type', 'cash_out')->sum('amount');
                        $availableCash = $opening + $cashIn + $cashPayments - $cashOut;

                        if ($amount > $availableCash) {
                            throw new DomainException("Kas fisik di laci shift #{$lockedShift->id} tidak mencukupi untuk refund tunai (Tersedia: Rp".number_format($availableCash, 0, ',', '.').', Dibutuhkan: Rp'.number_format($amount, 0, ',', '.').').');
                        }
                    }

                    $totalRefundAmount += $amount;

                    $validatedAllocations[] = [
                        'payment' => $payment,
                        'amount' => $amount,
                        'original_method' => $origMethod,
                        'refund_method' => $targetMethod,
                        'is_override' => $isOverride,
                        'override_reason' => $alloc['method_override_reason'] ?? null,
                    ];
                }

                // 6. Buat Record OrderReturn
                $returnNumber = 'RET-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $totalOrderQty = (int) $lockedOrder->orderItems()->sum('qty');
                $totalReturnedAll = (int) OrderReturnItem::whereIn('order_item_id', $lockedOrder->orderItems()->pluck('id'))->sum('quantity_returned')
                    + collect($validatedReturnItems)->sum('quantity');

                $returnType = $totalReturnedAll >= $totalOrderQty ? OrderReturn::TYPE_FULL : OrderReturn::TYPE_PARTIAL;

                $orderReturn = OrderReturn::forceCreate([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'store_id' => $lockedOrder->store_id,
                    'order_id' => $lockedOrder->id,
                    'shift_id' => $shiftId,
                    'device_id' => $deviceId,
                    'return_number' => $returnNumber,
                    'idempotency_key' => $idempotencyKey,
                    'status' => OrderReturn::STATUS_COMPLETED,
                    'return_type' => $returnType,
                    'subtotal_returned' => $subtotalReturned,
                    'discount_reversed' => 0.00,
                    'tax_returned' => 0.00,
                    'service_charge_returned' => 0.00,
                    'total_refundable_amount' => $totalRefundAmount,
                    'reason' => $reason,
                    'requested_by_user_id' => $actorUserId,
                    'approved_by_user_id' => $approvedByUserId,
                    'completed_at' => now(),
                ]);

                // 7. Proses Setiap Return Item & Mutasi Stok (AC-3.1)
                foreach ($validatedReturnItems as $vItem) {
                    $orderItem = $vItem['order_item'];
                    $product = $vItem['product'];
                    $qty = $vItem['quantity'];
                    $disposition = $vItem['disposition'];

                    $stockMovementId = null;

                    // Restock produk ritel: Tambah StockMovement baru (+qty) dan perbarui StockBalance
                    if ($disposition->isRestock() && $product && $product->ingredients->isEmpty()) {
                        $movement = StockMovement::create([
                            'tenant_id' => $lockedOrder->tenant_id,
                            'store_id' => $lockedOrder->store_id,
                            'product_id' => $product->id,
                            'order_id' => $lockedOrder->id,
                            'order_item_id' => $orderItem->id,
                            'type' => StockMovementType::RestoreCompensation,
                            'quantity' => $qty,
                            'reason' => "Restock retur item pesanan #{$lockedOrder->order_code} (Retur #{$returnNumber})",
                            'idempotency_key' => "return_restock:{$returnNumber}:{$orderItem->id}",
                        ]);

                        $stockMovementId = $movement->id;

                        // Perbarui stok produk dan balance otoritatif
                        $product->stock = (int) round((float) $product->stock + (float) $qty);
                        $product->save();
                    }

                    OrderReturnItem::forceCreate([
                        'tenant_id' => $lockedOrder->tenant_id,
                        'store_id' => $lockedOrder->store_id,
                        'order_return_id' => $orderReturn->id,
                        'order_item_id' => $orderItem->id,
                        'product_id' => $orderItem->product_id,
                        'quantity_returned' => $qty,
                        'unit_price_snapshot' => $vItem['unit_price'],
                        'line_discount_reversed' => 0.00,
                        'line_writeoff_or_refund_amount' => $vItem['line_amount'],
                        'disposition' => $disposition,
                        'disposition_reason' => $vItem['disposition_reason'],
                        'stock_movement_id' => $stockMovementId,
                    ]);
                }

                // 8. Proses Setiap Refund Finansial & Mutasi Cash Drawer (AC-3.3, AC-3.4)
                foreach ($validatedAllocations as $i => $vAlloc) {
                    $payment = $vAlloc['payment'];
                    $amount = $vAlloc['amount'];
                    $targetMethod = $vAlloc['refund_method'];
                    $refundNumber = 'REF-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                    $cashDrawerMovementId = null;

                    // Refund tunai wajib memotong kas laci pada shift aktif (AC-3.3)
                    if ($targetMethod === 'cash') {
                        $cdm = CashDrawerMovement::forceCreate([
                            'tenant_id' => $lockedOrder->tenant_id,
                            'store_id' => $lockedOrder->store_id,
                            'device_id' => $deviceId,
                            'shift_id' => $lockedShift->id,
                            'type' => 'cash_out',
                            'category' => 'refund',
                            'amount' => (int) $amount,
                            'reason' => "Refund tunai untuk order #{$lockedOrder->order_code} (Retur #{$returnNumber})",
                            'created_by' => $actorUserId,
                            'approved_by' => $approvedByUserId,
                            'idempotency_key' => "refund_cdm:{$refundNumber}",
                        ]);

                        $cashDrawerMovementId = $cdm->id;
                    }

                    PaymentRefund::forceCreate([
                        'tenant_id' => $lockedOrder->tenant_id,
                        'store_id' => $lockedOrder->store_id,
                        'order_id' => $lockedOrder->id,
                        'payment_id' => $payment->id,
                        'order_return_id' => $orderReturn->id,
                        'shift_id' => $shiftId,
                        'device_id' => $deviceId,
                        'idempotency_key' => "{$idempotencyKey}-ref-{$i}",
                        'refund_number' => $refundNumber,
                        'amount' => $amount,
                        'currency' => 'IDR',
                        'original_payment_method' => $vAlloc['original_method'],
                        'refund_method' => $targetMethod,
                        'is_method_override' => $vAlloc['is_override'] ? 1 : 0,
                        'method_override_reason' => $vAlloc['override_reason'],
                        'request_status' => PaymentRefund::REQUEST_COMPLETED,
                        'provider_status' => PaymentRefund::PROVIDER_NOT_APPLICABLE,
                        'financial_status' => PaymentRefund::FINANCIAL_SETTLED,
                        'provider_refund_reference' => null,
                        'cash_drawer_movement_id' => $cashDrawerMovementId,
                        'reason' => $reason,
                        'requested_by_user_id' => $actorUserId,
                        'approved_by_user_id' => $approvedByUserId,
                        'settled_at' => now(),
                    ]);
                }

                // AC-3.4: Baris orders dan payments ASLI TIDAK diubah nilai nominalnya
                // Nilai order_amount dan payment.amount historis dipertahankan utuh.

                // Audit Trail
                $this->auditTrail->log(
                    tenantId: (int) $lockedOrder->tenant_id,
                    action: 'order.return_and_refund',
                    userId: $actorUserId,
                    storeId: (int) $lockedOrder->store_id,
                    subjectType: OrderReturn::class,
                    subjectId: (int) $orderReturn->id,
                    description: "Retur #{$returnNumber} dan Refund sebesar Rp".number_format($totalRefundAmount, 0, ',', '.')." disetujui untuk pesanan #{$lockedOrder->order_code}.",
                    metadata: [
                        'order_id' => $lockedOrder->id,
                        'return_number' => $returnNumber,
                        'total_refund' => $totalRefundAmount,
                        'approved_by' => $approvedByUserId,
                    ]
                );

                return $orderReturn;
            }, attempts: 3);
        });
    }

    /**
     * Menyelesaikan/rekonsiliasi refund provider yang sempat menggantung (unknown_timeout).
     */
    public function reconcilePendingProviderRefund(PaymentRefund $refund): PaymentRefund
    {
        $tenantContext = app(TenantContext::class);

        return $tenantContext->runAs($refund->tenant_id, $refund->store_id, function () use ($refund) {
            return DB::transaction(function () use ($refund) {
                $locked = PaymentRefund::withoutGlobalScopes()->where('id', $refund->id)->lockForUpdate()->firstOrFail();

                if ($locked->financial_status === PaymentRefund::FINANCIAL_SETTLED) {
                    return $locked;
                }

                // Jika terbukti berhasil di provider
                $locked->provider_status = PaymentRefund::PROVIDER_SUCCEEDED;
                $locked->request_status = PaymentRefund::REQUEST_COMPLETED;
                $locked->financial_status = PaymentRefund::FINANCIAL_SETTLED;
                $locked->settled_at = now();
                $locked->save();

                return $locked;
            });
        });
    }

    /**
     * Memverifikasi PIN supervisor/manager yang berwenang.
     */
    protected function resolveApproverUserId(User $actorUser, string $supervisorPin, Order $order): int
    {
        $roleName = $actorUser->role?->name;
        $isSupervisorRole = in_array($roleName, ['Owner', 'Manager', 'Supervisor', 'Admin'], true)
            || (bool) $actorUser->role?->is_admin
            || (bool) $actorUser->role?->hasPermission('can_refund_order');

        // Jika aktor sendiri adalah supervisor/owner/manager
        if ($isSupervisorRole && $this->pinService->verifyPin($actorUser, $supervisorPin)) {
            return (int) $actorUser->id;
        }

        // Jika kasir reguler, cari akun Supervisor / Manager / Owner di tenant yang sama dengan PIN yang cocok
        $approver = User::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('is_active', true)
            ->whereHas('role', function ($q) {
                $q->whereIn('name', ['Owner', 'Manager', 'Supervisor', 'Admin']);
            })
            ->get()
            ->first(function (User $supervisor) use ($supervisorPin) {
                return $this->pinService->verifyPin($supervisor, $supervisorPin);
            });

        if (! $approver) {
            throw ValidationException::withMessages([
                'supervisor_pin' => 'PIN Supervisor/Manager yang dimasukkan tidak valid atau tidak berwenang.',
            ]);
        }

        return (int) $approver->id;
    }
}
