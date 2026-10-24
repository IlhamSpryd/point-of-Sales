# APEIRON POS — AUDIT STRUKTUR DATABASE MYSQL (READ-ONLY)

| Item | Nilai |
|---|---|
| Tanggal audit | 2026-10-22 |
| Repositori | `point-of-Sales/` (branch `chore/refactor-modular-architecture`, HEAD `79642f4`) |
| Target | `point_of_sales` @ `127.0.0.1:3306` (MariaDB 13.0.2) |
| Sifat | **Read-only.** Tidak ada DDL/DML, migration, seeder, atau perubahan kode aplikasi. |
| Metode | Inspeksi `information_schema` langsung, `SHOW CREATE TABLE`/`SHOW CREATE TRIGGER`, `EXPLAIN` (tanpa eksekusi data), pembacaan source code & migration |

---

## 1. Ringkasan Kondisi Database

| Metrik | Nilai terukur |
|---|---|
| Tabel InnoDB | **117** (semua `ENGINE=InnoDB`, `utf8mb4_unicode_ci`) |
| Migration terdaftar (`migrations`) | **159** — sama dengan jumlah file migration |
| Foreign key | **307** |
| Trigger | **45** (di 24 tabel) |
| View / Stored procedure | 0 / 0 |
| Generated column | 8 |
| CHECK constraint | tersebar di ±30 tabel (lihat §4) |
| SQL mode | `STRICT_TRANS_TABLES, ONLY_FULL_GROUP_BY, NO_ZERO_DATE, …` (strict) |
| Isolation level | `REPEATABLE-READ` |
| Isi data | **Seluruh tabel bisnis kosong** (0 baris). Hanya `plans` (4), `plan_entitlements` (24), `permissions` (39), `sessions` (2), `migrations` (159) |

Skema live **sudah sangat jauh di atas rata-rata proyek POS**: composite FK `(tenant_id, …)`/`(tenant_id, store_id, …)`, CHECK constraint untuk enum, generated column untuk uniqueness soft-delete, trigger append-only untuk tabel finansial, serta idempotency key di jalur transaksi. Model multi-tenancy (3-tier ownership) memang **terealisasi di level database**, bukan hanya di Eloquent.

Namun audit menemukan **cacat struktural yang nyata** di titik-titik paling sensitif: satu-satunya invariant "satu shift terbuka" bersifat **global lintas-tenant**, beberapa relasi finansial masih **single-column FK** sehingga referensi lintas-tenant bisa lolos, satu trigger pembentuk hash-chain loyalty secara **deterministik gagal** pada entry kedua per pelanggan, dan ada **migration yang secara diam-diam mengubah/menghapus foreign key**.

**Status akhir: `AUDIT COMPLETE — REMEDIATION REQUIRED`** (lihat §12).

---

## 2. Batas Pemeriksaan & Akses

### 2.1 Yang berhasil diverifikasi langsung
- Identitas server: `SELECT @@version, @@version_comment, @@sql_mode, @@transaction_isolation, @@default_storage_engine` → **MariaDB 13.0.2, InnoDB, REPEATABLE-READ**.
- DDL lengkap 117 tabel via `SHOW CREATE TABLE` (dump ke artefak kerja, tidak disimpan di repo).
- Definisi lengkap 45 trigger via `SHOW CREATE TRIGGER`.
- 307 FK + 760 constraint + seluruh index via `information_schema`.
- 159 rekaman `migrations`; dicocokkan satu-per-satu dengan file di `database/migrations` → **tidak ada drift rekaman** (159 = 159, tanpa duplikat).
- `EXPLAIN` terhadap 12 pola query representatif (tanpa mengeksekusi data bisnis).
- Menjalankan suite milik proyek `php artisan pos:verify-database` (10 skrip, read-only, **PASS semua** — tetapi lihat **DB-016** karena lulusnya miskin makna pada DB kosong).
- `git status`/`git log` untuk menentukan provenance migration.

### 2.2 Koneksi & segregasi database

| Konteks | Database | Keterangan |
|---|---|---|
| Runtime lokal (`.env`) | `point_of_sales` | **Ini yang diaudit.** |
| Testing (`phpunit.xml`, `.env.testing`) | `yovel_pos_test` | **Tidak diaudit.** |

User aplikasi `laravel_pos@127.0.0.1` memiliki `ALL PRIVILEGES` hanya pada dua database tersebut (tidak ada grant `*.*` selain `USAGE`). Tidak ada koneksi staging/produksi yang dapat diakses dari mesin ini.

### 2.3 Yang **tidak** dapat diverifikasi
- Skema dan perilaku database staging/produksi (tidak terjangkau).
- Perilaku runtime terhadap data volume nyata (tabel kosong → `table_rows`/optimizer cost tidak bermakna).
- Race-condition nyata (audit ini tidak menjalankan uji konkurensi dua-koneksi).
- Apakah data pada backup eksternal (`../point_of_sales_pre_seed_backup_20261014_141809.sql`) cocok dengan skema saat ini (lihat **DB-017**).

### 2.4 Catatan kerahasiaan
Audit menemukan kredensial database **hardcoded dalam plaintext di file yang di-commit** — lihat **DB-018**. Nilai kredensial **tidak** ditampilkan di laporan ini (sudah di-redaksi pada semua keluaran terminal).

---

## 3. Gambaran Struktur Database Aktual

### 3.1 Pembagian kepemilikan (sesuai hasil pengukuran, bukan asumsi dokumen)

| Kelas | Jumlah tabel | Contoh |
|---|---|---|
| Tanpa `tenant_id` (platform/global) | 14 | `tenants`, `permissions`, `role_permissions`, `plans`, `plan_entitlements`, `provisioning_nonces`, `cache*`, `jobs*`, `failed_jobs`, `sessions`, `password_reset_tokens`, `migrations` |
| Tenant-owned, tanpa `store_id` | 54 | `users`, `roles`, `stores`, `customers`, `products`, `categories`, `brands`, `suppliers`, `purchase_orders`, `loyalty_tiers`, `modifiers`, `ingredients`, … |
| Tenant+Store-owned (`store_id NOT NULL`) | 42 | `orders`, `order_items`, `payments`, `shifts`, `tables`, `stock_movements`, `cash_drawer_movements`, `activity_logs`, `devices`, `receipt_print_logs`, modul purchasing |
| Hybrid (`store_id NULLABLE`) | 7 | `discounts`, `loyalty_ledger`, `price_lists`, `product_cost_histories`, `document_sequences`, `accounting_payable_outbox`, `purchase_approval_rules` |

> Dokumen `docs/architecture/OWNERSHIP_MODEL.md` mendeklarasikan `activity_logs`, `export_tasks`, `stock_movements` sebagai Tier 3A. Hasil audit **mengonfirmasi** kelima tabel inti tersebut memiliki FK komposit `(tenant_id, store_id) → stores(tenant_id, id)`. Yang **tidak** sesuai kontrak: `discounts` tidak memiliki FK ke `stores` sama sekali (DB-007), `loyalty_ledger.store_id` tanpa FK store (DB-020), dan tabel modul baru (§3.3) menyimpang dari pola.

### 3.2 Kekuatan struktural yang layak dipertahankan
1. **Composite FK berlapis** pada jalur POS inti (`orders`, `order_items`, `payments`, `shifts`, `cash_drawer_movements`, `payment_attempts`, `payment_refunds`, `order_returns`) → tenant/store tidak bisa dicampur pada relasi tersebut.
2. **Append-only trigger** pada `payments`, `stock_movements`, `ingredient_stock_movements`, `activity_logs`, `cash_drawer_movements`, `product_cost_histories`, `payment_refunds`, `purchase_approval_logs`.
3. **Generated-column uniqueness** untuk soft-delete (`users.email_active_key`, `tables.table_name_uniqueness_key`, `categories.name_uniqueness_key`, `customers.phone_uniqueness_key`, `brands.name_uniqueness_key`).
4. **CHECK constraint** enum (`orders.order_status`, `payments.status`, `order_returns.status`, `stock_movements.type`, dll.).
5. **Idempotency key unik per tenant** pada `orders` (`orders_tenant_idempotency_key_unique`), `order_returns`, `payment_attempts`, `payment_refunds`.
6. **Urutan lock kanonik** untuk ledger stok (`InventoryLedgerService::recordBatchMovements` mengurutkan target sebelum `lockForUpdate`) — praktik terbaik anti-deadlock.

### 3.3 Tabel modul terbaru yang belum terikat pola ownership
`supplier_performance_logs` dan `reorder_suggestions` (migration `2027_01_03_000009`, **masih untracked di Git**) hanya memiliki **index** `(tenant_id, store_id, …)`, **tanpa foreign key**. Bandingkan dengan `supplier_invoices`, `goods_received_notes`, dll. yang memakai `fk_*_tenant_store`. Detail: **DB-021**.

---

## 4. Temuan Terperinci

Kategori: **[C]** = masalah terkonfirmasi, **[R]** = risiko desain (perlu validasi), **[I]** = rekomendasi peningkatan, **[U]** = belum dapat diaudit.

### DB-001 — `shifts_one_open_per_user_unique` bersifat global lintas-tenant **[C]**
- **Objek:** `shifts`, unique index `shifts_one_open_per_user_unique (open_shift_lock_key)`.
- **Bukti (live DDL):**
  ```sql
  `open_shift_lock_key` bigint unsigned GENERATED ALWAYS AS
    (case when `status` in ('open','handover_pending','closing')
          then coalesce(`active_cashier_id`,`user_id`) else NULL end) VIRTUAL,
  UNIQUE KEY `shifts_one_open_per_user_unique` (`open_shift_lock_key`),
  UNIQUE KEY `shifts_one_open_per_device_unique` (`tenant_id`,`store_id`,`open_device_guard`)
  ```
  Index device **diskop per tenant+store**, tetapi index per-user **tidak**.
- **Asal:** `2026_09_21_090000_harden_shift_one_open_per_user.php`: `$table->unique('open_shift_lock_key', ...)` — belum ada `tenant_id`/`store_id` karena tenant baru diperkenalkan pada `2026_09_25_211159`. Migration rescoping (`2026_11_02_000000_rescope_uniques.php`) **tidak menyentuh index ini**.
- **Kondisi yang memungkinkan masalah:** kasir tenant A `id=1` membuka shift (menghasilkan key `1`). Kasir tenant B `id=1` (id user adalah auto-increment global → sangat mungkin bertabrakan) membuka shift → `Duplicate entry '1'…` → **login/shift gagal di tenant yang sama sekali tidak terkait**.
- **Dampak:** gangguan operasional lintas-tenant (cross-tenant denial of service), tidak ada kebocoran data tetapi melanggar isolasi. Ketidakseimbangan: index device sudah benar, index user belum.
- **Prioritas:** **P0 (Critical).** Isolasi tenant adalah persyaratan produk inti.
- **Rekomendasi:** ganti menjadi `UNIQUE (tenant_id, store_id, open_shift_lock_key)`. Klarifikasi semantik: apakah "satu shift terbuka per kasir per cabang" atau "per kasir per tenant". Jika per-tenant, gunakan `(tenant_id, open_shift_lock_key)`.
- **Dependensi:** `ShiftService::openShift`, `handover`, `closeShift`; trigger `trg_shifts_*` (tidak ada trigger pada `shifts`); suite `06_shift_overlap.sql`.
- **Verifikasi:** pada dua tenant berbeda dengan `user_id` sama, buka shift bersamaan → keduanya sukses; buka dua shift untuk kasir yang sama di tenant yang sama → yang kedua ditolak dengan pesan domain, bukan error 1062 mentah.

### DB-002 — `trg_loyalty_ledger_hash_chain` deterministik gagal pada entry kedua per pelanggan **[C]**
- **Objek:** trigger `trg_loyalty_ledger_hash_chain` (`BEFORE INSERT ON loyalty_ledger`).
- **Bukti (live trigger, identik di `2026_09_22_030002`):**
  ```sql
  SET NEW.entry_hash = SHA2(CONCAT_WS('|',
      NEW.customer_id, IFNULL(NEW.order_id,''), NEW.type, NEW.points,
      NEW.balance_after, IFNULL(last_hash,''), NOW()), 256);
  ```
  Index: `UNIQUE KEY loyalty_ledger_entry_hash_unique (entry_hash)`.
- **Analisis:** input hash **tidak memuat** `reference`, `created_by`, maupun ID baris. Dua entry berurutan untuk pelanggan yang sama dengan `order_id`, `type`, `points`, `balance_after` identik dalam **detik yang sama** menghasilkan hash identik → pelanggaran unique `loyalty_ledger_entry_hash_unique`.
- **Kondisi konkret:** ledger yang ditulis dalam satu transaksi (earn lalu redeem/bonus dalam detik yang sama) dengan kombinasi bernilai sama; atau dua `adjustment` bernilai sama dalam satu detik. `order_id` NULL-lah yang paling berbahaya (`IFNULL(order_id,'')` sama untuk semua entry non-order).
- **Dampak:** kegagalan checkout/loyalty pada titik tak terduga, terlihat sebagai `SQLSTATE 23000`, bukan kesalahan domain. Rantai hash juga tidak unik secara kriptografis (bukan bukti integritas yang kuat).
- **Prioritas:** **P0** (jalur checkout POS).
- **Rekomendasi:** sertakan kolom pembeda (mis. `id`, `reference`, `created_by`, atau nonce/microsecond) ke dalam preimage; idealnya hitung hash di aplikasi setelah insert lalu lakukan append terpisah, atau sertakan AUTO_INCREMENT yang sudah dialokasikan.
- **Verifikasi:** di lingkungan terisolasi, insert dua baris `loyalty_ledger` identik dalam satu detik untuk pelanggan yang sama → keduanya berhasil dan `entry_hash` berbeda. Job verifikasi rantai tidak ada di repo → lihat DB-014.

### DB-003 — Trigger duplikat pada event yang sama (MariaDB menolak `CREATE TRIGGER` kedua) **[C]**
- **Objek:** `loyalty_ledger`, `ingredient_stock_movements`.
- **Bukti (live `information_schema.TRIGGERS`):**
  - `loyalty_ledger` BEFORE DELETE: `trg_loyalty_ledger_before_delete` **dan** `trg_loyalty_ledger_no_delete`.
  - `loyalty_ledger` BEFORE UPDATE: `trg_loyalty_ledger_no_update` (didefinisikan ulang lewat `DROP TRIGGER IF EXISTS`, sehingga hanya satu).
  - `ingredient_stock_movements` BEFORE DELETE: `trg_ingredient_stock_movements_before_delete` **dan** `trg_ingredient_stock_movements_no_delete`.
- **Asal:** `2026_09_22_010002` / `2026_09_22_030002` membuat `*_no_delete`; `2026_12_02_000000_add_immutability_triggers.php` membuat `*_before_delete` **tanpa** menghapus nama lama.
- **Temuan penting:** MariaDB >= 10.2 **menolak** dua trigger untuk event yang sama. Fakta bahwa keduanya ada di database ini membuktikan `2026_12_02` **tidak dieksekusi pada database ini** (atau gagal dan tetap ditandai `Ran`). Konsekuensi: trigger lain di migration yang sama (notably `trg_orders_before_update` versi baru, `trg_order_items_before_update` versi baru) kemungkinan **tidak lengkap/berbeda** dari yang diharapkan. Laporan `migrations` menunjukkan `Ran` → **status migration tidak dapat dipercaya sebagai bukti bahwa efektor DDL sudah diterapkan**.
- **Dampak:** pada instalasi baru, `2026_12_02` akan **gagal total** (§8.2). Pada DB ini, invariant "immutable" bergantung pada trigger versi lama — perlu verifikasi eksplisit.
- **Prioritas:** **P0** (new-install blocked + drift).
- **Rekomendasi:** ubah `2026_12_02` untuk `DROP TRIGGER IF EXISTS` seluruh nama lama sebelum `CREATE`; tambahkan pemeriksaan pasca-migrasi yang menghitung trigger per `(table, event)` dan gagal jika ≠ 1.
- **Verifikasi:** `SELECT event_object_table, action_timing, event_manipulation, COUNT(*) FROM information_schema.TRIGGERS WHERE trigger_schema=DATABASE() GROUP BY 1,2,3 HAVING COUNT(*)>1` → kosong; `php artisan migrate --pretend` + `migrate:fresh` di container sementara.

### DB-004 — `trg_orders_before_update` membuat order `pending` praktis tidak dapat diubah **[C]**
- **Objek:** `orders`, trigger `trg_orders_before_update` (versi live, dari `2026_09_18`/`2026_09_25`).
- **Bukti:** cabang pertama `IF OLD.order_status <=> 'pending' THEN … SIGNAL …` membandingkan `subtotal_amount`, `tax_amount`, `order_amount`, `discount_id`, `customer_id`, `table_id`, dst. Perubahan apa pun pada kolom-kolom tersebut saat status masih `pending` ditolak, **termasuk transisi `pending → paid` yang sah** (karena saat itu `NEW.order_status` berubah tetapi `NEW.order_amount`/`cash_received` dkk. juga berubah). Hanya perubahan `preparation_status`, `print_count`, dan `void_*` yang lolos.
- **Konteks kode:** `TransactionService` memecah alur menjadi `createTransaction()` (membuat `pending`) dan `payPendingOrder()` (`lockForUpdate` → update status & finansial). Baris 1228 memperbarui `order_items.preparation_status`, bukan `orders`.
- **Kondisi:** setiap checkout non-cash (order dibuat `pending`, lalu dibayar), atau setiap update `order_amount` setelah insert, akan ditolak oleh trigger dengan pesan "field kepemilikan/identitas/finansial tidak dapat diubah saat pending".
- **Status bukti:** **struktural pasti** (logika trigger terbaca). Perlu konfirmasi runtime karena tabel kosong — lihat §10.2 langkah T-1.
- **Dampak:** blocker alur POS paling utama; risiko tinggi saat seeder/skenario UAT dijalankan.
- **Prioritas:** **P0.**
- **Rekomendasi:** izinkan transisi status terkontrol (`pending→paid`, `pending→cancelled/expired/failed`) dengan daftar kolom yang boleh berubah per transisi, dan tegakkan hanya setelah transisi final. Lebih aman lagi: pindahkan guard ke `paid/void` saja dan gunakan `changes`-aware comparison, bukan membandingkan semua kolom.
- **Verifikasi:** di DB test terisolasi: insert order `pending` → update menjadi `paid` dengan `cash_received` terisi → sukses; ubah `order_amount` setelah `paid` → ditolak.

### DB-005 — `trg_loyalty_ledger_no_update` / `trg_ingredient_stock_movements_no_update` menolak `tenant_id` non-NULL **[C]**
- **Objek:** kedua trigger tersebut.
- **Bukti:**
  ```sql
  IF NOT ( ... AND OLD.tenant_id IS NULL ) THEN SIGNAL ...
  ```
  Padahal `loyalty_ledger.tenant_id` dan `ingredient_stock_movements.tenant_id` adalah `NOT NULL` (ditambahkan `2026_09_25_213221`). Artinya **setiap** `UPDATE` pada baris lama yang punya `tenant_id` terisi akan ditolak — bukan hanya perubahan kolom bisnis.
- **Dampak:** tidak ada perubahan data yang sah seharusnya terjadi (append-only), tetapi ini berarti `UPDATE` apa pun (termasuk operasi massal/backfill) gagal dengan pesan yang menyesatkan ("append-only") alih-alih menolak field spesifik.
- **Prioritas:** **P1** (korektnes pesan/observabilitas).
- **Rekomendasi:** hapus `OLD.tenant_id IS NULL` dan bandingkan `OLD.tenant_id <=> NEW.tenant_id`; lakukan hal sama untuk `store_id`, `product_id`, dst. agar semua kolom yang termasuk identitas ikut dibandingkan.
- **Verifikasi:** `UPDATE loyalty_ledger SET tenant_id = tenant_id` untuk satu baris → sukses; `SET points = points + 1` → ditolak.

### DB-006 — `trg_payments_prevent_overpayment` tidak melindungi `UPDATE` dan salah untuk split tender **[R/C]**
- **Objek:** `payments`, trigger `BEFORE INSERT` only.
- **Bukti:** guard hanya pada `INSERT`; `trg_payments_before_update` memang menolak semua `UPDATE`, sehingga satu-satunya jalur perubahan adalah `INSERT` (terlindungi). Namun:
  - `chk_payments_amount_positive CHECK (amount >= 0)` mengizinkan **amount = 0** (pembayaran no-op).
  - `payments.order_id` → `orders(tenant_id,id)` **tanpa** `store_id`; `payments.store_id` bisa berbeda dari `orders.store_id` untuk order yang sama (lihat DB-008). Trigger menghitung `SELECT SUM(amount) … WHERE order_id = NEW.order_id` tanpa menyertakan tenant — karena `order_id` global unik, ini aman, tetapi tetap **lolos** terhadap pencampuran store.
  - `SELECT order_amount INTO order_total FROM orders WHERE id = NEW.order_id` — jika `order_id` yatim (FK aktif, jadi tidak mungkin), `order_total` menjadi NULL → perbandingan `> NULL` = NULL → guard **lolos diam-diam**. Ini bukan bug yang dapat terjadi selama FK aktif; catat sebagai pertahanan berlapis.
- **Dampak:** risiko terutama pada split tender lintas cabang; tidak ada kebocoran uang langsung.
- **Prioritas:** **P1.**
- **Rekomendasi:** `CHECK (amount > 0)`; tambahkan trigger `BEFORE INSERT` yang memverifikasi `NEW.store_id = (SELECT store_id FROM orders WHERE tenant_id=NEW.tenant_id AND id=NEW.order_id)`; tambahkan `IF order_total IS NULL THEN SIGNAL …`.

### DB-007 — `discounts.store_id` tanpa FK ke `stores` dan tanpa cakupan unique lintas cabang **[C]**
- **Objek:** `discounts`.
- **Bukti (live):** kolom `store_id bigint unsigned DEFAULT NULL` (hybrid), satu-satunya FK adalah `discounts_tenant_id_foreign → tenants(id)`. Tidak ada `FOREIGN KEY (tenant_id, store_id) → stores`. Unique yang ada: `discounts_tenant_id_code_unique (tenant_id, code)`.
- **Konsekuensi:**
  1. `store_id` dapat menunjuk ke **cabang tenant lain** (12600/12272/12300).
  2. Kode diskon unik **satu tenant penuh** → cabang B tidak bisa membuat promo berkode `WEEKEND` miliknya sendiri.
  3. `orders` mengacu ke `discounts` via `(tenant_id, discount_id)` — bukan `(tenant_id, store_id, discount_id)` → order cabang A bisa memakai diskon yang di-scope ke cabang B.
  4. `DiscountStoreScope` mem-filter di aplikasi (`store_id = ? OR store_id IS NULL`), tetapi *tidak* divalidasi saat order dibuat (lihat DB-009).
- **Dampak:** kebocoran konfigurasi lintas cabang + bypass promo lintas cabang; isolasi hanya di level aplikasi, bukan database.
- **Prioritas:** **P1.**
- **Rekomendasi:** tambah FK `(tenant_id, store_id) → stores(tenant_id, id)`; pertimbangkan unique parsial `(tenant_id, store_id, code)` untuk diskon per cabang (butuh migration baru yang mem-backfill `store_id = NULL` sebagai global). Karena `discounts.store_id` sengaja nullable, gunakan `ON DELETE RESTRICT`.
- **Verifikasi:** insert diskon dengan `store_id` milik tenant lain → ditolak FK; buat kode sama di dua cabang sesuai kebijakan bisnis.

### DB-008 — Relasi finansial & operasional yang masih single-column (cross-tenant reference possible) **[C]**
- **Bukti (`information_schema.KEY_COLUMN_USAGE`, referensi yang **tidak** memakai `tenant_id`):**

| Tabel | Constraint | Kolom → Tabel |
|---|---|---|
| `discount_redemptions` | `fk_dr_customer` | `customer_id → customers(id)` |
| `discount_redemptions` | `fk_dr_user` | `redeemed_by_user_id → users(id)` |
| `parked_carts` | `fk_pc_customer`, `fk_pc_table`, `fk_pc_discount`, `fk_pc_resumed_user`, `fk_pc_converted_order` | single-column |
| `order_items` | `order_items_item_discount_id_foreign` | `item_discount_id → discounts(id)` |
| `goods_received_notes` | `grn_posted_by_fk`, `grn_received_by_fk`, `grn_reversed_by_fk`, `grn_reversal_of_fk` | `→ users(id)` / self |
| `purchase_approval_logs` | `palog_actor_fk` | `actor_id → users(id)` |
| `purchase_orders` | `po_creator_fk`, `po_approver_fk`, `po_releaser_fk` | `→ users(id)` |
| `purchase_requests` | `pr_requested_by_user_fk`, `pr_approved_by_user_fk` | `→ users(id)` |
| `supplier_invoices` | `sinv_approved_by_fk`, `sinv_hold_approved_by_fk` | `→ users(id)` |
| `receipt_print_logs` | `fk_rpl_device`, `fk_rpl_shift` | `→ devices(id)`, `shifts(id)` |
| `email_outbox` | `email_outbox_user_fk` | `user_id → users(id)` |
| `payment_attempts` | `payment_attempts_initiated_by_user_id_foreign` | `initiated_by_user_id → users(id)` |
| `product_cost_histories` | `pch_changed_by_foreign` | `changed_by → users(id)` |

- **Kondisi:** FK tunggal hanya menjamin "row dengan id itu ada", bukan "milik tenant yang sama". Rantai dua langkah **melewati** composite FK yang sudah ada, mis. `goods_received_notes` punya `fk_grn_store` komposit tetapi `posted_by_user_id` bisa user tenant lain → data audit pemasok lintas tenant.
- **Dampak:** audit trail lintas tenant, potensi eskalasi approval (approver dari tenant lain) jika query aplikasi tidak mem-filter, dan pelanggaran integritas kepemilikan yang **tidak terdeteksi** oleh `01_fk_orphans.sql` (script hanya memeriksa kolom, bukan scope).
- **Prioritas:** **P1** (user FKs) / **P2** (tabel non-finansial).
- **Rekomendasi:** konversi ke composite: `(tenant_id, user_id) → users(tenant_id, id)`, `(tenant_id, customer_id) → customers(tenant_id, id)`, dan `(tenant_id, store_id, order_id)` untuk `parked_carts.converted_order_id`, `(tenant_id, store_id) → shifts`. Pastikan `users` punya `UNIQUE(tenant_id, id)` (sudah ada). Untuk `parked_carts`, ikuti pola `order_item_modifiers`.
- **Verifikasi:** untuk tiap tabel, hitung jumlah FK dengan `ref_table IN ('users','customers','orders','shifts','devices')` dan kolom pertama ≠ `tenant_id` → harus 0 (kecuali tabel platform eksplisit).

### DB-009 — Tidak ada constraint DB yang mengikat `store_id` anak ke `store_id` induknya pada tabel hybrid **[R]**
- **Objek:** `order_items`, `order_item_modifiers`, `payments`, `stock_movements`, `ingredient_stock_movements`, `order_item_modifiers`, `receipt_print_logs`.
- **Bukti (live DDL):**
  - `order_items.order_id_composite_foreign` → `orders(tenant_id, id)` — **bukan** `(tenant_id, store_id, id)`.
  - `order_items` memiliki `order_items_tenant_id_store_id_composite_foreign → stores(tenant_id, id)` — jadi store-nya valid, tetapi bisa **berbeda** dari order-nya.
  - Sama untuk `payments`, `stock_movements.order_id`, `order_item_modifiers.order_item_id`.
  - `orders` punya `UNIQUE(tenant_id, id)` dan `UNIQUE(tenant_id, idempotency_key)` — cukup untuk FK 3-kolom jika ditambahkan `UNIQUE(tenant_id, store_id, id)`.
- **Kondisi:** `OrderItem::create([... 'order_id' => $order->id, 'store_id' => app(TenantContext::class)->getStoreId()])` (`TransactionService.php:665-668`) mengambil store dari **konteks**, bukan dari `$order->store_id`. Jika konteks bergeser (`runAs`, request yang salah resolve, atau job), baris detail masuk ke cabang berbeda dari header → laporan per-cabang tidak balance.
- **Dampak:** kesalahan rekonsiliasi cabang dan penjualan yang tidak terlihat di cabang yang mencatat; deteksi mustahil lewat FK.
- **Prioritas:** **P1.**
- **Rekomendasi:** tambahkan `UNIQUE(tenant_id, store_id, id)` pada `orders` (dan `order_items` bila perlu) lalu ubah FK menjadi 3-kolom. Alternatif: trigger `BEFORE INSERT` yang menyamakan `NEW.store_id` dengan store header.
- **Verifikasi:** `03_order_totals.sql` diperluas untuk memeriksa `order_items.store_id <> orders.store_id` → harus 0; coba insert detail dengan store berbeda → ditolak.

### DB-010 — `products.stock` (legacy mirror) adalah state duplikat **[R]**
- **Objek:** `products.stock int unsigned NOT NULL DEFAULT 0` vs `product_stock_balances.quantity decimal(18,6)`, dan `ingredients.current_stock decimal(14,4) unsigned` vs `ingredient_stock_balances.quantity`.
- **Bukti:** `InventoryLedgerService::syncLegacyMirrorColumn()` memperbarui mirror dengan `DB::raw("stock + ({$intDelta})")`; `products.stock` bertipe **INTEGER**, sedangkan saldo ledger bertipe `DECIMAL(18,6)`. Setiap delta pecahan (mis. 0.5 kg) hilang saat diproyeksikan ke mirror.
- **Dampak:** sumber kebenaran ganda; dashboard/katalog yang membaca `products.stock` menyimpang dari ledger; potensi **overflow** (INT max 2.147.483.647) tidak realistis, tetapi presisi hilang pasti terjadi untuk unit pecahan.
- **Prioritas:** **P2.**
- **Rekomendasi:** dokumentasikan mirror sebagai read-only cache, ubah ke `DECIMAL(18,6)`, atau ganti dengan VIEW terhitung. Jika dibiarkan, tambahkan CHECK yang memastikan mirror tidak dipakai untuk keputusan bisnis.
- **Verifikasi:** bandingkan `products.stock` vs `product_stock_balances.quantity` untuk semua (tenant, store, product) → 0 selisih; sudah ada dasar di `05_inventory_balances.sql`.

### DB-011 — `order_items` dan jenis moneter bercampur antara `bigint` (IDR) dan `decimal(15,2)` **[C]**
- **Objek:** `order_items.order_price/order_subtotal` `BIGINT`, vs `item_discount_amount/allocated_order_discount_amount/net_line_total` `DECIMAL(15,2)`; `orders.*` `BIGINT UNSIGNED`; `payments.amount` `BIGINT UNSIGNED`; `order_returns` & `payment_refunds` `DECIMAL(15,2)`.
- **Bukti:** `TransactionService::calculateOrderTotals` dan rekonsiliasi memakai `float` (`(float) $line['order_subtotal']`), lalu disimpan ke `bigint` → pembulatan implisit. `orders.order_amount` dihitung dari hasil yang sudah di-cast.
- **Dampak:** selisih 1 IDR antara header dan detail dapat muncul pada kombinasi diskon persentase + pembulatan; `03_order_totals.sql` memakai toleransi sehingga selisih kecil tidak tertangkap. Untuk Rupiah (tanpa sen) ini biasanya aman, tetapi **tipe tidak sesuai** antara header/detail/payment/refund, dan `float` di PHP bukan wadah moneter yang tepat.
- **Prioritas:** **P2.**
- **Rekomendasi:** satu tipe kanonik untuk uang (bilangan bulat Rupiah di semua tabel, atau `DECIMAL(18,2)` di semua). Jika tetap campur, tambahkan CHECK `order_subtotal BETWEEN -? AND ?` dan perhitungan berbasis integer (sudah ada `compareScaled`).
- **Verifikasi:** property test: untuk 10.000 kombinasi diskon pajak, `SUM(order_items.net_line_total) == orders.order_amount` tanpa toleransi.

### DB-012 — `eod_settlements` tidak memiliki unique `(tenant_id, store_id, business_date)` **[C]**
- **Objek:** `eod_settlements`.
- **Bukti:** `ReportService` (baris 185-188) memeriksa `where('business_date', $businessDate)->where('status', 'closed')`, lalu membuat settlement. Pemeriksaan ini **check-then-act** tanpa lock/unique. Tidak ada `UNIQUE (tenant_id, store_id, business_date)`. Status yang ada termasuk "closed"; trigger hanya mengunci baris yang sudah `closed`.
- **Dampak:** dua request EOD paralel dapat menghasilkan **dua settlement closed** untuk tanggal yang sama → total omzet dan selisih kas ganda.
- **Prioritas:** **P1.**
- **Rekomendasi:** `UNIQUE (tenant_id, store_id, business_date)` (jika hanya satu settlement per tanggal) atau unique partial pada status `closed` via generated column; tambahkan `lockForUpdate` pada pemeriksaan.
- **Verifikasi:** di test konkurensi dua proses menutup EOD bersamaan → satu sukses, satu mendapat pelanggaran unique.

### DB-013 — `order_returns` dapat melebihi nilai transaksi; `order_return_items` tidak mencegah retur ganda **[R]**
- **Objek:** `order_returns`, `order_return_items`, `payment_refunds`.
- **Bukti:** CHECK hanya `status`/`return_type`; tidak ada foreign-key/trigger yang membatasi `SUM(order_returns.total_refundable_amount)` ≤ `orders.order_amount` yang tersisa, tidak ada unique `(tenant_id, store_id, order_item_id)` pada `order_return_items`, dan tidak ada guard `SUM(payment_refunds.amount) ≤ SUM(payments captured)`.
- **Dampak:** retur melebihi nilai order (over-refund) hanya dicegah di aplikasi (`ReturnRefundService`) dengan `lockForUpdate` pada order—namun `payment_refunds` tidak memiliki trigger over-guard seperti `payments`.
- **Prioritas:** **P1** (finansial).
- **Rekomendasi:** tambahkan trigger `BEFORE INSERT ON payment_refunds` yang menolak jika `SUM(amount) WHERE financial_status='settled'` > `SUM(payments captured)`; unique parsial pada baris retur per item.

### DB-014 — Tidak ada job/tool untuk memverifikasi hash-chain loyalty **[I]**
- **Objek:** docblock `2026_09_22_030002` menjanjikan "job verifikasi berkala yang menghitung ulang rantai".
- **Bukti:** `grep` atas `app/` tidak menemukan job verifier; `pos:verify-database` (`10_security.sql`) tidak memeriksa `prev_hash`/`entry_hash`.
- **Dampak:** klaim integritas rantai tidak dapat dibuktikan; bersama DB-002, kolom hash menjadi beban tanpa manfaat terjamin.
- **Prioritas:** **P2.**
- **Rekomendasi:** buat command read-only `pos:verify-ledger-chain` yang menghitung ulang `entry_hash` dan melaporkan mismatch per pelanggan.

### DB-015 — Risiko konkurensi & lock ordering **[R]**
- **Bukti kode:**
  - `InventoryLedgerService::recordBatchMovements` — lock terurut (baik). Namun idempotency check di **luar** transaksi (`findExistingMovement`) → TOCTOU: dua proses sama-sama lolos, lalu salah satunya melanggar `UNIQUE(idempotency_key)`. Error akhirnya aman (unique), tetapi pemanggil menerima exception, bukan hasil idempoten.
  - `TransactionService::createTransaction` — mengunci `Shift` lebih dulu, lalu `ParkedCart`, lalu `Discount`, lalu produk (`lockProductBalances`), lalu ingredient. `payPendingOrder` mengunci `Order` → `Shift`. Dua alur dengan urutan terbalik (`Shift → Order` vs `Order → Shift`) dapat deadlock.
  - `EmployeeIdGenerator` — sudah dicatat di audit sebelumnya (DB-018) sebagai risiko shared→exclusive lock.
  - `payments_idempotency_key_unique` bersifat **global** (bukan per tenant) — kemungkinan tabrakan antar-tenant jika key tidak di-prefix tenant.
- **Status bukti:** statis; tidak dieksekusi.
- **Prioritas:** **P1.**
- **Rekomendasi:** dokumentasikan satu urutan lock global (mis. `Order → Shift → Balance → Discount`); pindahkan idempotency check ke dalam transaksi setelah `INSERT` dengan `INSERT IGNORE`/catch 1062 dan kembalikan baris yang sudah ada; rescope `payments` dan `stock_movements`/`ingredient_stock_movements`/`cash_drawer_movements` idempotency key ke `(tenant_id, idempotency_key)`.

### DB-016 — Suite verifikasi database lulus secara hampa **[C]**
- **Objek:** `database/sql/verify/*.sql`, `app/Console/Commands/VerifyDatabase.php`.
- **Bukti (hasil eksekusi):** 10/10 PASS, "Database integrity is verified". Tetapi:
  - Semua tabel bisnis **0 baris** → setiap `SELECT ... WHERE <pelanggaran>` mengembalikan 0. PASS tidak membuktikan apa pun.
  - Tidak ada skrip yang memeriksa `information_schema.TRIGGERS` atau `REFERENTIAL_CONSTRAINTS`/`KEY_COLUMN_USAGE`. Karena itu DB-001 (unique partially scoped), DB-003 (trigger duplikat), DB-007 (FK hilang), DB-008 (FK single-column) **lolos tanpa peringatan**.
  - `09_indexes.sql` hanya mengecek 10 nama index, bukan keunikan/scope-nya.
  - `10_security.sql` memeriksa user aktif tanpa `pin_hash`/`password_hash`, yang mencakup owner pending-activation.
- **Dampak:** jaminan palsu; regresi skema tidak tertangkap.
- **Prioritas:** **P0** (safety net).
- **Rekomendasi:** tambahkan `11_schema_invariants.sql` (cek trigger count per event, daftar FK yang diharapkan, unique scope); hitung SKIPPED sebagai gagal (sudah ada perbaikan parsial via `--allow-skipped`); jalankan suite terhadap DB yang sudah di-seed di CI.
- **Verifikasi:** suite harus **FAIL** pada snapshot skema saat ini (karena DB-001/DB-003/DB-007/DB-008).

### DB-017 — Artefak backup eksternal tidak konsisten dengan HEAD **[C]**
- **Objek:** `../point_of_sales_pre_seed_backup_20261014_141809.sql`.
- **Bukti:**
  - Snapshot berisi **50 tabel**; skema live **117 tabel**.
  - Tabel `migrations` di backup berisi **122** baris, migration terakhir `2026_12_04_000010_harden_email_outbox_lease_and_composite_fk`.
  - 37 migration lain (`2026_12_04_000011` … `2027_01_03_000009`) dinyatakan `Ran` pada DB live tetapi tidak ada di backup.
  - Backup menggunakan `DROP TABLE IF EXISTS` + `CREATE TABLE` (destruktif bila di-restore).
- **Dampak:** restore backup akan menghasilkan skema **lebih lama** dari kode; aplikasi modul 4/5 akan error "table not found". Dataset tidak boleh dijalankan dalam audit ini (dipatuhi).
- **Prioritas:** **P1** (proses, bukan skema).
- **Rekomendasi:** tandai backup sebagai kedaluwarsa; buat prosedur backup baru yang menyertakan seluruh 117 tabel dan hash migration terakhir.

### DB-018 — Kredensial database plaintext di file yang di-commit **[C]**
- **Objek:** `phpunit.xml` baris 30 (`<env name="DB_PASSWORD" value="…"/>`).
- **Bukti:** nilai password user DB tertulis eksplisit; file `phpunit.xml` adalah tracked (bukan di `.gitignore`).
- **Dampak:** siapa pun dengan akses repo mendapat kredensial DB lokal/testing; risiko lebih besar jika password yang sama dipakai di staging. Termasuk melanggar batasan audit (tidak menampilkan kredensial — laporan ini hanya merujuk lokasinya).
- **Prioritas:** **P0** (keamanan).
- **Rekomendasi:** hapus atribut `value`, gunakan `.env.testing` yang di-gitignore atau secret CI; rotasi password; tambahkan pemindai secret di CI.

### DB-019 — `provisioning_nonces` / `employee_id_sequences` — gap tenant consistency (residual) **[R]**
- **Objek:** `employee_id_sequences` (PK `(tenant_id, prefix)`) dan `provisioning_nonces` (PK `nonce`).
- **Bukti:** `employee_id_sequences.tenant_id` tidak memiliki FK ke `tenants`; `provisioning_nonces` tidak memiliki index pada `expires_at`/`expires_at_epoch` (prune = full scan).
- **Prioritas:** **P2.**
- **Rekomendasi:** FK `(tenant_id) → tenants(id)` dengan `ON DELETE CASCADE`; index expiry.

### DB-020 — `loyalty_ledger.store_id` nullable tanpa FK store **[R]**
- **Objek:** `loyalty_ledger` (kolom `store_id` nullable, tidak ada FK).
- **Kondisi:** saldo poin per pelanggan **dipakai lintas cabang** (customer tenant-wide). `store_id` hanya atribusi; konsisten dengan desain, tetapi nilai yang tidak valid dapat tersimpan.
- **Prioritas:** **P2.** Tambah FK `(tenant_id, store_id) → stores(tenant_id, id)` (nullable) atau hapus kolom jika tidak dipakai.

### DB-021 — Tabel modul 5 (`supplier_performance_logs`, `reorder_suggestions`) tanpa FK **[C]**
- **Objek:** migration `2027_01_03_000009_create_supplier_performance_and_reorder_tables.php` (**untracked di Git**).
- **Bukti:** kedua tabel hanya memiliki INDEX `(tenant_id, store_id, …)`; tidak ada `FOREIGN KEY` ke `suppliers`, `purchase_orders`, `goods_received_notes`, `products`/`ingredients`. Model `App\Modules\Purchasing\Models\SupplierPerformanceLog` sudah ada.
- **Dampak:** orphan records, referensi lintas-tenant, dan integritas modul baru bergantung penuh pada aplikasi — bertentangan dengan standar modul 1–4.
- **Prioritas:** **P1** sebelum modul 5 dipakai.
- **Verifikasi:** query FK count per tabel = 0 saat ini; setelah perbaikan harus ≥ 4.

---

## 5. Penilaian Multi-Tenancy & Integritas Data

| Aspek | Penilaian | Bukti |
|---|---|---|
| Isolasi tenant pada transaksi inti | **Baik** | Composite FK `(tenant_id, …)` di `orders`, `order_items`, `payments`, `shifts`, `stores`, `devices`, `order_returns`, `payment_refunds` |
| Isolasi tenant pada relasi turunan | **Celah** | 13+ FK single-column (§DB-008) |
| Isolasi cabang (store) | **Celah** | `discounts` tanpa FK store; detail tidak terikat store header (DB-007, DB-009) |
| Uniqueness lintas tenant/cabang | **Celah** | `shifts_one_open_per_user_unique` global (DB-001); `payments.idempotency_key` global; `discounts.code` lintas cabang |
| Penghapusan/immutability | **Baik dengan cacat** | Banyak trigger append-only; tetapi trigger duplikat (DB-003) dan `tenant_id IS NULL` (DB-005) |
| Audit trail | **Baik** | `activity_logs` append-only + composite FK; namun actor FK di modul purchasing single-column |
| Integritas header–detail | **Sebagian** | `order_items` terikat tenant order, tetapi tidak terikat store (DB-009) |
| Ledger vs saldo | **Baik secara struktur** | `product_stock_balances` PK komposit, drift dicek `05_inventory_balances.sql` (namun pada DB kosong) |

**Perlindungan DB vs aplikasi:** invariant "satu shift terbuka" **dijamin sebagian** oleh DB (tetapi dengan scope salah). Invariant "diskon harus milik cabang yang sama" **tidak dijamin DB sama sekali**. Invariant "detail order berada di cabang yang sama dengan header" **tidak dijamin DB**. Invariant "refund ≤ payment" **tidak dijamin DB**.

---

## 6. Penilaian Transaksi Konkuren & Performa

### 6.1 Konkurensi
- Positif: isolation `REPEATABLE-READ`, `lockForUpdate` dipakai di 125 lokasi, urutan lock stok terurut, `DB::afterCommit` untuk efek samping, fencing token pada beberapa job.
- Risiko: TOCTOU idempotency di luar transaksi; urutan lock `Shift↔Order` tidak seragam; EOD settlement tanpa unique (DB-012); employee ID deadlock (residual).
- Tidak diverifikasi: uji konkurensi nyata (read-only) — lihat §10.2.

### 6.2 Performa (`EXPLAIN` pada 12 query representatif, tabel kosong)

| Query | Plan terpilih | Catatan |
|---|---|---|
| Laporan penjualan per cabang (`order_date BETWEEN`, `order_status='paid'`, `store_id`) | `orders_date_status_code_index`, range | **Baik** — tetapi index tidak memuat `store_id`, sehingga filter cabang dievaluasi setelah range |
| Laporan seluruh tenant (`tenant_id`, `order_date`) | `orders_tenant_id_id_unique` | Terpilih karena tabel kosong; pada data nyata kandidat terbaik adalah `orders_date_status_code_index` (range) atau index baru `(tenant_id, store_id, order_date, order_status)` — butuh `EXPLAIN ANALYZE` di DB ter-seed |
| Pencarian produk (`tenant_id`, `product_name LIKE '%…%'`, `ORDER BY product_name`) | ref + **filesort** | `LIKE '%x%'` + sort tanpa index nama. Kandidat: FULLTEXT atau index `(tenant_id, product_name)` untuk prefix search |
| `order_items` per order | `order_items_tenant_id_id_unique` | **Suboptimal** — `(tenant_id, id)` tidak menutupi `order_id`; kandidat `(tenant_id, store_id, order_id)` (lihat bawah) |
| `payments` per order | `payments_order_status_index` | Baik |
| KDS queue (`preparation_status`, tanpa tenant/store) | **full scan** (`type=ALL`) | Model `OrderItem` memiliki `ScopedToStore`; jika query berjalan dengan store scope, index `(tenant_id, store_id, preparation_status)` akan lebih tepat dari `(preparation_status, created_at)` |
| `product_stock_balances` per store+product | PRIMARY | **Baik** |
| Loyalty chain (`customer_id`, ORDER BY id DESC) | `loyalty_ledger_customer_chain_index` | Baik, tetapi tanpa `tenant_id` (customer_id global unik → aman) |
| Open shift (`user_id`, `status`) | `shifts_user_status_index` | Baik |
| Daftar order terbaru tenant (`tenant_id`, `ORDER BY created_at`) | ref + **filesort** | Tidak ada index `(tenant_id, created_at)`; `orders_date_status_code_index` tidak membantu sort |
| `activity_logs` by created_at | — | Index `created_at` global (bukan per tenant) → scan/sort lintas tenant |

**Catatan struktural:** `order_items` **tidak** memiliki index yang diawali `(tenant_id, order_id)`; `order_items_tenant_id_id_unique` mengurutkan `id` setelah `tenant_id`, sehingga lookup per `order_id` harus memindai seluruh `tenant_id`. `orders_tenant_id_id_unique` juga tidak `(tenant_id, created_at)`. Pada volume 50.000 order / 150.000 item (target seeder), ini menambah biaya sort dan range scan.

**Rekomendasi index (berbasis pola query, bukan dugaan):**
1. `order_items (tenant_id, store_id, order_id)` — mengganti/menambah `order_items_order_id_composite_foreign` yang diawali `tenant_id,order_id` (sudah ada; tinggal tambah `store_id`).
2. `order_items (tenant_id, store_id, preparation_status, created_at)` — untuk KDS per cabang.
3. `orders (tenant_id, store_id, created_at)` — daftar order & dashboard.
4. `orders (tenant_id, store_id, order_date, order_status)` — laporan per cabang (saat ini `orders_date_status_code_index` tanpa store).
5. `activity_logs (tenant_id, store_id, created_at)` — audit per cabang.
6. `products (tenant_id, product_name)` — bila pencarian prefix. Untuk `%LIKE%` gunakan FULLTEXT (`ngram` tidak tersedia untuk Indonesia; pertimbangkan prefix-search).

**Duplikasi index:** hanya satu ditemukan — `owner_onboarding_tokens` memiliki `onboarding_tokens_tenant_user_unique` dan `owner_onboarding_tokens_tenant_id_user_id_index` dengan kolom identik (`(tenant_id,user_id)`). Hapus yang non-unik.

---

## 7. Perbedaan Skema Aktual vs Migration

| Item | Temuan | Klasifikasi |
|---|---|---|
| Jumlah migration | 159 file = 159 rekaman, tidak ada gap/duplikat | ✅ konsisten |
| Trigger `loyalty_ledger` BEFORE DELETE | Live punya **2** (`trg_loyalty_ledger_before_delete`, `trg_loyalty_ledger_no_delete`). MariaDB menolak >1 trigger/event → `2026_12_02_000000` **tidak tereksekusi** sebagaimana ditulis, walau tercatat `Ran` | ❌ drift fungsional (DB-003) |
| Trigger `ingredient_stock_movements` BEFORE DELETE | Sama: 2 trigger | ❌ (DB-003) |
| `2026_12_01_000000_branch_ownership` | Migration ini **me-recreate seluruh FK** tabel cabang lewat regex 2 kolom + `SET foreign_key_checks=0`. Regex tidak menangkap FK 3-kolom → **17 FK `(tenant_id,store_id,x)` berisiko hilang**, lalu "dipulihkan" oleh `2026_12_04_000011` (hanya sebagian; `receipt_print_logs` & modul returning sudah ada sesudahnya). FK `ON DELETE` juga ditulis ulang: migration menambahkan `ON DELETE RESTRICT`, tetapi kondisi normal beberapa FK menjadi `NO ACTION` (mis. `orders_tenant_id_store_id_composite_foreign`) | ❌ non-idempoten + kehilangan semantik |
| `2026_11_02_000000_rescope_uniques` | 32 `try/catch` kosong → kegagalan index **tidak terdeteksi**; tidak menyentuh `shifts_one_open_per_user_unique` | ❌ menelan error |
| `2027_01_03_000001…000009` | Tabel live ada, tetapi seluruh file migration modul 5 (7 file) berstatus **untracked** (`git status`) → instalasi baru dari klon repo tidak memiliki tabel tersebut | ❌ provenance |
| Backup eksternal | 50 tabel, 122 migration; jauh di belakang HEAD | ❌ (DB-017) |
| `supplier_performance_logs` / `reorder_suggestions` | Tabel ada di live, tanpa FK | ❌ (DB-021) |

> **Kesimpulan §7:** rekaman `migrations` **tidak konsisten** dengan efek DDL yang teramati (trigger duplikat membuktikan `2026_12_02` tidak diterapkan) dan beberapa migration bersifat non-idempoten/menelan error. Rekaman `Ran` karena itu tidak boleh dijadikan satu-satunya bukti bahwa skema sesuai. Sebagian besar FK 3-kolom yang terlihat saat ini kemungkinan besar berasal dari `2026_12_04_000011` dan migration tabel modul 4 yang lebih baru, bukan dari hasil `2026_12_01`.

> **Catatan 2027_01_03_00000x:** baris tabel di atas untuk migration modul 5 tidak menyebut `2027_01_03_000009` secara terpisah; lihat DB-021 dan §7 (provenance untracked).

---

## 8. Risiko Instalasi Baru (`migrate:fresh`)

### 8.1 Urutan & ketergantungan
- `0001_01_00_000000_create_roles_table` berjalan sebelum `users`; FK `users.role_id` ditambahkan belakangan → aman.
- `2026_09_21_090000` membuat unique tanpa `tenant_id`, di-*rescue* oleh `2026_11_02` — tetapi **tidak** untuk `shifts` (DB-001).
- `2026_12_01_000000` bergantung pada `information_schema` dan asumsi "satu store per tenant" saat backfill → pada instalasi baru tanpa data, lolos; pada data lama >1 store, **abort** (by design).

### 8.2 Prediksi kegagalan pasti
1. **`2026_12_02_000000_add_immutability_triggers`** akan gagal pada MariaDB ≥ 10.2 saat `CREATE TRIGGER trg_loyalty_ledger_before_delete` karena `trg_loyalty_ledger_no_delete` sudah ada (dan logika sebaliknya). **New install blocked** kecuali trigger lama di-drop.
2. **`2026_12_01_000000`** akan menghapus lalu me-recreate FK, dan pada skema baru beberapa FK 3-kolom **hilang permanen** (regex hanya mendukung 2 kolom). Ini bersifat **silent**.
3. **`2026_11_02_000000`** akan menelan kegagalan (index `tables_tenant_id_table_code_unique` mungkin bertabrakan dengan unique baru) tanpa error.

> Tidak ada `migrate:fresh` yang dijalankan dalam audit ini. Prediksi di atas berbasis pembacaan logika migration + perilaku MariaDB 13.0.2 yang teramati di DB ini.

### 8.3 Perubahan tidak dapat di-rollback
- `2026_12_01_000000::down()` hanya menghapus FK komposit; tidak mengembalikan FK yang hilang saat `up()`, dan tidak mengembalikan `store_id` ke nullable.
- `2026_12_02::down()` menghapus trigger `*_before_delete` tetapi tidak memulihkan `*_no_delete` (jika ada yang menggantikan).
- `2026_11_02` tidak memiliki `down()` (hanya `try` drop untuk sebagian index).

---

## 9. Rekomendasi Prioritas

### P0 — Sebelum sistem dipakai lebih luas (blocker)
| # | Tindakan | Temuan |
|---|---|---|
| P0-1 | Rescope `shifts_one_open_per_user_unique` ke `(tenant_id, store_id, open_shift_lock_key)` | DB-001 |
| P0-2 | Perbaiki preimage hash `trg_loyalty_ledger_hash_chain` (sertakan pembeda unik) | DB-002 |
| P0-3 | Perbaiki `2026_12_02` (drop semua trigger lama) & verifikasi trigger di DB ini | DB-003 |
| P0-4 | Uji & perbaiki `trg_orders_before_update` agar transisi `pending→paid` legal | DB-004 |
| P0-5 | Hapus password plaintext dari `phpunit.xml`; rotasi kredensial | DB-018 |
| P0-6 | Tambahkan `11_schema_invariants.sql` (trigger count, FK expected, unique scope) ke `pos:verify-database` | DB-016 |

### P1 — Sebelum modul berikutnya / Go-Live terbatas
| # | Tindakan | Temuan |
|---|---|---|
| P1-1 | Tambah FK store pada `discounts`; putuskan scope unique kode diskon | DB-007 |
| P1-2 | Ubah 13 FK single-column pada tabel finansial/operasional menjadi composite `(tenant_id, …)` | DB-008 |
| P1-3 | Tambah `UNIQUE(tenant_id, store_id, id)` + FK 3-kolom header↔detail (`orders`→`order_items`,`payments`,`stock_movements`) | DB-009 |
| P1-4 | `UNIQUE(tenant_id, store_id, business_date)` pada `eod_settlements` + lock | DB-012 |
| P1-5 | Guard over-refund pada `payment_refunds` + unique retur per item | DB-013 |
| P1-6 | Seragamkan urutan lock; pindahkan idempotency check ke dalam transaksi; rescope idempotency key per tenant | DB-015 |
| P1-7 | Perbaiki migration `2026_12_01` (regex 3-kolom, tanpa `foreign_key_checks=0`, pertahankan `ON DELETE`) | §7 |
| P1-8 | Track migration modul 5 & lengkapi FK `supplier_performance_logs`/`reorder_suggestions` | DB-021 |
| P1-9 | Perbaiki `trg_loyalty_ledger_no_update` (`tenant_id IS NULL`) | DB-005 |
| P1-10 | Ganti backup eksternal; dokumentasikan sebagai kedaluwarsa | DB-017 |

### P2 — Dapat dijadwalkan
| # | Tindakan | Temuan |
|---|---|---|
| P2-1 | Seragamkan tipe uang; hilangkan `float` moneter di service | DB-011 |
| P2-2 | Ubah mirror stok menjadi `DECIMAL(18,6)` atau VIEW | DB-010 |
| P2-3 | Index tambahan (§6.2) + hapus index duplikat token | §6 |
| P2-4 | `CHECK (payments.amount > 0)` + guard store pada overpayment | DB-006 |
| P2-5 | FK `(tenant_id, store_id)` pada `loyalty_ledger` | DB-020 |
| P2-6 | FK tenant pada `employee_id_sequences`, index expiry nonce | DB-019 |
| P2-7 | Command verifikasi hash-chain | DB-014 |

---

## 10. Strategi Pengujian & Verifikasi

### 10.1 Prinsip
Semua uji di bawah **wajib dijalankan pada `yovel_pos_test` atau container MySQL sekali-pakai**, bukan pada `point_of_sales`. Gunakan `migrate:fresh` di DB terpisah; jangan pernah di DB lokal yang berisi data.

### 10.2 Skenario uji terisolasi
| ID | Skenario | Kriteria lulus |
|---|---|---|
| T-1 | Checkout `pending→paid` (tunai & non-tunai) | Trigger mengizinkan; `order_status='paid'`, `payments` tercatat |
| T-2 | Dua tenant, user `id` sama, buka shift bersamaan | Keduanya sukses tanpa error 1062 |
| T-3 | Dua entry loyalty identik dalam satu detik | Keduanya tersimpan dengan hash berbeda |
| T-4 | Insert `loyalty_ledger` lalu `UPDATE points` | UPDATE ditolak dengan pesan append-only; `UPDATE tenant_id=tenant_id` sukses |
| T-5 | `migrate:fresh` pada container MySQL | Selesai tanpa error; 0 trigger duplikat; jumlah FK sama dengan daftar acuan |
| T-6 | Konkurensi: 20 checkout paralel satu produk stok 5 | Tidak ada stok negatif, tidak ada deadlock |
| T-7 | Konkurensi EOD settlement | Satu settlement per tanggal |
| T-8 | Retur/refund dua kali untuk item yang sama melebihi nilai | Ditolak |
| T-9 | `EXPLAIN` setelah seeder pada `yovel_pos_test` | Query laporan & KDS memakai index yang direkomendasikan (tanpa full scan) |
| T-10 | `pos:verify-database` pada DB ter-seed | Harus PASS pada skema yang sudah diperbaiki, FAIL pada skema saat ini |

### 10.3 Alat yang sudah tersedia
- `database/sql/audit/order_total_reconciliation.sql`, `shift_overlap_audit.sql`
- `database/sql/verify/01–10`
- `tests/Feature` (belum dijalankan dalam audit ini)

---

## 11. Hal yang Belum Dapat Dipastikan

1. **Perilaku runtime** seluruh temuan pada data nyata (DB kosong). DB-004 khususnya: struktur pasti, dampak empiris belum dibuktikan.
2. **Skema staging/produksi** — bisa jadi berbeda dari lokal; drift migration→DB ini hanya terbukti untuk instance lokal.
3. **Race condition aktual** — semua analisis konkurensi bersifat statis.
4. **Konsistensi FK yang hilang** — tidak dapat dibuktikan tanpa menjalankan `migrate:fresh` di lingkungan sekali-pakai.
5. **Pemakaian `products.stock`/`ingredients.current_stock`** di luar jalur ledger (perlu trace fitur dashboard/inventori).
6. **Kebijakan bisnis** untuk `shifts` (per kasir per cabang vs per tenant) dan `discounts.code` (global tenant vs per cabang) — menentukan bentuk constraint yang benar.
7. **Job verifikasi hash-chain** tidak ditemukan; klaim docblock tidak terpenuhi.
8. **Isi `point_of_sales_pre_seed_backup_20261014_141809.sql`** tidak dipulihkan/dijalankan (dilarang).

---

## 12. Kesimpulan Kesiapan Database

### Status: **`AUDIT COMPLETE — REMEDIATION REQUIRED`**

**Alasan:** audit selesai dengan akses penuh ke skema aktual MySQL/MariaDB, dan ditemukan **enam temuan P0** yang harus diperbaiki sebelum sistem dipakai lebih luas (isolasi tenant pada shift, cacat hash-chain loyalty, trigger duplikat yang memblokir instalasi baru, trigger order yang menghalangi transisi `pending→paid`, kredensial plaintext, dan suite verifikasi yang lulus secara hampa).

**Yang sudah kuat dan perlu dipertahankan:**
- Arsitektur ownership 3-tier yang benar-benar diterapkan lewat composite FK di jalur POS inti.
- Trigger append-only dan idempotency key yang menunjukkan disiplin integritas di atas rata-rata.
- Pemisahan database testing/lokal yang jelas dan grant user yang terbatas.

**Risiko yang diterima bila tidak diperbaiki:**
- Kegagalan instalasi baru secara pasti (`2026_12_02`).
- Cross-tenant denial-of-service pada shift.
- Kegagalan checkout/loyalty sporadis yang sulit direproduksi.
- Referensi lintas tenant melalui FK single-column yang tidak terdeteksi oleh tooling proyek.

> Status ini menyatakan audit telah selesai dan **wajib remediasi**; bukan pernyataan bahwa database bebas risiko. Rekomendasi belum diterapkan — tidak ada perubahan skema, migration, kode, index, atau data yang dilakukan dalam audit ini.
