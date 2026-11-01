# ENTERPRISE MASTER FINDINGS REGISTER
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform (Domains 2–10)

- **Auditor:** A0 — Principal Enterprise Architect & Master Systems Auditor
- **Audit Target:** Consolidated, Deduplicated, and Evidence-Backed Register of All Identified Findings across Domains 2–10
- **Classification Standard:** P0 (Blocker), P1 (Critical Business/Security/Data Integrity), P2 (Material Architecture/Scale/UX), P3 (Minor/Tech Debt)
- **Audit Date:** 1 November 2026
- **Status:** **FINAL CONSOLIDATED REGISTER**

---

## 1. SUMMARY MATRIX BY SEVERITY & DOMAIN

| Domain Code & Title | P0 (Blocker) | P1 (Critical) | P2 (Material) | P3 (Minor) | Total Findings |
|---|:---:|:---:|:---:|:---:|:---:|
| **Domain 02: Enterprise Backend & DDD** | 0 | 1 | 2 | 1 | **4** |
| **Domain 03: Frontend & Livewire Experience** | 0 | 2 | 1 | 0 | **3** |
| **Domain 04: Enterprise UX & Industry Workflows** | 0 | 0 | 2 | 0 | **2** |
| **Domain 05: Financial & Accounting Integrity** | 0 | 3 | 0 | 0 | **3** |
| **Domain 06: F&B & Retail Inventory / P2P** | 0 | 1 | 2 | 0 | **3** |
| **Domain 07: Security, RBAC & SaaS Governance** | 0 | 0 | 1 | 0 | **1** |
| **Domain 08: Concurrency & Distributed Processing** | 0 | 1 | 1 | 0 | **2** |
| **Domain 09: Performance, Scalability & Telemetry** | 0 | 0 | 2 | 0 | **2** |
| **Domain 10: DevOps, Infrastructure & Integrations** | 0 | 0 | 2 | 0 | **2** |
| **TOTAL CONSOLIDATED FINDINGS** | **0** | **8** | **13** | **1** | **22** |

---

## 2. P1 — CRITICAL FINDINGS (DATA INTEGRITY, SECURITY, CONCURRENCY)

---

### FINDING-P1-01: Disconnected Accounting Payable and Receivable Outbox Consumers in Production Runtime
- **Finding ID:** `FINDING-P1-01`
- **Domain:** Domain 05 — Financial, Accounting & Commercial Integrity
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`app/Modules/Finance/Services/ApOutboxConsumerService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Services/ApOutboxConsumerService.php#L27) dan [`app/Modules/Finance/Services/ArOutboxConsumerService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Services/ArOutboxConsumerService.php#L32)
- **Line Number:** `ApOutboxConsumerService.php:27`, `ArOutboxConsumerService.php:32`
- **Evidence:**
  ```bash
  $ grep -rn "processPendingOutbox" app/
  app/Modules/Finance/Services/ApOutboxConsumerService.php:27: public function processPendingOutbox(?int $tenantId = null, int $limit = 50): int
  app/Modules/Finance/Services/ArOutboxConsumerService.php:32: public function processPendingOutbox(?int $tenantId = null, int $limit = 50): int
  # 0 callers in app/, 0 commands in routes/console.php, 0 queue workers!
  ```
- **Actual Behavior:** Faktur Pemasok yang disetujui di Modul Purchasing disimpan ke tabel `accounting_payable_outbox` dengan status `pending`, namun tidak pernah diproses. Subledger Hutang (`ap_ledger_entries`) dan Jurnal Hutang Usaha General Ledger tidak pernah tercipta di lingkungan produksi.
- **Expected Behavior:** Antrean outbox hutang dan piutang harus diproses secara otomatis oleh background daemon atau scheduled command untuk memposting mutasi keuangan secara berkala.
- **Root Cause:** Implementasi consumer selesai dan lulus tes unit, namun tahap registrasi scheduler ke Laravel framework terlewatkan.
- **Business Impact:** Neraca Keuangan (*Balance Sheet*) dan Buku Besar Pembantu Hutang/Piutang tidak mencerminkan kewajiban hutang riil kepada vendor. Laporan keuangan terdistorsi secara material.
- **Technical Impact:** Penumpukan tak terbatas data outbox pending di database.
- **Triggering Condition:** Setiap persetujuan faktur pemasok (3-Way Matching GRN) atau penerbitan faktur piutang B2B.
- **Cross-Domain Dependency:** Modul 5 (Purchasing) -> Modul 7 (Finance) dan Modul 6 (Sales B2B) -> Modul 7 (Finance).
- **Recommendation:** Buat Artisan command `finance:process-subledgers` dan jadwalkan di `routes/console.php` setiap menit (`everyMinute()->withoutOverlapping()`).
- **Acceptance Criteria:** Eksekusi command memproses seluruh record status `pending` menjadi `processed`, mengisi `ap_ledger_entries` dan memicu `JournalEntryService::createAndPostJournal`.
- **Required Regression Test:** `tests/Feature/Finance/ApOutboxScheduledProcessingTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-02: Absence of Console Scheduler Registration for `finance:process-outbox`
- **Finding ID:** `FINDING-P1-02`
- **Domain:** Domain 05 / Domain 10 — Financial Operations & DevOps
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`routes/console.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/routes/console.php)
- **Line Number:** `routes/console.php:1-75`
- **Evidence:** Perintah `ProcessFinancialOutboxCommand.php` (`finance:process-outbox`) tidak terdaftar dalam definisi `Schedule::command(...)`.
- **Actual Behavior:** Jika queue worker mati atau mengalami restart, peristiwa finansial POS yang gagal di-dispatch secara instan akan tertahan selamanya di tabel `financial_event_outboxes` tanpa mekanisme retry sweep otomatis.
- **Expected Behavior:** Scheduler wajib memiliki job periodik untuk memindai outbox finansial yang pending atau gagal sementara.
- **Root Cause:** Kelupaan registrasi command di scheduler Laravel 11/12.
- **Business Impact:** Penundaan pembukuan pendapatan harian kasir ke Buku Besar Umum.
- **Technical Impact:** Ketiadaan fallback recovery untuk event bus asinkron.
- **Triggering Condition:** Kegagalan transient pada queue worker atau server restart.
- **Cross-Domain Dependency:** Modul 2 (POS) -> Modul 7 (Finance).
- **Recommendation:** Tambahkan `Schedule::command('finance:process-outbox')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();` pada `routes/console.php`.
- **Acceptance Criteria:** Scheduler menjalankan sweep setiap 5 menit dan memproses record pending.
- **Required Regression Test:** `tests/Feature/Console/FinancialOutboxSchedulerTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-03: Runtime Exception in 10 Financial Policies via Undefined User Model Methods
- **Finding ID:** `FINDING-P1-03`
- **Domain:** Domain 05 / Domain 07 — Security & Authorization Architecture
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`app/Policies/JournalEntryPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/JournalEntryPolicy.php#L14) (dan 9 file policy lainnya)
- **Line Number:** `JournalEntryPolicy.php:14, 23, 28, 42, 51, 60`
- **Evidence:**
  ```php
  // JournalEntryPolicy.php:
  return $user->hasPermissionTo('finance.journal.view') || $user->hasRole('owner');
  // Tinker Proof:
  BadMethodCallException: Call to undefined method App\Models\User::hasPermissionTo()
  ```
- **Actual Behavior:** 10 policy finansial memanggil method `hasPermissionTo` dan `hasRole` yang tidak ada pada model `User`. Setiap eksekusi `$this->authorize(...)` pada model terkait memicu fatal error 500.
- **Expected Behavior:** Policy memanggil method yang sah: `$user->hasPermission(...)` dan `$user->role?->name === 'Owner'`.
- **Root Cause:** Asumsi library Spatie Permission tanpa verifikasi model RBAC kustom Apeiron.
- **Business Impact:** Kelumpuhan otorisasi modul finansial.
- **Technical Impact:** Runtime crash pada setiap gate authorization check.
- **Triggering Condition:** Pemanggilan `$this->authorize(...)` pada entitas finansial.
- **Cross-Domain Dependency:** Modul 7 (Finance) <-> Modul 1 (User/Auth).
- **Recommendation:** Refactor 10 file policy untuk menggunakan method `$user->hasPermission(...)`.
- **Acceptance Criteria:** Seluruh unit test policy berjalan hijau tanpa exception.
- **Required Regression Test:** `tests/Unit/Policies/FinancePoliciesMethodExecutionTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-04: Systematic Omission of Component-Level Authorization Across 23 Core Livewire Managers
- **Finding ID:** `FINDING-P1-04`
- **Domain:** Domain 03 / Domain 07 — Livewire Security & Access Control
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** 23 file di `app/Livewire/Finance/`, `app/Livewire/HR/`, dan `app/Livewire/Inventory/`
- **Line Number:** Berbagai file (`JournalEntryManager.php:190`, `PayrollBatchManager.php:75`, dll.)
- **Evidence:** 23 file komponen Livewire manajerial sama sekali tidak memiliki pemanggilan `$this->authorize(...)`.
- **Actual Behavior:** Perlindungan akses hanya ada di level URL route (`routes/web.php`). Di tingkat aksi komponen, pengguna yang berhasil mengakses route dapat mengeksekusi mutasi apa pun tanpa otorisasi izin spesifik.
- **Expected Behavior:** Setiap aksi mutasi data (posting jurnal, approve payroll, stock adjustment) wajib memvalidasi izin pengguna via `$this->authorize(...)`.
- **Root Cause:** Pengabaian prinsip Defense-in-Depth pada siklus hidup komponen Livewire.
- **Business Impact:** Bypass hak akses manajerial dan pelanggaran Separation of Duties (SoD).
- **Technical Impact:** Tidak adanya otorisasi lapis kedua di level komponen.
- **Triggering Condition:** Pengguna memanipulasi method invocation Livewire dari console browser.
- **Cross-Domain Dependency:** Seluruh Modul Manajerial (M4, M7, M9) <-> Modul 1 (RBAC).
- **Recommendation:** Tambahkan `$this->authorize(...)` pada seluruh method mutasi di 23 komponen Livewire.
- **Acceptance Criteria:** Request dari user tanpa izin ditolak dengan HTTP 403 Forbidden.
- **Required Regression Test:** `tests/Feature/Security/LivewireComponentsAuthorizationTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-05: Sensitive Supervisor and Gift Card PINs Exposed in Public Livewire State
- **Finding ID:** `FINDING-P1-05`
- **Domain:** Domain 03 / Domain 07 — Data Privacy & Frontend Security
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`app/Livewire/Kasir/CreateOrder.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Kasir/CreateOrder.php#L143-L156)
- **Line Number:** `CreateOrder.php:143, 145, 156`
- **Evidence:**
  ```php
  public string $manualDiscountSupervisorPin = '';
  public ?string $supervisorPinInput = null;
  public string $giftCardPinInput = '';
  ```
- **Actual Behavior:** PIN supervisor dan PIN gift card tersimpan di properti publik yang diserialisasi ke dalam snapshot JSON di DOM browser kasir.
- **Expected Behavior:** Kredensial sensitif tidak boleh tersimpan dalam state publik komponen yang persisten.
- **Root Cause:** Two-way data binding tanpa pemisahan kredensial transien.
- **Business Impact:** Kasir dapat mencuri PIN supervisor melalui console browser untuk memberikan diskon ilegal.
- **Technical Impact:** Kebocoran kredensial otorisasi di sisi klien.
- **Triggering Condition:** Penggunaan diskon manual supervisor atau pembayaran gift card di terminal kasir.
- **Cross-Domain Dependency:** Modul 2 (POS) <-> Modul 1 (User/PIN).
- **Recommendation:** Teruskan PIN sebagai parameter metode aksi dan jangan simpan di properti publik.
- **Acceptance Criteria:** PIN tidak muncul dalam payload JSON snapshot Livewire di browser DevTools.
- **Required Regression Test:** `tests/Feature/Security/CreateOrderPublicStateLeakTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-06: Double-Submit Vulnerability Causing Duplicate Manual Journal Postings in `JournalEntryManager`
- **Finding ID:** `FINDING-P1-06`
- **Domain:** Domain 03 / Domain 05 — Frontend UX & Financial Accounting Integrity
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`app/Livewire/Finance/JournalEntryManager.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Finance/JournalEntryManager.php#L211) dan [`resources/views/livewire/finance/journal-entry-manager.blade.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/views/livewire/finance/journal-entry-manager.blade.php#L290)
- **Line Number:** `JournalEntryManager.php:211`, `journal-entry-manager.blade.php:290`
- **Evidence:** Tombol submit tidak memiliki `wire:loading.attr="disabled"`, dan kunci idempotensi dibuat ulang di dalam handler: `'idempotencyKey' => 'manual_journal:'.$tenantId.':'.uniqid('', true)`.
- **Actual Behavior:** Double-click pada tombol submit menghasilkan 2 kunci unik yang berbeda, memposting 2 jurnal identik ke General Ledger.
- **Expected Behavior:** Tombol submit dinonaktifkan saat request berjalan, dan kunci idempotensi dikunci saat modal dibuka.
- **Root Cause:** Penggunaan `uniqid()` di dalam submit handler yang merusak proteksi idempotensi.
- **Business Impact:** Pembukuan jurnal ganda pada General Ledger, merusak neraca saldo keuangan.
- **Technical Impact:** Pelanggaran integritas posting jurnal akuntansi.
- **Triggering Condition:** Double-click user pada tombol submit di bawah latensi jaringan.
- **Cross-Domain Dependency:** Modul 3 (UI) <-> Modul 7 (Finance).
- **Recommendation:** Tambahkan `wire:loading.attr="disabled"` dan kunci idempotency key di state modal.
- **Acceptance Criteria:** Double-click hanya menghasilkan 1 baris jurnal di tabel `journal_entries`.
- **Required Regression Test:** `tests/Feature/Finance/JournalEntryDoubleSubmitTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-07: POS TransactionService Bypasses Domain Inventory Service and Mutates Legacy Columns Directly
- **Finding ID:** `FINDING-P1-07`
- **Domain:** Domain 02 / Domain 06 — Architecture & Inventory Integrity
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`app/Modules/Sales/Services/TransactionService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/TransactionService.php#L360-L786)
- **Line Number:** `TransactionService.php:360-365, 580, 786`
- **Evidence:** Pemotongan stok dilakukan langsung via `$product->stock -= ...`, `$ingredient->current_stock = bcsub(...)`, dan raw insert `DB::table('stock_movements')->insert(...)` tanpa mendelegasikan ke `InventoryLedgerService`.
- **Actual Behavior:** Kasir membuat mutasi stok sendiri yang memotong kolom mirror legacy secara langsung.
- **Expected Behavior:** Seluruh mutasi persediaan fisik wajib didelegasikan ke `InventoryLedgerService::recordMovement()`.
- **Root Cause:** Migrasi parsial yang belum menuntaskan refactor pada modul kasir.
- **Business Impact:** Inkonsistensi data persediaan antara saldo terhitung cabang dengan kolom legacy.
- **Technical Impact:** Bypassing invariant audit trail `INV-M4-01` s.d. `INV-M4-08`.
- **Triggering Condition:** Setiap transaksi penjualan di terminal kasir.
- **Cross-Domain Dependency:** Modul 2 (Sales) <-> Modul 4 (Inventory).
- **Recommendation:** Refactor `TransactionService` untuk memanggil `InventoryLedgerService::recordMovement()`.
- **Acceptance Criteria:** Seluruh mutasi stok kasir tercatat resmi melalui `InventoryLedgerService`.
- **Required Regression Test:** `tests/Feature/Sales/PosInventoryLedgerDelegationTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

### FINDING-P1-08: Premature Lock Release in `ProcessFinancialOutboxJob`
- **Finding ID:** `FINDING-P1-08`
- **Domain:** Domain 08 — Concurrency & Distributed Processing
- **Severity:** **P1** | **Confidence:** **HIGH**
- **Exact File Path:** [`app/Modules/Finance/Jobs/ProcessFinancialOutboxJob.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Jobs/ProcessFinancialOutboxJob.php#L29-L42)
- **Line Number:** `ProcessFinancialOutboxJob.php:29-35`
- **Evidence:**
  ```php
  $outbox = DB::transaction(function () {
      return FinancialEventOutbox::withoutTenantScope()->where('id', $this->outboxId)->lockForUpdate()->first();
  }); // <-- Lock dilepas di sini!
  // Mutasi status dan posting dilakukan di luar transaksi lock!
  ```
- **Actual Behavior:** Row lock dilepas seketika saat closure selesai. Worker lain dapat membaca status pending sebelum status diubah ke `PROCESSING`.
- **Expected Behavior:** Seleksi outbox, perubahan status ke `PROCESSING`, posting jurnal, dan update ke `PROCESSED` harus berada di dalam 1 blok `DB::transaction` yang sama.
- **Root Cause:** Pemotongan batas transaksi database yang terlalu dini.
- **Business Impact:** Deadlock dan duplikasi pemrosesan job outbox saat antrean padat.
- **Technical Impact:** Kegagalan proteksi konkurensi antar-worker.
- **Triggering Condition:** Dua worker queue memproses job outbox yang sama secara paralel.
- **Cross-Domain Dependency:** Modul 7 (Finance) <-> Infrastructure Queue.
- **Recommendation:** Satukan seluruh alur outbox job di dalam satu transaksi database tunggal.
- **Acceptance Criteria:** Tidak ada race condition antar worker pada outbox yang sama.
- **Required Regression Test:** `tests/Feature/Finance/FinancialOutboxJobConcurrencyTest.php`.
- **Verification Status:** **CONFIRMED DEFECT**.

---

## 3. P2 & P3 — MATERIAL ARCHITECTURAL, UX & DEVOPS FINDINGS

| Finding ID | Domain | Severity | Title & Short Description | Impacted File & Line |
|---|:---:|:---:|---|---|
| **FINDING-P2-01** | Domain 02 | **P2** | Complete Absence of Module Service Providers and Dependency Inversion Contracts | `app/Providers/AppServiceProvider.php:26` |
| **FINDING-P2-02** | Domain 02 | **P2** | Ghost Backward-Compatibility Alias Layer in Root `app/Services/` (48 files) | `app/Services/*.php` |
| **FINDING-P2-03** | Domain 03 | **P2** | Massive God Component in POS Terminal (`CreateOrder.php` - 1,394 LOC, 35+ Public Props) | `app/Livewire/Kasir/CreateOrder.php` |
| **FINDING-P2-04** | Domain 04 | **P2** | Zero Offline Resilience for High-Volume POS Retail & Restaurant Operations | `app/Livewire/Kasir/CreateOrder.php` |
| **FINDING-P2-05** | Domain 04 | **P2** | Absence of Multi-Station Kitchen Routing in KDS (Bar vs Hot Kitchen vs Dessert) | `app/Livewire/Kds/Board.php:30` |
| **FINDING-P2-06** | Domain 06 | **P2** | Race Condition in Stock Transfer Number Generation (Non-Atomic Sequence Allocation) | `app/Modules/Inventory/Services/StockTransferService.php:348` |
| **FINDING-P2-07** | Domain 06 | **P2** | Absence of Recipe Versioning and Production Batch Preparation (Central Kitchen Gap) | Skema `product_ingredients` & `ingredients` |
| **FINDING-P2-08** | Domain 08 | **P2** | Missing `SKIP LOCKED` in Batch Outbox Processing (`finance:process-outbox`) | `app/Console/Commands/ProcessFinancialOutboxCommand.php:34` |
| **FINDING-P2-09** | Domain 09 | **P2** | Absence of Request Correlation IDs and Structured JSON Logging across Application Pipeline | `config/logging.php:35` |
| **FINDING-P2-10** | Domain 09 | **P2** | Absence of Per-Tenant Workload Throttling (Noisy-Neighbor Exposure) | `app/Providers/AppServiceProvider.php:112` |
| **FINDING-P2-11** | Domain 10 | **P2** | CI Database Container Mismatch with Production Engine (MySQL 8.0 vs MariaDB 10.11) | `.github/workflows/tests.yml:62` |
| **FINDING-P2-12** | Domain 10 | **P2** | Absence of Public Partner REST API & API-First Integration Layer | `routes/api.php:1-25` |
| **FINDING-P2-13** | Domain 07 | **P2** | Keterbatasan Hierarki Organisasi Multi-Company Holding (Terbatas 2 Tingkat Tenant->Store) | Skema `tenants` & `stores` |
| **FINDING-P3-01** | Domain 01 | **P3** | Auto-Increment Gap pada Tabel Tenants (Lonjakan nilai ke 7 akibat tenant uji transient) | Database table `tenants` |

---

## 4. VERIFIKASI DEDUP & KONSISTENSI TEMUAN
Seluruh 22 temuan di atas telah diverifikasi secara silang (*cross-verified*) terhadap kode sumber riil dan log commit terbaru. Tidak ada temuan yang saling bertentangan (*no conflicting findings*).
