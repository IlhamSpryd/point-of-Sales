# IMPLEMENTATION PLAN: SIDEBAR NAVIGATION SIMPLIFICATION & INFORMATION ARCHITECTURE
## Apeiron POS — Enterprise SaaS Navigation Architecture (Modules 1–4)

---

## 1. Document Control

| Attribute | Details |
|---|---|
| **Document ID** | `APEIRON-DOC-NAV-001` |
| **Document Version** | `1.0.0` |
| **Status** | `APPROVED & IMPLEMENTED` |
| **Target Path** | `docs/implementationPlanSidebarNavigation.md` |
| **Audited Commit SHA** | `a4518d6aa3999c2f500b1b5679091967cff8adbf` (`feat(inventory): implement inventory and warehouse management engine (module 4)`) |
| **Working Tree Status** | `CLEAN` |
| **Author / Mandate** | Principal UX Architect & Enterprise SaaS Information Architect |
| **Supervising Architect** | Principal System Architect |
| **Execution Tooling** | Gemini IDE / Laravel Test Runner |
| **Product Roadmap Scope** | Modules 1 through 4 (Platform, POS/Retail, Catalog, Inventory/Warehouse) |

---

## 2. Executive Summary

Apeiron POS telah menuntaskan empat modul inti: Multi-Tenancy Core (Modul 1), POS & Retail Operations (Modul 2), Product & Catalog Management (Modul 3), serta Inventory & Warehouse Management (Modul 4). 

Namun, evaluasi arsitektur navigasi menemukan diskoneksi kritis:
1. **Module 4 Inaccessibility**: 5 controller/Livewire manager pergudangan (`GoodsReceiptManager`, `StockAdjustmentManager`, `StockOpnameManager`, `StockTransferManager`, `SupplierReturnManager`) berstatus *orphaned* — berfungsi penuh di tingkat backend/service namun belum terdaftar di menu navigasi manapun.
2. **Cognitive Load Inflation**: Role `Owner` saat ini disajikan 18 item navigasi flat pada sidebar. Apabila 5 alur pergudangan Modul 4 ditambahkan secara flat, sidebar akan membengkak menjadi 23 item dengan kedalaman scroll vertikal yang melelahkan pada layar tablet dan laptop kasir.
3. **Misplaced Workstations**: Menu harian dengan frekuensi interaksi tertinggi (`Kasir (POS)` dan `Dapur (KDS)`) terisolasi di bagian terbawah sidebar (`Terminal Kerja`), sementara menu konfigurasi tenant yang jarang diakses (`Cabang`, `Pengguna`, `Peran`) menempati posisi visual sentral.
4. **Duplikasi Entri**: Route `settings.index` terdaftar ganda (pada sidebar grup `Organisasi & Pengaturan` dan di dalam user profile popover).
5. **Collapsed Sidebar Glitch**: Ketika sidebar desktop diciutkan (`w-18`), indikator cabang aktif (`x-branch-switcher`) disembunyikan total, menghilangkan *context awareness* operator.

Dokumen ini merancang penyederhanaan taksonomi navigasi Apeiron POS menjadi **5 Pilar Berbasis Tugas (Task-Oriented 5-Pillar Architecture)** dengan mengadopsi pola **Contextual In-Page Sub-Navigation** untuk domain Katalog dan Inventori. Rencana ini menjaga integritas otorisasi server-side, isolasi tenant-store, backward compatibility URL, serta cart dirty guard (`window.apeironPosCartDirty`).

---

## 3. Repository Evidence and Current Navigation Inventory

### 3.1. Technical Stack Baseline (Confirmed)
- **Framework**: Laravel 13 (`laravel/framework: ^13.17`), PHP 8.3 (`composer.json`).
- **Reactive UI**: Livewire 4 (`livewire/livewire: ^4.4`), Alpine.js 3 (`@livewireScripts` bundled).
- **Styling**: Tailwind CSS v4 (`@theme` directive di `resources/css/app.css`, tanpa `tailwind.config.js`).
- **Icons**: Google Material Symbols Rounded (`font-variation-settings: 'FILL' 0, 'wght' 400`).
- **Single Source of Truth**: Menu dikelola deklaratif melalui `config/navigation.php`.

### 3.2. Current Sidebar Navigation Inventory (`config/navigation.php`)

| # | Section Saat Ini | Label Item | Route Name | HTTP Method / Target | Roles Diizinkan | Data Scope | Status Navigasi |
|---|---|---|---|---|---|---|---|
| 1 | Overview | Dashboard | `dashboard` | `GET /dashboard` | Owner, Manager | `tenant` | Confirmed |
| 2 | Penjualan & Pesanan | Riwayat Pesanan | `orders.index` | `GET /orders` | Owner, Manager, Kasir, Waiter | `store` | Confirmed |
| 3 | Penjualan & Pesanan | Laporan Penjualan | `reports.sales` | `GET /reports/sales` | Owner, Manager | `tenant` | Confirmed |
| 4 | Penjualan & Pesanan | Diskon & Promo | `discounts.index` | `GET /discounts` | Owner, Manager | `store` | Confirmed |
| 5 | Katalog & Inventori | Produk | `products.index` | `GET /products` | Owner, Manager, Inventory | `tenant` | Confirmed |
| 6 | Katalog & Inventori | Bahan Baku | `inventory.ingredients` | `GET /inventory/ingredients` | Owner, Manager, Inventory | `store` | Confirmed |
| 7 | Katalog & Inventori | Riwayat Stok | `inventory.ledger` | `GET /inventory/ledger` | Owner, Manager, Inventory | `store` | Confirmed |
| 8 | Operasional Toko | Shift Kasir | `shifts.index` | `GET /shifts` | Owner, Manager, Kasir, Supervisor | `store` | Confirmed |
| 9 | Operasional Toko | Meja & QR | `tables.index` | `GET /tables` | Owner, Manager | `store` | Confirmed |
| 10 | Operasional Toko | Perangkat POS | `devices.index` | `GET /devices` | Owner, Manager | `store` | Confirmed |
| 11 | Operasional Toko | Integrasi Channel | `integrations.channel-mapping` | `GET /integrations/channel-mapping` | Owner, Manager | `tenant` | Confirmed |
| 12 | Organisasi & Pengaturan | Cabang | `stores.index` | `GET /stores` | Owner | `tenant` | Confirmed |
| 13 | Organisasi & Pengaturan | Pengguna | `users.index` | `GET /users` | Owner | `tenant` | Confirmed |
| 14 | Organisasi & Pengaturan | Peran | `roles.index` | `GET /roles` | Owner | `tenant` | Confirmed |
| 15 | Organisasi & Pengaturan | Pengaturan | `settings.index` | `GET /settings` | Owner | `tenant` | Confirmed (Duplicate) |
| 16 | Organisasi & Pengaturan | Audit Log | `activity-logs.index` | `GET /activity-logs` | Owner, Manager | `tenant` | Confirmed |
| 17 | Terminal Kerja | Kasir (POS) | `transaction.create` | `GET /transaction` | Owner, Manager, Kasir | `store` | Confirmed |
| 18 | Terminal Kerja | Dapur (KDS) | `kds.index` | `GET /kds` | Owner, Manager, Kasir, Barista, Waiter, Cook | `store` | Confirmed |

### 3.3. Module 4 Inventory Workflow Inventory (Unlinked in Navigation)

| # | Domain Workflow | Route Name | Livewire Component | Role Middleware | Scope | Status di Menu |
|---|---|---|---|---|---|---|
| 19 | Penerimaan Barang (GRN) | `inventory.goods-receipts` | `App\Livewire\Inventory\GoodsReceiptManager` | `role:Owner,Manager,Inventory` | `store` | Orphaned (Missing) |
| 20 | Penyesuaian Stok Fisik | `inventory.adjustments` | `App\Livewire\Inventory\StockAdjustmentManager` | `role:Owner,Manager,Inventory` | `store` | Orphaned (Missing) |
| 21 | Stock Opname (Drift-safe) | `inventory.opnames` | `App\Livewire\Inventory\StockOpnameManager` | `role:Owner,Manager,Inventory` | `store` | Orphaned (Missing) |
| 22 | Transfer Stok Antarcabang | `inventory.transfers` | `App\Livewire\Inventory\StockTransferManager` | `role:Owner,Manager,Inventory` | `store` | Orphaned (Missing) |
| 23 | Retur Stok ke Supplier | `inventory.supplier-returns` | `App\Livewire\Inventory\SupplierReturnManager` | `role:Owner,Manager,Inventory` | `store` | Orphaned (Missing) |

### 3.4. Existing Contextual Catalog Sub-Nav (`resources/views/catalog/partials/sub-nav.blade.php`)
Modul 3 telah menggunakan tab in-page terintegrasi untuk 8 layar master:
`[ Produk | Kategori | Merek | Satuan Ukur | Varian & Modifier | Kelas Pajak | Asortimen Cabang | Daftar Harga ]`.
Active state di sidebar dipertahankan aktif pada item `Produk` (`products.index`) melalui penggabungan pattern route di Blade.

---

## 4. Current Problems and Root Cause Analysis

### 4.1. Problem 1: Unlinked High-Value Module 4 Workflows (Severity: High)
- **Gejala**: Pengguna role `Owner`, `Manager`, dan khususnya `Inventory` tidak dapat mengakses Goods Receipt, Opname, Adjustment, Transfer, dan Supplier Return dari antarmuka visual.
- **Akar Masalah**: Pengerjaan Modul 4 fokus pada backend domain engine, database triggers, dan Livewire manager, sementara pendaftaran ke `config/navigation.php` ditunda guna menghindari penambahan visual clutter pada sidebar yang sudah padat.

### 4.2. Problem 2: Cognitive Overload & Flat List Exhaustion (Severity: High)
- **Gejala**: Sidebar role `Owner` menampilkan 18 item bertumpuk. Karyawan kasir atau manajer toko harus memindai deretan teks panjang untuk menemukan fitur harian.
- **Akar Masalah**: Taksonomi navigasi bersifat flat 1-level tanpa pengelompokan sub-domain fungsional. Seluruh entri menu diberikan bobot visual identik terlepas dari frekuensi penggunaannya.

### 4.3. Problem 3: Inversi Hierarki Prioritas Terminal Kerja (Severity: Medium)
- **Gejala**: `Kasir (POS)` dan `Dapur (KDS)` berada di section 6 (paling bawah). Pada viewport tablet landscape standar (1024x768 atau 1280x800), kasir seringkali harus melakukan scroll ke bawah hanya untuk membuka POS.
- **Akar Masalah**: Konsep "Terminal Kerja" diperlakukan sebagai appendix sekunder di bawah administrasi multi-cabang dan audit log.

### 4.4. Problem 4: Duplikasi Akses Pengaturan (Severity: Low)
- **Gejala**: `Pengaturan` (`settings.index`) muncul di section `Organisasi & Pengaturan` dan di menu popover profil bawah.
- **Akar Masalah**: Kurangnya demarkasi tegas antara *global application preferences* dan *tenant management resources*.

### 4.5. Problem 5: Hilangnya Konteks Cabang pada Collapsed State (Severity: Medium)
- **Gejala**: Ketika pengguna mengeklik tombol collapse (`expanded = false`, sidebar mengecil ke `w-18`), branch switcher disembunyikan total (`x-show="wide"`).
- **Akar Masalah**: Komponen `<x-branch-switcher>` hanya dirancang untuk layout lebar horizontal tanpa varian ringkas (icon-only mode) untuk sidebar sempit.

### 4.6. Problem 6: Redundant Mobile Header & Drawer Trigger (Severity: Low)
- **Gejala**: View `dashboard.blade.php` memiliki tombol hamburger sendiri di baris judul yang berdampingan dengan tombol hamburger fixed global dari layout navigation.
- **Akar Masalah**: Inkonsistensi penggunaan prop `:noPadding="true"` pada layout dashboard tanpa standardisasi header universal.

---

## 5. Navigation Design Principles

1. **Task-Oriented Organization**: Kelompokkan menu berdasarkan frekuensi kerja dan konteks operasional pengguna, bukan berdasarkan skema tabel database atau urutan modul pengembangan.
2. **Progressive Disclosure**: Sediakan entry point utama di sidebar, lalu tampilkan submenu/tab kontekstual di tingkat halaman kerja (hub pattern) untuk tugas-tugas spesifik domain yang kompleks.
3. **Strict Authorization Reflection**: Tampilkan menu hanya bila pengguna memiliki peran (`roles`) dan izin yang sah. Navigasi merefleksikan otorisasi, namun enforcement keamanan mutlak berada di middleware dan policy server-side.
4. **Stable Orientation & Active State**: Satu dan hanya satu item sidebar yang aktif pada satu waktu. Navigasi wajib memberikan sinyal lokasi visual yang konsisten antara sidebar, tab sub-nav, dan judul halaman.
5. **Zero Workflow Regression**: Jangan menghapus route, mengubah nama route, atau membatalkan validasi status keranjang (`window.apeironPosCartDirty`).
6. **Future-Proof Scalability**: Desain taksonomi mampu menampung modul masa depan (Modul 5 Purchasing, Modul 6 Sales, Modul 7 Finance) tanpa merombak ulang tata letak inti.

---

## 6. Proposed Information Architecture

Navigasi direstrukturisasi menjadi **5 Pilar Strategis** yang ramping:

```
┌────────────────────────────────────────────────────────┐
│                      APEIRON POS                       │
├────────────────────────────────────────────────────────┤
│ [Branch Switcher: Toko Utama / Semua Cabang]           │
├────────────────────────────────────────────────────────┤
│ 1. PUSAT KERJA (Workstations & Frontline)              │
│    ├── Dashboard                [Owner, Manager]       │
│    ├── Kasir (POS)              [Owner, Mgr, Kasir]    │
│    └── Dapur (KDS)              [Owner, Mgr, Kasir, ..]│
│                                                        │
│ 2. OPERASIONAL TOKO (Store Operations)                 │
│    ├── Riwayat Pesanan          [Owner, Mgr, Ksr, Wtr] │
│    ├── Shift Kasir              [Owner, Mgr, Ksr, Spv] │
│    └── Meja & QR                [Owner, Manager]       │
│                                                        │
│ 3. SUPLAI & INVENTORI (Catalog & Warehouse Hubs)       │
│    ├── Katalog Produk  ───────► [Contextual Sub-Nav: 8]│
│    │                            (Produk, Kategori, ...)│
│    └── Inventori & Stok ──────► [Contextual Sub-Nav: 7]│
│                                 (Bahan, GRN, Opname,..)│
│                                                        │
│ 4. ANALITIK & PENJUALAN (Intelligence & Growth)        │
│    ├── Laporan Penjualan        [Owner, Manager]       │
│    └── Diskon & Promo           [Owner, Manager]       │
│                                                        │
│ 5. PENGATURAN & KELOLA (Administration & Setup)        │
│    ├── Cabang                   [Owner]                │
│    ├── Pengguna & Peran         [Owner]                │
│    ├── Perangkat POS            [Owner, Manager]       │
│    ├── Integrasi Channel        [Owner, Manager]       │
│    └── Audit Log                [Owner, Manager]       │
├────────────────────────────────────────────────────────┤
│ [User Profile & Preferences Popover]                   │
│    ├── Profil Saya                                     │
│    ├── Pengaturan Toko (Single Global Settings Link)   │
│    └── Keluar                                          │
└────────────────────────────────────────────────────────┘
```

---

## 7. Menu Mapping: Current Item → Proposed Location → Reason

| # | Item Saat Ini | Route Target | Posisi / Section Baru | Aksi Desain | Alasan Arsitektural |
|---|---|---|---|---|---|
| 1 | Dashboard | `dashboard` | `Pusat Kerja` | `KEEP` | Pintu masuk utama analitik ringkas harian Owner & Manager. |
| 2 | Kasir (POS) | `transaction.create` | `Pusat Kerja` | `MOVE` | Dipindahkan dari bawah ke atas agar kasir langsung dapat bertransaksi tanpa scroll. |
| 3 | Dapur (KDS) | `kds.index` | `Pusat Kerja` | `MOVE` | Workstation display dapur/barista diletakkan berdampingan dengan POS. |
| 4 | Riwayat Pesanan | `orders.index` | `Operasional Toko` | `MOVE` | Aktivitas operasional harian kasir/waiter dalam melayani void/return dan status pesanan. |
| 5 | Shift Kasir | `shifts.index` | `Operasional Toko` | `KEEP` | Prosedur buka laci, pencatatan kas, dan serah terima shift harian kasir. |
| 6 | Meja & QR | `tables.index` | `Operasional Toko` | `KEEP` | Manajemen tata letak meja fisik dan token pemesanan dine-in tamu resto. |
| 7 | Produk | `products.index` | `Suplai & Inventori` | `RENAME` (Katalog Produk) | Menjadi payung tunggal master katalog produk dengan sub-nav 8 tab yang sudah ada. |
| 8 | Bahan Baku | `inventory.ingredients` | `Suplai & Inventori` | `GROUP & RENAME` (Inventori & Stok) | Ditingkatkan menjadi master hub pergudangan untuk seluruh 7 sub-workflow Modul 4. |
| 9 | Riwayat Stok | `inventory.ledger` | Tab di Sub-Nav Inventori | `GROUP` | Dipindahkan dari sidebar utama ke dalam tab in-page `inventory/partials/sub-nav.blade.php`. |
| 10 | Penerimaan Barang | `inventory.goods-receipts` | Tab di Sub-Nav Inventori | `GROUP` (New Link) | Mengatasi status orphaned Modul 4; dapat diakses instan melalui tab inventori. |
| 11 | Penyesuaian Stok | `inventory.adjustments` | Tab di Sub-Nav Inventori | `GROUP` (New Link) | Mengatasi status orphaned Modul 4; adjustment stok fisik terintegrasi di tab inventori. |
| 12 | Stock Opname | `inventory.opnames` | Tab di Sub-Nav Inventori | `GROUP` (New Link) | Mengatasi status orphaned Modul 4; siklus opname drift-safe terintegrasi di tab inventori. |
| 13 | Transfer Antarcabang | `inventory.transfers` | Tab di Sub-Nav Inventori | `GROUP` (New Link) | Mengatasi status orphaned Modul 4; pengiriman 2-fase antarcabang terintegrasi di tab inventori. |
| 14 | Retur Supplier | `inventory.supplier-returns` | Tab di Sub-Nav Inventori | `GROUP` (New Link) | Mengatasi status orphaned Modul 4; retur barang ke pemasok terintegrasi di tab inventori. |
| 15 | Laporan Penjualan | `reports.sales` | `Analitik & Penjualan` | `MOVE` | Dipisahkan dari transaksi harian kasir ke grup analitik & closing EOD. |
| 16 | Diskon & Promo | `discounts.index` | `Analitik & Penjualan` | `MOVE` | Diposisikan dalam domain pertumbuhan omzet dan strategi penetapan harga promosi. |
| 17 | Cabang | `stores.index` | `Pengaturan & Kelola` | `KEEP` | Konfigurasi cabang outlet fisik tenant oleh Owner. |
| 18 | Pengguna | `users.index` | `Pengaturan & Kelola` | `KEEP` | Administrasi staf, akun karyawan, dan penukaran tautan undangan. |
| 19 | Peran | `roles.index` | `Pengaturan & Kelola` | `KEEP` | Pengaturan wewenang RBAC tenant oleh Owner. |
| 20 | Perangkat POS | `devices.index` | `Pengaturan & Kelola` | `MOVE` | Pemasangan printer QZ Tray dan aktivasi device kasir adalah administrasi hardware berkala. |
| 21 | Integrasi Channel | `integrations.channel-mapping` | `Pengaturan & Kelola` | `MOVE` | Pemetaan API saluran pihak ketiga adalah konfigurasi integrasi teknis. |
| 22 | Audit Log | `activity-logs.index` | `Pengaturan & Kelola` | `KEEP` | Rekam jejak audit keamanan dan aktivitas sistem multi-tenant. |
| 23 | Pengaturan | `settings.index` | Popover Profil Pengguna | `REMOVE DUPLICATE` | Dihapus dari deretan flat sidebar agar tidak redundan dengan link di menu profil bawah. |

---

## 8. Role, Permission, Tenant, and Store Context

### 8.1. Role-Based Navigation Matrix

| Fitur / Entry Point | Route Name | Owner | Manager | Kasir | Waiter | Barista | Cook | Inventory | Supervisor |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Dashboard** | `dashboard` | ✓ | ✓ | — | — | — | — | — | — |
| **Kasir (POS)** | `transaction.create` | ✓ | ✓ | ✓ | — | — | — | — | — |
| **Dapur (KDS)** | `kds.index` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| **Riwayat Pesanan** | `orders.index` | ✓ | ✓ | ✓ | ✓ | — | — | — | — |
| **Shift Kasir** | `shifts.index` | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| **Meja & QR** | `tables.index` | ✓ | ✓ | — | — | — | — | — | — |
| **Katalog Produk** | `products.index` | ✓ | ✓ | — | — | — | — | ✓ | — |
| **Inventori & Stok** | `inventory.ingredients` | ✓ | ✓ | — | — | — | — | ✓ | — |
| **Laporan Penjualan** | `reports.sales` | ✓ | ✓ | — | — | — | — | — | — |
| **Diskon & Promo** | `discounts.index` | ✓ | ✓ | — | — | — | — | — | — |
| **Cabang** | `stores.index` | ✓ | — | — | — | — | — | — | — |
| **Pengguna** | `users.index` | ✓ | — | — | — | — | — | — | — |
| **Peran** | `roles.index` | ✓ | — | — | — | — | — | — | — |
| **Perangkat POS** | `devices.index` | ✓ | ✓ | — | — | — | — | — | — |
| **Integrasi Channel** | `integrations.channel-mapping` | ✓ | ✓ | — | — | — | — | — | — |
| **Audit Log** | `activity-logs.index` | ✓ | ✓ | — | — | — | — | — | — |
| **Total Top-Level Items** | | **16** | **13** | **4** | **2** | **1** | **1** | **2** | **1** |

*Catatan Efisiensi*:
- Role `Kasir` kini hanya melihat 4 item relevan: POS, KDS, Pesanan, Shift.
- Role `Inventory` disajikan 2 entry point bersih: `Katalog Produk` (8 tab) dan `Inventori & Stok` (7 tab), mencakup 100% kebutuhan suplai & logistik tanpa polusi menu kasir/pengaturan.
- Role `Owner` berkurang dari 18 item menjadi 16 item yang terstruktur rapi, sembari membuka akses ke 5 workflow gudang Modul 4.

### 8.2. Multi-Tenancy and Store Context Boundaries
1. **Tenant Isolation Invariant**: Seluruh query data menu difilter ketat oleh `tenant_id`. Navigasi tidak boleh membocorkan nama toko, nama user, atau ringkasan badge milik tenant lain.
2. **Store Context Reflection**: Setiap item navigasi memiliki atribut metadata `'scope' => 'store'` atau `'scope' => 'tenant'`. Label indikator visual ("Cabang" vs "Tenant") ditampilkan pada hover di desktop untuk memberi pemahaman cakupan data yang sedang diubah.
3. **Cart Dirty Protection**: Setiap perpindahan cabang via `<x-branch-switcher>` atau klik navigasi via `wire:navigate` wajib melewati konfirmasi JavaScript:
   `window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')`.

---

## 9. Desktop, Mobile, and Responsive Behavior

### 9.1. Desktop Behavior (Viewport ≥ 1024px)
- **Expanded State**: Lebar sidebar `w-64` (256px). Menampilkan logo, nama brand, branch switcher lengkap, label section uppercase kecil (`text-[11px] font-semibold text-[#6B6A66]`), icon Material Symbols (20px), label item (`text-[13px]`), dan tombol minimize (`left_panel_close`).
- **Collapsed State**: Lebar sidebar `w-18` (72px). Menampilkan logo icon terpusat, item icon terpusat (40x40px). 
- **Collapsed Branch Context Indicator**: Ketika collapsed, render icon tombol `storefront` mini dengan status dot hijau/oranye dan tooltip instan Alpine.js yang menampilkan nama cabang aktif saat dihover, sehingga operator tetap mengetahui cabang yang aktif tanpa harus melebarkan sidebar.

### 9.2. Tablet Behavior (Viewport 768px – 1023px)
- Pada resolusi landscape kasir (misal iPad 10.2" atau Android POS 1280x800), sidebar secara default dapat berada dalam mode collapsed atau expanded sesuai preferensi localStorage.
- Seluruh baris navigasi memiliki padding vertikal dengan target sentuh minimum 40px pada desktop/pointer mouse dan 44px pada layar sentuh.

### 9.3. Mobile Drawer Behavior (Viewport < 768px)
- Sidebar bertransformasi menjadi off-canvas drawer (`fixed inset-y-0 left-0 z-40 w-72`).
- Ditutupi oleh backdrop overlay gelap (`bg-apeiron-ink/40 backdrop-blur-sm`).
- Menekan tombol escape (`@keydown.escape.window`) atau mengeklik area overlay menutup drawer dan mengembalikan fokus keyboard ke tombol pemicu (`#mobile-menu-btn`).
- Tab trapping aktif saat drawer terbuka untuk memastikan keyboard user tidak keluar ke konten latar belakang yang tidak terlihat.
- Menghapus duplikasi tombol burger di `dashboard.blade.php` sehingga tombol mobile global di sudut kiri atas menjadi satu-satunya pemicu drawer.

---

## 10. Active State, Breadcrumb, Search, and State Persistence

### 10.1. Declarative Active State Engine
Hindari string URL matching ad-hoc di file Blade. Seluruh pencocokan route dideklarasikan di array `active` pada `config/navigation.php`:

```php
// config/navigation.php
'Suplai & Inventori' => [
    [
        'label' => 'Katalog Produk',
        'icon' => 'inventory_2',
        'route' => 'products.index',
        'active' => [
            'products.*',
            'categories.*',
            'brands.*',
            'units-of-measure.*',
            'inventory.modifiers',
            'tax-classes.*',
            'assortments.*',
            'price-lists.*',
        ],
        'roles' => ['Owner', 'Manager', 'Inventory'],
        'scope' => 'tenant',
    ],
    [
        'label' => 'Inventori & Stok',
        'icon' => 'warehouse',
        'route' => 'inventory.ingredients',
        'active' => [
            'inventory.ingredients',
            'inventory.ledger',
            'inventory.goods-receipts',
            'inventory.adjustments',
            'inventory.opnames',
            'inventory.transfers',
            'inventory.supplier-returns',
        ],
        'roles' => ['Owner', 'Manager', 'Inventory'],
        'scope' => 'store',
    ],
],
```

Evaluasi di Blade:
```blade
@php
    $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
    $isActive = $routeExists && request()->routeIs(...((array) $item['active']));
@endphp
```

### 10.2. Contextual In-Page Sub-Navigation Component
Membuat partial blade baru: `resources/views/inventory/partials/sub-nav.blade.php`:

```blade
@php
    $roleName = auth()->user()?->role?->name;
    $allInventoryTabs = [
        [
            'label' => 'Bahan Baku',
            'route' => 'inventory.ingredients',
            'active' => 'inventory.ingredients',
            'icon' => 'egg_alt',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Penerimaan Barang',
            'route' => 'inventory.goods-receipts',
            'active' => 'inventory.goods-receipts',
            'icon' => 'input',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Penyesuaian Stok',
            'route' => 'inventory.adjustments',
            'active' => 'inventory.adjustments',
            'icon' => 'tune',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Stock Opname',
            'route' => 'inventory.opnames',
            'active' => 'inventory.opnames',
            'icon' => 'fact_check',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Transfer Antarcabang',
            'route' => 'inventory.transfers',
            'active' => 'inventory.transfers',
            'icon' => 'local_shipping',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Retur Supplier',
            'route' => 'inventory.supplier-returns',
            'active' => 'inventory.supplier-returns',
            'icon' => 'keyboard_return',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
        [
            'label' => 'Riwayat Mutasi',
            'route' => 'inventory.ledger',
            'active' => 'inventory.ledger',
            'icon' => 'history',
            'roles' => ['Owner', 'Manager', 'Inventory'],
        ],
    ];

    $visibleInventoryTabs = array_filter($allInventoryTabs, function ($tab) use ($roleName) {
        return in_array($roleName, $tab['roles'], true) && \Illuminate\Support\Facades\Route::has($tab['route']);
    });
@endphp

<nav aria-label="Sub-navigasi Manajemen Inventori" class="flex items-center gap-1.5 md:gap-2 overflow-x-auto pb-2 mb-6 border-b border-[#E9E9E7] no-scrollbar">
    @foreach ($visibleInventoryTabs as $tab)
        @php
            $isActive = request()->routeIs($tab['active']);
        @endphp
        <a href="{{ route($tab['route']) }}" wire:navigate
            @click="if (window.apeironPosCartDirty && !confirm('Anda memiliki pesanan aktif di kasir. Pindah halaman akan membatalkan keranjang aktif. Lanjutkan?')) { $event.preventDefault(); return false; } else if (window.apeironPosCartDirty) { window.apeironPosCartDirty = false; }"
            @if ($isActive) aria-current="page" @endif
            class="inline-flex items-center gap-2 px-3.5 py-2 min-h-[40px] rounded-xl text-xs font-semibold whitespace-nowrap transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-apeiron-ink {{ $isActive ? 'bg-[#202020] text-white shadow-xs font-bold' : 'text-[#787774] hover:text-[#202020] hover:bg-[#F7F7F5]' }}">
            <span class="material-symbols-rounded text-[18px] shrink-0 {{ $isActive ? 'text-white' : 'text-[#787774]' }}">{{ $tab['icon'] }}</span>
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
</nav>
```

Setiap view manager Modul 4 (`ingredient-manager.blade.php`, `goods-receipt-manager.blade.php`, `stock-adjustment-manager.blade.php`, `stock-opname-manager.blade.php`, `stock-transfer-manager.blade.php`, `supplier-return-manager.blade.php`, `ingredient-ledger.blade.php`) menyertakan `@include('inventory.partials.sub-nav')` di bagian atasnya.

### 10.3. State Persistence
- Status collapse desktop disimpan di `localStorage` dengan key `sidebarExpanded`.
- Nilai diinisialisasi secara aman (`localStorage.getItem('sidebarExpanded') !== 'false'`).
- Penyimpanan client-side ini murni kosmetik presentasi dan tidak pernah mencemari session server atau context isolation multi-tenant.

---

## 11. Visual Design Specification

Sistem navigasi tunduk pada design token Tailwind v4 dari `resources/css/app.css`:

| Token / Atribut | Nilai CSS Aktual | Penerapan Navigasi |
|---|---|---|
| **Font Family** | `Plus Jakarta Sans`, sans-serif | Tipografi seluruh teks label navigasi dan section header. |
| **Brand Font** | `Playfair Display`, serif | Tipografi logo "Apeiron POS" pada header sidebar. |
| **Background Sidebar** | `#ffffff` (`--color-apeiron-sidebar`) | Latar belakang panel navigasi desktop dan mobile drawer. |
| **Batas Panel** | `1px solid #F1F1EF` | Garis pemisah header logo, branch switcher, dan footer profil. |
| **Section Label** | `text-[11px] font-semibold text-[#6B6A66] tracking-[0.02em] uppercase` | Penanda grup navigasi; kontras 5.1:1 memenuhi WCAG AA. |
| **Active Item Background** | `#F1F1EF` (`--color-primary-100`) | Latar belakang baris menu yang sedang aktif. |
| **Active Item Indicator** | `before:bg-[#202020] before:w-1 before:rounded-r` | Aksen garis hitam vertikal setebal 4px di sisi kiri item aktif. |
| **Active Item Text** | `#202020` (`--color-apeiron-ink`), `font-semibold` | Warna teks item terpilih; kontras 15.9:1. |
| **Inactive Item Text** | `#6B6A66` (`--color-apeiron-muted-700`) | Warna teks item normal; kontras 5.1:1. |
| **Inactive Item Hover** | `bg-[#F7F7F5] text-[#202020]` | Efek hover halus responsif saat kursor melintas. |
| **Corner Radius** | `rounded-xl` (12px) | Lengkungan sudut untuk button item dan dropdown menu. |
| **Icon Metrics** | `Material Symbols Rounded`, `text-[20px]` | Ukuran ikon terpusat optikal di sisi kiri teks. |

---

## 12. Accessibility Requirements (WCAG 2.1 AA)

1. **Semantic Landmarks**:
   - Layout dibungkus dalam `<aside id="main-sidebar" role="region" aria-label="Sidebar Navigasi Utama">`.
   - Daftar tautan berada di dalam `<nav aria-label="Navigasi utama">` dengan struktur list semantik `<ul>` dan `<li>`.
2. **Current Page Semantics**:
   - Tautan halaman aktif ditandai secara eksplisit dengan atribut `aria-current="page"`.
3. **Touch Targets & Focus Rings**:
   - Seluruh elemen interaktif memiliki minimum touch target 40px di desktop dan 44px di perangkat sentuh/mobile (`min-h-[44px]`).
   - Indikator fokus keyboard terstandarisasi: `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F]`.
4. **Contrast Compliance**:
   - Seluruh teks label navigasi menggunakan minimal `#6B6A66` (rasio kontras 5.1:1 terhadap latar `#ffffff`), melampaui ambang batas minimum WCAG AA 4.5:1 untuk teks berukuran 13px.
5. **Keyboard & Screen Reader Trapping**:
   - Drawer mobile menyertakan keyboard listener untuk tombol `Escape` dan navigasi `Tab`/`Shift+Tab` siklik.

---

## 13. Route and Component Compatibility

Implementasi rencana ini mematuhi kontrak arsitektur sistem yang tidak dapat dinegosiasikan:
1. **Tidak Ada Perubahan Route Name atau URL**: Semua named route (`products.index`, `inventory.ingredients`, `inventory.goods-receipts`, `transaction.create`, dll.) dipertahankan 100%. Deep links dan bookmark kasir tetap valid.
2. **Tidak Ada Modifikasi Domain Entities**: Tidak membuat route dummy, tidak mengubah skema database, dan tidak menyentuh database triggers/invariants ledger.
3. **Livewire & Alpine Stability**: Tetap memanfaatkan `@livewireScripts` dan `wire:navigate` tanpa menginjeksi Alpine CDN ganda.
4. **Cart Dirty Hook**: Melindungi keranjang POS aktif pada setiap event klik navigasi.

---

## 14. Implementation Increments

Rencana implementasi dibagi menjadi 6 increment atomik yang dapat diverifikasi secara independen:

### Increment 1: Inventory Contextual Sub-Nav Partial Creation
- **ID**: `INC-NAV-01`
- **Tujuan**: Menyediakan partial in-page sub-navigation bar untuk Modul 4 (`resources/views/inventory/partials/sub-nav.blade.php`).
- **Prekondisi**: Working tree clean; route `inventory.*` terkonfirmasi di `routes/inventory.php`.
- **Scope In**: Pembuatan file view blade `resources/views/inventory/partials/sub-nav.blade.php`.
- **Scope Out**: Belum menyentuh `config/navigation.php` atau layout sidebar.
- **Affected Files**: `resources/views/inventory/partials/sub-nav.blade.php`.
- **Kriteria Penerimaan**: File partial terbuat dengan 7 tab inventori, pengecekan role yang tepat, atribut WCAG AA, dan proteksi `window.apeironPosCartDirty`.
- **Test Wajib**: `php artisan test tests/Feature/Navigation/` (memastikan tidak ada syntax error).

### Increment 2: Inventory Views Sub-Nav Integration
- **ID**: `INC-NAV-02`
- **Tujuan**: Menyematkan `@include('inventory.partials.sub-nav')` ke 7 view Livewire komponen inventori Modul 4.
- **Prekondisi**: `INC-NAV-01` selesai dan lulus validasi.
- **Scope In**: Penyertaan baris include di bagian atas kartu/konten view inventori.
- **Scope Out**: Tidak mengubah logika Livewire manager atau method PHP di baliknya.
- **Affected Files**:
  - `resources/views/livewire/inventory/ingredient-manager.blade.php`
  - `resources/views/livewire/inventory/ingredient-ledger.blade.php`
  - `resources/views/livewire/inventory/goods-receipt-manager.blade.php`
  - `resources/views/livewire/inventory/stock-adjustment-manager.blade.php`
  - `resources/views/livewire/inventory/stock-opname-manager.blade.php`
  - `resources/views/livewire/inventory/stock-transfer-manager.blade.php`
  - `resources/views/livewire/inventory/supplier-return-manager.blade.php`
- **Kriteria Penerimaan**: Saat mengunjungi route `inventory.goods-receipts`, `inventory.opnames`, dll., bilah tab sub-navigasi inventori tampil rapi dengan tab aktif yang sesuai.
- **Test Wajib**: Pengujian render HTTP status 200 untuk seluruh endpoint inventori bagi role Owner, Manager, dan Inventory.

### Increment 3: Navigation Configuration Taxonomy Restructuring
- **ID**: `INC-NAV-03`
- **Tujuan**: Merestrukturisasi array `sections` pada `config/navigation.php` menjadi 5 Pilar Operasional dan mendaftarkan pola route `active` lengkap.
- **Prekondisi**: `INC-NAV-02` selesai.
- **Scope In**: Pembaruan struktur array pada `config/navigation.php`.
- **Scope Out**: Belum mengubah `navigation.blade.php` atau test assertion.
- **Affected Files**: `config/navigation.php`.
- **Kriteria Penerimaan**: Array `sections` berisi 5 grup: `Pusat Kerja`, `Operasional Toko`, `Suplai & Inventori`, `Analitik & Penjualan`, `Pengaturan & Kelola`.
- **Risiko**: `SidebarIntegrityTest` akan fail sementara sampai test disesuaikan di Increment 5.

### Increment 4: Sidebar Layout Refinement & Collapsed Branch Indicator
- **ID**: `INC-NAV-04`
- **Tujuan**: Memperbarui `resources/views/layouts/navigation.blade.php` untuk merender taksonomi baru secara murni deklaratif, menambahkan indikator cabang mini pada collapsed state, dan menghapus tombol burger redundan di `dashboard.blade.php`.
- **Prekondisi**: `INC-NAV-03` selesai.
- **Scope In**:
  - Pembersihan conditional hardcoded pattern di `layouts/navigation.blade.php`.
  - Penambahan collapsed mini branch indicator icon.
  - Penghapusan duplicate burger button di `resources/views/dashboard.blade.php`.
- **Scope Out**: Tidak mengubah layout aplikasi dasar selain navigasi.
- **Affected Files**:
  - `resources/views/layouts/navigation.blade.php`
  - `resources/views/dashboard.blade.php`
- **Kriteria Penerimaan**: Sidebar merender 5 grup baru dengan benar; active state menyala akurat saat berada di sub-page inventori; drawer mobile berfungsi tanpa tombol ganda di dashboard.

### Increment 5: Navigation Test Suite Synchronization & Expansion
- **ID**: `INC-NAV-05`
- **Tujuan**: Memperbarui dan memperluas assertion di `tests/Feature/Navigation/SidebarIntegrityTest.php` serta `Module3NavigationTest.php` untuk memvalidasi 5 pilar baru dan akses Modul 4.
- **Prekondisi**: `INC-NAV-04` selesai.
- **Scope In**:
  - Penyesuaian `expectedGroups` menjadi 5 pilar.
  - Penyesuaian expected visible route counts (Owner: 16, Manager: 12, Kasir: 4, Waiter: 2, Inventory: 2).
  - Penambahan test case untuk navigasi Modul 4 dan verifikasi rendering sub-navigasi inventori.
- **Scope Out**: Tidak mengubah test domain lain di luar navigasi.
- **Affected Files**:
  - `tests/Feature/Navigation/SidebarIntegrityTest.php`
- **Kriteria Penerimaan**: Seluruh test case navigasi lulus 100% dengan green status.

### Increment 6: Full Regression Verification & A11y Audit
- **ID**: `INC-NAV-06`
- **Tujuan**: Menjalankan seluruh test suite fitur terkait untuk memastikan nol regresi pada alur kasir, otorisasi peran, dan isolasi tenant.
- **Prekondisi**: `INC-NAV-05` lulus.
- **Scope In**: Menjalankan automated test suite `tests/Feature/Navigation/`, `tests/Feature/Inventory/`, `tests/Feature/Domain2/`, `tests/Feature/Domain3/`.
- **Kriteria Penerimaan**: 100% test lulus tanpa kegagalan.

---

## 15. Test and Regression Strategy

### 15.1. Automated Test Cases yang Wajib Ada
1. **`test_all_routes_referenced_by_sidebar_are_registered`**: Memastikan tidak ada route typo atau tak terdaftar di `config/navigation.php`.
2. **`test_role_list_in_sidebar_matches_role_middleware_on_target_route`**: Memastikan tidak ada disparitas izin antara menu item dan middleware `role:` pada route.
3. **`test_navigation_sections_contain_expected_five_pillars_and_valid_scope`**: Memverifikasi keberadaan tepat 5 pilar navigasi baru dan validitas metadata scope (`store` vs `tenant`).
4. **`test_role_based_menu_filtering_for_all_supported_roles`**: Memvalidasi jumlah dan isi menu persis untuk Owner (16), Manager (12), Kasir (4), Waiter (2), Inventory (2), Barista (1), Cook (1).
5. **`test_inventory_sub_nav_renders_all_module_4_workflow_tabs`**: Memverifikasi bahwa ketika membuka halaman inventori, partial sub-nav menampilkan 7 tab lengkap.
6. **`test_pos_cart_protection_and_branch_switcher_guards_present`**: Memverifikasi penjagaan `window.apeironPosCartDirty` tidak terhapus dari layout navigasi.

### 15.2. Manual Responsive & A11y Verification Matrix
- [ ] Buka halaman dashboard pada viewport desktop 1440x900: periksa kerapian 5 pilar dan kontras teks `#6B6A66`.
- [ ] Klik tombol minimize sidebar (`expanded = false`): pastikan sidebar mengecil ke `w-18`, icon terpusat, dan icon indikator cabang mini tetap terlihat dengan tooltip.
- [ ] Buka viewport tablet 1024x768: pastikan menu POS Kasir dan KDS berada di atas tanpa perlu menggulir ke bawah.
- [ ] Buka viewport mobile 375x667 (iPhone SE): periksa bahwa hanya ada 1 tombol hamburger di kiri atas, klik untuk membuka drawer, periksa bahwa tombol ESC menutup drawer dan mengembalikan fokus.
- [ ] Navigasi keyboard penuh menggunakan `Tab` dan `Shift+Tab`: periksa cincin fokus `focus-visible:ring-2 focus-visible:ring-[#37352F]`.

---

## 16. Acceptance Criteria and Success Metrics

### 16.1. Objective Metrics (Baseline vs Target)

| Metrik Navigasi | Baseline Saat Ini | Target Implementasi Baru | Evaluasi Keberhasilan |
|---|---|---|---|
| **Jumlah Section Grup Sidebar** | 6 section | **5 pilar operasional** | -16.7% kompleksitas pengelompokan |
| **Jumlah Top-Level Item (Owner)** | 18 item flat | **16 item terstruktur** | Berkurang 2 item sembari membuka 5 fitur gudang |
| **Jumlah Top-Level Item (Kasir)** | 4 item (terpencar ke section 2, 4, 6) | **4 item terfokus** (di section 1 & 2) | Akses instan workstation tanpa scroll |
| **Jumlah Top-Level Item (Inventory)** | 3 item (tanpa akses modul 4) | **2 item hub komprehensif** | Akses ke 15 layar logistik (8 catalog + 7 inventory) |
| **Aksesibilitas Modul 4** | 0 dari 5 workflow terdaftar | **5 dari 5 workflow terhubung** (100%) | Nol alur gudang yang orphaned |
| **Duplikasi Menu Pengaturan** | 2 lokasi | **1 lokasi utama** (di user popover) | Nol duplikasi navigasi |
| **Passing Test Suite Navigasi** | 12 tests (baseline lama) | **≥ 14 tests (seluruh 5 pilar & subnav)** | 100% test lulus hijau |

---

## 17. Risk Register and Rollback Plan

### 17.1. Risk Register

| Risk ID | Deskripsi Risiko | Tingkat Dampak | Probabilitas | Rencana Mitigasi |
|---|---|---|---|---|
| **RSK-01** | `SidebarIntegrityTest` gagal akibat perubahan nama section dan jumlah menu item. | Tinggi | Pasti | Lakukan pembaruan atomik pada test class di `INC-NAV-05` yang mencerminkan kontrak baru secara tepat. |
| **RSK-02** | Pengguna kehilangan lokasi `Riwayat Stok` karena dipindahkan ke tab sub-navigasi. | Sedang | Rendah | Tab "Riwayat Mutasi" diberi label jelas dengan ikon `history` yang sama dan otomatis aktif saat dibuka. |
| **RSK-03** | Inkonsistensi active state saat membuka child route (cth: edit produk atau detail goods receipt). | Sedang | Rendah | Menggunakan wildcard pattern deklaratif (`products.*`, `inventory.goods-receipts.*`) pada array `active`. |
| **RSK-04** | Kehilangan keranjang aktif kasir akibat penghapusan guard dirty cart secara tidak sengaja. | Kritis | Sangat Rendah | Assertion otomatis di `test_pos_cart_protection_and_branch_switcher_guards_present` memverifikasi string guard secara mutlak. |

### 17.2. Rollback Plan
Apabila terjadi regresi kritis yang tidak dapat diselesaikan dalam 1 increment:
1. `git restore config/navigation.php resources/views/layouts/navigation.blade.php resources/views/dashboard.blade.php`
2. Hapus file `resources/views/inventory/partials/sub-nav.blade.php`
3. Kembalikan perubahan pada view livewire inventori via `git checkout resources/views/livewire/inventory/`
4. Jalankan `php artisan test tests/Feature/Navigation/` untuk memastikan status kembali ke green baseline commit `a4518d6`.

---

## 18. Out-of-Scope Items

Hal-hal berikut secara tegas berada di luar cakupan pekerjaan navigasi ini:
1. Mengubah business logic, model, database migrations, atau service layer dari Modul 1 sampai Modul 4.
2. Mengimplementasikan fitur atau halaman baru untuk Modul 5 (Purchasing) sampai Modul 13.
3. Mengubah skema otorisasi RBAC atau nama-nama peran yang sudah tersimpan di database.
4. Mendesain ulang tata letak halaman internal di luar penyematan sub-navigasi bar.
5. Menghapus route atau mengubah endpoint URL yang sudah berjalan.

---

## 19. Implementation Instructions for Gemini IDE

Instruksi kerja wajib bagi Gemini IDE saat mengeksekusi rencana ini:

1. **Baca Dokumen Ini Terlebih Dahulu**: Wajib membaca dan mematuhi seluruh spesifikasi di `docs/implementationPlanSidebarNavigation.md` sebelum menyentuh file kode apapun.
2. **Verifikasi Repository Awal**: Pastikan working tree bersih dan jalankan `php artisan test tests/Feature/Navigation/` sebagai verifikasi dasar sebelum memulai.
3. **Eksekusi Bertahap (Increment by Increment)**: Kerjakan hanya increment yang sedang diinstruksikan. Jangan menggabungkan seluruh perubahan ke dalam satu commit raksasa.
4. **Pertahankan Invariant Keamanan**:
   - DILARANG menghapus pengecekan `window.apeironPosCartDirty`.
   - DILARANG membocorkan data lintas tenant pada navigasi.
   - DILARANG menghapus proteksi middleware `role:` pada route.
5. **Pertahankan Backward Compatibility**: Jangan mengganti nama route atau menghapus route yang sah.
6. **Jalankan Test dan Laporkan Bukti**: Setelah setiap increment, jalankan test suite yang relevan dan laporkan hasil aktual pengujian (jumlah test, assertions, dan status pass/fail).
7. **Berhenti Jika Ada Konflik**: Jika ditemukan kontradiksi antara rencana ini dan kondisi aktual kode yang belum teridentifikasi, hentikan eksekusi dan minta arahan Principal System Architect.

---

## 20. Final Readiness Verdict

**Status: `APPROVED & IMPLEMENTED`**

*Justifikasi*:
Rencana arsitektur navigasi ini telah didasarkan 100% pada bukti aktual repository Apeiron POS pada commit `a4518d6aa3999c2f500b1b5679091967cff8adbf`. Rencana ini menyelesaikan akar masalah kelebihan beban kognitif sidebar, memulihkan 5 alur pergudangan Modul 4 yang sebelumnya terisolasi, mengadopsi pola enterprise sub-navigasi yang konsisten, mempertahankan proteksi kasir, dan menyediakan 6 increment implementasi konkret yang dapat diverifikasi secara aman tanpa risiko regresi sistem.
