<?php

declare(strict_types=1);

return [
    'title' => 'Manajemen Keuangan',
    'subtitle' => 'Buku besar, bagan akun (CoA), entri jurnal, dan laporan keuangan otomatis.',
    'coa' => [
        'title' => 'Bagan Akun (CoA)',
        'account_code' => 'Kode Akun',
        'account_name' => 'Nama Akun',
        'account_type' => 'Tipe Akun',
        'normal_balance' => 'Saldo Normal',
        'current_balance' => 'Saldo Saat Ini',
    ],
    'journals' => [
        'title' => 'Jurnal Umum',
        'entry_number' => 'Nomor Jurnal',
        'entry_date' => 'Tanggal Posting',
        'debit' => 'Debit',
        'credit' => 'Kredit',
        'balance' => 'Saldo',
        'unbalanced' => 'Jurnal tidak seimbang. Total debit harus sama dengan total kredit.',
    ],
    'reconciliation' => [
        'title' => 'Rekonsiliasi Bank',
        'bank_statement' => 'Saldo Rekening Koran',
        'ledger_balance' => 'Saldo Buku Besar',
        'unreconciled' => 'Selisih Belum Rekonsiliasi',
        'match' => 'Rekonsiliasi Terpilih',
    ],
    'periods' => [
        'title' => 'Periode Akuntansi',
        'period_name' => 'Nama Periode',
        'start_date' => 'Tanggal Mulai',
        'end_date' => 'Tanggal Selesai',
        'status' => 'Status',
        'close_period' => 'Tutup Periode Akuntansi',
    ],
];
