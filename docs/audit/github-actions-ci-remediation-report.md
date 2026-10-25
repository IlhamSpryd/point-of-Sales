# APEIRON POS — GITHUB ACTIONS CI REMEDIATION REPORT

## 1. Status Kegagalan CI GitHub Actions

- **Repository:** `IlhamSpryd/point-of-Sales`
- **Workflow:** `CI` (`.github/workflows/tests.yml`)
- **Failed Run ID:** `40228513839`
- **Commit:** `953bf2946e8a4c51579edeedd69e8db801ea85fb` (Merge PR #6 ke `main`)
- **Event:** `push`

### Ringkasan Status Job pada Run 40228513839:
1. **Job `Lint & Static Analysis` (ID: 121275038463):**
   - Status: **BERHASIL (Passed / Green)** dalam 1m8s.
   - Langkah yang lulus: setup PHP 8.3, Composer install, `.env` generation, Laravel Pint style check, PHPStan/Larastan analyse.
2. **Job `Tests (MySQL nyata)` (ID: 121275038659):**
   - Status: **GAGAL (Failed / Red)** pada langkah `Smoke test migrasi + seeder` (exit code 1).
   - Langkah selanjutnya (`Test suite paralel`, `Chaos / concurrency suite`) tidak sempat dieksekusi karena pipeline dihentikan akibat kegagalan langkah smoke test.

---

## 2. Root Cause Analysis (Investigasi Bukti Log)

Berdasarkan pemeriksaan log aktual via GitHub CLI (`gh run view 40228513839 --log-failed`), kegagalan terjadi saat eksekusi command:
```bash
php artisan migrate:fresh --seed --force
```

### Cuplikan Log Error:
```text
Tests (MySQL nyata)	Smoke test migrasi + seeder	2026-10-25T19:59:35.8683796Z In MySqlConnection.php line 53:
Tests (MySQL nyata)	Smoke test migrasi + seeder	2026-10-25T19:59:35.8683972Z                                                                                
Tests (MySQL nyata)	Smoke test migrasi + seeder	2026-10-25T19:59:35.8684224Z   SQLSTATE[HY000]: General error: 3819 Check constraint 'chk_sm_type_enum' is  
Tests (MySQL nyata)	Smoke test migrasi + seeder	2026-10-25T19:59:35.8684414Z    violated.                                                                   
Tests (MySQL nyata)	Smoke test migrasi + seeder	2026-10-25T19:59:35.8684579Z                                                                                
Tests (MySQL nyata)	Smoke test migrasi + seeder	2026-10-25T19:59:35.8694712Z ##[error]Process completed with exit code 1.
```

### Akar Masalah:
1. **Definisi Constraint Database:**
   Pada migrasi `database/migrations/2027_01_02_000001_enhance_stock_balances_and_movements_for_module4.php` (baris 61–66):
   ```sql
   ALTER TABLE stock_movements ADD CONSTRAINT chk_sm_type_enum CHECK (type IN (
       'restore_compensation','sale_deduction','initial_sync','adjustment',
       'goods_receipt','goods_receipt_reversal','transfer_out','transfer_in',
       'transfer_return_in','transfer_shrinkage','adjustment_in','adjustment_out',
       'opname_adjustment','supplier_return_out'
   ))
   ```
2. **Implementasi Seeder:**
   Pada `database/seeders/Pos/OrderSeeder.php` baris 464, pemotongan stok untuk order status `paid` menyisipkan:
   ```php
   'type' => 'out',
   ```
3. **Penyebab Pelanggaran:**
   String literal `'out'` bukan merupakan salah satu dari 14 nilai enum yang diizinkan oleh `chk_sm_type_enum`. Pada MySQL 8.0 nyata di runner GitHub Actions, CHECK constraint ditegakkan secara ketat sehingga query `INSERT INTO stock_movements` melemparkan exception fatal `SQLSTATE[HY000]: General error: 3819`.

---

## 3. Remediasi Kode

### File yang Dimodifikasi:
`database/seeders/Pos/OrderSeeder.php`

### Diff Perbaikan:
```diff
--- a/database/seeders/Pos/OrderSeeder.php
+++ b/database/seeders/Pos/OrderSeeder.php
@@ -461,7 +461,7 @@ public function run(): void
                             'product_id' => $productId,
                             'order_id' => $orderId,
                             'order_item_id' => $oi['id'],
-                            'type' => 'out',
+                            'type' => 'sale_deduction',
                             'quantity' => -$itemQty,
                             'reason' => 'Penjualan order #'.$orderId,
                             'idempotency_key' => Str::uuid()->toString(),
```

### Justifikasi Arsitektur:
- Nilai `'sale_deduction'` adalah representasi kanonik domain Apeiron POS untuk pengurangan stok akibat transaksi penjualan POS (sesuai `App\Enums\StockMovementType::SaleDeduction->value` dan spesifikasi ledger append-only `T-ISM1`).
- Nilai ini sepenuhnya memenuhi constraint database `chk_sm_type_enum`.

---

## 4. Verifikasi Lokal Sebelum Push

1. **Static Analysis & Linting:**
   - `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`: LULUS (0 error).
   - `vendor/bin/pint --test`: LULUS (0 style violations).
   - `npm run build`: LULUS (Vite production build sukses, exit code 0).
2. **Database Engine Test:**
   - Dilakukan verifikasi terhadap database MySQL nyata (`yovel_pos_test`).
   - Seluruh test feature (Auth, Security, Catalog, Inventory, TableManagement, Concurrency) dieksekusi dengan sukses.
