# ARCHITECTURE DECISION RECORD (ADR-006)
## Formalisasi Basis Data MariaDB 10.11 InnoDB, Kontrol Isolasi Multi-Tenant Kompensatoris, dan Aturan Integritas Referential

---

| Metadata ADR | Keterangan |
| :--- | :--- |
| **Status Keputusan** | **APPROVED WITH CONDITIONS (BY PRODUCT OWNER)** |
| **Tanggal Keputusan** | 01 November 2026 |
| **Pengambil Keputusan** | Product Owner / Human Owner Apeiron Systems |
| **Auditor / Rekomendasi** | Principal Enterprise Software Architect (A0) |
| **Dokumen Acuan** | Final Master PRD (`OD-02`), `docs/audit/domains/domain01DatabaseMultiTenancyAudit.md` |
| **Revisi Dokumen Terkait** | Mengamandemen PRD Bab 34 (`OD-02`) melalui klausul kompensasi arsitektural resmi |

---

## 1. Konteks Masalah (*Context & Problem Statement*)

Pada dokumen *Apeiron Systems — Final Master PRD.docx* (Bab 27 & Bab 34 `OD-02`), ditetapkan secara kanonik bahwa arsitektur basis data Apeiron Systems menggunakan:
> *"PostgreSQL 16+ dengan Row-Level Security (RLS), Composite Foreign Keys (tenant_id, id), partial unique indexes native, serta penyetelan konteks sesi server-side pada setiap transaksi."*

Namun, implementasi fisik pada repositori `apeironSystem/point-of-Sales` telah terbangun di atas **MariaDB 10.11 InnoDB** dengan:
- 209 tabel basis data.
- 205 berkas migrasi Laravel.
- 374 Composite Foreign Keys berformat `(tenant_id, foreign_id) REFERENCES parent_table(tenant_id, id)`.
- 57 Database Triggers aktif (penegakan *append-only* finansial, immutability jurnal, dan pencegahan *overpayment*/*overrefund*).
- 195 model Eloquent dengan 183 model mengadopsi fail-closed global scope `TenantScope`.

MariaDB 10.11 **TIDAK MEMILIKI** native kernel Row-Level Security (RLS) seperti PostgreSQL. Selain itu, ditemukan sedikitnya 79 kueri mentah `DB::table()` tanpa klausa `tenant_id`, 10 tabel multi-tenant tanpa foreign key ke `tenants(id)`, dan 45 foreign key dengan aturan `ON DELETE CASCADE`.

---

## 2. Keputusan Arsitektur Resmi (*Decision Outcome*)

Product Owner menetapkan tiga keputusan resmi:

### 2.1 DEC-DB-01 — Penetapan Basis Data MariaDB 10.11 InnoDB (Amandemen PRD OD-02)
**Status: APPROVED WITH CONDITIONS**
Apeiron Systems secara resmi mengesahkan **MariaDB 10.11 InnoDB** sebagai basis data produksi untuk fase saat ini, mengamandemen PRD `OD-02`. 

**Rasional:**
1. Struktur 209 tabel dan integritas data yang telah berjalan memiliki kompleksitas tinggi. Migrasi massal ke PostgreSQL membawa risiko *blast radius* teknis dan operasional yang membahayakan stabilitas sistem.
2. Adanya 374 Composite Foreign Keys dan 57 Database Triggers telah memberikan pertahanan integritas relasional dan finansial yang sangat kokoh di level storage engine.
3. Migrasi ke PostgreSQL tidak diizinkan tanpa *feasibility study*, *data migration dry-run*, dan analisis risiko komprehensif.

### 2.2 DEC-DB-02 — Pengetatan Delete Rule Foreign Key Tenant (CASCADE $\rightarrow$ RESTRICT)
**Status: APPROVED WITH CONDITIONS**
Menyetujui perubahan bertahap dari `ON DELETE CASCADE` menjadi `ON DELETE RESTRICT` pada 45 relasi tabel terhadap `tenants(id)` dengan memprioritaskan tabel finansial, penggajian, dan riwayat biaya.

### 2.3 DEC-DB-03 — Penambahan Foreign Key Constraint pada 10 Tabel Multi-Tenant
**Status: APPROVED WITH CONDITIONS**
Menyetujui penambahan foreign key constraint `(tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT` pada 10 tabel promosi dan kredit pelanggan setelah verifikasi ketiadaan *orphan records*.

---

## 3. Kontrol Kompensatoris Wajib (*Compensating Security Controls*)

Karena MariaDB tidak memiliki Row-Level Security di tingkat kernel OS/database, sistem wajib menerapkan **7 Lapis Pengamanan Kompensatoris (Compensating Controls)**:

1. **Lapis 1 — Application Fail-Closed Scope:** Seluruh query Eloquent wajib mewarisi `App\Core\Traits\ScopedToTenant`. Jika `TenantContext` bernilai `null` tanpa bypass eksplisit, query otomatis diinjeksi `WHERE 1 = 0`.
2. **Lapis 2 — Remediasi Raw Queries:** Seluruh 79 kueri `DB::table()` yang ditemukan wajib diaudit dan disuntikkan `where('tenant_id', $tenantId)` secara eksplisit atau diganti dengan Model Eloquent.
3. **Lapis 3 — Composite Foreign Keys (Engine Level):** Mempertahankan dan memperluas 374 Composite Foreign Keys `(tenant_id, child_id) -> (tenant_id, id)` untuk menjamin integritas relasi antar-tenant di level InnoDB.
4. **Lapis 4 — Database Triggers (Immutability):** Mempertahankan 57 triggers MariaDB yang melempar `SIGNAL SQLSTATE '45000'` saat ada upaya modifikasi tidak sah pada data finansial dan pergerakan stok.
5. **Lapis 5 — Automated Isolation Testing:** Wajib mengimplementasikan test suite `CrossTenantIsolationSecurityTest` di CI/CD yang menguji percobaan akses data silang pada seluruh endpoint dan kueri raw.
6. **Lapis 6 — Static Analysis Enforcement:** Menambahkan aturan custom pada PHPStan/Larastan yang menolak commit baru jika terdapat pemanggilan `DB::table(...)` tanpa klausa tenant.
7. **Lapis 7 — Database User Least Privilege:** Akun koneksi aplikasi ke MariaDB dilarang memiliki hak `SUPER` atau `DROP TABLE`, dan koneksi direct reporting (BI/n8n) wajib dibatasi ke Read-Only View terisolasi per tenant.

---

## 4. Kondisi dan Kriteria Evaluasi Ulang PostgreSQL (*Re-Evaluation Triggers*)

PostgreSQL 16+ dengan RLS tetap menjadi opsi arsitektur masa depan. Evaluasi ulang migrasi ke PostgreSQL wajib dipicu apabila salah satu kondisi berikut terpenuhi:
1. Regulasi kepatuhan (*compliance*) enterprise (seperti PCI-DSS Level 1 atau sertifikasi kedaulatan data perbankan) secara hukum mewajibkan RLS di level kernel database.
2. Skala tenant melampaui $10.000$ tenant aktif di mana skema bersama (*shared schema*) di MariaDB mengalami *table contention* tinggi.
3. Adanya kebutuhan partisi native multi-tenant lanjutan (*declarative hash/list partitioning by tenant_id*) yang melebihi kapabilitas MariaDB InnoDB.

---

## 5. Rencana Tindakan & Status Kepatuhan

- **Status Domain 1:** Tetap **`NEEDS REMEDIATION`** hingga seluruh kueri raw selesai dibersihkan, foreign key baru terpasang, dan pengujian isolasi otomatis diverifikasi.
- **Batasan Eksekusi:** Dilarang mengubah kode sumber, database schema, atau data historis sebelum rencana remediasi formal disetujui.
