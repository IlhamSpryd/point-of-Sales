<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Core\Context\TenantContext;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\SplitBillService;
use Livewire\Attributes\Computed;

/**
 * Manages POS cashier split bill modals and execution (equal, custom, itemized).
 */
trait ManagesPosSplitBill
{
    public bool $showSplitBillModal = false;

    public string $splitBillMode = 'equal';

    public int $splitBillParts = 2;

    /** @var array<int, int|float|string> */
    public array $splitBillAmounts = [];

    /** @var array<int, int> */
    public array $splitBillItemAssignments = [];

    public ?int $splitBillOrderId = null;

    public function openSplitBillModal(?int $orderId = null): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $targetOrderId = $orderId ?? $this->pendingOrderId;

        if (! $targetOrderId && $this->tableId) {
            $tableOrder = Order::where('tenant_id', $tenantId)
                ->where('table_id', $this->tableId)
                ->whereNotIn('order_status', ['paid', 'completed', 'void', 'cancelled'])
                ->latest()
                ->first();
            if ($tableOrder) {
                $targetOrderId = $tableOrder->id;
            }
        }

        if (! $targetOrderId) {
            $this->dispatch('toast', message: 'Pilih meja dengan pesanan aktif atau muat pesanan tertunda terlebih dahulu untuk Split Bill.', type: 'warning');

            return;
        }

        $order = Order::with('orderItems.product')->where('tenant_id', $tenantId)->find($targetOrderId);
        if (! $order) {
            $this->dispatch('toast', message: 'Pesanan tidak ditemukan.', type: 'warning');

            return;
        }

        $this->splitBillOrderId = $order->id;
        $this->splitBillMode = 'equal';
        $this->splitBillParts = 2;
        $totalAmt = (float) $order->order_amount;
        $half1 = (int) floor($totalAmt / 2);
        $half2 = (int) ($totalAmt - $half1);
        $this->splitBillAmounts = [$half1, $half2];

        $this->splitBillItemAssignments = [];
        foreach ($order->orderItems as $item) {
            $this->splitBillItemAssignments[$item->id] = 0;
        }

        $this->showSplitBillModal = true;
    }

    public function closeSplitBillModal(): void
    {
        $this->showSplitBillModal = false;
        $this->splitBillOrderId = null;
    }

    public function setSplitBillParts(int $parts): void
    {
        $parts = max(2, min(10, $parts));
        $this->splitBillParts = $parts;

        $splitOrder = $this->splitBillOrder();
        if ($splitOrder !== null) {
            $totalAmt = (string) $splitOrder->order_amount;
            $partsStr = (string) $parts;
            $perPart = bcdiv($totalAmt, $partsStr, 0);
            $totalDivided = bcmul($perPart, (string) ($parts - 1), 0);
            $remainder = bcsub($totalAmt, $totalDivided, 0);

            $amounts = [];
            for ($i = 0; $i < $parts; $i++) {
                $amounts[] = (int) (($i === 0) ? $remainder : $perPart);
            }
            $this->splitBillAmounts = $amounts;
        }
    }

    public function addSplitBillAmountPart(): void
    {
        $this->splitBillAmounts[] = 0;
    }

    public function removeSplitBillAmountPart(int $index): void
    {
        if (count($this->splitBillAmounts) > 2) {
            unset($this->splitBillAmounts[$index]);
            $this->splitBillAmounts = array_values($this->splitBillAmounts);
        }
    }

    #[Computed]
    public function splitBillOrder(): ?Order
    {
        if (! $this->splitBillOrderId) {
            return null;
        }

        return Order::with(['orderItems.product', 'table'])->where('tenant_id', (int) app(TenantContext::class)->requireTenantId())->find($this->splitBillOrderId);
    }

    public function executeSplitBill(SplitBillService $splitService): void
    {
        if (! $this->splitBillOrderId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $order = Order::with('orderItems')->where('tenant_id', $tenantId)->findOrFail($this->splitBillOrderId);
        $userId = (int) auth()->id();

        try {
            if ($this->splitBillMode === 'item') {
                $splits = [
                    ['sub_order_label' => 'Sub-Order 1', 'items' => []],
                    ['sub_order_label' => 'Sub-Order 2', 'items' => []],
                ];

                foreach ($order->orderItems as $item) {
                    $assignedPart = (int) ($this->splitBillItemAssignments[$item->id] ?? 0);
                    $targetPart = ($assignedPart === 1) ? 1 : 0;
                    $splits[$targetPart]['items'][] = ['order_item_id' => $item->id, 'quantity' => (int) $item->qty];
                }

                if (empty($splits[0]['items']) || empty($splits[1]['items'])) {
                    $this->dispatch('toast', message: 'Setiap sub-order harus memiliki minimal 1 item pesanan.', type: 'warning');

                    return;
                }

                $subOrders = $splitService->splitByItem($order, $splits, $userId);
            } else {
                $amounts = array_map(fn ($a) => (int) round((float) $a), $this->splitBillAmounts);
                $sum = array_sum($amounts);
                $totalOrder = (int) $order->order_amount;

                if ($sum !== $totalOrder) {
                    $this->dispatch('toast', message: 'Total nominal pembagian (Rp '.number_format($sum, 0, ',', '.').') harus sama dengan total pesanan (Rp '.number_format($totalOrder, 0, ',', '.').').', type: 'warning');

                    return;
                }

                $subOrders = $splitService->splitByAmount($order, $amounts, $userId);
            }

            $count = count($subOrders);
            $this->closeSplitBillModal();
            $this->clearCart();
            $this->pendingOrderId = null;
            $this->pendingOrderCode = null;

            $this->dispatch('toast', message: "Tagihan #{$order->order_code} berhasil dipecah menjadi {$count} sub-order!", type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Gagal split bill: '.$e->getMessage(), type: 'destructive');
        }
    }
}
