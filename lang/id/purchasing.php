<?php

declare(strict_types=1);

return [
    'title' => 'Pembelian & Pengadaan',
    'subtitle' => 'Kelola pesanan pembelian, penerimaan barang, dan faktur pemasok.',
    'orders' => [
        'title' => 'Pesanan Pembelian (PO)',
        'create' => 'Buat Pesanan Pembelian',
        'po_number' => 'Nomor PO',
        'supplier' => 'Pemasok',
        'order_date' => 'Tanggal Pesanan',
        'expected_date' => 'Perkiraan Tiba',
        'total_amount' => 'Total Nilai',
        'receiving_status' => 'Status Penerimaan',
        'invoicing_status' => 'Status Penagihan',
    ],
    'grn' => [
        'title' => 'Penerimaan Barang (GRN)',
        'create' => 'Terima Barang',
        'grn_number' => 'Nomor GRN',
        'received_date' => 'Tanggal Diterima',
        'received_qty' => 'Kuantitas Diterima',
    ],
    'invoices' => [
        'title' => 'Faktur Pemasok',
        'invoice_number' => 'Nomor Faktur',
        'due_date' => 'Tanggal Jatuh Tempo',
        'match_status' => 'Status Pencocokan 3-Arah',
    ],
    'returns' => [
        'title' => 'Retur Pembelian',
        'create' => 'Buat Retur Pembelian',
        'return_number' => 'Nomor Retur',
        'debit_note' => 'Nota Debit',
    ],
];

