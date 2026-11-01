# APEIRON SYSTEMS — MASTER AUDIT REGISTER
## Registri Terpusat Temuan Audit, Status Domain, dan Pelacakan Resolusi

---

| Metadata Dokumen | Keterangan |
| :--- | :--- |
| **Audit Lead** | Principal Enterprise Software Architect (A0) |
| **Status Audit Sistem** | **PHASE 1 DECISIONS RECORDED (DOMAIN 1: NEEDS REMEDIATION; DOMAINS 2–10: NOT AUDITED)** |
| **Status Keputusan Human Owner** | **DEC-DB-01, DEC-DB-02, DEC-DB-03 APPROVED WITH CONDITIONS** |
| **Tanggal Pembaruan** | 01 November 2026 |

---

## 1. Master Domain Progress Dashboard

| No | Domain Audit | Status Domain | Skor (0–100) | Tingkat Keyakinan (*Confidence*) | Critical Findings (P0) | High Findings (P1) | Gerbang Persetujuan |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **1** | **Database Architecture & Multi-Tenancy** | **NEEDS REMEDIATION** | **78 / 100** | **CONFIRMED (VERIFIED)** | **1** | **2** | **KEPUTUSAN DICATAT (REMEDIASI PENDING)** |
| **2** | Core Backend & Business Logic | `NOT AUDITED` | — | — | — | — | **TERKUNCI (MENUNGGU PERSETUJUAN BUKA)** |
| **3** | Frontend, Livewire & State Management | `NOT AUDITED` | — | — | — | — | Terkunci |
| **4** | UI/UX & Operational Workflow | `NOT AUDITED` | — | — | — | — | Terkunci |
| **5** | Payments, Financial Integrity & Accounting | `NOT AUDITED` | — | — | — | — | Terkunci |
| **6** | Dynamic Catalog, Inventory & Purchasing | `NOT AUDITED` | — | — | — | — | Terkunci |
| **7** | Security, RBAC & SaaS Governance | `NOT AUDITED` | — | — | — | — | Terkunci |
| **8** | Concurrency, Transaction & Locking | `NOT AUDITED` | — | — | — | — | Terkunci |
| **9** | Performance, Scalability & Observability | `NOT AUDITED` | — | — | — | — | Terkunci |
| **10** | DevOps, Infrastructure & Integration | `NOT AUDITED` | — | — | — | — | Terkunci |

---

## 2. Master Finding Register & Acceptance Criteria (Domain 1)

| Finding ID | Severity | Status | Deskripsi Temuan | Lokasi Kode / Skema | Keputusan Owner | Kriteria Penerimaan (*Acceptance Criteria*) Sebelum Status Diubah |
| :--- | :---: | :---: | :--- | :--- | :---: | :--- |
| **DB-001** | **CRITICAL (P0)** | **IN PLANNING** | **Deviasi Arsitektur PRD OD-02 (MariaDB vs PostgreSQL RLS):** Basis data menggunakan MariaDB InnoDB tanpa kernel RLS. | Storage Engine / MariaDB 10.11 | **DEC-DB-01 (Approved w/ Cond)** | 1. Dokumen ADR-006 diterbitkan dan disahkan.<br>2. Tujuh lapis *compensating security controls* terdokumentasi dan diimplementasikan.<br>3. Seluruh 79 kueri raw `DB::table()` bersih dari potensi kebocoran data. |
| **DB-002** | **HIGH (P1)** | **RESOLVED (VERIFIED PASS)** | **Celah Kebocoran Data pada 79 Kueri Mentah:** Pemanggilan `DB::table()` tanpa klausa `tenant_id` membypass `TenantScope`. | `CreateOrder.php:392`, `LoyaltyService.php:33`, dll. | Terikat DEC-DB-01 | 1. 100% kueri runtime kritis direfaktor.<br>2. Verifikasi empiris: Celah IDOR CreateOrder tertutup.<br>3. Batch 1A Hardening: Fail-closed & supervisor boundary checks lolos 100%. |
| **DB-003** | **HIGH (P1)** | **RESOLVED (VERIFIED PASS)** | **Ketiadaan Foreign Key pada 10 Tabel Multi-Tenant:** Tabel memiliki `tenant_id` tanpa constraint FK ke `tenants(id)`. | Schema: `promotion_reservations`, `customer_credit_accounts`, dll. | **DEC-DB-03 (Approved w/ Cond)** | 1. 0 orphan rows terbukti pada seluruh 10 tabel.<br>2. Migrasi penambahan FK `ON DELETE RESTRICT` dieksekusi idempoten.<br>3. 10 FK aktif terverifikasi pada information_schema. |
| **DB-004** | **MEDIUM (P2)** | **IN PLANNING** | **Risiko Penghapusan Massal Riwayat Finansial via Foreign Key CASCADE:** 45 relasi FK menggunakan `CASCADE`. | Schema: `payroll_records`, `product_cost_histories`, dll. | **DEC-DB-02 (Approved w/ Cond)** | 1. Klasifikasi 45 tabel selesai (Telah diinventarisasi).<br>2. Tabel finansial, payroll, dan biaya inventaris diubah ke `ON DELETE RESTRICT`.<br>3. Uji coba penghapusan tenant dengan transaksi historis ditolak mutlak oleh DB engine. |
| **DB-005** | **LOW (P3)** | **OPEN** | **Duplikasi Namespace Trait Scoping Multi-Tenant:** Duplikasi trait antara `App\Core\Traits` dan `App\Models\Concerns`. | `app/Core/Traits`, `app/Models/Concerns` | Standardisasi | 1. Seluruh model distandarisasi ke `App\Core\Traits\ScopedToTenant`.<br>2. Trait usang dideprekasi tanpa merusak backward compatibility. |
| **DB-006** | **INFO** | **VERIFIED** | **Kekuatan Skema: 374 Composite FKs & 57 Triggers:** Pertahanan database-level kokoh. | MariaDB Engine | Diakui | 1. Dipertahankan tanpa regresi pada setiap penambahan skema baru. |

---

## 3. Registri Keputusan Pemilik Produk Terkini (*Architectural Decisions Log*)

- **DEC-DB-01 (MariaDB 10.11 Engine):** Disetujui bersyarat melalui **ADR-006**. Amandemen PRD OD-02 disahkan dengan kompensasi audit 79 kueri raw, implementasi cross-tenant isolation tests, dan pengawasan static analysis.
- **DEC-DB-02 (Tenant Delete Rules CASCADE $\rightarrow$ RESTRICT):** Disetujui bersyarat. Inventarisasi 45 tabel selesai. Prioritas migrasi ditetapkan pada tabel keuangan, penggajian, dan biaya inventaris.
- **DEC-DB-03 (Missing Foreign Keys 10 Tabel):** Disetujui bersyarat. Deteksi awal membuktikan **0 baris yatim (orphan rows)** pada seluruh 10 tabel. Siap dimigrasikan setelah rencana remediasi disahkan.
