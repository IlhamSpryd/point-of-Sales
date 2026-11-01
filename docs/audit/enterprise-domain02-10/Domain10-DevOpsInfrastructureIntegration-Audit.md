# DOMAIN 10 — DEVOPS, INFRASTRUCTURE, INTEGRATION & DISASTER RECOVERY AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & DevOps Auditor
- **Audit Target:** CI/CD Pipelines, Database Migrations, Environment Parity, Hardware Integrations (Printers/Scanners/KDS), API Architecture, and Disaster Recovery (RPO/RTO)
- **Scope:** GitHub Actions (`tests.yml`), Dockerfile, Scheduler (`routes/console.php`), Hardware Drivers (`mike42/escpos-php`), and External API Surfaces (`routes/api.php`)
- **Audit Date:** 1 November 2026
- **Status:** **NEEDS REMEDIATION**
- **Confidence:** **HIGH** (Empirically verified via CI configuration, scheduler commands, and API route analysis)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Infrastruktur dan pipeline operasional Apeiron Systems menunjukkan disiplin rekayasa perangkat lunak yang matang:
- **CI/CD Quality Gate Komprehensif:** Pipeline GitHub Actions (`.github/workflows/tests.yml`) menerapkan verifikasi berlapis: linter (Laravel Pint), analisis statis (Larastan/PHPStan memory limit 1GB), smoke test migrasi + seeder, serta eksekusi unit/feature test paralel.
- **Hardware Integration Driver Teruji:** Modul pencetakan kasir (`ReceiptPrinterService` dan `KitchenPrinterService`) memanfaatkan pustaka `mike42/escpos-php` yang mendukung protokol ESC/POS kanonis untuk printer thermal via USB, Network TCP/IP, dan Serial Port.
- **Perangkat Keras Kasir (Device Pairing):** Terminal POS fisik mendukung aktivasi aman berbasis token/nonce melalui endpoint API `/devices/pair`.
- **Rencana Cadangan & Pemulihan (Disaster Recovery):** Perintah `EnterpriseBackupCommand.php` dan `Schedule::command('backup:run')->dailyAt('02:00')` menjamin pencadangan basis data dan aset harian.

Namun, ditinjau dari kesiapan operasional ERP SaaS Tier-1, ditemukan kesenjangan kritis:
1. **Environment Parity Mismatch di CI/CD Pipeline (P2):** Pipeline GitHub Actions menggunakan service container `image: mysql:8.0`, padahal lingkungan produksi resmi yang disetujui melalui ADR-006 adalah **MariaDB 10.11 / 13.0**. Perbedaan dialek SQL dan perilaku trigger dapat meloloskan regresi spesifik MariaDB di CI.
2. **Ketiadaan Surface API Eksternal (API-First Gap - P2):** File `routes/api.php` hanya memiliki 4 rute (webhook pemesanan, registrasi provisioning, dan device pairing). Tidak ada REST API untuk integrasi pihak ketiga (seperti GrabFood, GoFood, Shopee, Tokopedia, atau akuntansi eksternal).
3. **Ketiadaan RTO/RPO SLA Formal & Automated Point-in-Time Recovery (PITR - P2):** Backup terjadwal bersifat snapshot harian (mysqldump). Belum ada replikasi binary log (binlog) otomatis untuk *Point-in-Time Recovery* dengan RPO < 15 menit.

**Domain 10 Verdict:** **`NEEDS REMEDIATION`**

---

## 2. CI/CD PIPELINE, ENVIRONMENT PARITY & DEPLOYMENT

### 2.1 GitHub Actions Workflow Evaluation
Pipeline pada `.github/workflows/tests.yml`:
1. **Job 1 (Lint & Static Analysis):**
   - PHP 8.3 setup dengan ekstensi `pdo_mysql`, `bcmath`, `intl`.
   - Pint check style.
   - PHPStan / Larastan level analysis.
2. **Job 2 (Tests & Database Integration):**
   - Service container: `mysql:8.0`.
   - Database: `apeiron_pos_test` dan `yovel_pos_test`.
   - Frontend asset build via `npm ci && npm run build`.
   - Eksekusi `php artisan test --parallel`.

**Temuan Diskrepansi Mesin Basis Data (P2):**
CI menggunakan container `mysql:8.0` alih-alih `mariadb:10.11`. Pada Domain 1, beberapa fitur (seperti evaluasi constraint foreign key leksikal dan trigger imutabilitas) bergantung pada spesifikasi engine MariaDB InnoDB. Menjalankan CI pada MySQL 8.0 berisiko menciptakan *false sense of security*.

---

## 3. INTEGRATION ARCHITECTURE & HARDWARE INTEGRATION

### 3.1 Hardware POS Drivers (Thermal Receipt & Kitchen Printers)
- Menggunakan `Mike42\Escpos\Printer` dengan abstraction connector:
  - `NetworkPrintConnector`: Untuk printer dapur dan kasir berbasis LAN/Ethernet IP.
  - `FilePrintConnector`: Untuk printer lokal USB (misal `/dev/usb/lp0` di Linux POS Terminal).
  - `WindowsPrintConnector`: Untuk printer thermal berbasis driver spooler Windows.
- Logging Pencetakan: Setiap aksi mencetak struk dicatat ke dalam tabel `receipt_print_logs` dengan status `success` / `failed` dan counter cetak ulang (*reprint counter*).

### 3.2 External API & Webhook Architecture
- **Webhooks:** Endpoint `/webhooks/{provider}/orders` dan `/tenants/{tenant}/webhooks/{provider}/orders` dilindungi oleh middleware `VerifyWebhookSignature` dan rate limiter `throttle:120,1`.
- **API Surface Deficit:** Platform belum menyediakan REST/GraphQL API publik yang terautentikasi (seperti Laravel Sanctum / Passport) untuk memungkinkan mitra atau tenant besar mengintegrasikan sistem inventori atau pesanan mereka secara mandiri.

---

## 4. DOMAIN 10 FINDINGS REGISTER

### FINDING-DOM10-01: CI Database Container Mismatch with Production Engine (MySQL 8.0 vs MariaDB 10.11)
- **Domain:** Domain 10 — DevOps & CI/CD Environment Parity
- **Severity:** **P2** (Environment Parity Risk)
- **Confidence:** **HIGH** (Empirically verified in `.github/workflows/tests.yml` line 62)
- **File Path:** [`.github/workflows/tests.yml`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/.github/workflows/tests.yml#L60-L70)
- **Evidence:**
  ```yaml
  services:
    mysql:
      image: mysql:8.0
  ```
- **Actual Behavior:** Uji otomatis CI dijalankan di atas container MySQL 8.0. Sementara basis data produksi resmi yang disetujui Product Owner dalam DEC-DB-01 dan ADR-006 adalah MariaDB 10.11.
- **Expected Behavior:** Pipeline CI/CD enterprise wajib mencerminkan lingkungan produksi secara identik (*12-Factor App: Dev/Prod Parity*). Service container CI harus menggunakan `image: mariadb:10.11`.
- **Root Cause:** Template konfigurasi bawaan CI yang belum disinkronkan pasca penetapan Architecture Decision Record (ADR-006).
- **Business Impact:** Risiko bug spesifik MariaDB yang lolos ke produksi atau tes CI gagal pada sintaks SQL yang sebenarnya sah di MariaDB.
- **Recommendation:** Ubah image container database pada `.github/workflows/tests.yml` dari `mysql:8.0` menjadi `mariadb:10.11`.

---

### FINDING-DOM10-02: Absence of Public Partner REST API & API-First Integration Layer
- **Domain:** Domain 10 — Integration Architecture & Omnichannel Extensibility
- **Severity:** **P2** (Enterprise Integration Gap)
- **Confidence:** **HIGH** (Empirically verified in `routes/api.php`)
- **File Path:** [`routes/api.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/routes/api.php)
- **Evidence:** File `routes/api.php` hanya memiliki 4 rute internal:
  - 2 webhook pesanan
  - 1 S2S provisioning
  - 1 device pairing
  Tidak ada endpoint API untuk Catalog, Order, Customer, Inventory, atau Reporting.
- **Actual Behavior:** Seluruh operasi saat ini terikat pada antarmuka web Livewire. Tidak ada cara bagi pihak ketiga (seperti agregator pengiriman makanan, aplikasi loyalitas kustom, atau e-commerce omnichannel) untuk berkomunikasi dengan Apeiron secara terprogram.
- **Expected Behavior:** Platform ERP enterprise modern (seperti Odoo, SAP, NetSuite) menyediakan RESTful atau GraphQL API publik yang terdokumentasi (OpenAPI/Swagger) dengan autentikasi API Key / OAuth2 Personal Access Tokens (Laravel Sanctum).
- **Root Cause:** Fokus pengembangan tahap awal pada aplikasi frontline kasir internal.
- **Business Impact:** Menghambat ekspansi ekosistem tenant enterprise yang membutuhkan integrasi marketplace (Tokopedia/Shopee/TikTok Shop) atau integrasi ERP korporat pihak ketiga.
- **Recommendation:** Rancang modul `App\Modules\Api` berbasis Laravel Sanctum dengan OpenAPI specification (Swagger) untuk mengekspos endpoint inti: Catalog, POS Orders, Stock Balances, dan Webhooks.

---

## 5. CAPABILITY GAP MATRIX (DEVOPS & INTEGRATIONS)

| DevOps / Integration Capability | Status Aktual Apeiron | Benchmark Enterprise (SAP BTP / NetSuite SuiteCloud) | Gap Analysis & Kebutuhan Vertikal |
|---|---|---|---|
| **CI/CD Quality Gates** | **Implemented & Verified** | Automated Static Analysis, Security Scans, Parallel Tests | Sangat baik dengan Larastan dan Pint. Perlu penyesuaian container MariaDB. |
| **Hardware Receipt Printing (ESC/POS)** | **Implemented & Verified** | Network, USB, and Serial Thermal Printer Spooling | Teruji dengan `mike42/escpos-php` dan audit log cetak. |
| **Kitchen Hardware / KDS Bump Bar** | **Partially Ready** | Industrial Bump Bar Keyboard & Touch Display Support | Antarmuka sentuh KDS tersedia; driver bump bar keyboard parsial. |
| **Disaster Recovery (RPO/RTO)** | **Partially Ready** | Multi-Region Automated Failover with RPO < 5 min, RTO < 30 min | Snapshot harian terjadwal; Point-in-time Recovery (PITR) belum terotomasi. |
| **Zero-Downtime Deployment** | **Partially Ready** | Blue/Green or Canary Deployments with Zero-Lock Migrations | Menggunakan script standar `artisan migrate --force`; butuh isolasi deployment blue-green. |
| **Public REST API Gateway** | **Not Implemented** | Full OpenAPI 3.0 REST/GraphQL Suite with Scoped Tokens | Belum ada API publik untuk produk, stok, atau order. |

---

## 6. VERDICT & CONDITIONS

Domain 10 dinyatakan **NEEDS REMEDIATION**. Integrasi perangkat keras thermal printer dan pipeline CI/CD sangat solid, namun penyesuaian container MariaDB pada CI, pendaftaran outbox scheduler, dan perancangan REST API eksternal wajib dilakukan untuk memenuhi ambisi enterprise ERP SaaS.
