# DOMAIN 05 — FINANCIAL, ACCOUNTING & COMMERCIAL INTEGRITY AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & Financial Systems Auditor
- **Audit Target:** Transactional Finance, General Ledger, Double-Entry Bookkeeping, Chart of Accounts, Subledgers (AP/AR), Tax, Inventory Valuation, COGS/Food Cost Analytics, Payment Gateways
- **Scope:** All Financial Services (`App\Modules\Finance\*`), POS Billing, Procurement P2P, B2B AR, Shifts, Midtrans Gateway, Database Immutability Triggers
- **Audit Date:** 1 November 2026
- **Status:** **NEEDS REMEDIATION**
- **Confidence:** **HIGH** (Empirically verified via mathematical analysis, database schema constraints, and service invocation tracing)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Integritas finansial dan akuntansi merupakan pilar paling mendasar bagi sebuah platform ERP. Apeiron Systems memiliki fondasi teknis akuntansi yang sangat impresif di tingkat database engine dan domain service:
- **Immutabilitas Buku Besar & Audit Trail di Tingkat Database:** Didukung oleh trigger MariaDB (`trg_journal_entries_before_delete`, `trg_journal_entries_before_update`, `trg_journal_lines_before_delete`, `trg_journal_lines_before_update`) yang secara absolut melarang operasi `UPDATE` dan `DELETE` pada jurnal akuntansi yang telah diposting.
- **Double-Entry Balance Enforcement (FIN-M7-01):** `JournalEntryService` secara deterministik memvalidasi bahwa `Sum(Debit) === Sum(Credit) > 0`, menolak baris bersisi ganda (*single-sided invariant*), dan menolak nominal negatif.
- **Deadlock-Free Balance Locking (INV-FIN-07):** Urutan penguncian baris neraca saldo periodik (`AccountPeriodBalance`) dieksekusi secara kanonis: `ORDER BY account_id ASC` sebelum mutasi saldo.
- **Proteksi Overpayment & Over-Refund Database:** Database triggers `trg_payments_prevent_overpayment` dan `trg_payment_refunds_prevent_overrefund` mencegah pembayaran atau pengembalian dana melebihi nilai transaksi resmi.

Namun demikian, audit menemukan **kegagalan orkestrasi runtime kritis** yang memutus aliran data akuntansi:
1. **Disconnected AP & AR Subledger Outbox Consumers (P1):** Service pemroses antrean outbox hutang dan piutang (`ApOutboxConsumerService` dan `ArOutboxConsumerService`) sama sekali tidak pernah dipanggil di lingkungan produksi (0 callers di luar test suite). Akibatnya, faktur pemasok yang disetujui di Modul Purchasing tidak pernah diposting ke Buku Besar Pembantu Hutang (AP) atau Jurnal GL!
2. **Ketiadaan Fallback Scheduler untuk Financial Outbox (P1):** Perintah `finance:process-outbox` (`ProcessFinancialOutboxCommand`) tidak didaftarkan pada scheduler `routes/console.php`. Jika job antrean queue gagal atau worker tertunda, peristiwa finansial POS menumpuk di `financial_event_outboxes`.
3. **Patahnya Otorisasi Policy Akuntansi (P1):** 10 policy finansial memanggil `$user->hasPermissionTo(...)` dan `$user->hasRole(...)` yang tidak ada pada model `User`, memicu `BadMethodCallException` seketika saat otorisasi dipanggil.

**Domain 5 Verdict:** **`NEEDS REMEDIATION`**

---

## 2. TRANSACTIONAL FINANCE & POS COMMERCIAL INTEGRITY

### 2.1 Sales, Split Payments, and Cash Movements
- **Split Payment Allocation:** Kasir mendukung pecahan multi-tender secara atomik di dalam satu transaksi database (`DB::transaction`). Transaksi mencatat relasi `payments` dengan referensi metode yang jelas (`cash`, `qris`, `card`, `gift_card`, `store_credit`, `voucher`).
- **Diskon & Service Charge:** Didukung oleh `UnifiedPricingCalculationPipelineService` dengan snapshot harga terperinci (`pricing_pipeline_snapshots` pada `orders`).
- **Rekonsiliasi Shift & Laci Kas:** `ShiftService` melacak `opening_balance`, `cash_sales`, `cash_refunds`, `cash_drops`, dan `closing_balance`. Selisih kas (*overage/shortage*) secara otomatis memicu peristiwa finansial `pos.shift.closed` ke outbox untuk dijurnal ke akun selisih kas (*Cash Over/Short*).

### 2.2 Payment Gateway (Midtrans Integration)
- **Keamanan Webhook:** Diinspeksi pada `MidtransNotificationController`. Menggunakan verifikasi tanda tangan SHA-512 kanonis:
  `hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey)`
  Mencegah pemalsuan status pembayaran oleh pihak ketiga.
- **Idempotensi Callback:** Status order diperbarui hanya jika order belum berstatus final, mencegah pemrosesan berulang callback Midtrans.
- **Bypass CSRF yang Tervalidasi:** Rute `midtrans/notification` dikecualikan secara eksplisit dari verifikasi token CSRF di `bootstrap/app.php` karena merupakan komunikasi server-to-server.

---

## 3. ACCOUNTING ARCHITECTURE & GENERAL LEDGER EVALUATION

### 3.1 Bagan Akun (Chart of Accounts - COA)
- Struktur hirarkis mendukung tipe akun standar: `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE`.
- Atribut `is_postable`: Akun induk (*summary accounts*) ditandai dengan `is_postable = false` dan dilarang secara ketat menerima posting jurnal langsung di `JournalEntryService`.
- Normal Balance: Didukung oleh enum `NormalBalanceEnum` (`DEBIT` vs `CREDIT`) untuk kalkulasi saldo debit/kredit yang konsisten.

### 3.2 Posting Engine & Dynamic Rule Versioning
- `PostingEngineService` memisahkan logika posting dari kode aplikasi menggunakan *Posting Rules* yang dapat dikonfigurasi per tenant (`posting_rules` dan `posting_rule_lines`).
- **Cent Residual Rounding Handling:** Apabila terjadi selisih sen kecil (<= 0.05) akibat alokasi prorata pajak atau diskon pecahan, `PostingEngineService` secara otomatis mengalokasikan selisih desimal ke baris transaksi terbesar untuk menjamin keseimbangan debit-kredit mutlak.

---

## 4. DOMAIN 5 FINDINGS REGISTER

### FINDING-DOM05-01: Disconnected Accounting Payable and Receivable Outbox Consumers in Runtime Environment
- **Domain:** Domain 5 — Financial & Accounting Integrity / Cross-Module Outbox
- **Severity:** **P1** (Critical Financial Integration Disconnect)
- **Confidence:** **HIGH** (Empirically verified: 0 invocations outside test files)
- **File Path:** [`app/Modules/Finance/Services/ApOutboxConsumerService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Services/ApOutboxConsumerService.php#L27) dan [`app/Modules/Finance/Services/ArOutboxConsumerService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Services/ArOutboxConsumerService.php#L32)
- **Evidence:**
  - `grep -rn "ApOutboxConsumerService" app/ routes/` hanya menemukan deklarasi class dan alias backward-compatibility.
  - Pemanggilan metode `processPendingOutbox()` hanya ditemukan pada 4 file test (`ApGlReconciliationTest`, `ApOutboxAndSubledgerTest`, `ArInvoiceAndPaymentPostingTest`, `ArGlReconciliationTest`).
  - Tidak ada Queue Job, tidak ada Artisan Command, dan tidak ada Event Listener yang memanggil service ini di lingkungan produksi.
- **Actual Behavior:** Ketika Purchasing memverifikasi dan menyetujui Faktur Pemasok (Supplier Invoice), baris outbox dibuat di tabel `accounting_payable_outbox` dengan status `pending`. Namun, baris tersebut tidak pernah diproses. Akibatnya, Buku Besar Pembantu Hutang (`ap_ledger_entries`) dan Jurnal Hutang Usaha di General Ledger **tidak pernah tercipta**. Hal yang sama terjadi pada Piutang Usaha (AR) di Modul Sales.
- **Expected Behavior:** Platform ERP wajib memiliki background queue worker atau scheduled cron job yang memproses antrean `accounting_payable_outbox` dan `accounting_receivable_outbox` secara berkala (misal: setiap 1–5 menit) untuk memposting mutasi ke subledger dan General Ledger secara idempoten.
- **Root Cause:** Pembangunan service consumer di Modul 7 yang telah selesai dan lulus uji fitur (*feature tests*), namun tahap akhir integrasi (*orchestration wiring*) ke Laravel Scheduler atau Queue Dispatcher terlewatkan.
- **Business Impact:** Laporan Keuangan Neraca (*Balance Sheet*) dan Buku Besar Pembantu Hutang/Piutang tidak mencerminkan kewajiban hutang kepada pemasok dan piutang pelanggan riil. Laporan keuangan terdistorsi secara material.
- **Recommendation:**
  1. Buat Artisan Command: `finance:process-ap-outbox` dan `finance:process-ar-outbox` (atau gabungkan ke dalam `finance:process-subledgers`).
  2. Daftarkan perintah tersebut ke dalam `routes/console.php` untuk dijalankan setiap menit (`everyMinute()->withoutOverlapping()`).
  3. Alternatif: Dispatch queue job instan saat record outbox dibuat di Modul Purchasing/Sales.

---

### FINDING-DOM05-02: Absence of Console Scheduler Registration for `finance:process-outbox`
- **Domain:** Domain 5 — Financial Posting Reliability
- **Severity:** **P1** (Operational Financial Posting Delay)
- **Confidence:** **HIGH** (Empirically verified in `routes/console.php`)
- **File Path:** [`routes/console.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/routes/console.php)
- **Evidence:** File `routes/console.php` menjadwalkan:
  - `backup:run`
  - `analytics:recompute-restock-forecasts`
  - `orders:expire-stale-cash`
  - `orders:reconcile-midtrans`
  - `emails:process-outbox`
  - `provisioning:prune-nonces`
  - `provisioning:reconcile`
  - `identity:prune-challenges`
  Perintah `finance:process-outbox` (`ProcessFinancialOutboxCommand.php`) **sama sekali tidak terdaftar**.
- **Actual Behavior:** Pemrosesan `financial_event_outboxes` hanya mengandalkan dispatch queue job `ProcessFinancialOutboxJob::dispatch($outbox->id)`. Jika queue worker mati, restart, atau mengalami timeout, outbox finansial yang berstatus `PENDING` atau `FAILED` tidak memiliki mekanisme recovery otomatis melalui cron.
- **Expected Behavior:** Sebagai arsitektur outbox enterprise yang resilient, background scheduler wajib menjalankan sweep berkala untuk memproses outbox yang tertinggal atau gagal sementara (*retry sweep*).
- **Root Cause:** Kelupaan pendaftaran perintah konsol di file routes scheduler Laravel 11/12.
- **Business Impact:** Penundaan posting transaksi kasir ke Buku Besar jika terjadi antrean worker yang padat atau kegagalan worker transient.
- **Recommendation:** Tambahkan jadwal `Schedule::command('finance:process-outbox')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();` pada `routes/console.php`.

---

### FINDING-DOM05-03: Method Call Mismatch in Financial Policies (`hasPermissionTo` Undefined on User Model)
- **Domain:** Domain 5 — Authorization & Financial Governance
- **Severity:** **P1** (Security & Runtime Defect)
- **Confidence:** **HIGH** (Empirically verified via Tinker and code inspection)
- **File Path:** 10 Policy Files di `app/Policies/`:
  - [`JournalEntryPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/JournalEntryPolicy.php#L14)
  - [`ChartOfAccountPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/ChartOfAccountPolicy.php#L14)
  - [`AccountingPeriodPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/AccountingPeriodPolicy.php#L14)
  - [`ApPaymentPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/ApPaymentPolicy.php#L14)
  - [`ExpensePolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/ExpensePolicy.php#L15)
  - [`FixedAssetPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/FixedAssetPolicy.php#L15)
  - [`CashBankAccountPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/CashBankAccountPolicy.php#L14)
  - [`StoreCreditPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/StoreCreditPolicy.php#L23)
  - [`GiftCardPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/GiftCardPolicy.php#L23)
  - [`VoucherPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/VoucherPolicy.php#L23)
- **Evidence:**
  ```php
  // JournalEntryPolicy.php line 14:
  return $user->hasPermissionTo('finance.journal.view') || $user->hasRole('owner');
  // Error saat dieksekusi di runtime:
  BadMethodCallException: Call to undefined method App\Models\User::hasPermissionTo()
  ```
- **Actual Behavior:** Model `App\Models\User` hanya mengimplementasikan `hasPermission(string $perm): bool`. Metode `hasPermissionTo` dan `hasRole` tidak ada (karena package `spatie/laravel-permission` tidak digunakan). Setiap pemanggilan `$this->authorize()` pada model-model finansial tersebut melempar error fatal 500.
- **Expected Behavior:** Seluruh policy finansial memanggil metode otorisasi yang sah pada model `User`: `$user->hasPermission(...)` dan memeriksa role via `$user->role?->name === '...'`.
- **Root Cause:** Penulisan policy mengasumsikan interface library Spatie tanpa memverifikasi implementasi aktual RBAC kustom di Apeiron.
- **Business Impact:** Otorisasi finansial lumpuh. Begitu Livewire atau Controller memasang `$this->authorize()`, seluruh fitur akuntansi akan mengalami crash 500 bagi seluruh pengguna.
- **Recommendation:** Standardisasi seluruh 10 policy finansial untuk menggunakan `$user->hasPermission($perm)` dan `$user->role?->name === 'Owner'`.

---

## 5. CAPABILITY GAP MATRIX (FINANCIAL ACCOUNTING)

| Financial Capability | Status Aktual Apeiron | Benchmark Enterprise (SAP FI-CO / NetSuite GL) | Gap Analysis & Kebutuhan F&B/Retail |
|---|---|---|---|
| **Double-Entry General Ledger** | **Implemented & Verified** | Perpetual Double-Entry with Subledgers | Sangat kokoh; debit=credit invariant dijamin secara matematis. |
| **Buku Besar Pembantu (AP/AR Subledgers)** | **Partially Ready** | Fully Integrated AP/AR Subledgers with Aging & GL Reconciliation | Model dan skema siap, namun worker consumer outbox belum di-wiring di scheduler. |
| **Bagan Akun (Chart of Accounts)** | **Implemented & Verified** | Multi-Tier Account Tree with Postable Flags | Hirarki COA lengkap dengan proteksi akun ringkasan. |
| **Penutupan Periode Akuntansi** | **Implemented & Verified** | Period Locking (Soft Close, Hard Close, Year-End Closing Entry) | `AccountingPeriodService` memvalidasi status OPEN/SOFT_CLOSED pada setiap posting. |
| **Penyusutan Aset Tetap (Depreciation)** | **Implemented & Verified** | Straight-line monthly depreciation with auto journal | Perintah `RunMonthlyDepreciationCommand` tersedia dan teruji. |
| **Theoretical vs Actual Food Costing** | **Partially Ready** | Perpetual FIFO / Weighted Avg Cost Layers with Shrinkage Variance | Menghitung theoretical cost via BOM, namun perbandingan actual variance masih dalam reporting statis. |
| **Multi-Currency Accounting** | **Not Implemented** | Multi-Currency Realized/Unrealized Gain-Loss | Sistem saat ini dirancang untuk mata uang tunggal (IDR). |

---

## 6. VERDICT & CONDITIONS

Domain 5 dinyatakan **NEEDS REMEDIATION**. Fondasi akuntansi ganda (*double-entry*) dan imutabilitas database sangat luar biasa, namun pengerjaan *wiring* consumer outbox AP/AR ke scheduler dan perbaikan sintaks otorisasi policy finansial adalah syarat mutlak yang harus diselesaikan sebelum sistem dapat beroperasi di produksi.
