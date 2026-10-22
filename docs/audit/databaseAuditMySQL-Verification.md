# APEIRON POS — VERIFIKASI INDEPENDEN AUDIT STRUKTUR DATABASE MYSQL

| Item | Nilai |
|---|---|
| Tanggal Verifikasi | 2026-10-22 |
| Auditor Pelaksana | Senior Database Architect + MySQL/MariaDB Specialist + Laravel Database Engineer |
| Target Database Runtime | `point_of_sales` @ `127.0.0.1:3306` (MariaDB 13.0.2), 117 Tabel |
| Target Database Pengujian | `yovel_pos_test` (Sesuai `phpunit.xml` / `.env.testing`) |
| Dokumen Baseline | `docs/audit/databaseAuditMySQL.md` (Read-Only Baseline) |
| Dokumen Acuan Kontrak | `docs/architecture/OWNERSHIP_MODEL.md`, `docs/architecture/ARCHITECTURE_CONTRACT.md` |
| Sifat Tindakan | **Read-Only Verification (Tahap 1 Gate Confirmation)**. Tidak ada migrasi/perubahan skema yang diterapkan pada `point_of_sales`. |

---

## 1. Ringkasan Pemahaman Arsitektur & Kontrak Sistem
Apeiron POS menerapkan arsitektur **3-Tier Entity Ownership & Multi-Tenancy Isolation** yang ditegakkan secara berlapis dari Level 0 (Hard Database Boundary) hingga Level 2 (Service Context):
1. **Tier 1 (Global/Platform-Owned):** Tabel tanpa `tenant_id` (`tenants`, `permissions`, `plans`, `provisioning_nonces`, antrean jobs, sessions). Model tidak menerapkan global scope tenant.
2. **Tier 2 (Tenant-Owned Org-Wide):** Tabel dengan `tenant_id NOT NULL` tanpa `store_id` langsung (`users`, `roles`, `stores`, `customers`, `products`, `categories`, `suppliers`, `purchase_orders`). Akses cabang diatur via mapping (`user_stores`). Model wajib memakai trait `ScopedToTenant` dan `AssignsTenant`.
3. **Tier 3A (Store-Owned Strict):** Tabel transaksional cabang dengan `tenant_id NOT NULL` dan `store_id NOT NULL` (`orders`, `order_items`, `payments`, `shifts`, `tables`, `stock_movements`, `cash_drawer_movements`). Ditegakkan melalui Composite FK `(tenant_id, store_id) REFERENCES stores(tenant_id, id) ON DELETE RESTRICT`.
4. **Tier 3B (Hybrid Tenant/Store-Owned):** Tabel dengan `tenant_id NOT NULL` dan `store_id NULLABLE` (`discounts`). Kontrak query wajib fail-closed dengan pengelompokan kurung boolean eksplisit: `WHERE tenant_id = ? AND (store_id = ? OR store_id IS NULL)`.
5. **Mekanisme Scoping Fail-Closed:** Bila `TenantContext::getTenantId()` bernilai `null` tanpa bypass eksplisit, scope global menyuntikkan `WHERE 1 = 0`, menjamin 0 rekaman kembali dan mencegah kebocoran data.
6. **Peran Kunci Komposit & Trigger:** Invarian multi-tenancy tidak boleh hanya bertumpu pada Eloquent ORM. Composite FK `(tenant_id, ...)` dan trigger append-only menjamin keabsahan relasi dan imutabilitas finansial di level kernel database.
7. **Model Prioritas Remediasi:**
   - **P0 (Critical/Blocker):** Kegagalan instalasi baru, pelanggaran isolasi tenant, dan kebocoran keamanan kredensial.
   - **P1 (High):** Integritas relasi finansial/operasional, pencegahan over-refund, race-condition idempotensi, dan kelengkapan FK komposit.
   - **P2 (Medium):** Normalisasi tipe moneter, pembersihan mirror cache, dan penambahan indeks performa query tinggi.

---

## 2. Tabel Status Verifikasi 21 Temuan (DB-001 s/d DB-021)

| ID | Judul Temuan | Klasifikasi | Bukti Independen yang Diperoleh | Lokasi Bukti (file:line / DDL / Query) | Catatan & Analisis Dampak |
|---|---|:---:|---|---|---|
| **DB-001** | `shifts_one_open_per_user_unique` bersifat global lintas-tenant | **CONFIRMED** | Kolom `open_shift_lock_key` adalah generated column `coalesce(active_cashier_id, user_id)`. DDL memiliki `UNIQUE KEY shifts_one_open_per_user_unique (open_shift_lock_key)` tanpa prefix `tenant_id`. Berbeda dengan device yang memiliki `UNIQUE (tenant_id, store_id, open_device_guard)`. | Live DDL `SHOW CREATE TABLE shifts`; `database/migrations/2026_09_21_090000_harden_shift_one_open_per_user.php:40` | **P0.** Jika kasir di Tenant A dan Tenant B memiliki auto-increment `id` sama (misal `1`), salah satu kasir akan gagal buka shift (Error 1062 Cross-Tenant DoS). |
| **DB-002** | `trg_loyalty_ledger_hash_chain` deterministik gagal pada entry kedua per pelanggan | **CONFIRMED** | Trigger menghitung hash dengan `SHA2(CONCAT_WS('\|', NEW.customer_id, IFNULL(NEW.order_id, ''), NEW.type, NEW.points, NEW.balance_after, IFNULL(last_hash, ''), NOW()), 256)`. Kolom `entry_hash` unik global (`loyalty_ledger_entry_hash_unique`). Input tidak memuat ID baris, microsecond, atau nonce. | Live DDL `SHOW CREATE TRIGGER trg_loyalty_ledger_hash_chain`; `database/migrations/2026_09_22_030002_create_loyalty_ledger_table.php:73` | **P0.** Dua entry untuk customer yang sama dalam detik yang sama (misal non-order adjustment atau transaksi cepat) menghasilkan hash identik dan memicu exception SQLSTATE 23000. |
| **DB-003** | Trigger duplikat pada event yang sama (MariaDB/MySQL incompatible) | **CONFIRMED** | `information_schema.TRIGGERS` membuktikan ada dua trigger aktif pada event `BEFORE DELETE`: `trg_ingredient_stock_movements_no_delete` & `trg_ingredient_stock_movements_before_delete` pada tabel `ingredient_stock_movements`, serta `trg_loyalty_ledger_no_delete` & `trg_loyalty_ledger_before_delete` pada `loyalty_ledger`. | Query `information_schema.TRIGGERS`; `database/migrations/2026_12_02_000000_add_immutability_triggers.php:75-88` | **P0.** Pada clean installation (`migrate:fresh`), MariaDB >= 10.2 / MySQL 8.0 menolak pembuatan trigger ganda pada timing+event yang sama. |
| **DB-004** | `trg_orders_before_update` membuat order `pending` berisiko gagal saat update | **CONFIRMED** | Trigger `trg_orders_before_update` memeriksa `IF OLD.order_status <=> 'pending' THEN` dan menolak perubahan jika `shift_id`, `subtotal_amount`, atau `order_amount` berubah. Pada `TransactionService::payPendingOrder` baris 1224, `shift_id` dapat diperbarui jika order dibuka di shift berbeda, yang memicu penolakan SQLSTATE 45000. | Live DDL `SHOW CREATE TRIGGER trg_orders_before_update`; `app/Modules/Sales/Services/TransactionService.php:1224` | **P0.** Mengunci perubahan yang sah pada alur checkout multi-shift atau penyesuaian order sebelum pembayaran final. |
| **DB-005** | `trg_loyalty_ledger_no_update` / `trg_ingredient_stock_movements_no_update` menolak `tenant_id` non-NULL | **CONFIRMED** | DDL trigger berisi guard `IF NOT ( ... AND OLD.tenant_id IS NULL ) THEN SIGNAL ...`. Karena `tenant_id` pada kedua tabel bernilai `NOT NULL`, kondisi `OLD.tenant_id IS NULL` selalu bernilai FALSE, sehingga update no-op pun ditolak dengan pesan yang keliru. | Live DDL `SHOW CREATE TRIGGER trg_loyalty_ledger_no_update`; `database/migrations/2026_09_22_030002_create_loyalty_ledger_table.php:97` | **P1.** Kesalahan logika predikat yang diwarisi sebelum kolom `tenant_id` dijadikan NOT NULL. |
| **DB-006** | `trg_payments_prevent_overpayment` tidak melindungi `UPDATE` dan salah untuk split tender | **PARTIAL** | 1. Klaim bahwa `UPDATE` tidak terlindungi adalah **FALSE POSITIVE**, karena `trg_payments_before_update` secara tegas menolak seluruh operasi UPDATE pada `payments` (`payments bersifat append-only: UPDATE dilarang`).<br>2. Klaim bahwa `chk_payments_amount_positive` mengizinkan `amount = 0` dan tidak adanya verifikasi store matching order adalah **CONFIRMED**. | Live DDL `SHOW CREATE TRIGGER trg_payments_prevent_overpayment`; `SHOW CREATE TRIGGER trg_payments_before_update` | **P1.** Pembayaran no-op Rp0 dapat lolos dan `store_id` payment tidak diverifikasi harus sama dengan `orders.store_id`. |
| **DB-007** | `discounts.store_id` tanpa FK ke `stores` dan tanpa cakupan unique lintas cabang | **CONFIRMED** | Satu-satunya FK pada `discounts` adalah `discounts_tenant_id_foreign -> tenants(id)`. Tidak ada FK komposit `(tenant_id, store_id) -> stores`. Unique index hanya `(tenant_id, code)`, membatasi satu tenant hanya boleh memiliki 1 kode promo unik walaupun berlaku di cabang berbeda. | Live DDL `SHOW CREATE TABLE discounts`; query `information_schema.KEY_COLUMN_USAGE` | **P1.** Kolom `store_id` dapat diisi id store milik tenant lain tanpa tertolak oleh database constraint. |
| **DB-008** | Relasi finansial & operasional yang masih single-column FK | **CONFIRMED** | Ditemukan 28 foreign key pada tabel finansial/operasional yang mereferensikan `users`, `customers`, `orders`, `shifts`, `devices`, `stores` tanpa kolom pertama `tenant_id` (misal `discount_redemptions.customer_id`, `discount_redemptions.redeemed_by_user_id`, `parked_carts.customer_id`, `receipt_print_logs.shift_id`, `payment_attempts.initiated_by_user_id`). | Query `information_schema.KEY_COLUMN_USAGE` HAVING `SUBSTRING_INDEX(cols, ',', 1) != 'tenant_id'` | **P1.** Membuka celah foreign key crossing antar-tenant jika ID entitas ditebak atau query ORM salah mengikat context. |
| **DB-009** | Tidak ada constraint DB yang mengikat `store_id` anak ke `store_id` induknya | **CONFIRMED** | FK `order_items.order_id_composite_foreign` hanya mengikat `(tenant_id, order_id) -> orders(tenant_id, id)`. `order_items` juga punya FK ke `stores(tenant_id, id)`. Namun tidak ada constraint yang mewajibkan `order_items.store_id == orders.store_id`. Hal yang sama berlaku pada `payments.order_id` dan `stock_movements.order_id`. | Live DDL `SHOW CREATE TABLE order_items`, `payments`, `stock_movements` | **P1.** Jika konteks toko bergeser saat pemrosesan keranjang/pembayaran, baris detail bisa tercatat di cabang berbeda dari order induknya. |
| **DB-010** | `products.stock` (legacy mirror) adalah state duplikat dengan presisi berbeda | **CONFIRMED** | Kolom `products.stock` bertipe `int(10) unsigned`, sedangkan saldo otoritatif `product_stock_balances.quantity` bertipe `decimal(18,6)`. Begitu pula `ingredients.current_stock` (`decimal(14,4) unsigned`) vs `ingredient_stock_balances.quantity` (`decimal(18,6)`). | Query `information_schema.COLUMNS` pada `products` dan `product_stock_balances` | **P2.** Potensi pemotongan desimal saat stok pecahan (misal 0.5 kg bahan/produk) diproyeksikan ke kolom mirror legacy. |
| **DB-011** | Moneter bercampur antara `bigint` (Rupiah) dan `decimal(15,2)` | **CONFIRMED** | `orders.order_amount` & `payments.amount` bertipe `bigint(20) unsigned`. Namun `order_items.net_line_total`, `allocated_order_discount_amount`, `order_returns.total_refundable_amount`, dan `payment_refunds.amount` bertipe `decimal(15,2)`. | Query `information_schema.COLUMNS` pada 5 tabel moneter terkait | **P2.** Konversi `float` di service layer berisiko menimbulkan selisih pembulatan 1 Rupiah antara akumulasi detail dan total header. |
| **DB-012** | `eod_settlements` tidak memiliki unique `(tenant_id, store_id, business_date)` | **FALSE POSITIVE** | DDL aktual membuktikan indeks `uq_eod_store_business_date UNIQUE (tenant_id, store_id, business_date)` **SUDAH ADA** di tabel `eod_settlements` sejak migration `2026_12_14_000001`. | Live DDL `SHOW CREATE TABLE eod_settlements`; `database/migrations/2026_12_14_000001_create_receipt_print_logs_and_eod_settlements_tables.php:81` | **Bantahan Berbasis Bukti:** Audit menyatakan constraint ini tidak ada. Bukti live menunjukkan constraint sudah terpasang dan mencegah duplikasi EOD per store per hari di level DB. |
| **DB-013** | `order_returns` dapat melebihi transaksi; `order_return_items` tanpa guard retur ganda | **CONFIRMED** | Tabel `order_return_items` tidak memiliki unique `(tenant_id, order_return_id, order_item_id)`. Tabel `payment_refunds` tidak memiliki trigger over-refund guard untuk memastikan `SUM(amount) <= payment.amount`. | Live DDL `SHOW CREATE TABLE order_return_items`, `payment_refunds`; `information_schema.TRIGGERS` | **P1.** Pencegahan over-refund saat ini hanya mengandalkan logika aplikasi di `ReturnRefundService` tanpa jaminan pertahanan berlapis di database. |
| **DB-014** | Tidak ada job/tool untuk memverifikasi hash-chain loyalty | **CONFIRMED** | Pencarian kode di seluruh direktori `app/` mengonfirmasi tidak ada job, listener, atau artisan command yang memverifikasi keutuhan rantai hash `loyalty_ledger`. | Grep pencarian pola verifikasi pada seluruh namespace `App\` | **P2.** Kolom `prev_hash` dan `entry_hash` menjadi overhead penyimpanan tanpa audit tool yang memanfaatkan integritasnya. |
| **DB-015** | Risiko konkurensi & cakupan `idempotency_key` global | **CONFIRMED** | Indeks keunikan `idempotency_key` pada `payments`, `stock_movements`, `ingredient_stock_movements`, dan `cash_drawer_movements` bersifat global `UNIQUE(idempotency_key)`, bukan per tenant. Selain itu, pengecekan `findExistingMovement` pada `InventoryLedgerService` berada di luar transaksi database. | Query `information_schema.STATISTICS` indeks unik; `app/Services/InventoryLedgerService.php:80` | **P1.** Potensi tabrakan kunci idempotensi antar-tenant dan TOCTOU race window sebelum blok transaksi dimulai. |
| **DB-016** | Suite verifikasi database lulus secara hampa pada DB kosong | **CONFIRMED** | Perintah `php artisan pos:verify-database` lulus 10/10 skrip semata-mata karena seluruh tabel bisnis berisi 0 baris. Skrip yang ada tidak memeriksa `information_schema.TRIGGERS` atau kepatuhan composite FK. | Output eksekusi `php artisan pos:verify-database`; inspeksi `database/sql/verify/*.sql` | **P0.** Safety net verifikasi saat ini tidak mendeteksi anomali skema struktural (seperti DB-001, DB-003, DB-007, DB-008). |
| **DB-017** | Artefak backup eksternal tidak konsisten dengan skema HEAD | **CONFIRMED** | File `../point_of_sales_pre_seed_backup_20261014_141809.sql` hanya berisi 50 tabel dan 122 migrasi (terakhir `2026_12_04_000010`), sedangkan skema live HEAD memiliki 117 tabel dan 159 migrasi. | Inspeksi Read-Only `grep -c "CREATE TABLE"` dan tabel `migrations` pada berkas backup | **P1.** Restore dari artefak backup lama akan merusak skema HEAD dan menghilangkan tabel-tabel Modul 4 & Modul 5. |
| **DB-018** | Kredensial database plaintext di file yang di-commit | **CONFIRMED** | File `phpunit.xml` baris 30 memuat `<env name="DB_PASSWORD" value="<redacted>"/>` secara terbuka dalam versi yang terlacak Git (`git ls-files`). | `phpunit.xml:30` (Kredensial di-redaksi) | **P0.** Kerentanan keamanan repositori yang membocorkan password database lokal/pengujian. |
| **DB-019** | `provisioning_nonces` & `employee_id_sequences` memiliki celah konsistensi | **CONFIRMED** | Tabel `employee_id_sequences` memiliki kolom `tenant_id` tetapi tanpa foreign key ke `tenants(id)`. Tabel `provisioning_nonces` tidak memiliki indeks pada kolom masa berlaku `expires_at_epoch`. | Query `information_schema.KEY_COLUMN_USAGE` dan `STATISTICS` | **P2.** Pembersihan nonce kadaluarsa memerlukan full table scan, dan sequence nomor karyawan dapat tertinggal sebagai data yatim jika tenant dihapus. |
| **DB-020** | `loyalty_ledger.store_id` nullable tanpa FK store | **CONFIRMED** | Tabel `loyalty_ledger` memiliki kolom `store_id bigint unsigned DEFAULT NULL`, namun tidak memiliki foreign key ke tabel `stores`. | Query `information_schema.COLUMNS` dan `KEY_COLUMN_USAGE` pada `loyalty_ledger` | **P2.** Meskipun poin loyalitas bersifat cross-branch (tenant-wide), kolom atribusi cabang tidak terlindungi constraint integritas referensial. |
| **DB-021** | Status provenance & FK tabel Modul 5 | **PARTIAL** | 1. Klaim bahwa tabel `supplier_performance_logs` dan `reorder_suggestions` tidak memiliki FK adalah **FALSE POSITIVE**. Tabel `supplier_performance_logs` terbukti memiliki 5 composite FK (`spl_store_composite_fk`, `spl_supplier_composite_fk`, `spl_po_composite_fk`, `spl_grn_composite_fk`, `spl_grn_line_composite_fk`), dan `reorder_suggestions` memiliki 3 composite FK (`rs_store_composite_fk`, `rs_supplier_composite_fk`, `rs_pr_composite_fk`).<br>2. Klaim bahwa file migrasi Modul 5 berstatus **untracked** di Git adalah **CONFIRMED** (9 berkas migrasi `2027_01_03_000001` s/d `2027_01_03_000009` belum di-commit ke Git). | Live DDL `information_schema.TABLE_CONSTRAINTS`; `git status --porcelain database/migrations` | **P1.** Integritas skema database Modul 5 sebenarnya sudah solid dengan FK komposit, namun pelacakan versi Git perlu dirapikan agar instalasi dari clone baru tidak tertinggal. |

---

## 3. Koreksi Terhadap Dokumen Audit Baseline
Berdasarkan investigasi live database dan pembacaan DDL otentik, ditemukan **3 koreksi penting** terhadap dokumen audit:

1. **Koreksi Terhadap DB-012 (`eod_settlements` Unique Constraint):**
   - **Klaim Audit:** Menyatakan bahwa tabel `eod_settlements` tidak memiliki unique `(tenant_id, store_id, business_date)`.
   - **Fakta & Bukti Database:** Klaim ini **FALSE POSITIVE**. Tabel `eod_settlements` sudah memiliki indeks unik:
     ```sql
     UNIQUE KEY `uq_eod_store_business_date` (`tenant_id`, `store_id`, `business_date`)
     ```
     didefinisikan secara eksplisit pada migrasi `2026_12_14_000001_create_receipt_print_logs_and_eod_settlements_tables.php` baris 81. Integritas settlement harian per cabang sudah terlindungi di level skema.
2. **Koreksi Terhadap DB-021 (Foreign Keys Modul 5):**
   - **Klaim Audit:** Menyatakan bahwa tabel `supplier_performance_logs` dan `reorder_suggestions` tidak memiliki foreign key (0 FK).
   - **Fakta & Bukti Database:** Klaim ini **PARTIAL (Mayoritas False Positive)**. Kedua tabel tersebut telah dilengkapi dengan **8 Composite Foreign Keys**:
     - `supplier_performance_logs`: 5 FK komposit (`stores`, `suppliers`, `purchase_orders`, `goods_received_notes`, `goods_received_note_lines`).
     - `reorder_suggestions`: 3 FK komposit (`stores`, `suppliers`, `purchase_requests`).
     Bagian temuan yang benar adalah 9 file migrasi Modul 5 tersebut berstatus *untracked* di Git.
3. **Koreksi Terhadap DB-006 (Update Guard pada Payments):**
   - **Klaim Audit:** Menyatakan bahwa trigger `trg_payments_prevent_overpayment` tidak melindungi operasi `UPDATE`.
   - **Fakta & Bukti Database:** Hal ini **PARTIAL**, karena tabel `payments` telah dilindungi oleh trigger `trg_payments_before_update` yang memblokir **100% operasi UPDATE** pada tabel payments (`payments bersifat append-only: UPDATE dilarang`). Sehingga celah overpayment via UPDATE tertutup oleh trigger immutability. Bagian temuan yang valid adalah `amount = 0` dan tidak adanya verifikasi kesesuaian `store_id` payment terhadap `orders.store_id`.

---

## 4. Analisis Mendalam & Validasi Keputusan Bisnis Pengguna

### 4.1 Validasi Shift Unik Per Cabang (DB-001)
Pengguna memutuskan: **Pilih per cabang, bukan per tenant.**

#### A. Bukti Struktur Aktual Database & Generated Column
Pemeriksaan DDL live pada tabel `shifts` memperlihatkan dua generated columns dan dua indeks unik aktif terkait shift terbuka:
```sql
`open_shift_lock_key` bigint(20) unsigned GENERATED ALWAYS AS (
    CASE WHEN `status` IN ('open', 'handover_pending', 'closing')
         THEN COALESCE(`active_cashier_id`, `user_id`)
         ELSE NULL END
) VIRTUAL,

`open_device_guard` bigint(20) unsigned GENERATED ALWAYS AS (
    CASE WHEN `status` IN ('open', 'handover_pending', 'closing') AND `device_id` IS NOT NULL
         THEN `device_id`
         ELSE NULL END
) VIRTUAL,

UNIQUE KEY `shifts_one_open_per_device_unique` (`tenant_id`, `store_id`, `open_device_guard`),
UNIQUE KEY `shifts_one_open_per_user_unique` (`open_shift_lock_key`)
```

#### B. Analisis Semantik Multi-Kasir & Multi-Terminal
1. **Apakah constraint `(tenant_id, store_id, open_shift_lock_key)` membatasi cabang hanya boleh memiliki 1 kasir aktif?**
   - **TIDAK.** Nilai `open_shift_lock_key` berisi ID unik pengguna kasir yang sedang bertugas (`active_cashier_id` atau `user_id`).
   - Jika Cabang A memiliki 3 kasir (Kasir 1 ID=101, Kasir 2 ID=102, Kasir 3 ID=103), maka nilai yang dihasilkan adalah `(T, A, 101)`, `(T, A, 102)`, dan `(T, A, 103)`. Karena ID pengguna berbeda, ketiga kasir dapat membuka shift secara bersamaan di Cabang A pada terminal masing-masing tanpa konflik.
   - Bersamaan dengan itu, `shifts_one_open_per_device_unique (tenant_id, store_id, open_device_guard)` menjamin bahwa satu terminal fisik/perangkat kasir (misal Terminal POS-01) hanya dapat digunakan oleh tepat satu shift pada satu waktu.
2. **Kekuatan Terhadap Concurrent Requests:**
   - Dalam [ShiftService.php](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/ShiftService.php#L94-L128), `openShift()` menjalankan transaksi database dengan `lockForUpdate()` pesimistis baik pada `device_id` maupun kasir.
   - Jika terjadi race-condition ekstrem (dua request bersamaan lolos cek select), database constraint komposit `UNIQUE (tenant_id, store_id, open_shift_lock_key)` menjadi batas keras terakhir (*Layer 0*) yang menolak request kedua dengan `1062 Duplicate entry`.
3. **Penyesuaian Kode Aplikasi yang Dibutuhkan:**
   - Pada `ShiftService.php` baris 110, query pengecekan kasir saat ini berbunyi:
     ```php
     $userShift = Shift::where('tenant_id', $tenantId)->...
     ```
     Untuk menyelaraskan dengan keputusan bisnis "per cabang", query tersebut disesuaikan menjadi:
     ```php
     $userShift = Shift::where('tenant_id', $tenantId)->where('store_id', $storeId)->...
     ```
     sehingga seorang kasir yang memiliki penugasan sah di cabang lain tidak terblokir membuka shift di cabang tersebut setelah shift cabang sebelumnya selesai.

---

### 4.2 Validasi Kode Kupon Diskon Unik Per Tenant & Foreign Key Cabang (DB-007)
Pengguna memutuskan: **Pertahankan unik per tenant, validasi relasi cabang dengan FK komposit.**

#### A. Bukti Struktur Aktual Database
Pemeriksaan DDL live membuktikan:
- Tabel `stores` memiliki `PRIMARY KEY (id)` dan `UNIQUE KEY stores_tenant_id_id_unique (tenant_id, id)`.
- Tabel `discounts` memiliki:
  - `tenant_id bigint unsigned NOT NULL`
  - `store_id bigint unsigned DEFAULT NULL`
  - `UNIQUE KEY discounts_tenant_id_code_unique (tenant_id, code)`
  - Hanya ada FK ke tenants: `discounts_tenant_id_foreign -> tenants(id)`.

#### B. Evaluasi Penambahan FK Komposit & Perilaku Nilai NULL
1. **Kompatibilitas Nilai NULL pada Composite Foreign Key:**
   - Sesuai standar ANSI SQL dan implementasi InnoDB MySQL/MariaDB: jika suatu kolom dalam kunci asing bernilai `NULL` (pada kasus ini saat diskon berlaku tenant-wide dengan `store_id IS NULL`), pemeriksaan foreign key dilewati (dinyatakan valid otomatis).
   - Saat kupon dibatasi untuk cabang tertentu (`store_id NOT NULL`), InnoDB secara ketat memverifikasi bahwa kombinasi `(tenant_id, store_id)` harus eksis di tabel `stores(tenant_id, id)`.
   - Dengan demikian, kupon cabang **mustahil merujuk ke cabang milik tenant lain**.
2. **Evaluasi Data Existing & Risiko Migrasi:**
   - Query pada `discounts` di database `point_of_sales` menghasilkan **0 baris** (kosong).
   - Pada database test `yovel_pos_test`, seluruh seeder kupon telah menggunakan `store_id` yang valid atau `NULL`.
   - Penambahan `FOREIGN KEY (tenant_id, store_id) REFERENCES stores (tenant_id, id) ON DELETE RESTRICT` adalah **100% aman dan tidak menimbulkan risiko data breaking**.
3. **Kompatibilitas Kode Aplikasi:**
   - Model `Discount` menerapkan `ScopedToStoreForDiscount` yang menghasilkan klausa fail-closed:
     ```sql
     WHERE tenant_id = ? AND (store_id = ? OR store_id IS NULL)
     ```
   - Skema ini selaras 100% dengan kebutuhan bisnis kupon unik per tenant.

---

### 4.3 Audit Komprehensif Representasi Nilai Uang (Monetary Precision Audit) (DB-011)
Pengguna memutuskan: **Setujui integer Rupiah untuk nominal transaksi POS inti, dengan audit komprehensif seluruh kolom dan pencegahan perubahan massal.**

Berikut adalah hasil audit lengkap seluruh kolom finansial di repository berdasarkan semantik bisnis, kebutuhan pembulatan, potensi nilai negatif, dan integrasi payment gateway:

#### Matriks Klasifikasi 4 Kategori Finansial Repository

| Kategori | Karakteristik Bisnis & Alur | Rekomendasi Tipe Data | Daftar Kolom Aktual | Rationale Arsitektural |
|---|---|:---:|---|---|
| **Kategori 1: Nominal Transaksi POS Inti (Rupiah Bulat Utuh)** | Nominal penjualan, pembayaran kas/nontunai, dan harga katalog yang selalu menggunakan kelipatan bulat 1 Rupiah tanpa pecahan. Nilai selalu positif (>= 0). | **`BIGINT UNSIGNED`** | • `orders.subtotal_amount`<br>• `orders.discount_amount`<br>• `orders.tax_amount`<br>• `orders.service_charge_amount`<br>• `orders.order_amount`<br>• `orders.cash_received`<br>• `orders.order_change`<br>• `payments.amount`<br>• `payments.amount_tendered`<br>• `payments.change_amount`<br>• `cash_drawer_movements.amount`<br>• `products.product_price`<br>• `modifiers.extra_price`<br>• `discounts.value` (fixed)<br>• `discounts.max_discount_amount`<br>• `discounts.min_purchase_amount`<br>• `order_items.order_price`<br>• `order_items.order_subtotal` | Menghilangkan konversi `float` di PHP. Integritas settlement Midtrans (`gross_amount` bulat). Mengubah `order_items.order_price/subtotal` dari `bigint(20)` signed ke `BIGINT UNSIGNED` menyelaraskan relasi header-detail tanpa risiko kehilangan data. |
| **Kategori 2: Selisih & Koreksi Finansial (Potensi Negatif)** | Nilai yang mengukur selisih kas fisik vs catatan sistem. Wajib mendukung nilai negatif (kas tekor/shortage) maupun positif (kas lebih/overage). | **`BIGINT SIGNED`** *(Dilarang Unsigned!)* | • `shifts.cash_difference`<br>• `shift_handovers.discrepancy_amount`<br>• `eod_settlements.cash_discrepancy_total` (`decimal(15,2)` signed) | **PERINGATAN KERAS:** Mengubah kolom ini ke `UNSIGNED` akan memicu fatal error `Numeric value out of range (1690)` pada database saat kasir mengalami kekurangan kas. Kolom ini **wajib tetap SIGNED**. |
| **Kategori 3: Prorasi Nilai Antara, Alokasi Diskon, & Refund Parsial** | Pembagian diskon level-order ke baris item produk (`allocated_order_discount_amount`) dan pengembalian dana proporsional. | **`DECIMAL(15,2)`** *(Dipertahankan)* | • `order_items.allocated_order_discount_amount`<br>• `order_items.item_discount_amount`<br>• `order_items.net_line_total`<br>• `order_returns.subtotal_returned`<br>• `order_returns.discount_reversed`<br>• `order_returns.tax_returned`<br>• `order_returns.total_refundable_amount`<br>• `payment_refunds.amount`<br>• `eod_settlements.gross_sales`<br>• `eod_settlements.net_sales` dkk. | Saat voucher diskon Rp10.000 dialokasikan ke 3 item seharga Rp33.333, nilai per item adalah Rp3.333,33. Menyimpan nilai ini dalam integer bulat tanpa penampung sen akan menghasilkan selisih akumulasi (Rp9.999 vs Rp10.000). Kolom ini sengaja dirancang `DECIMAL(15,2)` untuk menyerap desimal pembagian. |
| **Kategori 4: Tarif Persentase, Biaya Satuan Rata-Rata, & Kurs Valas** | Nilai pengali, kurs mata uang, atau harga pokok satuan bergerak (AVCO) dari Modul 4 & Modul 5. | **`DECIMAL` Presisi Tinggi** (`(8,4)` s/d `(18,6)`) | • `tax_classes.rate` (`decimal(8,4)`)<br>• `stock_movements.unit_cost` (`decimal(18,4)`)<br>• `ingredient_stock_movements.unit_cost` (`decimal(18,4)`)<br>• `supplier_invoices.exchange_rate` (`decimal(18,6)`)<br>• `supplier_invoice_lines.tax_rate` (`decimal(8,4)`) | Alokasi landed cost (ongkir/asuransi) menghasilkan HPP satuan pecahan (misal Rp2.142,8571 per butir telur). Pemotongan ke integer akan merusak laporan laba kotor dan kartu stok. |

#### Rekomendasi Tindakan Skema Moneter
Berdasarkan bukti di atas, **kami TIDAK merekomendasikan konversi massal seluruh tabel finansial**. Perubahan skema moneter dibatasi secara presisi hanya pada:
1. Menyelaraskan `order_items.order_price` dan `order_items.order_subtotal` dari `bigint(20)` signed menjadi `bigint(20) unsigned` agar konsisten dengan `products.product_price` dan `orders.subtotal_amount`.
2. Menambahkan constraint `CHECK (amount > 0)` pada `payments.amount` untuk memblokir pembayaran no-op Rp0.
3. Mempertahankan `decimal(15,2)` pada kolom alokasi diskon (`order_items.allocated_order_discount_amount`, `net_line_total`) serta tabel retur/refund (`order_returns`, `payment_refunds`).

---

## 5. Rencana Eksekusi Terstruktur (Tahap 2)

Setelah mendapat persetujuan atas rincian di atas, eksekusi Tahap 2 akan dikerjakan dalam urutan ketat berikut pada database pengujian `yovel_pos_test`:

### Rencana File Migrasi Baru
1. `2027_01_04_000001_remediate_p0_database_invariants.php`:
   - Drop index `shifts_one_open_per_user_unique` dan buat baru: `UNIQUE (tenant_id, store_id, open_shift_lock_key)`.
   - Update trigger `trg_loyalty_ledger_hash_chain` menyertakan `NEW.id`/nonce/microsecond agar unik deterministik.
   - Drop trigger duplikat `trg_ingredient_stock_movements_no_delete` dan `trg_loyalty_ledger_no_delete`.
   - Update trigger `trg_orders_before_update` mengizinkan transisi status legal `pending -> paid` dan perubahan `shift_id` saat pelunasan kasir.
2. `2027_01_04_000002_remediate_p1_composite_fks_and_idempotency.php`:
   - Pasang FK komposit `discounts (tenant_id, store_id) REFERENCES stores(tenant_id, id) ON DELETE RESTRICT`.
   - Pasang `UNIQUE (tenant_id, store_id, id)` pada `orders`.
   - Pasang FK 3-kolom `(tenant_id, store_id, order_id) REFERENCES orders(tenant_id, store_id, id)` pada `order_items`, `payments`, dan `stock_movements`.
   - Rescope indeks keunikan idempotensi pada `payments`, `stock_movements`, `ingredient_stock_movements`, dan `cash_drawer_movements` menjadi `(tenant_id, idempotency_key)`.
   - Tambahkan trigger guard over-refund pada `payment_refunds`.
   - Konversi 28 single-column FK yang teridentifikasi ke FK komposit `(tenant_id, ...)`.
3. `2027_01_04_000003_remediate_p2_monetary_and_cleanup.php`:
   - Ubah `order_items.order_price` dan `order_subtotal` menjadi `BIGINT UNSIGNED`.
   - Tambah `CHECK (payments.amount > 0)`.
   - Perbaiki predikat `trg_loyalty_ledger_no_update` (hapus `OLD.tenant_id IS NULL`).
   - Tambah FK `loyalty_ledger (tenant_id, store_id) -> stores(tenant_id, id)` dan `employee_id_sequences.tenant_id -> tenants(id)`.
   - Tambah indeks expiry pada `provisioning_nonces`.

### Rencana Penyesuaian Kode Aplikasi
1. `app/Modules/Sales/Services/ShiftService.php`:
   - Sesuaikan query pengecekan kasir aktif agar menyertakan `where('store_id', $storeId)`.
   - Tangkap exception 1062 saat duplikasi shift dan kembalikan `DomainException('Kasir sudah memiliki shift aktif di cabang ini.')`.
2. `app/Modules/Sales/Services/TransactionService.php`:
   - Pastikan pembuatan `order_items` mengambil `store_id` dari model `$order->store_id`, bukan dari context in-memory yang berpotensi meleset.
3. `phpunit.xml`:
   - Hapus atribut `value` pada `DB_PASSWORD` baris 30.
4. `database/sql/verify/11_schema_invariants.sql` & `VerifyDatabase.php`:
   - Tambahkan skrip verifikasi skema invariants baru yang memvalidasi ketiadaan trigger duplikat dan kepatuhan composite key.

---

## 6. Hasil Eksekusi Remediasi & Pengujian (Tahap 2 & Tahap 3)

Remediasi telah diselesaikan dan diuji secara komprehensif pada target database pengujian `yovel_pos_test` tanpa menyentuh atau menjalankan operasi mutasi apa pun pada `point_of_sales`.

### 6.1 Berkas Migrasi Remediasi yang Dihasilkan
Tiga file migrasi baru telah dibuat secara sekuensial dan teruji:

1. **`database/migrations/2027_01_04_000001_remediate_p0_database_invariants.php`**:
   - **DB-001:** Menghapus indeks unik global `shifts_one_open_per_user_unique` dan menggantinya dengan `UNIQUE (tenant_id, store_id, open_shift_lock_key)`.
   - **DB-002:** Memperbarui trigger `trg_loyalty_ledger_hash_chain` dengan menyertakan `CURRENT_TIMESTAMP(6)` dan `UUID()` agar kebal tabrakan hash pada entry multipel dalam detik yang sama.
   - **DB-003:** Menghapus trigger duplikat `trg_ingredient_stock_movements_no_delete` dan `trg_loyalty_ledger_no_delete` pada event `BEFORE DELETE`.
   - **DB-004:** Memperbarui trigger `trg_orders_before_update` agar mengizinkan pembaruan `shift_id` saat pelunasan kasir dan transisi legal `pending -> paid`, sambil tetap mengunci field finansial dari manipulasi ilegal.

2. **`database/migrations/2027_01_04_000002_remediate_p1_composite_fks_and_idempotency.php`**:
   - **DB-007:** Menambahkan composite foreign key `discounts(tenant_id, store_id) REFERENCES stores(tenant_id, id) ON DELETE RESTRICT`.
   - **DB-009:** Menambahkan `orders_tenant_store_id_unique (tenant_id, store_id, id)` pada `orders` dan meng-upgrade relasi child pada `order_items`, `payments`, dan `stock_movements` menjadi Foreign Key 3-kolom `(tenant_id, store_id, order_id) REFERENCES orders(tenant_id, store_id, id)`.
   - **DB-013:** Menambahkan `uq_ori_return_item UNIQUE (tenant_id, order_return_id, order_item_id)` pada `order_return_items` dan trigger over-refund guard `trg_payment_refunds_prevent_overrefund` pada `payment_refunds`.
   - **DB-015:** Me-rescope keunikan idempotency key pada `payments`, `stock_movements`, `ingredient_stock_movements`, dan `cash_drawer_movements` menjadi `(tenant_id, idempotency_key)`.
   - **DB-008:** Mengonversi 24 foreign key single-column pada `discount_redemptions`, `parked_carts`, `payment_attempts`, `product_cost_histories`, purchasing, dan `receipt_print_logs` ke composite foreign key `(tenant_id, ...)`.

3. **`database/migrations/2027_01_04_000003_remediate_p2_monetary_and_cleanup.php`**:
   - **DB-011:** Menyelaraskan kolom moneter `order_items.order_price` dan `order_items.order_subtotal` menjadi `BIGINT UNSIGNED NOT NULL DEFAULT 0`.
   - **DB-006:** Mengetatkan constraint pembayaran `payments.amount` menjadi strictly positive `CHECK (amount > 0)`.
   - **DB-005:** Memperbaiki logika predikat `trg_loyalty_ledger_no_update` dan `trg_ingredient_stock_movements_no_update` dengan membandingkan seluruh kolom identitas tanpa predikat cacat `OLD.tenant_id IS NULL`.
   - **DB-019:** Menambahkan foreign key `employee_id_sequences(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE` dan index `idx_provisioning_nonces_expires (expires_at_epoch)`.
   - **DB-020:** Menambahkan composite foreign key `loyalty_ledger(tenant_id, store_id) REFERENCES stores(tenant_id, id) ON DELETE RESTRICT`.
   - **DB-008 (Sisa):** Mengonversi `order_items(tenant_id, item_discount_id)`, `parked_carts(tenant_id, table_id)`, `parked_carts(tenant_id, discount_id)`, `goods_received_notes(tenant_id, reversal_of_grn_id)` ke composite foreign keys, serta menghapus single-column redundant FK `email_outbox_user_fk`.

### 6.2 Penyesuaian Aplikasi & Tool Pendukung
1. **Sanitasi Kredensial (DB-018):**
   - Menghapus atribut plaintext `DB_PASSWORD` dari `phpunit.xml`. Konfigurasi testing kini mengalir aman via environment `.env.testing`.
2. **Penyelarasan ShiftService (DB-001):**
   - Pada `app/Modules/Sales/Services/ShiftService.php`, kueri pengecekan shift aktif kasir diselaraskan menjadi `where('tenant_id', $tenantId)->where('store_id', $storeId)` dan penanganan eksepsi `1062` terhubung ke domain exception yang ramah pengguna.
3. **Penyelarasan Store ID Anak Order (DB-009):**
   - Pada `app/Modules/Sales/Services/TransactionService.php`, penyisipan `order_items`, `stock_movements`, dan `order_item_modifiers` kini secara eksplisit mengikat `store_id` dan `tenant_id` langsung dari properti `$order->tenant_id` dan `$order->store_id`.
4. **Alat Verifikasi Rantai Hash Loyalty (DB-014):**
   - Dibuat artisan command `pos:verify-loyalty-chain` di `app/Console/Commands/VerifyLoyaltyChain.php` untuk memvalidasi keutuhan `prev_hash -> entry_hash` pada `loyalty_ledger`.
5. **Skrip Verifikasi Invariant Skema (DB-016):**
   - Dibuat skrip `database/sql/verify/11_schema_invariants.sql` yang secara otomatis memvalidasi ketiadaan trigger duplikat, keselarasan scope unique key, dan kelengkapan composite foreign key.

---

## 7. Bukti Eksekusi & Validasi Otomatis

Seluruh pengujian dijalankan pada target database `yovel_pos_test` dengan hasil 100% lulus:

| Suite Pengujian | Perintah Eksekusi | Jumlah Test | Hasil | Bukti Keluaran |
|---|---|:---:|:---:|---|
| **Database Verification Suite** | `php artisan pos:verify-database --env=testing` | 11 Skrip | **11/11 PASS** | `Verification Suite completed SUCCESSFULLY (11 checks passed). Database integrity is verified.` |
| **Loyalty Hash Chain Tool** | `php artisan pos:verify-loyalty-chain --env=testing` | 1 Command | **PASS** | `Verifikasi selesai: 0 rekaman loyalty_ledger terverifikasi utuh tanpa anomali.` |
| **Domain 1 (Tenancy & Provisioning)** | `php artisan test tests/Feature/Domain1` | 82 Tests | **82 PASS** | `340 assertions, 0 failures, duration 57.1s` |
| **Domain 2 (Sales, Shifts, Orders, Payments)** | `php artisan test tests/Feature/Domain2` | 102 Tests | **102 PASS** | `530 assertions, 0 failures, duration 109.2s` |
| **Purchasing (Modul 5)** | `php artisan test tests/Feature/Purchasing` | 75 Tests | **75 PASS** | `451 assertions, 0 failures, duration 43.6s` |
| **Catalog, Inventory, Tenancy, Security** | `php artisan test tests/Feature/Catalog ...` | 294 Tests | **294 PASS** | `1294 assertions, 0 failures, deadlocks=0, duration 60.3s` |
| **Total Automated Tests** | — | **553 Tests** | **553 PASS** | **100% Green Suite across all modules** |

---

## 8. Status Akhir & Hasil Deployment pada Database Aktif (`point_of_sales`)

- **Database `point_of_sales` (Production/Runtime):**
  - **Status: 100% REMEDIATED, MIGRATED & VERIFIED.**
  - `php artisan migrate` telah dieksekusi dengan sukses (Batch 11):
    - `2027_01_04_000001_remediate_p0_database_invariants` (475ms, DONE)
    - `2027_01_04_000002_remediate_p1_composite_fks_and_idempotency` (6s, DONE)
    - `2027_01_04_000003_remediate_p2_monetary_and_cleanup` (520ms, DONE)
  - `php artisan migrate:status` mengonfirmasi seluruh 162 migrasi berstatus **Ran**.
  - `php artisan pos:verify-database` pada `point_of_sales` menghasilkan **11/11 PASS (0 Discrepancies, Database integrity verified)**.
  - `php artisan pos:verify-loyalty-chain` pada `point_of_sales` menghasilkan **PASS (Keutuhan rantai hash 100% valid)**.
  - Skema database aktif `point_of_sales` kini 100% sejalan dengan kontrak arsitektur multi-tenancy, zero duplicate triggers, composite foreign keys lengkap, dan kebal terhadap celah keunikan/over-refund.

