# Finance Module 7 — Operational Runbook & Production Guide

## 1. Executive Summary & Architectural Overview

Modul 7 (Finance & General Ledger) adalah tulang punggung akuntansi dan pelaporan finansial pada platform Apeiron POS. Modul ini dirancang dengan prinsip **Dual-Entry Bookkeeping**, **Full Accrual Basis (PSAK)**, **Append-Only Immutability**, dan **Arbitrary-Precision Arithmetic (bcmath)**.

### Submodul dalam Modul 7:
1. **Submodul 7.1 — General Ledger Core:** Bagan Akun Standar (CoA 3-Tier), Jurnal Otomatis & Manual, Engine Reversal.
2. **Submodul 7.2 — Accounts Receivable (AR):** Buku Pembantu Piutang, Faktur Pelanggan B2B, Alokasi Pembayaran Parsial, Credit Note.
3. **Submodul 7.3 — Accounts Payable (AP):** Buku Pembantu Utang, Three-Way Matching Faktur Supplier, Alokasi Pembayaran Utang.
4. **Submodul 7.4 — Cash & Bank Management:** Mutasi Kasir POS, Transfer Antar-Bank/Kas, Impor Rekening Koran CSV, Rekonsiliasi Bank Otomatis & Manual.
5. **Submodul 7.5 — Tax Engine Internal:** PPN Masukan & Keluaran, PPh 21/23/Final, Imutabilitas Snapshot Pajak.
6. **Submodul 7.6 — Expense Management:** Pencatatan Beban Multi-Kategori, Workflow Persetujuan & Pemisahan Tugas (SoD), Recovery Gagal Bayar Kas.
7. **Submodul 7.7 — Fixed Assets & Depreciation:** Kapitalisasi Aset Tetap, Jadwal Penyusutan Garis Lurus, Jurnal Otomatis, Pelepasan/Disposal Aset.
8. **Submodul 7.8 — Inventory Accounting & Valuation:** Integrasi HPP Moving Average, Penyesuaian Stok (Waste F&B vs Restok Retail), Snapshot Valuasi Akhir Periode.
9. **Submodul 7.9 — Financial Closing:** Kalender Fiskal, 7 Gerbang Rekonsiliasi Otomatis (*Closing Gates*), Soft/Hard Close, Controlled Reopening, Year-End Close & Retained Earnings Roll-Forward.
10. **Submodul 7.10 — Financial Statements:** Neraca Saldo (*Trial Balance*), Laporan Laba Rugi (*Profit & Loss*), Neraca (*Balance Sheet*), Laporan Arus Kas (*Cash Flow*), Ekspor Excel Asinkron.

---

## 2. Invarian Finansial Inti (FIN-M7-01 s/d FIN-M7-12)

| Kode Invarian | Nama Invarian | Deskripsi & Penegakan |
|---|---|---|
| `FIN-M7-01` | **Balanced Journal Enforcement** | Setiap jurnal POSTED wajib memiliki $\sum \text{Debit} \equiv \sum \text{Credit} > 0$ (`bccomp === 0`) dengan minimal 2 baris. |
| `FIN-M7-02` | **Single-Sided Line Exclusivity** | Setiap baris jurnal hanya boleh berisi nilai positif pada Debit atau Kredit (CHECK constraint `chk_journal_lines_single_sided`). |
| `FIN-M7-03` | **Posted Journal Immutability** | Jurnal POSTED/REVERSED dilarang di-UPDATE atau di-DELETE (MySQL Triggers `trg_journal_entries_immutable` & `trg_journal_lines_immutable`). |
| `FIN-M7-04` | **Correction via Reversal Only** | Koreksi jurnal POSTED hanya boleh dilakukan melalui jurnal `REVERSAL` atau `ADJUSTMENT`. |
| `FIN-M7-05` | **Verifiable Source Reference** | Setiap jurnal sistem wajib memiliki `source_module`, `source_type`, `source_id`, dan `idempotency_key` yang dapat diverifikasi. |
| `FIN-M7-06` | **Strict Idempotency** | Seluruh operasi posting wajib idempoten via indeks unik `(tenant_id, idempotency_key)`. |
| `FIN-M7-07` | **Leaf-Only Active Posting** | Posting hanya diizinkan pada akun daun (`is_postable = true`) dan aktif (`is_active = true`). |
| `FIN-M7-08` | **Closed-Period Enforcement** | Posting ke periode `CLOSED` atau `LOCKED` ditolak (Trigger `trg_journal_entries_before_insert`), kecuali jurnal `CLOSING_ENTRY` akhir tahun. |
| `FIN-M7-09` | **Deterministic Precision Arithmetic** | Seluruh kalkulasi wajib menggunakan `bcmath` dengan skala 2 desimal uang dan 4 desimal kuantitas/biaya unit. |
| `FIN-M7-10` | **Tenant Boundary Consistency** | Header jurnal, baris jurnal, akun CoA, dan dokumen sumber wajib memiliki `tenant_id` yang identik (Composite Foreign Keys). |
| `FIN-M7-11` | **Concurrency Serialization** | Alokasi pembayaran utang/piutang wajib menggunakan `SELECT ... FOR UPDATE` dengan `ORDER BY id ASC`. |
| `FIN-M7-12` | **Subledger-to-GL Control Integrity** | Akun kontrol GL (`is_control_account = true`) tidak menerima jurnal manual sembarangan dan wajib seimbang terhadap subledger. |

---

## 3. Prosedur Operasional Rutin

### 3.1 Evaluasi Gerbang Rekonsiliasi Finansial
Sebelum melakukan penutupan periode bulanan, jalankan evaluasi 7 gerbang rekonsiliasi via CLI:
```bash
# Periksa rekonsiliasi seluruh tenant untuk periode berjalan
php artisan finance:reconcile-ledgers

# Periksa rekonsiliasi tenant tertentu untuk periode spesifik
php artisan finance:reconcile-ledgers 1 --period=2026-10
```

#### Rincian 7 Gerbang Rekonsiliasi:
1. **`GATE_1_OUTBOX`:** 0 antrean pending/failed pada `financial_event_outboxes`, `accounting_payable_outbox`, dan `accounting_receivable_outboxes`.
2. **`GATE_2_DRAFT_JOURNALS`:** 0 jurnal berstatus `DRAFT` atau `PENDING_APPROVAL` pada periode tersebut.
3. **`GATE_3_TRIAL_BALANCE`:** Neraca saldo seimbang ($\sum \text{Debit} = \sum \text{Credit}$).
4. **`GATE_4_AR_SUBLEDGER`:** Total saldo piutang `customer_invoices` seimbang dengan GL Akun 1130 (`AR_CONTROL`).
5. **`GATE_5_AP_SUBLEDGER`:** Total sisa utang `supplier_invoices` seimbang dengan GL Akun 2101 (`AP_CONTROL`).
6. **`GATE_6_INVENTORY_SUBLEDGER`:** Total nilai valuasi stok seimbang dengan GL Akun 1140 & 1141 (`INVENTORY`).
7. **`GATE_7_DEPRECIATION`:** Seluruh jadwal penyusutan aset tetap periode tersebut telah berstatus `POSTED`.

---

### 3.2 Penutupan Periode (Period Closing)
Penutupan periode dilakukan secara aman via `AccountingPeriodService::closePeriod()`:
```php
use App\Modules\Finance\Services\AccountingPeriodService;

$service = app(AccountingPeriodService::class);
$closedPeriod = $service->closePeriod($periodId, $actorUser);
```
- Sistem secara otomatis memvalidasi seluruh 7 gerbang.
- Jika ada gerbang yang gagal, sistem melempar `ReconciliationGateFailedException` dan membatalkan penutupan.
- Jika seluruh gerbang lolos, status periode beralih menjadi `CLOSED`, materialized balances difinalisasi pada `account_period_balances`, snapshot valuasi inventori disimpan, dan log audit dicatat di `period_closing_logs`.

---

### 3.3 Pembukaan Kembali Periode Terkendali (Controlled Reopening)
Jika terdapat audit eksternal atau kebutuhan koreksi yang sah:
- Syarat: Alasan pembukaan kembali wajib memiliki panjang minimal 20 karakter.
- Syarat: Periode berstatus `LOCKED` **dilarang keras** dibuka kembali.
```php
$reopenedPeriod = $service->reopenPeriod($periodId, 'Koreksi penyesuaian audit eksternal tahunan KAP', $actorUser);
```

---

### 3.4 Penutupan Akhir Tahun Buku (Year-End Close)
Menutup tahun buku fiskal setelah seluruh 12 periode berstatus `CLOSED` atau `LOCKED`:
```php
$result = $service->runYearEndClose(2026, $actorUser, $tenantId);
```
- Menghitung laba/rugi bersih dari seluruh akun nominal (Pendapatan & Beban).
- Menerbitkan jurnal penutup (`CLOSING_ENTRY`) memindahkan saldo nominal ke Laba Ditahan (`3200 - Retained Earnings`).
- Melakukan roll-forward saldo akun permanen (Aset, Liabilitas, Ekuitas) sebagai Saldo Awal Periode 1 Tahun Fiskal Berikutnya.

---

## 4. Pelaporan Keuangan & Rekonstruksi Saldo

### 4.1 Menghasilkan Laporan Keuangan
Laporan keuangan diakses secara deterministik melalui `FinancialStatementService`:
```php
use App\Modules\Finance\Services\FinancialStatementService;

$service = app(FinancialStatementService::class);

// 1. Neraca Saldo
$trialBalance = $service->generateTrialBalance($periodId, $storeId);

// 2. Laporan Laba Rugi
$profitAndLoss = $service->generateProfitAndLoss($periodId, $storeId, yearToDate: false);

// 3. Neraca
$balanceSheet = $service->generateBalanceSheet($periodId, $storeId);

// 4. Laporan Arus Kas
$cashFlow = $service->generateCashFlowStatement($periodId, $storeId);
```

### 4.2 Rekonstruksi / Rebuild Saldo Periode
Jika diperlukan verifikasi ulang integritas materialized balance terhadap data mentah `journal_lines`:
```php
$service->rebuildPeriodBalances($periodId, $tenantId);
```

---

## 5. Pemeriksaan Integritas Database Otomatis (Invariant Verifier)

Untuk memastikan tidak ada anomali struktural atau ketidakseimbangan finansial di tingkat database, jalankan suite verifikasi berkala:
```bash
php artisan pos:verify-database
```
Script `database/sql/verify/12_finance_gl_invariants.sql` memeriksa:
1. Tidak ada jurnal POSTED yang debit $\neq$ kredit.
2. Header total sama persis dengan agregasi baris `journal_lines`.
3. Minimal 2 baris jurnal per transaksi.
4. Baris jurnal bersifat single-sided.
5. Konsistensi `tenant_id` antara header, baris, akun CoA, dan periode.
6. Tidak ada posting pada akun non-postable atau non-aktif.
7. Tidak ada posting pada periode LOCKED.
8. Keseimbangan neraca saldo global setiap tenant.
