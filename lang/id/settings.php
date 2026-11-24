<?php

declare(strict_types=1);

return [
    'title' => 'Pengaturan Sistem',
    'subtitle' => 'Konfigurasi profil perusahaan, default regional, cabang, perangkat POS, dan kontrol akses.',
    'sections' => [
        'general' => 'Profil Perusahaan',
        'branches' => 'Cabang & Lokasi',
        'devices' => 'Terminal POS & Printer',
        'taxes' => 'Pajak & Biaya Layanan',
        'users' => 'Manajemen Pengguna',
        'roles' => 'Peran & Hak Akses',
        'integrations' => 'API & Integrasi',
        'audit' => 'Log Audit Sistem',
    ],
    'branches' => [
        'title' => 'Cabang Operasional',
        'add_branch' => 'Tambah Cabang',
        'edit_branch' => 'Ubah Cabang',
        'branch_name' => 'Nama Cabang',
        'branch_code' => 'Kode Cabang',
        'address' => 'Alamat Fisik',
        'phone' => 'Telepon',
    ],
    'devices' => [
        'title' => 'Perangkat Keras & Terminal POS',
        'add_device' => 'Daftarkan Perangkat',
        'device_name' => 'Nama Perangkat',
        'device_identifier' => 'Identitas Perangkat / UUID',
        'printer_name' => 'Printer Struk',
    ],
    'taxes' => [
        'title' => 'Kelas Pajak',
        'add_tax' => 'Tambah Kelas Pajak',
        'rate' => 'Tarif Pajak (%)',
        'inclusive' => 'Harga Termasuk Pajak',
    ],
];

