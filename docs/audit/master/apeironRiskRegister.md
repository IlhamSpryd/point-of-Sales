# APEIRON SYSTEMS — RISK REGISTER
## Registri Risiko Arsitektural, Keamanan, Integritas Data, dan Risiko Operasional SaaS

---

| Metadata Dokumen | Keterangan |
| :--- | :--- |
| **Audit Lead** | Principal Enterprise Software Architect & Security Auditor (A0) |
| **Status Dokumen** | **UPDATED POST-DECISION BASELINE (DOMAIN 1)** |
| **Tanggal Pembaruan** | 01 November 2026 |

---

## 1. Matriks Evaluasi Risiko (Risk Matrix)

| Likelihood \ Impact | Low (1) | Medium (2) | High (3) | Critical (4) |
| :--- | :---: | :---: | :---: | :---: |
| **Almost Certain (5)** | Low | Medium | High | **CRITICAL** |
| **Likely (4)** | Low | Medium | High | **CRITICAL** |
| **Possible (3)** | Low | Medium | **HIGH** | High |
| **Unlikely (2)** | Low | Low | Medium | High |
| **Rare (1)** | Low | Low | Low | Medium |

---

## 2. Registri Risiko Domain 1 Terkini (*Open Risks Register*)

| Risk ID | Deskripsi Risiko | Kategori | Likelihood | Impact | Tingkat Risiko | Status Penanganan | Temuan / Keputusan Terkait | Tindakan Mitigasi Terjadwal |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| **RSK-SEC-01** | **Kebocoran Data Antar-Tenant via Raw SQL:** 79 kueri `DB::table()` tanpa filter tenant membypass `TenantScope`, memungkinkan kebocoran saldo kredit dan data pelanggan. | Keamanan & Multi-Tenancy | Possible (3) | Critical (4) | **CRITICAL (P0)** | **OPEN (IN PLANNING)** | DB-002 / DEC-DB-01 | Paket Kerja WP-DB-01: Audit dan refaktor 79 kueri raw; penambahan test otomatis `RawQueryTenantIsolationTest`. |
| **RSK-ARC-01** | **Kompensasi Ketiadaan Native RLS MariaDB:** MariaDB tidak memiliki Row-Level Security tingkat kernel, mengalihkan beban pertahanan sepenuhnya ke aplikasi. | Arsitektur & Kepatuhan | Likely (4) | High (3) | **HIGH (P1)** | **CONTROLLED VIA ADR-006** | DB-001 / DEC-DB-01 | Penerapan 7 lapis pengamanan kompensatoris (ADR-006), verifikasi 374 composite FKs, dan CI static analysis. |
| **RSK-DAT-01** | **Penghapusan Massal Riwayat Finansial & Gaji via Cascade:** 45 relasi FK menggunakan `CASCADE` yang berpotensi menghapus data penggajian dan biaya produk jika tenant terhapus. | Integritas Data & Kepatuhan | Unlikely (2) | Critical (4) | **HIGH (P1)** | **IN PLANNING** | DB-004 / DEC-DB-02 | Paket Kerja WP-DB-03: Migrasi pengubahan rule ke `RESTRICT` pada seluruh tabel finansial, payroll, dan biaya inventaris. |
| **RSK-DAT-02** | **Integritas Referensial Longgar pada 10 Tabel Promosi:** Ketiadaan FK pada 10 tabel multi-tenant berpotensi menciptakan orphan records. | Integritas Data | Possible (3) | Medium (2) | **MEDIUM (P2)** | **IN PLANNING (0 ORPHANS VERIFIED)** | DB-003 / DEC-DB-03 | Paket Kerja WP-DB-02: Migrasi penambahan FK `(tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT`. |
| **RSK-MNT-01** | **Duplikasi Trait Namespace Multi-Tenancy:** Trait ganda membingungkan pengembang dan berisiko regresi saat refactoring. | Maintainability | Likely (4) | Low (1) | **LOW (P3)** | **OPEN** | DB-005 | Konsolidasi seluruh model ke `App\Core\Traits\ScopedToTenant` pada siklus refaktorisasi Sprint 2. |
