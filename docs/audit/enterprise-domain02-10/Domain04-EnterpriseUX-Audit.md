# DOMAIN 04 — ENTERPRISE UX & INDUSTRY WORKFLOW DESIGN AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & ERP Usability Auditor
- **Audit Target:** Role-Based Workspaces, Frontline Operational Ergonomics, F&B & Retail Vertical Workflows, Multi-Branch Operations, Exception Handling UX, and Accessibility
- **Scope:** Frontline POS Terminal, Kitchen Display System (KDS), Table Management, Procurement, Inventory, Finance Workbench, HR Workspaces, and Navigation Architecture
- **Audit Date:** 1 November 2026
- **Status:** **PASS WITH CONDITIONS**
- **Confidence:** **HIGH** (Empirically verified via blade views, Livewire interaction flows, and operational role simulations)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Apeiron Systems menunjukkan pemahaman vertikal (*domain knowledge*) yang sangat mendalam terhadap kebutuhan industri ritel dan restoran di Indonesia. Desain antarmuka melampaui aplikasi POS generik:
- **POS Kasir Cepat & Ergonomis:** Tata letak modular dengan dukungan split payment multi-tender, hold/park cart, modal modifier F&B, dan pencarian produk instan.
- **Visual Table & Room Management:** Denah meja interaktif untuk dine-in restoran dengan status real-time (Kosong, Terisi, Menunggu Pembayaran).
- **Kitchen Display System (KDS):** Antarmuka kanban dapur real-time berbasis timer warna (kuning, oranye, merah saat pesanan terlambat).
- **Lengkapnya Workspace Manajerial:** Ketersediaan antarmuka workbench untuk Jurnal Akuntansi, Rekonsiliasi Bank, Penjadwalan Shift Karyawan, dan Verifikasi Faktur Pemasok (3-Way Matching).

Meskipun demikian, evaluasi terhadap standar benchmark enterprise (seperti Toast POS, Lightspeed, Odoo Point of Sale, dan Oracle Simphony) mengidentifikasi beberapa kesenjangan workflow penting:
1. **Ketiadaan Mode Operasi Offline (Offline-First Resiliency):** Terminal kasir 100% bergantung pada koneksi internet real-time melalui websocket/HTTP round-trip Livewire. Jika jaringan Wi-Fi outlet terputus, operasional kasir berhenti total.
2. **Ketiadaan Routing Stasiun Dapur Multipel (Multi-Station KDS Routing):** Tiket dapur belum dapat dipisahkan secara otomatis berdasarkan stasiun kerja (misalnya Stasiun Bar/Minuman, Stasiun Dapur Panas/Kitchen, dan Stasiun Dessert/Pastry).
3. **Ketiadaan Kokpit Beranda Khusus Per Peran (Role-Tailored Operational Dashboards):** Seluruh pengguna (Kasir, Akuntan, Petugas Gudang, HR) diarahkan ke navigasi umum yang sama, alih-alih memiliki beranda operasional berbasis peran (*Role-Based Cockpit*).

**Domain 4 Verdict:** **`PASS WITH CONDITIONS`**

---

## 2. EVALUASI WORKFLOW PER PERAN OPERASIONAL

### 2.1 Frontline Cashier & Waiter (Modul 2 POS & Kasir)
- **Ergonomi Kasir:** Tombol angka besar, kalkulator kembalian otomatis, tombol cepat pecahan uang tunai (Uang Pas, 50.000, 100.000).
- **Split Payment & Multi-Tender:** Sangat baik. Mendukung pembayaran terpisah dalam satu transaksi (misal: sebagian tunai, sebagian QRIS, atau kombinasi gift card dan kartu debit).
- **Hold & Park Order:** Sangat membantu kasir saat antrean padat ketika pelanggan ingin menambah pesanan atau mengambil barang lain.
- **Workflow Meja & Self-Order:** Dukungan kode QR meja pelanggan yang langsung terhubung ke sistem kasir untuk pembayaran.

### 2.2 Kitchen Staff & Barista (KDS Board)
- **Visualisasi Tiket:** Menampilkan detail pesanan item, modifier khusus (misal: "Less Sugar", "Extra Shot"), tipe pesanan (Dine-in / Takeaway), dan waktu berlalu (*elapsed time*).
- **Keterbatasan Workflow:** Belum ada fitur *Course Firing* (menahan piring utama hingga hidangan pembuka selesai) dan belum ada pembagian tiket per stasiun dapur (*station-based ticket filtering*).

### 2.3 Store & Outlet Manager (Shift & Kas)
- **Penutupan Shift (Shift Handover):** Dilengkapi penghitungan fisik uang laci (*blind cash count*), pencatatan selisih kas (*cash overage/shortage*), dan mutasi kas kecil (*cash drop/expense*).
- **Otorisasi Supervisor:** Modal persetujuan diskon manual atau void order telah dirancang, namun memerlukan perbaikan keamanan pada transmisi PIN (lihat Domain 3).

### 2.4 Warehouse & Inventory Controller (Modul 4 Inventori)
- **Alur Stock Opname Fisik:** Sangat terstruktur dengan status Draft -> Ongoing -> Count Submitted -> Reconciled. Menghitung otomatis variance kuantitas dan nilai finansial selisih.
- **Transfer Antarcabang (2-Phase Transfer):** Alur kirim (*ship*) dan terima (*receive*) antarcabang mencakup penanganan selisih barang rusak/hilang di perjalanan (*discrepancy resolution*).

### 2.5 Finance Officer & Accountant (Modul 7 Akuntansi)
- **Workbench Jurnal Interaktif:** Memungkinkan pembuatan jurnal penyesuaian manual berimbang dengan validasi debit-kredit real-time di browser.
- **Rekonsiliasi Bank:** Menampilkan perbandingan mutasi rekening koran (*bank statement*) dengan buku bank internal.

---

## 3. DOMAIN 4 FINDINGS REGISTER

### FINDING-DOM04-01: Zero Offline Resilience for High-Volume POS Retail & Restaurant Operations
- **Domain:** Domain 4 — Enterprise UX & Operational Reliability
- **Severity:** **P2** (Material Operational Risk for F&B/Retail)
- **Confidence:** **HIGH** (Empirically verified: Livewire requires active server connection for state mutations)
- **File Path:** [`app/Livewire/Kasir/CreateOrder.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Kasir/CreateOrder.php)
- **Evidence:** Setiap klik penambahan item ke keranjang belanja, pengubahan kuantitas, atau submit pesanan mengirimkan request HTTP POST ke server `/livewire/update`. Tidak ada service worker, IndexedDB caching, atau local queue storage.
- **Actual Behavior:** Ketika koneksi internet terputus selama 10 detik, antarmuka kasir membeku (*hang*) dengan pesan koneksi terputus, menghentikan antrean kasir.
- **Expected Behavior:** Terminal POS enterprise F&B/Retail (seperti Toast, Lightspeed, Odoo POS) memiliki arsitektur *offline-first* lokal (via Web Storage / IndexedDB PWA) yang memungkinkan kasir tetap memindai produk, mencetak struk kasir, dan menampung transaksi tunai secara offline, lalu melakukan sinkronisasi otomatis (*auto-sync*) saat koneksi pulih.
- **Root Cause:** Pemilihan arsitektur Livewire murni server-driven tanpa hybrid offline caching layer.
- **Business Impact:** Kerugian finansial dan reputasi bagi outlet saat jaringan internet mal/ruko mengalami gangguan pada jam sibuk makan siang atau akhir pekan.
- **Recommendation:**
  1. Jangka Menengah (Roadmap F2): Bangun Progressive Web App (PWA) offline cashier mode dengan IndexedDB untuk pesanan tunai.
  2. Jangka Pendek: Tambahkan detektor status koneksi browser (`window.addEventListener('offline')`) dengan banner visual yang jelas agar kasir tidak melakukan klik ganda saat koneksi terputus.

---

### FINDING-DOM04-02: Absence of Multi-Station Kitchen Routing in KDS (Bar vs Hot Kitchen vs Dessert)
- **Domain:** Domain 4 — F&B Industry Workflow Design
- **Severity:** **P2** (Industry Vertical Workflow Gap)
- **Confidence:** **HIGH** (Empirically verified in `app/Livewire/Kds/Board.php`)
- **File Path:** [`app/Livewire/Kds/Board.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Kds/Board.php)
- **Evidence:** KDS Board mengambil seluruh pesanan aktif toko tanpa opsi filter berdasarkan kategori produk, stasiun cetak dapur, atau lokasi persiapan.
- **Actual Behavior:** Semua pesanan makanan dan minuman muncul bersamaan dalam satu layar KDS tunggal.
- **Expected Behavior:** Restoran skala menengah hingga besar memiliki minimal 2 stasiun terpisah: Barista/Bar (minuman) dan Kitchen (makanan). Layar KDS barista hanya boleh menampilkan item minuman, dan layar dapur hanya menampilkan makanan.
- **Root Cause:** Implementasi KDS tahap awal yang difokuskan pada outlet kafe satu konter.
- **Business Impact:** Kebingungan operasional staf dapur, staf barista harus menyaring secara manual item minuman di antara puluhan baris pesanan makanan, memperlambat kecepatan penyajian (*table turnover time*).
- **Recommendation:** Tambahkan atribut `kitchen_station` pada master `categories` atau `products`, dan sediakan filter stasiun pada `Kds/Board.php`.

---

## 4. CAPABILITY GAP MATRIX (ENTERPRISE WORKFLOWS)

| Operational Workflow | Status Aktual Apeiron | Benchmark Enterprise (Toast / Odoo / Oracle Simphony) | Gap Analysis & Kebutuhan Vertikal |
|---|---|---|---|
| **Dine-In Table Management** | **Implemented & Verified** | Interactive Floor Plan, Table Merging, Table Transfer | Denah meja 2D visual lengkap dengan status warna real-time. |
| **Kitchen Display System (KDS)** | **Implemented but Incomplete** | Multi-Station Routing, Bump Bar, Course Timing, Cook Time | KDS kanban tersedia, namun routing stasiun bar/kitchen belum dipisahkan. |
| **Cash Drawer Reconciliation** | **Implemented & Verified** | Blind Cash Count, Shift Variance Audit, Cash Drops | ShiftService dan modal penutupan kasir sangat lengkap dan akuntabel. |
| **Procurement 3-Way Matching** | **Implemented & Verified** | Automated PO vs GRN vs Supplier Invoice Discrepancy Tolerance | Sangat kokoh di backend dan frontend pengadaan barang. |
| **Offline POS Mode** | **Not Implemented** | Full Offline Queue with Automatic Background Conflict Resolution | Belum tersedia; bergantung penuh pada koneksi internet stabil. |
| **Multi-Branch Store Switching** | **Implemented & Verified** | Global Organization Hierarchy & Quick Branch Switcher | Dropdown cabang tersedia dengan scoping tenant context yang aman. |

---

## 5. VERDICT & CONDITIONS

Domain 4 dinyatakan **PASS WITH CONDITIONS**. Tata letak antarmuka, ergonomi kasir, dan kelengkapan visual workflow sangat superior untuk kategori ERP POS SaaS. 

**Conditions:**
1. Tambahkan indikator status koneksi internet (*Online/Offline Banner*) pada layar kasir untuk mencegah kebingungan saat jaringan drop.
2. Tambahkan penyaringan stasiun dapur (*Kitchen Station Routing*) pada KDS Board sebelum deployment ke restoran multi-stasiun.
