<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Struk Pesanan') }} - {{ $order->order_code }}</title>
    <link rel="icon" href="{{ asset('spark-admin-1.0.0/assets/images/favicon.ico') }}">
    <style>
        /* CSS Reset & Base Settings */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            /* Font Monospace sangat krusial bagi thermal printer agar karakter mudah terbaca tegas */
            font-family: 'Courier New', Courier, 'Lucida Sans Typewriter', monospace;
            font-size: 11px;
            line-height: 1.3;
            color: #000;
            background: #f7f7f7;
            display: flex;
            justify-content: center;
        }

        /* Print Container: Didesain merespon ukuran kertas 58mm (approx 220px) atau 80mm */
        .receipt-container {
            width: 100%;
            max-width: 320px; /* Batas wajar tampilan layar sebelum di-print */
            padding: 20px;
            margin: 30px auto;
            background: #fff;
            border: 1px solid #eaeaea;
            border-radius: 6px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        /* Utility */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }

        /* Header Area */
        .header { margin-bottom: 15px; }
        .header h2 {
            font-size: 18px;
            font-weight: 900;
            margin-bottom: 3px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .header p {
            font-size: 11px;
            margin-bottom: 2px;
            line-height: 1.4;
        }

        /* Garis Pemisah ala Kertas Termal */
        .separator {
            border-bottom: 1px dashed #000;
            margin: 10px 0;
        }
        .separator-solid {
            border-bottom: 1px solid #000;
            margin: 8px 0;
        }

        /* Informasi Waktu & Kasir */
        .meta-info {
            font-size: 11px;
            margin-bottom: 10px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }

        /* Rincian Pesanan (Professional Layout: Nama Menu -> Baris Bawahnya QTY x Harga) */
        .items-list {
            width: 100%;
            font-size: 11px;
        }
        .item-row {
            display: flex;
            flex-direction: column;
            margin-bottom: 8px;
        }
        .item-name {
            font-weight: 600;
            margin-bottom: 2px;
            text-transform: uppercase;
            /* Paksa kata panjang yang meluber untuk diputus demi mengamankan layout */
            word-break: break-word; 
        }
        .item-details {
            display: flex;
            justify-content: space-between;
            color: #222;
        }

        /* Total Bayar & Pajak */
        .totals-area {
            width: 100%;
            font-size: 11px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }
        .grand-total-row {
            display: flex;
            justify-content: space-between;
            font-size: 16px;
            font-weight: 900;
            margin: 8px 0;
            text-transform: uppercase;
        }

        /* Footer & Label */
        .footer {
            margin-top: 15px;
            font-size: 11px;
        }
        .lunas-badge {
            display: inline-block;
            border: 2px solid #000;
            padding: 2px 10px;
            font-weight: 900;
            font-size: 14px;
            letter-spacing: 2px;
            border-radius: 4px;
            margin-bottom: 8px;
        }

        @media print {
            body {
                background: none;
                display: block; /* Matikan flex agar flow dokumen netral pada saat diprint OS */
            }
            .receipt-container {
                width: 100%;
                /* Batasi maksimum di 48mm, karena rasio efektif cetak printer 58mm adalah 48mm agar margin tepi tidak kepotong */
                max-width: 48mm; 
                margin: 0 auto;
                padding: 0;
                border: none;
                border-radius: 0;
                box-shadow: none;
                word-wrap: break-word;
                word-break: break-all; 
            }
            
            /* RESET MARGIN EDGE PRINTER THERMAL (Kunci agar pas dgn kertas roll) */
            @page { 
                margin: 0; 
            }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="receipt-container">
        <!-- Header Konstituen -->
        <div class="header text-center">
            <h2>{{ __('PPKDJP') }}</h2>
            <p>{{ __('Jl. Karet Pasar Baru Barat, Karet Tengsin') }}</p>
            <p>{{ __('Jakarta Pusat, DKI Jakarta 10250') }}</p>
            <p>{{ __('Telp: (021) 57950913') }}</p>
        </div>

        <div class="separator"></div>

        <!-- Identifikasi Transaksi -->
        <div class="meta-info">
            <div class="meta-row">
                <span>{{ __('Tgl :') }} {{ $order->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <div class="meta-row">
                <span>{{ __('Ref :') }} {{ $order->order_code }}</span>
                <span>{{ __('OP :') }} {{ $order->user->name ?? 'Admin' }}</span>
            </div>
        </div>

        <div class="separator-solid"></div>

        <!-- Detail Item -->
        <div class="items-list">
            @foreach($order->orderDetails as $item)
            <div class="item-row">
                <span class="item-name">{{ $item->product->product_name ?? __('Produk Terhapus') }}</span>
                <div class="item-details">
                    <span>{{ $item->qty }} x {{ number_format($item->order_price, 0, ',', '.') }}</span>
                    <span>{{ number_format($item->order_subtotal, 0, ',', '.') }}</span>
                </div>
            </div>
            @endforeach
        </div>

        <div class="separator-solid"></div>

        <!-- Total Nominal -->
        <div class="totals-area">
            <div class="total-row">
                <span>{{ __('Subtotal') }}</span>
                <span>{{ number_format($order->subtotal_amount, 0, ',', '.') }}</span>
            </div>
            @if($order->tax_amount > 0)
            <div class="total-row">
                <span>{{ __('Pajak') }} ({{ rtrim(rtrim(number_format($taxRatePercent, 1), '0'), '.') }}%)</span>
                <span>{{ number_format($order->tax_amount, 0, ',', '.') }}</span>
            </div>
            @endif
            
            <div class="separator"></div>

            <div class="grand-total-row">
                <span>{{ __('TOTAL') }}</span>
                <span>{{ number_format($order->order_amount, 0, ',', '.') }}</span>
            </div>
            
            <div class="separator"></div>

            <!-- Tipe Pembayaran -->
            <div class="total-row font-bold">
                <span>{{ __('BAYAR / ') }}{{ strtoupper($order->payment_method) }}</span>
                <span>{{ number_format($order->payment_method === 'cash' ? ($order->cash_received ?? $order->order_amount) : $order->order_amount, 0, ',', '.') }}</span>
            </div>
            @if($order->payment_method === 'cash')
            <div class="total-row">
                <span>{{ __('KEMBALI') }}</span>
                <span>{{ number_format($order->order_change, 0, ',', '.') }}</span>
            </div>
            @endif
        </div>

        <div class="separator"></div>

        <!-- Footer / Pesan Kepada Konsumen -->
        <div class="footer text-center">
            @if(in_array($order->order_status, ['paid', 'settlement', 'capture']))
                <div class="lunas-badge">{{ __('LUNAS') }}</div>
            @endif
            <p>{{ __('Terima Kasih Atas Kunjungan Anda') }}</p>
            <p style="margin-top: 6px; font-size: 10px; line-height: 1.2;">
                * {{ __('Barang yang sudah dibeli') }}<br>
                {{ __('tidak dapat ditukar/dikembalikan') }}
            </p>
        </div>
    </div>

</body>
</html>
