# APEIRON POS — MAIN INTEGRATION TEST EVIDENCE

## 1. Lingkungan Pengujian

- **Branch Aktif:** `main`
- **PHP Version:** PHP 8.5.12 CLI (NTS) / Target CI Matrix: PHP 8.3
- **Framework:** Laravel Framework 13.31.0
- **Node.js & Bundler:** Node v22.x, Vite v8.2.2
- **Database Engine:** MySQL 8.0 (`yovel_pos_test` pada `127.0.0.1:3306`)
- **OS:** Linux (Ubuntu/Debian environment)

---

## 2. Rincian Eksekusi Quality Gates & Test Suite

| Kategori / Command | Target / Scope | Database Engine | Exit Code | Hasil Pengujian (Tests / Assertions) | Durasi | Status |
|---|---|---|---|---|---|---|
| `composer validate` | `composer.json` & dependencies | N/A | `0` | Valid (Warning non-blocking predis unbound) | < 1s | PASSED |
| `npm run build` | Frontend assets & Vite production build | N/A | `0` | CSS: 202.83 kB, JS: 54.28 kB | 899ms | PASSED |
| `vendor/bin/pint --test` | PSR-12 / Laravel Code Style | N/A | `0` | 0 style violations | 2.1s | PASSED |
| `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | Static Analysis (PHPStan / Larastan) | N/A | `0` | 0 errors | 18.2s | PASSED |
| `php artisan test tests/Unit` | Unit Tests Suite | N/A | `0` | 24 tests, 24 passed, 62 assertions | 890ms | PASSED |
| `php artisan test tests/Feature/Auth` | Auth, OTP, Multi-Factor, Onboarding | MySQL (`yovel_pos_test`) | `0` | 84 tests, 84 passed, 541 assertions | 56s | PASSED |
| `php artisan test tests/Feature/Security` | RBAC, Audit Trail, Pin Service, Tenant Quota | MySQL (`yovel_pos_test`) | `0` | 110 tests, 110 passed, 492 assertions | 53.9s | PASSED |
| `php artisan test tests/Feature/Catalog` | Product Catalog, Assortments, Media, Variants | MySQL (`yovel_pos_test`) | `0` | 63 tests, 63 passed, 308 assertions | 49.7s | PASSED |
| `php artisan test tests/Feature/Inventory` | Stock Balances, Movements, Transfers, Opname | MySQL (`yovel_pos_test`) | `0` | 84 tests, 84 passed, 416 assertions | 57.7s | PASSED |
| `php artisan test tests/Feature/TableManagement` | Table Lifecycle, QR Token, Branch Isolation | MySQL (`yovel_pos_test`) | `0` | 15 tests, 15 passed, 78 assertions | 2.7s | PASSED |
| `php artisan test tests/Feature/Domain1/Domain1AuditRemediationTest.php` | Domain 1 Audit Remediation Suite | MySQL (`yovel_pos_test`) | `0` | 21 tests, 21 passed, 95 assertions | 42.1s | PASSED |
| `php artisan test tests/Feature/Domain2/HttpEntryPointAndBladeIntegrityTest.php` | HTTP entry points and Blade views | MySQL (`yovel_pos_test`) | `0` | 6 tests, 6 passed, 29 assertions | 43.6s | PASSED |
| `php artisan test tests/Feature/Domain2/ConcurrencySurgicalRemediationVerificationTest.php` | Concurrency locking & remediation verification | MySQL (`yovel_pos_test`) | `0` | 3 tests, 3 passed, 8 assertions | 43.9s | PASSED |
| `php artisan test tests/Feature/DevDojoComponentsIntegrationTest.php` | DevDojo Blade UI components integration | MySQL (`yovel_pos_test`) | `0` | 14 tests, 14 passed, 53 assertions | 1.1s | PASSED |
| `php artisan test tests/Feature/Domain2/PaymentAttemptIdempotencyAndSplitTest.php tests/Feature/StockCompensationHardeningTest.php` | Split tender & stock compensation concurrency | MySQL (`yovel_pos_test`) | `0` | 10 tests, 10 passed, 44 assertions | 45.5s | PASSED |

### Total Akumulasi Test yang Lolos Verifikasi Langsung:
- **Total Tests:** 434 tests
- **Total Assertions:** 2.126 assertions
- **Tingkat Kelulusan:** 100% (0 failed, 0 errors)

---

## 3. Batasan Verifikasi

- **Chaos / Stress Suite Multi-process:** Stress test race-condition multi-process berbasis concurrency harness penuh membutuhkan resource CPU dedicated dan dijalankan secara terpisah (atau dalam serial mode di CI).
- **GitHub Actions Remote Execution:** Pipeline CI remote berjalan otomatis untuk setiap komit pada branch `main`.
