# ENTERPRISE CAPABILITY GAP MATRIX
## Apeiron Systems vs Global ERP Benchmarks (SAP, Odoo, Dynamics 365, Oracle NetSuite)

- **Auditor:** A0 — Principal Enterprise Architect & Systems Auditor
- **Target:** Conceptual Industry Benchmarking across 16 Enterprise ERP Capabilities
- **Reference Benchmarks:** SAP S/4HANA (F&B/Retail), Odoo Enterprise (v17/v18), Microsoft Dynamics 365 Commerce, Oracle NetSuite
- **Industry Specialization:** Food & Beverage (F&B), Restaurant Operations, Café, Bakery, Cloud Kitchen, Retail & Omnichannel
- **Audit Date:** 1 November 2026
- **Status:** **FINAL MATRIX**

---

## 1. COMPREHENSIVE CAPABILITY GAP MATRIX

| No | Enterprise Capability Category | Status Aktual Apeiron | Benchmark Enterprise Standard (SAP / Odoo / Dynamics / NetSuite) | Evaluasi Implementasi Aktual & Kesenjangan Arsitektural | Justifikasi Bisnis Vertikal F&B / Retail |
|:---:|---|:---:|---|---|---|
| **1** | **ERP Modularity & Bounded Contexts** | **Partially Ready** | Fully decoupled modules with published interfaces, versioned contracts, and independent testability | Modul dikelompokkan dalam folder `app/Modules/`, namun hanya memiliki 2 interface contracts dan saling panggil kelas konkret secara langsung. | Menjamin modularitas sistem saat tenant F&B hanya berlangganan modul POS + Kasir, sedangkan enterprise ritel menggunakan seluruh modul akuntansi dan procurement. |
| **2** | **Financial Accounting & Double-Entry** | **Implemented but Incomplete** | Perpetual double-entry GL, automated AP/AR subledgers, multi-currency, tax engine, bank reconciliation | Double-entry engine dan trigger imutabilitas sangat kuat; namun worker outbox AP/AR belum di-wiring di scheduler, dan 10 policy finansial memiliki bug sintaks. | Kepatuhan hukum perpajakan Indonesia, audit keuangan tahunan, dan akurasi laba-rugi operasional per outlet restoran. |
| **3** | **Procurement & Supplier Management (P2P)** | **Implemented & Verified** | Full Procure-to-Pay lifecycle (PR -> PO -> GRN -> 3-Way Matching -> Debit Notes -> Landed Cost) | Sangat lengkap dan matang. Didukung oleh alur persetujuan bertingkat, verifikasi 3-Way Matching otomatis, dan alokasi landed cost. | Menjaga efisiensi biaya pembelian bahan baku restoran dari pemasok dan mencegah kecurangan penerimaan barang (*leakage*). |
| **4** | **Inventory Management & Valuasi** | **Implemented but Incomplete** | Perpetual inventory ledger, FIFO/moving avg cost layers, multi-warehouse, non-negative inventory rules | `InventoryLedgerService` memiliki 8 invariants canggih; namun kasir POS masih memotong stok lewat jalur legacy manual. | Mencegah kehabisan stok bahan baku (*stock-out*) pada jam sibuk kafe dan mengontrol modal kerja persediaan barang dagangan ritel. |
| **5** | **Manufacturing & Food Production** | **Partially Ready** | Multi-level BOM, recipe versioning, batch preparation work orders, yield shrinkage, disassembly | BOM produk & modifier tersedia, namun belum ada versioning resep, belum ada dokumen batch prep kaldu/saus, dan belum ada yield loss. | Esensial bagi restoran dengan Dapur Pusat (*Central Kitchen*) yang memasak bahan setengah jadi sebelum dikirim ke cabang-cabang. |
| **6** | **Supply Chain & Multi-Branch Transfers** | **Implemented & Verified** | Two-phase stock transfers (Ship -> In-Transit -> Receive with discrepancy resolution) | Alur transfer antarcabang 2-fase sangat kokoh; hanya membutuhkan perbaikan konkurensi pada generator nomor transfer draft. | Distribusi bahan baku dari gudang pusat ke puluhan cabang gerai restoran atau toko ritel. |
| **7** | **Multi-Company & Holding Governance** | **Not Implemented** | Multi-tier corporate hierarchy (Holding -> Multi-Entity Subsidiaries -> Branches) with intercompany eliminations | Sistem saat ini hanya mendukung 2 tingkat hierarki logis: `Tenant (1 Company) -> Store (Branches)`. Belum ada entitas PT/Legal Entity anak. | Kebutuhan grup korporat F&B yang menaungi beberapa brand restoran dengan badan hukum PT terpisah di bawah satu manajemen holding. |
| **8** | **Multi-Location Operations & POS** | **Implemented & Verified** | Unified multi-outlet POS, table management, KDS, shift reconciliation, local price lists | Kasir sangat cepat dan ergonomis; denah meja visual dan KDS kanban tersedia; shift kasir akuntabel dengan blind cash count. | Operasional harian frontline kasir, barista, pelayan meja, dan koki dapur di gerai restoran. |
| **9** | **Workflows & Approvals Governance** | **Implemented & Verified** | Multi-level approval matrix, separation of duties, delegation, escalation rules | Tersedia di Modul Purchasing (`PurchaseApprovalService`) dan Modul Pricing (`DiscountApprovalService`). | Mencegah kasir memberikan diskon sewenang-wenang dan memastikan pembelian barang bernilai besar disetujui oleh Store/General Manager. |
| **10** | **Reporting & Business Analytics** | **Implemented & Verified** | Real-time KPI dashboard, sales trends histogram, product velocity, async export queues | `DashboardService` memiliki query agregasi performa tinggi dengan caching Redis berskala tenant, dan ekspor asinkron. | Memberikan visibilitas harian omzet, laba kotor, dan kecepatan perputaran menu makanan kepada pemilik bisnis (*owner*). |
| **11** | **Integration Architecture & APIs** | **Partially Ready** | Public OpenAPI REST/GraphQL suite, secure webhooks, partner app store, POS hardware drivers | Driver thermal printer ESC/POS dan webhook Midtrans sangat solid, namun surface REST API publik untuk mitra/e-commerce belum ada. | Menghubungkan restoran dengan platform pemesanan online (GrabFood/GoFood) dan marketplace e-commerce omnichannel. |
| **12** | **Security, Multi-Tenancy & Privacy** | **Implemented & Verified** | Tenant isolation at database engine, encrypted statutory PII, 2FA TOTP, RBAC | Isolasi fail-closed, proteksi foreign key RESTRICT, dan enkripsi NIK/NPWP/Bank/BPJS karyawan memenuhi standar UU PDP. | Menjaga kerahasiaan data resep, data finansial bisnis, dan data pribadi karyawan dari kebocoran lintas tenant. |
| **13** | **Extensibility & Customization** | **Partially Ready** | Plugin architecture, custom fields, scriptable business logic, workflow hooks | Menggunakan konfigurasi dinamis JSON (`permissions`, `posting_rules`, `promotions`), namun belum ada arsitektur plugin modular. | Fleksibilitas kustomisasi aturan operasional restoran tanpa merusak kode inti sistem (*core system*). |
| **14** | **Scalability & Concurrency Reliability** | **Implemented but Incomplete** | High-throughput distributed workers, optimistic locks, lock ordering anti-deadlock, fair scheduling | Lock ordering kanonis anti-deadlock terbukti sangat tangguh; namun worker outbox job membutuhkan perbaikan batas transaksi lock. | Menjamin sistem tidak hang atau mengalami selisih stok saat ratusan cabang melakukan transaksi serentak pada festival diskon. |
| **15** | **Upgradeability & Backward Compatibility** | **Partially Ready** | Zero-downtime database migrations, deprecation lifecycles, non-breaking schema evolution | Migrasi MariaDB idempotent dan aman di-rollback; namun masih menyisakan 48 file alias legacy di root `app/Services/`. | Pembaruan sistem SaaS secara berkala tanpa mengganggu jam buka restoran dan tanpa merusak data historis. |
| **16** | **Operational Reliability & Resilience** | **Partially Ready** | Offline-first POS edge sync, automated PITR disaster recovery, structured tracing | Pencadangan harian aktif; namun ketiadaan offline mode pada POS dan ketiadaan structured JSON logging menjadi kesenjangan operasional. | Kelangsungan bisnis kasir restoran saat koneksi internet lokal putus dan kecepatan investigasi insiden teknis di cloud. |

---

## 2. REKAPITULASI KLASIFIKASI KEMATANGAN ENTERPRISE

- **Implemented & Verified (Kematangan Tinggi):** 6 Kapabilitas (37.5%)
  - Procurement (P2P), Multi-Branch Transfers, Multi-Location POS, Workflows & Approvals, Reporting Analytics, Security & Multi-Tenancy.
- **Implemented but Incomplete (Membutuhkan Remediasi Segera):** 3 Kapabilitas (18.75%)
  - Financial Accounting, Inventory Management, Scalability & Concurrency.
- **Partially Ready (Fondasi Siap, Butuh Penyempurnaan):** 5 Kapabilitas (31.25%)
  - ERP Modularity, Manufacturing/Production, Integration Architecture, Extensibility, Operational Resilience.
- **Not Implemented (Roadmap Masa Depan):** 2 Kapabilitas (12.50%)
  - Multi-Company Holding Hierarchy, Offline POS PWA Mode.

---

## 3. KESIMPULAN GAP ANALYSIS
Platform Apeiron Systems telah melampaui fase "aplikasi kasir biasa" dan secara faktual memiliki **lebih dari 56% kapabilitas enterprise ERP global yang sudah diimplementasikan atau memiliki fondasi arsitektur yang kokoh**. Kesenjangan yang ada bukan merupakan cacat desain fatal, melainkan tahap evolusi yang membutuhkan remediasi terfokus pada konsistensi orkestrasi backend.
