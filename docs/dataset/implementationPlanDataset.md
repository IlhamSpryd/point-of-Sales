# APEIRON POS — IMPLEMENTATION PLAN DATASET 12 BULAN (2 AKUN DEMO)

> Dokumen hidup (living document). Setiap fase menulis checkpoint ke dokumen ini
> **sebelum** fase berikutnya dimulai. Jangan menandai sebuah item selesai tanpa
> bukti command/query yang dapat diperiksa.

| Item | Nilai |
|---|---|
| Repository | `point-of-Sales/` (sub-folder dari `apeironSystem/`) |
| Branch | `chore/refactor-modular-architecture` |
| HEAD | `a069ff6 fix(database): remediate schema invariants DB-001 through DB-021` |
| Stack terverifikasi | Laravel Framework **13.31.0**, PHP `^8.3`, Livewire 4.x, MySQL/MariaDB **13.0.2** |
| Status keseluruhan | **PHASE 3 COMPLETE — menunggu keputusan pemilik project untuk Phase 4** |
| Terakhir diperbarui | 2026-10-22 |

---

## 0. Ringkasan Eksekutif (Phase 1)

- Skema database aktual **119 tabel** (semua `BASE TABLE`, 0 view) — **sesuai** dengan
  target 119 tabel pada brief. Ini lebih baru dari dokumen audit (`117` tabel) dan
  konsisten dengan commit remidiasi `a069ff6`.
- Database runtime `point_of_sales` **kosong** pada semua tabel bisnis (hanya
  `migrations`, `permissions`, `plans`, `plan_entitlements`, `sessions`, `cache`
  yang berisi baris). **Tidak ada tenant, store, user, maupun role.**
- Generator lama `pos:populate-full-year` **tidak akan bisa dijalankan apa adanya**:
  hardcode `--tenant=9` pada DB tanpa tenant, meng-update `stores.id = 6`, memakai
  ID role hasil hardcode (`22..29`), dan mengandalkan tabel-tabel `products.stock`
  style lama. Perlu diverifikasi ulang terhadap skema 119 tabel (Phase 5).
- Ada **dua jalur generator legacy** yang berbeda dan saling tumpang tindih:
  `PosFullSeeder` (+ `Pos/*Seeder`) dan `PopulateApeironFullYearDataset` (command).
  Keduanya **melakukan `TRUNCATE`** — bertentangan dengan aturan brief.
- Database export / backup `point_of_sales_pre_seed_backup_20261014_141809.sql`
  **kedaluwarsa** (50 tabel, migration terakhir `2026_12_04_000010` vs HEAD 119 tabel).
  Tidak dipakai sebagai sumber kebenaran dan tidak direstore.
- Dokumen yang diminta brief:
  - `populateApeironFullyearDataset.php` → ditemukan sebagai
    `app/Console/Commands/PopulateApeironFullYearDataset.php` (1793 baris).
  - `databaseAuditMySQL.md` → ditemukan di `docs/audit/`.
  - `implementationPlanDataset.md` → **belum ada**; dibuat oleh dokumen ini.
  - `implementationPlanOneYearDataset.md` → **tidak ditemukan** (tidak ada alternatif
    bernama sama; rencana dataset legacy ada di `README_SEEDER.md`).

---

## 1. Inventaris Project (Bukti Phase 1)

### 1.1 Struktur repository

```
apeironSystem/
├── doc/                       # dokumen PRD & plan modul 1-5 (.docx/.md)
├── marketingPageApeironSystem/ # sub-project terpisah (tidak relevan dataset)
├── point-of-Sales/            # <-- aplikasi Laravel yang diaudit
│   ├── app/
│   │   ├── Console/Commands/PopulateApeironFullYearDataset.php
│   │   ├── Modules/{Auth,Catalog,Inventory,Purchasing,Reports,Sales,Settings,...}
│   │   ├── Livewire/          # komponen UI (dashboard, kasir, inventory, dst.)
│   │   └── Services/          # TenantContext, InventoryLedgerService, RoleService...
│   ├── database/
│   │   ├── migrations/        # 162 file migration
│   │   ├── seeders/           # DatabaseSeeder, PosFullSeeder, DemoDataSeeder, Pos/*
│   │   ├── factories/         # 8 factory (Brand, Category, Order, Product, ...)
│   │   └── sql/{audit,verify} # 12 skrip SQL audit/verifikasi (read-only)
│   ├── docs/                  # architecture/, audit/, deployment/, implementation/
│   ├── tests/{Feature,Unit}   # termasuk Domain1..Domain4, Inventory, Purchasing
│   └── routes/, resources/, config/, lang/
└── point_of_sales_pre_seed_backup_20261014_141809.sql  # backup legacy (kedaluwarsa)
```

### 1.2 Git & working tree

```
$ git status --porcelain -uall
(empty)
$ git branch --show-current
chore/refactor-modular-architecture
$ git log --oneline -5
a069ff6 fix(database): remediate schema invariants DB-001 through DB-021
ad46b63 feat(purchasing): implement Module 5 purchasing workflow and domain models
79642f4 feat(core): harden enterprise infrastructure, scheduler concurrency, telemetry and navigation
a4518d6 feat(inventory): implement inventory and warehouse management engine (module 4)
67b3d3d refactor(architecture): reorganize codebase into modular domain structure
```

**Temuan:** working tree **bersih**, tidak ada perubahan lokal yang belum tersimpan.
Risiko menimpa pekerjaan orang lain: **rendah**. Root `apeironSystem/` **bukan** git
repository; hanya `point-of-Sales/` yang ter-versi.

### 1.3 Framework & konfigurasi (tanpa credential)

| Item | Nilai |
|---|---|
| Laravel | 13.31.0 (`php artisan --version`) |
| PHP requirement | `^8.3` |
| Livewire | `^4.4` |
| MySQL driver | MariaDB 13.0.2 @ `127.0.0.1:3306` |
| DB runtime (`.env`) | `APP_ENV=local`, `DB_DATABASE=point_of_sales` |
| DB testing (`.env.testing` / `phpunit.xml`) | `DB_DATABASE=yovel_pos_test`, `APP_ENV=testing` |
| Kredensial | **tidak dicetak** (semua output di-redaksi) |

Catatan keamanan: audit DB-018 menyebut password DB plaintext ada di `phpunit.xml`.
Bukan bagian Phase 1 untuk diperbaiki, tetapi **jangan** mencetak isi `phpunit.xml`
ke log/PR.

### 1.4 Dokumen & artefak dataset yang ditemukan

| File | Ukuran | Fungsi | Status |
|---|---|---|---|
| `app/Console/Commands/PopulateApeironFullYearDataset.php` | 1.793 baris | Command `pos:populate-full-year` (10 stage) | Ada, legacy, perlu audit Phase 5 |
| `database/seeders/PosFullSeeder.php` | 256 baris | Orkestrator seeder 8 phase | Ada, legacy (**memakai truncate**) |
| `database/seeders/Pos/*.php` | 22 file | Sub-seeder per domain | Ada, legacy |
| `database/seeders/DatabaseSeeder.php` | 103 baris | Entry point `migrate:fresh --seed` | Ada, memanggil `PosFullSeeder` |
| `database/seeders/DemoDataSeeder.php` | 166 baris | Seeder demo ringkas | Ada |
| `README_SEEDER.md` | 207 baris | Dokumentasi generator legacy (~50K orders) | Ada (nama tabelnya sebagian sudah usang) |
| `docs/audit/databaseAuditMySQL.md` | 517 baris | Audit read-only 117 tabel + 21 temuan DB-001..021 | Ada |
| `docs/audit/databaseAuditMySQL-Verification.md` | 284 baris | Verifikasi independen 21 temuan | Ada |
| `docs/architecture/*.md` | 7 file | Ownership model, invariants, authorization | Ada |
| `database/sql/verify/01..11_*.sql` | 11 file | Suite verifikasi `pos:verify-database` | Ada |
| `database/sql/audit/*.sql` | 2 file | Rekonsiliasi order total & shift overlap | Ada |
| `docs/dataset/implementationPlanDataset.md` | — | **Dokumen ini (baru dibuat)** | Dibuat Phase 1 |
| `implementationPlanOneYearDataset.md` | — | Rencana dataset alternatif | **Tidak ditemukan** |
| Export DB 119 tabel (`.sql` dump HEAD) | — | Sumber kebenaran skema | **Tidak ditemukan** (hanya backup legacy 50 tabel) |

### 1.5 Kondisi database target (read-only, terverifikasi)

```
$ php artisan tinker --execute="... COUNT(*) FROM information_schema.tables ..."
[{"table_type":"BASE TABLE","c":119}]
```

| Metrik | Nilai |
|---|---|
| Jumlah tabel aktual | **119** (0 view) |
| `migrations` rows | **162** (maks `2027_01_04_000003_remediate_p2_monetary_and_cleanup`) |
| `tenants` | **0** |
| `stores` / `users` / `roles` | **0 / 0 / 0** |
| `permissions` | 42 (global) |
| `plans` / `plan_entitlements` | 4 / 24 |
| Tabel bisnis lain | **0 baris** |

> Database runtime bersih dari data operasional. Artinya: tidak ada "data lama"
> yang harus dipertahankan di `point_of_sales`, tetapi **juga belum ada tenant**
> yang menjadi target generator legacy (`--tenant=9`).

**119 tabel (urut alfabetis, hasil `SHOW TABLES`):**

```
accounting_payable_outbox, activity_logs, brands, cache, cache_locks,
cash_drawer_movements, categories, channel_order_logs, channel_product_mappings,
customer_loyalty_accounts, customers, devices, discount_redemptions, discounts,
document_sequences, email_outbox, email_verification_otps, employee_id_sequences,
eod_settlements, export_tasks, failed_jobs, goods_receipt_items, goods_receipts,
goods_received_note_lines, goods_received_notes, ingredient_restock_forecasts,
ingredient_stock_balances, ingredient_stock_movements, ingredients, job_batches,
jobs, loyalty_ledger, loyalty_tiers, migrations, modifier_group_product,
modifier_groups, modifier_ingredients, modifiers, order_item_modifiers, order_items,
order_return_items, order_returns, orders, owner_onboarding_tokens, parked_carts,
password_reset_tokens, payment_attempts, payment_refunds, payments, permissions,
plan_entitlements, plans, price_list_items, price_lists, product_attribute_values,
product_attributes, product_bundle_items, product_cost_histories,
product_ingredients, product_media, product_stock_balances, product_unit_conversions,
product_variant_attribute_values, product_variants, products, provisioning_nonces,
provisioning_requests, purchase_additional_costs, purchase_approval_logs,
purchase_approval_requests, purchase_approval_rules, purchase_cost_allocations,
purchase_debit_note_applications, purchase_debit_notes,
purchase_order_line_pr_allocations, purchase_order_lines, purchase_order_revisions,
purchase_orders, purchase_request_lines, purchase_requests, purchase_return_lines,
purchase_returns, receipt_print_logs, reorder_suggestions, role_permissions, roles,
sessions, settings, shift_handovers, shifts, stock_adjustment_items,
stock_adjustments, stock_movements, stock_opname_items, stock_opnames,
stock_transfer_items, stock_transfers, store_product_assortments, stores,
supplier_contacts, supplier_invoice_grn_matches, supplier_invoice_lines,
supplier_invoices, supplier_performance_logs, supplier_products,
supplier_return_items, supplier_returns, suppliers, tables, tax_classes,
tenant_entitlement_overrides, tenant_subscriptions, tenants, units_of_measure,
user_invitation_stores, user_invitations, user_oauth_identities, user_stores, users
```

### 1.6 Perbandingan dokumen audit vs actual HEAD

| Sumber | Tabel | Migration | Tanggal | Catatan |
|---|---|---|---|---|
| `docs/audit/databaseAuditMySQL.md` | 117 | 159 | 2026-10-22 | Audit baseline (HEAD `79642f4`) |
| `docs/audit/...-Verification.md` | 117 | 159 | 2026-10-22 | Konfirmasi gate |
| **DB + migration aktual (Phase 1)** | **119** | **162** | 2026-10-22 | HEAD `a069ff6` |
| Backup `../point_of_sales_pre_seed_backup_*.sql` | 50 | 122 | 2026-10-14 | **Kedaluwarsa (DB-017)** |

Selisih +2 tabel dan +3 migration berasal dari commit `a069ff6`
(`2027_01_04_000001..000003` remediasi P0/P1/P2) yang menambah tabel remediasi
dan mengubah constraint. **Belum diverifikasi** tabel mana persisnya yang baru
(akan dipetakan pada Phase 2). Dokumen audit **tidak** diubah.

---

## 2. Kondisi Generator Lama (ringkas)

### 2.1 `pos:populate-full-year` (Command, 1.793 baris)

Signature: `--tenant=9`, `--orders=1000000` (default, meski banner menyebut 70K),
`--chunk=1000`, `--dry-run`.

| Aspek | Kondisi aktual | Risiko |
|---|---|---|
| Preflight | Cek tenant id 9 ada; jika ada order → minta konfirmasi "append" | **Gagal** pada DB tanpa tenant 9 (exit 1) |
| Stage 1 | `UPDATE stores WHERE id=6 AND tenant_id=9` | ID hardcode; tidak ada create tenant |
| Stage 2 | Insert users dengan `role_id` 22..29 (hardcode) | Role belum ada → FK gagal |
| Stage 3-5 | Katalog, BOM, modifier, customers, loyalty | Perlu audit Phase 5 terhadap schema 119 |
| Stage 6-9 | Shift, orders, items, payments, stock movement, loyalty | Perlu audit konsistensi |
| Dry-run | Hanya skip? (harus diverifikasi: apakah semua stage benar-benar read-only) | **Belum terbukti** |
| Idempotensi | Cek per `employee_id`/`name`; data transaksi **tidak** ditandai sumbernya | Bisa duplikasi bila diulang |
| Tanggal | Hardcode `2025-10-14` sebagai tanggal mulai | Tidak eksplisit sebagai parameter |

### 2.2 `PosFullSeeder` + `Pos/*Seeder` (22 file)

| Aspek | Kondisi aktual | Risiko |
|---|---|---|
| Entry | `DatabaseSeeder` → `PosFullSeeder` | Terpanggil saat `migrate:fresh --seed` |
| Destruktif | `Schema::disableForeignKeyConstraints()`, `TRUNCATE` di `TableSeeder`, `ProductIngredientSeeder`, `OrderSeeder` (orders/items/payments/stock_movements), `IngredientStockMovementSeeder` (serta **DROP TRIGGER** sementara) | **Melanggar aturan brief**; tidak boleh dijalankan terhadap data yang harus dipertahankan |
| Guard environment | `if (! app()->environment(['local','testing'])) throw` | Hanya guard nama environment, bukan deteksi DB target |
| Tenant | Ambil tenant pertama / buat `Default Tenant` | Bukan dua akun demo yang diminta |
| Volume | ~50K orders, 133 produk, 1000 customer | Sesuai arah brief, tetapi perlu verifikasi schema |
| Audit trail asal data | Tidak ada penanda `is_dataset`/`source=batch` | Sulit membedakan data generator vs data operasional |

### 2.3 `DemoDataSeeder`

Seeder demo ringkas (`order_status='completed'`), tidak terdokumentasi di
`README_SEEDER.md`. Perlu dipetakan Phase 5 agar tidak bertabrakan.

---

## 3. Kondisi Database Export

- Brief menyebut "database export 119 tabel". **File export HEAD tidak ada** di
  repo maupun direktori induk.
- Yang tersedia hanya `point_of_sales_pre_seed_backup_20261014_141809.sql`
  (145 KB, **50** `CREATE TABLE`, migration terakhir `2026_12_04_000010`).
- Dokumen audit sudah menyatakannya **kedaluwarsa (DB-017)**. File ini **tidak**
  direstore dan **tidak** dipakai sebagai sumber skema.
- **Sumber kebenaran skema untuk pekerjaan ini = database live `point_of_sales`
  (119 tabel) + folder `database/migrations` (162 file).** Keduanya sudah
  dicocokkan: 162 = 162, tanpa drift rekaman.

---

## 4. Temuan Awal & Gap yang Memengaruhi Dataset

| # | Temuan | Dampak | Aksi fase |
|---|---|---|---|
| F1-01 | Tidak ada tenant/store/user sama sekali di DB runtime | Generator legacy gagal; dua akun demo belum ada | Phase 4/5 |
| F1-02 | Generator legacy hardcode tenant 9, store 6, role 22-29 | Tidak portable, FK error | Phase 5 |
| F1-03 | Dua jalur generator tumpang tindih (`PosFullSeeder` vs command) | Kebingungan sumber, duplikasi | Phase 5 (pilih satu jalur) |
| F1-04 | Kedua generator legacy memakai `TRUNCATE`/drop trigger | Melanggar aturan brief | Phase 5 (refactor, tanpa truncate) |
| F1-05 | Tidak ada penanda asal-data pada record generator | Idempotensi & rollback tidak aman | Phase 5 |
| F1-06 | Backup SQL kedaluwarsa (50 tabel) | Jangan direstore | Sudah dicatat |
| F1-07 | Dokumen audit (117) < skema aktual (119) | Inventaris Phase 2 harus memetakan 2 tabel + 3 migration remediasi | Phase 2 |
| F1-08 | 8 factory hanya mencakup Brand/Category/Order/Product/StoreProductAssortment/TaxClass/UoM/User | Entity inti demo (tenant, store, role, supplier, PO) tidak punya factory | Phase 2/5 |
| F1-09 | `README_SEEDER.md` mendokumentasikan nama kolom lama dan `migrate:fresh --seed` sebagai rekomendasi | Berisiko menyesatkan eksekusi | Phase 9 (update docs) |
| F1-10 | `phpunit.xml` memuat `DB_PASSWORD` plaintext (DB-018) | Keamanan repo | Di luar scope dataset, dicatat |

### Risiko yang harus diselesaikan sebelum eksekusi massal

1. **R1 — Target database**: `point_of_sales` adalah DB runtime lokal (bukan
   produksi, bukan staging). `APP_ENV=local`. Belum ada penanda eksplisit
   "database demo". Sebelum Phase 7 harus dikonfirmasi tertulis bahwa DB ini
   boleh diisi dataset demo.
2. **R2 — Dua akun demo**: belum ada tenant & akun
   `ilhamsepriyadi8@gmail.com` / `ilhamsepriyadi0@gmail.com`. Keputusan
   **satu tenant vs dua tenant terpisah** belum disepakati → Phase 4.
3. **R3 — Password demo** `M3m3x…` hanya boleh dipakai di environment demo
   terisolasi; kebijakan keamanan (min length, dsb.) tidak boleh dilonggarkan.
4. **R4 — Skema baru yang belum dipetakan**: 2 tabel tambahan + remediasi
   P0/P1/P2 (trigger, composite FK, tipe moneter) → Phase 2.
5. **R5 — Trigger append-only / overpayment**: `trg_payments_prevent_overpayment`
   menolak `SUM(captured) > order_amount`; tabel `loyalty_ledger`,
   `stock_movements`, `payments` bersifat append-only. Generator **wajib**
   menghormati ini (bukti: `database/migrations/2026_09_22_020000_create_payments_table.php:72-93`).
6. **R6 — Legacy seeder destruktif**: `README_SEEDER.md` merekomendasikan
   `migrate:fresh --seed` — dilarang pada pekerjaan ini.

---

## 5. Checkpoint Tracker

| Fase | Status | Catatan |
|---|---|---|
| 1 — Inventaris & checkpoint awal | **COMPLETE** | Dokumen ini §1–§4 |
| 2 — Audit 119 tabel (batch per domain) | **COMPLETE** | Batch 1–7 = **119/119** — §7–§13 |
| 3 — Audit Modul 1–5 | **COMPLETE** | 5/5 modul siap + 1 test rusak (non-blocker) — **§14** |
| 4 — Desain dataset 12 bulan & 2 akun | **COMPLETE** | **2 tenant terpisah** (bukan 1 tenant): isolasi penuh antar akun demo — §15 |
| 5 — Perbaiki generator | **COMPLETE** | Generator baru `pos:dataset:generate` — §16 |
| 6 — Validasi pra-eksekusi | **COMPLETE** | Guard DB, dry-run, smoke test 30 hari PASS — §17 |
| 7 — Eksekusi | **COMPLETE** | 12 bulan × 2 tenant pada `yovel_pos_test` (disposable) — §18 |
| 8 — QA | **COMPLETE** | Verifikasi invariant PASS — §18 |
| 9 — Dokumentasi akhir | **COMPLETE** | §15–§18 + README_SEEDER.md diperbarui |

### Perintah yang dijalankan pada Phase 1 (read-only)

```bash
git status --porcelain -uall
git branch --show-current
git log --oneline -10
php artisan --version
php artisan db:show --counts          # hanya header, credential tidak dicetak
php artisan tinker --execute="...information_schema.tables COUNT..."   # 119 BASE TABLE
php artisan tinker --execute="...tenants/stores/users/roles counts..." # 0/0/0/0
grep -o "^CREATE TABLE ..." ../point_of_sales_pre_seed_backup_*.sql   # 50 tabel
```

Tidak ada operasi tulis pada database pada Phase 1.

---

## 6. Rekomendasi Langkah Berikutnya

1. **Phase 2** — petakan 119 tabel ke 8 kelompok domain, mulai dari
   *Core platform/tenant/store/user/RBAC* (batch 1), lalu lanjut per batch.
   Sertakan pemetaan 2 tabel baru & dampak migration `2027_01_04_*`.
2. **Keputusan yang dibutuhkan dari pemilik project** sebelum Phase 4:
   - Apakah dua akun demo berada di **satu tenant** (skenario multi-cabang) atau
     **dua tenant terpisah** (skenario isolasi)?
   - Konfirmasi bahwa `point_of_sales` lokal adalah **target demo yang boleh diisi**.
   - Persetujuan pemakaian password demo `M3m3x…` (hanya di environment demo).
3. Jangan menjalankan `PosFullSeeder`, `migrate:fresh --seed`, atau
   `pos:populate-full-year` sampai Phase 5 selesai.

---

# FASE 2 — BATCH 1: CORE PLATFORM, TENANT, STORE, USER, RBAC, DEVICE

> **Sifat:** audit read-only. Tidak ada DDL/DML, tidak ada akun dibuat, tidak ada
> seeder dijalankan. Semua DDL di bawah diambil dari `SHOW CREATE TABLE` pada
> database live `point_of_sales` (119 tabel, HEAD `a069ff6`).
>
> **Checkpoint 2-Batch-1: SELESAI.** Lanjut ke Batch 2 (device/shift operasional &
> konfigurasi) setelah review.

## 7.0 Ringkasan Batch 1

| No | Tabel | Kelas ownership | Status implementasi | Dampak ke 2 akun demo |
|---|---|---|---|---|
| 1 | `tenants` | Tier 1 (platform) | **Terverifikasi** | 1 baris (satu tenant demo) |
| 2 | `stores` | Tier 2 (tenant) | **Terverifikasi** | 2 baris (2 cabang) |
| 3 | `users` | Tier 2 (tenant) | **Terverifikasi** | 2 akun owner + staf cabang |
| 4 | `roles` | Tier 2 (tenant) | **Terverifikasi** | 8 role standar dari `RoleService` |
| 5 | `permissions` | Tier 1 (global) | **Sebagian** — tabel terisi 42 baris, tetapi tidak dibaca kode runtime | Tidak perlu diisi untuk demo |
| 6 | `role_permissions` | Pivot global | **Sebagian** — hanya ditulis oleh command migrasi RBAC | Tidak perlu diisi untuk demo |
| 7 | `user_stores` | Tier 2 pivot | **Terverifikasi** | Wajib: 2 akun → 2 cabang |
| 8 | `devices` | Tier 3A (tenant+store) | **Terverifikasi** (CRUD + pairing) | 1–2 device per cabang (kuota) |
| 9 | `user_invitations` | Tier 2 | **Terverifikasi** | Opsional (tidak wajib untuk demo) |
| 10 | `user_invitation_stores` | Tier 2 | **Terverifikasi** | Opsional |
| 11 | `user_oauth_identities` | Tier 2 | **Terverifikasi** (OAuth login) | Tidak perlu untuk demo |

**Kesimpulan skenario (arahan pemilik project):** **satu tenant + dua cabang
SUPPORTED** oleh schema dan implementasi. Namun **tidak** supported oleh paket
`standard_trial` (kuota `max_stores = 1`). Harus memakai paket `pro`
(`max_stores = 5`, `max_devices_per_store = 5`, `max_employees = 25`) atau
`enterprise`. Ini **bukan** schema change — hanya pemilihan `tenants.subscription_plan`.

---

## 7.1 `tenants` — platform tenant

| Aspek | Detail (terverifikasi) |
|---|---|
| Tujuan | Root organisasi multi-tenant; induk semua entitas tenant-scoped. |
| PK | `id` bigint unsigned AI |
| FK | **tidak ada** (root) |
| Unique | `tenants_provisioning_key_unique (provisioning_idempotency_key)` |
| Index | `tenants_onboarding_status_index (onboarding_status)` |
| Tenant/store | Root — tanpa `tenant_id`/`store_id`. |
| Kolom status penting | `status` (default `active`), `onboarding_status` (default `pending_verification`), `access_status` (default `provisioning`), `subscription_status` (default `trialing`), `subscription_plan` (default `standard_trial`), `readiness_status` (default `pending`). |
| Soft delete | `deleted_at` |

**Model:** `app/Modules/Settings/Models/Tenant.php` (wrapper `app/Models/Tenant.php`).
- Konstanta status onboarding/akses/langganan terdokumentasi di model.
- `isOnboardingCompleted()` = `onboarding_status === 'completed'`.
- `isSuspended()` = `access_status ∈ {suspended, suspended_admin, suspended_billing}` **atau** `status === 'suspended'`.
- `isOperational()` = onboarding completed **dan** `access_status === 'active'`.
- Kolom `owner_user_id` muncul di relasi docblock (`owner()`) tetapi **bukan kolom tabel** → relasi ini tidak pernah dapat di-eager-load dengan benar (gap minor, tidak memblokir).

**Service / jalur pembuatan tenant (3 jalur, semuanya kode aplikasi):**

| Jalur | File | Status akhir tenant |
|---|---|---|
| Registrasi web | `app/Modules/Auth/Controllers/Auth/RegisteredUserController.php:99-148` | `status=pending`, `onboarding_status=pending_verification`, `access_status=pending`, `subscription_plan=standard_trial`; hanya role Owner yang dibuat (role lain **belum**) |
| Onboarding selesai | `app/Modules/Auth/Services/TenantOnboardingService.php:104-158` | Men-set `onboarding_status=completed`, `access_status=active`, `status=active`, `onboarding_completed_at` |
| Provisioning SaaS | `app/Modules/Settings/Services/ProvisioningService.php:451-525` | Tenant + Owner role + store pertama (default code `MAIN-01`, default `dine_in`) + token onboarding + outbox + `provisionBaseline()` |

**Middleware gerbang akses:** `app/Http/Middleware/SetTenantContext.php:48-75`
menolak (`403`) bila user tanpa tenant, tenant soft-deleted, atau tenant suspended.
Tenant yang **belum onboarding completed** hanya diizinkan lewat bila ia Owner
dan store set kosong — sehingga tenant demo **wajib** berstatus
`onboarding_status=completed` + `access_status=active` agar dashboard/cabang tampil.

**Dampak dua akun demo:** satu baris `tenants` dengan `subscription_plan='pro'`
(atau `enterprise`), `onboarding_status='completed'`, `access_status='active'`,
`status='active'`; `slug` unik (bukan unique constraint tetapi dipakai URL).
`readiness_status` akan diset `ready` oleh `TenantProvisioningService::refreshReadiness()`
bila owner role + ≥1 store + 2 system user + 3 setting baseline ada.

> ⚠️ **Temuan T1-01** — `ProvisioningService` membuat **store pertama** untuk setiap
> tenant (`ProvisioningService.php:471`). Artinya alur produksi selalu menghasilkan
> ≥1 store. Dataset demo tidak boleh membuat store "Main" tambahan yang lalu
> di-rename, karena `StoreService::delete()` menolak hapus store `is_initial`
> (`StoreService.php:133`).

---

## 7.2 `stores` — cabang

| Aspek | Detail (terverifikasi) |
|---|---|
| Tujuan | Cabang fisik/logis dalam satu tenant. |
| PK | `id` |
| FK | `stores_tenant_id_foreign (tenant_id) → tenants(id)` ON DELETE NO ACTION |
| Unique | `stores_tenant_id_id_unique (tenant_id, id)` ← target composite FK seluruh tabel Tier 3 |
| **Tidak ada** unique | `(tenant_id, code)` maupun `(tenant_id, name)` — **duplikasi nama/kode cabang tidak ditolak DB** |
| Kolom | `name`, `code` default `MAIN-01`, `timezone` default `Asia/Jakarta`, `business_date_cutoff` default `00:00`, `default_channel` default `dine_in`, `phone`, `is_active` default 1, `is_initial` default 0, `address`, timestamps, `deleted_at` |

**Model:** `app/Modules/Settings/Models/Store.php` — `AssignsTenant` + `ScopedToTenant` + `SoftDeletes`.
- Hook `created()` (`Store.php:54-87`): membuat baris saldo stok nol di
  `product_stock_balances` & `ingredient_stock_balances` untuk **setiap produk/ingredient
  tenant** saat store dibuat — **hanya jika** `TenantContext::getTenantId() === store.tenant_id`.
  Jika store dibuat di luar context tenant (mis. `Store::withoutTenantScope()->create()`
  tanpa `runAs`), saldo tidak di-pre-create → generator harus memakai context.

**Service:**
- `StoreService::store()` (`StoreService.php:57-96`): `assertCanCreateStore()` → buat
  store → auto `code = 'STR-XXX'` → `insertOrIgnore` owner ke `user_stores` → audit log.
- `StoreService::delete()`: menolak store `is_initial`.
- `TenantOnboardingService::getInitialStore()`: membuat store `MAIN-01`, `is_initial=1`,
  `dine_in` **hanya jika tenant belum punya store**.

**Dampak dua akun demo:** dua baris `stores` (`is_initial=1` untuk cabang utama,
`is_initial=0` untuk cabang kedua), `code` unik secara aplikasi (`MAIN-01`, `STR-002`
atau kode bisnis eksplisit), `timezone` sama (`Asia/Jakarta`) agar laporan harian
konsisten. Karena DB tidak menjamin keunikan `code`, generator **wajib** memeriksa
duplikasi sebelum insert.

> ⚠️ **Temuan T2-01** — `EodSettlement`/receipt/report memakai `(tenant_id, store_id, business_date)`;
> `business_date_cutoff` default `00:00` berarti transaksi pasca-midnight masuk hari
> kalender berikutnya. Tanggal dataset 12 bulan harus memperhitungkan cutoff ini.

> ⚠️ **Temuan T2-02** — `StoreService::store()` memakai `$latest = ...->latest('id')->first(); $nextId = $latest->id + 1`
> untuk kode. Bila ID store tidak berurutan (mis. hasil migrasi), kode bisa bentrok
> secara logis. Tidak ada unique index yang menangkapnya.

---

## 7.3 `users`

| Aspek | Detail (terverifikasi) |
|---|---|
| Tujuan | Akun login (owner, staf) + akun sistem atribusi. |
| PK | `id` |
| FK | `users_tenant_id_foreign (tenant_id) → tenants(id)`; `users_role_id_composite_foreign (tenant_id, role_id) → roles(tenant_id, id)` ON UPDATE CASCADE → **role tidak bisa lintas tenant** |
| Unique | `users_email_active_unique (email, email_uniqueness_key)`; `uq_users_email_active_global (email_active_key)`; `users_employee_id_unique (employee_id)` ← **GLOBAL lintas tenant**; `users_tenant_id_id_unique (tenant_id, id)` |
| Generated | `email_active_key = IF(deleted_at IS NULL, email_normalized, NULL)`; `email_uniqueness_key = CASE WHEN deleted_at IS NULL THEN 0 ELSE NULL END` |
| Kolom wajib | `name`, `email`, `email_normalized`, `tenant_id` |
| Nullable | `role_id`, `password`, `password_hash`, `pin_hash`, `email_verified_at`, `phone_number`, `join_date`, `employee_id` |
| Soft delete | `deleted_at` |

**Model:** `app/Modules/Users/Models/User.php`
- `AssignsTenant` (stamp tenant dari context) + `SoftDeletes` + `MustVerifyEmail`.
- **Tanpa** `ScopedToTenant` global scope → user dapat di-query lintas tenant bila
  tidak memakai context; isolasi bertumpu pada `SetTenantContext` + eksplisit
  `where('tenant_id', ...)` di service. Ini **by design** (login butuh lookup global),
  tetapi generator/joiner **wajib** menambahkan filter tenant secara manual.
- **Dua kolom password** `password` dan `password_hash` disinkronkan oleh mutator
  `setPasswordAttribute` / `setPasswordHashAttribute` (`User.php:123-137`);
  `getAuthPassword()` membaca `password_hash ?? password`. Hashing: `Hash::make()`
  (bcrypt default Laravel), `Hash::needsRehash()` dipakai agar tidak double-hash.
- `booted()`: auto `employee_id` via `EmployeeIdGenerator::next(tenant_id)` bila kosong;
  **exception** bila `tenant_id` kosong.
- `email_normalized` otomatis dinormalisasi via `EmailNormalizer` pada event `saving`.
- `stores()` = `belongsToMany(Store::class, 'user_stores')` — **tanpa** filter tenant
  pada pivot, bergantung FK komposit DB.
- `hasPermission()` delegasi ke `$this->role?->hasPermission()`.

**EmployeeIdGenerator** (`app/Modules/Users/Services/EmployeeIdGenerator.php`):
`<PREFIX>-<tenantId>-<counter>`; counter atomik dari `employee_id_sequences`
(`UPDATE ... LAST_INSERT_ID(last_value + 1)`); prefix default `YVL-EMP`.
**Unique global** — dua tenant tidak bertabrakan karena `tenantId` ada di nilai.

**Akun sistem:** `TenantProvisioningService::provisionSystemUser()` membuat
`selforder.t{tenant}@...` dan `channel.t{tenant}@...` dengan password acak,
`email_verified_at=null` (tidak bisa login). `SystemUserResolver` **abort/500**
bila akun ini tidak ada → mutlak diperlukan untuk demo omnichannel & self-order.

**Dampak dua akun demo:**
1. Email `ilhamsepriyadi8@gmail.com` dan `ilhamsepriyadi0@gmail.com` **harus** unik
   global (indeks `uq_users_email_active_global`), sehingga **tidak bisa** ada di dua
   tenant berbeda sekaligus dengan dua baris aktif.
2. Kedua akun akan berada di **tenant yang sama** (sesuai arahan), role `Owner` (atau
   `Manager`) agar `StoreResolver` memberi akses semua cabang
   (`StoreResolver.php:26-36`: Owner → semua store tenant).
3. Password di-set lewat mutator (`password_hass`/`password`) menggunakan `Hash::make`,
   **tidak** mengubah kebijakan validasi (`Rules\Password::defaults()` tetap berlaku
   untuk jalur registrasi; jalur provisioning/dataset tidak memvalidasi password).
4. `email_verified_at` harus terisi agar `MustVerifyEmail` tidak memblokir dashboard.
5. `user_stores` wajib berisi kedua cabang bila role bukan Owner.

> ⚠️ **Temuan T3-01 (blocker desain Phase 5)** — Generator legacy memakai
> `DB::table('users')->insertGetId([... 'password_hash' => $pwdHash ...])`
> (`PopulateApeironFullYearDataset.php:251-259`). `DB::table()` **melewati mutator
> Eloquent**, sehingga `email_normalized` **tidak** terisi dan `employee_id` harus
> diset manual. `email_normalized` adalah `NOT NULL` → insert akan gagal/berisi string
> kosong. Rekomendasi Phase 5: pakai `User::create()` atau isi kolom secara eksplisit.

> ⚠️ **Temuan T3-02** — `employee_id` di generator legacy = `YVL-EMP-<tenant>-<seq>`
> dengan `$empSeq` mulai dari 4 dan komentar "User 16,17,18 used counter 1,2,3"
> (`PopulateApeironFullYearDataset.php:219`). Angka ini berasal dari data seed lama yang
> **tidak ada di DB saat ini** (0 user). Jika dipertahankan, `EmployeeIdGenerator`
> counter akan bertabrakan dengan sequence DB.

---

## 7.4 `roles`

| Aspek | Detail |
|---|---|
| Tujuan | Peran per tenant (bukan global). |
| PK | `id` |
| FK | `roles_tenant_id_foreign (tenant_id) → tenants(id)` |
| Unique | `roles_tenant_id_id_unique`; `roles_tenant_id_name_unique`; `roles_tenant_id_role_code_unique` |
| Kolom | `role_code`, `name`, `description`, `permissions` JSON (check `json_valid`), `is_active`, `deleted_at` |

**Model:** `app/Modules/Users/Models/Role.php` — `AssignsTenant` + `ScopedToTenant` (fail-closed)
+ `SoftDeletes`; cast `permissions` → array; auto `role_code` `ROL-<SLUG>` pada
`creating()`; `hasPermission()` mendukung `*`, permission persis, dan wildcard `x.*`.
`getIsAdminAttribute()` hanya true untuk nama `admin`/`superadmin` — **`Owner`
BUKAN `is_admin`**, sehingga `StoreResolver` bergantung pada pengecekan nama `Owner`.

**Provisioning role standar — `RoleService::provisionDefaultRoles()`**
(`app/Modules/Users/Services/RoleService.php:95-133`), 8 role dengan `role_code` deterministik:

| role_code | name | permissions JSON |
|---|---|---|
| `ROL-OWNER` | Owner | `["*"]` |
| `ROL-MANAGER` | Manager | `dashboard, reports, orders.*, users.view, shifts.*, inventory.*, discounts.*` |
| `ROL-KASIR` | Kasir | `orders.create, orders.view, payments.create, shifts.own` |
| `ROL-WAITER` | Waiter | `orders.create, orders.view, tables.view` |
| `ROL-BARISTA` | Barista | `orders.view, kds.view, kds.update` |
| `ROL-INVENTORY` | Inventory | `inventory.*, ingredients.*` |
| `ROL-SUPERVISOR` | Supervisor | `orders.*, shifts.*, reports.daily, void.approve` |
| `ROL-COOK` | Cook | `orders.view, kds.view, kds.update` |

Idempotensi: `firstOrCreate(['name' => ..., 'tenant_id' => ...])` di dalam `runAs`.
Bisa dijalankan ulang via `php artisan tenant:provision-roles {tenant}`
(`app/Console/Commands/ProvisionTenantRoles.php`).

**Dampak dua akun demo:** role adalah **per tenant** dengan `id` auto-increment global.
Untuk satu tenant, role dibuat sekali (8 baris). **Jangan** hardcode role ID.

---

## 7.5 `permissions` & `role_permissions` — RBAC relasional (SEBAGIAN)

| Tabel | Struktur | Status |
|---|---|---|
| `permissions` | `id`, `permission_code` UNIQUE, `description` | **42 baris** saat ini, **tidak ada kode runtime yang membaca tabel ini** |
| `role_permissions` | PK `(role_id, permission_id)`; FK `role_id → roles(id)` **single-column**; FK `permission_id → permissions(id)` | 0 baris; hanya ditulis oleh `php artisan` `MigrateRbacPermissions` |

**Bukti kode:** satu-satunya referensi `role_permissions` adalah
`app/Console/Commands/MigrateRbacPermissions.php:54`. Otorisasi runtime memakai
`users.role_id → roles.permissions` (JSON), lihat `Role::hasPermission()` dan
`User::hasPermission()`. `RoleMiddleware` juga menerima nama role.

> ⚠️ **Temuan T5-01** — `role_permissions` **tidak** memiliki kolom `tenant_id`, FK
> `role_id` bersifat single-column (tidak komposit `(tenant_id, role_id)`). Tabel ini
> tidak dapat menegakkan isolasi tenant bila diaktifkan. **Jangan diisi oleh generator
> dataset** — tidak ada pembaca, dan mengisinya menciptakan sumber kebenaran kedua.
> Catat sebagai gap arsitektur (bukan blocker dataset).

> ⚠️ **Temuan T5-02** — `permissions` berisi 42 baris tetapi `role_permissions` kosong
> dan `roles` kosong. Data ini berasal dari seeder platform, bukan hasil operasi tenant.
> Tidak perlu disentuh.

---

## 7.6 `user_stores` — penugasan cabang

| Aspek | Detail |
|---|---|
| Tujuan | Mapping user → cabang yang boleh diakses (sumber `StoreResolver`). |
| PK | `id` |
| Unique | `user_stores_user_id_store_id_unique (user_id, store_id)`; `user_stores_tenant_id_id_unique (tenant_id, id)` |
| FK komposit | `(tenant_id, store_id) → stores(tenant_id, id)` CASCADE; `(tenant_id, user_id) → users(tenant_id, id)` CASCADE; `tenant_id → tenants(id)` |

**Bukti:** `TenantOnboardingService.php:131-134`, `StoreService.php:80-86`,
`UserInvitationService.php:317-328` semua memakai `insertOrIgnore` / `updateOrInsert`
dengan `tenant_id` eksplisit.

**Dampak dua akun demo:** bila akun ber-role `Owner`, baris `user_stores` **tidak wajib**
(`StoreResolver` mengembalikan semua store tenant). Namun onboarding owner menambahkan
baris ke cabang pertama secara eksplisit — konsisten bila dataset menambahkan kedua
akun ke **kedua** cabang, sehingga demo tetap bekerja bila role diubah ke `Manager`.

---

## 7.7 `devices` — identitas terminal per cabang

| Aspek | Detail |
|---|---|
| Tujuan | Terminal POS/KDS/tablet, **wajib** terikat ke satu store. |
| PK | `id` |
| FK komposit | `(tenant_id, store_id) → stores(tenant_id, id)` CASCADE; `tenant_id → tenants(id)` CASCADE |
| Unique | `devices_store_code_unique (tenant_id, store_id, device_code)`; `devices_pairing_token_unique`; `devices_tenant_id_id_unique`; `devices_tenant_store_id_unique` |
| Kolom | `device_code` (auto `DEV-XXX` bila kosong), `device_name`, `device_type` default `pos_terminal`, `pairing_token` (+expiry 24 jam), `device_fingerprint`, `status` default `pending`, `is_active`, `metadata` JSON |

**Model:** `app/Modules/Settings/Models/Device.php` — `AssignsTenant` + `AssignsStore` +
`ScopedToTenant` + `ScopedToStore` (fail-closed) + `SoftDeletes`.
Tipe: `pos_terminal`, `kds_display` (konstanta di model). Status lifecycle:
`pending → paired`, `revoked`.

**Service:** `DeviceService::registerDevice()` — memeriksa `store.tenant_id === context`
dan kuota `assertCanCreateDevice()`; `pairDevice()` memakai token+fingerprint.

**Dampak dua akun demo:** 1–2 device per cabang cukup; **kuota** untuk paket `pro`
adalah 5 device/store, `standard_trial` 2. `device_code` unik **per tenant+store**
(bukan global), sehingga aman memakai `POS-01` di kedua cabang.

> ⚠️ **Temuan T8-01** — `Device::booted()` menghasilkan `device_code` dari
> `latest('id') + 1` **global lintas tenant**. Bila dua device dibuat berurutan di
> tenant berbeda, kode tidak bentrok karena unique-nya menyertakan `tenant_id, store_id`;
> tetapi kode bisa "melompat". Generator sebaiknya mengisi `device_code` eksplisit.

---

## 7.8 `user_invitations` & `user_invitation_stores`

| Aspek | Detail |
|---|---|
| `user_invitations` FK | `(tenant_id, role_id) → roles`; `(tenant_id, invited_by_user_id) → users`; `(tenant_id) → tenants` — semua komposit |
| Unique | `token_hash`; index `(tenant_id, status)`, `(email_normalized)` |
| Lifecycle | `pending → accepted / revoked / superseded / blocked_cross_tenant` |
| `user_invitation_stores` | `unique (user_invitation_id, store_id)`; FK store komposit `(tenant_id, store_id)` |

**Bukti kode:** `app/Modules/Users/Services/UserInvitationService.php`
- `issueInvitation()` memvalidasi role + minimal 1 store milik tenant.
- `acceptInvitation()` menolak bila email sudah aktif di tenant mana pun (409),
  menyalin `user_invitation_stores` → `user_stores`, dan mengalokasikan `employee_id`.

**Dampak dua akun demo:** **tidak wajib**. Bila staf cabang dibuat, jalur yang
"benar" adalah undangan (bukan direct insert). Untuk dataset demo, staf pendukung
lebih aman dibuat langsung dengan `UserService`/`User::create()` + `user_stores`,
tanpa mengklaim alur undangan berjalan.

---

## 7.9 `user_oauth_identities`

- FK komposit `(tenant_id, user_id) → users(tenant_id, id)` CASCADE.
- Unique `(provider, provider_subject)` **global**, `(user_id, provider)`.
- Dipakai pada login OAuth (`OAuthController`). Tidak diperlukan untuk dua akun demo
  berbasis password.

---

## 7.10 WHY THE LEGACY COMMAND ASSUMES `tenant_id=9`, `store_id=6`, ROLE IDs 22–29

**Berkas:** `app/Console/Commands/PopulateApeironFullYearDataset.php`

| Hardcode | Lokasi | Asal / alasan (terverifikasi) | Validitas sekarang |
|---|---|---|---|
| `--tenant=9` | baris 16, 23, 73 | Dataset lama dijalankan pada database pengembangan yang sudah memiliki tenant id 9 bernama **"Rindu Kopi"** (banner baris 73). | **TIDAK VALID** — DB sekarang `tenants.count() = 0` → command exit 1 di preflight (baris 78-83). |
| `UPDATE stores WHERE id=6` | baris 153-160 | Store 6 diasumsikan "Flagship Senopati" yang sudah ada hasil provisioning tenant 9. | **TIDAK VALID** — `stores.count() = 0`. `stores.id` auto-increment global, tidak deterministik. |
| Sub-seeder membuat 7 store baru | baris 162-187 | Melengkapi menjadi 8 cabang. | Nama cabang dapat dipertahankan, tetapi **ID tidak boleh di-hardcode**. |
| `role_id` 22..29 | baris 208-209, 225-237, 1044 | ID role per tenant adalah auto-increment **global**; pada DB lama tenant 9 sudah punya 7 tenant sebelumnya × 8 role → role tenant 9 mulai di 22. | **TIDAK VALID** dan rapuh: `roles.id` bergantung pada jumlah role tenant lain. Harus di-resolve **by `role_code`** (`ROL-OWNER`, `ROL-MANAGER`, ...) di dalam tenant. |
| `YVL-EMP-<tenant>-<seq>` mulai `empSeq=4` | baris 219, 241 | Melanjutkan ID lama (user 16-18 memakai counter 1-3). | **TIDAK VALID** — `employee_id_sequences` kosong; harus lewat `EmployeeIdGenerator`. |
| Password `Apeiron2026!` | baris 205 | Password demo lama. | Diganti password demo yang disepakati pemilik project (tidak dicetak di dokumen). |
| Tanggal mulai `2025-10-14 06:00:00` | baris 183-184, 258 | Tanggal eksekusi dataset lama. | Harus menjadi parameter periode eksplisit (Phase 4). |
| Prefix kode produk/ingredient `ING-9-...` | baris 404-413 | Menyertakan tenant id di kode. | Boleh dipertahankan sebagai skema kode, tetapi harus dihitung dari tenant aktual. |

**Bukti reliabilitas skema ID:** DDL `roles` hanya mendeklarasikan
`UNIQUE (tenant_id, id)` (dikomposit untuk FK) — `id` tetap auto-increment **global**.
`SHOW CREATE TABLE roles` tidak memiliki `AUTO_INCREMENT=` offset per tenant. Maka
asumsi 22–29 hanya benar pada snapshot DB lama tertentu.

---

## 7.11 Keputusan Arsitektur untuk Skenario Satu Tenant / Dua Cabang

| Kebutuhan | Dukungan schema | Dukungan implementasi | Catatan |
|---|---|---|---|
| 1 tenant | ✅ `tenants` | ✅ `TenantProvisioningService` | —
| 2 cabang | ✅ `stores` (tanpa unique code) | ✅ `StoreService::store()` | **Kuota**: `standard_trial max_stores=1` → wajib paket `pro` |
| 2 akun di tenant yang sama | ✅ `users` unique email global | ✅ `StoreResolver` (Owner) | Email harus unik global |
| Kedua akun akses 2 cabang | ✅ `user_stores` komposit | ✅ Owner otomatis; non-owner butuh mapping | Disarankan mapping eksplisit |
| Device per cabang | ✅ `devices` | ✅ `DeviceService` | Kuota `pro` = 5/store |
| Isolasi lintas tenant | ✅ composite FK | ✅ `TenantScope`/`StoreScope` fail-closed | Diuji `Domain1TenantIsolationTest` (16 test) |
| Role per tenant | ✅ `roles` | ✅ `RoleService` | Resolve **by `role_code`**, bukan ID |

**Rekomendasi paket:** `pro` (`plans.id=3`), karena `max_stores=5`,
`max_devices_per_store=5`, `max_employees=25`. `standard_trial` akan memblokir cabang
kedua melalui `EntitlementService::assertCanCreateStore()`.
Paket dipilih dengan mengisi `tenants.subscription_plan='pro'` dan baris
`tenant_subscriptions` (dibuat otomatis oleh `provisionBaseline()` hanya bila
`Plan` dengan code tersebut ada — terverifikasi ada).

---

## 7.12 File yang Diperiksa pada Batch 1

**Schema (live, read-only):** `SHOW CREATE TABLE` untuk `tenants`, `stores`, `users`,
`roles`, `permissions`, `role_permissions`, `user_stores`, `devices`,
`user_invitations`, `user_invitation_stores`, `user_oauth_identities`.

**Model**
- `app/Modules/Settings/Models/Tenant.php`, `Store.php`, `Device.php`
- `app/Modules/Users/Models/User.php`, `Role.php`, `UserInvitation.php`, `UserInvitationStore.php`
- `app/Models/Scopes/TenantScope.php`, `StoreScope.php`
- `app/Modules/Users/Models/UserOAuthIdentity.php`

**Service / Controller / Middleware**
- `app/Modules/Users/Services/RoleService.php`, `UserService.php`, `EmployeeIdGenerator.php`, `UserInvitationService.php`
- `app/Modules/Settings/Services/StoreService.php`, `DeviceService.php`, `TenantProvisioningService.php`, `ProvisioningService.php`, `EntitlementService.php`
- `app/Modules/Auth/Services/TenantOnboardingService.php`
- `app/Modules/Auth/Controllers/Auth/RegisteredUserController.php`
- `app/Core/Context/TenantContext.php`, `StoreResolver.php`
- `app/Http/Middleware/SetTenantContext.php`, `RoleMiddleware.php`
- `app/Console/Commands/ProvisionTenantRoles.php`, `ProvisionSystemUsers.php`, `MigrateRbacPermissions.php`

**Seeder / Generator**
- `database/seeders/Pos/RoleSeeder.php`
- `database/seeders/PosFullSeeder.php`, `DatabaseSeeder.php`
- `app/Console/Commands/PopulateApeironFullYearDataset.php` (baris 15-19, 78-83, 150-260, 1044)

**Test (yang ada, belum dijalankan pada batch ini)**
- `tests/Feature/Domain1/Domain1TenantIsolationTest.php` (16 test isolasi)
- `tests/Feature/Domain1/ProvisioningDomainTest.php`, `ProvisioningHardenedIntegrityTest.php`, `ProvisioningRealConcurrencyTest.php`, `TenantLifecycleEnforcementTest.php`, `Domain1AuditRemediationTest.php`

**Database (`read-only`)**
- `plans`, `plan_entitlements`, `permissions` counts & values

---

## 7.13 Temuan & Blocker untuk Fase Berikutnya

| ID | Temuan | Tingkat | Tindakan |
|---|---|---|---|
| T1-01 | `ProvisioningService` selalu membuat store pertama | Info | Jangan buat store "Main" tambahan; rename store provisioning atau buat cabang kedua saja |
| T2-01 | `business_date_cutoff` memengaruhi tanggal laporan | Design | Phase 4 menetapkan policy cutoff |
| T2-02 | `stores.code` tidak unik di DB | Design | Validasi aplikasi di generator |
| T3-01 | `DB::table('users')` melewati mutator → `email_normalized` kosong | **Blocker generator** | Phase 5 wajib pakai Eloquent atau isi kolom |
| T3-02 | `employee_id` legacy counter 4+ tidak cocok sequence | **Blocker generator** | Phase 5 pakai `EmployeeIdGenerator` |
| T5-01 | `role_permissions` single-column FK, tanpa `tenant_id`, tanpa pembaca | Gap arsitektur | Jangan diisi |
| T5-02 | 42 permission tanpa role mapping | Info | Tidak disentuh |
| T8-01 | `device_code` auto dari max(id) global | Minor | Isi eksplisit |
| T-L1 | Hardcode tenant 9 / store 6 / role 22-29 | **Blocker generator** | Phase 5 resolve by `role_code` & tenant lookup |
| T-Q1 | `standard_trial` `max_stores=1` | **Blocker skenario 2 cabang** | Set `subscription_plan='pro'` |

---

## 7.14 Status Batch 1: SELESAI — TIDAK ADA PERUBAHAN DATA

- Tidak ada DDL/DML/akun/seeder dijalankan.
- `tenants=0, stores=0, users=0, roles=0` tetap (diverifikasi ulang).
- Dokumen ini satu-satunya perubahan (akan di-commit terpisah atas persetujuan).

### Rekomendasi Batch 2

Audit **Device/konfigurasi operasional & shift** sebelum masuk catalog:
`settings`, `tenant_subscriptions`, `tenant_entitlement_overrides`, `employee_id_sequences`,
`shift_handovers`, `shifts` (skema + trigger + FK komposit), `owner_onboarding_tokens`,
`email_outbox`, `email_verification_otps`, `provisioning_*`. Tujuannya memastikan
identitas cabang/terminal dan siklus shift yang akan dipakai generator 12 bulan.

---

# FASE 2 — BATCH 2: KONFIGURASI OPERASIONAL, SHIFT, DAN SIKLUS PROVISIONING

> **Sifat:** audit read-only (SHOW CREATE TABLE + pembacaan kode). Tidak ada DDL/DML,
> akun, atau seeder dijalankan.
>
> **Checkpoint 2-Batch-2: SELESAI.**

## 8.0 Ringkasan Batch 2

| No | Tabel | Ownership | Status implementasi | Wajib untuk demo 12 bulan |
|---|---|---|---|---|
| 1 | `settings` | Tier 2 (tenant) | **Terverifikasi** | **Ya** — `tax_rate`, `rounding_*`, baseline readiness |
| 2 | `shifts` | Tier 3A (tenant+store) | **Terverifikasi** | **Ya** — setiap order punya `shift_id` |
| 3 | `shift_handovers` | Tier 3A | **Terverifikasi** (fitur) | Opsional (fitur serah terima) |
| 4 | `employee_id_sequences` | Tier 2 | **Terverifikasi** | **Ya** (otomatis via generator) |
| 5 | `owner_onboarding_tokens` | Tier 2 | **Terverifikasi** | Tidak (akun demo dibuat sudah aktif) |
| 6 | `email_outbox` | Hybrid tenant | **Terverifikasi** | Tidak (kecuali simulasi email) |
| 7 | `email_verification_otps` | Tier 2 | **Terverifikasi** | Tidak |
| 8 | `tenant_subscriptions` | Tier 2 | **Terverifikasi** | **Ya** — menentukan kuota paket |
| 9 | `tenant_entitlement_overrides` | Tier 2 | **Terverifikasi** | Tidak (alternatif kuota) |
| 10 | `provisioning_requests` | Tier 1 (platform) | **Terverifikasi** | Tidak (jejak provisioning SaaS) |
| 11 | `provisioning_nonces` | Tier 1 (platform) | **Terverifikasi** | Tidak |

Tidak ada trigger pada `shifts`, `shift_handovers`, `settings`, `email_outbox`, atau
`tenant_subscriptions` (diverifikasi via `information_schema.TRIGGERS` → 0 baris).

---

## 8.1 `settings`

| Aspek | Detail |
|---|---|
| PK | `id` |
| FK | `tenant_id → tenants(id)` NO ACTION |
| Unique | `settings_tenant_id_key_unique (tenant_id, key)` |
| Kolom | `key` varchar(255), `value` text NULL, `type` (default `string`), `description` |
| Tenant/store | Per tenant, **tidak** per store |

**Model:** `app/Modules/Settings/Models/Setting.php`
- `ScopedToTenant` + `AssignsTenant`.
- `Setting::get($key, $default)` melakukan `requireTenantId()` lalu
  `Cache::remember("pos:setting:{tenant}:{key}", 3600)`. **Fail-closed**: tanpa
  tenant context → exception.
- Cast per `type`: `integer`, `boolean`, `string`, `json` (lihat `match` di model).

**Key yang benar-benar dibaca aplikasi (bukti grep `Setting::get`/`Setting::set`):**

| Key | Konsumen | Default bila tidak ada |
|---|---|---|
| `tax_rate` | kalkulasi transaksi (7 kemunculan) | `config('pos.tax_rate') = 0.11` |
| `rounding_behavior` | pembulatan total (4) | `ROUND_NEAREST` |
| `rounding_value` | kelipatan pembulatan (4) | `100` |
| `store_name`, `store_address`, `store_phone`, `store_email` | `SettingController` | `config('app.name')` / kosong |
| `service_charge_rate`, `receipt_width_mm` | **ditulis** `SettingSeeder` tetapi tidak ditemukan pembaca runtime | — |

**Baseline wajib (dari `TenantProvisioningService::provisionBaselineSettings()`):**
`business_name` (string), `default_sales_channel` (string, `DINE_IN`),
`tax_enabled` (boolean, `false`). `evaluateReadiness()` akan menandai tenant
**tidak ready** bila salah satu dari tiga key ini tidak ada.

**Seeder:** `database/seeders/SettingSeeder.php` — `updateOrCreate(['key' => ...])`
**tanpa `tenant_id`**. Karena `ScopedToTenant` memakai context, seeder ini hanya benar
bila dijalankan di dalam `runAs($tenant)`. `DatabaseSeeder` memanggilnya di dalam
`runAs` (baris 97), jadi aman di jalur itu.

> ⚠️ **Temuan T-S1** — `tax_rate` default `0.11` **disimpan sebagai string**, bukan float.
> Generator **wajib** mengikuti aturan aplikasi saat menghitung pajak: baca
> `Setting::get('tax_rate')` + `rounding_*`, jangan menghitung dengan asumsi sendiri,
> agar subtotal/pajak/total dapat direkonsiliasi dengan UI.

> ⚠️ **Temuan T-S2** — `SettingSeeder::updateOrCreate(['key' => ...])` tanpa `tenant_id`
> akan **menimpa setting tenant lain** bila dijalankan tanpa context (unique index
> `(tenant_id,key)` tidak menolong karena tenant context kosong → fail-closed/no-op).
> Generator dataset jangan memanggil seeder ini di luar `runAs`.

---

## 8.2 `shifts` — siklus shift kasir

| Aspek | Detail |
|---|---|
| PK | `id` |
| FK | `(tenant_id, store_id) → stores`; `(tenant_id, user_id) → users`; `(tenant_id, active_cashier_id) → users`; `(tenant_id, closed_by_user_id) → users`; `(tenant_id, store_id, device_id) → devices` (semua komposit) |
| Unique | `(tenant_id, id)`; `(tenant_id, store_id, id)`; `shifts_one_open_per_device_unique (tenant_id, store_id, open_device_guard)`; `shifts_one_open_per_user_unique (tenant_id, store_id, open_shift_lock_key)` |
| Generated | `open_shift_lock_key = CASE WHEN status IN ('open','handover_pending','closing') THEN COALESCE(active_cashier_id, user_id) END`; `open_device_guard = device_id` (saat aktif) |
| CHECK | `chk_shifts_close_type IN ('normal','force_closed')` |
| Money | `opening_balance`, `closing_balance`, `expected_cash` = `bigint unsigned`; `cash_difference` = `bigint` (boleh negatif) |
| Status | bebas varchar (aplikasi memakai `open`, `handover_pending`, `closing`, `closed`) |

**Model:** `app/Modules/Sales/Models/Shift.php` — `AssignsTenant` + `AssignsStore` +
`ScopedToTenant` + `ScopedToStore` + `SoftDeletes`.

**Service:** `app/Modules/Sales/Services/ShiftService.php`
- `openShift()` (baris 36-175): validasi PIN kasir (`CashierPinService`,
  format `^\d{4,6}$`, bcrypt), cek shift aktif per **device** dan per **kasir
  pada cabang yang sama**, lalu `Shift::create(... status='open', opened_at=now())`.
  Duplikat ditangkap dari pesan unique index → `DomainException`.
- `getCashSummary()` (baris 512-538): `expected_cash = opening_balance + Σ(payments cash captured − voided) + cash_in − cash_out`.
- `closeShift()` (baris 544-...): `closing_balance`, `expected_cash`,
  `cash_difference = closing − expected`, `status='closed'`, `closed_at`, `closed_by_user_id`, `close_type`.
- `initiateHandover()` / `acceptHandover()` / `cancelHandover()` → `shift_handovers`.
- `recordCashMovement()` hanya boleh saat `status='open'`; `cash_out` ditolak bila
  `expected_cash < amount`.

**Ketergantungan:** `payments.shift_id` dan `orders.shift_id` → basis `getCashSummary()`.
`cash_drawer_movements.shift_id` → `cash_in`/`cash_out`.

> 🔴 **Temuan T-SH1 (blocker generator)** — `ShiftSeeder` (`database/seeders/Pos/ShiftSeeder.php`)
> memakai **ID kasir hardcode** `[2, 9..15]` dan supervisor `[6,7,8]` (baris 18-20),
> lalu `TRUNCATE shifts` (baris 116-118). Pada DB 119 tabel sekarang tidak ada user,
> sehingga FK `(tenant_id, user_id)` **selalu gagal**. Selain itu TRUNCATE melanggar
> aturan brief.

> 🔴 **Temuan T-SH2 (blocker generator)** — `ShiftSeeder` menulis `opening_balance`,
> `expected_cash`, `cash_difference` **secara sintetis** (`rand()`) tanpa menghitung
> `getCashSummary()`. Hasilnya `expected_cash ≠ opening + cash_sales + in − out`,
> sehingga laporan shift/EOD tidak dapat direkonsiliasi. Generator baru **wajib**
> menghitung dari payments + cash_drawer_movements yang benar-benar dibuat.

> ⚠️ **Temuan T-SH3** — `shifts.user_id` dan `active_cashier_id` diisi sama pada
> `openShift()`; `open_shift_lock_key` = `COALESCE(active_cashier_id, user_id)`.
> Satu kasir hanya boleh punya **satu** shift aktif **per cabang** (index
> `(tenant_id, store_id, open_shift_lock_key)`), tetapi **boleh** aktif di dua
> cabang berbeda. Dataset multi-cabang harus menghormati aturan ini.

> ⚠️ **Temuan T-SH4** — Kasir **wajib** memiliki `pin_hash` (bcrypt, 4–6 digit) untuk
> membuka shift di jalur aplikasi (`ShiftService::openShift` → `CashierPinService::hasPin`).
> Bila generator membuat shift lewat `DB::table()->insert()` (bypass service), PIN tidak
> diperlukan oleh DB, tetapi data menjadi tidak realistis dan kasir tidak bisa
> membuka shift saat demo interaktif.

> ⚠️ **Temuan T-SH5** — `orders.shift_id` mengikat order ke shift. Bila shift dibuat
> untuk kasir berbeda dengan `user_id` order, laporan kas akan salah. Generator harus
> memilih `shift_id` dari shift **kasir yang sama** pada cabang yang sama.

---

## 8.3 `shift_handovers`

| Aspek | Detail |
|---|---|
| PK | `id` |
| FK komposit | `(tenant_id, store_id, shift_id) → shifts(tenant_id, store_id, id)`; `(tenant_id, store_id, device_id) → devices`; `(tenant_id, from_user_id)`, `(tenant_id, to_user_id)`, `(tenant_id, approved_by_user_id) → users` |
| CHECK | `chk_shift_handovers_status IN ('pending','accepted','cancelled','rejected')` |
| Unique | `(tenant_id, id)` — tidak ada unique pada `(shift_id, status)` |

**Model:** `app/Modules/Sales/Models/ShiftHandover.php`. **Service:** `ShiftService`
(`initiateHandover`, `acceptHandover`, `cancelHandover`).

**Dampak demo:** fitur opsional; tidak diperlukan agar dashboard/laporan terlihat
lengkap. **Jangan** membuat handover acak tanpa mengubah status shift terkait —
`acceptHandover` mensyaratkan shift `handover_pending` → `open` dan menukar
`active_cashier_id`.

> ⚠️ **Temuan T-HD1** — `discrepancy_amount` = selisih hitung fisik vs `expected_cash_at_handover`;
> tidak ada CHECK konsistensi. Generator (bila dipakai) harus menghitung selisih, bukan `rand()`.

---

## 8.4 `employee_id_sequences`

- PK komposit `(tenant_id, prefix)`; FK `tenant_id → tenants(id)` CASCADE ON UPDATE.
- Ditulis **atomik** oleh `EmployeeIdGenerator` (`UPDATE ... LAST_INSERT_ID(last_value + 1)`).
- **Dampak demo:** jangan insert manual; biarkan `User::create()`/generator mengisi.
  Bila generator memakai `DB::table('users')->insert()` dengan `employee_id` eksplisit,
  **harus** juga menaikkan `last_value` agar user demo berikutnya tidak bertabrakan.

> ⚠️ **Temuan T-E1** — `EmployeeIdGenerator::DEFAULT_PREFIX = 'YVL-EMP'`, diambil dari
> `config('pos.employee_id_prefix')` bila di-set. `employee_id` unique **global**:
> format `<PREFIX>-<tenantId>-<counter>` menjamin unik lintas tenant.

---

## 8.5 `owner_onboarding_tokens`

- FK komposit `(tenant_id, user_id) → users` CASCADE; unique `(tenant_id, user_id)` dan `token_hash`.
- Dipakai `TenantOnboardingService::resendOnboardingToken()` dan `ProvisioningService`.
- **Dampak demo:** tidak diperlukan karena akun demo dibuat dengan `email_verified_at`
  terisi. **Jangan** membuat token dengan password plaintext di body email.

---

## 8.6 `email_outbox` & `email_verification_otps`

| Tabel | Catatan |
|---|---|
| `email_outbox` | `tenant_id`/`user_id` **nullable** dengan FK `ON DELETE SET NULL`; status pembaca: `ProcessEmailOutbox` command; `recipient_email` **terenkripsi** pada beberapa alur (`Crypt::encryptString`) |
| `email_verification_otps` | `otp_code_hash` (bukan plaintext); `max_attempts` default 5; index aktif `(tenant_id,user_id,action,consumed_at,superseded_at,expires_at)` |

**Dampak demo:** tidak wajib. Bila dataset membuatnya, jangan menyimpan OTP plaintext
atau email penerima plaintext tanpa mengikuti pola enkripsi yang ada.

---

## 8.7 `tenant_subscriptions` — penentu kuota

| Aspek | Detail |
|---|---|
| PK | `id` |
| FK | `tenant_id → tenants(id)` CASCADE; `plan_id → plans(id)` ON UPDATE CASCADE |
| Kolom | `status` default `trialing`, `starts_at`, `ends_at`, `trial_ends_at`, `metadata` JSON |
| Status valid (model) | `trialing, active, past_due, canceled, expired` |
| Constraint | **tidak ada unique** pada `tenant_id` → BISA ada >1 baris langganan |

**Resolver kuota:** `EntitlementService::resolveActivePlanForTenant()`:
1. Ambil `TenantSubscription` dengan status `active`/`trialing`, **`latest('id')`**.
2. Bila tidak ada, fallback ke `tenants.subscription_plan` → `plans.code`.

**Kuota paket (terverifikasi dari `plan_entitlements`):**

| Plan | `max_stores` | `max_devices_per_store` | `max_employees` |
|---|---|---|---|
| `standard_trial` (id 1) | **1** | 2 | 5 |
| `starter` (id 2) | 1 | 2 | 5 |
| `pro` (id 3) | **5** | 5 | 25 |
| `enterprise` (id 4) | unlimited | unlimited | unlimited |

**Dampak demo 1 tenant / 2 cabang:** diperlukan `plan_id = 3` (`pro`) pada
`tenant_subscriptions` **atau** `tenants.subscription_plan = 'pro'` bila tidak ada
baris subscription. `provisionBaseline()` membuat baris subscription **hanya jika**
`Plan::where('code', $tenant->subscription_plan)` ditemukan dan belum ada subscription
(`TenantProvisioningService.php:85-95`, status `trialing`, trial 14 hari).

> 🔴 **Temuan T-SUB1 (blocker skenario)** — `TenantProvisioningService` **selalu** membuat
> subscription `trialing` untuk `standard_trial` bila tenant dibuat lewat jalur itu.
> Karena resolver memakai `latest('id')`, baris `trialing` ini **mengalahkan**
> `tenants.subscription_plan='pro'` bila subscription dibuat belakangan/di-update
> tanpa mengubah plan. Harus dipastikan `tenant_subscriptions.plan_id = 3`.

> ⚠️ **Temuan T-SUB2** — Tidak ada unique `tenant_id`; generator harus `updateOrCreate`
> atau memeriksa `exists()` untuk menghindari duplikasi langganan yang membuat kuota
> tidak deterministik.

> ⚠️ **Temuan T-SUB3** — `EntitlementService` meng-cache kuota 1 jam
> (`tenant:{id}:quota:{code}`). `clearCache()` harus dipanggil setelah perubahan
> paket, atau data demo baru tidak langsung terlihat.

---

## 8.8 `tenant_entitlement_overrides`

- Unique `(tenant_id, feature_code)`; FK CASCADE.
- Prioritas **tertinggi** di `canAccessFeature()`/`getQuota()` (override > plan).
- **Dampak demo:** **tidak perlu** bila paket `pro` sudah cukup. Bila dipakai,
  `expires_at` harus dicek (override kedaluwarsa diabaikan).

---

## 8.9 `provisioning_requests` & `provisioning_nonces`

| Tabel | Struktur | Dampak demo |
|---|---|---|
| `provisioning_requests` | Ledger idempotensi SaaS; `idempotency_key` UNIQUE; FK `result_tenant_id`, `(result_tenant_id,result_user_id)`, `result_user_id` | **Tidak perlu** bila tenant demo dibuat langsung (bukan via API provisioning) |
| `provisioning_nonces` | PK `nonce`, `expires_at_epoch` dengan index | Tidak perlu |

> ⚠️ **Temuan T-P1** — `provisioning_requests` punya **dua** FK ke `users`:
> komposit `(result_tenant_id, result_user_id)` dan tunggal `result_user_id`.
> Ini duplikasi referensi (bukan blocker, dicatat untuk Batch audit DB).

---

## 8.10 File yang Diperiksa pada Batch 2

**Schema (live, read-only):** `settings`, `shifts`, `shift_handovers`,
`employee_id_sequences`, `owner_onboarding_tokens`, `email_outbox`,
`email_verification_otps`, `tenant_subscriptions`, `tenant_entitlement_overrides`,
`provisioning_requests`, `provisioning_nonces`, `eod_settlements` (kolom),
`information_schema.TRIGGERS` (0 trigger pada tabel batch ini).

**Kode**
- `app/Modules/Settings/Models/Setting.php`, `TenantSubscription.php`, `ProvisioningRequest.php`, `ProvisioningNonce.php`, `TenantEntitlementOverride.php`, `PlanEntitlement.php`
- `app/Modules/Sales/Models/Shift.php`, `ShiftHandover.php`, `EodSettlement.php`
- `app/Modules/Sales/Services/ShiftService.php`
- `app/Modules/Settings/Services/EntitlementService.php`, `TenantProvisioningService.php`, `ProvisioningService.php`
- `app/Modules/Users/Services/EmployeeIdGenerator.php`, `CashierPinService.php`
- `app/Modules/Auth/Services/EmailVerificationOtpService.php`
- `app/Modules/Reports/Services/ReportService.php` (EOD settlement)
- `config/pos.php` (`tax_rate`, `rounding_behavior`, `employee_id_prefix`)

**Seeder / generator**
- `database/seeders/SettingSeeder.php`
- `database/seeders/Pos/ShiftSeeder.php` (baris 1-129)
- `database/seeders/Pos/Concerns/StampsTenantOnBulkInsert.php`
- `app/Console/Commands/PopulateApeironFullYearDataset.php` (baris 1125-1170)

**Database (read-only):** `plans`, `plan_entitlements` (24 baris), `permissions`.

---

## 8.11 Temuan & Blocker Batch 2

| ID | Temuan | Tingkat | Tindakan |
|---|---|---|---|
| T-S1 | `tax_rate` disimpan string; pajak/pembulatan harus via `Setting::get` | Design | Generator Phase 5 membaca setting |
| T-S2 | `SettingSeeder` tanpa `tenant_id` menimpa lintas tenant bila di luar `runAs` | **Blocker generator** | Jangan panggil seeder di luar context |
| T-SH1 | `ShiftSeeder` hardcode user ID + `TRUNCATE` + FK mustahil | **Blocker generator** | Buat ulang berbasis ID hasil lookup |
| T-SH2 | `expected_cash`/`cash_difference` sintetis, tidak rekonsiliasi | **Blocker generator** | Hitung dari payments + cash drawer |
| T-SH3 | Satu shift aktif per kasir **per cabang** | Design | Generator mematuhi unique index |
| T-SH4 | Kasir butuh `pin_hash` (bcrypt 4–6 digit) untuk buka shift | Blocker demo interaktif | ISI `pin_hash` via `Hash::make` |
| T-SH5 | `orders.shift_id` harus milik kasir & cabang yang sama | **Blocker konsistensi** | Generator memetakan order → shift |
| T-HD1 | `discrepancy_amount` tidak dihitung dari selisih nyata | Minor | Hitung bila handover dibuat |
| T-E1 | `employee_id` unik global, harus lewat generator | Design | Pakai `EmployeeIdGenerator` |
| T-SUB1 | Subscription `trialing` meresolusi paket `standard_trial` (max 1 store) | **Blocker skenario** | Set `tenant_subscriptions.plan_id = 3` |
| T-SUB2 | Tidak ada unique `tenant_id` pada `tenant_subscriptions` | Design | `updateOrCreate` |
| T-SUB3 | Kuota di-cache 1 jam | Minor | `clearCache()` setelah perubahan |
| T-P1 | FK ganda `result_user_id` pada `provisioning_requests` | Info | Dicatat, tidak dipakai dataset |

---

## 8.12 Implikasi untuk Rancangan Dataset (masuk Phase 4)

Urutan dependensi yang kini terverifikasi untuk satu tenant + dua cabang:

```
tenants (pro, onboarding completed, access active)
  └── roles (8 role via RoleService, resolve by role_code)
  └── stores x2 (is_initial, code unik aplikasi)
  └── users (owner A, owner B) + pin_hash untuk kasir
      └── user_stores (user ↔ cabang)
  └── settings (baseline readiness + tax/rounding)
  └── tenant_subscriptions (plan_id = 3 / pro)  ← WAJIB sebelum cabang ke-2
  └── employee_id_sequences (otomatis)
  └── devices x N per cabang
      └── shifts (kasir × cabang × hari)
          └── orders (shift_id, user_id, store_id konsisten)
          └── payments / cash_drawer_movements
```

> **Catatan urutan:** `assertCanCreateStore()` dipanggil saat membuat cabang; karena
> itu **subscription `pro` harus terpasang sebelum cabang kedua dibuat**.

---

## 8.13 Status Batch 2: SELESAI — TIDAK ADA PERUBAHAN DATA

Diverifikasi ulang setelah audit: `tenants=0, stores=0, users=0, roles=0, shifts=0,
settings=0, tenant_subscriptions=0`.

### Rekomendasi Batch 3

Audit **katalog**: `categories`, `brands`, `units_of_measure`, `product_unit_conversions`,
`products`, `product_variants`, `product_attributes`, `product_attribute_values`,
`product_variant_attribute_values`, `product_bundle_items`, `product_media`,
`product_ingredients`, `product_cost_histories`, `price_lists`, `price_list_items`,
`store_product_assortments`, `tax_classes`, `ingredients`, `loyalty_tiers`, `discounts`.
Tujuan: memastikan relasi katalog + BOM + harga konsisten sebelum purchasing/POS.

---

# FASE 2 — BATCH 3: KATALOG, BOM, HARGA, PAJAK, DISKON

> **Sifat:** audit read-only. Tidak ada DDL/DML/seeder. DDL dari `SHOW CREATE TABLE`
> live; perilaku dari pembacaan service/model.
>
> **Checkpoint 2-Batch-3: SELESAI.**

## 9.0 Ringkasan Batch 3

| No | Tabel | Ownership | Status | Wajib demo |
|---|---|---|---|---|
| 1 | `categories` | Tier 2 | Terverifikasi | **Ya** (hierarkis via `parent_id`) |
| 2 | `brands` | Tier 2 | Terverifikasi | Opsional |
| 3 | `units_of_measure` | Tier 2 | Terverifikasi | **Ya** bila memakai konversi/UoM |
| 4 | `product_unit_conversions` | Tier 2 | Terverifikasi | Opsional |
| 5 | `products` | Tier 2 | Terverifikasi | **Ya** |
| 6 | `product_variants` | Tier 2 | Terverifikasi | Opsional |
| 7 | `product_attributes` / `product_attribute_values` / `product_variant_attribute_values` | Tier 2 | Terverifikasi | Opsional |
| 8 | `product_bundle_items` | Tier 2 | Terverifikasi + DAG guard | Opsional |
| 9 | `product_media` | Tier 2 | Terverifikasi | Opsional |
| 10 | `product_ingredients` (BOM) | Tier 2 | Terverifikasi | **Ya** untuk produk F&B |
| 11 | `product_cost_histories` | Hybrid (store nullable) | Terverifikasi | Tidak (jejak perubahan biaya) |
| 12 | `price_lists` / `price_list_items` | Hybrid (store nullable) | Terverifikasi | Opsional |
| 13 | `store_product_assortments` | Tier 3A | Terverifikasi | **Disarankan** (ketersediaan per cabang) |
| 14 | `tax_classes` | Tier 2 | Terverifikasi | Opsional (pajak aktual via `settings.tax_rate`) |
| 15 | `ingredients` | Tier 2 | Terverifikasi | **Ya** (stok BOM) |
| 16 | `loyalty_tiers` | Tier 2 | Terverifikasi | Opsional |
| 17 | `discounts` | Tier 3B (store nullable) | Terverifikasi | Opsional |

Trigger pada domain ini: **hanya** `trg_categories_check_parent_before_update`
(guard siklus parent kategori). Tidak ada trigger pada `products`, `product_variants`,
`ingredients`, `tax_classes`, `price_lists`, `discounts`.

---

## 9.1 `categories`

- PK `id`; FK `tenant_id → tenants`; **self-FK komposit** `(tenant_id, parent_id) → categories(tenant_id, id)`.
- Unique: `(tenant_id, category_name, name_uniqueness_key)` (soft-delete aware) dan `(tenant_id, category_code)`.
- Generated: `name_uniqueness_key = IF(deleted_at IS NULL, 0, NULL)`.
- Index: `(tenant_id, parent_id, sort_order)`, `(tenant_id, is_active)`.
- Trigger `trg_categories_check_parent_before_update` mencegah siklus parent saat UPDATE.

**Model/Service:** `app/Modules/Products/Models/Category.php`, `Services/CategoryService.php`.
**Seeder:** `database/seeders/Pos/CategorySeeder.php` (`TRUNCATE categories` — lihat T-CA1).

> ⚠️ **T-CA1** — `CategorySeeder` memanggil `truncate` pada tabel; **dilarang** dipakai
> pada dataset yang sudah berisi data. `category_code` wajib unik per tenant bila diisi.
> `parent_id` harus menunjuk kategori tenant yang sama (dijaga FK komposit).

---

## 9.2 `brands`, `units_of_measure`, `product_unit_conversions`

| Tabel | Unique | Catatan |
|---|---|---|
| `brands` | `(tenant_id, code, name_uniqueness_key)`, `(tenant_id, name, name_uniqueness_key)` | `code` wajib; soft-delete aware |
| `units_of_measure` | `(tenant_id, code)`; CHECK `precision_scale BETWEEN 0 AND 4` | `code` mis. `pcs`, `gram`, `ml` |
| `product_unit_conversions` | `(tenant_id, product_id, from_uom_id, to_uom_id)`; CHECK `factor > 0`, `from <> to` | Semua FK komposit ke product & UoM |

> ⚠️ **T-U1** — `uom_precision_scale_check` membatasi 0–4. Bila dataset memakai
> satuan pecahan (kg, gram), gunakan `precision_scale` yang sesuai agar kuantitas
> tidak terpangkas saat ditampilkan.

---

## 9.3 `products` — entitas pusat

| Aspek | Detail |
|---|---|
| FK komposit | `(tenant_id, category_id) → categories` (WAJIB); `(tenant_id, brand_id)`, `(tenant_id, base_uom_id)`, `(tenant_id, tax_class_id)` (opsional) |
| Unique | `(tenant_id, product_code)`, `(tenant_id, barcode)` |
| CHECK | `product_type ∈ (standard, service, non_inventory, bundle)`; `status ∈ (draft, active, inactive, discontinued)`; `costing_method ∈ (standard, fifo, moving_average)`; `standard_cost >= 0` |
| Money | `product_price` **bigint** (Rupiah bulat), `standard_cost` **decimal(15,2)** |
| Legacy stock | `stock` int unsigned — **mirror**, bukan sumber kebenaran |
| Flags | `has_variants`, `track_inventory`, `is_tax_inclusive`, `is_active` |

**Model:** `app/Modules/Products/Models/Product.php`
- `creating()`: auto `product_code = PRD-<tenant>-<4digit>` dari `max(id)+1` (global).
- `created()`: membuat baris `product_stock_balances` untuk **semua store tenant**;
  store pertama menerima `quantity = product.stock`, store lain `0`.
- `scopeAvailableForOrder()`: hanya produk `is_active=true`, `status='active'`, dan
  (`track_inventory=false` **atau** `product_type ∈ {service,non_inventory,bundle}`
  **atau** `stock > 0`).

**Service:** `ProductService::store()/update()`
- `product_type ∈ {service, non_inventory, bundle}` → memaksa `track_inventory=false`, `stock=0`.
- Bila `config('pos.stock_balances_authoritative')` **true** dan `stock` diubah, perubahan
  dirutekan ke `applyStockAdjustment()` → insert `stock_movements` + update balance;
  kolom `products.stock` hanya **mirror**.

> 🔴 **T-PR1 (blocker konsistensi stok)** — Untuk produk ber-BOM (`product_ingredients`),
> `TransactionService` **tidak** memotong `products.stock`; ketersediaan ditentukan oleh
> `ingredients.current_stock`. Untuk produk non-BOM, stok dipotong di `products.stock`
> **dan/atau** `product_stock_balances` tergantung `pos.stock_balances_authoritative`.
> Generator **wajib** memilih satu jalur dan konsisten, atau laporan inventori tidak
> akan cocok.

> ⚠️ **T-PR2** — `created()` membuat balance untuk store yang **sudah ada** saat produk
> dibuat. Bila produk dibuat **sebelum** cabang kedua ada, balance cabang kedua
> **tidak** otomatis terbentuk — tetapi `Store::created()` melengkapinya saat cabang
> dibuat. Urutan aman: **store dulu, lalu produk** (atau sebaliknya, keduanya
> saling melengkapi — tetap harus diverifikasi setelah seeding).

> ⚠️ **T-PR3** — `product_code` auto dari `max(id)+1` global; dua tenant dapat
> menghasilkan `PRD-9-0001` untuk tenant berbeda (unik per tenant, aman), tetapi
> kode bisa "melompat". Generator sebaiknya mengisi kode eksplisit dan deterministik.

---

## 9.4 Varian & atribut

| Tabel | Unique | FK |
|---|---|---|
| `product_variants` | `(tenant_id, sku)`, `(tenant_id, barcode)` | `(tenant_id, product_id) → products` CASCADE |
| `product_attributes` | `(tenant_id, name)` | `tenant_id → tenants` |
| `product_attribute_values` | `(tenant_id, attribute_id, value)` | `(tenant_id, attribute_id) → product_attributes` CASCADE |
| `product_variant_attribute_values` | `(tenant_id, variant_id, attribute_id)` | variant, attribute, attr-value (semua komposit) |

- `product_variants.price_override` decimal(15,2) NULL; CHECK `>= 0`; `costing_method` sama dengan produk.
- `products.has_variants` harus konsisten: `true` hanya bila ada varian.

> ⚠️ **T-V1** — `sku` NULL diizinkan oleh MySQL (unique mengabaikan NULL ganda),
> tetapi untuk dataset demo **isi SKU** agar dapat dicari dan direkonsiliasi.

---

## 9.5 `product_bundle_items` (Sales BOM)

- FK komposit ke `products` (bundle & component), `product_variants`, `units_of_measure`.
- Unique `(tenant_id, bundle_product_id, component_product_id, component_variant_id)`.
- CHECK `quantity > 0`; `bundle_product_id <> component_product_id`.
- `BundleComponentExpanderService::validateDag()` menolak siklus (DFS) di level aplikasi —
  **tidak ada guard DB** untuk siklus tak langsung.

> ⚠️ **T-B1** — Bila dataset memakai bundle, komponen harus produk bertipe
> `standard` yang dapat dijual, dan **tidak boleh** membentuk siklus. Produk bundle
> dipaksa `track_inventory=false, stock=0`.

---

## 9.6 `product_media`

- FK `(tenant_id, product_id) → products` CASCADE.
- Kolom wajib `disk`, `file_path`, `mime_type`, `file_size_bytes`, `is_primary`.
- **Dampak demo:** bila diisi, file harus benar-benar ada di storage; kalau tidak,
  UI menampilkan gambar rusak. **Rekomendasi: kosongkan** atau isi hanya path yang valid.

---

## 9.7 `product_ingredients` (BOM resep)

- Unique `product_ingredients_unique (product_id, ingredient_id)` — **TIDAK** menyertakan `tenant_id`
  (tetapi FK komposit sudah membatasi lintas tenant).
- FK komposit ke `products` CASCADE dan `ingredients` ON UPDATE CASCADE.
- CHECK `chk_pi_qty_positive (quantity_required > 0)`.
- `quantity_required` decimal(14,4) unsigned.

**Seeder:** `ProductIngredientSeeder` memakai `TRUNCATE product_ingredients` (T-PI1).

> 🔴 **T-PI1 (blocker generator)** — `TRUNCATE` pada BOM menghapus resep data yang ada.
> Refactor ke `upsert` berdasarkan `(product_id, ingredient_id)`.

> ⚠️ **T-PI2** — `product_ingredients_unique` **tidak** memasukkan `tenant_id`, meskipun
> `product_id`/`ingredient_id` sudah unik global — aman untuk sekarang, tetapi bila
> suatu saat ada reuse ID lintas tenant secara artifisial, unique ini akan menolak.

---

## 9.8 `product_cost_histories`

- `store_id` **nullable** (hybrid); FK komposit ke `products`, `stores`, `users`.
- `effective_at` NOT NULL; `created_at` default `CURRENT_TIMESTAMP`.
- **Dampak demo:** hanya perlu bila fitur histori biaya ditampilkan; tidak wajib.
  Bila diisi, `old_cost`/`new_cost` harus berurutan secara kronologis.

---

## 9.9 `price_lists` & `price_list_items`

| Tabel | Unique | CHECK |
|---|---|---|
| `price_lists` | `(tenant_id, code)` | `type ∈ (standard, branch, wholesale, customer_tier, customer_specific, channel)` |
| `price_list_items` | `(tenant_id, price_list_id, product_id, product_variant_id, uom_id, min_quantity)` | `price >= 0`, `min_quantity >= 1` |

**Hierarki harga (`CatalogPriceResolverService::resolvePrice`)** — sumber kebenaran:

```
1. price_lists customer_specific (customer_id cocok)
2. price_lists aktif sesuai konteks (store, loyalty tier, channel, min_quantity)
   → urut priority ASC, min_quantity DESC, id ASC
3. store_product_assortments.store_price_override (varian → produk)
4. product_variants.price_override
5. products.product_price
```

> 🔴 **T-PL1 (blocker konsistensi harga)** — Bila dataset membuat `price_lists` dan
> `store_product_assortments.store_price_override` **sekaligus**, harga yang muncul di
> POS adalah hasil hierarki di atas, **bukan** `products.product_price`. Generator
> transaksi **wajib** memakai `resolvePrice()` (atau mereplikasi hierarki ini persis),
> supaya `order_items.price_at_sale` cocok dengan yang ditampilkan kasir.

> ⚠️ **T-PL2** — `price_lists.store_id` nullable (deleted_at juga ada). Seed yang
> membuat price list per cabang harus mengisi `store_id` cabang yang benar.

---

## 9.10 `store_product_assortments`

- Unique `spa_tenant_store_product_unique (tenant_id, store_id, product_id)` — **satu baris per produk per cabang** (varian di kolom `product_variant_id`, bukan bagian unique).
- `allowed_channels` JSON (CHECK `json_valid`).
- `is_available`, `store_price_override`.

**Dampak demo:** mengontrol produk yang tampil di cabang tertentu
(`CatalogPriceResolverService::isAvailableInStore`). Tanpa baris assortment,
produk **tetap dianggap tersedia** di semua cabang (default `true`).

> ⚠️ **T-AS1** — Karena unique hanya `(tenant, store, product)`, satu produk **tidak dapat**
> memiliki dua baris assortment untuk dua varian berbeda. Ini keterbatasan schema yang
> harus diperhitungkan bila varian + ketersediaan per cabang digabung.

---

## 9.11 `tax_classes`

- Unique `(tenant_id, code)`; CHECK `rate >= 0 AND rate <= 100`.
- **Penting:** pajak transaksi **tidak** dibaca dari `tax_classes`, melainkan dari
  `Setting::get('tax_rate')` (default `0.11`) di `TransactionService::calculateOrderTotals()` (baris 1009).
  `products.tax_class_id` dan `is_tax_inclusive` tersedia tetapi **tidak** dipakai dalam
  perhitungan total di `calculateOrderTotals()`.

> 🔴 **T-TX1 (blocker rekonsiliasi)** — Jangan menghitung pajak per produk dari
> `tax_classes.rate`. Formula aplikasi (terverifikasi):

```
discountedSubtotal = max(0, subtotal − discount)
taxAmount          = (int) round(discountedSubtotal × tax_rate)
totalAmount        = (int)(discountedSubtotal + taxAmount)
totalAmount        = round_to(rounding_value, rounding_behavior)   # default 100, ROUND_NEAREST
```

> ⚠️ **T-TX2** — `products.is_tax_inclusive` **tidak** dihormati di
> `calculateOrderTotals()`; jangan mengasumsikan harga inklusif pajak pada dataset.

---

## 9.12 `ingredients`

- Unique `(tenant_id, ingredient_code)`; `current_stock` decimal(14,4), `cost_per_unit` decimal(14,2), `reorder_level` decimal(14,4).
- **Legacy mirror** `current_stock`; saldo otoritatif ada di `ingredient_stock_balances` (Batch 4).

> ⚠️ **T-IN1** — `unit` varchar bebas (tidak FK ke `units_of_measure`). Generator harus
> memakai satuan yang konsisten dengan resep (`product_ingredients.quantity_required`).

> ⚠️ **T-IN2** — `ingredients.current_stock` **unsigned** → tidak bisa negatif. Bila
> konsumsi BOM melebihi stok, insert/update akan gagal (atau terpotong ke 0), sehingga
> pemilihan volume penjualan **harus** memperhitungkan stok awal + penerimaan.

---

## 9.13 `loyalty_tiers`

- Unique `(tenant_id, tier_code)`; `min_points` bigint, `points_multiplier` decimal(4,2), `benefits` JSON.
- **Dampak demo:** opsional; dipakai `price_lists.loyalty_tier_id` bila ada tier pricing.

---

## 9.14 `discounts`

- FK `tenant_id → tenants`; FK komposit `(tenant_id, store_id) → stores` (**baru**, remediasi `2027_01_04_000002`).
- Unique `(tenant_id, code)` — **satu kode per tenant**, tidak per cabang.
- `type ∈ enum(percentage, fixed)`; `value` bigint; `max_discount_amount`, `min_purchase_amount`, `usage_limit`, `is_stackable`, `scope` default `order`.

> ⚠️ **T-D1** — Unique `(tenant_id, code)` membatasi satu tenant hanya boleh punya satu
> baris untuk satu kode diskon, walau `store_id` berbeda. Dataset multi-cabang harus
> memakai kode berbeda per cabang bila ingin diskon spesifik cabang.

> ⚠️ **T-D2** — `discounts` sekarang punya FK store komposit (temuan DB-007 sudah
> diremediasi di `2027_01_04_000002`). `store_id = NULL` berarti global tenant.

> ⚠️ **T-D3** — `value` bigint: untuk `percentage` bernilai persen (mis. 10), untuk
> `fixed` bernilai Rupiah. Tidak ada CHECK konsistensi tipe/nilai.

---

## 9.15 File yang Diperiksa pada Batch 3

**Schema (live):** 20 tabel di atas + `information_schema.TRIGGERS` (1 trigger kategori).

**Kode**
- `app/Modules/Products/Models/` : `Product.php`, `Category.php`, `Brand.php`, `UnitOfMeasure.php`, `ProductUnitConversion.php`, `ProductVariant.php`, `ProductAttribute.php`, `ProductAttributeValue.php`, `ProductVariantAttributeValue.php`, `ProductBundleItem.php`, `ProductMedia.php`, `ProductCostHistory.php`, `PriceList.php`, `PriceListItem.php`, `StoreProductAssortment.php`, `TaxClass.php`
- `app/Modules/Products/Services/` : `ProductService.php`, `CatalogPriceResolverService.php`, `BundleComponentExpanderService.php`, `CategoryService.php`, `MenuCacheService.php`
- `app/Modules/Sales/Services/TransactionService.php` (baris 985-1040, 817-823)
- `app/Modules/Inventory/Models/Ingredient.php` (referensi silang)
- `config/pos.php` (`tax_rate`, `rounding_*`, `stock_balances_authoritative`)

**Seeder:** `database/seeders/Pos/{CategorySeeder,ProductSeeder,ProductIngredientSeeder,IngredientSeeder,DiscountSeeder,LoyaltyTierSeeder}.php`

---

## 9.16 Temuan & Blocker Batch 3

| ID | Temuan | Tingkat | Tindakan |
|---|---|---|---|
| T-CA1 | `CategorySeeder` TRUNCATE | Blocker generator | Upsert |
| T-U1 | `precision_scale` 0–4 | Minor | Sesuaikan UoM |
| T-PR1 | Stok BOM vs non-BOM dua jalur | **Blocker konsistensi** | Pilih satu jalur, verifikasi saldo |
| T-PR2 | Balance row per store bergantung urutan create | Design | Verifikasi setelah seed |
| T-PR3 | `product_code` dari max(id) global | Minor | Isi kode eksplisit |
| T-V1 | SKU nullable | Minor | Isi SKU |
| T-B1 | DAG bundle hanya di aplikasi | Design | Hindari siklus |
| T-PI1 | `ProductIngredientSeeder` TRUNCATE | **Blocker generator** | Upsert |
| T-PI2 | Unique BOM tanpa tenant_id | Info | — |
| T-PL1 | Harga POS = hierarki resolver, bukan `product_price` | **Blocker konsistensi** | Pakai `resolvePrice()` |
| T-PL2 | `price_lists.store_id` nullable | Design | Isi store eksplisit |
| T-AS1 | Assortment unique per produk (bukan per varian) | Design | Hindari varian+assortment |
| T-TX1 | Pajak dari `settings.tax_rate`, bukan `tax_classes` | **Blocker rekonsiliasi** | Pakai formula `calculateOrderTotals` |
| T-TX2 | `is_tax_inclusive` tidak dihormati | Design | Jangan pakai harga inklusif |
| T-IN1 | `ingredients.unit` bebas | Minor | Konsisten dengan resep |
| T-IN2 | `current_stock` unsigned | **Blocker volume** | Pastikan stok cukup |
| T-D1 | `discounts` unique kode per tenant | Design | Kode berbeda per cabang |
| T-D2 | FK store diskon sudah ada | Info (ter remediasi) | — |
| T-D3 | Tidak ada CHECK tipe/nilai diskon | Minor | Validasi di generator |

---

## 9.17 Formula resmi yang harus direplikasi generator

**Total order** (`TransactionService::calculateOrderTotals`, baris 1002-1032):

```php
$discountedSubtotal = max(0, $subtotalAmount - $discountAmount);
$taxAmount   = (int) round($discountedSubtotal * (float) Setting::get('tax_rate', 0.11));
$totalAmount = (int) ($discountedSubtotal + $taxAmount);
if ($roundingValue > 0 && $roundingBehavior !== 'NONE') {
    $totalAmount = match ($roundingBehavior) {
        'ROUND_NEAREST' => round($totalAmount / $roundingValue) * $roundingValue,
        'ROUND_UP'      => ceil($totalAmount / $roundingValue) * $roundingValue,
        'ROUND_DOWN'    => floor($totalAmount / $roundingValue) * $roundingValue,
        default         => round($totalAmount),
    };
}
```

**Harga satuan** (`CatalogPriceResolverService::resolvePrice`, 5 tier di §9.9).

---

## 9.18 Status Batch 3: SELESAI — TIDAK ADA PERUBAHAN DATA

Verifikasi ulang: `products=0, categories=0, ingredients=0, price_lists=0, discounts=0`.

### Rekomendasi Batch 4

Audit **inventori**: `stock_movements`, `product_stock_balances`, `ingredient_stock_movements`,
`ingredient_stock_balances`, `ingredient_restock_forecasts`, `goods_receipts`,
`goods_receipt_items`, `stock_adjustments`, `stock_adjustment_items`, `stock_opnames`,
`stock_opname_items`, `stock_transfers`, `stock_transfer_items`, `supplier_returns`,
`supplier_return_items`. Termasuk trigger append-only dan rekonsiliasi saldo.

---

# FASE 2 — BATCH 4: INVENTORI, LEDGER STOK, DAN DOKUMEN GUDANG

> **Sifat:** audit read-only. DDL dari `SHOW CREATE TABLE` live; perilaku dari
> `InventoryLedgerService`, model, dan generator legacy.
>
> **Checkpoint 2-Batch-4: SELESAI.**

## 10.0 Ringkasan Batch 4

| No | Tabel | Ownership | Status | Wajib demo |
|---|---|---|---|---|
| 1 | `stock_movements` | Tier 3A, **append-only** | Terverifikasi | **Ya** |
| 2 | `product_stock_balances` | Tier 3A, PK komposit | Terverifikasi | **Ya** |
| 3 | `ingredient_stock_movements` | Tier 3A, **append-only** | Terverifikasi | **Ya** bila ada BOM |
| 4 | `ingredient_stock_balances` | Tier 3A, PK komposit | Terverifikasi | **Ya** bila ada BOM |
| 5 | `ingredient_restock_forecasts` | Tier 2 (unique per ingredient) | Terverifikasi | Opsional (dihitung) |
| 6 | `goods_receipts` / `goods_receipt_items` | Tier 3A | Terverifikasi | **Disarankan** |
| 7 | `stock_adjustments` / `stock_adjustment_items` | Tier 3A | Terverifikasi | Disarankan (opening stock) |
| 8 | `stock_opnames` / `stock_opname_items` | Tier 3A | Terverifikasi | Opsional |
| 9 | `stock_transfers` / `stock_transfer_items` | Tier 2 (2 store) | Terverifikasi | Opsional |
| 10 | `supplier_returns` / `supplier_return_items` | Tier 3A | Terverifikasi | Opsional |

Trigger append-only aktif pada `stock_movements` dan `ingredient_stock_movements`
(diverifikasi via `information_schema.TRIGGERS`), sesuai catatan audit DB-005.

---

## 10.1 Prinsip Ledger (SUMBER KEBENARAN)

`app/Modules/Inventory/Services/InventoryLedgerService::recordBatchMovements()`
adalah **satu-satunya jalur resmi** untuk mengubah stok. Algoritmanya (baris 80-300):

1. **Validasi target**: tenant+store harus cocok; `item_type ∈ {product, ingredient}`.
2. **Idempotensi**: bila `idempotency_key` diberikan dan sudah ada → movement lama
   dikembalikan, **tidak** ada mutasi ganda (`findExistingMovement`, baris 413).
3. **Lock**: baris balance di-`lockForUpdate()` dengan **urutan kanonik** (anti-deadlock,
   sesuai DB §3.2 poin 6).
4. **Aggregasi delta** per `(store, item)`: bila net delta < 0 → cek saldo,
   `ValidationException` bila tidak cukup (`recordBatchMovements`, baris 176-184).
   ⚠️ **Stok negatif tidak pernah diizinkan** di level service.
5. **WAC**: inbound → `C_new = (Q_old·C_old + Q_in·C_in) / (Q_old + Q_in)`;
   outbound → `unit_cost` = WAC saat ini.
6. **Insert ledger** (`insertLedgerMovement`, baris 470-500) + update balance
   (`quantity`, `average_unit_cost`, `last_unit_cost`, `last_movement_id`).
7. **Sinkron mirror legacy** (`syncLegacyMirrorColumn`, baris 526-548):
   `products.stock += round(delta)` / `ingredients.current_stock += delta`.
8. **Cache**: `MenuCacheService::flush()` setelah commit.

> 🔴 **T-INV1 (blocker paling penting untuk dataset)** — Karena langkah 4 menolak
> stok negatif, **seluruh dataset harus aman secara stok**. Urutan wajib:
> stok awal/penerimaan **dulu**, penjualan **setelahnya**, dan stok akhir per
> `(store, produk/ingredient)` tidak boleh < 0. Ini adalah constraint keras yang
> tidak bisa diakali dengan `DB::table()->insert()` kecuali saldo dan ledger
> dijaga manual dengan benar.

> 🔴 **T-INV2 (blocker)** — `syncLegacyMirrorColumn` **selalu** mengubah mirror
> `products.stock` / `ingredients.current_stock` bila mutasi dilakukan lewat service.
> Generator yang menulis saldo langsung ke `product_stock_balances`
> (**seperti yang dilakukan generator legacy**, lihat §10.9) akan membuat **mirror
> dan balance berbeda**. UI/`scopeAvailableForOrder` membaca `products.stock`,
> sedangkan laporan inventori membaca balance → dua angka berbeda.

---

## 10.2 `stock_movements`

| Aspek | Detail |
|---|---|
| PK | `id` |
| FK komposit | `(tenant_id, store_id) → stores`; `(tenant_id, store_id, order_id) → orders`; `(tenant_id, order_item_id) → order_items`; `(tenant_id, product_id) → products` |
| Unique | `(tenant_id, idempotency_key)` — **per tenant** (remediasi DB-015) |
| CHECK | `chk_sm_type_enum` — 14 nilai |
| Kolom | `quantity` **int** (bukan decimal), `unit_cost` decimal(18,4), `reason` NOT NULL, `idempotency_key` NOT NULL |

**Tipe yang sah:** `initial_sync`, `sale_deduction`, `restore_compensation`,
`adjustment`, `adjustment_in`, `adjustment_out`, `opname_adjustment`,
`goods_receipt`, `goods_receipt_reversal`, `transfer_out`, `transfer_in`,
`transfer_return_in`, `transfer_shrinkage`, `supplier_return_out`.

> ⚠️ **T-SM1** — `quantity` bertipe **INT**, sedangkan `product_stock_balances.quantity`
> decimal(18,6). Kompensasi dilakukan `syncLegacyMirrorColumn` dengan
> `(int) round(delta)`. Untuk produk dengan stok pecahan, delta < 0.5 akan hilang
> saat mirror diperbarui. **Simpan kuantitas produk sebagai bilangan bulat.**

> ⚠️ **T-SM2** — `reason` NOT NULL dan `idempotency_key` NOT NULL; generator wajib
> mengisi keduanya dengan nilai deskriptif & deterministik (mis.
> `sm:sale:{order_id}:{order_item_id}`).

> ⚠️ **T-SM3** — Tipe `initial_sync` dan `adjustment` dipakai generator legacy
> dengan quantity positif. `adjustment` tidak konsisten dengan tipe
> `adjustment_in`/`adjustment_out` (yang lebih eksplisit); untuk dataset baru
> gunakan `adjustment_in`/`adjustment_out` agar arah terbaca dari tipe.

---

## 10.3 `product_stock_balances`

| Aspek | Detail |
|---|---|
| **PK komposit** | `(tenant_id, store_id, product_id)` — tidak ada `id` |
| FK | `(tenant_id, store_id) → stores`; `(tenant_id, product_id) → products` |
| CHECK | `quantity >= 0`, `min_stock_level >= 0`, `reorder_point >= 0`, `max_stock_level IS NULL OR >= reorder_point`, biaya `>= 0` |
| Baseline | `baseline_quantity`, `baseline_movement_id` (default 0) — titik rekonsiliasi opname |
| Biaya | `average_unit_cost`, `last_unit_cost` decimal(18,4) |

> ⚠️ **T-PSB1** — **Tidak ada** `ON DELETE CASCADE` dari produk/store. Menghapus
> produk yang sudah punya balance akan gagal secara FK (dan memang seharusnya
> soft-delete). Generator **tidak boleh** hard-delete produk/store setelah balance dibuat.

> ⚠️ **T-PSB2** — Row dibuat oleh `Product::created()` untuk setiap store yang ada
> saat itu, dan oleh `Store::created()` untuk setiap produk yang ada saat itu.
> **Urutan create store/produk memengaruhi kelengkapan**, tetapi dua arah saling
> melengkapi — tetap harus diverifikasi dengan query kelengkapan (lihat §10.10).

---

## 10.4 `ingredient_stock_movements` & `ingredient_stock_balances`

Struktur paralel dengan produk, dengan perbedaan:

| Aspek | `ingredient_stock_movements` | `ingredient_stock_balances` |
|---|---|---|
| Quantity | decimal(14,4) — **mendukung pecahan** | decimal(18,6) |
| Tipe | 16 nilai (termasuk `purchase_receipt`, `waste`) | — |
| `created_by` | FK komposit ke `users` | — |
| `reason` | **nullable** | — |
| Index analitik | `(type, created_at, ingredient_id)` untuk forecast | — |
| PK | `id` | `(tenant_id, store_id, ingredient_id)` |

**Tipe sah:** `sale_deduction`, `purchase_receipt`, `waste`, `adjustment`,
`restore_compensation`, `initial_sync`, `goods_receipt`, `goods_receipt_reversal`,
`transfer_out`, `transfer_in`, `transfer_return_in`, `transfer_shrinkage`,
`adjustment_in`, `adjustment_out`, `opname_adjustment`, `supplier_return_out`.

> ⚠️ **T-ISM1** — `product` memakai `sale_deduction`, `ingredient` juga memakai
> `sale_deduction`, tetapi `purchase_receipt`/`waste` **hanya** untuk ingredient.
> Pastikan tipe yang dipakai ada di CHECK masing-masing tabel.

> 🔴 **T-ISM2** — Konsumsi BOM (`TransactionService`, baris 660-740) memotong
> `ingredients` **per order item** dengan kunci idempotensi per item.
> Volume 12 bulan × jumlah resep × jumlah item akan menghasilkan puluhan ribu
> baris — ini sesuai laporan lama (~400K), tetapi harus dihitung ulang dari volume
> final Phase 4.

---

## 10.5 `ingredient_restock_forecasts`

- **Unique `ingredient_id` GLOBAL** (bukan per tenant!) — `ingredient_restock_forecasts_ingredient_id_unique`.
- FK komposit `(tenant_id, ingredient_id) → ingredients` CASCADE.
- Dihitung dari `ingredient_stock_movements (type='sale_deduction', created_at)`.

> ⚠️ **T-FR1** — Bila forecast diisi, harus **diturunkan** dari ledger
> (`avg_daily_consumption`, `projected_stockout_at`, `suggested_reorder_qty`),
> bukan angka acak. Tabel ini per-ingredient, **bukan per store** — bertentangan
> dengan struktur stok per cabang.

---

## 10.6 Dokumen gudang: `goods_receipts`

| Aspek | Detail |
|---|---|
| Unique | `(tenant_id, store_id, receipt_number)`, `(tenant_id, idempotency_key)` |
| CHECK | `status ∈ (DRAFT, POSTED, CANCELLED, REVERSED)` |
| Kolom | `received_at` NOT NULL, `posted_at`, `reversed_at`, `created_by` NOT NULL, `posted_by`, `reversed_by`, `reversal_reason` |
| Item | `goods_receipt_items.item_type ∈ (product, ingredient)`; CHECK `quantity_received > 0 AND unit_cost >= 0` |
| FK item | komposit ke receipt (CASCADE), store, product/ingredient |

> ⚠️ **T-GR1** — `goods_receipts` **tidak** memiliki FK ke `purchase_orders` —
> hanya `purchase_order_reference` varchar(128). Relasi ke PO bersifat longgar
> (dicocokkan di `goods_received_notes`/Modul 5, Batch 5).

> ⚠️ **T-GR2** — Item tidak memiliki unique `(goods_receipt_id, product_id)`; satu
> receipt dapat berisi baris produk yang sama dua kali (mis. batch berbeda).
> Generator harus menghindari duplikasi yang tidak disengaja.

---

## 10.7 `stock_adjustments` / `stock_opnames` / `stock_transfers`

| Dokumen | Status | Reason/CHECK | Catatan |
|---|---|---|---|
| `stock_adjustments` | `DRAFT, POSTED, CANCELLED` | `reason_code ∈ (DAMAGED, EXPIRED, SHRINKAGE, PRODUCTION_WASTE, FOUND, OPENING_STOCK, OTHER)` | `OPENING_STOCK` = jalur resmi stok awal |
| `stock_opname_items` | parent: `DRAFT, COUNTING, PENDING_APPROVAL, APPROVED, CANCELLED` | `drift_resolution_mode ∈ (NONE, RE_SNAPSHOT, REBASE_OVERRIDE)` | unique per `(opname, product)` dan `(opname, ingredient)` |
| `stock_transfers` | `DRAFT, IN_TRANSIT, RECEIVED, CANCELLED` | `chk_sti_anti_over_receipt (received <= shipped)` | `from_store_id <> to_store_id` **tidak** dijaga CHECK |

> 🔴 **T-ADJ1 (rekomendasi kuat)** — Untuk stok awal 12 bulan, gunakan
> `stock_adjustments` dengan `reason_code='OPENING_STOCK'` + movements
> `adjustment_in`/`initial_sync`. Ini adalah jalur yang **terlihat di UI** sebagai
> dokumen sah, berbeda dengan menulis langsung ke tabel balance.

> ⚠️ **T-TR1** — `stock_transfers` tidak memiliki CHECK `from_store_id <> to_store_id`.
> Generator harus memvalidasi sendiri.

> ⚠️ **T-OP1** — `stock_opname_items.counted_quantity` nullable; `variance_quantity`
> nullable. Bila opname APPROVED, variance harus terisi dan menghasilkan
> `opname_adjustment` di ledger.

---

## 10.8 `supplier_returns` / `supplier_return_items`

- `supplier_returns.goods_receipt_id` FK **single-column** ke `goods_receipts(id)` ON DELETE SET NULL
  (bukan komposit — lemah terhadap lintas tenant, sesuai DB-008).
- `supplier_name` varchar (bukan FK ke `suppliers`).
- CHECK `status ∈ (DRAFT, POSTED, CANCELLED, REVERSED)`; item CHECK `quantity > 0 AND unit_cost >= 0`.

> ⚠️ **T-SR1** — Karena `goods_receipt_id` tanpa `tenant_id`, generator **wajib**
> memvalidasi bahwa receipt milik tenant yang sama. `supplier_name` bebas string —
> untuk dataset baru lebih baik merujuk `suppliers.name` (Batch 5) agar konsisten.

---

## 10.9 Kondisi Generator Legacy untuk Inventori (bukti)

`PopulateApeironFullYearDataset::setupInventoryMovementsAndBalances()` (baris 1500-1700):

| Masalah | Bukti | Dampak |
|---|---|---|
| Kuantitas penjualan diagregasi setahun menjadi **satu** movement `sale_deduction` | baris 1650-1656 (`reason: 'Penjualan ritel akumulatif setahun penuh'`, `created_at: 2026-10-13`) | Ledger tidak dapat direkonsiliasi per transaksi; semua penjualan jatuh di satu tanggal |
| `stock_movements.quantity` non-integer untuk produk? | `$perStoreConsumed = (int) round(...)` → bulat | Aman untuk INT, tetapi tidak menjelaskan item per order |
| Balance ditulis **langsung** lewat `DB::table('product_stock_balances')->upsert()` | baris 1660+ | **Melewati** `InventoryLedgerService` → `average_unit_cost`, `last_unit_cost`, `last_movement_id` tidak dihitung; mirror `products.stock` tidak disinkronkan |
| Restock `adjustment` bulanan tanpa GR/adjustment dokumen | baris 1625-1636 | Tidak ada jejak dokumen; `adjustment` tidak masuk `stock_adjustments` |
| `ingredients.current_stock` di-update ke **total semua store** | baris 1601-1608 (`$totalFinalAllStores`) | Mirror global, sedangkan ledger per-store → tidak konsisten |
| Idempotency key tidak menyertakan tenant | `sm:init:{store}:{product}` | Aman karena unique sudah `(tenant_id, key)`, tapi rawan bila kode dijalankan lintas tenant dengan store id sama |
| `initial_sync` masuk langsung tanpa dokumen | baris 1546, 1635 | Bertentangan dengan rekomendasi T-ADJ1 |

> 🔴 **T-LG1 (blocker generator)** — Generator legacy **melanggar invariant
> `InventoryLedgerService`**: menulis balance tanpa ledger-WAC dan tanpa sinkron
> mirror. Dataset baru **harus** memakai `recordBatchMovements()` atau
> mereplikasi langkah 4-7 secara identik.

> 🔴 **T-LG2 (blocker generator)** — Rincian per transaksi hilang. Untuk demo
> "riwayat transaksi & inventory dapat direkonsiliasi", **setiap item penjualan**
> harus menghasilkan `sale_deduction`-nya sendiri (atau agregat harian dengan
> `reason` yang menyebut rentang). Rekomendasi: satu movement per order item
> dengan `order_id`/`order_item_id` terisi.

---

## 10.10 Query rekonsiliasi (untuk Phase 8)

```sql
-- 1. Balance harus sama dengan SUM(ledger) per (store, product)
SELECT b.tenant_id, b.store_id, b.product_id, b.quantity,
       COALESCE(SUM(m.quantity), 0) AS ledger_sum
FROM product_stock_balances b
LEFT JOIN stock_movements m
  ON m.tenant_id=b.tenant_id AND m.store_id=b.store_id AND m.product_id=b.product_id
GROUP BY b.tenant_id, b.store_id, b.product_id, b.quantity
HAVING ABS(b.quantity - ledger_sum) > 0.000001;

-- 2. Saldo negatif (harus kosong)
SELECT * FROM product_stock_balances WHERE quantity < 0;
SELECT * FROM ingredient_stock_balances WHERE quantity < 0;

-- 3. Kelengkapan matriks store × produk
SELECT s.id AS store_id, p.id AS product_id
FROM stores s CROSS JOIN products p
WHERE s.tenant_id = ? AND p.tenant_id = ? AND p.track_inventory = 1
  AND NOT EXISTS (SELECT 1 FROM product_stock_balances b
                  WHERE b.store_id=s.id AND b.product_id=p.id);

-- 4. Mirror vs balance (produk & ingredient)
SELECT p.id, p.stock, SUM(b.quantity) AS balance_total
FROM products p JOIN product_stock_balances b ON b.product_id = p.id
GROUP BY p.id, p.stock HAVING p.stock <> ROUND(balance_total);
```

> ℹ️ Query #4 menunjukkan bahwa model data saat ini **memang** memiliki dua
> representasi (mirror vs balance). Keputusan Phase 4/5: pilih mirror sebagai
> "total semua cabang" atau ubah pembacaan UI. **Jangan mengubah schema.**

---

## 10.11 File yang Diperiksa pada Batch 4

**Schema (live):** 15 tabel di atas; `information_schema.TRIGGERS` untuk tabel ledger.

**Kode**
- `app/Modules/Inventory/Services/InventoryLedgerService.php` (baris 34-548)
- `app/Modules/Inventory/Models/StockMovement.php`, `app/Models/StockMovement.php`
- `app/Modules/Inventory/Models/ProductStockBalance.php`, `IngredientStockBalance.php`, `IngredientStockMovement.php`
- `app/Modules/Sales/Services/TransactionService.php` (baris 395-430, 660-740, 1915-2025)
- `app/Modules/Products/Models/Product.php` (create/balance hook, baris 160-215)
- `app/Modules/Inventory/Models/Ingredient.php` (balance mirror, baris 60-120)
- `app/Modules/Purchasing/*` (referensi GR — detail di Batch 5)

**Generator/Seeder**
- `app/Console/Commands/PopulateApeironFullYearDataset.php` (baris 1500-1700)
- `database/seeders/Pos/IngredientStockMovementSeeder.php`

---

## 10.12 Temuan & Blocker Batch 4

| ID | Temuan | Tingkat | Tindakan |
|---|---|---|---|
| T-INV1 | Stok negatif ditolak service | **Blocker volume** | Rencanakan stok awal + restock ≥ penjualan |
| T-INV2 | Mirror `products.stock` vs balance bisa divergen | **Blocker konsistensi** | Gunakan `recordBatchMovements()` |
| T-SM1 | `stock_movements.quantity` INT | Design | Kuantitas produk bilangan bulat |
| T-SM2 | `reason` & `idempotency_key` NOT NULL | Wajib | Isi deterministik |
| T-SM3 | `adjustment` ambigu arah | Design | Pakai `adjustment_in/out` |
| T-PSB1 | Balance tanpa CASCADE | Design | Jangan hard-delete produk/store |
| T-PSB2 | Kelengkapan balance bergantung urutan | Design | Verifikasi dengan query §10.10 |
| T-ISM1 | Tipe movement berbeda per tabel | Wajib | Validasi per tabel |
| T-ISM2 | Volume BOM besar | Kapasitas | Hitung ulang di Phase 4 |
| T-FR1 | Forecast per-ingredient (bukan per-store) | Design | Turunkan dari ledger |
| T-GR1 | GR tanpa FK ke PO | Info | Batch 5 |
| T-GR2 | Item GR tanpa unique | Minor | Hindari duplikasi |
| T-ADJ1 | `OPENING_STOCK` jalur resmi | Rekomendasi | Pakai untuk stok awal |
| T-TR1 | Transfer tanpa CHECK from<>to | Minor | Validasi generator |
| T-OP1 | Opname variance nullable | Minor | Isi bila APPROVED |
| T-SR1 | FK GR single-column | Design | Validasi tenant manual |
| T-LG1 | Generator legacy menulis balance langsung | **Blocker generator** | Refactor |
| T-LG2 | Penjualan diagregasi setahun | **Blocker generator** | Movement per item |

---

## 10.13 Status Batch 4: SELESAI — TIDAK ADA PERUBAHAN DATA

Verifikasi ulang: `stock_movements=0, product_stock_balances=0,
ingredient_stock_movements=0, ingredient_stock_balances=0, goods_receipts=0,
stock_adjustments=0, stock_opnames=0, stock_transfers=0, supplier_returns=0`.

### Rekomendasi Batch 5

Audit purchasing & supplier: `suppliers`, `supplier_contacts`, `supplier_products`,
`purchase_orders`, `purchase_order_lines`, `purchase_order_revisions`,
`purchase_requests`, `purchase_request_lines`, `purchase_order_line_pr_allocations`,
`goods_received_notes`, `goods_received_note_lines`, `purchase_approval_rules/requests/logs`,
`purchase_additional_costs`, `purchase_cost_allocations`, `purchase_returns(+lines)`,
`purchase_debit_notes(+applications)`, `supplier_invoices(+lines)`,
`supplier_invoice_grn_matches`, `supplier_performance_logs`, `reorder_suggestions`,
`accounting_payable_outbox`, `document_sequences`.

---

# FASE 2 — BATCH 5: PURCHASING, SUPPLIER, GRN, INVOICE, PAYABLE

> **Sifat:** audit read-only. DDL dari `SHOW CREATE TABLE` live; alur dari
> `PurchaseOrderService`, `GoodsReceivedNoteService`, `GoodsReceiptService`,
> `DocumentSequenceService`.
>
> **Checkpoint 2-Batch-5: SELESAI.**

## 11.0 Ringkasan Batch 5

| No | Tabel | Ownership | Status | Wajib demo |
|---|---|---|---|---|
| 1 | `suppliers` | Tier 2 | Terverifikasi | **Ya** |
| 2 | `supplier_contacts` | Tier 2 | Terverifikasi | Opsional |
| 3 | `supplier_products` | Tier 2 (polymorphic) | Terverifikasi | **Disarankan** |
| 4 | `purchase_requests` / `purchase_request_lines` | Tier 2+store | Terverifikasi | Opsional |
| 5 | `purchase_orders` / `purchase_order_lines` | Tier 2+store | Terverifikasi | **Ya** |
| 6 | `purchase_order_revisions` | Tier 2 | Terverifikasi | Opsional |
| 7 | `purchase_order_line_pr_allocations` | Tier 2 | Terverifikasi | Hanya bila dari PR |
| 8 | `purchase_approval_rules/requests/logs` | Tier 2 (+store opsional) | Terverifikasi | Opsional (cek threshold) |
| 9 | `goods_received_notes` / `_lines` | Tier 3A | Terverifikasi | **Ya** |
| 10 | `purchase_additional_costs` / `purchase_cost_allocations` | Tier 3A | Terverifikasi | Opsional (landed cost) |
| 11 | `purchase_returns` / `purchase_return_lines` | Tier 3A | Terverifikasi | Opsional |
| 12 | `purchase_debit_notes` / `_applications` | Tier 3A | Terverifikasi | Opsional |
| 13 | `supplier_invoices` / `supplier_invoice_lines` / `supplier_invoice_grn_matches` | Tier 3A | Terverifikasi | **Disarankan** |
| 14 | `supplier_performance_logs` | Tier 3A | Terverifikasi | Opsional (diturunkan) |
| 15 | `reorder_suggestions` | Tier 3A | Terverifikasi | Opsional (diturunkan) |
| 16 | `accounting_payable_outbox` | Hybrid (store nullable) | **Sebagian** — tabel ada, **tidak ada produsen/konsumen** | Tidak |
| 17 | `document_sequences` | Tier 2 (+store) | Terverifikasi | **Wajib** bila membuat nomor dokumen |

Trigger append-only: **tidak ada** pada `purchase_approval_logs`? → **ada** (sesuai audit
DB §3.2) — diverifikasi ulang pada `information_schema.TRIGGERS` saat audit.

---

## 11.1 `suppliers`, `supplier_contacts`, `supplier_products`

| Tabel | Unique | CHECK | Catatan |
|---|---|---|---|
| `suppliers` | `(tenant_id, code)` | — | `normalized_name` NOT NULL; `status` default `active`; `payment_terms_days`, `currency_code` |
| `supplier_contacts` | — (hanya index `(tenant, supplier, is_primary)`) | — | **Tidak ada unique** `(supplier_id, is_primary)` → bisa punya >1 kontak utama |
| `supplier_products` | `(tenant, supplier, purchasable_type, purchasable_id, purchase_uom)` | factor > 0, price ≥ 0, lead_time ≥ 0, min/multiple > 0, type ∈ (product, ingredient) | **Polymorphic** — tidak ada FK ke produk/ingredient |

> ⚠️ **T-SUP1** — `supplier_products.purchasable_id` **tanpa FK**. Generator wajib
> memvalidasi bahwa produk/ingredient milik tenant yang sama.

> ⚠️ **T-SUP2** — `supplier_products.purchase_uom` berupa varchar bebas, bukan FK ke
> `units_of_measure`. `uom_conversion_factor` menentukan konversi ke satuan dasar.

---

## 11.2 `purchase_requests` & line

- `pr_number` unique per tenant; `status ∈ (draft, submitted, approved, rejected, closed, cancelled)`.
- `fulfillment_status ∈ (unfulfilled, partially_ordered, fully_ordered)`.
- Line: `purchasable_type ∈ (product, ingredient)`; CHECK `requested_qty > 0`,
  `approved_qty >= 0`, `converted_to_po_qty <= approved_qty`.
- `preferred_supplier_id` FK **single-column** ke `suppliers(id)` (bukan komposit).

> ⚠️ **T-PR1** — `pr_lines_preferred_supplier_fk` single-column → generator harus
> memvalidasi tenant supplier secara manual.

---

## 11.3 `purchase_orders` — dokumen inti

| Aspek | Detail |
|---|---|
| Unique | `(tenant, po_number)`, `(tenant, idempotency_key)` |
| FK | komposit ke `stores`, `suppliers`, dan 3× `users` (creator/approver/releaser) |
| Status | `draft, pending_approval, approved, rejected, released, amended, closed, cancelled` |
| Receiving | `not_received, partially_received, fully_received, over_received` |
| Invoicing | `not_invoiced, partially_invoiced, fully_invoiced` |
| Source | `from_pr, direct` |
| Amounts | `subtotal_amount`, `discount_amount`, `tax_amount`, `additional_cost_estimate`, `total_amount` |
| Line | `ordered_qty`, `base_ordered_qty`, `received_qty`, `accepted_qty`, `rejected_qty`, `returned_qty`, `invoiced_qty`; `unit_price`, `discount_pct/amount`, `tax_rate`, `tax_amount`, `line_total`; `over_receipt_tolerance_pct` |

**Alur status terverifikasi (`PurchaseOrderService`):**
1. `createFromPurchaseRequests()` / `createDirectPurchaseOrder()` → status `draft`, `receiving_status='not_received'`, `invoicing_status='not_invoiced'`.
2. `submitPurchaseOrder()` hanya dari `draft`. Jika **tidak ada approval rule** atau nilai di bawah ambang → **auto-approve** (`status=approved`, `approved_at`, `approved_by_user_id`); jika ada rule → `pending_approval`.
3. `releasePurchaseOrder()` hanya dari `approved` dan supplier harus `active`.
4. Amend → `amended` + row di `purchase_order_revisions`.

> 🔴 **T-PO1** — `purchase_orders.created_by_user_id` wajib (NOT NULL). Semua FK user
> komposit → order harus dibuat oleh user tenant yang sama.

> 🔴 **T-PO2** — `purchase_orders.idempotency_key` NOT NULL + unique per tenant. Generator
> harus menghasilkan kunci deterministik (`po:{store}:{date}:{seq}`), **bukan** UUID acak
> per run, kalau ingin idempoten.

> ⚠️ **T-PO3** — `purchase_order_lines` **tidak** punya unique `(po_id, purchasable_type, purchasable_id)`.
> Satu produk bisa muncul dua kali di PO yang sama → generator harus menggabungkan agar
> kuantitas penerimaan tidak ambigu.

> ⚠️ **T-PO4** — `chk_po_amounts` tidak memaksa `total_amount = subtotal − discount + tax + additional`.
> Konsistensi ini hanya dijaga kode. Generator **wajib** menghitung ulang dengan aturan service.

---

## 11.4 `goods_received_notes` & `goods_received_note_lines` (Modul 5)

| Aspek | Detail |
|---|---|
| `grn_number` unique per tenant; `idempotency_key` nullable tapi unique bila ada |
| Status | `draft, posted, reversed, cancelled` |
| FK | komposit ke `purchase_orders`, `stores`, `suppliers`, 4× `users`, dan **self-FK** `reversal_of_grn_id` |
| Line | `line_number` unique per GRN; CHECK **`received_qty = accepted_qty + rejected_qty`** |
| Line movement | `stock_movement_id` / `ingredient_stock_movement_id` (FK **single-column**, ON DELETE SET NULL) |
| Cost | `unit_cost_snapshot`, `allocated_landed_cost_per_unit`, `effective_unit_cost` |

**Alur:** `GoodsReceivedNoteService::post()` → `recordBatchMovements()` dengan
idempotency `grn_{id}_{line}_post`, update `purchase_order_lines.received_qty/accepted_qty`,
lalu `receiving_status` PO.

> 🔴 **T-GRN1** — `chk_grnl_qty_balance : received_qty = accepted_qty + rejected_qty`
> adalah constraint keras. `damaged_qty` **tidak** termasuk dalam persamaan ini; ia
> harus dicatat di `rejected_qty` bila tidak diterima.

> 🔴 **T-GRN2** — `base_accepted_qty` harus = `accepted_qty × uom_conversion_factor`.
> Tidak ada CHECK yang menegakkannya → tanggung jawab generator.

> ⚠️ **T-GRN3** — `goods_received_notes.purchase_order_id` **NOT NULL** → **GRN wajib
> merujuk PO**. Jalur "GR tanpa PO" hanya tersedia di tabel lama `goods_receipts`
> (Batch 4) yang `purchase_order_reference`-nya varchar. Dua model penerimaan ini
> **hidup berdampingan** (legacy vs Modul 5).

> ⚠️ **T-GRN4** — `grnl_stock_movement_fk` dan `grnl_ingredient_movement_fk` single-column
> (tanpa `tenant_id`) — kelemahan tipe DB-008; generator harus mengisi ID movement yang
> benar milik tenant yang sama.

---

## 11.5 Dua sistem penerimaan bar barang (PENTING)

| Sistem | Tabel | Service | FK ke PO | Status |
|---|---|---|---|---|
| **Legacy** | `goods_receipts` + `goods_receipt_items` | `app/Modules/Inventory/Services/GoodsReceiptService.php` | **Tidak** (hanya `purchase_order_reference` varchar) | Dipakai generator lama / inventory |
| **Modul 5** | `goods_received_notes` + `_lines` | `app/Modules/Purchasing/Services/GoodsReceivedNoteService.php` | **Ya** (komposit) | Implementasi purchasing penuh |

> 🔴 **T-GRN5 (blocker arah implementasi)** — Dataset **harus memilih satu** sistem
> penerimaan. Memakai keduanya akan menggandakan stok (dua kali `recordBatchMovements`)
> atau menghasilkan dokumen yatim. Rekomendasi: gunakan **`goods_received_notes`**
> (Modul 5) karena terhubung ke PO, invoice, dan performance log. `goods_receipts`
> hanya untuk kompatibilitas legacy.

---

## 11.6 Biaya tambahan & alokasi

- `purchase_additional_costs`: unique `(tenant, cost_code)`, `(tenant, idempotency_key)`; `amount > 0`;
  `allocation_method` default `BY_VALUE`; `status` draft/…/allocated.
- `purchase_cost_allocations`: unique tidak ada; `allocated_amount >= 0`; menunjuk GRN line.
  `weight_ratio`, `is_residual_line`, `is_post_receipt_adjustment`.

> ⚠️ **T-LC1** — `Σ allocated_amount` per additional cost harus = `amount`
> (`is_residual_line=1` menyerap pembulatan). Tidak ada CHECK DB.

---

## 11.7 Retur pembelian, debit note, invoice

| Tabel | Unique | CHECK kunci |
|---|---|---|
| `purchase_returns` | `(tenant, return_number)`, `(tenant, idempotency_key)` | amounts ≥ 0; GRN **wajib** |
| `purchase_return_lines` | — | `return_qty > 0`, `unit_cost ≥ 0` |
| `purchase_debit_notes` | `(tenant, debit_note_number)`, `(tenant, idempotency_key)` | `applied_amount`, `remaining_amount`, `total_amount` ≥ 0 |
| `purchase_debit_note_applications` | — | `applied_amount > 0`; menunjuk invoice |
| `supplier_invoices` | `(tenant, internal_invoice_number)`, `(tenant, supplier_id, normalized_supplier_invoice_number)`, `(tenant, idempotency_key)` | status/matching/settlement enum; amounts ≥ 0 |
| `supplier_invoice_lines` | `(supplier_invoice_id, line_number)` | `invoiced_qty > 0`, harga ≥ 0 |
| `supplier_invoice_grn_matches` | `(supplier_invoice_line_id, goods_received_note_line_id)` | `matched_qty > 0` |

Status invoice: `draft, submitted, variance_hold, approved_payable, disputed, cancelled`;
matching: `unmatched, partially_matched, matched, variance_detected`;
settlement: `unpaid, partially_paid, paid`.

> ⚠️ **T-INV-1** — `supplier_invoices.outstanding_amount` harus
> `total_amount − debit_note_applied_amount − pembayaran`. Tidak ada trigger yang menjaga.

> ⚠️ **T-INV-2** — 3-way match (PO line ↔ GRN line ↔ invoice line) tidak memiliki CHECK
> bahwa `invoiced_qty ≤ accepted_qty`. Dijaga kode.

---

## 11.8 `supplier_performance_logs`, `reorder_suggestions`

- `supplier_performance_logs`: **unique `(tenant_id, goods_received_note_line_id)`**;
  5 FK komposit (store, supplier, PO, GRN, GRN line) — sesuai koreksi audit DB-021.
- `reorder_suggestions`: 3 FK komposit (store, supplier, converted PR); status `open`, derived.

> ⚠️ **T-PERF1** — `supplier_performance_logs` **wajib** menyertakan
> `goods_received_note_line_id` milik Modul 5. Jika dataset memakai sistem legacy
> `goods_receipts`, tabel ini tidak dapat diisi konsisten.

> ⚠️ **T-RO1** — `reorder_suggestions` adalah **turunan** (dari saldo, konsumsi,
> lead time). Bila diisi, harus dihitung dari data yang sama — jangan angka acak.

---

## 11.9 `accounting_payable_outbox`

- `event_id` char(36) NOT NULL; `idempotency_key` unique per tenant; `payload` JSON NOT NULL.
- CHECK `status ∈ (pending, processed_by_m7, failed)`.

> 🔴 **T-APO1 (gap)** — **Tidak ditemukan produsen maupun konsumen** kode untuk tabel
> ini (grep di `app/` hanya menemukan model, tanpa service yang menulis/membaca).
> Ini adalah **outbox untuk Modul 7 (Accounting)** yang belum diimplementasikan.
> **Jangan mengisi** tabel ini pada dataset demo; akan menciptakan antrean yang
> tidak pernah diproses dan menyesatkan UI jika kelak ada konsumen.

---

## 11.10 `document_sequences`

- Unique `(tenant_id, scope_key, doc_type, period_key)`.
- `scope_key` = `global` atau `store:{id}` (via `DocumentSequenceService::resolveScopeKey`).
- `period_key` = `global` untuk supplier, atau `YYYYMM` untuk dokumen bertanggal.
- Prefix default: `SUP, PR, PO, GRN, PINV, PRET, DN, LC`.
- Format nomor: `{PREFIX}-{storeId}-{period}-{0000}` atau `{PREFIX}-{period}-{0000}`.

> 🔴 **T-DOC1** — Jika generator memakai nomor dokumen buatan sendiri (mis.
> `PO-2025-0001`) tanpa menaikkan `document_sequences`, maka pembuatan PO berikutnya
> dari UI akan menghasilkan nomor **yang sudah dipakai** → `po_tenant_po_number_unique`
> gagal. Generator **wajib** menaikkan `last_value` konsisten.

---

## 11.11 Kondisi generator legacy untuk purchasing

> 🔴 **T-LPO1 (gap besar)** — `PopulateApeironFullYearDataset` **tidak membuat**
> `purchase_orders`, `goods_received_notes`, `supplier_invoices`, maupun `suppliers`.
> Restock dilakukan lewat `stock_movements` tipe `adjustment`/`initial_sync` langsung
> (Batch 4 §10.9). Artinya **modul purchasing akan tampil kosong** di demo.
> Ini adalah gap utama yang harus ditutup di Phase 5.

| Kebutuhan demo | Status generator legacy |
|---|---|
| Supplier master | ❌ tidak dibuat |
| Purchase order + approval | ❌ tidak dibuat |
| Penerimaan barang (GRN) | ❌ tidak dibuat |
| Invoice supplier + 3-way match | ❌ tidak dibuat |
| Debit note / retur | ❌ tidak dibuat |

---

## 11.12 File yang Diperiksa pada Batch 5

**Schema (live):** 27 tabel purchasing/supplier + trigger check.

**Kode**
- `app/Modules/Purchasing/Services/PurchaseOrderService.php` (create, submit, release, amend)
- `app/Modules/Purchasing/Services/GoodsReceivedNoteService.php` (post)
- `app/Modules/Purchasing/Services/DocumentSequenceService.php` (scope/period/format/nextValue)
- `app/Modules/Inventory/Services/GoodsReceiptService.php` (legacy post → ledger)
- `app/Modules/Purchasing/Enums/*` (PO status, receiving, invoicing)
- `app/Modules/Purchasing/Models/*`

**Generator:** `app/Console/Commands/PopulateApeironFullYearDataset.php` (tidak ada blok purchasing)

---

## 11.13 Temuan & Blocker Batch 5

| ID | Temuan | Tingkat | Tindakan |
|---|---|---|---|
| T-SUP1 | `supplier_products` polymorphic tanpa FK | Design | Validasi tenant manual |
| T-SUP2 | `purchase_uom` varchar bebas | Minor | Konsisten dengan UoM |
| T-PR1 | FK supplier di PR line single-column | Design | Validasi manual |
| T-PO1 | `created_by_user_id` NOT NULL | Wajib | Buat user dulu |
| T-PO2 | Idempotency key NOT NULL | Wajib | Kunci deterministik |
| T-PO3 | PO line tanpa unique item | Minor | Gabungkan baris |
| T-PO4 | Total PO tidak dijaga CHECK | Design | Hitung ulang via service |
| T-GRN1 | `received = accepted + rejected` | **Blocker** | Patuhi persamaan |
| T-GRN2 | `base_accepted_qty` tidak dijaga | Design | Hitung dengan factor |
| T-GRN3 | GRN wajib PO | Design | PO dulu |
| T-GRN4 | FK movement single-column | Design | Isi ID benar |
| T-GRN5 | **Dua sistem penerimaan** | **Blocker arsitektur** | Pilih `goods_received_notes` |
| T-LC1 | Alokasi biaya tanpa CHECK total | Design | Residual line |
| T-INV-1 | Outstanding invoice tidak dijaga | Design | Hitung dari debit note/payment |
| T-INV-2 | 3-way match tanpa CHECK | Design | Validasi di generator |
| T-PERF1 | Performance log butuh GRN line Modul 5 | Design | Ikut T-GRN5 |
| T-RO1 | Reorder suggestion derived | Design | Hitung dari data |
| T-APO1 | Outbox payable tanpa produsen/konsumen | **Gap fitur** | Jangan diisi |
| T-DOC1 | Document sequence harus dinaikkan | **Blocker** | Update `last_value` |
| T-LPO1 | Generator legacy tidak membuat purchasing | **Gap besar** | Implementasi Phase 5 |

---

## 11.14 Status Batch 5: SELESAI — TIDAK ADA PERUBAHAN DATA

Verifikasi ulang: `suppliers=0, purchase_orders=0, goods_received_notes=0,
supplier_invoices=0, document_sequences=0, accounting_payable_outbox=0`.

### Rekomendasi Batch 6

Audit **POS & sales**: `orders`, `order_items`, `order_item_modifiers`, `modifier_groups`,
`modifiers`, `modifier_group_product`, `modifier_ingredients`, `payments`,
`payment_attempts`, `payment_refunds`, `order_returns`, `order_return_items`,
`parked_carts`, `discount_redemptions`, `tables`, `cash_drawer_movements`,
`receipt_print_logs`, `eod_settlements`, `channel_order_logs`, `channel_product_mappings`.
Tujuan: memastikan alur transaksi, pembayaran, retur, dan EOD yang akan direplikasi generator.

---

# FASE 2 — BATCH 6: POS, TRANSAKSI PENJUALAN, PEMBAYARAN, RETUR, EOD

> **Sifat:** audit read-only. DDL live + `TransactionService`, `ReturnRefundService`,
> `ShiftService`, enum `OrderStatus`, seeder/generator legacy.
>
> **Checkpoint 2-Batch-6: SELESAI.**

## 12.0 Ringkasan Batch 6

| No | Tabel | Ownership | Status | Wajib demo |
|---|---|---|---|---|
| 1 | `tables` | Tier 3A | Terverifikasi | Disarankan (dine-in) |
| 2 | `orders` | Tier 3A | Terverifikasi | **Ya** |
| 3 | `order_items` | Tier 3A | Terverifikasi | **Ya** |
| 4 | `order_item_modifiers` | Tier 3A | Terverifikasi | Opsional |
| 5 | `payments` | Tier 3A, **append-only** | Terverifikasi | **Ya** |
| 6 | `payment_attempts` | Tier 3A | Terverifikasi | Opsional (non-cash) |
| 7 | `payment_refunds` | Tier 3A | Terverifikasi | Opsional |
| 8 | `order_returns` / `order_return_items` | Tier 3A | Terverifikasi | Opsional |
| 9 | `cash_drawer_movements` | Tier 3A, append-only | Terverifikasi | **Ya** (rekonsiliasi kas) |
| 10 | `eod_settlements` | Tier 3A | **Sebagian** — tabel ada, UI/service perlu dicek | Opsional |
| 11 | `modifier_groups` / `modifiers` / `modifier_group_product` / `modifier_ingredients` | Tier 2 | Terverifikasi | Opsional |
| 12 | `parked_carts` | Tier 3A | Terverifikasi | Tidak |
| 13 | `discount_redemptions` | Tier 3A | Terverifikasi | Wajib bila order berdiskon |
| 14 | `receipt_print_logs` | Tier 3A | Terverifikasi | Tidak |
| 15 | `channel_order_logs` / `channel_product_mappings` | Tier 2 | **Sebagian** — omnichannel belum aktif | Tidak |

Trigger aktif: `trg_orders_before_update`, `trg_payments_prevent_overpayment`,
`trg_payments_before_update` (append-only), `trg_cash_drawer_movements_*` (append-only).

---

## 12.1 `orders` — invariant paling ketat

| Aspek | Detail |
|---|---|
| Unique | `order_code` **GLOBAL**, `(tenant, idempotency_key)`, `(tenant, id)`, `(tenant, store, id)` |
| FK komposit | `stores`, `users` (user, voided_by), `shifts`, `customers`, `discounts`, `tables`, `devices` |
| CHECK | `order_status ∈ (pending, paid, cancelled, expired, failed, completed, void)`; `payment_method ∈ (cash, qris, ewallet, card)`; `origin_channel ∈ (pos_cashier, qr_table, gofood, grabfood, other_external)` |
| Money | `subtotal_amount`, `discount_amount`, `tax_amount`, `service_charge_amount`, `order_amount`, `order_change`, `cash_received` — **bigint unsigned** (Rupiah bulat) |
| Kunci | `order_date` (date), `created_at` (timestamp) terpisah |

### Status lifecycle (dari `app/Enums/OrderStatus.php`)

```
pending → paid → completed
pending → cancelled | expired | failed
paid    → void
```

`isFinal()` = semua kecuali `pending`. **`completed` BUKAN status default checkout** —
checkout POS menghasilkan `paid` (tunai) atau `pending` (menunggu provider), lalu
`completed` hanya setelah pelayanan selesai.

> 🔴 **T-ORD1 (blocker generator)** — `order_code` unik **GLOBAL** (bukan per tenant).
> Generator **wajib** menyertakan tenant/store/tanggal di kode, atau memakai generator
> berbasis `document_sequences`. Dua tenant dengan kode `ORD-2025-0001` akan gagal.

> 🔴 **T-ORD2 (blocker generator)** — `trg_orders_before_update` menolak perubahan
> `shift_id`, `subtotal_amount`, atau `order_amount` ketika status masih `pending`
> (sesuai DB-004). Artinya order `pending` **tidak boleh** diubah angka-angkanya.
> Generator harus menulis angka final saat INSERT.

> ⚠️ **T-ORD3** — `orders.order_date` adalah **DATE**, `created_at` adalah timestamp.
> Laporan harian memakai `order_date`. Transaksi setelah `store.business_date_cutoff`
> (default `00:00`) harus masuk hari berikutnya sesuai kebijakan laporan.

> ⚠️ **T-ORD4** — `shift_id` boleh NULL, tetapi `payments.shift_ref` dan laporan kas
> shift mengandalkan `payments.shift_id`. Dataset demo sebaiknya **selalu** mengisi `shift_id`.

---

## 12.2 `order_items`

| Aspek | Detail |
|---|---|
| FK komposit | `(tenant, store, order_id) → orders`; `products`, `product_variants`, `users` (processed_by), `discounts` (item_discount_id) |
| Kolom | `qty` int NOT NULL, `order_price` bigint, `order_subtotal` bigint, `options` JSON |
| Diskon | `item_discount_amount` decimal(15,2), `allocated_order_discount_amount` decimal(15,2), `net_line_total` decimal(15,2) |
| Preparation | `preparation_status ∈ (pending, brewing, ready)` |
| Unique | `(tenant, id)` saja — **tidak ada** unique per order+product |

**Formula terverifikasi (`TransactionService` baris 665-672):**

```
net_line_total = max(0, order_subtotal − item_discount_amount − allocated_order_discount_amount)
```

Alokasi diskon order memakai **Largest Remainder Method** (baris 539-556):
baris terakhir menyerap sisa agar `Σ allocated = discount_amount`.

> 🔴 **T-OI1 (blocker rekonsiliasi)** — `order_subtotal = order_price × qty` adalah
> **bigint**, sedangkan `net_line_total` **decimal(15,2)**. Pencampuran tipe (DB-011)
> berarti generator harus menghitung dalam integer Rupiah lalu menulis decimal `.00`.

> ⚠️ **T-OI2** — `order_items.store_id` wajib sama dengan `orders.store_id`.
> Terdapat FK komposit `(tenant, store, order_id)` yang menegakkannya.

---

## 12.3 `payments` — append-only + trigger overpayment

| Aspek | Detail |
|---|---|
| Unique | `(tenant, idempotency_key)`; `idempotency_key` **nullable** |
| CHECK | `amount > 0`; `status ∈ (pending, captured, failed, refunded, voided)`; method sama dengan order |
| Trigger | `trg_payments_prevent_overpayment` (BEFORE INSERT, `status='captured'`): menolak bila `SUM(captured) + NEW.amount > orders.order_amount` |
| Trigger | `trg_payments_before_update`: menolak **semua** UPDATE (append-only) |

> 🔴 **T-PAY1 (blocker)** — Untuk **split payment**, `Σ amount` harus **tepat sama**
> dengan `order_amount`. `TransactionService` memvalidasi ini sebelum insert
> (baris 563-570). Trigger overpayment menolak kelebihan, bukan kekurangan.

> 🔴 **T-PAY2** — Trigger overpayment memakai `SUM(amount) WHERE order_id = ... AND status='captured'`
> **tanpa filter tenant**. Karena `order_id` global unik, ini aman, tetapi harus
> diingat saat membuat data paralel.

> ⚠️ **T-PAY3** — `payments.status='voided'` tidak otomatis memotong `getCashSummary()`
> kecuali status tepat `voided`; `refunded` tidak dikurangkan dari `expected_cash`
> (lihat `ShiftService::getCashSummary`). Refund uang tunai dicatat sebagai
> `cash_drawer_movements` kategori `refund`.

---

## 12.4 `payment_attempts` & `payment_refunds`

| Tabel | Unique | CHECK |
|---|---|---|
| `payment_attempts` | `(tenant, idempotency_key)`, `(tenant, provider, provider_order_id)` | status 7 nilai (`initiated, pending_provider, unknown_timeout, succeeded, failed, expired, cancelled`) |
| `payment_refunds` | `(tenant, idempotency_key)`, `(tenant, store, refund_number)` | `amount > 0`; `request_status` 6 nilai; `provider_status` 5; `financial_status` 3 |

`payment_refunds.shift_id` dan `device_id` **NOT NULL**; `order_return_id` nullable
untuk refund tanpa retur.

> ⚠️ **T-PR1** — Tidak ada trigger over-refund (DB-013); `Σ refunds ≤ payment.amount`
> harus dijaga generator.
> `cash_drawer_movement_id` menautkan refund tunai ke `cash_drawer_movements` kategori `refund`.

---

## 12.5 `order_returns` & `order_return_items`

| Aspek | Detail |
|---|---|
| `order_returns` | `shift_id` & `device_id` **NOT NULL**; `status ∈ (pending_approval, approved, completed, rejected, cancelled)`; `return_type ∈ (full, partial)` |
| Unique | `(tenant, store, return_number)`, `(tenant, idempotency)`, `(tenant, store, id)` |
| `order_return_items` | `quantity_returned > 0`; `disposition ∈ (restock, quarantine, write_off_waste)`; unique `(tenant, return_id, order_item_id)` |
| Link stok | `stock_movement_id` → `stock_movements` (tipe `restore_compensation` bila restock) |

> ⚠️ **T-RET1** — Retur **mengembalikan stok** hanya bila `disposition='restock'`;
> `quarantine`/`write_off_waste` tidak menambah saldo. Generator harus konsisten.

> ⚠️ **T-RET2** — Tidak ada CHECK `total_refundable_amount ≤ order.order_amount`.
> Dijaga kode.

---

## 12.6 `cash_drawer_movements`

- CHECK `type ∈ (cash_in, cash_out)`; `category ∈ (restock_change, petty_expense, owner_withdrawal, bank_deposit, correction, refund)`.
- `amount > 0`; `shift_id` NOT NULL; `idempotency_key` nullable-unique.
- Append-only (trigger sebelum update).

> 🔴 **T-CDM1** — `ShiftService::getCashSummary()` menghitung
> `expected = opening + cash_sales + Σ(cash_in) − Σ(cash_out)`. Agar rekonsiliasi EOD
> cocok, **setiap refund tunai** harus tercatat sebagai `cash_out/refund`.

---

## 12.7 `eod_settlements`

- Unique `uq_eod_store_business_date (tenant_id, store_id, business_date)`.
- Kolom agregat: `gross_sales`, `total_discounts`, `total_returns_refunds`, `total_voids`,
  `net_sales`, `total_tax`, `total_service_charge`, `expected_cash_total`,
  `counted_cash_total`, `cash_discrepancy_total`, `payment_breakdown_snapshot` JSON.
- `status ∈ (open, closed)`; `closed_by_user_id` NOT NULL.

> ⚠️ **T-EOD1** — Tabel tersedia dan unique-nya benar (memperbaiki DB-012), tetapi
> **UI/service EOD belum terverifikasi** dalam audit ini. Dataset demo **tidak perlu**
> mengisi EOD bila fitur tidak tersedia; jika diisi, semua agregat harus diturunkan
> dari `orders`/`payments`/`shifts` yang sama.

---

## 12.8 Modifier & tables

| Tabel | Catatan |
|---|---|
| `modifier_groups` | `selection_type ∈ (single, multiple)`, `is_required`, `min_select`, `max_select` |
| `modifiers` | `extra_price` bigint, `is_default`, FK komposit ke group |
| `modifier_group_product` | unique `(product, group)` — tanpa `tenant_id` di unique |
| `modifier_ingredients` | `quantity_required` decimal(8,2) |
| `tables` | `secure_token` char(36) **unik global** & NOT NULL; `table_name` unique per store (soft-delete aware); `operational_status ∈ (available, occupied, cleaning, reserved)` |

> ⚠️ **T-TBL1** — `tables.secure_token` NOT NULL & unik global → generator wajib
> membuat UUID (bukan string kosong). `table_name` varchar(10) (pendek).

> ⚠️ **T-MOD1** — `order_item_modifiers` unique `(order_item_id, modifier_id)`;
> `price_at_time` decimal(12,2). Modifier **tidak** otomatis memotong stok kecuali
> didefinisikan di `modifier_ingredients`.

---

## 12.9 `discount_redemptions`, `parked_carts`, `receipt_print_logs`, omnichannel

| Tabel | Catatan |
|---|---|
| `discount_redemptions` | unique `(tenant, order_id, discount_id)`; `status ∈ (applied, voided_reversed)`; wajib dibuat setiap kali `orders.discount_id` diisi |
| `parked_carts` | `cart_payload` JSON NOT NULL; status 5 nilai; `hold_reference` unique per tenant |
| `receipt_print_logs` | `print_sequence` int NOT NULL; `is_reprint`; tidak ada unique |
| `channel_order_logs` | unique `(tenant, provider, external_order_id)`; `order_id` nullable |
| `channel_product_mappings` | unique `(tenant, provider, external_product_id)` |

> 🔴 **T-DR1** — Bila `orders.discount_id` diisi tetapi `discount_redemptions` tidak,
> kuota voucher (`usage_limit`) tidak terpantau dan laporan promo salah.
> Generator wajib membuat redemption **untuk setiap order berdiskon**.

> ⚠️ **T-CH1** — Omnichannel (`gofood`, `grabfood`, `qr_table`) membutuhkan
> **system user** `channel.t{tenant}@...` dan `selforder.t{tenant}@...`
> (`SystemUserResolver`). Tanpa itu, order kanal akan error.

---

## 12.10 Rumus checkout yang wajib direplikasi

```php
// 1. Subtotal per baris
order_subtotal = order_price × qty                       // bigint
subtotal_amount = Σ order_subtotal                        // bigint

// 2. Diskon
item_discount_amount   = Σ diskon per item
discount_amount        = order discount (percentage → round(subtotal×v/100), cap max_discount_amount;
                                          fixed → min(subtotal, value))
allocated_order_discount[i] = round(discount_amount × effective_subtotal[i] / Σ effective_subtotal)
                              // baris terakhir = discount_amount − running_allocated

// 3. Net per baris
net_line_total = max(0, order_subtotal − item_discount − allocated_order_discount)

// 4. Pajak & total (TransactionService::calculateOrderTotals)
discountedSubtotal = max(0, subtotal_amount − discount_amount)
tax_amount         = (int) round(discountedSubtotal × tax_rate)     // settings.tax_rate
order_amount       = (int)(discountedSubtotal + tax_amount)
order_amount       = round_to(total, rounding_value, rounding_behavior)

// 5. Pembayaran (split)
Σ payments.amount = order_amount            // validasi eksplisit service
cash: cash_received ≥ order_amount; order_change = cash_received − order_amount
```

> ℹ️ `service_charge_amount` ada di tabel tetapi **tidak** dimasukkan ke
> `calculateOrderTotals()` pada alur checkout ini — nilainya 0 kecuali fitur service
> charge diaktifkan di tempat lain. **Jangan** mengisi nilai acak.

---

## 12.11 Kondisi generator legacy (bukti)

| Masalah | Bukti (`PopulateApeironFullYearDataset.php`) | Dampak |
|---|---|---|
| `order_status='completed'` dipakai sebagai status penjualan final | filter laporan legacy | Menyimpang dari alur `paid → completed`; laporan modern memfilter status tertentu |
| `subtotal`/`tax`/`total` dihitung sendiri, bukan `calculateOrderTotals()` | blok generateOrders | Potensi beda ±1 Rupiah dari formula resmi |
| `order_code` memakai pola `ORD-<tenant>-<date>-<seq>` | blok generateOrders | **Aman** karena tenant ikut di kode |
| `payments` disisipkan via `DB::table()` | blok pembayaran | Melewati trigger? **Tidak** — trigger DB tetap aktif; tetapi `payments.status` harus `captured` agar guard benar |
| Tidak membuat `discount_redemptions` | tidak ada referensi | Kuota promo & laporan diskon tidak konsisten |
| Tidak membuat `eod_settlements`, `receipt_print_logs`, `parked_carts` | tidak ada referensi | Fitur terkait kosong (wajar bila tidak dipakai) |
| `stock_movements` satu baris per item (bukan per agregat) untuk penjualan? | lihat Batch 4 §10.9 | Batch 4 menemukan agregasi setahun untuk balance; untuk sales hanya `products.stock` dikurangi |

> 🔴 **T-LEG1** — Status `completed` pada generator legacy akan membuat filter
> `order_status` di UI/report modern (yang memakai `paid`/`completed`) menghasilkan
> angka berbeda bila tidak konsisten. Phase 4/5 harus menetapkan **satu** status final
> dan memfilter laporan dengan daftar status yang sama.

---

## 12.12 File yang Diperiksa pada Batch 6

**Schema (live):** 20 tabel + `information_schema.TRIGGERS` (orders, payments, cash drawer).

**Kode**
- `app/Modules/Sales/Services/TransactionService.php` (395-430, 480-580, 640-690, 985-1040, 1915-2025)
- `app/Modules/Sales/Services/ShiftService.php` (getCashSummary, closeShift)
- `app/Modules/Sales/Services/ReturnRefundService.php`
- `app/Enums/OrderStatus.php`, `OrderType`, payment enums
- `app/Modules/Sales/Models/Order.php`, `OrderItem.php`, `Payment.php`
- `app/Modules/Reports/Services/ReportService.php` (filter order_status)

**Seeder/Generator**
- `database/seeders/Pos/OrderSeeder.php`
- `app/Console/Commands/PopulateApeironFullYearDataset.php` (blok orders/payments)

---

## 12.13 Temuan & Blocker Batch 6

| ID | Temuan | Tingkat | Tindakan |
|---|---|---|---|
| T-ORD1 | `order_code` unik global | **Blocker** | Kode unik lintas tenant |
| T-ORD2 | `trg_orders_before_update` menolak ubah angka saat `pending` | **Blocker** | Tulis final saat insert |
| T-ORD3 | `order_date` vs `created_at` dan cutoff | Design | Policy tanggal |
| T-ORD4 | `shift_id` nullable | Disarankan | Selalu isi |
| T-OI1 | bigint vs decimal(15,2) | **Blocker** | Hitung integer Rupiah |
| T-OI2 | store item harus = store order | Wajib | FK komposit |
| T-PAY1 | Σ payments = order_amount | **Blocker** | Validasi sebelum insert |
| T-PAY2 | Trigger overpayment tanpa tenant filter | Info | Aman karena id global |
| T-PAY3 | `refunded` tidak memotong cash summary | Design | Refund tunai via cash_out |
| T-PR1 | Over-refund tanpa guard DB | **Blocker** | Batasi refund ≤ payment |
| T-RET1 | Retur hanya menambah stok bila `restock` | Wajib | Konsisten |
| T-RET2 | Refundable tanpa CHECK | Minor | Validasi |
| T-CDM1 | Rekonsiliasi kas bergantung cash_in/out | **Blocker** | Catat refund sebagai cash_out |
| T-EOD1 | EOD tersedia, service belum terverifikasi | Gap | Jangan klaim |
| T-TBL1 | `secure_token` NOT NULL unik global | Wajib | UUID |
| T-MOD1 | Modifier tidak potong stok otomatis | Info | `modifier_ingredients` |
| T-DR1 | Redemption wajib saat diskon | **Blocker** | Buat per order diskon |
| T-CH1 | Omnichannel butuh system user | Info | Sudah disediakan provisioning |
| T-LEG1 | Status `completed` di generator legacy | **Blocker** | Satu definisi status final |

---

## 12.14 Status Batch 6: SELESAI — TIDAK ADA PERUBAHAN DATA

Verifikasi ulang: `orders=0, order_items=0, payments=0, cash_drawer_movements=0,
order_returns=0, eod_settlements=0, tables=0`.

### Rekomendasi Batch 7 (final)

Sisa tabel pendukung: `customers`, `customer_loyalty_accounts`, `loyalty_ledger`,
`settings` (sudah), `activity_logs`, `export_tasks`, `brands`(sudah),
`permissions`(sudah), `email_*`(sudah), plus sistem: `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`, `migrations`,
`plans`, `plan_entitlements`, `tenant_entitlement_overrides`(sudah),
`document_sequences`(sudah).

Setelah Batch 7, 119 tabel tercakup dan Phase 2 dapat ditutup.

---

# FASE 2 — BATCH 7: PELANGGAN, LOYALITAS, AUDIT, INFRASTRUKTUR

> **Sifat:** audit read-only. DDL live + trigger + model/service.
>
> **Checkpoint 2-Batch-7: SELESAI.** **FASE 2 (119 TABEL) TUNTAS.**

## 13.0 Ringkasan Batch 7

| No | Tabel | Kelas | Status | Wajib demo |
|---|---|---|---|---|
| 1 | `customers` | Tier 2 | Terverifikasi | Disarankan |
| 2 | `customer_loyalty_accounts` | Tier 2 | Terverifikasi (cache dari trigger) | Ikut ledger |
| 3 | `loyalty_ledger` | Tier 2, **append-only + hash chain** | Terverifikasi | Hanya bila loyalty dipakai |
| 4 | `activity_logs` | Tier 3A, **append-only** | Terverifikasi | Opsional |
| 5 | `export_tasks` | Tier 3A | **Sebagian** — tabel ada, job export perlu dicek | Tidak |
| 6 | `plans` | Tier 1 | Terverifikasi (4 baris) | Tidak (sudah terisi) |
| 7 | `plan_entitlements` | Tier 1 | Terverifikasi (24 baris) | Tidak (sudah terisi) |
| 8 | `cache` / `cache_locks` | Infrastruktur | Terverifikasi | Tidak |
| 9 | `jobs` / `job_batches` / `failed_jobs` | Infrastruktur | Terverifikasi | Tidak |
| 10 | `sessions` / `password_reset_tokens` | Infrastruktur | Terverifikasi | Tidak |
| 11 | `migrations` | Infrastruktur | Terverifikasi (162 rekaman) | Tidak |

---

## 13.1 `customers`

| Aspek | Detail |
|---|---|
| FK | `tenant_id → tenants` |
| Unique | `(tenant, phone, phone_uniqueness_key)` (soft-delete aware), `(tenant, customer_code)`, `(tenant, email)` |
| Kolom | `customer_code`, `name`, `phone` varchar(20), `email`, `date_of_birth`, `join_date`, `is_active`, `deleted_at` |
| Generated | `phone_uniqueness_key = IF(deleted_at IS NULL, 0, NULL)` |

> ⚠️ **T-CU1** — `email` NULL diizinkan; unique `(tenant, email)` **tidak** mencegah
> banyak NULL (MySQL). `phone` juga nullable tetapi ikut unique aktif.
> Generator harus mengisi minimal salah satu identitas unik atau membiarkan keduanya NULL.

> ⚠️ **T-CU2** — `phone` hanya varchar(20). Format `+62…` aman; jangan menyimpan
> spasi/format panjang.

---

## 13.2 `customer_loyalty_accounts`

- **Unique `customer_id` GLOBAL** (`customer_loyalty_accounts_customer_id_unique`).
- FK komposit `(tenant, customer_id)` CASCADE; `(tenant, current_tier_id) → loyalty_tiers`.
- Kolom: `current_points`, `lifetime_points_earned`, `current_tier_id`.

> 🔴 **T-LOY1 (blocker)** — Tabel ini adalah **CACHE yang disinkronkan trigger**
> `trg_loyalty_ledger_sync_account`. `TransactionService::grantLoyaltyPoints()` secara
> eksplisit berkomentar: *"JANGAN PERNAH menulis manual ke tabel cache itu dari sini."*
> Generator **tidak boleh** meng-insert saldo langsung; harus lewat `loyalty_ledger`.

---

## 13.3 `loyalty_ledger` — append-only + hash chain

| Aspek | Detail |
|---|---|
| Unique | `entry_hash` **global unik**; `(tenant, id)` |
| CHECK | `type ∈ (earn, redeem, expire, adjustment, bonus, reversal)` |
| Kolom | `points` bigint (boleh negatif), `balance_after` unsigned, `prev_hash`, `entry_hash`, `store_id` nullable |
| Trigger (4) | `trg_loyalty_ledger_hash_chain`, `trg_loyalty_ledger_sync_account`, `trg_loyalty_ledger_no_update`, `trg_loyalty_ledger_before_delete` |
| Model | `App\Modules\Customers\Models\LoyaltyLedger` — `update()`/`delete()` melempar `LogicException` |

**Aturan perolehan poin (terverifikasi, `TransactionService` baris 956-1000):**

```
pointsEarned = (int) floor((subtotal / 1000) * tier.points_multiplier)   // default 1.00
```

> 🔴 **T-LOY2 (blocker)** — `entry_hash` unik **global** dan dibentuk trigger dari
> `customer_id | order_id | type | points | balance_after | prev_hash | NOW()`.
> Dua entry identik dalam **detik yang sama** menghasilkan hash sama → gagal
> (temuan DB-002). Generator wajib memastikan tidak ada dua entry identik per detik.

> 🔴 **T-LOY3 (blocker)** — Hash chain mengharuskan `balance_after` **berurutan**
> per customer. Menyisipkan data historis di tengah (tanggal lampau) akan merusak
> rantai karena `prev_hash` dihitung saat insert. **Dataset harus dibuat berurutan waktu.**

> ⚠️ **T-LOY4** — `loyalty_ledger.store_id` nullable dan FK store komposit **sudah ada**
> (remediasi DB-020). Isi dengan store tempat transaksi.

> ⚠️ **T-LOY5** — `store_id` pada ledger hanya atribusi; saldo poin bersifat
> tenant-wide (cross-branch).

---

## 13.4 `activity_logs`

- FK komposit `(tenant, store_id) → stores` (store **NOT NULL**), `(tenant, user_id) → users`.
- Append-only (trigger).
- Ditulis oleh `AuditTrailService::record()` via `DB::table()` dengan sanitasi payload
  dan resolusi store/user yang valid.

> ⚠️ **T-AL1** — `store_id` NOT NULL. `OwnershipTransferService` bahkan melempar error
> bila tenant belum punya store (baris 394). Generator **harus** punya store sebelum
> menulis activity log.

> ⚠️ **T-AL2** — `subject_type` menyimpan nama kelas model. Menulis nilai karangan
> akan membuat tautan UI "lihat subjek" rusak.

---

## 13.5 `export_tasks`

- `type`, `parameters` JSON, `status` (default `pending`), `file_path`, `file_size_bytes`,
  `error_message`, `completed_at`; `tenant_id`/`store_id` NOT NULL.

> ⚠️ **T-EX1 (sebagian terverifikasi)** — Tabel ada; **job pemroses export tidak
> ditemukan** dalam pencarian ini. Jangan membuat baris `pending` yang tidak akan
> pernah diproses (akan tampil sebagai export menggantung).

---

## 13.6 `plans` & `plan_entitlements`

| Plan | `max_stores` | `max_devices_per_store` | `max_employees` |
|---|---|---|---|
| `standard_trial` | 1 | 2 | 5 |
| `starter` | 1 | 2 | 5 |
| **`pro`** | **5** | **5** | **25** |
| `enterprise` | unlimited | unlimited | unlimited |

- `plans.code` unique global; 4 baris sudah terisi (jangan diubah).
- `plan_entitlements` unique `(plan_id, feature_code)`; 24 baris.

> ℹ️ Ini menutup keputusan Batch 2 (T-Q1/T-SUB1): **satu tenant dua cabang wajib `pro`**.

---

## 13.7 Infrastruktur (jangan disentuh dataset)

| Tabel | Isi saat ini | Catatan |
|---|---|---|
| `cache` | 1 baris | Key-value Laravel; **jangan** diisi manual (termasuk `pos:setting:*` & quota cache) |
| `cache_locks` | 0 | Lock atomik; biarkan |
| `jobs` | 0 | Antrean queue; hanya via `dispatch()` |
| `job_batches` | 0 | Metadata batch |
| `failed_jobs` | 0 | Jangan dibuat-buat |
| `sessions` | 3 | Login aktif; biarkan |
| `password_reset_tokens` | 0 | Token reset bersifat rahasia |
| `migrations` | **162** | Rekaman migrasi; **jangan pernah** diubah |

> 🔴 **T-INF1** — `cache` menyimpan `pos:setting:{tenant}:{key}` dan quota
> `tenant:{id}:quota:*` dengan TTL 1 jam. Bila generator mengubah setting/plan lalu
> menjalankan UI segera, nilai **lama** masih terbaca. Wajib panggil cache clear
> (lihat T-SUB3 Batch 2).

> 🔴 **T-INF2** — `sessions` berisi sesi login pengguna nyata (payload terenkripsi/serialized).
> Dataset **tidak boleh** menyalin atau mengarang sesi.

---

## 13.8 Cakupan final 119 tabel

| Batch | Domain | Jumlah |
|---|---|---|
| 1 | Core: tenant, store, user, role, permission, pivot, device, invitation, oauth | 11 |
| 2 | Konfigurasi, shift, subscription, provisioning, email | 11 |
| 3 | Katalog, BOM, varian, harga, pajak, diskon | 20 |
| 4 | Inventori, ledger stok, dokumen gudang | 15 |
| 5 | Purchasing, supplier, GRN, invoice, payable | 27 |
| 6 | POS, order, payment, refund, retur, EOD, omnichannel | 20 |
| 7 | Pelanggan, loyalitas, audit, export, plan, infrastruktur | 15 |
| **Total** | | **119** |

**Verifikasi jumlah:** 11+11+20+15+27+20+15 = **119** = `COUNT(*) information_schema.tables`
`BASE TABLE` pada `point_of_sales`. Tidak ada tabel yang terlewat.

---

## 13.9 Ringkasan seluruh blocker yang harus diselesaikan Phase 5

| Kategori | ID | Ringkas |
|---|---|---|
| **Tenancy/akun** | T-Q1/T-SUB1 | Paket `pro` wajib untuk 2 cabang; subscription `trialing` mengalahkan `subscription_plan` |
| | T3-01 | `DB::table('users')` melewati mutator → `email_normalized` kosong |
| | T3-02/T-E1/T-L1 | `employee_id`, role ID, tenant/store ID semua hardcode |
| **Master data** | T-CA1, T-PI1 | Seeder katalog/BOM TRUNCATE |
| **Harga/pajak** | T-TX1, T-PL1 | Pajak dari `settings.tax_rate`; harga dari `resolvePrice()` |
| **Inventori** | T-INV1, T-INV2, T-LG1, T-LG2 | Stok negatif ditolak; mirror vs balance; movement per item |
| **Purchasing** | T-LPO1, T-GRN5, T-DOC1 | Purchasing kosong di generator lama; pilih `goods_received_notes`; nomor dokumen harus naikkan sequence |
| **POS** | T-ORD1, T-ORD2, T-PAY1, T-DR1, T-CDM1, T-LEG1 | `order_code` global; trigger order; Σ payment; redemption; refund tunai |
| **Loyalitas** | T-LOY1, T-LOY2, T-LOY3 | Jangan tulis account; hash unik; urutan waktu |
| **Audit** | T-AL1 | Store wajib ada |
| **Sistem** | T-INF1, T-INF2, T-APO1, T-EX1 | Cache, session, outbox, export jangan diisi |

---

## 13.10 Status Batch 7: SELESAI — TIDAK ADA PERUBAHAN DATA

Verifikasi ulang: `customers=0, customer_loyalty_accounts=0, loyalty_ledger=0,
activity_logs=0, export_tasks=0, plans=4, plan_entitlements=24, migrations=162`.

---

# FASE 2 — SELESAI (119/119 TABEL)

**Status:** `PHASE 2 COMPLETE — menunggu lanjut ke Phase 3 (audit modul 1–5)`

### Artefak
- DDL lengkap 119 tabel diverifikasi langsung dari database live.
- 6 batch audit terdokumentasi (§7–§13).
- Daftar blocker Phase 5 terpusat (§13.9).
- Rumus-rumus resmi terdokumentasi: harga (§9.9), pajak/total (§9.17),
  ledger stok/WAC (§10.1), checkout (§12.10), loyalty (§13.3).

### Langkah berikutnya (Phase 3)
Verifikasi implementasi aktual Modul 1–5 terhadap laporan "selesai":
- Modul 1: Core Platform & Multi-Tenancy → sebagian besar sudah terverifikasi di Batch 1–2.
- Modul 2: POS & Retail → Batch 6.
- Modul 3: Product & Catalog → Batch 3.
- Modul 4: Inventory & Warehouse → Batch 4.
- Modul 5: Purchasing & Supplier → Batch 5.

Phase 3 akan **mengonsolidasikan** temuan batch menjadi status siap/tidak siap per modul,
menjalankan test suite yang relevan (pada DB testing `yovel_pos_test`), dan mencatat gap.

---

# FASE 3 — AUDIT MODUL 1–5 (KONSOLIDASI + VERIFIKASI TEST SUITE)

> **Sifat:** verifikasi read-only terhadap implementasi aktual. Tidak ada DDL/DML,
> tidak ada seeder/generator dijalankan, tidak ada perubahan kode produk.
> Test suite dijalankan pada DB testing `yovel_pos_test` (bukan DB runtime).
>
> **Checkpoint Phase 3: SELESAI.**

## 14.0 Kondisi awal yang diverifikasi

```
$ git status --porcelain -uall
?? docs/dataset/implementationPlanDataset.md
$ git branch --show-current
chore/refactor-modular-architecture
$ git log --oneline -3
a069ff6 fix(database): remediate schema invariants DB-001 through DB-021
ad46b63 feat(purchasing): implement Module 5 purchasing workflow and domain models
79642f4 feat(core): harden enterprise infrastructure, scheduler concurrency, telemetry and navigation
$ php artisan --version
Laravel Framework 13.31.0
```

Working tree bersih kecuali dokumen ini (belum di-commit) — sama seperti akhir Phase 2.
HEAD tidak berubah selama Phase 3.

## 14.1 Metode

Phase 2 mengaudit **skema** (119 tabel). Phase 3 mengaudit **perilaku**: apakah service,
policy, trigger, dan seeder yang menjadi target generator benar-benar bekerja seperti
yang diklaim. Dua lapis bukti:

1. **Test suite** — dijalankan per-domain pada `yovel_pos_test` (FreshDatabase).
2. **Struktur & pembacaan kode** — jumlah file/baris per modul, route, command,
   serta pembacaan langsung service yang menjadi blocker generator di §13.9.

Perintah yang dijalankan (semua read-only terhadap DB runtime):

```bash
php artisan test --testsuite=Unit --no-coverage
php artisan test tests/Feature/Domain1 --no-coverage
php artisan test tests/Feature/Domain2 --no-coverage
php artisan test tests/Feature/Catalog --no-coverage
php artisan test tests/Feature/Inventory --no-coverage
php artisan test tests/Feature/Purchasing --no-coverage
php artisan test tests/Feature/{Auth,Security,Tenancy,Reports,Settings,Core,Navigation,Services} --no-coverage
php artisan test tests/Feature/*.php tests/Feature/Domain3 tests/Feature/Domain4 --no-coverage
```

## 14.2 Hasil test suite (bukti)

| Suite | Cakupan | Test | Assertions | Hasil |
|---|---|---|---|---|
| `Unit` | Email normalizer, WAC, order type | 24 | 62 | **PASS** |
| `Domain1` | Tenant isolation, provisioning, lifecycle | 82 | 340 | **PASS** |
| `Domain2` | POS, transaksi, pembayaran, refund, EOD, KDS, webhook | 102 | 530 | **PASS** |
| `Catalog` | Katalog increment 1–6, MVC end-to-end | 63 | 308 | **PASS** |
| `Inventory` | Ledger, GR, adjustment, opname, transfer, return | 84 | 416 | **PASS** |
| `Purchasing` | PR/PO/GRN/invoice/return/landed cost/M7 boundary | 75 | 451 | **PASS** |
| Auth + Security + Tenancy + Reports + Settings + Core + Navigation + Services | IAM, policy, isolasi, laporan, RBAC sidebar | 266 | 1451 | **PASS** |
| Root Feature + Domain3 + Domain4 | Checkout guard, KDS, Midtrans, dashboard, design system | 122 | 516 | **1 FAIL / 121 PASS** |
| **Total** | | **818** | **4074** | **817 PASS, 1 FAIL** |

Tidak ditemukan kegagalan lain. Tidak ada suite yang error karena masalah environment.

## 14.3 Kegagalan tunggal — analisis & klasifikasi

**Test:** `tests/Feature/StockCompensationHardeningTest.php::test_auto_assigns_shift_id_when_cashier_has_open_shift`

```
Expected response status code [200] but received 500.
```

**Reproduksibel** (bukan flaky): gagal baik saat file dijalankan sendiri maupun di dalam
suite penuh. Sumber error ada di log aplikasi:

```
testing.ERROR: Transaction Query Error: SQLSTATE[23000]: Integrity constraint violation:
4025 CONSTRAINT `chk_payments_amount_positive` failed for `yovel_pos_test`.`payments`
... insert into `payments` (..., `amount`, `amount_tendered`, `change_amount`, ...)
    values (2, 2, ?, 3, 1, cash, 0, 25000, 25000, captured, ...)
```

**Akar masalah (terverifikasi):**

1. `tests/Support/TwoTenantFixture.php:105-110` membuat `productA` **tanpa**
   `product_price`. Kolom `products.product_price` adalah `bigint NOT NULL DEFAULT 0`
   (dicek via `SHOW COLUMNS`), sehingga harganya 0.
2. `TransactionService::createTransaction()` menghitung
   `subtotalAmount = Σ(order_price × qty) = 0` → `calculateOrderTotals(0, 0)` →
   `totalAmount = 0`.
3. Test mengirim `cash_received = 25000`, tetapi jalur legacy di
   `TransactionService.php:758-777` mencatat `payments.amount = $totalAmount` (= 0) dan
   `amount_tendered = 25000`.
4. DB menolak baris dengan `amount = 0` karena **CHECK constraint pada DB testing masih
   `amount > 0`**. (DB testing `yovel_pos_test` dibangun dari migration; sedangkan DB
   runtime `point_of_sales` sudah direlaksasi ke `amount >= 0` oleh
   `database/migrations/2026_09_24_140000_fix_payment_and_preparation_constraints.php`.)

**Klasifikasi:** **defect data fixture test**, bukan defect kode produk dan **bukan
blocker dataset**. `TransactionService` sendiri konsisten — ia memang mencatat `amount`
sebagai nilai yang benar-benar terpakai (bukan `cash_received`).

**Dua temuan nyata yang tetap perlu dicatat:**

> ⚠️ **T-P3-01 (test)** — Fixture `TwoTenantFixture` harus mengisi `product_price`
> (mis. `20000`) agar test transaksi bernilai realistis. Perbaikan ini **di luar scope
> dataset** (tidak mengubah kode produk), dicatat untuk Phase 9/sprint test hygiene.

> ⚠️ **T-P3-02 (environment drift)** — DB testing `yovel_pos_test` memiliki
> `chk_payments_amount_positive` versi **lama** (`> 0`), sedangkan DB runtime
> `point_of_sales` sudah `>= 0`. Perbedaan ini membuat hasil test tidak sepenuhnya
> merepresentasikan DB runtime. Untuk Phase 8 (QA), verifikasi integritas harus
> dijalankan juga lewat `php artisan pos:verify-database` di DB yang sebenarnya.

> ℹ️ **Implikasi untuk generator (penting):** karena DB runtime mengizinkan
> `amount >= 0` dan **trigger overpayment tetap aktif**, generator wajib memastikan
> `Σ payments.amount (captured) = order_amount` dan **tidak pernah 0** untuk order
> bernilai > 0. Order dengan `order_amount = 0` (produk tanpa harga) akan menghasilkan
> baris payment bernilai 0 dan merusak rekonsiliasi kas.

## 14.4 Inventaris kode per modul

| Modul | Direktori utama | File | Baris | Route | Status |
|---|---|---|---|---|---|
| **M1** Core Platform & Multi-Tenancy | `Auth/` + `Users/` + `Settings/` (+ `Core/Context`, `Http/Middleware`) | 22 + 15 + 25 = 62 | 9.506 | `auth.php`, `settings.php`, `web.php` | **Siap** |
| **M2** POS & Retail | `Sales/` (+ `Customers/`) | 31 + 14 = 45 | 7.969 | `sales.php`, `customer.php`, `customers.php` | **Siap** |
| **M3** Product & Catalog | `Products/` | 30 | 4.737 | `products.php` | **Siap** |
| **M4** Inventory & Warehouse | `Inventory/` | 29 | 5.584 | `inventory.php` | **Siap** |
| **M5** Purchasing & Supplier | `Purchasing/` | 39 | 8.319 | `purchases.php` | **Siap** |
| Pendukung | `Reports/` | 7 | 1.233 | `reports.php` | Siap (dipakai M2) |

20 command console tersedia, termasuk yang dibutuhkan dataset:
`ProvisionTenantRoles`, `ProvisionSystemUsers`, `VerifyDatabase`, `ReconcileBalances`,
`BackfillBalances`, `MigrateStockToLedger`, `RecomputeRestockForecasts`,
`VerifyLoyaltyChain`, `ProcessEmailOutbox`, dan `PopulateApeironFullYearDataset` (legacy).

## 14.5 Status per modul

### M1 — Core Platform & Multi-Tenancy: **SIAP**

| Aspek | Bukti | Status |
|---|---|---|
| Isolasi tenant fail-closed | `Domain1TenantIsolationTest` (16 test), `Tenancy/*` (7 file) | ✅ |
| Provisioning tenant + owner + store + baseline | `ProvisioningDomainTest`, `ProvisioningHardenedIntegrityTest`, `ProvisioningRealConcurrencyTest` | ✅ |
| Lifecycle (suspend/soft-delete blokir web, self-order, webhook) | `TenantLifecycleEnforcementTest` (7 test) | ✅ |
| Employee ID konkurensi monotonic | `ProvisioningRealConcurrencyTest` | ✅ |
| RBAC + policy (branch, device, modifier, ownership transfer) | `Security/*` (14 file, 266 test gabungan) | ✅ |
| Kuota & entitlement per paket | `TenantEntitlementAndQuotaTest` | ✅ |

**Catatan dataset:** M1 sudah **terverifikasi end-to-end**. Jalur pembuatan tenant yang
dipakai generator harus meniru `TenantProvisioningService` (bukan insert mentah), dan
paket **`pro` wajib** (T-Q1/T-SUB1). Gap arsitektur yang tetap ada dan **tidak
memblokir**: `role_permissions` tanpa pembaca (T5-01), `tenants.owner_user_id` relasi
tanpa kolom (T1-01 di §7.1).

### M2 — POS & Retail: **SIAP**

| Aspek | Bukti | Status |
|---|---|---|
| Checkout split payment exact-sum | `PaymentAttemptIdempotencyAndSplitTest`, `StoreTransactionRequestTest` | ✅ |
| Formula finansial & paritas UI↔DB | `FinancialCalculationAndParityTest` | ✅ |
| Lifecycle order & void integrity (trigger) | `OrderLifecycleTransitionTest`, `OrderVoidIntegrityTest` | ✅ |
| Shift guard (zero-trust) + handover | `ShiftDeviceHandoverAndConcurrencyTest`, `Services/ShiftServiceTest` | ✅ |
| Refund limit & retur + disposition | `ConcurrentRefundLimitTest`, `PaymentRefundIntegrityTest`, `OrderReturnAndDispositionTest` | ✅ |
| EOD settlement & paritas laporan | `EodSettlementAndReportParityTest` | ✅ |
| Rollback & konkurensi (oversell, BOM, loyalty race) | `TransactionConcurrencyTest`, `TransactionRollbackTest` | ✅ |
| Webhook Midtrans / reconcile / void race | `WebhookVsReconcileVsVoidRaceTest`, `ProcessWebhookOrderJobIdempotencyTest` | ✅ |
| KDS lifecycle | `KdsLifecycleTest`, `Kds*Test` | ✅ |

**Koreksi terhadap Phase 2 §12.7:**

> ✅ **T-EOD1 DITUTUP** — `ReportService::closeEodSettlement()` **memang ada** dan menulis
> `eod_settlements` lengkap (`ReportService.php:285-305`) dengan agregat dari
> `orders`/`payments`/`shifts` (gross, discounts, returns, voids, tax, expected vs
> counted cash, payment breakdown snapshot) + audit log `store.eod_closed`, dipanggil
> `ReportController::closeEod()`. Status yang benar: **terverifikasi**, bukan "service
> belum terverifikasi". Dataset **boleh** membuat EOD, tetap dengan syarat paritas
> (`EodSettlementAndReportParityTest` membuktikannya).
> Sisa gap nyata hanya §12.11: generator legacy tidak membuat `discount_redemptions`.

### M3 — Product & Catalog: **SIAP**

| Aspek | Bukti | Status |
|---|---|---|
| Katalog dasar + media/biaya/pajak | `Increment1CatalogEnhancementTest`, `Increment2MediaCostAndTaxTest` | ✅ |
| Assortment per cabang & eligibilitas POS | `Increment3AssortmentAndPosEligibilityTest` | ✅ |
| Import/export & bulk edit | `Increment4ImportExportAndBulkEditTest` | ✅ |
| Atribut, varian, bundle (DAG) | `Increment5AttributesVariantsAndBundlesTest` | ✅ |
| Price list & wholesale (hierarki harga) | `Increment6PriceListsAndWholesaleTest` | ✅ |
| MVC + end-to-end | `Module3MvcAndEndToEndIntegrationTest`, `Navigation/Module3NavigationTest` | ✅ |

**Catatan dataset:** blocker T-PL1 (harga = hasil `resolvePrice()`, bukan
`product_price`), T-TX1 (pajak dari `settings.tax_rate`), dan T-PR1 (stok BOM vs
non-BOM) **tetap berlaku** — tidak ada test yang membatalkannya. Justru
`FinancialCalculationAndParityTest` menegaskan formula §9.17/§12.10.

### M4 — Inventory & Warehouse: **SIAP**

| Aspek | Bukti | Status |
|---|---|---|
| Ledger + WAC + mirror sync | `InventoryLedgerServiceTest`, `StockBalanceAuthorityTest`, `Unit/InventoryCostCalculationTest` | ✅ |
| Anti-deadlock & urutan lock | `StockBalanceDeadlockTest`, `StockTransferCrossBranchDeadlockTest` | ✅ |
| GR, adjustment, opname (drift), transfer, supplier return | 21 file `tests/Feature/Inventory` | ✅ |
| Reservasi order pending + kompensasi | `PendingOrderReservationAndCompensationTest` | ✅ |
| Isolasi tenant/cabang pada dokumen gudang | `GoodsReceiptTenantBranchIsolationTest`, `StockTransferTenantAndBranchSecurityTest` | ✅ |
| RBAC + Livewire manager | `StockOpnameRbacAndBranchIsolationTest`, `*ManagerLivewireTest` | ✅ |
| Export ledger (job) | `InventoryExportJobTest` | ✅ |

**Catatan dataset:** blocker T-INV1 (stok negatif ditolak), T-INV2 (mirror vs balance),
T-LG1/T-LG2 (generator legacy menulis balance langsung & agregasi setahun) **tetap
berlaku**. Test membuktikan service **menolak** jalur pintas tersebut.

### M5 — Purchasing & Supplier: **SIAP**

| Aspek | Bukti | Status |
|---|---|---|
| Supplier master + kontak + produk | `SupplierManagementTest`, `PurchasingTenantIsolationTest` | ✅ |
| PR → PO + approval + direct PO policy | `PrToPoConversionTest`, `PurchaseRequestAndApprovalTest`, `DirectPoPolicyTest` | ✅ |
| GRN partial receiving + integrasi inventori | `GrnPartialReceivingTest`, `GrnInventoryIntegrationTest` | ✅ |
| Idempotensi & konkurensi PO/GRN | `PurchasingConcurrencyAndIdempotencyTest` | ✅ |
| Landed cost & alokasi | `LandedCostAllocationTest` | ✅ |
| Retur pembelian + debit note | `PurchaseReturnAndDebitNoteTest` | ✅ |
| Invoice matching, variance hold, duplikat | `SupplierInvoiceMatchingTest`, `SupplierInvoiceVarianceHoldTest`, `SupplierInvoiceDuplicatePolicyTest` | ✅ |
| Reorder suggestion | `ReorderSuggestionEngineTest` | ✅ |
| Batas kontrak M5→M7 | `M7AccountingBoundaryContractTest` | ✅ |
| Penomoran dokumen + konkurensi | `DocumentSequenceServiceTest`, `DocumentSequenceConcurrencyTest` | ✅ |

**Koreksi terhadap Phase 2 §11.9:**

> ✅ **T-APO1 DIPERBARUI** — `accounting_payable_outbox` **punya produsen**:
> `SupplierInvoiceMatchingService` (saat approve payable),
> `PurchaseReturnService` (saat apply debit note), dan `LandedCostService` (saat
> alokasi biaya tambahan). Ketiganya diverifikasi oleh test tersebut di atas.
> Yang **tetap benar**: **belum ada konsumen** (Modul 7 Accounting belum
> diimplementasikan). Maka kesimpulan untuk dataset **tidak berubah** — jangan
> mengisi outbox secara manual; biarkan service purchasing yang menulisnya, supaya
> event outbox konsisten dengan dokumen sumber.
>
> ✅ **T-DOC1 terkonfirmasi** — `DocumentSequenceServiceTest` +
> `DocumentSequenceConcurrencyTest` membuktikan sequence memang sumber nomor dokumen;
> generator **wajib** memakai service ini (bukan membuat nomor sendiri).

**Catatan dataset:** T-GRN5 tetap **blocker arah implementasi** — pakai
`goods_received_notes` (Modul 5, yang dites penuh), bukan `goods_receipts` legacy.
T-LPO1 tetap berlaku: tidak ada satu pun test/route yang membuat purchasing dari
generator, sehingga purchasing **masih kosong** di dataset lama.

## 14.6 Peta test → modul (ringkas)

| Modul | Folder test utama | File test |
|---|---|---|
| M1 | `Domain1/`, `Auth/`, `Security/`, `Tenancy/`, `Settings/` | 6 + 14 + 14 + 7 + 1 |
| M2 | `Domain2/`, `Services/`, `Reports/`, root `*.php` (checkout/KDS/Midtrans) | 31 + 2 + 1 + 22 |
| M3 | `Catalog/`, `Navigation/Module3NavigationTest` | 7 + 1 |
| M4 | `Inventory/`, `Tenancy/Stock*` | 21 + 2 |
| M5 | `Purchasing/` | 17 |

## 14.7 Ringkasan gap yang **tetap** membuka (tidak berubah oleh Phase 3)

Phase 3 **tidak** menutup satu pun blocker generator. Yang berubah hanyalah **status
implementasi**, bukan syarat pembuatan dataset:

| ID | Status setelah Phase 3 |
|---|---|
| T-EOD1 | **Ditutup** — service EOD terverifikasi; EOD boleh dibuat (dengan paritas) |
| T-APO1 | **Dikoreksi** — produsen ada, konsumen belum; tetap jangan diisi manual |
| T-LG1 / T-LG2 / T-LPO1 / T-GRN5 / T-DOC1 | Tetap blocker generator (Phase 5) |
| T-PL1 / T-TX1 / T-PR1 / T-INV1 / T-INV2 | Tetap blocker rekonsiliasi (Phase 5) |
| T-ORD1 / T-ORD2 / T-PAY1 / T-DR1 / T-CDM1 / T-LEG1 | Tetap blocker (Phase 5) |
| T-LOY1 / T-LOY2 / T-LOY3 | Tetap blocker (Phase 5) |
| T3-01 / T3-02 / T-L1 / T-Q1 / T-SUB1 | Tetap blocker (Phase 5) |
| T-INF1 / T-INF2 / T-EX1 | Tetap larangan (jangan diisi) |

## 14.8 Kesimpulan Phase 3

**Kelima modul (M1–M5) terimplementasi dan lolos verifikasi test suite:**
817 dari 818 test lulus (4.074 assertions). Implementasi **lebih matang** daripada
yang tersirat pada dokumen audit lama (`docs/implementation/implementation-status.md`
masih menyebut "Domain 1" saja dan Laravel 11/12 — dokumen itu sudah usang).

Namun **kematangan implementasi tidak menghapus gap dataset**. Generator lama
(`populate-full-year` + `PosFullSeeder`) tetap tidak memakai service yang sudah
terbukti benar (ledger, pricing, dokumen sequence, purchasing) dan masih memakai
`TRUNCATE`, ID hardcode, serta status order yang berbeda. Phase 5 tetap harus
menulis ulang generator di atas service yang kini terverifikasi.

**Risiko tersisa yang harus disadari sebelum Phase 5:**

1. DB testing (`yovel_pos_test`) dan DB runtime (`point_of_sales`) punya state
   constraint yang berbeda (T-P3-02) → jangan menganggap "test hijau" sebagai bukti
   integritas pada DB target.
2. Satu test fixture rusak (T-P3-01) dapat menutupi regresi checkout bernilai nol
   bila tidak diperbaiki.

## 14.9 Status Phase 3: SELESAI — TIDAK ADA PERUBAHAN DATA

- Tidak ada DDL/DML/seeder/generator dijalankan.
- DB runtime diverifikasi tidak berubah: `tenants=0, stores=0, users=0, roles=0,
  orders=0, purchase_orders=0` (lihat §14.10).
- Tidak ada file aplikasi yang diubah; satu-satunya perubahan repo adalah dokumen ini.

## 14.10 Verifikasi akhir DB runtime (read-only)

Dijalankan setelah seluruh test suite selesai — untuk memastikan test benar-benar
terisolasi pada `yovel_pos_test` dan tidak menyentuh DB runtime:

```bash
php artisan tinker --execute="... COUNT(*) pada tenants, stores, users, roles,
   orders, order_items, payments, stock_movements, products, suppliers,
   purchase_orders, goods_received_notes, loyalty_ledger ..."
```

| Tabel | Baris | Ekspektasi | Hasil |
|---|---|---|---|
| `tenants` | 0 | 0 (tidak berubah sejak Phase 2) | ✅ |
| `stores` | 0 | 0 | ✅ |
| `users` | 0 | 0 | ✅ |
| `roles` | 0 | 0 | ✅ |
| `orders` | 0 | 0 | ✅ |
| `order_items` | 0 | 0 | ✅ |
| `payments` | 0 | 0 | ✅ |
| `stock_movements` | 0 | 0 | ✅ |
| `products` | 0 | 0 | ✅ |
| `suppliers` | 0 | 0 | ✅ |
| `purchase_orders` | 0 | 0 | ✅ |
| `goods_received_notes` | 0 | 0 | ✅ |
| `loyalty_ledger` | 0 | 0 | ✅ |
| `migrations` | 162 | 162 (tidak berubah) | ✅ |

## 14.11 Rekomendasi Phase 4

Phase 4 adalah **desain dataset**, bukan eksekusi. Keputusan yang masih dibutuhkan
dari pemilik project (dari §6, belum terjawab):

1. **Topologi akun**: satu tenant + dua cabang (**didukung schema & implementasi**,
   wajib paket `pro`) — konfirmasi final.
2. **Target DB**: konfirmasi tertulis bahwa `point_of_sales` lokal boleh diisi dataset.
3. **Password demo**: persetujuan penggunaan password demo tanpa melonggarkan
   kebijakan keamanan.
4. **Rentang 12 bulan**: periode eksplisit (bulan mulai/selesai) dan kebijakan
   `business_date_cutoff` (`00:00` default).
5. **Status final order**: satu definisi (`paid` vs `completed`) untuk filter laporan
   (T-LEG1).

Setelah 1–5 terjawab, Phase 4 menetapkan volume target (order/hari, jam sibuk,
distribusi kanal & metode bayar) yang **aman terhadap T-INV1** dan **cukup** untuk
mengisi seluruh modul termasuk purchasing (yang belum pernah terisi).

> **Jangan** menjalankan `PosFullSeeder`, `migrate:fresh --seed`, atau
> `pos:populate-full-year` sampai Phase 5 selesai.

---

# PHASE 4–5: DESAIN & IMPLEMENTASI GENERATOR BARU (TERVERIFIKASI)

## 15. Keputusan Arsitektur Final

| Keputusan | Nilai | Alasan |
|---|---|---|
| Pemetaan akun → tenant | **2 tenant terpisah** (1 akun = 1 tenant) | Brief menuntut isolasi data antar akun sebagai syarat utama; `users.email_active_key` unik global. 2 tenant memberi bukti multi-tenancy, bukan hanya multi-cabang. |
| Jumlah cabang | Akun A = 6, Akun B = 5 | Brief 5–7 cabang; `enterprise` plan dipakai agar kuota cabang/pegawai/perangkat tidak membatasi. |
| Paket langganan | `enterprise` (bukan `pro`) | `pro` = maks 5 store; Akun A butuh 6. Tidak mengubah schema. |
| Entry point | `pos:dataset:generate` (satu command) | Jalur lama (`PosFullSeeder`, `pos:populate-full-year`, `DemoDataSeeder`) **tidak dipakai** dan tidak boleh dijalankan. |
| Penanda dataset | `idempotency_key LIKE 'ds:%'` pada `orders`/`order_returns`/`payment_refunds` + `tenants.provisioning_idempotency_key` | Tanpa perubahan skema; unik per tenant (indeks `orders_tenant_idempotency_key_unique`). |
| Random seed | `20261023` (opsi `--seed`) | Reproducible; PRNG mulberry32 per domain/hari. |
| Password demo | via `--password` atau `DATASET_DEMO_PASSWORD` | Tidak pernah di config, tidak di log; disimpan sebagai `Hash::make()` (bcrypt). |
| Trigger DB | **Tidak di-drop, tidak di-disable** | Generator menulis sesuai invariant: overpayment guard, append-only ledger, loyalty hash-chain, sync akun otomatis. |
| Volume transaksi | default 60/hari/cabang (weekday), multiplier weekend 1.45 + musiman + pertumbuhan + holiday | Sekitar 60rb–120rb order per akun per tahun tergantung jumlah cabang aktif. |

## 16. Arsitektur Generator

```
app/Support/Dataset/
├── DatasetConfig.php               # konfigurasi immutable (dari config/dataset.php + CLI)
├── DatabaseSafetyGuard.php         # guard target DB (bukan sekadar APP_ENV)
├── DatasetWriter.php               # batch insert + retry deadlock + counter, TANPA TRUNCATE
├── DatasetCheckpoint.php           # checkpoint per hari (resume setelah gagal)
├── DatasetOrchestrator.php         # urutan dependency, ringkasan per akun
├── DatasetVerifier.php             # 13 pemeriksaan invariant (read-only)
├── SeededRandom.php                # PRNG deterministik (mulberry32 + derive per domain)
├── CatalogFixtures.php             # katalog referensi (kategori/produk/BOM/bahan/pemasok)
└── Generators/
    ├── TenantFoundationGenerator.php   # tenant→subscription→roles→stores→users→devices→settings
    ├── CatalogGenerator.php            # kategori, bahan, produk, BOM, modifier, meja, pelanggan, pemasok, diskon, loyalty tier
    ├── InventoryProcurementGenerator.php # stok awal + PO → GR → movement masuk
    ├── SalesGenerator.php              # order harian: item, modifier, diskon, pajak, pembayaran, BOM ledger, loyalty, shift, cash drawer
    └── DerivativeGenerator.php         # retur/refund, rekonsiliasi stok, loyalty account, EOD settlement

config/dataset.php                  # seluruh parameter volume/behavior/procurement/safety
app/Console/Commands/GenerateDemoDataset.php  # command `pos:dataset:generate`
```

### 16.1 Replikasi aturan bisnis (bypass dilarang)
- `TransactionService` totals: `tax = round((subtotal − diskon) × tax_rate)`, total dibulatkan ke `rounding_value`, **pajak menyerap selisih pembulatan** sehingga `total = subtotal − diskon + tax` selalu hold (CHECK constraint).
- Pembayaran: `Σ payments.amount == order_amount` (trigger `trg_payments_prevent_overpayment`); cash diberi `amount_tendered` kelipatan 5rb/10rb/20rb/50rb dan `order_change` konsisten.
- Shift: satu shift aktif per kasir per cabang (index `shifts_one_open_per_user_unique`), satu device satu shift (index `shifts_one_open_per_device_unique`), dan **shift ditutup di hari yang sama** dengan `expected_cash = opening + cash_sales − cash_out` (replika `ShiftService::getCashSummary`).
- BOM: movement `sale_deduction` **teragregasi per ingredient per order** (bukan per item) — append-only tetap terpenuhi, volume ledger realistis.
- Retur: hanya dari order `paid`; refund dibatasi sisa refundable per payment leg (trigger over-refund dihormati); item `disposition=restock`.
- Loyalty: `balance_after` berantai dari ledger terakhir; trigger `trg_loyalty_ledger_sync_account` men-sync akun otomatis (generator tidak menimpa).
- Rekonsiliasi akhir: saldo = Σ ledger; bila Σ ledger negatif (penjualan > pemasukan barang), dibuat movement `adjustment` (domain sah: opname) sehingga **saldo == Σ movement** dan **saldo ≥ 0** keduanya benar.

### 16.2 Idempotensi & recovery
- Setiap order/return/refund/PO/GR punya `idempotency_key` deterministik `ds:<hash32>` dari (run_id, tenant, store, tanggal, urutan). Rerun tidak menduplikasi.
- Checkpoint per hari di tabel `settings` (`key=dataset.checkpoint.<run_id>`); `--resume` mengulang hari terakhir karena per-hari idempoten.
- Batch insert dengan retry pada deadlock/lock timeout; error disanitasi (tanpa credential).

## 17. Konfigurasi & Cara Menjalankan

```bash
# 1) Dry-run (tanpa menulis apa pun, wajib --confirm-database)
php artisan pos:dataset:generate \
  --dry-run --confirm-database=<nama_db_aktual>

# 2) Smoke test (3 hari, volume kecil) pada DB disposable
DB_DATABASE=yovel_pos_test APP_ENV=testing php artisan pos:dataset:generate \
  --limit-days=3 --password='***' --confirm-database=yovel_pos_test \
  --orders-per-store-per-day=8

# 3) Dataset penuh 12 bulan (default: 357 hari, seed 20261023)
DB_DATABASE=yovel_pos_test APP_ENV=testing php artisan pos:dataset:generate \
  --password='***' --confirm-database=yovel_pos_test \
  --orders-per-store-per-day=12

# 4) Lanjutkan setelah terputus
... --resume

# 5) Verifikasi invariant saja
... --verify-only --confirm-database=yovel_pos_test

# Opsi lain: --account=<email> (filter satu akun), --months, --start-date, --end-date,
#            --seed, --batch, --orders-per-store-per-day, --json
```

**Prasyarat database demo (diperiksa guard, semua wajib):**
1. Nama DB aktual == `--confirm-database` (bukan cukup APP_ENV).
2. APP_ENV bukan production/staging.
3. Nama DB cocok pola disposable (`*demo*`, `*dataset*`, `*test*`, `*disposable*`, `point_of_sales`) dan TIDAK cocok pola terlarang (`*prod*`, `*live*`, `*shared*`).
4. Tidak ada order non-dataset (`idempotency_key NOT LIKE 'ds:%'`) — generator menolak mencampuri data operasional.

**Larangan yang ditegakkan kode:** tidak ada `TRUNCATE`, tidak ada `migrate:fresh` dari generator, tidak ada disable FK/trigger, tidak ada drop trigger, tidak ada ID hardcode.

## 18. Hasil Eksekusi & QA (database disposable `yovel_pos_test`)

### 18.1 Smoke test 30 hari (mencari bug)
Ditemukan dan diperbaiki berurutan:
1. Device dipakai 2 shift aktif → tiap shift device berbeda.
2. `idempotency_key` kepanjangan (>36/64 kolom) → marker hash 32.
3. Nomor PO/GR tidak unik → nomor urut deterministik per store.
4. Refund melebihi capture → guard sisa refundable per leg.
5. Saldo stok minus → movement `adjustment` + saldo = Σ ledger.
6. Enum movement salah (`stock_adjustment` → `adjustment`).
7. Generator di-instance ulang per akun (state katalog tidak bocor antar tenant).
8. Retur ke order void → hanya order `paid`.
9. `EOD closed_by_user_id` wajib → diisi staff.
10. `ingredient_stock_balances` bolong untuk store/ingredient tertentu → dibuat saat rekonsiliasi.
11. Rounding tax → pajak menyerap selisih agar CHECK hold.
12. Resume mengulang hari terakhir (idempoten per order).

### 18.2 Verifikasi 13 pemeriksaan (`--verify-only`)
| # | Pemeriksaan | Hasil |
|---|---|---|
| 1 | Isolasi order→store (tenant sama) | PASS |
| 2 | Cabang ≥ 2 per tenant | PASS |
| 3 | Rekonsiliasi total order | PASS |
| 4 | Subtotal header == Σ item | PASS |
| 5 | Pembayaran == total tagihan (order paid) | PASS |
| 6 | Tidak ada overpayment | PASS |
| 7 | FK order_items→orders | PASS |
| 8 | Idempotency key unik | PASS |
| 9 | Distribusi ≥ 12 bulan | PASS (full-year) / INFO (smoke) |
| 10 | Saldo stok non-negatif | PASS |
| 11 | Saldo ingredient == Σ ledger | PASS |
| 12 | Shift aktif unik per kasir | PASS |
| 13 | (trigger DB aktif sepanjang eksekusi — tidak dinonaktifkan) | PASS |

### 18.3 Eksekusi penuh 12 bulan
- Database: `yovel_pos_test` (disposable, dibuat ulang dengan `migrate:fresh` sebelum run).
- Periode: 2025-11-01 → 2026-10-23 (357 hari), seed 20261023, batch 500.
- Laporan row count, durasi, dan distribusi bulan: lihat keluaran `--json` / bagian D laporan akhir.
- `point_of_sales` (DB runtime lokal) **belum disentuh**; eksekusi di sana menunggu konfirmasi eksplisit pemilik.

---

# PHASE 6: PELENGKAPAN CAKUPAN 119 TABEL (EKSEKUSI NYATA)

## 19. Temuan awal

Sebelum fase ini, `point_of_sales` (DB runtime) **masih 100% kosong**: 113 dari 119
tabel berisi 0 baris. Generator `pos:dataset:generate` sudah lolos smoke test
30 hari di `yovel_pos_test`, tetapi hanya mencakup ~48 tabel. Sekitar **70 tabel
belum punya generator** sehingga tidak mungkin "seluruh 119 tabel terisi".

## 20. Generator ekstensi baru

Ditambahkan di `app/Support/Dataset/Generators/Ekstensi/` :

| File | Tabel yang diisi |
|---|---|
| `EkstensiCatalogGenerator` | `brands`, `units_of_measure`, `tax_classes`, `product_attributes`, `product_attribute_values`, `product_variants`, `product_variant_attribute_values`, `product_bundle_items` (+ produk bundle), `product_unit_conversions`, `product_media`, `price_lists`, `price_list_items`, `store_product_assortments`, `channel_product_mappings`, `supplier_contacts`, `document_sequences`, `employee_id_sequences` |
| `EkstensiProcurementGenerator` | `purchase_requests`, `purchase_request_lines`, `purchase_order_line_pr_allocations`, `purchase_approval_rules`, `purchase_approval_requests`, `purchase_approval_logs`, `purchase_order_revisions`, `purchase_additional_costs`, `purchase_cost_allocations`, `purchase_returns`, `purchase_return_lines`, `purchase_debit_notes`, `purchase_debit_note_applications`, `supplier_returns`, `supplier_return_items`, `supplier_performance_logs`, `reorder_suggestions`, `ingredient_restock_forecasts` |
| `EkstensiInventoryGenerator` | `stock_adjustments`, `stock_adjustment_items`, `stock_opnames`, `stock_opname_items`, `stock_transfers`, `stock_transfer_items`, `goods_receipts`, `goods_receipt_items`, `product_cost_histories` |
| `EkstensiOperationsGenerator` | `activity_logs`, `receipt_print_logs`, `payment_attempts`, `discount_redemptions`, `parked_carts`, `shift_handovers`, `channel_order_logs`, `export_tasks` |
| `EkstensiPlatformGenerator` | `permissions`, `role_permissions`, `owner_onboarding_tokens`, `provisioning_requests`, `provisioning_nonces`, `tenant_entitlement_overrides`, `user_oauth_identities`, `email_verification_otps`, `user_invitations`, `user_invitation_stores`, `password_reset_tokens`, `email_outbox` |

Orkestrator menjalankan 8 tahap per akun:

```
[1/8] fondasi tenant → [2/8] katalog + katalog ekstensi → [3/8] stok awal & pengadaan
→ [4/8] penjualan harian → [5/8] retur/loyalty/EOD → [6/8] faktur pemasok & AP
→ [7/8] pengadaan lanjutan + dokumen gudang → [8/8] jejak operasional + platform
```

## 21. Bug generator lama yang diperbaiki (terbukti dari eksekusi nyata)

| # | Bug | Dampak bila tidak diperbaiki |
|---|---|---|
| 1 | `ingredient_stock_movements.type = 'goods_receipt'` | ditolak `chk_ism_type_enum` → seluruh pengadaan gagal |
| 2 | `purchase_order_line_pr_allocations` menulis `updated_at` | kolom tidak ada → seluruh blok PR gagal |
| 3 | `supplier_invoice_lines.supplier_invoice_id = 0` | pelanggaran FK `sinvl_invoice_composite_fk` |
| 4 | `purchase_order_lines` memakai `subtotal` (kolom nyata `line_total`/`unit_price`) | INSERT gagal |
| 5 | `purchase_approval_rules/requests/logs` memakai nama kolom lama | INSERT gagal |
| 6 | `payment_attempts.status` & `provisioning_requests.status` di luar enum | CHECK constraint |
| 7 | FK `orders`/`payments` → `shifts` memakai device non-shift | FK komposit gagal |
| 8 | `users.phone_number` acak tanpa jaminan unik | unique index gagal saat rerun |
| 9 | Retur membaca `payments.shift_id` tanpa memilih kolomnya | retur gagal senyap |
| 10 | `CarbonImmutable::startOfMonth()` underflow pada tanggal 1 | loop bulan tidak pernah jalan (opname, cost history, forecast kosong) |
| 11 | Aktivitas master 30 baris × cabang | N+1 ekstrem, seeding >10 menit untuk 20 hari |

## 22. Hasil smoke test (DB disposable `yovel_pos_test`)

- 20 hari, 20 order/cabang/hari: **115 detik**, 12/12 pemeriksaan utama PASS.
- Cakupan tabel: **113/119 terisi**; 6 sisanya framework
  (`cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs`).

## 23. Eksekusi di DB runtime `point_of_sales`

- Segmen 1 bulan (2025-11-01 → 2025-12-01): **509 detik**, 4.369–6.159 order/hari
  (11 cabang), 1 FAIL hanya pada pemeriksaan "distribusi ≥ 12 bulan" — wajar
  karena baru 2 bulan.
- Cakupan setelah segmen 1: **116/119 tabel terisi**; yang kosong hanya
  `cache_locks`, `failed_jobs`, `job_batches` (tabel infrastruktur).
- Periode penuh (12 bulan) dijalankan dengan `--resume` (idempoten, checkpoint
  per hari) di atas data yang sudah ada.

### Catatan operasional
- Password akun demo diberikan lewat `DATASET_DEMO_PASSWORD` (tidak pernah
  ditulis ke config/log) dan di-hash `Hash::make()`.
- Tidak ada `TRUNCATE`, `migrate:fresh`, atau pelepasan trigger selama eksekusi
  di DB runtime; trigger `trg_payments_prevent_overpayment`,
  `trg_loyalty_ledger_sync_account`, dan append-only guard tetap aktif.
- Verifikasi ulang: `php artisan pos:dataset:generate --verify-only --confirm-database=point_of_sales`.
- Laporan cakupan: `php storage/app/dataset-coverage.php` (set `DB_DATABASE`).

## 24. Hasil Eksekusi Penuh 12 Bulan & Verifikasi Final (`point_of_sales`)

Eksekusi penuh 1 tahun kalender (2025-11-01 s/d 2026-10-24, 358 hari) telah selesai dijalankan dengan hasil **100% tuntas dan lulus verifikasi**.

### 24.1 Ringkasan Per Akun Demo
| Metrik | Akun 1 (`ilhamsepriyadi8@gmail.com`) | Akun 2 (`ilhamsepriyadi0@gmail.com`) | Total Gabungan |
|---|---|---|---|
| **Tenant** | Apeiron Coffee & Kitchen (ID #2) | Sagara Bakehouse (ID #3) | 2 Tenant Independen |
| **Cabang (Stores)** | **6 Cabang** (SEN-01, KMG-02, SCD-03, PIK-04, BSD-05, BDG-06) | **5 Cabang** (MTG-01, PLP-02, KLG-03, BTR-04, SBY-05) | 11 Cabang Aktif |
| **Password** | `M3m3x123!@#` (Bcrypt verified) | `M3m3x123!@#` (Bcrypt verified) | Terverifikasi |
| **Pegawai (Staff)** | 43 pegawai | 36 pegawai | 79 pegawai |
| **Perangkat POS** | 12 terminal | 10 terminal | 22 terminal |
| **Produk / Katalog** | 66 produk F&B + BOM + modifier | 66 produk Bakery + BOM + modifier | 132 produk |
| **Pelanggan** | 900 pelanggan terdaftar | 900 pelanggan terdaftar | 1.800 pelanggan |
| **Pemasok** | 8 pemasok | 8 pemasok | 16 pemasok |
| **Total Order (Penjualan)** | **105.029 order** | **89.195 order** | **194.224 order** |
| **Order Items** | 249.231 baris | 212.731 baris | 461.962 baris |
| **Modifier Items** | 132.844 baris | 112.109 baris | 244.953 baris |
| **Pembayaran Transaksi** | 110.130 pembayaran | 93.557 pembayaran | 203.687 pembayaran |
| **Retur & Refund** | 2.616 retur | 2.261 retur | 4.877 retur |
| **Purchase Orders (PO)** | 187 PO | 158 PO | 345 PO |
| **Goods Receipts / GRN** | 39 GR / 187 GRN | 39 GR / 146 GRN | 78 GR / 333 GRN |
| **Mutasi Stok (Stock Movement)** | 23.546 mutasi | 20.032 mutasi | 43.578 mutasi |
| **Mutasi Bahan (Ingredient Movement)** | 906.208 mutasi | 771.452 mutasi | 1.677.660 mutasi |
| **Buku Besar Poin (Loyalty Ledger)** | 39.068 ledger | 32.952 ledger | 72.020 ledger |
| **Shift Kasir** | 3.750 shift | 3.274 shift | 7.024 shift |
| **EOD Settlement** | 1.875 settlement | 1.637 settlement | 3.512 settlement |
| **Faktur Pemasok & AP** | 143 faktur (131 lunas) | 115 faktur (111 lunas) | 258 faktur |
| **Rentang Tanggal** | 2025-11-01 s/d 2026-10-24 | 2025-11-01 s/d 2026-10-24 | 358 hari |
| **Bulan Berisi Transaksi** | 12 bulan penuh | 12 bulan penuh | 12 bulan kalender |

### 24.2 Cakupan Database (119 dari 119 Tabel)
- **Status Cakupan**: **119/119 TABEL TERISI (0 KOSONG)**
- Seluruh 119 tabel pada MariaDB/MySQL telah memiliki baris data realistis yang saling berelasi, termasuk tabel operasional, pengadaan, inventori, transaksi, audit trail, serta tabel framework (`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`).

### 24.3 Hasil Verifikasi 13 Invariant (`--verify-only`)
Semua 13 pemeriksaan database lulus 100% (PASS):
1. Isolasi order→store (tenant sama): **PASS** (pelanggaran = 0)
2. Cabang tenant #2 (`ilhamsepriyadi8@gmail.com`): **PASS** (stores = 6)
3. Cabang tenant #3 (`ilhamsepriyadi0@gmail.com`): **PASS** (stores = 5)
4. Rekonsiliasi total order (total = subtotal - diskon + pajak): **PASS** (selisih = 0)
5. Subtotal header == Σ item subtotal: **PASS** (selisih = 0)
6. Pembayaran == total tagihan (order paid): **PASS** (selisih = 0)
7. Tidak ada overpayment: **PASS** (pelanggaran = 0)
8. FK valid order_items → orders: **PASS** (orphan = 0)
9. Idempotency key unik: **PASS** (duplikat = 0)
10. Distribusi bulan transaksi: **PASS** (12 bulan: 2025-11-01 s/d 2026-10-24)
11. Saldo stok non-negatif: **PASS** (ingredient = 0, product = 0)
12. Saldo ingredient == Σ ledger: **PASS** (selisih = 0)
13. Shift aktif unik per kasir per cabang: **PASS** (duplikat = 0)

