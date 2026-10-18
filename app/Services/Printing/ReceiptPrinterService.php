<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ReceiptPrintLog;
use App\Services\AuditTrailService;
use App\Services\CashierPinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer;

class ReceiptPrinterService
{
    /**
     * Menghasilkan payload cetak struk ESC/POS resmi dan mencatat audit print log.
     *
     * @return array{receipt_base64: string, drawer_hex: string, print_sequence: int, is_reprint: bool, receipt_text: string}
     */
    public function generatePrintPayload(
        Order $order,
        int $actorUserId,
        ?int $deviceId = null,
        ?int $shiftId = null,
        ?string $reprintReason = null,
        ?string $supervisorPin = null
    ): array {
        return DB::transaction(function () use ($order, $actorUserId, $deviceId, $shiftId, $reprintReason, $supervisorPin) {
            $lockedOrder = Order::withoutGlobalScopes()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $lockedOrder->order_status instanceof OrderStatus
                ? $lockedOrder->order_status
                : OrderStatus::tryFrom((string) $lockedOrder->order_status);

            if ($status !== OrderStatus::Paid) {
                throw ValidationException::withMessages([
                    'order' => 'Hanya pesanan yang sudah lunas (Paid) yang dapat dicetak struk resminya.',
                ]);
            }

            $currentPrintCount = (int) $lockedOrder->print_count;
            $newPrintSequence = $currentPrintCount + 1;
            $isReprint = $newPrintSequence > 1;

            // Jika cetak ulang lebih dari 2x, validasi PIN supervisor jika diberikan/dibutuhkan
            if ($isReprint && $newPrintSequence > 2 && $supervisorPin !== null) {
                app(CashierPinService::class)->verifySupervisorPin($actorUserId, $supervisorPin, $lockedOrder->tenant_id);
            }

            // Naikkan counter cetak via forceFill karena mass assignment dibatasi
            $lockedOrder->forceFill(['print_count' => $newPrintSequence])->save();

            // Catat log cetak struk
            ReceiptPrintLog::create([
                'tenant_id' => $lockedOrder->tenant_id,
                'store_id' => $lockedOrder->store_id,
                'order_id' => $lockedOrder->id,
                'device_id' => $deviceId ?? $lockedOrder->created_by_device_id,
                'shift_id' => $shiftId ?? $lockedOrder->shift_id,
                'printed_by_user_id' => $actorUserId,
                'print_sequence' => $newPrintSequence,
                'is_reprint' => $isReprint,
                'reprint_reason' => $isReprint ? ($reprintReason ?? 'Permintaan cetak ulang pelanggan / kasir') : null,
                'printed_at' => now(),
            ]);

            // Audit Trail
            app(AuditTrailService::class)->log(
                tenantId: $lockedOrder->tenant_id,
                action: $isReprint ? 'receipt.reprinted' : 'receipt.printed',
                userId: $actorUserId,
                storeId: $lockedOrder->store_id,
                subjectType: Order::class,
                subjectId: $lockedOrder->id,
                description: $isReprint
                    ? "Struk dicetak ulang ke-{$newPrintSequence} untuk pesanan {$lockedOrder->order_code}. Alasan: {$reprintReason}"
                    : "Struk dicetak pertama kali untuk pesanan {$lockedOrder->order_code}",
                metadata: [
                    'order_code' => $lockedOrder->order_code,
                    'print_sequence' => $newPrintSequence,
                    'is_reprint' => $isReprint,
                    'reprint_reason' => $reprintReason,
                    'device_id' => $deviceId,
                    'shift_id' => $shiftId,
                ]
            );

            // Load data untuk rendering struk
            $lockedOrder->loadMissing(['orderItems.product', 'user']);

            $connector = new DummyPrintConnector;
            $printer = new Printer($connector);

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text("Apeiron System\n");
            $printer->setEmphasis(false);
            $printer->text("Jl. Mawar No. 123, Jakarta\n");
            $printer->text(str_repeat('-', 32)."\n");

            // Indikator cetak ulang jika bukan cetakan pertama (AC-5.1)
            if ($isReprint) {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->setEmphasis(true);
                $printer->text("*** COPY / CETAK ULANG #{$newPrintSequence} ***\n");
                $printer->setEmphasis(false);
                $printer->text(str_repeat('-', 32)."\n");
            }

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text('Order : '.$lockedOrder->order_code."\n");
            $printer->text('Kasir : '.($lockedOrder->user->name ?? 'Kasir')."\n");
            $printer->text('Waktu : '.$lockedOrder->created_at->format('d/m/Y H:i')."\n");
            $printer->text(str_repeat('-', 32)."\n");

            foreach ($lockedOrder->orderItems as $item) {
                $name = str_pad(mb_substr($item->product->product_name ?? 'Produk Dihapus', 0, 16), 16);
                $qty = str_pad((string) $item->qty, 3, ' ', STR_PAD_LEFT);
                $price = str_pad(number_format((float) $item->order_subtotal, 0, ',', '.'), 11, ' ', STR_PAD_LEFT);
                $printer->text("{$name} {$qty} {$price}\n");
            }
            $printer->text(str_repeat('-', 32)."\n");

            $printer->setJustification(Printer::JUSTIFY_RIGHT);
            $printer->setEmphasis(true);
            $printer->text('Total   : Rp '.number_format((float) $lockedOrder->order_amount, 0, ',', '.')."\n");
            $printer->setEmphasis(false);

            $paymentMethodValue = is_string($lockedOrder->payment_method)
                ? $lockedOrder->payment_method
                : ($lockedOrder->payment_method?->value ?? 'cash');

            if ($paymentMethodValue === 'cash') {
                $printer->text('Bayar   : Rp '.number_format((float) $lockedOrder->cash_received, 0, ',', '.')."\n");
                $printer->text('Kembali : Rp '.number_format((float) $lockedOrder->order_change, 0, ',', '.')."\n");
            } else {
                $printer->text('Metode  : '.strtoupper($paymentMethodValue)."\n");
            }

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("\nTerima Kasih Atas Kunjungan Anda\n");
            $printer->feed(2);
            $printer->cut();

            $receiptData = $connector->getData();
            $printer->close();

            $drawerHex = "\x1B\x70\x00\x19\xFA";

            return [
                'receipt_base64' => base64_encode($receiptData),
                'drawer_hex' => bin2hex($drawerHex),
                'print_sequence' => $newPrintSequence,
                'is_reprint' => $isReprint,
                'receipt_text' => $receiptData,
            ];
        });
    }

    /**
     * Backward-compatible wrapper untuk pemanggilan lama.
     *
     * @return array{receipt_base64: string, drawer_hex: string}
     */
    public function generatePayload(Order $order): array
    {
        $result = $this->generatePrintPayload(
            $order,
            $order->user_id ?? 1,
            $order->created_by_device_id,
            $order->shift_id
        );

        return [
            'receipt_base64' => $result['receipt_base64'],
            'drawer_hex' => $result['drawer_hex'],
        ];
    }
}
