# Registri Modul Utama — Apeiron Systems Enterprise ERP

Dokumen ini merupakan registri resmi yang mencakup 18 modul produk Apeiron Systems, kode domain unik, entitas utama, kepemilikan data, dan status implementasi aktual pada sistem, serta pemetaannya ke 13 Bounded Context Kanonikal (ADR-102).

> [!IMPORTANT]
> **Anti-Drift Guardrail (ADR-102, ADR-114 & AGENTS.md):**
> Kode sumber aplikasi Apeiron Systems dipartisi secara ketat ke dalam **13 Canonical Bounded Contexts** di bawah `app/Modules/<Context>/` dan Platform Primitives di bawah `app/Platform/`.
> Direktori lama seperti `HR`, `Products`, `FNB`, `Retail`, `MasterData`, `CRM`, `Users`, `Auth`, dan `Settings` telah **dihapus permanen**. Modul-modul produk di bawah dipetakan langsung ke 13 context kanonikal tersebut.

---

## 1. Daftar Resmi 18 Modul ERP & Pemetaan Bounded Context (ADR-102)

| No | Kode Modul | Nama Modul | Tanggung Jawab Inti | Entitas / Tabel Utama | Bounded Context Kanonikal (app/Modules/) | Status Implementasi |
|---|---|---|---|---|---|---|
| 01 | **CORE** | Core Platform & Multi-Tenancy | Manajemen tenant, cabang, otentikasi, RBAC, sesi, audit trail, isolasi 5-tier | `tenants`, `stores`, `users`, `roles`, `permissions`, `devices`, `activity_logs` | `Organization` & `app/Platform/` | **VERIFIED** |
| 02 | **MDM** | Organization & Master Data | Profil bisnis, struktur organisasi, UOM konversi, sekuens penomoran dokumen | `document_sequences`, `units_of_measure`, `product_unit_conversions`, `tax_classes` | `Organization` & `Catalog` | **VERIFIED** |
| 03 | **CAT** | Product, Catalog & Merchandising | Master produk, varian, atribut, merek, kategori, bundling, assortment cabang | `products`, `product_variants`, `product_attributes`, `brands`, `categories`, `product_bundle_items`, `store_product_assortments` | `Catalog` | **VERIFIED** |
| 04 | **PRC** | Pricing, Promotion & Loyalty | Daftar harga, mesin kampanye diskon, voucher, kartu hadiah, poin loyalitas berantai | `price_lists`, `promotion_campaigns`, `promotion_rules`, `promotion_rewards`, `vouchers`, `gift_cards`, `loyalty_ledger` | `Pricing` | **VERIFIED** |
| 05 | **POS** | POS & Store Operations | Kasir, cart, tender split, manajemen shift, laci kas, closing kasir, cetak struk | `orders`, `order_items`, `payments`, `payment_attempts`, `shifts`, `cash_drawer_movements`, `receipt_print_logs` | `Sales` | **VERIFIED** |
| 06 | **SLS** | Sales & Order Management | Penawaran harga (Quotation), Pesanan Penjualan B2B (SO), Faktur Komersial AR | `quotations`, `quotation_lines`, `sales_orders`, `sales_order_lines`, `customer_invoices`, `sales_credit_notes` | `Sales` | **VERIFIED** |
| 07 | **INV** | Inventory & Warehouse Management | Buku besar stok append-only, saldo persediaan, opname, transfer cabang, penyesuaian | `stock_movements`, `product_stock_balances`, `ingredient_stock_balances`, `stock_opnames`, `stock_transfers`, `stock_adjustments` | `Inventory` | **VERIFIED** |
| 08 | **PUR** | Purchasing & Supplier Management | Pemasok, Permintaan Pembelian, PO, Penerimaan GRN, Verifikasi 3-Arah, Faktur AP | `suppliers`, `purchase_requests`, `purchase_orders`, `goods_received_notes`, `supplier_invoices`, `purchase_debit_notes` | `Purchasing` | **VERIFIED** |
| 09 | **MFG** | Manufacturing, Recipe & Production | Resep (BOM), deduksi bahan baku otomatis saat pesanan kasir, biaya resep | `ingredients`, `product_ingredients`, `modifier_ingredients`, `ingredient_stock_movements`, `ingredient_restock_forecasts` | `Manufacturing` | **PARTIALLY VERIFIED** (Batch MO terpisah: *In-Progress*) |
| 10 | **FNB** | Restaurant & F&B Management | Manajemen tata letak meja, Kitchen Display System (KDS), modifier menu bertingkat | `tables`, `table_sessions`, `modifier_groups`, `modifiers`, `order_item_modifiers` | `Sales` (Meja/KDS) & `Catalog` (Modifier) | **VERIFIED** (Hardened Wave 7B) |
| 11 | **RTL** | Retail Management | Matriks varian ritel, operasional kode batang, label harga rak, retur ritel | `product_variants`, `store_product_assortments`, `product_attributes` | `Catalog` & `Sales` | **VERIFIED** |
| 12 | **FIN** | Finance & Accounting | Double-entry GL, Bagan Akun (CoA), Jurnal posting otomatis, Subledger AR/AP/Pajak | `chart_of_accounts`, `accounting_periods`, `journal_entries`, `journal_lines`, `ap_ledger_entries`, `ar_ledger_entries`, `tax_ledger_entries` | `Finance` | **VERIFIED** |
| 13 | **CRM** | Customer Relationship Management | Profil pelanggan, grup pelanggan, akun kredit pelanggan, riwayat belanja | `customers`, `customer_groups`, `customer_credit_accounts`, `customer_credit_logs`, `customer_loyalty_accounts` | `Customers` | **VERIFIED** |
| 14 | **HRM** | HR, Employee & Workforce Management | Data karyawan, absensi, lembur PP 35/2021, cuti, komisi, PPh 21 TER, BPJS, payroll | `employees`, `attendance_records`, `overtime_records`, `leave_requests`, `payroll_batches`, `statutory_rule_versions` | `Workforce` | **VERIFIED** |
| 15 | **RBI** | Reporting & Business Intelligence | Kamus metrik analitik penjualan, keuangan, laba kotor, ekspor latar belakang | `export_tasks`, `inventory_valuation_snapshots`, agregasi analitik per cabang | `Reporting` | **VERIFIED** |
| 16 | **WFL** | Workflow, Approval & Document | Alur kerja persetujuan multi-tingkat (PR, diskon besar, penyesuaian stok, expense) | `approvals`, `purchase_approval_rules`, `purchase_approval_requests`, `expense_approvals` | `app/Platform/Workflow` | **VERIFIED** |
| 17 | **SUB** | SaaS Subscription & Platform Billing | Paket harga SaaS, limitasi kuota (cabang/user), penagihan otomatis, status suspensi | `plans`, `plan_entitlements`, `tenant_subscriptions`, `tenant_entitlement_overrides`, `provisioning_requests` | `Subscription` | **VERIFIED** |
| 18 | **INT** | Integration, API & Automation | Gerbang Midtrans, GrabFood/GoFood OFD, printer thermal ESC/POS, webhook, outbox | `email_outbox`, `channel_order_logs`, `channel_product_mappings`, `ofd_channel_configs`, Midtrans | `Integration` | **VERIFIED** (Extended Wave 7C) |
