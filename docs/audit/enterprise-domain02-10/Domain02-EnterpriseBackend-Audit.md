# DOMAIN 02 — ENTERPRISE BACKEND & DOMAIN ARCHITECTURE AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & Systems Auditor
- **Audit Target:** Core Backend Architecture, Domain Boundaries, Service Layer, Application/Domain/Infrastructure Decoupling, Event-Driven Architecture, Reliability & Invariants
- **Scope:** All Modules (M1–M9), Shared Kernel, Framework Layer, and Cross-Module Interactions
- **Audit Date:** 1 November 2026
- **Status:** **NEEDS REMEDIATION**
- **Confidence:** **HIGH** (Empirically verified against source code, service container, and database schema)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Apeiron Systems dirancang dengan niat arsitektur yang sangat ambisius: membangun **Modular Monolith** berstandar enterprise untuk industri vertikal F&B dan Retail. Repositori mengelompokkan logika bisnis ke dalam direktori `app/Modules/` (mencakup Auth, Customers, Finance, HR, Inventory, Pricing, Products, Purchasing, Reports, Sales, Settings, Users).

Namun, audit teknis independen menemukan kesenjangan struktural antara narasi arsitektural di dalam dokumentasi/PRD dan implementasi kode aktual:
1. **Module Decoupling & Contract Deficit:** Di seluruh 11 direktori modul dalam `app/Modules/`, hanya ditemukan **2 Service Contracts/Interfaces** (`PostingEngineContract` dan `PromotionRuleEvaluatorInterface`). Seluruh modul lainnya saling memanggil secara langsung (*hard direct coupling*), tanpa abstraksi interface, membatasi kesiapan ekstraksi mikroservis masa depan (*future service extraction readiness*).
2. **Missing Module Encapsulation:** Tidak ada satu pun Service Provider per modul (`*ServiceProvider.php` di dalam `app/Modules/`). Seluruh dependensi, alias, dan bootstrapping ditumpuk di dalam `AppServiceProvider.php` tunggal.
3. **Ghost Alias Layer:** Ditemukan 48 file di dalam `app/Services/` yang hanya berfungsi sebagai kelas wrapper kosong (*backward-compatibility aliases*) yang meng-`extends` layanan di dalam `app/Modules/` untuk menjaga kode legacy agar tidak patah.
4. **Event-Driven Architecture Fallback:** Sistem tidak memiliki infrastruktur Domain Event standar Laravel (`app/Events` hanya memuat 1 file: `WaiterCalled.php`, dan direktori `app/Listeners` sama sekali tidak ada). Komunikasi asinkron antar-modul diselesaikan secara parsial menggunakan tabel Outbox di basis data, namun mekanisme konsumsinya tidak terorkestrasi secara terpadu.
5. **God Services:** Beberapa service utama (seperti `TransactionService.php` dengan 2.050 baris dan `CreateOrder.php` dengan 1.394 baris) menampung logika orkestrasi lintas 5 modul sekaligus (Pricing, POS, Inventory, Loyalty, dan Financial Outbox).

**Domain 2 Verdict:** **`NEEDS REMEDIATION`**

---

## 2. DETAILED ARCHITECTURAL EVALUATION

### 2.1 Domain-Driven Design (DDD) & Bounded Contexts
Sistem telah memetakan Bounded Contexts secara konseptual:
- **Catalog & Product Management:** `App\Modules\Products`
- **Frontline POS & Commerce:** `App\Modules\Sales`
- **Inventory & Fulfillment:** `App\Modules\Inventory`
- **Procurement & Vendor Management:** `App\Modules\Purchasing`
- **Financial Accounting & General Ledger:** `App\Modules\Finance`
- **Human Resources & Workforce:** `App\Modules\HR`
- **Pricing & Promotions:** `App\Modules\Pricing`
- **Customer Relationship & Loyalty:** `App\Modules\Customers`

**Evaluasi Implementasi Bounded Contexts:**
Meskipun batas konseptual telah terdefinisi, batasan runtime (*runtime boundaries*) sering dilanggar:
- **Pelanggaran Batas Modul 2 (Sales) ke Modul 4 (Inventory):** `TransactionService` di Modul Sales melakukan pemotongan stok langsung pada tabel `products` dan `ingredients` serta melakukan raw insert ke `stock_movements`, alih-alih mendelegasikan transaksi mutasi ke `InventoryLedgerService` yang merupakan pemilik sah bounded context inventori.
- **Model Leaks:** Model Eloquent dari suatu domain (misalnya `App\Models\Order`, `App\Models\Product`, `App\Models\Customer`) diakses, dimutasi, dan di-query secara langsung dari domain lain tanpa melewati repositori atau service boundary.

### 2.2 Dependency Direction & Modularity
- **Dependency Inversion Principle (DIP):** Sangat rendah. Service di layer atas (`App\Modules\Sales\Services\TransactionService`) bergantung langsung pada implementasi konkret service layer bawah (`App\Modules\Pricing\Services\UnifiedPricingCalculationPipelineService`, `App\Modules\Customers\Services\LoyaltyService`).
- **Circular Dependency Risk:** Terdapat ketergantungan melingkar potensial antara Modul Sales dan Modul Finance: Sales memancarkan event ke Financial Outbox melalui `FinancialOutboxDispatcherService`, sementara Finance membaca model `Order` dan relasi `Payment` dari Modul Sales secara langsung di dalam worker consumer.

### 2.3 Layered Architecture (Domain, Application, Infrastructure)
Struktur direktori di masing-masing modul umumnya hanya terdiri dari:
```text
app/Modules/{Domain}/
├── Controllers/
├── DataTransferObjects/ (atau DTOs/)
├── Enums/
├── Models/
└── Services/
```
Tidak ada pemisahan formal antara *Domain Layer* (Pure Business Logic, Domain Entities, Value Objects, Domain Exceptions) dan *Application Service Layer* (Use Cases, Orchestration). Service bertindak sekaligus sebagai orkestrator transaksi, validator input, kalkulator matematika finansial, dan pemanggil query database.

---

## 3. ENTERPRISE BACKEND RELIABILITY & INVARIANTS

### 3.1 Idempotency Management
- **Order Creation:** Kolom `idempotency_key` pada tabel `orders` ditegakkan dengan indeks unik `orders_tenant_id_idempotency_key_unique`. `TransactionService::createOrder` memeriksa kunci ini sebelum memulai transaksi.
- **Financial Posting:** `JournalEntryService::createAndPostJournal` memeriksa `idempotency_key` pada `journal_entries`.
- **Payment Processing:** Pembayaran Midtrans memiliki idempotensi berbasis `payment_reference`.
- **Kelemahan Idempotensi:** Pembuatan transfer stok di `StockTransferService::createDraft` memiliki parameter `ship_idempotency_key`, namun nomor dokumen transfer `transfer_number` dihasilkan menggunakan query MAX/latest tanpa lock, memicu potensi kegagalan concurency.

### 3.2 Outbox / Inbox Integration Pattern
Platform menerapkan pola **Transactional Outbox Pattern** untuk 3 alur utama:
1. `financial_event_outboxes`: Menampung peristiwa dari POS Order, Retur Penjualan, dan Penutupan Shift untuk diposting ke General Ledger.
2. `accounting_payable_outbox`: Menampung faktur pemasok yang telah diverifikasi 3-Way Matching untuk dicatat ke AP Subledger dan GL.
3. `accounting_receivable_outbox`: Menampung piutang pelanggan B2B untuk dicatat ke AR Subledger.

**Cacat Kritis Orkestrasi Outbox (P1):**
Sebagaimana dibuktikan dalam audit teknis, service consumer `ApOutboxConsumerService` dan `ArOutboxConsumerService` **tidak memiliki caller di lingkungan produksi** (tidak terdaftar di scheduler `routes/console.php`, tidak dipicu oleh queue job, dan tidak dipanggil oleh controller apa pun). Outbox AP dan AR hanya menumpuk data di database tanpa pernah diproses menjadi entri buku besar.

---

## 4. DOMAIN 2 FINDINGS REGISTER

### FINDING-DOM02-01: Complete Absence of Module Service Providers and Dependency Inversion Contracts
- **Domain:** Domain 2 — Enterprise Backend Architecture
- **Severity:** **P2** (Material Architectural Defect / High Technical Debt)
- **Confidence:** **HIGH** (Empirically verified via file system scan)
- **File Path:** [`app/Providers/AppServiceProvider.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Providers/AppServiceProvider.php#L26-L45) dan seluruh direktori `app/Modules/`
- **Evidence:**
  - Hanya ada 1 Service Provider di seluruh aplikasi (`AppServiceProvider.php`).
  - Tidak ada `*ServiceProvider.php` di dalam `app/Modules/*`.
  - Di antara 11 modul, hanya ada 2 kontrak: `PostingEngineContract.php` dan `PromotionRuleEvaluatorInterface.php`.
- **Actual Behavior:** Modul-modul dipanggil langsung melalui kelas konkret. Seluruh binding service didaftarkan terpusat di `AppServiceProvider`.
- **Expected Behavior:** Setiap modul enterprise ERP memiliki Service Provider mandiri (`PurchasingServiceProvider`, `FinanceServiceProvider`, dll.) yang meregistrasikan interface contracts ke container, menegakkan isolasi domain, dan memungkinkan independensi unit testing.
- **Root Cause:** Implementasi modularitas dilakukan dengan sekadar memindahkan file ke folder `Modules` tanpa membangun arsitektur kontrak interface yang formal.
- **Business Impact:** Menghambat evolusi platform. Modul tidak dapat diuji secara terisolasi atau diekstrak menjadi mikroservis di kemudian hari tanpa penulisan ulang besar-besaran (*massive refactoring*).
- **Recommendation:** Bangun `ModuleServiceProvider` untuk setiap modul inti (M1–M9) dan definisikan service contract interfaces di namespace `App\Modules\{Domain}\Contracts`.

---

### FINDING-DOM02-02: POS TransactionService Bypasses Domain Inventory Service and Mutates Legacy Columns Directly
- **Domain:** Domain 2 — Enterprise Backend Architecture / Cross-Domain Integrity
- **Severity:** **P1** (Major Business Integrity Failure / Architectural Fracture)
- **Confidence:** **HIGH** (Empirically verified via `TransactionService.php` lines 340–365 & lines 570–585)
- **File Path:** [`app/Modules/Sales/Services/TransactionService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/TransactionService.php#L340-L365)
- **Evidence:**
  ```php
  // TransactionService.php lines 360-362:
  if ($product->stock < $item['quantity']) { ... }
  $product->stock -= $item['quantity'];
  // lines 580:
  $ingredient->current_stock = bcsub((string) $ingredient->current_stock, $needed, 4);
  // lines 786:
  DB::table('stock_movements')->insert([...]);
  ```
- **Actual Behavior:** `TransactionService` (Modul Sales) memotong kolom `products.stock` dan `ingredients.current_stock` secara langsung, serta melakukan direct insert ke tabel `stock_movements`, tanpa memanggil `InventoryLedgerService::recordMovement()`.
- **Expected Behavior:** Sesuai prinsip DDD dan aturan Modul 4 (`INV-M4-01` s.d. `INV-M4-08`), setiap mutasi persediaan fisik wajib didelegasikan ke `InventoryLedgerService` agar invariant audit trail, validasi balance, dan single source of truth persediaan terjamin.
- **Root Cause:** Sisa kode legacy yang tidak sepenuhnya dimigrasikan saat Modul 4 (Inventory) dibangun.
- **Business Impact:** Inkonsistensi data persediaan antara pembukuan inventori cabang (`ingredient_stock_balances` / `product_stock_balances`) dengan kolom legacy. Kegagalan pelacakan riwayat stok jika terjadi audit stok fisik.
- **Recommendation:** Refactor `TransactionService` untuk mendelegasikan seluruh pengurangan stok retail dan F&B ke `InventoryLedgerService::recordMovement()`.

---

### FINDING-DOM02-03: Ghost Backward-Compatibility Alias Layer in Root `app/Services`
- **Domain:** Domain 2 — Enterprise Backend Architecture
- **Severity:** **P2** (Maintainability & Clean Architecture Debt)
- **Confidence:** **HIGH** (Empirically verified: 48 alias files in `app/Services/`)
- **File Path:** [`app/Services/`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Services/)
- **Evidence:** Ditemukan 48 file PHP di `app/Services/` yang isinya hanya deklarasi kelas kosong turunan modular, contohnya:
  ```php
  // app/Services/LoyaltyService.php
  class LoyaltyService extends \App\Modules\Customers\Services\LoyaltyService {}
  ```
- **Actual Behavior:** Developer dan komponen aplikasi memanggil class dari namespace campuran (`App\Services` dan `App\Modules\...`).
- **Expected Behavior:** Single source of truth untuk lokasi service class. Tidak ada duplikasi namespace yang membingungkan dependency injection container dan analisis statis IDE/Larastan.
- **Root Cause:** Proses refactoring modularisasi yang tidak menuntaskan migrasi impor namespace di seluruh controller, komponen Livewire, dan test.
- **Business Impact:** Kebingungan developer, risiko import namespace yang salah saat membuat fitur baru, dan mempersulit pemeliharaan jangka panjang.
- **Recommendation:** Lakukan audit pemanggilan global untuk mengganti seluruh import `App\Services\*` ke namespace modular `App\Modules\*\Services\*`, kemudian hapus layer alias di `app/Services/`.

---

## 5. CAPABILITY GAP MATRIX (BENCHMARK: SAP / ODOO / DYNAMICS 365 / NETSUITE)

| Architectural Capability | Status Aktual Apeiron | Benchmark Enterprise (SAP/Odoo/NetSuite) | Gap Analysis & Kebutuhan Vertikal F&B/Retail |
|---|---|---|---|
| **Modular Bounded Contexts** | **Partially Ready** | Strict isolated modules / packages with independent lifecycles | Bounded context terdefinisi di struktur folder, namun bocor pada mutasi inventori langsung. |
| **Service Contracts / DIP** | **Not Implemented** | Full interface contracts per module domain | Hanya 2 contracts di seluruh sistem; modul berkomunikasi via kelas konkret. |
| **Event-Driven Architecture** | **Partially Ready** | Enterprise Event Bus / Kafka / RabbitMQ dengan retry & DLQ | Hanya ada 1 event (`WaiterCalled`). Outbox database tersedia tetapi tidak memiliki orkestrator terpadu. |
| **Transactional Outbox Pattern** | **Implemented but Incomplete** | Reliable Outbox Dispatcher + Consumer daemons with Backoff | Tabel outbox ada di basis data, namun consumer AP dan AR tidak dihubungkan ke runtime scheduler. |
| **Idempotency Architecture** | **Architecturally Ready** | Global idempotency middleware & unique transaction keys | Diterapkan pada orders dan journals, namun transfer antarcabang masih memiliki race condition nomor seri. |
| **Domain Exception Taxonomy** | **Partially Ready** | Structured hierarchy: `DomainException`, `InvariantViolationException` | Masih mencampurkan generic `\RuntimeException`, `ValidationException`, dan `DomainException`. |

---

## 6. REVERSED VERDICT & RECOMMANDATIONS

Domain 2 dinyatakan **NEEDS REMEDIATION**. Fondasi modularisasi telah diletakkan dengan sangat baik melalui struktur `app/Modules/`, namun platform membutuhkan standarisasi interface contracts, integrasi outbox scheduler, dan pembersihan kebocoran logika inventori dari POS sebelum siap dievaluasi sebagai fondasi ERP kelas enterprise.
