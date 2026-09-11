<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Struk Pesanan') }} - {{ $order->order_code }}</title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
        }

        .receipt-container {
            width: 300px;
            padding: 15px;
            margin: 20px auto;
            border: 1px dashed #ccc;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
            padding: 0;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        
        .header {
            margin-bottom: 20px;
        }
        .header h2 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 12px;
        }

        .divider {
            border-bottom: 1px dashed #000;
            margin: 10px 0;
        }

        .info-table {
            width: 100%;
            font-size: 11px;
            margin-bottom: 10px;
        }
        .info-table td {
            vertical-align: top;
            padding-bottom: 3px;
        }
        .info-table td:nth-child(2) {
            text-align: right;
        }

        .items-table {
            width: 100%;
            font-size: 11px;
            border-collapse: collapse;
        }
        .items-table th {
            text-align: left;
            border-bottom: 1px dashed #000;
            padding-bottom: 5px;
        }
        .items-table td {
            padding: 5px 0;
            vertical-align: top;
        }
        .items-table .item-name {
            display: block;
            margin-bottom: 2px;
        }
        .items-table .item-qty {
            display: inline-block;
            width: 30px;
        }
        .items-table .item-price {
            display: inline-block;
        }
        .items-table .item-subtotal {
            text-align: right;
        }

        .totals-table {
            width: 100%;
            font-size: 12px;
            margin-top: 5px;
        }
        .totals-table td {
            padding: 2px 0;
        }

        .footer {
            margin-top: 20px;
            font-size: 11px;
        }
        .footer p {
            margin-bottom: 5px;
        }

        @media print {
            body {
                background: none;
            }
            .receipt-container {
                border: none;
                margin: 0;
                padding: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="receipt-container">
        <!-- Header -->
        <div class="header text-center">
            <h2>{{ __('POINT OF SALES') }}</h2>
            <p>{{ __('Jl. Contoh Alamat No. 123') }}</p>
            <p>{{ __('Telp: 08123456789') }}</p>
        </div>

        <div class="divider"></div>

        <!-- Info -->
        <table class="info-table">
            <tr>
                <td>{{ __('Tgl') }}: {{ $order->created_at->format('d/m/Y H:i') }}</td>
                <td>{{ __('Ref') }}: {{ $order->order_code }}</td>
            </tr>
            <tr>
                <td>{{ __('Kasir') }}: {{ $order->user->name ?? 'Admin' }}</td>
                <td>{{ __('Status') }}: {{ strtoupper($order->order_status) }}</td>
            </tr>
        </table>

        <!-- Items -->
        <table class="items-table">
            <thead>
                <tr>
                    <th colspan="2">{{ __('Item') }}</th>
                    <th class="text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->orderDetails as $item)
                <tr>
                    <td colspan="2">
                        <span class="item-name">{{ $item->product->product_name ?? __('Produk Dihapus') }}</span>
                        <span class="item-qty">{{ $item->qty }}x</span>
                        <span class="item-price">{{ number_format($item->order_price, 0, ',', '.') }}</span>
                    </td>
                    <td class="item-subtotal text-bold">
                        {{ number_format($item->order_subtotal, 0, ',', '.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- Totals -->
        <table class="totals-table">
            <tr>
                <td>{{ __('Subtotal') }}</td>
                <td class="text-right">{{ number_format($order->subtotal_amount, 0, ',', '.') }}</td>
            </tr>
            @if($order->tax_amount > 0)
            <tr>
                <td>{{ __('Pajak (10%)') }}</td>
                <td class="text-right">{{ number_format($order->tax_amount, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="text-bold" style="font-size: 14px;">
                <td style="padding-top: 5px;">{{ __('GRAND TOTAL') }}</td>
                <td class="text-right" style="padding-top: 5px;">{{ number_format($order->order_amount, 0, ',', '.') }}</td>
            </tr>
            <tr><td colspan="2"><div class="divider" style="margin: 5px 0;"></div></td></tr>
            <tr>
                <td>{{ __('Tipe Bayar') }}</td>
                <td class="text-right text-bold">{{ strtoupper($order->payment_method) }}</td>
            </tr>
            @if($order->payment_method === 'cash')
            <tr>
                <td>{{ __('Tunai') }}</td>
                <td class="text-right">{{ number_format($order->cash_received ?? $order->order_amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td>{{ __('Kembali') }}</td>
                <td class="text-right">{{ number_format($order->order_change, 0, ',', '.') }}</td>
            </tr>
            @endif
        </table>

        <div class="divider"></div>

        <!-- Footer -->
        <div class="footer text-center">
            <p>{{ __('Terima Kasih Atas Kunjungan Anda') }}</p>
            <p>{{ __('Barang yang sudah dibeli tidak dapat ditukar/dikembalikan') }}</p>
        </div>

    </div>

</body>
</html>
