<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['permission_code' => 'finance.coa.view', 'description' => 'Melihat daftar dan hierarki Bagan Akun (Chart of Accounts)'],
            ['permission_code' => 'finance.coa.manage', 'description' => 'Membuat, mengubah, dan menonaktifkan akun pada Bagan Akun'],
            ['permission_code' => 'finance.period.view', 'description' => 'Melihat daftar kalender fiskal dan periode akuntansi'],
            ['permission_code' => 'finance.period.manage', 'description' => 'Mengatur kalender fiskal dan membuka periode akuntansi'],
            ['permission_code' => 'finance.period.close', 'description' => 'Menjalankan prosedur penutupan periode (Soft/Hard Close)'],
            ['permission_code' => 'finance.period.reopen', 'description' => 'Membuka kembali periode akuntansi yang telah ditutup (Controlled Reopen)'],
            ['permission_code' => 'finance.journal.view', 'description' => 'Melihat daftar jurnal umum dan buku besar'],
            ['permission_code' => 'finance.journal.create', 'description' => 'Membuat draf jurnal manual dan penyesuaian'],
            ['permission_code' => 'finance.journal.approve', 'description' => 'Menyetujui draf jurnal manual'],
            ['permission_code' => 'finance.journal.post', 'description' => 'Memposting jurnal manual ke buku besar umum'],
            ['permission_code' => 'finance.journal.reverse', 'description' => 'Menerbitkan jurnal pembalik (reversal) atas jurnal yang telah diposting'],
            ['permission_code' => 'finance.ap.view', 'description' => 'Melihat buku pembantu utang (AP Subledger)'],
            ['permission_code' => 'finance.ap.create_payment', 'description' => 'Mencatat rencana pengeluaran pembayaran ke supplier'],
            ['permission_code' => 'finance.ap.approve_payment', 'description' => 'Menyetujui pengeluaran pembayaran utang supplier'],
            ['permission_code' => 'finance.ap.allocate', 'description' => 'Mengalokasikan pembayaran ke faktur pemasok'],
            ['permission_code' => 'finance.ar.view', 'description' => 'Melihat rekonsiliasi dan laporan piutang usaha (AR)'],
            ['permission_code' => 'finance.ar.receipt', 'description' => 'Mencatat bukti penerimaan pelunasan piutang pelanggan'],
            ['permission_code' => 'finance.ar.reconcile', 'description' => 'Menjalankan rekonsiliasi AR subledger terhadap GL'],
            ['permission_code' => 'finance.cash_bank.view', 'description' => 'Melihat daftar kas dan rekening bank beserta mutasinya'],
            ['permission_code' => 'finance.cash_bank.manage', 'description' => 'Membuat dan mengubah master rekening kas/bank'],
            ['permission_code' => 'finance.cash_bank.transfer', 'description' => 'Mencatat transfer dana internal antarrekening kas/bank'],
            ['permission_code' => 'finance.cash_bank.reconcile', 'description' => 'Melakukan rekonsiliasi mutasi bank terhadap rekening koran'],
            ['permission_code' => 'finance.tax.view', 'description' => 'Melihat konfigurasi dan buku pembantu transaksi pajak'],
            ['permission_code' => 'finance.tax.manage', 'description' => 'Mengatur kode pajak, tarif ber-tanggal efektif, dan pemetaan akun pajak'],
            ['permission_code' => 'finance.tax.report', 'description' => 'Mengekspor laporan rekapitulasi pajak PPN dan PB1'],
            ['permission_code' => 'finance.expense.view', 'description' => 'Melihat daftar biaya dan pengeluaran operasional'],
            ['permission_code' => 'finance.expense.create', 'description' => 'Mengajukan pencatatan beban operasional baru'],
            ['permission_code' => 'finance.expense.approve', 'description' => 'Menyetujui pengajuan biaya operasional'],
            ['permission_code' => 'finance.expense.pay', 'description' => 'Mengeksekusi pembayaran atas biaya yang telah disetujui'],
            ['permission_code' => 'finance.fixed_asset.view', 'description' => 'Melihat daftar aset tetap dan jadwal depresiasi'],
            ['permission_code' => 'finance.fixed_asset.manage', 'description' => 'Mencatat perolehan dan pelepasan aset tetap'],
            ['permission_code' => 'finance.fixed_asset.depreciate', 'description' => 'Mengeksekusi jurnal penyusutan bulanan aset tetap'],
            ['permission_code' => 'finance.reports.view', 'description' => 'Melihat laporan keuangan (Trial Balance, P&L, Neraca, Arus Kas)'],
            ['permission_code' => 'finance.reports.export', 'description' => 'Mengekspor laporan keuangan ke format PDF dan Excel'],
        ];

        foreach ($permissions as $p) {
            DB::table('permissions')->insertOrIgnore([
                'permission_code' => $p['permission_code'],
                'description' => $p['description'],
            ]);
        }
    }

    public function down(): void
    {
        $codes = [
            'finance.coa.view', 'finance.coa.manage',
            'finance.period.view', 'finance.period.manage', 'finance.period.close', 'finance.period.reopen',
            'finance.journal.view', 'finance.journal.create', 'finance.journal.approve', 'finance.journal.post', 'finance.journal.reverse',
            'finance.ap.view', 'finance.ap.create_payment', 'finance.ap.approve_payment', 'finance.ap.allocate',
            'finance.ar.view', 'finance.ar.receipt', 'finance.ar.reconcile',
            'finance.cash_bank.view', 'finance.cash_bank.manage', 'finance.cash_bank.transfer', 'finance.cash_bank.reconcile',
            'finance.tax.view', 'finance.tax.manage', 'finance.tax.report',
            'finance.expense.view', 'finance.expense.create', 'finance.expense.approve', 'finance.expense.pay',
            'finance.fixed_asset.view', 'finance.fixed_asset.manage', 'finance.fixed_asset.depreciate',
            'finance.reports.view', 'finance.reports.export',
        ];

        DB::table('permissions')->whereIn('permission_code', $codes)->delete();
    }
};
