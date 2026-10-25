# Apeiron POS — Architecture Decision Records: Table Management & Self-Order

**Dokumen**: `docs/architecture/manageTableArchitectureDecisionRecords.md`  
**Status**: Official Architecture Decision Records (ADR-001 s.d. ADR-010)  
**Kompatibilitas**: Melengkapi `docs/architecture/ARCHITECTURE_DECISIONS.md` tanpa menimpa keputusan yang telah ada.

---

## ADR-001: Kepemilikan Cabang Kanonik (`Tenant -> Store -> Table`) dan Integritas Referensial Komposit

- **ADR ID**: ADR-001
- **Title**: Kepemilikan Cabang Kanonik (`Tenant -> Store -> Table`) dan Integritas Referensial Komposit
- **Status**: **Accepted**
- **Context**:
  Apeiron POS mendukung banyak tenant dan banyak cabang per tenant. Masalah utama yang dilaporkan adalah Table Management belum ter-scope dengan benar per cabang. Di dalam repositori, istilah bisnis "Branch" diimplementasikan secara teknis sebagai model `Store` (`app/Models/Store.php`), sementara tabel `tables` pada awalnya dibuat sebelum fondasi multi-store (`2026_09_17_074003_create_tables_table.php` vs `2026_09_25_211159_add_tenant_and_store_core.php`).
- **Evidence**:
  - Verified: `app/Models/Store.php`, `app/Services/Context/StoreResolver.php`, `app/Models/Concerns/ScopedToStore.php`, `app/Models/Concerns/AssignsStore.php`, `database/migrations/2026_12_01_000000_branch_ownership.php`, `2026_12_04_000011_restore_branch_composite_foreign_keys.php`.
- **Decision**:
  1. Menetapkan `Store` (`stores.id`) sebagai satu-satunya representasi kanonik dari cabang.
  2. Setiap baris pada tabel `tables` wajib memiliki `tenant_id` (`NOT NULL`) dan `store_id` (`NOT NULL`) dengan *Composite Foreign Key* `(tenant_id, store_id) REFERENCES stores(tenant_id, id) ON DELETE RESTRICT`.
  3. Tabel `orders` wajib memiliki kolom `table_id` (`NULLABLE`) dengan *Composite Foreign Key* `(tenant_id, store_id, table_id) REFERENCES tables(tenant_id, store_id, id) ON DELETE RESTRICT`.
  4. Model `app/Models/Table.php` wajib menggunakan trait `ScopedToTenant`, `AssignsTenant`, `ScopedToStore`, dan `AssignsStore`.
- **Alternatives Considered**:
  1. *Hanya mengandalkan `store_id` pada `tables` tanpa `tenant_id`*: Ditolak karena kueri analitik tingkat tenant dan pola `TenantScope` di seluruh repositori (`OWNERSHIP_MODEL.md`) mewajibkan `tenant_id` dan *composite FK* untuk mencegah penautan silang antar tenant di level SQL.
  2. *Hanya mengandalkan filter `WHERE store_id = ?` di Eloquent tanpa Composite FK di database*: Ditolak karena raw query, bug kode di masa depan, atau manipulasi payload dapat meloloskan referensi meja lintas cabang jika tidak dijaga oleh constraint database.
- **Consequences**:
  - Database menjamin secara mutlak bahwa meja Cabang B tidak pernah dapat ditautkan ke pesanan Cabang A.
  - Membutuhkan indeks unik komposit `UNIQUE (tenant_id, store_id, id)` pada tabel `tables`.
- **Security and Operational Implications**:
  - Mengeliminasi celah *Insecure Direct Object Reference* (IDOR) lintas cabang dan lintas tenant pada operasi meja dan pembuatan pesanan.
- **Migration Implications**:
  - Memerlukan backfill `tables.store_id` dan `orders.table_id` (Fase 2) sebelum constraint `NOT NULL` ditegakkan sepenuhnya.
- **Conditions for Revisiting**:
  - Hanya jika arsitektur multi-tenant Apeiron POS beralih ke *database-per-branch* fisik di masa depan.

---

## ADR-002: Identitas Stabil Meja vs Keunikan Penomoran Tampilan Per Cabang

- **ADR ID**: ADR-002
- **Title**: Identitas Stabil Meja vs Keunikan Penomoran Tampilan Per Cabang
- **Status**: **Accepted**
- **Context**:
  Restoran dengan banyak cabang lazim memberi nomor meja yang identik di setiap cabang (mis. "Meja 01" ada di Cabang Kemang dan "Meja 01" juga ada di Cabang Senopati). Selain itu, manajer cabang kadang mengubah label meja (mis. dari "01" menjadi "A1") tanpa boleh merusak riwayat pesanan masa lalu.
- **Evidence**:
  - Verified: `database/migrations/2026_09_17_074004_add_table_number_to_orders_table.php`, `2026_09_20_000001_harden_soft_delete_unique_constraint_on_tables.php`, `2026_12_01_000001_table_name_per_store.php`, `2026_12_01_000002_drop_old_table_name_unique.php`.
- **Decision**:
  1. Gunakan `tables.id` (BIGINT PK) sebagai identitas relasional internal yang tidak berubah.
  2. Gunakan `tables.name` sebagai label/nomor tampilan meja yang wajib unik hanya di dalam lingkup `(tenant_id, store_id)` untuk meja yang aktif (tidak *soft-deleted*).
  3. Saat pesanan dibuat, simpan `orders.table_id = $table->id` sebagai referensi relasional otoritatif DAN salin `$table->name` ke `orders.table_number` sebagai *immutable snapshot* untuk pencetakan struk dan audit historis.
- **Alternatives Considered**:
  1. *Menjadikan `table_number` unik per Tenant*: Ditolak karena memaksa cabang kedua menggunakan prefiks buatan (mis. "CBG2-01") yang buruk bagi pengalaman pengguna restoran.
  2. *Menghapus kolom `orders.table_number` sepenuhnya*: Ditolak karena akan merusak kompatibilitas mundur dengan tampilan struk (`resources/views/transaction/receipt.blade.php`), laporan penjualan (`SalesExport.php`), dan trigger imutabilitas (`2026_12_02_000000_add_immutability_triggers.php`).
- **Consequences**:
  - Setiap cabang bebas mendefinisikan skema penomoran mejanya sendiri.
  - Perubahan nama meja di kemudian hari tidak mengubah nomor meja yang tercetak pada struk transaksi masa lalu.
- **Security and Operational Implications**:
  - Validasi `StoreTableRequest` dan `UpdateTableRequest` wajib menyertakan klausa `->where('tenant_id', $tenantId)->where('store_id', $storeId)`.
- **Migration Implications**:
  - Pesanan lama yang hanya memiliki `table_number` di-backfill ke `table_id` dengan mencocokkan `(tenant_id, store_id, table_number)`.
- **Conditions for Revisiting**:
  - Jika sebuah cabang memperkenalkan sub-area/lantai (`floor_id` / `zone_id`) di mana nomor meja yang sama diizinkan di lantai yang berbeda dalam satu cabang yang sama.

---

## ADR-003: Arsitektur Status Meja (*Derived Order State* + *Lightweight Session Guard*) vs Tabel Sesi Finansial Terpisah

- **ADR ID**: ADR-003
- **Title**: Arsitektur Status Meja (*Derived Order State* + *Lightweight Session Guard*) vs Tabel Sesi Finansial Terpisah
- **Status**: **Proposed** *(Safe Default — menunggu konfirmasi aturan bisnis Q1 pada produk untuk multi-order per kunjungan, namun siap diimplementasikan tanpa memblokir Fase 0–3)*
- **Context**:
  Perlu diputuskan apakah Apeiron POS memerlukan tabel domain baru `table_sessions` di database untuk melacak siklus kunjungan meja, atau menurunkan status meja secara otoritatif dari `Order` aktif yang dikombinasikan dengan middleware `EnsureTableSession` yang sudah ada.
- **Evidence**:
  - Verified: Repositori telah memiliki `app/Http/Middleware/EnsureTableSession.php`, `app/Models/Order.php`, `app/Models/Payment.php`, `app/Models/PaymentAttempt.php`, dan `app/Console/Commands/ExpireStaleCashSelfOrders.php`, serta **tidak** memiliki model `TableSession` di `app/Models/`.
- **Decision**:
  Memilih pendekatan **Authoritative Order-Driven Table State + Lightweight Ephemeral Session Guard (`EnsureTableSession`)**:
  1. Tabel `Order` dan `Payment` tetap menjadi **satu-satunya sumber kebenaran finansial dan status pesanan**.
  2. Kolom `tables.status` (`available`, `occupied`, `reserved`, `disabled`) diperbarui secara atomik di dalam transaksi `TransactionService` dan `TableService`.
  3. Middleware `EnsureTableSession` mengelola token sesi peramban pelanggan yang terikat pada `(tenant_id, store_id, table_id, qr_token_version)` untuk mengisolasi keranjang dan mencegah pembajakan sesi meja dari perangkat asing.
  4. Status visual meja (`occupied` / `available`) tidak pernah digunakan untuk menyimpulkan apakah pesanan sudah dibayar (`orders.payment_status` adalah otoritatif).
- **Alternatives Considered**:
  1. *Membuat tabel `table_sessions` persisten penuh dengan siklus tagihan sendiri*: Ditolak karena menciptakan sumber kebenaran kedua yang berkonflik dengan `orders`, memperumit trigger imutabilitas (`2026_12_02_000000_add_immutability_triggers.php`), dan menambah beban rekonsiliasi ketika webhook Midtrans atau job `ExpireStaleCashSelfOrders` berjalan.
  2. *Menghitung status meja 100% on-the-fly lewat subquery `EXISTS (SELECT 1 FROM orders ...)` tanpa kolom `tables.status`*: Ditolak karena tidak dapat merepresentasikan status non-order seperti `reserved` atau `disabled` dan menambah beban kueri pada pemuatan dashboard meja frekuensi tinggi.
- **Consequences**:
  - Desain tetap sederhana, kohesif dengan kode yang sudah ada (`EnsureTableSession`), dan bebas dari *dual-ledger drift*.
  - Setiap transisi terminal pada `Order` (Paid+Completed, Voided, Cancelled, Expired) wajib memanggil helper sinkronisasi status meja di dalam transaksi yang sama.
- **Security and Operational Implications**:
  - Mencegah bug di mana meja tampak kosong (`available`) padahal masih ada tagihan yang belum dibayar, karena `TableService::releaseTable` memvalidasi ketidakadaan `Order` aktif yang belum lunas.
- **Migration Implications**:
  - Tidak memerlukan tabel baru `table_sessions`; cukup memanfaatkan tabel `tables` dan `orders` yang diperkuat.
- **Conditions for Revisiting**:
  - Jika di masa depan produk membutuhkan fitur penggabungan beberapa meja fisik ke dalam satu tagihan induk (*complex multi-table bill merging*) atau perhitungan durasi duduk (*table turn-time billing* per menit) sebelum pesanan dibuat.

---

## ADR-004: Konvensi Tipe Layanan (*Service Types*) dan Independensi Checkout Non-Meja

- **ADR ID**: ADR-004
- **Title**: Konvensi Tipe Layanan (*Service Types*) dan Independensi Checkout Non-Meja
- **Status**: **Accepted**
- **Context**:
  Apeiron POS melayani F&B Dine-In (wajib meja jika fitur meja aktif), F&B Takeaway/Delivery (tanpa meja), dan Retail (tanpa manajemen meja sama sekali).
- **Evidence**:
  - Verified: `app/Enums/OrderType.php`, `app/Casts/OrderTypeCast.php`, `database/migrations/2026_12_06_000002_normalize_order_type_takeaway_values.php`, `app/Services/Omnichannel/GoFoodOrderNormalizer.php`, `app/Services/Omnichannel/GrabFoodOrderNormalizer.php`, `tests/Unit/OrderTypeNormalizationTest.php`.
- **Decision**:
  1. Gunakan `app/Enums/OrderType.php` sebagai standar tunggal tipe layanan pesanan.
  2. Dalam `TransactionService` dan `StoreTransactionRequest`:
     - Jika cabang memiliki `tables_enabled = false` (Retail / F&B Booth tanpa meja): `table_id` dan `table_number` selalu dipaksa `NULL`.
     - Jika cabang memiliki `tables_enabled = true` dan `order_type === OrderType::DineIn`: `table_id` wajib diisi dan wajib merujuk meja aktif milik `(tenant_id, store_id)` tersebut.
     - Jika `order_type !== OrderType::DineIn` (mis. `Takeaway`, `Delivery`, GoFood, GrabFood): `table_id` dan `table_number` dipaksa `NULL` dan tidak pernah mengubah status meja mana pun.
- **Alternatives Considered**:
  1. *Membuat "Meja Virtual / Dummy Table" (mis. "Meja Takeaway") di tabel `tables` untuk pesanan non-meja*: Ditolak keras karena mencemari analitik okupansi meja, memicu kontensi kunci baris (`FOR UPDATE`) pada satu baris meja dummy untuk seluruh transaksi Takeaway/Retail, dan memaksa tenant Retail memiliki data meja palsu.
- **Consequences**:
  - Checkout Retail dan F&B Takeaway 100% bebas dari ketergantungan pada tabel `tables`.
- **Security and Operational Implications**:
  - Mencegah kasir secara tidak sengaja menautkan pesanan Takeaway ke meja Dine-In yang sedang ditempati tamu lain.
- **Migration Implications**:
  - Kolom `orders.table_id` dan `orders.table_number` tetap `NULLABLE`.
- **Conditions for Revisiting**:
  - Tidak ada; pemisahan pesanan berbasis meja vs non-meja adalah prinsip fundamental domain POS.

---

## ADR-005: Identitas QR Self-Order, Siklus Hidup Kredensial, dan Batas Kepercayaan Publik

- **ADR ID**: ADR-005
- **Title**: Identitas QR Self-Order, Siklus Hidup Kredensial, dan Batas Kepercayaan Publik
- **Status**: **Accepted**
- **Context**:
  Pelanggan memindai kode QR di meja tanpa login. Jika URL QR hanya berisi `/customer/table/1` atau ID sekuensial, penyerang dapat dengan mudah memesan atas nama meja lain atau cabang lain. Selain itu, manajer cabang membutuhkan cara untuk mencabut akses QR jika stiker QR meja disalahgunakan.
- **Evidence**:
  - Verified: `app/Http/Middleware/ResolveTableFromToken.php`, `app/Http/Middleware/EnsureTableSession.php`, `routes/customer.php`, `tests/Feature/CustomerSelfOrderPatchesTest.php`.
- **Decision**:
  1. Setiap meja memiliki kredensial `qr_token` berupa *opaque random token* 256-bit (64 karakter heksadesimal dari `random_bytes(32)`) yang unik secara global (`UNIQUE(qr_token)`).
  2. Tabel `tables` menyimpan `qr_token_version` (`INT UNSIGNED DEFAULT 1`) dan `qr_rotated_at` (`TIMESTAMP NULL`).
  3. Saat admin cabang menjalankan `TableService::rotateQrToken()`, `qr_token` diganti baru dan `qr_token_version` dinaikkan. URL dengan token lama langsung ditolak oleh `ResolveTableFromToken` (HTTP 404/410), dan sesi aktif yang menyimpan `qr_token_version` lama langsung di-invalidasi oleh `EnsureTableSession`.
  4. Seluruh konteks `tenant_id`, `store_id`, dan `table_id` pada rute publik `/customer/*` diturunkan secara eksklusif di sisi server dari hasil lookup `qr_token`—tidak pernah dari parameter body/query tambahan.
- **Alternatives Considered**:
  1. *Stateless Signed URL dengan `table_id` tanpa menyimpan token di DB*: Ditolak karena URL yang sudah dicetak pada stiker fisik tidak dapat dicabut (*revoked*) per meja secara individual tanpa mengganti `APP_KEY` seluruh aplikasi.
  2. *Menggunakan UUID/ULID meja yang bersifat permanen di dalam URL QR*: Ditolak karena jika identitas publik meja bocor/difoto oleh pihak jahil, identitas tersebut tidak dapat dirotasi tanpa mengubah identitas meja.
- **Consequences**:
  - Memberikan keseimbangan terbaik antara kemudahan operasional restoran (stiker fisik tetap valid selama tidak dirotasi) dan keamanan (dapat dicabut instan per meja kapan saja).
- **Security and Operational Implications**:
  - Dilengkapi dengan rate limiter pada rute pemindaian menu, panggilan pelayan (`WaiterCallController`), dan pengiriman checkout (`Customer\CheckoutController`).
- **Migration Implications**:
  - Menambahkan `qr_token_version` dan `qr_rotated_at` pada `tables`; meja eksisting yang sudah memiliki `qr_token` diberi nilai awal `qr_token_version = 1`.
- **Conditions for Revisiting**:
  - Jika restoran fine-dining meminta fitur *dynamic per-visit printed QR receipt* (token satu kali pakai yang dicetak kasir saat tamu duduk), yang dapat didukung di atas mekanisme `qr_token_version` ini.

---

## ADR-006: Konvergensi Pembuatan Pesanan dan Batas Layanan Aplikasi (`TransactionService`)

- **ADR ID**: ADR-006
- **Title**: Konvergensi Pembuatan Pesanan dan Batas Layanan Aplikasi (`TransactionService`)
- **Status**: **Accepted**
- **Context**:
  Repositori memiliki beberapa titik masuk pembuatan pesanan: `app/Livewire/Kasir/CreateOrder.php`, `app/Http/Controllers/TransactionController.php`, `app/Http/Controllers/Customer/CheckoutController.php`, dan `app/Jobs/ProcessWebhookOrderJob.php`. Duplikasi logika validasi harga, stok, pajak, dan status meja di masing-masing controller/komponen berisiko tinggi menimbulkan bug inkonsistensi.
- **Evidence**:
  - Verified: `app/Services/TransactionService.php`, `app/Livewire/Kasir/CreateOrder.php`, `app/Http/Controllers/TransactionController.php`, `app/Http/Controllers/Customer/CheckoutController.php`, `docs/architecture/FINANCIAL_INVARIANTS.md`, `docs/architecture/INVENTORY_INVARIANTS.md`.
- **Decision**:
  1. Menetapkan `app/Services/TransactionService.php` sebagai satu-satunya orkestrator transaksional otoritatif untuk pembuatan pesanan dan perubahan status pesanan.
  2. Controller dan komponen Livewire hanya bertugas menangani protokol HTTP/UI, otorisasi spesifik kanal, dan pembentukan DTO/Command terstandarisasi, lalu mendelegasikan eksekusi inti ke `TransactionService`.
  3. Di dalam `TransactionService`, validasi kepemilikan `(tenant_id, store_id)`, asosiasi `table_id`, kalkulasi ulang harga produk/modifier, penerapan diskon (`DiscountStoreScope`), pemotongan stok (`StockBalance`), dan pembaruan status okupansi `Table` dijalankan secara atomik dalam satu transaksi database.
- **Alternatives Considered**:
  1. *Membuat `SelfOrderTransactionService` terpisah untuk QR Self-Order*: Ditolak karena akan menduplikasi rumus pajak, pembulatan finansial, diskon, dan pemotongan resep/bahan baku (`ProductIngredient`), yang melanggar `FINANCIAL_INVARIANTS.md` dan `INVENTORY_INVARIANTS.md`.
- **Consequences**:
  - Satu sumber kebenaran untuk aturan finansial, inventori, dan siklus hidup meja di seluruh kanal.
- **Security and Operational Implications**:
  - Menjamin bahwa celah keamanan kalkulasi harga atau bypass stok tidak dapat muncul di kanal Self-Order.
- **Migration Implications**:
  - Refactoring terarah pada `Customer\CheckoutController` dan `Kasir\CreateOrder` agar memanggil kontrak metode yang konsisten pada `TransactionService`.
- **Conditions for Revisiting**:
  - Tidak ada dalam arsitektur monolit modular saat ini.

---

## ADR-007: Strategi Idempotensi dan Pengendalian Konkurensi Berbasis Urutan Kunci Kanonik

- **ADR ID**: ADR-007
- **Title**: Strategi Idempotensi dan Pengendalian Konkurensi Berbasis Urutan Kunci Kanonik
- **Status**: **Accepted**
- **Context**:
  Dalam lingkungan restoran yang sibuk, kasir dan pelanggan QR dapat memesan pada meja atau produk yang sama secara bersamaan, jaringan seluler pelanggan sering mengalami *timeout* yang memicu pengiriman ulang request, dan webhook pembayaran Midtrans dapat datang bersamaan dengan job rekonsiliasi atau kadaluwarsa.
- **Evidence**:
  - Verified: `database/migrations/2026_09_20_151255_add_idempotency_key_to_orders_table.php`, `2026_09_24_182406_enforce_orders_idempotency_key_not_null.php`, `2026_12_11_000001_create_payment_attempts_and_split_tender_support.php`, `app/Services/DatabaseErrorClassifier.php`, `tests/Feature/IdempotencyLockTest.php`, `tests/Feature/Tenancy/StockBalanceDeadlockTest.php`, `tests/Feature/Domain2/WebhookVsReconcileVsVoidRaceTest.php`.
- **Decision**:
  1. Setiap operasi pembuatan pesanan (POS maupun Self-Order) wajib menyertakan `idempotency_key` yang ditegakkan oleh constraint unik `(tenant_id, idempotency_key)` pada tabel `orders`.
  2. Saat menangani duplikasi `idempotency_key` (baik melalui pengecekan awal maupun penangkapan *unique constraint violation* oleh `DatabaseErrorClassifier`), sistem mengembalikan pesanan yang telah ada tanpa membuat transaksi baru.
  3. Menetapkan urutan penguncian baris (`SELECT ... FOR UPDATE`) yang deterministik di seluruh transaksi penjualan:
     `Table` -> `Product / StockBalance / Ingredient (ORDER BY id ASC)` -> `Discount` -> `Order` -> `Payment / PaymentAttempt`.
  4. Seluruh efek samping eksternal (broadcast event, cetak printer dapur, invalidasi cache) wajib dijalankan di dalam callback `DB::afterCommit()`.
- **Alternatives Considered**:
  1. *Distributed Lock berbasis Redis untuk setiap meja dan produk*: Ditolak karena menambah ketergantungan infrastruktur eksternal dan kompleksitas kegagalan *lock lease*, sedangkan database relasional (MySQL InnoDB / PostgreSQL) sudah memegang transaksi ACID untuk `orders` dan `stock_balances`.
  2. *Optimistic Locking saja tanpa `SELECT ... FOR UPDATE`*: Ditolak untuk pemotongan stok dan status pembayaran karena tingkat kegagalan retry yang tinggi saat jam sibuk restoran.
- **Consequences**:
  - Bebas *deadlock* karena tidak ada siklus pada grafik ketergantungan kunci (*acyclic lock graph*).
  - Pengujian konkurensi sejati wajib dijalankan terhadap mesin MySQL/PostgreSQL (bukan hanya SQLite file/memory lock).
- **Security and Operational Implications**:
  - Mencegah *race-condition exploit* (mis. penggunaan kuota voucher atau saldo stok terakhir secara ganda melalui request paralel).
- **Migration Implications**:
  - Memanfaatkan kolom `idempotency_key` dan `DatabaseErrorClassifier` yang sudah ada di repositori.
- **Conditions for Revisiting**:
  - Hanya jika volume transaksi satu cabang melampaui kapasitas baris kunci RDBMS tunggal (ribuan checkout per detik pada satu cabang yang sama).

---

## ADR-008: Gating Fitur Multi-Lapis (Entitlement Tenant, Konfigurasi Cabang, dan RBAC Policy)

- **ADR ID**: ADR-008
- **Title**: Gating Fitur Multi-Lapis (Entitlement Tenant, Konfigurasi Cabang, dan RBAC Policy)
- **Status**: **Accepted**
- **Context**:
  Satu tenant dapat berupa bisnis Retail murni, bisnis F&B Dine-In, bisnis F&B Takeaway/Cloud Kitchen tanpa meja, atau perusahaan hibrida yang memiliki cabang restoran sekaligus cabang ritel/roastery.
- **Evidence**:
  - Verified: `app/Services/EntitlementService.php`, `app/Http/Middleware/EnforceEntitlement.php`, `app/Models/Plan.php`, `app/Models/PlanEntitlement.php`, `app/Models/TenantEntitlementOverride.php`, `app/Models/Setting.php`, `app/Policies/TablePolicy.php`, `tests/Feature/Security/TenantEntitlementAndQuotaTest.php`.
- **Decision**:
  Menetapkan evaluasi ketersediaan fitur Table Management dan Self-Order dalam **3 Lapisan Server-Side**:
  1. **Lapis 1 — Tenant Plan Entitlement (`EntitlementService` / `EnforceEntitlement`)**: Memeriksa apakah paket langganan tenant (atau override-nya) mengizinkan fitur manajemen meja / QR Self-Order.
  2. **Lapis 2 — Konfigurasi Operasional Cabang (`Store` / `Setting` per `store_id`)**: Memeriksa apakah cabang aktif mengaktifkan `pos.tables_enabled` dan `pos.self_order_enabled`. Hal ini memungkinkan tenant F&B menonaktifkan meja pada cabang *Cloud Kitchen / Booth Takeaway*-nya, serta memastikan tenant Retail memiliki `pos.tables_enabled = false` secara default.
  3. **Lapis 3 — Otorisasi Peran & Akses Cabang (`TablePolicy` + `user_stores`)**: Memeriksa izin RBAC pengguna dan penugasan cabang aktifnya.
- **Alternatives Considered**:
  1. *Mengandalkan kolom `tenants.business_type` saja*: Ditolak karena tidak mendukung paket langganan berjenjang (*tiered SaaS plans*) dan tidak mendukung tenant F&B yang memiliki sebagian cabang berupa *booth takeaway* tanpa meja.
  2. *Hanya menyembunyikan menu Meja di Blade UI*: Ditolak karena melanggar prinsip *defense-in-depth*; endpoint controller dan Livewire tetap harus menolak akses secara otoritatif di sisi server.
- **Consequences**:
  - Fleksibilitas penuh untuk F&B Dine-In, F&B Takeaway, Retail, dan skenario multi-format cabang tanpa membangun framework plugin baru yang berlebihan.
- **Security and Operational Implications**:
  - Permintaan langsung ke `/tables` atau `/customer/t/{token}` pada tenant/cabang yang tidak memiliki entitlement atau menonaktifkan fitur akan ditolak di sisi server (HTTP 403/404).
- **Migration Implications**:
  - Default konfigurasi cabang disetel otomatis berdasarkan tipe bisnis/data meja yang ada agar cabang F&B eksisting tetap berjalan mulus.
- **Conditions for Revisiting**:
  - Jika produk menambahkan penjualan lisensi add-on per cabang (*per-branch billing*).

---

## ADR-009: Strategi Migrasi Data *Expand-and-Contract* dan Penanganan Data Meja Warisan

- **ADR ID**: ADR-009
- **Title**: Strategi Migrasi Data *Expand-and-Contract* dan Penanganan Data Meja Warisan
- **Status**: **Accepted**
- **Context**:
  Database yang sedang berjalan tidak boleh di-reset (`migrate:fresh` dilarang), dan terdapat proses generator dataset 12 bulan yang harus dilindungi. Data meja dan pesanan eksisting harus dimigrasikan ke model kepemilikan cabang yang ketat tanpa menebak kepemilikan secara sembarangan.
- **Evidence**:
  - Verified: `app/Console/Commands/BackfillTenantStoreData.php`, `app/Console/Commands/PopulateApeironFullYearDataset.php`, `app/Console/Commands/VerifyDatabase.php`, `database/sql/verify/01_fk_orphans.sql`.
- **Decision**:
  1. Gunakan migrasi *Expand-and-Contract* 3 tahap (Expand kolom & indeks -> Backfill deterministik -> Enforce constraint).
  2. Terapkan 4 aturan rekonsiliasi deterministik (inferensi dari `orders`, inferensi tenant 1-cabang, pemisahan/kloning terkendali jika satu baris meja dipakai lintas cabang pada riwayat `orders`, dan karantina `is_active = false` untuk meja yatim yang ambigu pada tenant multi-cabang).
  3. Jangan pernah menghapus atau mengosongkan `orders.table_number` pada data historis.
- **Alternatives Considered**:
  1. *Menetapkan seluruh meja yang `store_id IS NULL` ke cabang pertama (`MIN(store_id)`) milik tenant secara paksa*: Ditolak karena melanggar aturan arsitektur (*"How existing tables are migrated to the correct branch without assigning them arbitrarily"*) dan dapat memindahkan meja aktif cabang kedua ke cabang pertama secara salah.
- **Consequences**:
  - Nol kehilangan data (*zero data loss*), audit historis struk tetap utuh, dan tidak ada atribusi cabang yang ditebak secara buta.
- **Security and Operational Implications**:
  - Meja yang dikarantina karena ambigu tidak dapat diakses publik via QR sampai manajer/owner mengonfirmasi cabangnya.
- **Migration Implications**:
  - Menyediakan command `BackfillBranchTablesAndOrders` dengan flag `--dry-run` untuk inspeksi pra-eksekusi.
- **Conditions for Revisiting**:
  - Setelah seluruh lingkungan produksi selesai menjalankan migrasi Fase 2 dan diverifikasi oleh `VerifyDatabase`, command backfill dapat diarsipkan.

---

## ADR-010: Observabilitas, Audit Trail, dan Pemulihan Kegagalan Operasional

- **ADR ID**: ADR-010
- **Title**: Observabilitas, Audit Trail, dan Pemulihan Kegagalan Operasional
- **Status**: **Accepted**
- **Context**:
  Operasi meja dan Self-Order melibatkan pengguna anonim, perputaran uang, serta sinkronisasi antara kasir dan dapur. Tanpa log terstruktur dan jejak audit yang aman, insiden seperti penyalahgunaan QR, kegagalan pembayaran, atau konflik status meja sulit diinvestigasi.
- **Evidence**:
  - Verified: `app/Services/AuditTrailService.php`, `app/Models/ActivityLog.php`, `app/Console/Commands/ExpireStaleCashSelfOrders.php`, `app/Console/Commands/ReconcilePendingMidtransOrders.php`, `tests/Feature/Security/AuditTrailServiceTest.php`.
- **Decision**:
  1. Seluruh mutasi penting pada siklus hidup meja (`table.created`, `table.updated`, `table.deleted`, `table.qr_rotated`, `table.released`) dan kegagalan keamanan Self-Order (`self_order.invalid_token`, `self_order.branch_disabled`, `self_order.rate_limited`) wajib dicatat secara terstruktur dengan konteks `(tenant_id, store_id, table_id, actor_id, correlation_id)`.
  2. **Redaksi Data Sensitif**: Nilai mentah `qr_token` dilarang ditulis ke file log atau `activity_logs`; hanya simpan `table_id` dan `qr_token_version` (atau hash SHA-256 singkat 8 karakter dari token).
  3. Pemulihan otomatis untuk sesi meja yang menggantung akibat pesanan tunai Self-Order yang ditinggalkan pelanggan ditangani oleh `ExpireStaleCashSelfOrders`, dan untuk pembayaran digital ditangani oleh `ReconcilePendingMidtransOrders`, yang keduanya wajib memperbarui status meja secara atomik saat pesanan dibatalkan.
- **Alternatives Considered**:
  1. *Mencatat URL lengkap Self-Order ke dalam log standar*: Ditolak karena URL mengandung `qr_token` rahasia; siapa pun yang memiliki akses baca ke log viewer dapat mengambil alih pemesanan meja tersebut.
- **Consequences**:
  - Memenuhi standar keamanan aplikasi OWASP (pencegahan kebocoran kredensial di log) sekaligus memberikan visibilitas operasional penuh per cabang.
- **Security and Operational Implications**:
  - Memungkinkan deteksi dini terhadap serangan *brute-force* atau *scanning* pada endpoint `/customer/*` per cabang.
- **Migration Implications**:
  - Mengintegrasikan pemanggilan `AuditTrailService` ke dalam `TableService` dan middleware `ResolveTableFromToken`.
- **Conditions for Revisiting**:
  - Jika platform mengadopsi sistem SIEM atau OpenTelemetry terpusat di masa depan.