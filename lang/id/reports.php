<?php

declare(strict_types=1);

return [
    'title' => 'Laporan & Business Intelligence',
    'subtitle' => 'Analisis menyeluruh kinerja penjualan, valuasi inventaris, dan pelaporan akuntansi.',
    'sales' => [
        'title' => 'Laporan Penjualan',
        'gross_sales' => 'Penjualan Kotor',
        'net_sales' => 'Penjualan Bersih',
        'total_discounts' => 'Total Diskon',
        'total_tax' => 'Total Pajak Terkumpul',
        'cogs' => 'HPP (COGS)',
        'gross_margin' => 'Margin Kotor',
        'transactions_count' => 'Jumlah Transaksi',
        'aov' => 'Rata-Rata Nilai Pesanan (AOV)',
    ],
    'inventory' => [
        'title' => 'Laporan Valuasi Inventaris',
        'stock_movement' => 'Riwayat Mutasi Stok',
        'dead_stock' => 'Stok Bergerak Lambat / Mati',
        'turnover_rate' => 'Rasio Perputaran Stok',
    ],
    'financial' => [
        'income_statement' => 'Laporan Laba Rugi',
        'balance_sheet' => 'Neraca Keuangan',
        'cash_flow' => 'Laporan Arus Kas',
        'trial_balance' => 'Neraca Saldo',
    ],
    'filters' => [
        'date_range' => 'Rentang Tanggal',
        'today' => 'Hari Ini',
        'yesterday' => 'Kemarin',
        'last_7_days' => '7 Hari Terakhir',
        'last_30_days' => '30 Hari Terakhir',
        'this_month' => 'Bulan Ini',
        'last_month' => 'Bulan Lalu',
        'custom_range' => 'Rentang Kustom',
        'branch' => 'Filter berdasarkan Cabang',
        'category' => 'Filter berdasarkan Kategori',
    ],
];

