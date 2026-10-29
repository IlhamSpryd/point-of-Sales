<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Enums\ReturnDispositionEnum;
use App\Enums\StockMovementType;
use App\Models\CashDrawerMovement;
use App\Models\Device;
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
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Purchasing\Services\DocumentSequenceService;
use App\Modules\Sales\Enums\ArLedgerEntryTypeEnum;
use App\Modules\Sales\Enums\CreditNoteStatusEnum;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\AccountingReceivableOutbox;
use App\Modules\Sales\Models\ArLedgerEntry;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesCreditNoteApplication;
use App\Services\AuditTrailService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ReturnRefundService: Layanan sentral untuk orkestrasi Retur Barang (OrderReturn),
 * Pengembalian Dana (PaymentRefund), Nota Kredit (SalesCreditNote), dan Disposisi Inventori.
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
        protected AuditTrailService $auditTrail,
        protected ?DocumentSequenceService $sequenceService = null
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

                $balancesAuthoritative = (bool) config('pos.stock_balances_authoritative', false);
                $lockedProductBalances = $balancesAuthoritative && ! empty($productIds)
                    ? DB::table('product_stock_balances')
                        ->where('tenant_id', $lockedOrder->tenant_id)
                        ->where('store_id', $lockedOrder->store_id)
                        ->whereIn('product_id', $productIds)
                        ->orderBy('product_id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('product_id')
                    : collect();

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
                        $movement = StockMovement::firstOrCreate(
                            ['idempotency_key' => "return_restock:{$returnNumber}:{$orderItem->id}"],
                            [
                                'tenant_id' => $lockedOrder->tenant_id,
                                'store_id' => $lockedOrder->store_id,
                                'product_id' => $product->id,
                                'order_id' => $lockedOrder->id,
                                'order_item_id' => $orderItem->id,
                                'type' => StockMovementType::RestoreCompensation,
                                'quantity' => $qty,
                                'reason' => "Restock retur item pesanan #{$lockedOrder->order_code} (Retur #{$returnNumber})",
                            ]
                        );

                        $stockMovementId = $movement->id;

                        if ($movement->wasRecentlyCreated) {
                            if ($balancesAuthoritative) {
                                $balance = $lockedProductBalances->get($product->id);
                                if ($balance === null) {
                                    DB::table('product_stock_balances')->insertOrIgnore([
                                        'tenant_id' => $lockedOrder->tenant_id,
                                        'store_id' => $lockedOrder->store_id,
                                        'product_id' => $product->id,
                                        'quantity' => 0,
                                        'baseline_quantity' => 0,
                                        'baseline_movement_id' => 0,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }

                            // Perbarui stok produk dan balance otoritatif
                            $product->stock = (int) round((float) $product->stock + (float) $qty);
                            $product->save();
                        }
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

                try {
                    app(FinancialOutboxDispatcherService::class)->dispatchSalesReturnRefunded($orderReturn);
                } catch (\Throwable $e) {
                    Log::warning("[FINANCE OUTBOX] Gagal dispatch outbox retur #{$orderReturn->id}: ".$e->getMessage());
                }

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

    /**
     * Memproses retur barang dan menerbitkan Nota Kredit (SalesCreditNote).
     * Jika diarahkan ke faktur piutang (CustomerInvoice) yang belum lunas,
     * nota kredit dapat langsung diaplikasikan untuk mengurangi balance_due dan invoiced_exposure.
     *
     * @param  array<int, array{order_item_id: int, quantity: int, disposition: string|ReturnDispositionEnum, disposition_reason?: ?string}>  $returnItemsWithDisposition
     * @return array{return: OrderReturn, credit_note: SalesCreditNote, application: ?SalesCreditNoteApplication}
     */
    public function createReturnWithCreditNote(
        Order $order,
        array $returnItemsWithDisposition,
        int $actorUserId,
        string $supervisorPin,
        string $reason,
        string $idempotencyKey,
        ?int $customerInvoiceId = null,
        bool $autoApplyToInvoice = true,
        ?int $shiftId = null,
        ?int $deviceId = null
    ): array {
        $tenantContext = app(TenantContext::class);
        $tenantId = (int) $tenantContext->requireTenantId();

        if ((int) $order->tenant_id !== $tenantId) {
            throw new DomainException('Akses lintas tenant ditolak.');
        }

        return $tenantContext->runAs($order->tenant_id, $order->store_id, function () use (
            $order,
            $returnItemsWithDisposition,
            $actorUserId,
            $supervisorPin,
            $reason,
            $idempotencyKey,
            $customerInvoiceId,
            $autoApplyToInvoice,
            $shiftId,
            $deviceId
        ) {
            // 1. Cek Idempotensi
            $existingReturn = OrderReturn::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingReturn) {
                $existingCn = SalesCreditNote::withoutGlobalScopes()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('order_return_id', $existingReturn->id)
                    ->first();
                $existingApp = $existingCn ? SalesCreditNoteApplication::withoutGlobalScopes()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('sales_credit_note_id', $existingCn->id)
                    ->first() : null;

                return [
                    'return' => $existingReturn,
                    'credit_note' => $existingCn,
                    'application' => $existingApp,
                ];
            }

            // 2. Verifikasi PIN Supervisor / Manager
            $actorUser = User::withoutGlobalScopes()->findOrFail($actorUserId);
            $approvedByUserId = $this->resolveApproverUserId($actorUser, $supervisorPin, $order);

            // 3. Eksekusi Atomik dengan Penguncian Baris Kanonis (A0/A8)
            return DB::transaction(function () use (
                $order,
                $returnItemsWithDisposition,
                $actorUserId,
                $approvedByUserId,
                $reason,
                $idempotencyKey,
                $customerInvoiceId,
                $autoApplyToInvoice,
                $shiftId,
                $deviceId
            ) {
                // Resolusi Shift & Device jika tidak disertakan
                $resolvedShiftId = $shiftId ?: ($order->shift_id ?: Shift::withoutGlobalScopes()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('store_id', $order->store_id)
                    ->where('status', 'open')
                    ->value('id') ?: Shift::withoutGlobalScopes()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('store_id', $order->store_id)
                    ->value('id'));

                $resolvedDeviceId = $deviceId ?: ($order->device_id ?: Device::withoutGlobalScopes()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('store_id', $order->store_id)
                    ->value('id'));

                if (! $resolvedShiftId || ! $resolvedDeviceId) {
                    throw new DomainException('Shift dan Device aktif wajib tersedia untuk memproses retur.');
                }

                // 3.1 Lock Order
                $lockedOrder = Order::withoutGlobalScopes()
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 3.2 Lock Target Invoice jika disertakan
                $lockedInvoice = null;
                if ($customerInvoiceId) {
                    $lockedInvoice = CustomerInvoice::withoutGlobalScopes()
                        ->where('tenant_id', $lockedOrder->tenant_id)
                        ->where('id', $customerInvoiceId)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                // Resolusi Customer
                $customerId = $lockedOrder->customer_id ?: ($lockedInvoice?->customer_id);
                if (! $customerId) {
                    throw new DomainException('Retur dengan Nota Kredit membutuhkan data pelanggan terdaftar (B2B/B2C).');
                }

                // 3.3 Lock Customer Credit Account jika ada
                $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
                    ->where('tenant_id', $lockedOrder->tenant_id)
                    ->where('customer_id', $customerId)
                    ->lockForUpdate()
                    ->first();

                // 3.4 Lock Order Items & Products (ORDER BY id ASC)
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

                $balancesAuthoritative = (bool) config('pos.stock_balances_authoritative', false);
                $lockedProductBalances = $balancesAuthoritative && ! empty($productIds)
                    ? DB::table('product_stock_balances')
                        ->where('tenant_id', $lockedOrder->tenant_id)
                        ->where('store_id', $lockedOrder->store_id)
                        ->whereIn('product_id', $productIds)
                        ->orderBy('product_id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('product_id')
                    : collect();

                // 4. Validasi Item Retur & Disposisi
                if (empty($returnItemsWithDisposition)) {
                    throw ValidationException::withMessages([
                        'items' => 'Minimal satu item pesanan wajib disertakan dalam permohonan retur.',
                    ]);
                }

                $validatedReturnItems = [];
                $subtotalReturned = '0.00';

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

                    $alreadyReturnedQty = (int) OrderReturnItem::withoutGlobalScopes()
                        ->where('order_item_id', $orderItem->id)
                        ->sum('quantity_returned');

                    $remainingReturnable = (int) $orderItem->qty - $alreadyReturnedQty;
                    if ($qty > $remainingReturnable) {
                        throw new DomainException("Kuantitas retur ({$qty}) melebihi sisa yang dapat diretur ({$remainingReturnable}) untuk item '{$orderItem->product?->product_name}'.");
                    }

                    $product = $lockedProducts->get($orderItem->product_id);
                    if ($disposition->isRestock() && $product && $product->ingredients->isNotEmpty()) {
                        throw new DomainException("Larangan Restock: Produk F&B berbasis resep ('{$product->product_name}') tidak boleh direstock ke inventori layak jual.");
                    }

                    $linePrice = (string) $orderItem->order_price;
                    $lineSubtotal = bcmul($linePrice, (string) $qty, 2);
                    $subtotalReturned = bcadd($subtotalReturned, $lineSubtotal, 2);

                    $validatedReturnItems[] = [
                        'order_item' => $orderItem,
                        'product' => $product,
                        'quantity' => $qty,
                        'unit_price' => $linePrice,
                        'line_amount' => $lineSubtotal,
                        'disposition' => $disposition,
                        'disposition_reason' => $dispositionReason,
                    ];
                }

                // Kalkulasi pajak proporsional jika ada
                $taxReturned = '0.00';
                $orderTotalAmount = (string) ($lockedOrder->order_amount ?? $lockedOrder->total_amount ?? '0.00');
                $orderTaxAmount = (string) ($lockedOrder->tax_amount ?? '0.00');
                if (bccomp($orderTotalAmount, '0.00', 2) > 0 && bccomp($orderTaxAmount, '0.00', 2) > 0) {
                    $taxRatio = bcdiv($orderTaxAmount, $orderTotalAmount, 6);
                    $taxReturned = bcmul($subtotalReturned, $taxRatio, 2);
                }
                $totalRefundableAmount = bcadd($subtotalReturned, $taxReturned, 2);

                // 5. Buat Record OrderReturn
                $returnNumber = 'RET-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
                $cnNumber = 'SCN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $totalOrderQty = (int) $lockedOrder->orderItems()->sum('qty');
                $totalReturnedAll = (int) OrderReturnItem::whereIn('order_item_id', $lockedOrder->orderItems()->pluck('id'))->sum('quantity_returned')
                    + collect($validatedReturnItems)->sum('quantity');

                $returnType = $totalReturnedAll >= $totalOrderQty ? OrderReturn::TYPE_FULL : OrderReturn::TYPE_PARTIAL;

                $orderReturn = OrderReturn::forceCreate([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'store_id' => $lockedOrder->store_id,
                    'order_id' => $lockedOrder->id,
                    'customer_id' => $customerId,
                    'customer_invoice_id' => $customerInvoiceId,
                    'shift_id' => $resolvedShiftId,
                    'device_id' => $resolvedDeviceId,
                    'return_number' => $returnNumber,
                    'idempotency_key' => $idempotencyKey,
                    'status' => OrderReturn::STATUS_COMPLETED,
                    'return_type' => $returnType,
                    'subtotal_returned' => $subtotalReturned,
                    'discount_reversed' => 0.00,
                    'tax_returned' => $taxReturned,
                    'service_charge_returned' => 0.00,
                    'total_refundable_amount' => $totalRefundableAmount,
                    'reason' => $reason,
                    'requested_by_user_id' => $actorUserId,
                    'approved_by_user_id' => $approvedByUserId,
                    'completed_at' => now(),
                ]);

                // 6. Mutasi Stok dan Baris Return Items
                foreach ($validatedReturnItems as $vItem) {
                    $orderItem = $vItem['order_item'];
                    $product = $vItem['product'];
                    $qty = $vItem['quantity'];
                    $disposition = $vItem['disposition'];

                    $stockMovementId = null;

                    if ($disposition->isRestock() && $product && $product->ingredients->isEmpty()) {
                        $movement = StockMovement::firstOrCreate(
                            ['idempotency_key' => "return_restock:{$returnNumber}:{$orderItem->id}"],
                            [
                                'tenant_id' => $lockedOrder->tenant_id,
                                'store_id' => $lockedOrder->store_id,
                                'product_id' => $product->id,
                                'order_id' => $lockedOrder->id,
                                'order_item_id' => $orderItem->id,
                                'type' => StockMovementType::RestoreCompensation,
                                'quantity' => $qty,
                                'reason' => "Restock retur item pesanan #{$lockedOrder->order_code} (Retur #{$returnNumber})",
                            ]
                        );

                        $stockMovementId = $movement->id;

                        if ($movement->wasRecentlyCreated) {
                            if ($balancesAuthoritative) {
                                $balance = $lockedProductBalances->get($product->id);
                                if ($balance === null) {
                                    DB::table('product_stock_balances')->insertOrIgnore([
                                        'tenant_id' => $lockedOrder->tenant_id,
                                        'store_id' => $lockedOrder->store_id,
                                        'product_id' => $product->id,
                                        'quantity' => 0,
                                        'baseline_quantity' => 0,
                                        'baseline_movement_id' => 0,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }

                            $product->stock = (int) round((float) $product->stock + (float) $qty);
                            $product->save();
                        }
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

                // 7. Buat SalesCreditNote
                $creditNote = SalesCreditNote::forceCreate([
                    'tenant_id' => $lockedOrder->tenant_id,
                    'store_id' => $lockedOrder->store_id,
                    'credit_note_number' => $cnNumber,
                    'customer_id' => $customerId,
                    'order_return_id' => $orderReturn->id,
                    'origin_customer_invoice_id' => $customerInvoiceId,
                    'origin_order_id' => $lockedOrder->id,
                    'status' => CreditNoteStatusEnum::ISSUED,
                    'issue_date' => now()->toDateString(),
                    'reason_code' => 'RETURN',
                    'subtotal' => $subtotalReturned,
                    'tax_amount' => $taxReturned,
                    'total_amount' => $totalRefundableAmount,
                    'applied_amount' => '0.00',
                    'remaining_amount' => $totalRefundableAmount,
                    'notes' => $reason,
                    'idempotency_key' => "{$idempotencyKey}-cn",
                    'issued_by_user_id' => $approvedByUserId,
                    'issued_at' => now(),
                ]);

                // 8. Auto-apply ke Invoice jika diinstruksikan dan target invoice memiliki balance_due > 0
                $application = null;
                if ($autoApplyToInvoice && $lockedInvoice && bccomp((string) $lockedInvoice->balance_due, '0.00', 2) > 0) {
                    $applyAmount = bccomp((string) $creditNote->remaining_amount, (string) $lockedInvoice->balance_due, 2) >= 0
                        ? (string) $lockedInvoice->balance_due
                        : (string) $creditNote->remaining_amount;

                    if (bccomp($applyAmount, '0.00', 2) > 0) {
                        $application = $this->applyCreditNoteToInvoiceInternal(
                            $creditNote,
                            $lockedInvoice,
                            $applyAmount,
                            $actorUserId,
                            "{$idempotencyKey}-auto-apply"
                        );
                    }
                }

                $this->auditTrail->log(
                    tenantId: (int) $lockedOrder->tenant_id,
                    action: 'order.return_and_credit_note',
                    userId: $actorUserId,
                    storeId: (int) $lockedOrder->store_id,
                    subjectType: SalesCreditNote::class,
                    subjectId: (int) $creditNote->id,
                    description: "Retur #{$returnNumber} dan Nota Kredit #{$cnNumber} sebesar Rp ".number_format((float) $totalRefundableAmount, 2, ',', '.')." disetujui untuk pesanan #{$lockedOrder->order_code}.",
                    metadata: [
                        'order_id' => $lockedOrder->id,
                        'return_number' => $returnNumber,
                        'credit_note_number' => $cnNumber,
                        'total_amount' => $totalRefundableAmount,
                        'customer_invoice_id' => $customerInvoiceId,
                        'application_id' => $application?->id,
                    ]
                );

                return [
                    'return' => $orderReturn,
                    'credit_note' => $creditNote,
                    'application' => $application,
                ];
            }, attempts: 3);
        });
    }

    /**
     * Menerbitkan SalesCreditNote manual / mandiri (misal: koreksi harga atau kompensasi).
     */
    public function issueCreditNote(
        int $tenantId,
        int $storeId,
        int $customerId,
        string $amount,
        string $reasonCode,
        ?int $originOrderId = null,
        ?int $originInvoiceId = null,
        ?int $orderReturnId = null,
        ?int $actorUserId = null,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): SalesCreditNote {
        $tenantContext = app(TenantContext::class);
        $currentTenantId = (int) $tenantContext->requireTenantId();

        if ($tenantId !== $currentTenantId) {
            throw new DomainException('Akses lintas tenant ditolak.');
        }

        return $tenantContext->runAs($tenantId, $storeId, function () use (
            $tenantId,
            $storeId,
            $customerId,
            $amount,
            $reasonCode,
            $originOrderId,
            $originInvoiceId,
            $orderReturnId,
            $actorUserId,
            $notes,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $storeId,
                $customerId,
                $amount,
                $reasonCode,
                $originOrderId,
                $originInvoiceId,
                $orderReturnId,
                $actorUserId,
                $notes,
                $idempotencyKey
            ) {
                if ($idempotencyKey) {
                    $existing = SalesCreditNote::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $cleanAmount = bcadd((string) $amount, '0.00', 2);
                if (bccomp($cleanAmount, '0.00', 2) <= 0) {
                    throw new DomainException('Nominal nota kredit harus lebih dari nol.');
                }

                $cnNumber = 'SCN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                return SalesCreditNote::forceCreate([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'credit_note_number' => $cnNumber,
                    'customer_id' => $customerId,
                    'order_return_id' => $orderReturnId,
                    'origin_customer_invoice_id' => $originInvoiceId,
                    'origin_order_id' => $originOrderId,
                    'status' => CreditNoteStatusEnum::ISSUED,
                    'issue_date' => now()->toDateString(),
                    'reason_code' => $reasonCode,
                    'subtotal' => $cleanAmount,
                    'tax_amount' => '0.00',
                    'total_amount' => $cleanAmount,
                    'applied_amount' => '0.00',
                    'remaining_amount' => $cleanAmount,
                    'notes' => $notes,
                    'idempotency_key' => $idempotencyKey,
                    'issued_by_user_id' => $actorUserId,
                    'issued_at' => now(),
                ]);
            });
        });
    }

    /**
     * Menerapkan SalesCreditNote ke Faktur Piutang (CustomerInvoice).
     */
    public function applyCreditNoteToInvoice(
        SalesCreditNote $creditNote,
        CustomerInvoice $invoice,
        string $amount,
        int $actorUserId,
        ?string $idempotencyKey = null
    ): SalesCreditNoteApplication {
        $tenantContext = app(TenantContext::class);
        $tenantId = (int) $tenantContext->requireTenantId();

        if ((int) $creditNote->tenant_id !== $tenantId || (int) $invoice->tenant_id !== $tenantId) {
            throw new DomainException('Akses lintas tenant ditolak.');
        }

        if ((int) $creditNote->customer_id !== (int) $invoice->customer_id) {
            throw new DomainException('Nota kredit dan faktur harus milik pelanggan yang sama.');
        }

        return $tenantContext->runAs($creditNote->tenant_id, $creditNote->store_id, function () use (
            $creditNote,
            $invoice,
            $amount,
            $actorUserId,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $creditNote,
                $invoice,
                $amount,
                $actorUserId,
                $idempotencyKey
            ) {
                return $this->applyCreditNoteToInvoiceInternal(
                    $creditNote,
                    $invoice,
                    $amount,
                    $actorUserId,
                    $idempotencyKey
                );
            }, attempts: 3);
        });
    }

    /**
     * Helper internal aplikasi Credit Note dengan penguncian deterministik A0/A8.
     */
    protected function applyCreditNoteToInvoiceInternal(
        SalesCreditNote $creditNote,
        CustomerInvoice $invoice,
        string $amount,
        int $actorUserId,
        ?string $idempotencyKey = null
    ): SalesCreditNoteApplication {
        $amount = bcadd($amount, '0.00', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new DomainException('Nominal aplikasi nota kredit harus lebih dari nol.');
        }

        $idempotencyKey = $idempotencyKey ?: 'cn_app:'.Str::uuid();

        // 1. Cek idempotensi
        $existing = SalesCreditNoteApplication::withoutGlobalScopes()
            ->where('tenant_id', $creditNote->tenant_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Lock kanonikal A0/A8: Customer Credit Account -> CustomerInvoice -> SalesCreditNote
        $creditAccount = CustomerCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $creditNote->tenant_id)
            ->where('customer_id', $creditNote->customer_id)
            ->lockForUpdate()
            ->first();

        $lockedInvoice = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('id', $invoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $lockedCreditNote = SalesCreditNote::withoutGlobalScopes()
            ->where('tenant_id', $creditNote->tenant_id)
            ->where('id', $creditNote->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($lockedInvoice->status, [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID], true)) {
            throw new DomainException("Faktur {$lockedInvoice->invoice_number} tidak dapat menerima alokasi nota kredit. Status saat ini: {$lockedInvoice->status->value}");
        }

        if (in_array($lockedCreditNote->status, [CreditNoteStatusEnum::VOIDED, CreditNoteStatusEnum::APPLIED, CreditNoteStatusEnum::CONVERTED_TO_STORE_CREDIT], true)) {
            throw new DomainException("Nota kredit {$lockedCreditNote->credit_note_number} tidak aktif atau sudah diterapkan penuh.");
        }

        if (bccomp($amount, (string) $lockedCreditNote->remaining_amount, 2) > 0) {
            throw new DomainException('Nominal aplikasi (Rp '.number_format((float) $amount, 2, ',', '.').') melebihi sisa nota kredit (Rp '.number_format((float) $lockedCreditNote->remaining_amount, 2, ',', '.').').');
        }

        if (bccomp($amount, (string) $lockedInvoice->balance_due, 2) > 0) {
            throw new DomainException('Nominal aplikasi (Rp '.number_format((float) $amount, 2, ',', '.').') melebihi sisa tagihan faktur (Rp '.number_format((float) $lockedInvoice->balance_due, 2, ',', '.').').');
        }

        // 3. Mutasi Invoice
        $newCreditApplied = bcadd((string) $lockedInvoice->credit_applied_amount, $amount, 2);
        $newBalanceDue = bcsub((string) $lockedInvoice->balance_due, $amount, 2);

        $lockedInvoice->credit_applied_amount = $newCreditApplied;
        $lockedInvoice->balance_due = $newBalanceDue;
        $lockedInvoice->status = bccomp($newBalanceDue, '0.00', 2) === 0
            ? CustomerInvoiceStatusEnum::PAID
            : CustomerInvoiceStatusEnum::PARTIALLY_PAID;
        $lockedInvoice->save();

        // 4. Mutasi Credit Note
        $newAppliedCn = bcadd((string) $lockedCreditNote->applied_amount, $amount, 2);
        $newRemainingCn = bcsub((string) $lockedCreditNote->remaining_amount, $amount, 2);

        $lockedCreditNote->applied_amount = $newAppliedCn;
        $lockedCreditNote->remaining_amount = $newRemainingCn;
        $lockedCreditNote->status = bccomp($newRemainingCn, '0.00', 2) === 0
            ? CreditNoteStatusEnum::APPLIED
            : CreditNoteStatusEnum::PARTIALLY_APPLIED;
        $lockedCreditNote->save();

        // 5. Kurangi Invoiced Exposure pada CustomerCreditAccount
        if ($creditAccount) {
            $curInvoiced = (string) $creditAccount->invoiced_exposure;
            $creditAccount->invoiced_exposure = bccomp($curInvoiced, $amount, 2) >= 0
                ? bcsub($curInvoiced, $amount, 2)
                : '0.00';
            $creditAccount->save();
        }

        // 6. Buat Record SalesCreditNoteApplication
        $application = SalesCreditNoteApplication::create([
            'tenant_id' => $lockedCreditNote->tenant_id,
            'sales_credit_note_id' => $lockedCreditNote->id,
            'customer_invoice_id' => $lockedInvoice->id,
            'applied_amount' => $amount,
            'applied_by_user_id' => $actorUserId,
            'applied_at' => Carbon::now(),
            'idempotency_key' => $idempotencyKey,
        ]);

        // 7. Tulis ArLedgerEntry (CREDIT_NOTE)
        $runningBalance = $creditAccount ? $creditAccount->getTotalExposure() : '0.00';
        ArLedgerEntry::create([
            'tenant_id' => $lockedCreditNote->tenant_id,
            'store_id' => $lockedInvoice->store_id,
            'customer_id' => $lockedCreditNote->customer_id,
            'customer_invoice_id' => $lockedInvoice->id,
            'entry_date' => Carbon::now(),
            'entry_type' => ArLedgerEntryTypeEnum::CREDIT_NOTE,
            'reference_type' => SalesCreditNote::class,
            'reference_id' => $lockedCreditNote->id,
            'reference_number' => $lockedCreditNote->credit_note_number,
            'debit_amount' => '0.00',
            'credit_amount' => $amount,
            'running_balance_snapshot' => $runningBalance,
            'idempotency_key' => $idempotencyKey.':ar_ledger',
            'actor_user_id' => $actorUserId,
            'notes' => "Aplikasi Nota Kredit #{$lockedCreditNote->credit_note_number} ke Faktur #{$lockedInvoice->invoice_number}",
        ]);

        // 8. Tulis AccountingReceivableOutbox
        AccountingReceivableOutbox::create([
            'event_id' => (string) Str::uuid(),
            'tenant_id' => $lockedCreditNote->tenant_id,
            'store_id' => $lockedInvoice->store_id,
            'event_type' => 'CREDIT_NOTE_APPLIED',
            'idempotency_key' => $idempotencyKey.':outbox',
            'source_document_type' => 'CUSTOMER_INVOICE',
            'source_document_id' => $lockedInvoice->id,
            'source_document_number' => $lockedInvoice->invoice_number,
            'status' => 'pending',
            'payload' => [
                'credit_note_id' => $lockedCreditNote->id,
                'credit_note_number' => $lockedCreditNote->credit_note_number,
                'customer_invoice_id' => $lockedInvoice->id,
                'invoice_number' => $lockedInvoice->invoice_number,
                'customer_id' => $lockedCreditNote->customer_id,
                'applied_amount' => $amount,
                'invoice_balance_due_remaining' => $newBalanceDue,
                'credit_note_remaining' => $newRemainingCn,
                'applied_by_user_id' => $actorUserId,
                'timestamp' => Carbon::now()->toIso8601String(),
            ],
        ]);

        $this->auditTrail->log(
            tenantId: (int) $lockedCreditNote->tenant_id,
            action: 'credit_note.applied',
            userId: $actorUserId,
            storeId: (int) $lockedInvoice->store_id,
            subjectType: SalesCreditNote::class,
            subjectId: (int) $lockedCreditNote->id,
            description: "Nota Kredit #{$lockedCreditNote->credit_note_number} diaplikasikan sebesar Rp ".number_format((float) $amount, 2, ',', '.')." ke Faktur #{$lockedInvoice->invoice_number}.",
            metadata: [
                'invoice_id' => $lockedInvoice->id,
                'applied_amount' => $amount,
                'application_id' => $application->id,
            ]
        );

        return $application;
    }

    /**
     * Mengkonversi sisa Nota Kredit menjadi saldo Store Credit pelanggan.
     */
    public function convertCreditNoteToStoreCredit(
        SalesCreditNote $creditNote,
        int $actorUserId,
        ?string $idempotencyKey = null
    ): SalesCreditNote {
        $tenantContext = app(TenantContext::class);
        $tenantId = (int) $tenantContext->requireTenantId();

        if ((int) $creditNote->tenant_id !== $tenantId) {
            throw new DomainException('Akses lintas tenant ditolak.');
        }

        return $tenantContext->runAs($creditNote->tenant_id, $creditNote->store_id, function () use (
            $creditNote,
            $actorUserId

        ) {
            return DB::transaction(function () use (
                $creditNote,
                $actorUserId

            ) {
                $locked = SalesCreditNote::withoutGlobalScopes()
                    ->where('id', $creditNote->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (bccomp((string) $locked->remaining_amount, '0.00', 2) <= 0) {
                    throw new DomainException('Nota kredit tidak memiliki sisa nominal untuk dikonversi.');
                }

                if ($locked->status === CreditNoteStatusEnum::VOIDED) {
                    throw new DomainException('Nota kredit yang dibatalkan tidak dapat dikonversi.');
                }

                $locked->status = CreditNoteStatusEnum::CONVERTED_TO_STORE_CREDIT;
                $locked->remaining_amount = '0.00';
                $locked->save();

                $this->auditTrail->log(
                    tenantId: (int) $locked->tenant_id,
                    action: 'credit_note.converted_to_store_credit',
                    userId: $actorUserId,
                    storeId: (int) $locked->store_id,
                    subjectType: SalesCreditNote::class,
                    subjectId: (int) $locked->id,
                    description: "Nota Kredit #{$locked->credit_note_number} dikonversi ke Store Credit pelanggan.",
                    metadata: [
                        'credit_note_id' => $locked->id,
                        'customer_id' => $locked->customer_id,
                    ]
                );

                return $locked;
            });
        });
    }
}
