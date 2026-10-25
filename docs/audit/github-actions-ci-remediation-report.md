# APEIRON POS — GITHUB ACTIONS CI REMEDIATION REPORT

## 1. Status Kegagalan CI GitHub Actions

- **Repository:** `IlhamSpryd/point-of-Sales`
- **Workflow:** `CI` (`.github/workflows/tests.yml`)
- **Initial Failed Run ID:** `40228513839` (Commit `953bf29` pada `main`)
- **Second Monitored Run ID:** `40230710413` (Commit `a0d90ea` pada `main`)
- **Event:** `push`

---

## 2. Investigasi Root Cause dan Remediasi Bertahap

### Masalah 1: Pelanggaran Check Constraint pada Seeder (Run 40228513839)
- **Job:** `Tests (MySQL nyata)`
- **Langkah Gagal:** `Smoke test migrasi + seeder` (`php artisan migrate:fresh --seed --force`)
- **Error:**
  ```text
  SQLSTATE[HY000]: General error: 3819 Check constraint 'chk_sm_type_enum' is violated.
  ```
- **Akar Masalah:**
  Pada migrasi `2027_01_02_000001_enhance_stock_balances_and_movements_for_module4.php` (baris 61–66), constraint `chk_sm_type_enum` membatasi tipe pergerakan stok menjadi 14 enum kanonik. Namun seeder `database/seeders/Pos/OrderSeeder.php` baris 464 menyisipkan `'type' => 'out'`.
- **Remediasi:**
  Mengubah `'type' => 'out'` menjadi `'type' => 'sale_deduction'` pada `database/seeders/Pos/OrderSeeder.php`.
- **Hasil Verifikasi CI Remote (Run 40230710413):**
  Langkah `Smoke test migrasi + seeder` **LULUS 100% (Green / ✓)**.

---

### Masalah 2: Hak Akses User MySQL & Konfigurasi Paratest Parallel (Run 40230710413)
- **Job:** `Tests (MySQL nyata)`
- **Langkah Gagal:** `Test suite (paralel, tanpa grup chaos)` (`php artisan test --parallel --exclude-group=chaos`)
- **Error:**
  ```text
  Illuminate\Database\QueryException: SQLSTATE[HY000] [1045] Access denied for user 'laravel_pos'@'172.18.0.1' (using password: YES) (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: yovel_pos_test, SQL: drop database if exists `yovel_pos_test_test_1`)
  ```
- **Akar Masalah:**
  1. File `phpunit.xml` menggunakan atribut `force="true"` untuk `DB_USERNAME=laravel_pos` dan `DB_DATABASE=yovel_pos_test`.
  2. Saat runner GitHub Actions menjalankan `php artisan test --parallel`, Paratest mencoba membuat database paralel terisolasi (`yovel_pos_test_test_1`, `test_2`, dst.).
  3. Namun, container layanan MySQL di GitHub Actions runner hanya memiliki user `root`, dan `laravel_pos` belum di-provisioning ataupun diberi hak `CREATE/DROP DATABASE`.
  4. Selain itu, suite `TransactionConcurrencyTest` yang menguji process-level concurrency locking dan child-process fork belum dianotasi `@group chaos`, sehingga ikut terseret dalam eksekusi paratest paralel.
- **Remediasi Terpadu:**
  1. **`phpunit.xml`:** Mengubah `force="true"` menjadi `force="false"` dan menetapkan nilai default CI (`DB_USERNAME=root`, `DB_PASSWORD=root`, `DB_DATABASE=apeiron_pos_test`) sehingga environment variabel lokal tetap dapat override secara aman.
  2. **`.github/workflows/tests.yml`:** Pada langkah *Pastikan database MySQL siap*, ditambahkan provisioning user `laravel_pos` dengan `GRANT ALL PRIVILEGES ON *.* WITH GRANT OPTION` baik dari `%` maupun `localhost`.
  3. **`tests/Feature/Domain2/TransactionConcurrencyTest.php`:** Menambahkan anotasi `@group chaos` pada docblock class agar dieksekusi secara serial pada langkah khusus chaos suite (`php artisan test --group=chaos`) dan tidak bentrok dengan eksekusi paralel paratest.

---

## 3. Ringkasan File yang Diubah

| File | Alasan Perubahan |
|---|---|
| `database/seeders/Pos/OrderSeeder.php` | Memperbaiki constraint violation `chk_sm_type_enum` (`sale_deduction`). |
| `phpunit.xml` | Melepas hardcoded `force="true"` agar fleksibel antara CI runner dan mesin dev lokal. |
| `.github/workflows/tests.yml` | Menjamin user `laravel_pos` & `root` memiliki akses penuh membuat database testing paralel. |
| `tests/Feature/Domain2/TransactionConcurrencyTest.php` | Menandai suite sebagai `@group chaos` agar berjalan serial terisolasi. |
| `app/Modules/Sales/Services/TransactionService.php` | Memperbaiki validasi meja sesuai `INV-TBL-03` dan `INV-TBL-04`. |
| `docs/audit/*` | Dokumentasi komprehensif bukti audit, remediasi CI, dan pengujian. |
