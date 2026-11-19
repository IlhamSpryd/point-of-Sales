# MATRIKS INTEGRITAS NAVIGASI SISTEM (NAVIGATION INTEGRITY MATRIX)
## Apeiron Systems — Enterprise ERP & Multi-Tenant SaaS POS
### Versi: 2.4-CANONICAL | Status: VERIFIED & TESTED | Tanggal: 07 November 2026

---

## 1. STRUKTUR 8 PILAR NAVIGASI UTAMA (SIDEBAR ACCORDION)

Navigasi sidebar Apeiron Systems dikelompokkan ke dalam **8 Pilar Tugas Kanonikal (Task-Oriented Pillars)** yang diatur secara deklaratif melalui `config/navigation.php` dan diverifikasi otomatis oleh suite pengujian `SidebarIntegrityTest`:

| No | Pilar Navigasi (Section) | Item Menu | Rute Terdaftar | Middleware Peran (Roles) | Cakupan Data (Scope) | Sub-Navigasi Terhubung |
|---|---|---|---|---|---|---|
| **1** | **Overview** | Dashboard | `dashboard` | `Owner`, `Manager` | `tenant` | Quick Action Pills |
| **2** | **Cashier & Operations** | POS Terminal | `transaction.create` | `Owner`, `Manager`, `Kasir` | `store` | Cart, Tender, Struk |
| | | Table Management | `tables.index` | `Owner`, `Manager` | `store` | Floor Plan, QR Token |
| | | Order History | `orders.index` | `Owner`, `Manager`, `Kasir`, `Waiter` | `store` | Detail, Void, Return |
| | | Cashier Shifts | `shifts.index` | `Owner`, `Manager`, `Kasir`, `Supervisor` | `store` | Open, Movement, Close |
| | | Kitchen Display (KDS) | `kds.index` | `Owner`, `Manager`, `Kasir`, `Barista`, `Waiter`, `Cook` | `store` | Live Kitchen Queue |
| **3** | **Catalog & Recipes** | Product Catalog | `products.index` | `Owner`, `Manager`, `Inventory` | `tenant` | `catalog/partials/sub-nav` |
| | | Price Lists | `price-lists.index` | `Owner`, `Manager`, `Inventory` | `tenant` | Tiered Volume & Currency |
| | | Branch Assortment | `assortments.index` | `Owner`, `Manager`, `Inventory` | `store` | Store-level availability |
| **4** | **Inventory & Supply** | Raw Materials & Stock | `inventory.ingredients` | `Owner`, `Manager`, `Inventory` | `store` | `inventory/partials/sub-nav` |
| | | Stock Operations | `inventory.goods-receipts` | `Owner`, `Manager`, `Inventory` | `store` | `inventory/partials/sub-nav` |
| | | Stock Ledger | `inventory.ledger` | `Owner`, `Manager`, `Inventory` | `store` | Mutasi per lot & batch |
| | | Purchasing | `purchasing.suppliers.index` | `Owner`, `Manager`, `Inventory` | `tenant` | `purchasing/partials/sub-nav`|
| **5** | **Analytics & Customers** | BI & Analytics | `reports.analytics` | `Owner`, `Manager` | `tenant` | Visualisasi Metrik & KPI |
| | | Sales Reports | `reports.sales` | `Owner`, `Manager` | `tenant` | Laporan Transaksi & Pajak |
| | | Customers & CRM | `customers.index` | `Owner`, `Manager` | `tenant` | `customers/partials/sub-nav`|
| | | Discounts & Promotions | `discounts.index` | `Owner`, `Manager` | `store` | Kampanye, Voucher, Aturan |
| | | B2B Sales Orders | `sales.orders.index` | `Owner`, `Manager`, `Sales`, `Admin`, `Finance` | `tenant` | `sales/partials/sub-nav` |
| **6** | **Finance & Accounting** | Finance Overview | `finance.index` | `Owner`, `Manager`, `Finance`, `Admin` | `tenant` | `finance/partials/sub-nav` |
| **7** | **Human Resources & Payroll** | Employees | `hr.employees.index` | `Owner`, `Manager` | `tenant` | `livewire/hr/partials/sub-nav` |
| | | Workforce Schedules | `hr.schedules.index` | `Owner`, `Manager` | `store` | `livewire/hr/partials/sub-nav` |
| | | Attendance & Overtime | `hr.attendance.index` | `Owner`, `Manager` | `store` | `livewire/hr/partials/sub-nav` |
| | | Leave Management | `hr.leaves.index` | `Owner`, `Manager` | `tenant` | `livewire/hr/partials/sub-nav` |
| | | Sales Commissions | `hr.commissions.index` | `Owner`, `Manager` | `store` | `livewire/hr/partials/sub-nav` |
| | | Payroll Batches | `hr.payroll.index` | `Owner`, `Manager` | `tenant` | `livewire/hr/partials/sub-nav` |
| **8** | **Settings & Governance** | Branches | `stores.index` | `Owner` | `tenant` | Multi-store CRUD |
| | | Users | `users.index` | `Owner` | `tenant` | Invitations, Store Access |
| | | Roles & Permissions | `roles.index` | `Owner` | `tenant` | RBAC Matrix Custom |
| | | POS Devices | `devices.index` | `Owner`, `Manager` | `store` | Device activation & pairing |
| | | System Settings | `settings.index` | `Owner` | `tenant` | Tax, Receipt header, Seq |
| | | Audit Logs | `activity-logs.index` | `Owner`, `Manager` | `tenant` | Immutable Activity Trail |

---

## 2. MATRIKS KETERJANGKAUAN PERAN PENGGUNA (ROLE ACCESSIBILITY MATRIX)

Setiap peran dalam Apeiron Systems memiliki visibilitas menu sidebar yang presisi dan fail-closed:

| Peran (Role) | Jumlah Item Sidebar | Daftar Item Menu yang Dapat Diakses Langsung | Status Celah |
|---|---|---|---|
| **Owner** | **31 Item** | Akses penuh ke seluruh 8 pilar sistem (Dashboard, POS, Meja, Pesanan, Shift, KDS, Produk, Harga, Assortment, Bahan, Operasi Stok, Kartu Stok, Purchasing, BI Analytics, Laporan Penjualan, CRM, Diskon, Sales B2B, Finance Overview, 6 Menu HRM, dan 6 Menu Pengaturan). | **RESOLVED & VERIFIED** |
| **Manager** | **27 Item** | Seluruh menu operasional di atas, kecuali pengelolaan entitas level tenant seperti Master Cabang (`stores`), Pengguna (`users`), Peran (`roles`), dan Pengaturan Sistem (`settings`). | **RESOLVED & VERIFIED** |
| **Kasir** | **4 Item** | `POS Terminal`, `Cashier Shifts`, `Order History`, `Kitchen Display (KDS)` | **RESOLVED & VERIFIED** |
| **Waiter** | **2 Item** | `Order History`, `Kitchen Display (KDS)` | **RESOLVED & VERIFIED** |
| **Supervisor** | **1 Item** | `Cashier Shifts` (ditambah otorisasi void & diskon manajerial di POS) | **RESOLVED & VERIFIED** |
| **Inventory** | **7 Item** | `Product Catalog`, `Price Lists`, `Branch Assortment`, `Raw Materials & Stock`, `Stock Operations`, `Stock Ledger`, `Purchasing` | **RESOLVED & VERIFIED** |
| **Finance** | **2 Item** | `B2B Sales Orders` (terhubung ke AR), `Finance Overview` (terhubung ke CoA, GL, Jurnal, Periode, Rekonsiliasi) | **RESOLVED & VERIFIED** |
| **Sales** | **1 Item** | `B2B Sales Orders` (terhubung ke Sales Order, Quotation, AR Invoice, Return Credit Note) | **RESOLVED & VERIFIED** |
| **Cook** | **1 Item** | `Kitchen Display (KDS)` (antrean pesanan dapur) | **RESOLVED & VERIFIED** |
| **Barista** | **1 Item** | `Kitchen Display (KDS)` (antrean minuman) | **RESOLVED & VERIFIED** |

---

## 3. KLASIFIKASI KETERJANGKAUAN 264 BLADE TEMPLATES (6-LEVEL TAXONOMY)

Hasil analisis menyeluruh terhadap seluruh 264 file template Blade menunjukkan bahwa **100% halaman terintegrasi dengan benar** dan tidak ada file yang terlantar (*zero orphans*):

| Kategori Klasifikasi | Jumlah Halaman | Persentase | Karakteristik & Contoh Halaman |
|---|---|---|---|
| **Level 1: DIRECT_SIDEBAR** | **49 Views** | 18.6% | Halaman utama yang memiliki entri langsung di sidebar navigasi (`dashboard`, `products.index`, `sales.orders.index`, `finance.index`, `hr.employees.index`, dll.). |
| **Level 2: DIRECT_NAVBAR** | **1 View** | 0.4% | Halaman yang diakses langsung dari header navigasi atas (`profile.edit` via dropdown avatar pengguna). |
| **Level 3: IN_PAGE_TAB_OR_BUTTON** | **25 Views** | 9.5% | Halaman sub-modul yang diakses melalui tab sub-navigasi horizontal di dalam hub modul (misal: `categories.index`, `brands.index`, `units-of-measure.index`, `finance.coa.index`, `purchasing.orders.index`, `customers.credit.index`). |
| **Level 4: CONTEXTUAL_WORKFLOW** | **186 Views** | 70.5% | Alur kerja terpandu kontekstual: form create/edit (`categories.create`), alur otentikasi (`login`, `register`, `forgot-password`, `2fa-challenge`), onboarding wizard, self-order tamu meja QR (`customer.menu`, `customer.checkout`), modal Livewire, dan komponen reusable `<x-...>`. |
| **Level 5: DIRECT_URL_ONLY** | **0 Views** | 0.0% | **NIHIL**. Seluruh halaman yang memiliki rute kini terhubung dengan navigasi visual UI. |
| **Level 6: COMPLETELY_ORPHANED** | **3 Views** | 1.1% | Halaman penanganan kesalahan HTTP standar framework (`errors/403.blade.php`, `errors/404.blade.php`, `errors/500.blade.php`) yang dipanggil secara otomatis oleh exception handler Laravel saat terjadi abort. |

---

## 4. DAFTAR SUB-NAVIGASI MODULAR KANONIKAL

Sistem Apeiron Systems menggunakan pola arsitektur **Sub-Navigasi Kontekstual** yang memastikan setiap halaman sub-modul memiliki akses cepat dua arah ke seluruh fitur terkait:

### 4.1 Sub-Navigasi Katalog (`catalog/partials/sub-nav.blade.php`)
- `products.index` — Master Katalog Produk
- `categories.index` — Kategori Produk & Hierarki
- `brands.index` — Merek Dagang
- `units-of-measure.index` — Satuan Ukuran & Konversi Satuan
- `inventory.modifiers` — Varian & Modifier Bertingkat
- `tax-classes.index` — Golongan Tarif Pajak
- `assortments.index` — Ketersediaan Produk Cabang

### 4.2 Sub-Navigasi Inventori (`inventory/partials/sub-nav.blade.php`)
- `inventory.ingredients` — Bahan Baku & Stok Gudang
- `inventory.goods-receipts` — Penerimaan Barang Masuk
- `inventory.adjustments` — Penyesuaian Selisih Stok
- `inventory.opnames` — Sensus Fisik Stok (Opname)
- `inventory.transfers` — Mutasi / Transfer Antarcabang
- `inventory.supplier-returns` — Retur Barang ke Pemasok
- `inventory.ledger` — Buku Besar Mutasi Stok

### 4.3 Sub-Navigasi Pengadaan (`purchasing/partials/sub-nav.blade.php`)
- `purchasing.suppliers.index` — Direktori Pemasok
- `purchasing.orders.index` — Purchase Orders (PO)
- `purchasing.grns.index` — Goods Received Note (GRN)
- `purchasing.invoices.index` — Verifikasi Faktur Pemasok (3-Way Matching)
- `purchasing.returns.index` — Retur Pembelian

### 4.4 Sub-Navigasi Penjualan B2B (`sales/partials/sub-nav.blade.php`)
- `sales.orders.index` — Sales Orders (SO)
- `sales.quotations.index` — Penawaran Harga (Quotations)
- `sales.invoices.index` — Faktur Komersial & Piutang (AR)
- `sales.returns.index` — Retur Penjualan & Nota Kredit

### 4.5 Sub-Navigasi Keuangan (`finance/partials/sub-nav.blade.php`)
- `finance.index` — Ikhtisar Dasbor Keuangan
- `finance.coa.index` — Bagan Akun Standar (COA)
- `finance.journals.index` — Buku Jurnal Umum & Posting
- `finance.periods.index` — Periode Fiskal & Tutup Buku
- `finance.reconciliation.index` — Rekonsiliasi Rekening Bank

### 4.6 Sub-Navigasi Pelanggan & CRM (`customers/partials/sub-nav.blade.php`)
- `customers.index` — Direktori Pelanggan
- `customers.groups.index` — Segmentasi & Grup Pelanggan
- `customers.credit.index` — Kontrol Plafon Kredit B2B
- `customers.instruments.index` — Poin Loyalitas, Voucher & Kartu Hadiah

### 4.7 Sub-Navigasi SDM & Payroll (`livewire/hr/partials/sub-nav.blade.php`)
- `hr.employees.index` — Direktori Karyawan
- `hr.schedules.index` — Penjadwalan Kerja & Shift
- `hr.attendance.index` — Absensi & Lembur PP 35/2021
- `hr.leaves.index` — Saldo & Pengajuan Cuti
- `hr.commissions.index` — Komisi Penjualan Staf
- `hr.payroll.index` — Batch Penggajian & PPh 21 TER PMK 168
