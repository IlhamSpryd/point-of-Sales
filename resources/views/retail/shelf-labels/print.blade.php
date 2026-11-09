<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Label Rak - Batch {{ $batchNumber }}</title>
    <style>
        @page {
            size: 60mm 30mm;
            margin: 0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        body {
            background-color: #f3f4f6;
            color: #111827;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Screen toolbar styling */
        .no-print {
            padding: 12px 24px;
            background-color: #1f2937;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            font-size: 14px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .no-print button {
            background-color: #2563eb;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .no-print button:hover {
            background-color: #1d4ed8;
        }

        .no-print a {
            color: #9ca3af;
            text-decoration: none;
        }

        .no-print a:hover {
            color: #ffffff;
        }

        /* Print Container */
        .labels-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            padding: 20px 0;
        }

        /* Physical 60x30mm retail shelf label */
        .shelf-label-card {
            width: 60mm;
            height: 30mm;
            max-width: 60mm;
            max-height: 30mm;
            padding: 2mm 3mm;
            background: #ffffff;
            border: 1px dashed #d1d5db;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
        }

        .label-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            border-bottom: 0.5px solid #000000;
            padding-bottom: 1mm;
            font-size: 6.5pt;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .store-name {
            max-width: 35mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sku-code {
            font-family: monospace;
            font-size: 6pt;
            color: #4b5563;
        }

        .product-title {
            font-size: 8pt;
            font-weight: 700;
            line-height: 1.15;
            max-height: 7.5mm;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            color: #000000;
            margin-top: 1mm;
        }

        .pricing-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 1mm;
        }

        .barcode-box {
            width: 32mm;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .barcode-box svg {
            width: 100%;
            height: 8mm;
        }

        .barcode-text {
            font-family: monospace;
            font-size: 5.5pt;
            letter-spacing: 0.5px;
            color: #000000;
            margin-top: 0.5mm;
        }

        .price-box {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .price-currency {
            font-size: 6pt;
            font-weight: 700;
            color: #111827;
            line-height: 1;
        }

        .price-amount {
            font-size: 12pt;
            font-weight: 900;
            color: #000000;
            line-height: 1;
            letter-spacing: -0.5px;
        }

        .print-date {
            font-size: 4.5pt;
            color: #6b7280;
            margin-top: 0.5mm;
        }

        @media print {
            body {
                background-color: transparent !important;
            }

            .no-print {
                display: none !important;
            }

            .labels-container {
                padding: 0 !important;
                gap: 0 !important;
            }

            .shelf-label-card {
                border: none !important;
                margin: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <div>
            <strong>Pratinjau Cetak Label Rak Fisik (60x30 mm)</strong>
            <span style="margin-left: 8px; opacity: 0.7;">Batch: {{ $batchNumber }} ({{ count($labels) }} Label)</span>
        </div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="javascript:window.close()">Tutup Jendela</a>
            <button onclick="window.print()">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                Cetak Label Fisik
            </button>
        </div>
    </div>

    <div class="labels-container">
        @foreach ($labels as $label)
            <div class="shelf-label-card">
                <div class="label-header">
                    <span class="store-name">{{ $storeName }}</span>
                    <span class="sku-code">{{ $label['sku'] }}</span>
                </div>

                <div class="product-title">
                    {{ $label['product_name'] }}
                </div>

                <div class="pricing-section">
                    <div class="barcode-box">
                        {!! $label['barcode_svg'] !!}
                        <span class="barcode-text">{{ $label['barcode'] }}</span>
                    </div>
                    <div class="price-box">
                        <span class="price-currency">IDR</span>
                        <span class="price-amount">{{ number_format($label['price'], 0, ',', '.') }}</span>
                        <span class="print-date">{{ $label['printed_at'] }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        // Auto-trigger print dialog if opened in popup or preview mode
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('autoprint') !== 'false') {
                setTimeout(function() {
                    window.print();
                }, 300);
            }
        });
    </script>
</body>
</html>
