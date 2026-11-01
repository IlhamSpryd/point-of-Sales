# APEIRON SYSTEMS — GEMINI IDE IMPLEMENTATION INSTRUCTIONS
## BATCH 1A: FAIL-CLOSED TENANT CONTEXT HARDENING
### Modul Target: Customer Loyalty & Sales Shift Authorization Services

---

| Metadata Instruksi | Keterangan |
| :--- | :--- |
| **Penerbit Instruksi** | Principal Database Security Auditor & Senior Laravel Architect (A0) |
| **Target Pelaksana** | Google Antigravity IDE (Gemini) |
| **Dasar Penugasan** | Temuan Verifikasi Independen WP-DB-01 (`WP-DB-01-independent-verification.md`) |
| **Tujuan Utama** | Mengeliminasi klausul penyaringan tenant bersyarat (*soft-conditional*) dan menegakkan prinsip *fail-closed* mutlak |
| **File Target** | `app/Modules/Customers/Services/LoyaltyService.php`<br>`app/Modules/Sales/Services/ShiftService.php`<br>`tests/Feature/Customers/CustomerTenantIsolationTest.php` |
| **Tanggal Terbit** | 01 November 2026 |

---

## 1. PANDUAN PENGGUNAAN OLEH PRODUCT OWNER (PO)

Salin seluruh teks di bawah ini (mulai dari `=== START OF PROMPT BATCH 1A ===` sampai `=== END OF PROMPT BATCH 1A ===`) dan serahkan kepada **Gemini IDE**.

> [!IMPORTANT]
> **Peringatan Integritas Repositori untuk Gemini IDE:**
> Modifikasi dari Batch 1 saat ini berada di *working tree* lokal dalam status **uncommitted**. Gemini IDE **DILARANG KERAS** melakukan `git reset`, `git checkout .`, `git clean`, atau `git stash pop` yang dapat menghilangkan pekerjaan Batch 1 yang sudah lolos verifikasi.

---

```
================================================================================
=== START OF PROMPT BATCH 1A (FAIL-CLOSED TENANT CONTEXT HARDENING) ===
================================================================================
```

# MASTER IMPLEMENTATION PROMPT — GEMINI IDE
## BATCH 1A: Fail-Closed Tenant Context Hardening
### Target: Eliminasi Soft-Conditional Filtering pada LoyaltyService & ShiftService

---

### 1. KONTEKS ARSITEKTUR & TEMUAN AUDIT INDEPENDEN
Berdasarkan hasil verifikasi independen A0 terhadap implementasi Batch 1:
Implementasi kueri tenant sebelumnya menggunakan pola **penyaringan bersyarat (*soft-conditional filtering*)**:
1. Pada `app/Modules/Customers/Services/LoyaltyService.php`:
   Klausul filter tenant dibungkus dengan `if ($tenantId > 0)`.
   Apabila method `calculateEarnablePoints()` atau `checkAndUpgradeTier()` dipanggil saat `$customer->tenant_id` tidak terdefinisi dan konteks sesi `TenantContext` kosong, `$tenantId` bernilai `0`.
   Akibatnya, blok `if ($tenantId > 0)` dilewati, dan kueri dieksekusi **secara unscoped melintasi seluruh data tenant di basis data**.
2. Pada `app/Modules/Sales/Services/ShiftService.php`:
   Method `canAuthorizeSupervisor()` menggunakan `->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))`.
   Jika `$tenantId` kosong/null, klausul `where('tenant_id', ...)` dilewati.
   Lebih lanjut, method ini sebelumnya mengizinkan Owner/Admin mengotorisasi shift tanpa memverifikasi apakah `$storeId` yang diminta benar-benar milik tenant yang sama.

Dalam arsitektur ERP SaaS Tier-1, sistem keamanan multi-tenant wajib bersifat **Fail-Closed**:
- Kueri tenant **TIDAK BOLEH** berjalan tanpa `tenant_id` yang valid.
- Jika konteks tenant hilang/tidak valid, operasi wajib melempar exception (`RuntimeException` / `DomainException`) atau mengembalikan hasil kosong yang aman, **BUKAN** jatuh ke kueri unscoped (*no fallback to global query*).

---

### 2. FILE TARGET & LINE RANGES YANG WAJIB DIPERBAIKI

1. **`app/Modules/Customers/Services/LoyaltyService.php`**
   - Method `calculateEarnablePoints(Customer $customer, float $dpp): int` (sekitar baris 25–65)
   - Method `checkAndUpgradeTier(Customer $customer, ?int $pointsOrSpend = null): ?LoyaltyTier` (sekitar baris 97–146)
2. **`app/Modules/Sales/Services/ShiftService.php`**
   - Method `openShift(...)` (sekitar baris 45–95)
   - Method `canAuthorizeSupervisor(User $user, int $storeId): bool` (sekitar baris 725–756)
3. **`tests/Feature/Customers/CustomerTenantIsolationTest.php`**
   - Tambahkan automated tests untuk membuktikan kegagalan *fail-closed* saat tenant context tidak tersedia dan penolakan otorisasi supervisor lintas tenant.

---

### 3. INSTRUKSI IMPLEMENTASI TEKNIS KONKRET

#### A. Hardening `app/Modules/Customers/Services/LoyaltyService.php`

1. **Pada `calculateEarnablePoints(Customer $customer, float $dpp): int`**:
   - Gantikan resolusi tenant:
     ```php
     if ($dpp <= 0) {
         return 0;
     }

     $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());
     if ($tenantId <= 0) {
         throw new \RuntimeException('Konteks tenant yang valid wajib tersedia untuk kalkulasi poin loyalitas.');
     }
     ```
   - **Hapus seluruh pembungkus `if ($tenantId > 0)` dan `elseif ($tenantId > 0)`**.
   - Terapkan kueri langsung tanpa syarat:
     ```php
     $account = DB::table('customer_loyalty_accounts')
         ->where('tenant_id', $tenantId)
         ->where('customer_id', $customer->id)
         ->first();

     $multiplier = 1.00;
     if ($account && $account->current_tier_id) {
         $tier = DB::table('loyalty_tiers')
             ->where('tenant_id', $tenantId)
             ->where('id', $account->current_tier_id)
             ->where('is_active', true)
             ->first();
         if ($tier) {
             $multiplier = (float) $tier->points_multiplier;
         }
     } else {
         $baseTier = DB::table('loyalty_tiers')
             ->where('tenant_id', $tenantId)
             ->where('is_active', true)
             ->where('min_points', 0)
             ->first();
         if ($baseTier) {
             $multiplier = (float) $baseTier->points_multiplier;
         }
     }

     $baseUnits = floor($dpp / 10000);
     $earned = (int) floor($baseUnits * $multiplier);

     return max(0, $earned);
     ```

2. **Pada `checkAndUpgradeTier(Customer $customer, ?int $pointsOrSpend = null): ?LoyaltyTier`**:
   - Gantikan resolusi tenant:
     ```php
     $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());
     if ($tenantId <= 0) {
         throw new \RuntimeException('Konteks tenant yang valid wajib tersedia untuk evaluasi tier loyalitas.');
     }
     ```
   - **Hapus seluruh pembungkus `if ($tenantId > 0)` dan hapus blok duplikat fallback query**.
   - Terapkan kueri langsung:
     ```php
     $account = DB::table('customer_loyalty_accounts')
         ->where('tenant_id', $tenantId)
         ->where('customer_id', $customer->id)
         ->first();

     if (! $account) {
         return null;
     }

     $metric = $pointsOrSpend !== null ? $pointsOrSpend : (int) $account->lifetime_points_earned;

     $eligibleTier = LoyaltyTier::where('tenant_id', $tenantId)
         ->where('is_active', true)
         ->where('min_points', '<=', $metric)
         ->orderByDesc('min_points')
         ->first();

     if ($eligibleTier && (int) $account->current_tier_id !== (int) $eligibleTier->id) {
         DB::table('customer_loyalty_accounts')
             ->where('tenant_id', $tenantId)
             ->where('id', $account->id)
             ->update([
                 'current_tier_id' => $eligibleTier->id,
                 'updated_at' => Carbon::now(),
             ]);
     }

     return $eligibleTier;
     ```

---

#### B. Hardening `app/Modules/Sales/Services/ShiftService.php`

1. **Pada `openShift(...)`**:
   - Pada resolusi tenant kasir (sekitar baris 65–68), pastikan jika `$tenantId <= 0`, lempar exception:
     ```php
     if ($tenantId === null) {
         $tenantId = (int) $user->tenant_id;
     }
     if ($tenantId <= 0) {
         throw new \DomainException('Konteks tenant aktif tidak ditemukan untuk kasir ini.');
     }
     ```

2. **Pada `canAuthorizeSupervisor(User $user, int $storeId): bool`**:
   - Gantikan logika otorisasi dengan validasi batas tenant yang ketat:
     ```php
     public function canAuthorizeSupervisor(User $user, int $storeId): bool
     {
         $roleName = $user->role?->name;
         if (! in_array($roleName, ['Supervisor', 'Manager', 'Owner', 'Admin'], true) && ! (bool) $user->role?->is_admin) {
             return false;
         }

         $tenantId = (int) ($user->tenant_id ?? app(TenantContext::class)->requireTenantId());
         if ($tenantId <= 0) {
             throw new \DomainException('Konteks tenant aktif tidak ditemukan untuk verifikasi otorisasi supervisor.');
         }

         // 1. Guarding IDOR: Pastikan storeId yang diminta adalah sah milik tenant yang sama
         $storeBelongsToTenant = DB::table('stores')
             ->where('tenant_id', $tenantId)
             ->where('id', $storeId)
             ->exists();

         if (! $storeBelongsToTenant) {
             return false;
         }

         // 2. Owner & Admin memiliki wewenang atas seluruh cabang milik tenant-nya
         if (in_array($roleName, ['Owner', 'Admin'], true) || (bool) $user->role?->is_admin) {
             return true;
         }

         // 3. Supervisor & Manager: periksa assignment di user_stores milik tenant
         $hasAssignments = DB::table('user_stores')
             ->where('tenant_id', $tenantId)
             ->where('user_id', $user->id)
             ->exists();

         if (! $hasAssignments) {
             // Jika belum dibatasi ke cabang spesifik dalam tenant-nya, diizinkan untuk cabang tenant tersebut
             return true;
         }

         return DB::table('user_stores')
             ->where('tenant_id', $tenantId)
             ->where('user_id', $user->id)
             ->where('store_id', $storeId)
             ->exists();
     }
     ```

---

#### C. Penambahan Pengujian Otomatis di `CustomerTenantIsolationTest.php`

Tambahkan metode pengujian berikut ke dalam `tests/Feature/Customers/CustomerTenantIsolationTest.php`:

1. **`test_loyalty_service_fails_closed_when_tenant_context_is_missing()`**:
   - Bersihkan tenant context via `app(TenantContext::class)->clear()`.
   - Buat objek customer tiruan tanpa tenant: `$unscopedCustomer = new Customer(); $unscopedCustomer->id = 9999; $unscopedCustomer->tenant_id = null;`
   - Pastikan pemanggilan `$loyaltyService->calculateEarnablePoints($unscopedCustomer, 100000)` melempar `\RuntimeException`.
   - Pastikan pemanggilan `$loyaltyService->checkAndUpgradeTier($unscopedCustomer)` melempar `\RuntimeException`.

2. **`test_shift_supervisor_authorization_strictly_rejects_cross_tenant_store()`**:
   - Login sebagai Owner Tenant A (`$this->userAOwner`).
   - Panggil `app(ShiftService::class)->canAuthorizeSupervisor($this->userAOwner, $this->storeB->id)`.
   - **Assert:** Bernilai `false` (Meskipun pengguna adalah Owner di Tenant A, ia DITOLAK mengotorisasi Store B milik Tenant B).
   - Panggil `app(ShiftService::class)->canAuthorizeSupervisor($this->userAOwner, $this->storeA->id)`.
   - **Assert:** Bernilai `true` (Otorisasi sah di dalam tenant sendiri).

---

### 4. SAFEGUARDS (PERLINDUNGAN EKSEKUSI)
- Jalankan `git status --short` sebelum memulai.
- **DILARANG KERAS** menjalankan perintah `git checkout .`, `git reset --hard`, `git clean -fd`, atau `git stash pop`.
- Pertahankan seluruh perubahan yang sudah ada dari Batch 1.
- Eksekusi pengujian hanya pada basis data testing yang terisolasi.

---

### 5. KRITERIA PENERIMAAN (ACCEPTANCE CRITERIA)
1. Tidak ada kueri `DB::table(...)` di `LoyaltyService.php` yang dibungkus oleh `if ($tenantId > 0)`.
2. Tidak ada kueri `DB::table(...)` di `ShiftService.php` yang menggunakan `when($tenantId, ...)`.
3. Pemanggilan `LoyaltyService` tanpa tenant context melempar `\RuntimeException` (*fail-closed*).
4. Otorisasi supervisor menolak mutlak percobaan otorisasi pada cabang milik tenant lain (*zero cross-tenant authorization leak*).
5. Seluruh pengujian di `tests/Feature/Customers/` dan `tests/Feature/Domain2/PosOrderCreationTest.php` lulus 100% tanpa regresi.
6. Analisis statis Larastan pada file yang dimodifikasi menghasilkan 0 error.

---

### 6. PERINTAH VERIFIKASI PENGUJIAN
Jalankan perintah berikut dan periksa hasilnya:
```bash
./vendor/bin/phpunit tests/Feature/Customers/CustomerTenantIsolationTest.php
./vendor/bin/phpunit tests/Feature/Customers/
./vendor/bin/phpunit tests/Feature/Domain2/PosOrderCreationTest.php
./vendor/bin/phpstan analyse --memory-limit=1G app/Modules/Customers/Services/LoyaltyService.php app/Modules/Sales/Services/ShiftService.php tests/Feature/Customers/CustomerTenantIsolationTest.php
```

---

### 7. FORMAT LAPORAN HASIL KERJA
Setelah implementasi selesai, serahkan laporan hasil kerja dalam format:
```markdown
### LAPORAN EKSEKUSI BATCH 1A (FAIL-CLOSED HARDENING)
1. **Ringkasan Perubahan:** [Jelaskan eliminasi soft-conditional filtering]
2. **File yang Dimodifikasi:** [Daftar file]
3. **Hasil Eksekusi Pengujian:**
   - CustomerTenantIsolationTest: [X passed, Y assertions]
   - Full Customers Feature Suite: [X passed, Y assertions]
   - PosOrderCreationTest: [X passed, Y assertions]
4. **Hasil Static Analysis (PHPStan):** [Output PHPStan]
5. **Konfirmasi Fail-Closed:** [Bukti bahwa missing tenant context melempar exception dan cross-tenant supervisor ditolak]
```

```
================================================================================
=== END OF PROMPT BATCH 1A ===
================================================================================
```

---
*Instruksi Batch 1A ini disusun secara resmi oleh A0 (Principal Database Security Auditor) sebagai penyempurnaan langsung terhadap arsitektur isolasi multi-tenant.*
