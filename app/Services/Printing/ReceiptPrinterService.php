<?php

namespace App\Services\Printing;

use App\Models\Order;
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;

class ReceiptPrinterService
{
    /**
     * @return array{receipt_base64: string, drawer_hex: string}
     */
    public function generatePayload(Order $order): array
    {
        $connector = new DummyPrintConnector();
        $printer = new Printer($connector);

        // Header
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("YOVEL COFFEE & CAFE\n");
        $printer->text("Jl. Mawar No. 123, Jakarta\n");
        $printer->text("================================\n");

        // Info Transaksi
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Order : " . $order->order_code . "\n");
        $printer->text("Kasir : " . ($order->user->name ?? 'Self-Order') . "\n");
        $printer->text("Waktu : " . $order->created_at->format('d/m/Y H:i') . "\n");
        $printer->text("--------------------------------\n");

        // Item Pesanan
        foreach ($order->items as $item) {
            $name = str_pad(substr($item->product->name, 0, 16), 16);
            $qty = str_pad((string)$item->quantity, 3, ' ', STR_PAD_LEFT);
            $price = str_pad(number_format($item->subtotal, 0, ',', '.'), 11, ' ', STR_PAD_LEFT);
            $printer->text("{$name} {$qty} {$price}\n");
        }
        $printer->text("--------------------------------\n");

        // Total
        $printer->setJustification(Printer::JUSTIFY_RIGHT);
        $printer->text("Total   : Rp " . number_format($order->total_amount, 0, ',', '.') . "\n");
        $printer->text("Bayar   : Rp " . number_format($order->paid_amount ?? $order->total_amount, 0, ',', '.') . "\n");
        $change = max(0, ($order->paid_amount ?? $order->total_amount) - $order->total_amount);
        $printer->text("Kembali : Rp " . number_format($change, 0, ',', '.') . "\n");

        // Footer
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("\nTerima Kasih Atas Kunjungan Anda\n");
        $printer->cut();

        $receiptData = $connector->getData();
        $printer->close();

        // Standard ESC/POS drawer kick command (Pin 2)
        $drawerHex = "x1B\x70\x00\x19\xFA"; 

        return [
            'receipt_base64' => base64_encode($receiptData),
            'drawer_hex' => bin2hex($drawerHex)
        ];
    }
}
