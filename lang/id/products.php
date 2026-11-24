<?php

declare(strict_types=1);

return [
    'title' => 'Produk',
    'subtitle' => 'Kelola katalog produk, varian, harga, dan klasifikasi master barang.',
    'add_product' => 'Tambah Produk',
    'edit_product' => 'Ubah Produk',
    'bulk_edit' => 'Ubah Massal',
    'import_csv' => 'Impor CSV',
    'export_excel' => 'Ekspor Excel',
    'search_placeholder' => 'Cari berdasarkan nama, SKU, atau barcode...',
    'fields' => [
        'name' => 'Nama Produk',
        'code' => 'Kode Produk',
        'sku' => 'SKU',
        'barcode' => 'Barcode',
        'type' => 'Tipe Produk',
        'category' => 'Kategori',
        'brand' => 'Merek',
        'unit' => 'Satuan Dasar',
        'price' => 'Harga Jual',
        'cost' => 'HPP (COGS)',
        'stock' => 'Stok Fisik',
        'status' => 'Status',
        'track_inventory' => 'Lacak Inventaris',
        'has_variants' => 'Memiliki Varian',
    ],
    'types' => [
        'standard' => 'Produk Standar',
        'service' => 'Layanan / Jasa',
        'non_inventory' => 'Non-Inventaris',
        'bundle' => 'Paket / Bundel',
    ],
    'empty' => [
        'title' => 'Belum Ada Produk',
        'description' => 'Buat produk baru atau impor daftar produk menggunakan CSV untuk memulai.',
    ],
    'messages' => [
        'created' => 'Produk berhasil dibuat.',
        'updated' => 'Produk berhasil diperbarui.',
        'deleted' => 'Produk berhasil dihapus.',
        'bulk_updated' => 'Produk terpilih berhasil diperbarui.',
    ],
];

