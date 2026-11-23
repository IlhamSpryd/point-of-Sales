<?php

declare(strict_types=1);

namespace App\Livewire\Kasir\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Modules\Organization\Services\CashierPinService;
use App\Modules\Sales\Models\PaymentAttempt;
use App\Modules\Sales\Services\Printing\ReceiptPrinterService;
use App\Modules\Sales\Services\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

trait ManagesOrderVoidAndReprint
{
    public string $voidReason = '';

    public ?int $voidingOrderId = null;

    public string $supervisorPin = '';

    // === REPRINT MODAL STATE ===
    public bool $showReprintModal = false;

    public ?int $reprintingOrderId = null;

    public string $reprintReason = '';

    public string $reprintSupervisorPin = '';

    public int $reprintOrderPrintCount = 0;

    public function voidOrder(?int $orderId = null, ?string $voidReason = null, ?string $pin = null): void
    {
        $targetOrderId = $orderId ?? $this->voidingOrderId;
        if (! $targetOrderId) {
            return;
        }

        $reason = trim((string) ($voidReason ?? $this->voidReason));

        if (strlen($reason) < 5) {
            $this->addError('voidReason', 'Alasan void harus minimal 5 karakter.');

            return;
        }

        $order = $this->getOrderQuery()->findOrFail($targetOrderId);
        // [D2-BE-004] Otorisasi policy void: tenant/store match + peran berwenang.
        Gate::authorize('void', $order);

        if (! $order || $order->order_status !== OrderStatus::Paid) {
            session()->flash('error', 'Order not found or not in paid status.');
            $this->dispatch('toast', message: 'Order not found or not in paid status.', type: 'error');

            return;
        }

        // Safeguard: Periksa apakah ada PaymentAttempt yang berstatus pending/timeout (AC-2.4)
        $hasUnresolvedAttempts = PaymentAttempt::where('order_id', $order->id)
            ->whereIn('status', [
                PaymentAttemptStatus::PendingProvider,
                PaymentAttemptStatus::UnknownTimeout,
            ])->exists();

        if ($hasUnresolvedAttempts) {
            $this->addError('voidReason', 'Order has pending electronic payments. Please reconcile payment before voiding.');
            $this->dispatch('toast', message: 'Order has pending electronic payments. Please reconcile payment before voiding.', type: 'error');

            return;
        }

        $inputPin = $pin ?? $this->supervisorPin;
        $user = Auth::user();

        if ($user && ! empty($inputPin)) {
            $pinValid = false;
            try {
                $pinValid = app(CashierPinService::class)->verifySupervisorPin((int) $user->id, (string) $inputPin, (int) $order->tenant_id);
            } catch (\Throwable $e) {
                $pinValid = app(CashierPinService::class)->verifyPin($user, (string) $inputPin);
            }

            if (! $pinValid) {
                $this->addError('supervisorPin', 'Invalid supervisor PIN.');
                $this->dispatch('toast', message: 'Invalid supervisor PIN.', type: 'error');

                return;
            }
        }

        try {
            app(TransactionService::class)->voidOrder($order, $reason, Auth::id());
            session()->flash('success', 'Order successfully voided and inventory restored.');
            $this->dispatch('toast', message: 'Order successfully voided and inventory restored.', type: 'success');
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            Log::error('Void order failed: '.$e->getMessage(), [
                'exception' => $e,
                'order_id' => $targetOrderId,
                'user_id' => Auth::id(),
            ]);
            session()->flash('error', 'A system error occurred while processing order void. Please try again.');
            $this->dispatch('toast', message: 'A system error occurred while processing order void. Please try again.', type: 'error');
        }

        $this->voidingOrderId = null;
        $this->voidReason = '';
        $this->supervisorPin = '';
        if ($this->selectedOrderId === $order->id) {
            $this->closeDetail();
        }
    }

    // === REPRINT ORCHESTRATION ===

    public function requestReprint(int $orderId): void
    {
        $order = $this->getOrderQuery()->findOrFail($orderId);
        $printCount = (int) $order->print_count;

        if ($printCount === 0) {
            try {
                app(ReceiptPrinterService::class)->generatePrintPayload(
                    $order,
                    (int) Auth::id(),
                    $order->created_by_device_id,
                    $order->shift_id
                );
            } catch (\Throwable $e) {
                // Ignore fallback to normal receipt view
            }
            $this->redirect(route('transaction.receipt', ['orderCode' => $order->order_code]), navigate: false);

            return;
        }

        $this->reprintingOrderId = $orderId;
        $this->reprintOrderPrintCount = $printCount;
        $this->reprintReason = '';
        $this->reprintSupervisorPin = '';
        $this->showReprintModal = true;
    }

    public function closeReprintModal(): void
    {
        $this->showReprintModal = false;
        $this->reprintingOrderId = null;
        $this->reprintReason = '';
        $this->reprintSupervisorPin = '';
    }

    public function confirmReprint(ReceiptPrinterService $printerService): void
    {
        if (! $this->reprintingOrderId) {
            return;
        }

        if (strlen(trim($this->reprintReason)) < 5) {
            $this->addError('reprintReason', 'Reprint reason must be at least 5 characters.');

            return;
        }

        $order = $this->getOrderQuery()->findOrFail($this->reprintingOrderId);
        $newSequence = (int) $order->print_count + 1;

        if ($newSequence > 2 && empty($this->reprintSupervisorPin)) {
            $this->addError('reprintSupervisorPin', 'Supervisor PIN is required for reprint 3 or greater.');

            return;
        }

        try {
            $printerService->generatePrintPayload(
                $order,
                (int) Auth::id(),
                $order->created_by_device_id,
                $order->shift_id,
                $this->reprintReason,
                $this->reprintSupervisorPin ?: null
            );

            $this->showReprintModal = false;
            $this->reprintingOrderId = null;
            $this->redirect(route('transaction.receipt', ['orderCode' => $order->order_code, 'is_reprint' => 1]), navigate: false);
        } catch (ValidationException $e) {
            $this->addError('reprintSupervisorPin', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addError('reprintReason', $e->getMessage());
        }
    }
}
