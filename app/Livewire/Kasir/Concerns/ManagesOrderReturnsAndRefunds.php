<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Modules\Sales\Models\OrderReturnItem;
use App\Modules\Sales\Models\PaymentRefund;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\ReturnRefundService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait ManagesOrderReturnsAndRefunds
{
    // === RETURN & REFUND MODAL STATE ===
    public bool $showReturnModal = false;

    public ?int $returningOrderId = null;

    public array $returnItems = [];

    public array $refundAllocations = [];

    public string $returnReason = '';

    public string $returnSupervisorPin = '';

    public ?string $returnErrorMessage = null;

    public function openReturnModal(int $orderId): void
    {
        $this->returningOrderId = $orderId;
        $order = $this->getOrderQuery()
            ->with(['orderItems.product.ingredients', 'payments'])
            ->findOrFail($orderId);

        $this->returnItems = [];
        foreach ($order->orderItems as $item) {
            $alreadyReturned = (int) OrderReturnItem::withoutGlobalScopes()
                ->where('order_item_id', $item->id)
                ->sum('quantity_returned');
            $remaining = max(0, (int) $item->qty - $alreadyReturned);
            $isRecipe = $item->product && $item->product->ingredients && $item->product->ingredients->isNotEmpty();

            $this->returnItems[$item->id] = [
                'order_item_id' => $item->id,
                'product_name' => $item->product->product_name ?? 'Produk',
                'unit_price' => (float) $item->order_price,
                'original_qty' => (int) $item->qty,
                'already_returned_qty' => $alreadyReturned,
                'remaining_qty' => $remaining,
                'selected' => $remaining > 0,
                'qty' => $remaining,
                'disposition' => $isRecipe ? 'write_off_waste' : 'restock',
                'disposition_reason' => '',
                'is_recipe_product' => (bool) $isRecipe,
            ];
        }

        $this->refundAllocations = [];
        foreach ($order->payments as $payment) {
            $alreadyRefunded = (float) PaymentRefund::withoutGlobalScopes()
                ->where('payment_id', $payment->id)
                ->whereNotIn('request_status', [PaymentRefund::REQUEST_FAILED, PaymentRefund::REQUEST_CANCELLED])
                ->sum('amount');
            $remainingRefundable = max(0.00, (float) $payment->amount - $alreadyRefunded);
            $origMethod = is_string($payment->payment_method) ? $payment->payment_method : $payment->payment_method->value;

            $this->refundAllocations[$payment->id] = [
                'payment_id' => $payment->id,
                'orig_method' => $origMethod,
                'refund_method' => $origMethod,
                'amount' => (int) $remainingRefundable,
                'max_amount' => (int) $remainingRefundable,
                'is_method_override' => false,
                'method_override_reason' => '',
            ];
        }

        $this->returnReason = '';
        $this->returnSupervisorPin = '';
        $this->returnErrorMessage = null;
        $this->showReturnModal = true;
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->returningOrderId = null;
        $this->returnItems = [];
        $this->refundAllocations = [];
        $this->returnReason = '';
        $this->returnSupervisorPin = '';
        $this->returnErrorMessage = null;
    }

    public function processReturn(ReturnRefundService $returnRefundService): void
    {
        if (! $this->returningOrderId) {
            return;
        }

        $this->returnErrorMessage = null;
        $order = $this->getOrderQuery()->findOrFail($this->returningOrderId);

        $selectedItems = collect($this->returnItems)->filter(fn ($item) => ! empty($item['selected']) && (int) $item['qty'] > 0)->values()->all();

        if (empty($selectedItems)) {
            $this->returnErrorMessage = 'Please select at least one item to return.';

            return;
        }

        foreach ($selectedItems as $item) {
            if (! empty($item['is_recipe_product']) && $item['disposition'] === 'restock') {
                $this->returnErrorMessage = "Restock Prohibited: Recipe-based F&B product ('{$item['product_name']}') cannot be restocked.";

                return;
            }
        }

        if (strlen(trim($this->returnReason)) < 5) {
            $this->returnErrorMessage = 'Return/refund reason must be at least 5 characters.';

            return;
        }

        if (empty($this->returnSupervisorPin)) {
            $this->returnErrorMessage = 'Supervisor PIN is required to authorize return/refund.';

            return;
        }

        $activeShift = Shift::forBranch((int) $order->store_id)
            ->where('status', 'open')
            ->first();

        $shiftId = $activeShift ? $activeShift->id : (int) $order->shift_id;

        $returnItemsInput = array_map(function ($item) {
            return [
                'order_item_id' => (int) $item['order_item_id'],
                'quantity' => (int) $item['qty'],
                'disposition' => $item['disposition'],
                'disposition_reason' => $item['disposition_reason'] ?? '',
            ];
        }, $selectedItems);

        $allocationsInput = [];
        foreach ($this->refundAllocations as $alloc) {
            if ((float) $alloc['amount'] > 0) {
                $isOverride = ($alloc['orig_method'] !== 'cash' && $alloc['refund_method'] === 'cash');
                $allocationsInput[] = [
                    'payment_id' => (int) $alloc['payment_id'],
                    'amount' => (float) $alloc['amount'],
                    'refund_method' => $alloc['refund_method'],
                    'is_method_override' => $isOverride,
                    'method_override_reason' => $alloc['method_override_reason'] ?? ($isOverride ? 'Cash Refund Exception' : null),
                ];
            }
        }

        if (empty($allocationsInput)) {
            $this->returnErrorMessage = 'Refund amount must be greater than 0.';

            return;
        }

        try {
            $deviceId = $this->resolveActiveDeviceId(
                null,
                $order->created_by_device_id ? (int) $order->created_by_device_id : ($activeShift?->device_id ? (int) $activeShift->device_id : null),
                (int) $order->store_id,
                (int) $order->tenant_id
            );
            $idempotencyKey = 'ret-'.Str::uuid()->toString();
            $orderReturn = $returnRefundService->createReturnAndRefund(
                $order,
                $returnItemsInput,
                $allocationsInput,
                $shiftId,
                $deviceId,
                (int) Auth::id(),
                $this->returnSupervisorPin,
                $this->returnReason,
                $idempotencyKey
            );

            $this->showReturnModal = false;
            $this->returningOrderId = null;
            session()->flash('success', "Return #{$orderReturn->return_number} and refund successfully processed.");
            $this->dispatch('toast', message: "Return #{$orderReturn->return_number} and refund successfully processed.", type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $this->returnErrorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            Log::error('Process return failed: '.$e->getMessage(), ['exception' => $e]);
            $this->returnErrorMessage = __('common.alerts.operation_failed');
        }
    }
}
