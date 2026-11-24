<?php

declare(strict_types=1);

return [
    'title' => 'Manajemen Inventaris',
    'subtitle' => 'Pantau level stok, lacak mutasi, dan lakukan penyesuaian stok gudang.',
    'stock_on_hand' => 'Stok Fisik',
    'available_stock' => 'Stok Tersedia',
    'reserved_stock' => 'Stok Dipesan',
    'reorder_point' => 'Batas Pemesanan Ulang',
    'transfers' => [
        'title' => 'Transfer Stok',
        'create' => 'Buat Transfer Stok',
        'source_branch' => 'Cabang Asal',
        'destination_branch' => 'Cabang Tujuan',
        'transfer_number' => 'Nomor Transfer',
        'sent' => 'Dikirim',
        'received' => 'Diterima',
    ],
    'adjustments' => [
        'title' => 'Penyesuaian Stok',
        'create' => 'Buat Penyesuaian Stok',
        'reason' => 'Alasan Penyesuaian',
        'quantity_diff' => 'Selisih Kuantitas',
    ],
    'stocktake' => [
        'title' => 'Stok Opname',
        'counted_qty' => 'Kuantitas Dihitung',
        'system_qty' => 'Kuantitas Sistem',
        'discrepancy' => 'Selisih Stok',
    ],
    'messages' => [
        'transfer_created' => 'Transfer stok berhasil dibuat.',
        'transfer_received' => 'Transfer stok ditandai telah diterima.',
        'adjustment_posted' => 'Penyesuaian stok berhasil diposting.',
    ],
];

