<?php

namespace App\Services\Printing;

use App\Models\Order;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer;

class ReceiptPrinterService
{
    /**
     * [OMEGA-NODE4] Perbaikan: relasi/kolom Order->items(), quantity,
     * subtotal, total_amount, paid_amount tidak pernah ada di model asli
     * -- diganti ke orderItems(), qty, order_subtotal, order_amount,
     * cash_received. Drawer byte diperbaiki dari "x1B..." (literal, bukan
     * escape) menjadi "\x1B..." (ESC sungguhan). | 2026-09-22
     *
     * @return array{receipt_base64: string, drawer_hex: string}
     */
    public function generatePayload(Order $order): array
    {
        $order->loadMissing(['orderItems.product', 'user']);

        $connector = new DummyPrintConnector;
        $printer = new Printer($connector);

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text("YOVEL COFFEE & CAFE\n");
        $printer->setEmphasis(false);
        $printer->text("Jl. Mawar No. 123, Jakarta\n");
        $printer->text(str_repeat('-', 32)."\n");

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text('Order : '.$order->order_code."\n");
        $printer->text('Kasir : '.($order->user->name ?? 'Self-Order')."\n");
        $printer->text('Waktu : '.$order->created_at->format('d/m/Y H:i')."\n");
        $printer->text(str_repeat('-', 32)."\n");

        foreach ($order->orderItems as $item) {
            $name = str_pad(mb_substr($item->product->product_name ?? 'Produk Dihapus', 0, 16), 16);
            $qty = str_pad((string) $item->qty, 3, ' ', STR_PAD_LEFT);
            $price = str_pad(number_format($item->order_subtotal, 0, ',', '.'), 11, ' ', STR_PAD_LEFT);
            $printer->text("{$name} {$qty} {$price}\n");
        }
        $printer->text(str_repeat('-', 32)."\n");

        $printer->setJustification(Printer::JUSTIFY_RIGHT);
        $printer->setEmphasis(true);
        $printer->text('Total   : Rp '.number_format($order->order_amount, 0, ',', '.')."\n");
        $printer->setEmphasis(false);

        if ($order->payment_method?->value === 'cash') {
            $printer->text('Bayar   : Rp '.number_format((float) $order->cash_received, 0, ',', '.')."\n");
            $printer->text('Kembali : Rp '.number_format((float) $order->order_change, 0, ',', '.')."\n");
        } else {
            $printer->text('Metode  : '.strtoupper($order->payment_method?->value ?? '-')."\n");
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("\nTerima Kasih Atas Kunjungan Anda\n");
        $printer->feed(2);
        $printer->cut();

        $receiptData = $connector->getData();
        $printer->close();

        // ESC p m t1 t2 -- drawer kick Pin 2 standar. Bug lama: karakter
        // pertama tertulis "x1B" tanpa backslash sehingga tidak pernah
        // membentuk byte 0x1B yang valid.
        $drawerHex = "\x1B\x70\x00\x19\xFA";

        return [
            'receipt_base64' => base64_encode($receiptData),
            'drawer_hex' => bin2hex($drawerHex),
        ];
    }
}
