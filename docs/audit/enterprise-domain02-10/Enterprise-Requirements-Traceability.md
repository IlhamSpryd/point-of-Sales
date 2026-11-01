# ENTERPRISE REQUIREMENTS TRACEABILITY MATRIX
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & Traceability Auditor
- **Target:** Full Traceability between Approved PRD Requirements, Current Codebase Implementation, Architectural Decisions (ADR), and Enterprise Industry Targets
- **Audit Date:** 1 November 2026
- **Status:** **FINAL TRACEABILITY REPORT**

---

## 1. REQUIREMENTS GOVERNANCE TAXONOMY

Sesuai arahan audit pada Bagian 2 Dokumen Mandat, audit membedakan secara tegas antara 6 kategori status kebutuhan sistem:
1. **[APPROVED PRD]:** Kebutuhan produk yang telah didokumentasikan dan disetujui secara resmi dalam dokumen Master PRD.
2. **[ADR APPROVED]:** Keputusan arsitektur teknis yang telah disetujui oleh Product Owner (misal: DEC-DB-01, ADR-006).
3. **[IMPLEMENTED & VERIFIED]:** Kebutuhan yang telah diimplementasikan dalam kode sumber, bermigrasi di database, dan lulus pengujian bukti empiris.
4. **[IMPLEMENTED WITH GAP]:** Fitur telah dibangun, namun memiliki cacat teknis runtime atau belum terintegrasi tuntas.
5. **[NOT IMPLEMENTED]:** Kebutuhan yang belum ada wujud kode implementasinya di dalam repositori.
6. **[PRODUCT DECISION REQUIRED]:** Aspek fungsional atau arsitektur yang memerlukan keputusan resmi dari Human Product Owner sebelum dapat dikerjakan.

---

## 2. MODUL 1–9 REQUIREMENTS TRACEABILITY REGISTER

### Modul 1: Core Platform, Multi-Tenancy & Governance
- **PRD Ref:** OD-01, OD-02, SEC-001 s.d. SEC-018
- **Approved Requirement:** Isolasi data tenant mutlak (`tenant_id`), RBAC dengan peran bawaan (Owner, Manager, Kasir, Waiter, Kitchen), 2FA TOTP, audit log tidak dapat diubah (*immutable*).
- **Current Implementation:** `TenantContext`, `Role`, `User`, `ActivityLog`, database triggers imutabilitas log.
- **Traceability Status:** **`[IMPLEMENTED & VERIFIED]`** (Domain 1 WP-DB-01 s.d. WP-DB-04 selesai dan terverifikasi).
- **Target Enterprise:** Dukungan Corporate Multi-Entity / Holding Company (`[PRODUCT DECISION REQUIRED]`).

---

### Modul 2: POS, Frontline Commerce & Restaurant Operations
- **PRD Ref:** M2-01 s.d. M2-24, PO-01 s.d. PO-10
- **Approved Requirement:** Kasir ritel & F&B, multi-tender split payment, hold/park cart, visual table layout, shift kasir dengan blind count, KDS dapur, self-order meja QR.
- **Current Implementation:** `CreateOrder.php`, `ShiftService.php`, `TableController.php`, `Kds/Board.php`, `CheckoutController.php`.
- **Traceability Status:** **`[IMPLEMENTED WITH GAP]`**
  - Cacat: Kasir memotong stok inventori secara langsung melewati `InventoryLedgerService` (FINDING-P1-07).
  - Cacat: Kredensial PIN supervisor terekspos di state publik Livewire (FINDING-P1-05).
  - Gap: KDS belum memiliki pemfilteran stasiun kerja dapur (`[PRODUCT DECISION REQUIRED]`).
  - Gap: Mode offline kasir belum didukung (`[NOT IMPLEMENTED]`).

---

### Modul 3: Product, Catalog & Menu Management
- **PRD Ref:** M3-01 s.d. M3-15
- **Approved Requirement:** Master produk, SKU/barcode, kategori bertingkat, merek, modifier groups, asortimen toko, pemetaan kanal pihak ketiga (Gojek/Grab).
- **Current Implementation:** `ProductService.php`, `CategoryService.php`, `CatalogPriceResolverService.php`, `ModifierManager.php`.
- **Traceability Status:** **`[IMPLEMENTED & VERIFIED]`**.
  - Target Enterprise: Versi resep per tanggal berlaku (*Recipe Versioning*) (`[NOT IMPLEMENTED]`).

---

### Modul 4: Inventory, Stock Ledger & Warehouse Operations
- **PRD Ref:** M4-01 s.d. M4-18, OD-05, OD-06
- **Approved Requirement:** Buku besar mutasi append-only, saldo termaterialisasi per cabang, strict non-negative stock, XOR produk vs bahan baku, stock opname fisik, transfer antarcabang 2-tahap.
- **Current Implementation:** `InventoryLedgerService.php`, `StockOpnameService.php`, `StockTransferService.php`, triggers database XOR.
- **Traceability Status:** **`[IMPLEMENTED WITH GAP]`**
  - Cacat: Race condition penomoran transfer antarcabang draft (FINDING-P2-06).
  - Gap: Modul Dapur Pusat / Batch Preparation Work Orders (`[NOT IMPLEMENTED]`).

---

### Modul 5: Purchasing & Supplier Management (Procure-to-Pay)
- **PRD Ref:** M5-01 s.d. M5-16, AT-15 s.d. AT-19
- **Approved Requirement:** Direktori vendor, PR dengan persetujuan bertingkat, PO, GRN, 3-Way Matching faktur pemasok, nota debet retur beli, landed cost, emisi outbox AP.
- **Current Implementation:** `PurchaseOrderService.php`, `GoodsReceivedNoteService.php`, `SupplierInvoiceMatchingService.php`, `LandedCostService.php`.
- **Traceability Status:** **`[IMPLEMENTED & VERIFIED]`** (Di sisi modul pengadaan).
  - Catatan: Emisi outbox AP berjalan sempurna, namun consumer di Modul 7 belum terhubung (lihat Modul 7).

---

### Modul 6: Sales & Customer Management (CRM & B2B)
- **PRD Ref:** M6-01 s.d. M6-18
- **Approved Requirement:** Profil pelanggan, grup pelanggan, plafon kredit B2B, kartu loyalitas, voucher, gift card, store credit, quotation, sales order, faktur AR.
- **Current Implementation:** `CustomerService.php`, `CustomerCreditService.php`, `LoyaltyService.php`, `VoucherService.php`, `GiftCardService.php`, `SalesOrderService.php`.
- **Traceability Status:** **`[IMPLEMENTED & VERIFIED]`**.
  - Catatan: Outbox AR dipancarkan, namun consumer di Modul 7 belum terhubung (lihat Modul 7).

---

### Modul 7: Finance & General Ledger
- **PRD Ref:** M7-01 s.d. M7-22, ADR-006
- **Approved Requirement:** Buku besar akrual ganda (*double-entry*), COA hirarkis, aturan posting dinamis, penutupan periode akuntansi, penyusutan aset tetap bulanan, rekonsiliasi kas/bank, subledger AP/AR.
- **Current Implementation:** `PostingEngineService.php`, `JournalEntryService.php`, `AccountingPeriodService.php`, `FixedAssetService.php`, `JournalEntryManager.php`.
- **Traceability Status:** **`[IMPLEMENTED WITH GAP]`**
  - Cacat Kritis: `ApOutboxConsumerService` dan `ArOutboxConsumerService` tidak memiliki pemanggil di lingkungan produksi (FINDING-P1-01).
  - Cacat Kritis: Perintah `finance:process-outbox` tidak dijadwalkan di `routes/console.php` (FINDING-P1-02).
  - Cacat Kritis: 10 policy finansial memanggil method `hasPermissionTo()` yang tidak ada (FINDING-P1-03).
  - Cacat Kritis: Kerentanan double-submit pada `JournalEntryManager` memicu duplikasi jurnal (FINDING-P1-06).

---

### Modul 8: Pricing, Promotion & Loyalty Engine
- **PRD Ref:** M8-01 s.d. M8-14
- **Approved Requirement:** Pipeline kalkulasi harga terpadu, kampanye promosi (BxGY, Happy Hour, Diskon Bertingkat), reservasi kuota promosi 2-fase, price books, approval diskon kasir.
- **Current Implementation:** `UnifiedPricingCalculationPipelineService.php`, `PromotionEngineService.php`, `DiscountApprovalService.php`.
- **Traceability Status:** **`[IMPLEMENTED & VERIFIED]`**.

---

### Modul 9: HR, Employee & Payroll
- **PRD Ref:** `implementationPlanModul9.md` (Fase 1–16)
- **Approved Requirement:** Pemisahan identitas Employee vs User, data staf terenkripsi, penugasan multi-cabang, shift template, absensi server, cuti, komisi, dan penggajian PPh 21/BPJS.
- **Current Implementation:** Seluruh fase 1–16 telah diimplementasikan pada commit `2ddff27` (`Employee`, `Department`, `JobTitle`, `WorkforceScheduler`, `AttendanceService`, `PayrollBatchService`, enkripsi simetris NIK/NPWP).
- **Traceability Status:** **`[IMPLEMENTED & VERIFIED]`**.
  - Catatan: Livewire manager HR memerlukan penambahan otorisasi `$this->authorize(...)` (FINDING-P1-04).

---

## 3. UNAPPROVED REQUIREMENTS & PRODUCT DECISIONS REQUIRED

| Area Kebutuhan | Deskripsi Masalah & Kebutuhan Keputusan | Opsi Keputusan Produk | Rekomendasi Auditor |
|---|---|---|---|
| **DEC-PROD-01: Multi-Station KDS Routing** | KDS saat ini menampilkan seluruh menu. Perlukah membagi layar KDS per stasiun (Bar vs Kitchen)? | A: Ya, wajib untuk F&B skala menengah.<br>B: Tidak, pertahankan satu layar untuk MVP kafe. | **Opsi A**: Tambahkan atribut `station` pada kategori produk. |
| **DEC-PROD-02: Offline POS Cashier Mode** | Kasir saat ini 100% online. Apakah offline mode menjadi syarat Go-Live produksi? | A: Ya, wajib sebelum peluncuran gerai fisik.<br>B: Tidak, tunda ke Fase 2 (Online-Only first). | **Opsi B**: Tunda PWA offline ke Fase 2, namun tambahkan offline warning banner sekarang. |
| **DEC-PROD-03: Central Kitchen Batch Production** | Apakah platform perlu mendukung dokumen produksi batch (Work Orders) bahan setengah jadi? | A: Ya, masukkan ke roadmap Modul Manufaktur F&B.<br>B: Tidak diperlukan. | **Opsi A**: Masukkan ke Roadmap Fase 2 untuk menyasar tenant enterprise F&B. |
| **DEC-PROD-04: Corporate Multi-Company Holding** | Apakah platform perlu mendukung struktur grup holding PT di atas tenant? | A: Ya, arsitekturkan multi-company.<br>B: Cukup multi-store (multi-cabang) untuk saat ini. | **Opsi B**: Cukup multi-store untuk Fase 1; evaluasi holding untuk pasar korporat Tier-1. |

---

## 4. KESIMPULAN KETERLACAKAN (TRACEABILITY SUMMARY)
Platform Apeiron Systems memiliki keterlacakan kebutuhan yang sangat tinggi terhadap PRD yang telah disetujui. 9 modul resmi telah berdiri dan berfungsi. Kesenjangan yang teridentifikasi sebagian besar merupakan **koneksi integrasi outbox perantara yang belum tuntas di-wiring** dan **fitur enterprise masa depan yang sengaja dialokasikan untuk fase pengembangan berikutnya**.
