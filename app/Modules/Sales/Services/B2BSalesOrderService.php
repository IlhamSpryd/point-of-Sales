<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Order;
use App\Models\User;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Models\CustomerInvoice;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class B2BSalesOrderService
{
    public function __construct(
        protected SalesOrderService $salesOrderService,
        protected CustomerCreditService $customerCreditService,
        protected InventoryLedgerService $inventoryLedgerService,
        protected AccountsReceivableService $accountsReceivableService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Membuat Sales Order B2B baru dengan validasi plafon kredit pelanggan (T-SLS-02).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException
     */
    public function createOrder(
        array $payload,
        User $actor,
        bool $allowOverride = false,
        ?string $overrideReason = null
    ): SalesOrder {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());
        $customerId = (int) ($payload['customer_id'] ?? 0);

        if ($customerId <= 0) {
            throw new DomainException('Customer is required for B2B Sales Orders.');
        }

        // Hitung estimasi total pesanan
        $rawTotal = $payload['total_amount'] ?? '0.00';
        $estimatedTotal = is_numeric($rawTotal) ? (string) $rawTotal : '0.00';
        if (bccomp($estimatedTotal, '0.00', 2) <= 0 && isset($payload['items']) && is_array($payload['items'])) {
            $calc = '0.00';
            foreach ($payload['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rawQty = $item['quantity'] ?? '1';
                $rawPrice = $item['unit_price'] ?? '0';
                $qty = is_numeric($rawQty) ? (string) $rawQty : '1';
                $price = is_numeric($rawPrice) ? (string) $rawPrice : '0';
                $calc = bcadd($calc, bcmul($qty, $price, 2), 2);
            }
            $estimatedTotal = $calc;
        }

        // Validasi Plafon Kredit Pelanggan (T-SLS-02)
        $creditEval = $this->customerCreditService->evaluateCreditExposure($tenantId, $customerId, $estimatedTotal);
        if (! $creditEval->allowed && ! $allowOverride) {
            throw new DomainException(
                'Customer has exceeded credit limit (Limit: '.number_format((float) $creditEval->creditLimit, 2).
                ', Projected: '.number_format((float) $creditEval->projectedTotalExposure, 2).
                '). Creation of new Sales Order rejected unless overridden.'
            );
        }

        $salesOrder = $this->salesOrderService->createOrder($payload, $actor);

        if ($allowOverride && ! $creditEval->allowed) {
            $salesOrder->update([
                'credit_override_approved_by' => $actor->id,
                'credit_override_reason' => $overrideReason ?: 'B2B credit limit override authorization',
            ]);
        }

        return $salesOrder;
    }

    /**
     * Membuat Sales Order turunan dari Quotation B2B yang disetujui.
     */
    public function createOrderFromQuotation(
        Quotation $quotation,
        User $actor,
        bool $allowOverride = false,
        ?string $overrideReason = null
    ): SalesOrder {
        $tenantId = (int) $quotation->tenant_id;
        $customerId = (int) $quotation->customer_id;
        $totalAmount = (string) $quotation->total_amount;

        $creditEval = $this->customerCreditService->evaluateCreditExposure($tenantId, $customerId, $totalAmount);
        if (! $creditEval->allowed && ! $allowOverride) {
            throw new DomainException(
                'Customer has exceeded credit limit (Limit: '.number_format((float) $creditEval->creditLimit, 2).
                ', Projected: '.number_format((float) $creditEval->projectedTotalExposure, 2).
                '). Creation of Sales Order from quotation rejected unless overridden.'
            );
        }

        $salesOrder = $this->salesOrderService->createOrderFromQuotation($quotation, $actor);

        if ($allowOverride && ! $creditEval->allowed) {
            $salesOrder->update([
                'credit_override_approved_by' => $actor->id,
                'credit_override_reason' => $overrideReason ?: 'Otorisasi override plafon kredit Quotation B2B',
            ]);
        }

        return $salesOrder;
    }

    /**
     * Konfirmasi Sales Order (CONFIRMED) dengan reservasi stok gudang:
     * - Menambah reserved_quantity pada product_stock_balances tanpa mengurangi stok fisik (T-SLS-01).
     * - Memvalidasi plafon kredit jika pesanan memiliki terms tempo kredit (T-SLS-02).
     *
     * @throws DomainException
     */
    public function confirmOrderWithReservation(
        SalesOrder $salesOrder,
        User $actor,
        bool $allowOverride = false,
        ?string $overrideReason = null
    ): SalesOrder {
        return DB::transaction(function () use ($salesOrder, $actor, $allowOverride, $overrideReason) {
            $lockedSO = SalesOrder::withoutGlobalScopes()
                ->where('id', $salesOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            $tenantId = (int) $lockedSO->tenant_id;
            $storeId = (int) $lockedSO->store_id;

            // Validasi Plafon Kredit (T-SLS-02)
            $creditAccount = $this->customerCreditService->getOrCreateCreditAccount((int) $lockedSO->customer_id, $tenantId);
            $creditEval = $this->customerCreditService->evaluateCreditExposure($tenantId, (int) $lockedSO->customer_id, (string) $lockedSO->total_amount);

            if (! $creditEval->allowed && ! $allowOverride) {
                throw new DomainException(
                    'Customer has exceeded credit limit (Limit: '.number_format((float) $creditEval->creditLimit, 2).
                    ', Projected: '.number_format((float) $creditEval->projectedTotalExposure, 2).
                    '). Sales Order confirmation rejected unless overridden.'
                );
            }

            // Delegasikan konfirmasi status SO ke SalesOrderService
            $confirmedSO = $this->salesOrderService->confirmOrder($lockedSO, $actor, $allowOverride, $overrideReason);

            // Reservasi Stok Gudang (T-SLS-01): Tambah reserved_quantity tanpa mengurangi stok fisik (quantity)
            $confirmedSO->loadMissing('lines');
            /** @var Collection<int, SalesOrderLine> $soLines */
            $soLines = $confirmedSO->lines;
            foreach ($soLines as $line) {
                $prodId = (int) $line->product_id;
                $reservedDelta = (float) $line->ordered_qty;

                $balance = DB::table('product_stock_balances')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('product_id', $prodId)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    DB::table('product_stock_balances')
                        ->where('tenant_id', $tenantId)
                        ->where('store_id', $storeId)
                        ->where('product_id', $prodId)
                        ->increment('reserved_quantity', $reservedDelta);
                } else {
                    DB::table('product_stock_balances')->insert([
                        'tenant_id' => $tenantId,
                        'store_id' => $storeId,
                        'product_id' => $prodId,
                        'quantity' => 0.000000,
                        'reserved_quantity' => $reservedDelta,
                        'baseline_quantity' => 0.000000,
                        'baseline_movement_id' => 0,
                        'min_stock_level' => 0.0000,
                        'reorder_point' => 0.0000,
                        'average_unit_cost' => 0.0000,
                        'last_unit_cost' => 0.0000,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'b2b.sales_order_stock_reserved',
                userId: $actor->id,
                storeId: $storeId,
                subjectType: SalesOrder::class,
                subjectId: $confirmedSO->id,
                description: "Sales Order {$confirmedSO->so_number} confirmed and reserved product stock in product_stock_balances.",
                metadata: [
                    'sales_order_id' => $confirmedSO->id,
                    'lines_count' => $confirmedSO->lines->count(),
                ]
            );

            return $confirmedSO;
        });
    }

    /**
     * Memenuhi (Fulfill) Sales Order:
     * - Mengurangi reserved_quantity pada product_stock_balances.
     * - Memotong stok aktual secara riil melalui InventoryLedgerService.
     *
     * @param  array<int, array{line_id: int, quantity: float|string}>  $fulfillmentLines
     */
    public function fulfillOrder(
        SalesOrder $salesOrder,
        array $fulfillmentLines,
        User $actor,
        ?string $idempotencyKey = null
    ): Order {
        return DB::transaction(function () use ($salesOrder, $fulfillmentLines, $actor, $idempotencyKey) {
            $tenantId = (int) $salesOrder->tenant_id;
            $storeId = (int) $salesOrder->store_id;

            // Eksekusi fulfillment aktual via SalesOrderService (memotong stok fisik via InventoryLedgerService)
            $order = $this->salesOrderService->fulfillOrder($salesOrder, $fulfillmentLines, $actor, $idempotencyKey);

            // Lepaskan reservasi stok proporsional pada product_stock_balances
            foreach ($fulfillmentLines as $fLine) {
                $lineId = (int) $fLine['line_id'];
                $qty = (float) $fLine['quantity'];

                if ($qty <= 0) {
                    continue;
                }

                $line = SalesOrderLine::withoutGlobalScopes()->find($lineId);
                if ($line) {
                    $prodId = (int) $line->product_id;
                    $balance = DB::table('product_stock_balances')
                        ->where('tenant_id', $tenantId)
                        ->where('store_id', $storeId)
                        ->where('product_id', $prodId)
                        ->lockForUpdate()
                        ->first();

                    if ($balance) {
                        $currentReserved = (float) $balance->reserved_quantity;
                        $newReserved = max(0.0, $currentReserved - $qty);
                        DB::table('product_stock_balances')
                            ->where('tenant_id', $tenantId)
                            ->where('store_id', $storeId)
                            ->where('product_id', $prodId)
                            ->update([
                                'reserved_quantity' => $newReserved,
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            return $order;
        });
    }

    /**
     * Menerbitkan Faktur Piutang B2B Tempo AR Net 30 untuk Sales Order.
     */
    public function issueInvoiceForOrder(
        SalesOrder $salesOrder,
        User $actor,
        int $paymentTermsDays = 30
    ): CustomerInvoice {
        return DB::transaction(function () use ($salesOrder, $actor, $paymentTermsDays) {
            $options = [
                'payment_terms_days' => $paymentTermsDays,
                'due_date' => now()->addDays($paymentTermsDays)->toDateString(),
            ];

            // Buat invoice dari SalesOrder
            $invoice = $this->accountsReceivableService->createInvoice(
                source: $salesOrder,
                options: $options,
                actor: $actor
            );

            // Posting faktur piutang agar terbentuk piutang usaha di buku besar
            return $this->accountsReceivableService->postInvoice($invoice, $actor);
        });
    }
}
