# DOMAIN 06 — F&B & RETAIL COMMERCE, CATALOG, INVENTORY, PROCUREMENT & PRODUCTION AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & Supply Chain Systems Auditor
- **Audit Target:** Catalog & SKU Management, Recipe/BOM & Ingredient Consumption, Yield & Production, Inventory Valuation & Stock Ledger, Procure-to-Pay (P2P), 3-Way Matching, and Central Kitchen Readiness
- **Scope:** Modul 3 (Products & Catalog), Modul 4 (Inventory), Modul 5 (Purchasing), and POS Fulfillment
- **Audit Date:** 1 November 2026
- **Status:** **NEEDS REMEDIATION**
- **Confidence:** **HIGH** (Empirically verified via supply chain services, schema invariants, and POS consumption flows)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Apeiron Systems dirancang secara spesifik untuk mengatasi dualitas industri ritel (Retail) dan makanan-minuman (F&B). Platform membedakan secara tegas antara **Produk Jadi/Barang Dagangan (Products)** dan **Bahan Baku Resep (Ingredients)**:
- **Integritas XOR di Tingkat Database Engine:** Didukung oleh trigger MariaDB (`trg_goods_receipt_items_xor_insert`, `trg_stock_adjustment_items_xor_insert`, `trg_stock_transfer_items_xor_insert`, `trg_supplier_return_items_xor_insert`) yang secara absolut mencegah rekaman mutasi memiliki `product_id` dan `ingredient_id` sekaligus.
- **Siklus Pengadaan Barang P2P (Procure-to-Pay) yang Matang:** Modul 5 (Purchasing) mengimplementasikan alur pengadaan enterprise yang sangat lengkap: Permintaan Pembelian (PR) dengan alur persetujuan bertingkat (*Purchase Approval Request*), Purchase Order (PO), Penerimaan Barang Fisik (GRN), verifikasi faktur 3-Way Matching, alokasi biaya tambahan pengiriman (*Landed Cost*), dan Retur Pembelian dengan Nota Debet.
- **Buku Besar Persediaan Append-Only (Modul 4):** `InventoryLedgerService` menegakkan 8 invariant persediaan (`INV-M4-01` s.d. `INV-M4-08`) termasuk larangan stok negatif, imutabilitas buku besar, dan urutan penguncian deterministik.

Namun, audit menemukan beberapa kelemahan arsitektur kritis pada domain supply chain:
1. **Jalur Ganda Mutasi Stok POS (P1):** Komponen kasir (`TransactionService`) memotong stok barang retail dan bahan baku F&B secara manual pada kolom mirror legacy alih-alih mendelegasikan transaksi mutasi ke `InventoryLedgerService`.
2. **Ketiadaan Modul Batch Preparation / Central Kitchen (P2):** Sistem belum memiliki dokumen *Production Order* atau *Batch Preparation* untuk mengonversi bahan baku mentah menjadi bahan setengah jadi (*semi-finished goods / prep items*, misal: membuat kaldu 50 liter atau saus pasta 20 kg).
3. **Ketiadaan Versi Resep (Recipe Versioning Gap - P2):** Resep tersimpan secara flat pada tabel `product_ingredients`. Perubahan gramatur resep langsung menimpa data lama tanpa riwayat versi, mendistorsi perhitungan biaya pokok makanan (*historical theoretical food cost*).
4. **Race Condition Penomoran Transfer Antarcabang (P2):** Penomoran dokumen transfer stok di `StockTransferService` rentan terhadap tabrakan nomor seri saat eksekusi konkuren.

**Domain 6 Verdict:** **`NEEDS REMEDIATION`**

---

## 2. PRODUCT CATALOG, SKU & PRICING ARCHITECTURE

### 2.1 Retail vs F&B Product Modeling
- **Retail Modeling:** Mendukung SKU, barcode unik, varian atribut (`product_attributes` dan `product_variants`), merek (`brands`), kategori hirarkis (`categories`), asortimen toko (`store_product_assortments`), dan paket kombo (`product_bundle_items`).
- **F&B Modeling:** Mendukung modifier groups (pilihan saus, tingkat kepedasan, ukuran porsi, topping ekstra) dengan pemetaan resep bahan baku tambahan pada `modifier_ingredients`.
- **Satuan Ukur (Unit of Measure - UoM):** Master UoM mendukung konversi unit bertingkat melalui `product_unit_conversions` (misal: 1 Box = 12 Pack, 1 Pack = 500 Gram).

### 2.3 Pricing, Price Lists, and Dynamic Promotions
- Didukung oleh `CatalogPriceResolverService` dan `UnifiedPricingCalculationPipelineService` (Modul 8).
- Memungkinkan harga khusus cabang (*store-specific price override*), harga kelompok pelanggan B2B (*customer tier price lists*), dan kampanye promosi bertingkat (Buy X Get Y, Happy Hour, Diskon Persen/Nominal).

---

## 3. F&B RECIPES, PRODUCTION & INVENTORY VALUATION

### 3.1 Bill of Materials (BOM) & Ingredient Consumption
- Resep F&B menghubungkan 1 produk ke banyak bahan baku dengan atribut `quantity_required`.
- Modifier F&B juga memiliki resep sendiri di `modifier_ingredients`, memungkinkan pengurangan stok bahan baku ekstra (misal: "Extra Cheese" memotong stok keju 20 gram).
- **Kelemahan Produksi:** Belum mendukung *Yield Shrinkage* (persentase penyusutan saat memasak, misal daging menyusut 15% setelah dipanggang) dan belum mendukung *Batch Production Orders*.

### 3.2 Inventory Ledger & Balance Tracking
- Menggunakan pendekatan hybrid: Materialized Balances (`product_stock_balances` dan `ingredient_stock_balances`) dipadukan dengan Immutable Ledger (`stock_movements` dan `ingredient_stock_movements`).
- Metode Valuasi: Standard Costing dan Moving Average Costing. Belum mendukung perpetual FIFO layer tracking (cost layers).

---

## 4. DOMAIN 6 FINDINGS REGISTER

### FINDING-DOM06-01: Dual Stock Mutation Paths (POS Bypassing InventoryLedgerService)
- **Domain:** Domain 6 — Inventory & F&B Operations
- **Severity:** **P1** (Material Data Integrity Discrepancy)
- **Confidence:** **HIGH** (Empirically verified in `TransactionService.php` lines 340–365, 570–585, 786)
- **File Path:** [`app/Modules/Sales/Services/TransactionService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/TransactionService.php#L340-L365)
- **Evidence:** `TransactionService` mengeksekusi:
  ```php
  $product->stock -= $item['quantity'];
  $ingredient->current_stock = bcsub((string) $ingredient->current_stock, $needed, 4);
  DB::table('stock_movements')->insert([...]);
  ```
  Alih-alih memanggil `InventoryLedgerService::recordMovement()`.
- **Actual Behavior:** POS frontline membuat mutasi stok sendiri dengan logika terpisah dari modul inventori. Logika validasi stok negatif bergantung pada konfigurasi flag `pos.stock_balances_authoritative`.
- **Expected Behavior:** Seluruh mutasi persediaan fisik (baik penjualan POS, retur, opname, penerimaan barang, maupun transfer) harus melewati satu pintu kanonis: `InventoryLedgerService`.
- **Root Cause:** Migrasi bertahap dari arsitektur lama yang tidak menuntaskan refactor pada modul kasir.
- **Business Impact:** Risiko desinkronisasi saldo antara tabel materialized balance (`ingredient_stock_balances`) dengan kolom legacy `ingredients.current_stock`.
- **Recommendation:** Refactor alur pemotongan stok pada `TransactionService` untuk sepenuhnya mendelegasikan operasi mutasi ke `InventoryLedgerService::recordMovement()`.

---

### FINDING-DOM06-02: Race Condition in Stock Transfer Number Generation
- **Domain:** Domain 6 — Multi-Branch Inventory Transfers
- **Severity:** **P2** (Operational Concurrency Defect)
- **Confidence:** **HIGH** (Empirically verified in `StockTransferService.php` line 348)
- **File Path:** [`app/Modules/Inventory/Services/StockTransferService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Inventory/Services/StockTransferService.php#L340-L365)
- **Evidence:**
  ```php
  // StockTransferService.php:
  $latest = StockTransfer::withoutTenantScope()
      ->where('tenant_id', $tenantId)
      ->where('transfer_number', 'LIKE', "{$prefix}%")
      ->orderByDesc('transfer_number')
      ->value('transfer_number');
  $seq = str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
  ```
- **Actual Behavior:** Penomoran dokumen transfer dihasilkan dari pembacaan record terakhir tanpa penguncian pesimistik (*pessimistic lock*) atau tabel urutan sekuensial. Jika dua cabang membuat transfer stok pada detik yang sama, keduanya mendapatkan nomor urut yang sama dan salah satu akan gagal dengan `SQLSTATE[23000] Duplicate entry`.
- **Expected Behavior:** Penomoran dokumen bisnis enterprise wajib menggunakan generator sekuensial terpusat yang aman dari konkurensi (seperti `DocumentSequenceService` yang telah tersedia di Modul Purchasing).
- **Root Cause:** Tidak digunakannya `DocumentSequenceService` pada modul transfer antarcabang.
- **Business Impact:** Error acak saat outlet ramai melakukan pengiriman transfer stok antarcabang.
- **Recommendation:** Integrasikan `DocumentSequenceService` ke dalam `StockTransferService::generateTransferNumber()`.

---

### FINDING-DOM06-03: Absence of Recipe Versioning and Production Batch Preparation (Central Kitchen Gap)
- **Domain:** Domain 6 — F&B Production & Costing
- **Severity:** **P2** (Industry Vertical Capability Gap)
- **Confidence:** **HIGH** (Empirically verified: `product_ingredients` is flat without effective date range or version)
- **File Path:** Skema database `product_ingredients` dan `ingredients`
- **Evidence:** Tabel `product_ingredients` hanya menyimpan relasi `(product_id, ingredient_id, quantity_required)`. Tidak ada kolom `version`, `effective_from`, `effective_to`, `yield_percentage`, atau dokumen `production_orders`.
- **Actual Behavior:** Jika seorang koki mengubah takaran sirup vanila dari 30ml menjadi 20ml, seluruh kalkulasi historis laporan margin produk di masa lalu akan terhitung ulang menggunakan resep baru. Selain itu, dapur pusat (*central kitchen*) tidak dapat mencatat proses produksi batch untuk membuat saus atau kaldu sebelum didistribusikan ke cabang.
- **Expected Behavior:** ERP F&B kelas enterprise (seperti SAP Recipe Management atau Oracle Simphony) mendukung *Recipe Versioning* dengan rentang tanggal aktif dan dokumen *Batch Production Orders* untuk mengonversi bahan baku menjadi *semi-finished goods*.
- **Root Cause:** Desain resep POS restoran konvensional yang belum ditingkatkan ke standar ERP manufaktur/F&B.
- **Business Impact:** Distorsi analisis biaya makanan historis (*historical food costing analysis*) dan ketidakmampuan mendukung operasional restoran dengan model *Central Kitchen / Commissary*.
- **Recommendation:**
  1. Tambahkan `recipe_versions` dan `recipe_version_items` dengan `effective_date`.
  2. Rancang Modul Central Kitchen Batch Production pada Roadmap Fase 2.

---

## 5. CAPABILITY GAP MATRIX (INVENTORY & SUPPLY CHAIN)

| Supply Chain Capability | Status Aktual Apeiron | Benchmark Enterprise (SAP MM / NetSuite SCM) | Gap Analysis & Kebutuhan F&B/Retail |
|---|---|---|---|
| **Procure-to-Pay (PR, PO, GRN, Bill)** | **Implemented & Verified** | Full Lifecycle P2P with Multi-Level Hierarchy | Modul 5 Purchasing sangat komprehensif dan memenuhi standar enterprise. |
| **3-Way Matching Verification** | **Implemented & Verified** | Automated PO vs GRN vs Invoice Matching with Tolerance Rules | `SupplierInvoiceMatchingService` berjalan dengan akurasi tinggi. |
| **Multi-Branch Stock Transfers (2-Phase)** | **Implemented & Verified** | Two-Phase Transfer (Ship -> In-Transit -> Receive/Discrepancy) | Didukung penuh di `StockTransferService` dengan proteksi non-negatif. |
| **Physical Stock Opname Workflow** | **Implemented & Verified** | Blind Counts, Cycle Counting, Discrepancy Adjustments | Sangat baik dengan alur verifikasi multi-tahap. |
| **Recipe / Bill of Materials (BOM)** | **Implemented but Incomplete** | Multi-Level BOM, Yield Shrinkage, Recipe Versioning | Resep BOM tersedia, namun belum ada versioning dan yield loss. |
| **Central Kitchen & Batch Production** | **Not Implemented** | Work Orders, Disassembly, Semi-Finished Goods Batching | Belum tersedia dokumen manufaktur/batch prep F&B. |
| **Lot / Batch Expiry Tracking** | **Partially Ready** | Per-lot Expiration Dates, FEFO Picking, Recall Traceability | Kolom `batch_number` dan `expiry_date` ada di skema GRN, namun belum ditegakkan di POS picking. |

---

## 6. VERDICT & CONDITIONS

Domain 6 dinyatakan **NEEDS REMEDIATION**. Modul Pengadaan (Purchasing P2P) dan Integritas Database XOR sangat kokoh, namun penyatuan jalur mutasi stok kasir ke `InventoryLedgerService` dan perbaikan sekuens transfer stok wajib dituntaskan sebelum rilis produksi.
