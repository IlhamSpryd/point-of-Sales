# DOMAIN 07 — SECURITY, MULTI-TENANT GOVERNANCE & ENTERPRISE SAAS CONTROL AUDIT
## Apeiron Systems — Multi-Tenant Enterprise SaaS ERP Platform

- **Auditor:** A0 — Principal Enterprise Architect & SaaS Security Auditor
- **Audit Target:** Authentication, RBAC, Fine-Grained Permissions, Multi-Tenancy Governance, Data Privacy & Encryption, SaaS Provisioning, and Entitlements
- **Scope:** Auth Module, Security Middleware, TenantContext, Model Policies, HR PII Encryption, and SaaS Control Plane
- **Audit Date:** 1 November 2026
- **Status:** **NEEDS REMEDIATION**
- **Confidence:** **HIGH** (Empirically verified via security middleware, cryptographic casts, and policy invocation traces)

---

## 1. EXECUTIVE SUMMARY & DOMAIN VERDICT

Apeiron Systems dirancang dengan standar pertahanan keamanan (*defense-in-depth*) yang sangat impresif di tingkat arsitektur inti platform:
- **Pemisahan Identitas & Enkripsi PII Sensitif (Modul 9 HR):** Data identitas karyawan (NIK, NPWP, Nomor Rekening Bank, Nomor BPJS Ketenagakerjaan/Kesehatan) dienkripsi secara simetris di tingkat kolom basis data menggunakan Laravel Cryptographic Casts (`'encrypted'`), menjamin kepatuhan terhadap UU Perlindungan Data Pribadi (UU PDP).
- **Otentikasi Berlapis & 2FA (Two-Factor Authentication):** Mendukung Google/Apple Authenticator (TOTP) dengan kode pemulihan terenkripsi (`encrypted:array`), verifikasi email OTP, pembatasan percobaan login gagal (*brute-force lockout*: 5 percobaan = kunci 15 menit), dan hashing PIN kasir menggunakan algoritma bcrypt/argon2 (`'hashed'`).
- **Isolasi Konteks Multi-Tenant Fail-Closed:** `TenantContext` diimplementasikan sebagai scoped singleton di container. Route Model Binding secara otomatis menyaring `tenant_id` aktif dan melempar exception fail-closed jika context tidak tersedia.
- **Perlindungan Hard-Delete di Database Engine:** 38 tabel master dan finansial dilindungi oleh `ON DELETE RESTRICT` langsung pada foreign key MariaDB InnoDB (hasil verifikasi Domain 1), mencegah catastrophic cascade deletion dari akun tenant.

Meskipun fondasi keamanan data sangat kokoh, audit independen menemukan beberapa **celah otorisasi dan tata kelola SaaS** yang membutuhkan remediasi mendesak:
1. **Patahnya Metode Otorisasi pada 10 Policy Finansial (P1):** Policy akuntansi memanggil `$user->hasPermissionTo(...)` dan `$user->hasRole(...)` yang tidak ada pada model `User`, memicu error fatal saat diakses.
2. **Ketiadaan Otorisasi Komponen pada 20+ Komponen Livewire (P1):** Komponen manajerial (Jurnal Umum, Rekonsiliasi Bank, COA, Penggajian, Opname) tidak mengeksekusi `$this->authorize(...)` di dalam method mutasinya.
3. **Pengeksposan Kredensial PIN pada State Publik Livewire (P1):** PIN supervisor dan PIN gift card tersimpan dalam properti publik komponen kasir yang terekspos ke klien.
4. **Keterbatasan Hierarki Organisasi Multi-Company (P2):** Struktur multi-tenant saat ini hanya mendukung 2 tingkat hirarki: `Tenant (Company) -> Store (Branch)`. Belum mendukung struktur holding enterprise (*Holding Company -> Multi-Brand / Multi-Subsidiary -> Outlets*).

**Domain 7 Verdict:** **`NEEDS REMEDIATION`**

---

## 2. AUTHENTICATION, SESSIONS & USER LIFECYCLE

### 2.1 Password, PIN, and Session Security
- **Hashing:** Password pengguna (`password_hash`) dan PIN kasir (`pin_hash`) di-cast sebagai `'hashed'` di Eloquent, memastikan bahwa seluruh input string di-hash menggunakan driver `Hash::make()` (bcrypt / Argon2id).
- **Session Protection:** Didukung oleh middleware prioritas: `StartSession`, `AuthenticatesSessions`, `SetTenantContext`.
- **Security Headers:** Middleware `SecurityHeaders.php` aktif secara global, menerapkan:
  - `X-Frame-Options: SAMEORIGIN` (mencegah Clickjacking)
  - `X-Content-Type-Options: nosniff` (mencegah MIME-sniffing)
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: geolocation=(), camera=(), microphone=()`

### 2.2 Two-Factor Authentication (2FA) & Recovery
- Kolom `two_factor_secret` dan `two_factor_recovery_codes` dienkripsi secara persisten di tabel `users`.
- Middleware `RequireTwoFactorChallenge` mencegat request pengguna yang memiliki 2FA aktif hingga tantangan OTP diselesaikan.

---

## 3. MULTI-TENANT GOVERNANCE & SAAS CONTROL PLANE

### 3.1 Tenant Lifecycle & Provisioning
- **Penyediaan Tenant Baru:** `TenantProvisioningService` mengorkestrasi pembuatan tenant, cabang awal, peran bawaan (Owner, Manager, Kasir, Waiter, Kitchen), bagan akun standar (COA), dan akun user pertama.
- **Signature Verification:** Komunikasi penyediaan dari portal SaaS eksternal dilindungi oleh HMAC signature verification di `VerifyProvisioningSignature.php`.
- **Suspension & Offboarding:** Middleware `EnforceEntitlement.php` memeriksa masa aktif langganan tenant (`tenant_subscriptions`) dan kuota fitur (`tenant_entitlement_overrides`), memblokir akses jika akun ditangguhkan (*suspended*).

### 3.2 Hierarki Organisasi Enterprise
Struktur basis data saat ini:
```text
tenants (id, name, business_type)
    └── stores (id, tenant_id, store_name, branch_code)
```
- **Keterbatasan Arsitektur:** Jika sebuah grup bisnis memiliki 3 anak perusahaan dengan PT berbeda (misal: PT Resto Boga Makmur, PT Ritel Niaga Sejahtera, PT Kopi Indonesia) di bawah satu akun SaaS, Apeiron saat ini harus mendaftarkan mereka sebagai tenant terpisah atau toko terpisah tanpa kemampuan konsolidasi laporan keuangan lintas-PT (*intercompany financial consolidation*).

---

## 4. DOMAIN 7 FINDINGS REGISTER

### FINDING-DOM07-01: Runtime Exception on 10 Financial Policies via Undefined User Methods
- **Domain:** Domain 7 — Security & RBAC Enforcement
- **Severity:** **P1** (High Severity Runtime Defect & Authorization Failure)
- **Confidence:** **HIGH** (Empirically verified in `app/Policies/` and Tinker)
- **File Path:** [`app/Policies/JournalEntryPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/JournalEntryPolicy.php#L14), [`ChartOfAccountPolicy.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Policies/ChartOfAccountPolicy.php#L14), dll.
- **Evidence:**
  ```php
  // JournalEntryPolicy.php:
  return $user->hasPermissionTo('finance.journal.view') || $user->hasRole('owner');
  // Eksekusi Tinker:
  BadMethodCallException: Call to undefined method App\Models\User::hasPermissionTo()
  ```
- **Actual Behavior:** 10 policy finansial memanggil method yang tidak pernah dideklarasikan di model `User`.
- **Expected Behavior:** Seluruh policy menggunakan method kanonis: `$user->hasPermission(...)` dan memeriksa role melalui `$user->role?->name`.
- **Root Cause:** Pengembang mengasumsikan interface package Spatie Permission yang tidak digunakan di repositori ini.
- **Business Impact:** Kerusakan runtime (HTTP 500) saat pengembang memasang `$this->authorize(...)` pada modul akuntansi.
- **Recommendation:** Lakukan refactor pada 10 file policy finansial untuk mencocokkan signature model `User`.

---

### FINDING-DOM07-02: Systematic Omission of Component-Level Authorization ($this->authorize) Across Managerial Livewire Components
- **Domain:** Domain 7 — Security & Access Control
- **Severity:** **P1** (Critical Authorization Bypass Risk)
- **Confidence:** **HIGH** (Empirically verified: 23 Livewire component managers miss `$this->authorize()`)
- **File Path:** Seluruh komponen manajerial di `app/Livewire/Finance/`, `app/Livewire/HR/`, dan `app/Livewire/Inventory/`
- **Evidence:** Komponen `JournalEntryManager`, `ChartOfAccountManager`, `BankReconciliationWorkbench`, `AccountingPeriodManager`, `PayrollBatchManager`, `EmployeeManager`, `StockOpnameManager`, `StockTransferManager` tidak memiliki satu pun baris pemanggilan `$this->authorize(...)`.
- **Actual Behavior:** Perlindungan akses hanya mengandalkan pengecekan role di level route URL (`routes/web.php` middleware `role:Owner,Manager`). Tidak ada pengecekan izin granular pada metode eksekusi aksi (misal: apakah user berhak memposting jurnal, membatalkan transfer stok, atau menyetujui batch gaji).
- **Expected Behavior:** Prinsip *Defense-in-Depth* mewajibkan setiap mutasi data pada controller dan komponen Livewire diverifikasi menggunakan Laravel Policies (`$this->authorize('create', JournalEntry::class)`, `$this->authorize('approve', $batch)`).
- **Root Cause:** Ketergantungan berlebih pada route middleware dan pengabaian otorisasi di siklus hidup komponen Livewire.
- **Business Impact:** Pengguna dengan role Manager (yang diizinkan mengakses URL) dapat mengeksekusi operasi finansial sensitif tanpa batasan peran pemisahan tugas (*Separation of Duties - SoD*).
- **Recommendation:** Tambahkan `$this->authorize(...)` pada setiap method mutasi di seluruh 23 komponen Livewire manajerial.

---

### FINDING-DOM07-03: Kredensial Sensitif PIN Supervisor & Gift Card Terekspos di Client Livewire Snapshot
- **Domain:** Domain 7 — Security & Data Protection
- **Severity:** **P1** (Credential Exposure Vulnerability)
- **Confidence:** **HIGH** (Tercatat di `app/Livewire/Kasir/CreateOrder.php` baris 143, 145, 156)
- **Detail:** Lihat penjelasan lengkap pada [FINDING-DOM03-01](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/docs/audit/enterprise-domain02-10/Domain03-FrontendLivewire-Audit.md#finding-dom03-01-sensitive-authentication-pins-exposed-in-public-livewire-state).

---

## 5. CAPABILITY GAP MATRIX (SECURITY & SAAS GOVERNANCE)

| Security / Governance Capability | Status Aktual Apeiron | Benchmark Enterprise (SAP Cloud / NetSuite SaaS) | Gap Analysis & Kebutuhan Vertikal |
|---|---|---|---|
| **Tenant Isolation Engine** | **Implemented & Verified** | Scoped Singleton Tenant Context with DB RESTRICT Rules | Sangat kokoh; 38 tabel dilindungi RESTRICT, queries fail-closed. |
| **Data Privacy & PII Encryption** | **Implemented & Verified** | Symmetric Column-Level Encryption for Statutory Identity | NIK, NPWP, Rekening Bank, BPJS dienkripsi dengan standar UU PDP. |
| **Multi-Factor Authentication (2FA)** | **Implemented & Verified** | TOTP with Encrypted Recovery Codes & Rate Limiting | Didukung penuh di model User dan middleware challenge. |
| **Fine-Grained RBAC & Wildcards** | **Partially Ready** | Role & Permission Matrix with Granular Policy Checks | Model Role mendukung izin JSON dan wildcard, namun implementasi policy finansial patah. |
| **Multi-Company Holding Hierarchy** | **Not Implemented** | Multi-Entity Consolidation (Holding -> Subsidiaries -> Stores) | Terbatas pada 2 tingkat hirarki (Tenant -> Store). |
| **Privileged Access & Break-Glass Audit**| **Not Implemented** | Time-Bounded Elevated Access with Mandatory Justification | Belum ada audit khusus untuk akses darurat Super Admin. |

---

## 6. VERDICT & CONDITIONS

Domain 7 dinyatakan **NEEDS REMEDIATION**. Proteksi isolasi multi-tenant dan enkripsi PII karyawan sangat luar biasa, namun perbaikan sintaks policy finansial dan penambahan `$this->authorize(...)` pada seluruh komponen Livewire manajerial wajib dieksekusi sebelum rilis produksi.
