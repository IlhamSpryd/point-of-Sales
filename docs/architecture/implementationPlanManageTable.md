# Apeiron POS — Implementation Plan: Multi-Branch Table Management & Self-Order

**Dokumen**: `docs/architecture/implementationPlanManageTable.md`  
**Status**: Implementation-Ready Execution Plan  
**Referensi Arsitektur**: `docs/architecture/manageTableArchitecture.md`, `docs/architecture/manageTableArchitectureDecisionRecords.md`

---

## 1. Prinsip Eksekusi & Pelindungan Lingkungan

1. **Perlindungan Proses Dataset Aktif (Non-Negotiable)**:
   - Lingkungan saat ini memiliki generator dataset 12 bulan (`app/Console/Commands/PopulateApeironFullYearDataset.php`).
   - Agen implementasi **DILARANG** menjalankan `php artisan migrate:fresh`, `db:wipe`, `TRUNCATE`, menonaktifkan foreign key checks, atau menjalankan PHPUnit terhadap database produksi/demo aktif.
   - Seluruh pengujian otomatis wajib menggunakan koneksi database pengujian terisolasi (`DB_DATABASE` khusus testing pada `.env.testing`).
2. **Urutan Expand-and-Contract**:
   - **Expand**: Tambahkan kolom nullable (`orders.table_id`, `tables.qr_token_version`, `tables.qr_rotated_at`), indeks komposit, dan *composite foreign key* tanpa menghapus kolom lama (`orders.table_number`).
   - **Backfill & Reconcile**: Jalankan skrip/command rekonsiliasi data yang deterministik dan idempoten.
   - **Switch Application Logic**: Perbarui model, policy, service, controller, middleware, dan Livewire.
   - **Verify**: Jalankan verifikasi SQL (`VerifyDatabase`) dan suite pengujian fitur/konkurensi.

---

## 2. Grafik Dependensi Eksekusi (*Execution Dependency Graph*)

```mermaid
graph TD
    P0_1[T0.1: Git & Active DB Safeguard Check] --> P0_2[T0.2: Read-Only Source Inspection & Gap Confirmation]
    P0_2 --> P0_3[T0.3: Isolated Test DB Verification]
    P0_3 --> P1_1[T1.1: Finalize Contracts, Enums & Entitlement Keys]
    P1_1 --> P2_1[T2.1: Expand Migration on tables & stores/settings]
    P2_1 --> P2_2[T2.2: Expand Migration on orders.table_id + Composite FK]
    P2_2 --> P2_3[T2.3: Deterministic Data Backfill & Orphan Quarantine Command]
    P2_3 --> P2_4[T2.4: Enforce NOT NULL & Composite Constraints on tables]
    P2_4 --> P3_1[T3.1: Harden Table Model Scopes & Relationships]
    P3_1 --> P3_2[T3.2: Harden TablePolicy & FormRequests per Store]
    P3_2 --> P3_3[T3.3: Upgrade TableService Lifecycle & QR Rotation]
    P3_3 --> P3_4[T3.4: Converge TransactionService Order-Table Validation & Locks]
    P3_4 --> P4_1[T4.1: Harden ResolveTableFromToken & EnsureTableSession]
    P4_1 --> P4.2[T4.2: Partition CartService & MenuCacheService by Store & Table]
    P4.2 --> P4_3[T4.3: Harden Customer CheckoutController & WaiterCallController]
    P3_4 --> P5_1[T5.1: Update TableController & Blade Views for Branch Context]
    P5_1 --> P5_2[T5.2: Update Livewire Kasir CreateOrder & KDS Board]
    P5_2 --> P5_3[T5.3: Harden StoreSwitchController State Reset & Retail Gating]
    P4_3 --> P6_1[T6.1: Implement Comprehensive Test Suite T-01 to T-15]
    P5_3 --> P6_1
    P6_1 --> P7_1[T7.1: SQL Verification, Observability & Rollout Sign-Off]
Blocker Kritis Sebelum Koding Dimulai
BLK-01: Konfirmasi isolasi .env.testing agar tidak mengarah ke database yang digunakan oleh PopulateApeironFullYearDataset.

BLK-02: Inspeksi read-only isi file database/migrations/2026_12_01_000000_branch_ownership.php, 2026_12_01_000001_table_name_per_store.php, dan app/Models/Table.php pada Task T0.2 untuk memastikan migrasi T2.1 dan T2.2 bersifat komplementer dan tidak menduplikasi constraint yang sudah ada.

3. Rencana Implementasi Terperinci Per Fase
Phase	Task ID	Priority	Objective	Files/Components	Implementation Details	Dependencies	Acceptance Criteria	Tests	Rollback/Recovery	Risk
Phase 0	T0.1	P0	Mencatat status Git dan memastikan perlindungan proses generator dataset aktif.	Working tree, .env, .env.testing, app/Console/Commands/PopulateApeironFullYearDataset.php (Existing - Read-Only)	Jalankan git status dan ps aux (hanya baca). Pastikan tidak ada perintah destruktif yang dijalankan pada DB utama.	None	Status working tree tercatat; proses dataset generator tidak terganggu.	Pemeriksaan manual output git status.	Tidak ada perubahan state.	Low
Phase 0	T0.2	P0	Memetakan detail baris kode eksisting pada modul Table, Order, StoreResolver, dan Customer Self-Order.	app/Models/Table.php, app/Models/Order.php, app/Services/TableService.php, app/Services/TransactionService.php, app/Http/Middleware/ResolveTableFromToken.php, app/Http/Middleware/EnsureTableSession.php, database/migrations/2026_12_01_*.php (Existing - Read-Only)	Baca isi file eksisting untuk mengonfirmasi nama kolom eksak pada tables (name vs table_number, store_id, qr_token) dan keberadaan table_id pada orders.	T0.1	Semua item Unverified pada matriks bukti terkonfirmasi dengan nomor baris eksak.	Analisis statis / phpstan.	Tidak ada perubahan file.	Low
Phase 0	T0.3	P0	Menyiapkan strategi database pengujian yang terisolasi dengan aman.	phpunit.xml, .env.testing (Existing - Read-Only)	Verifikasi bahwa phpunit.xml / .env.testing menggunakan database test terpisah (mis. database test khusus di MySQL/PostgreSQL atau SQLite untuk unit test cepat) dan bukan database demo utama.	T0.2	Test runner terbukti hanya menyentuh database test terisolasi.	Jalankan 1 unit test murni (tests/Unit/OrderTypeNormalizationTest.php).	Hentikan eksekusi jika .env.testing mengarah ke DB demo.	Med
Phase 1	T1.1	P0	Menetapkan kontrak domain, konstanta status meja, dan kunci entitlement/setting cabang.	app/Enums/OrderType.php (Existing), app/Services/EntitlementService.php (Existing), app/Enums/TableStatusEnum.php [Proposed New File]	Definisikan TableStatusEnum (Available = 'available', Occupied = 'occupied', Reserved = 'reserved', Disabled = 'disabled'). Tetapkan kunci setting operasional cabang (pos.tables_enabled, pos.self_order_enabled) dengan default aman (true untuk tenant F&B, false untuk Retail).	T0.3	Kontrak enum dan helper pengecekan fitur cabang terdefinisi tanpa memutus kompatibilitas kode lama.	Unit test untuk TableStatusEnum dan resolusi fitur cabang.	Hapus file enum baru jika dibatalkan.	Low
Phase 2	T2.1	P0	Expand Schema pada tabel tables: memastikan dukungan indeks komposit (tenant_id, store_id, id) dan metadata rotasi QR.	database/migrations/2026_12_15_000001_expand_tables_branch_and_qr_columns.php [Proposed New File]	Tambahkan (jika belum ada dari migrasi sebelumnya): qr_token_version (UNSIGNED INT DEFAULT 1), qr_rotated_at (TIMESTAMP NULL), indeks UNIQUE(tenant_id, store_id, id), dan indeks (tenant_id, store_id, is_active, status). Jangan hapus kolom lama.	T1.1	Migrasi berhasil dijalankan secara idempoten tanpa mengunci tabel secara destruktif.	tests/Feature/Domain2/MigrationIntegrityTest.php	migrate:rollback --step=1 menghapus indeks dan kolom tambahan baru.	Med
Phase 2	T2.2	P0	Expand Schema pada tabel orders: menambahkan table_id nullable beserta Composite Foreign Key ke tables.	database/migrations/2026_12_15_000002_add_branch_scoped_table_id_to_orders_table.php [Proposed New File]	Tambahkan kolom table_id (BIGINT UNSIGNED NULLABLE) setelah store_id pada orders. Tambahkan indeks (tenant_id, store_id, table_id, status) dan FOREIGN KEY (tenant_id, store_id, table_id) REFERENCES tables(tenant_id, store_id, id) ON DELETE RESTRICT. Perbarui trigger imutabilitas orders (dari 2026_12_02_000000_add_immutability_triggers.php) hanya jika diperlukan agar mengizinkan backfill satu kali atau menjaga table_id tetap imutabel setelah order selesai.	T2.1	orders.table_id menolak referensi ke meja dari store_id atau tenant_id yang berbeda di level SQL.	Feature test yang menyisipkan Order dengan table_id milik cabang lain dan memastikan SQL exception terlempar.	Drop FK dan kolom table_id via down().	High
Phase 2	T2.3	P0	Membuat command rekonsiliasi & backfill deterministik untuk menghubungkan tables.store_id dan orders.table_id.	app/Console/Commands/BackfillBranchTablesAndOrders.php [Proposed New File], database/sql/verify/01_fk_orphans.sql (Existing)	Implementasikan algoritma 4-aturan (Bagian J.1 manageTableArchitecture.md): (1) inferensi dari orders, (2) inferensi tenant 1-store, (3) kloning meja lintas cabang jika satu nama meja dipakai di >1 store, (4) karantina meja ambigu (is_active = false, status = 'disabled'). Isi orders.table_id berdasarkan kecocokan (tenant_id, store_id, table_number). Sediakan mode --dry-run.	T2.2	0 baris meja aktif memiliki store_id tidak valid; seluruh orders historis yang memiliki pasangan meja valid di cabangnya tertaut ke orders.table_id.	Feature test backfill dengan skenario: 1-store, multi-store shared table number, dan orphan ambigu.	Command bersifat idempoten; data asli table_number pada orders tidak pernah dihapus.	High
Phase 2	T2.4	P1	Memperketat constraint tables.store_id (NOT NULL) dan keunikan nama meja per (tenant_id, store_id) setelah backfill terverifikasi.	database/migrations/2026_12_15_000003_enforce_tables_branch_constraints.php [Proposed New File], app/Console/Commands/VerifyDatabase.php (Existing)	Pastikan FOREIGN KEY (tenant_id, store_id) REFERENCES stores(tenant_id, id) aktif dan constraint unik nama meja terikat pada (tenant_id, store_id) (bukan global atau tenant-wide). Tambahkan pengecekan ke VerifyDatabase.php.	T2.3	VerifyDatabase melaporkan 0 pelanggaran orphan atau duplikasi nama meja dalam cabang yang sama.	tests/Feature/Domain2/VerifyDatabaseCommandTest.php	Rollback constraint jika ditemukan baris karantina yang belum diproses.	Med
Phase 3	T3.1	P0	Memperkuat model Table dan Order dengan global scope cabang, trait penugasan otomatis, dan relasi yang tepat.	app/Models/Table.php, app/Models/Order.php, app/Models/Store.php (Existing)	Pada Table.php, pastikan penggunaan ScopedToTenant, AssignsTenant, ScopedToStore, dan AssignsStore. Tambahkan relasi store(), orders(), dan activeOrders() (dibatasi where('store_id', $this->store_id)). Pada Order.php, tambahkan relasi table(): BelongsTo.	T2.4	Table::all() dalam konteks cabang A hanya mengembalikan meja cabang A. Table::create() otomatis mengisi tenant_id dan store_id dari konteks aktif.	Unit/Feature test model scoping pada Table dan Order.	Kembalikan perubahan file model via Git.	Med
Phase 3	T3.2	P0	Memperkuat TablePolicy serta validasi StoreTableRequest dan UpdateTableRequest agar terikat pada cabang aktif.	app/Policies/TablePolicy.php, app/Http/Requests/StoreTableRequest.php, app/Http/Requests/UpdateTableRequest.php (Existing)	Pada TablePolicy, verifikasi $user->tenant_id === $table->tenant_id, pastikan pengguna memiliki akses ke $table->store_id (via user_stores atau peran Owner), dan pastikan table.store_id === StoreResolver::currentStoreId(). Pada StoreTableRequest dan UpdateTableRequest, terapkan Rule::unique('tables')->where('tenant_id', ...)->where('store_id', ...)->whereNull('deleted_at').	T3.1	Pembuatan meja dengan nomor yang sama di Cabang B berhasil, sedangkan duplikasi nomor di Cabang A ditolak dengan HTTP 422. Staf Cabang A ditolak (HTTP 403) saat mengakses meja Cabang B.	Feature test kebijakan otorisasi dan validasi request lintas cabang.	Kembalikan perubahan Policy dan Request via Git.	Med
Phase 3	T3.3	P0	Memperbarui TableService untuk operasi CRUD per cabang, rotasi kredensial QR, dan pelepasan meja yang aman.	app/Services/TableService.php, app/Services/AuditTrailService.php (Existing)	Implementasikan pembuatan token QR 64-hex kriptografis (bin2hex(random_bytes(32))), metode rotateQrToken() yang menaikkan qr_token_version & mengisi qr_rotated_at, penghapusan meja yang memvalidasi tidak adanya pesanan aktif, serta releaseTable() yang menegakkan INV-TBL-07 dengan SELECT ... FOR UPDATE. Catat setiap aksi ke AuditTrailService.	T3.2	Pelepasan atau penghapusan meja yang masih memiliki pesanan unpaid aktif ditolak oleh TableService. Rotasi QR langsung mengganti token dan menaikkan versi.	Feature test untuk TableService::rotateQrToken, releaseTable, dan deleteTable.	Kembalikan TableService.php.	Med
Phase 3	T3.4	P0	Menyatukan validasi meja, tipe layanan (OrderType), dan penguncian konkurensi di dalam TransactionService.	app/Services/TransactionService.php, app/Http/Requests/StoreTransactionRequest.php (Existing)	Di dalam transaksi DB TransactionService: (1) Jika cabang Retail (!tables_enabled) atau order_type !== OrderType::DineIn, paksa table_id = null dan table_number = null. (2) Jika order_type === OrderType::DineIn dan tables_enabled, kunci baris Table (WHERE tenant_id = ? AND store_id = ? AND id = ? FOR UPDATE), pastikan is_active = true dan status !== 'disabled', simpan table_id dan snapshot table_number = $table->name, lalu ubah table.status = 'occupied'. (3) Saat pesanan di-void, cancelled, atau completed + paid, periksa apakah masih ada pesanan aktif lain di table_id tersebut; jika tidak ada, kembalikan table.status = 'available'.	T3.3	Seluruh jalur pembuatan pesanan menegakkan invarian INV-TBL-01 s.d. INV-TBL-07 secara konsisten tanpa menduplikasi logika bisnis.	Feature test untuk Dine-In, Takeaway, Retail checkout, dan transisi void/paid ke pelepasan meja.	Kembalikan TransactionService.php.	High
Phase 4	T4.1	P0	Memperkeras middleware ResolveTableFromToken dan EnsureTableSession untuk isolasi cabang publik dan pencabutan QR instan.	app/Http/Middleware/ResolveTableFromToken.php, app/Http/Middleware/EnsureTableSession.php, routes/customer.php (Existing)	Pada ResolveTableFromToken: cari meja berdasarkan qr_token, verifikasi is_active, status !== 'disabled', store.is_active, tenant.status === 'active', dan entitlement Self-Order. Setel TenantContext dan StoreResolver dari meja yang ditemukan. Pada EnsureTableSession: ikat session cookie ke (tenant_id, store_id, table_id, qr_token_version). Jika versi token tidak cocok atau meja berubah, reset keranjang lama dan buat session baru.	T3.4	Token QR yang telah dirotasi atau milik meja/cabang nonaktif langsung ditolak (HTTP 404/410). Konteks cabang untuk request publik selalu berasal dari meja di DB.	Feature test pemindaian QR valid, QR rotasi lama, meja nonaktif, dan cabang nonaktif.	Kembalikan middleware ke versi sebelumnya.	High
Phase 4	T4.2	P1	Mempartisi CartService dan MenuCacheService berdasarkan (tenant_id, store_id, table_id).	app/Services/CartService.php, app/Services/MenuCacheService.php, app/Http/Controllers/Customer/MenuController.php, app/Http/Controllers/Customer/CartController.php (Existing)	Ubah kunci session di CartService menjadi customer_cart.{tenant_id}.{store_id}.{table_id}. Pastikan MenuController dan AddToCartRequest hanya mengizinkan produk dan modifier yang aktif pada (tenant_id, store_id) meja tersebut.	T4.1	Pelanggan yang memindai QR di Cabang B tidak pernah membawa item keranjang dari Cabang A. Produk milik Cabang B tidak dapat dimasukkan ke keranjang Cabang A.	Feature test isolasi keranjang dan produk Self-Order lintas cabang.	Kembalikan CartService.php dan MenuCacheService.php.	Med
Phase 4	T4.3	P0	Memperkeras Customer\CheckoutController, WaiterCallController, dan ExpireStaleCashSelfOrders terhadap idempotensi, rate-limiting, dan batas cabang.	app/Http/Controllers/Customer/CheckoutController.php, app/Http/Controllers/Customer/WaiterCallController.php, app/Http/Requests/Customer/StoreCheckoutRequest.php, app/Console/Commands/ExpireStaleCashSelfOrders.php, app/Events/WaiterCalled.php (Existing)	Pada CheckoutController: abaikan input harga/status/store/table dari request, wajibkan idempotency_key, delegasikan ke TransactionService, dan tambahkan rate limiter. Pada WaiterCallController: sertakan tenant_id, store_id, dan table_id pada event WaiterCalled dengan rate limit per meja. Pada ExpireStaleCashSelfOrders: saat membatalkan order tunai yang basi, lepaskan status meja kembali ke available jika tidak ada order aktif lain di meja tersebut.	T4.2	Checkout ganda dengan idempotency_key sama hanya menghasilkan 1 order; order basi yang kedaluwarsa otomatis membebaskan meja di cabangnya.	tests/Feature/SelfOrderCashCheckoutTest.php, tests/Feature/CustomerSelfOrderPatchesTest.php	Kembalikan perubahan controller dan command.	High
Phase 5	T5.1	P1	Memperbarui TableController dan tampilan Blade resources/views/tables/* untuk operasional multi-cabang dan manajemen QR.	app/Http/Controllers/TableController.php, resources/views/tables/index.blade.php, resources/views/tables/create.blade.php, resources/views/tables/edit.blade.php (Existing)	Tampilkan indikator cabang aktif pada halaman manajemen meja, tombol "Rotasi QR" dengan konfirmasi, status okupansi real-time beserta nomor pesanan aktif, dan tombol "Bebaskan Meja" (hanya aktif bila memenuhi INV-TBL-07). Sembunyikan menu/rute meja jika cabang/tenant tidak memiliki fitur meja (EnforceEntitlement + konfigurasi cabang).	T3.3	UI manajemen meja hanya menampilkan meja cabang aktif, menampilkan peringatan saat cabang belum memiliki meja (empty state), dan menyembunyikan fitur meja untuk akun Retail.	Feature test rendering Blade tables/index per cabang dan otorisasi rute.	Kembalikan view dan TableController.php.	Low
Phase 5	T5.2	P0	Memperbarui komponen Livewire Kasir\CreateOrder, Kasir\OrderHistory, dan Kds\Board agar terikat ketat pada cabang aktif.	app/Livewire/Kasir/CreateOrder.php, resources/views/livewire/kasir/create-order.blade.php, app/Livewire/Kasir/OrderHistory.php, app/Livewire/Kds/Board.php (Existing)	Pada CreateOrder.php: simpan $activeStoreId saat mount(), muat daftar meja hanya untuk $activeStoreId, sembunyikan pemilih meja jika orderType !== 'dine_in' atau cabang Retail, dan validasi bahwa $activeStoreId === StoreResolver::currentStoreId() sebelum submitOrder(). Pada Kds\Board.php: tampilkan informasi nomor meja dari order.table_number yang di-scope ke $activeStoreId.	T3.4	Kasir hanya melihat meja dari cabang aktif; saat memilih Takeaway atau pada akun Retail, pemilih meja tidak wajib dan tidak memblokir transaksi.	tests/Feature/Domain2/LivewirePosAuthorizationEnforcementTest.php, tests/Feature/CheckoutGuardTest.php	Kembalikan komponen Livewire dan view terkait.	Med
Phase 5	T5.3	P1	Memperkeras StoreSwitchController dan navigasi sidebar untuk mereset state meja saat perpindahan cabang dan menegakkan gating Retail vs F&B.	app/Http/Controllers/StoreSwitchController.php, resources/views/components/branch-switcher.blade.php, config/navigation.php, resources/views/layouts/navigation.blade.php (Existing)	Saat StoreSwitchController mengganti cabang aktif, bersihkan session state kasir (selected_table_id, draft cart cabang lama). Pada navigation.blade.php / config/navigation.php, tampilkan menu "Kelola Meja" hanya jika tenant & cabang aktif mengaktifkan fitur manajemen meja.	T5.1, T5.2	Berpindah dari Cabang A ke Cabang B membersihkan pilihan meja Cabang A dan memperbarui daftar meja ke Cabang B tanpa kebocoran state.	tests/Feature/Navigation/SidebarIntegrityTest.php, tests/Feature/Auth/SessionRevocationAndBranchAccessTest.php	Kembalikan StoreSwitchController.php dan navigasi.	Low
Phase 6	T6.1	P0	Mengimplementasikan dan mengeksekusi 15 skenario pengujian invarian bisnis, isolasi cabang, idempotensi, dan konkurensi.	tests/Feature/TableManagement/BranchTableIsolationTest.php [Proposed New File], tests/Feature/TableManagement/SelfOrderQrSecurityTest.php [Proposed New File], tests/Feature/TableManagement/TableConcurrencyAndLifecycleTest.php [Proposed New File]	Implementasikan ke-15 skenario pengujian wajib (dirinci pada Bagian 4 di bawah). Gunakan koneksi database terisolasi. Untuk pengujian konkurensi kunci baris, jalankan terhadap driver MySQL/PostgreSQL test atau simulasi transaksi deterministik sebagaimana pola ProvisioningRealConcurrencyTest.php.	T4.3, T5.3	Seluruh 15 skenario pengujian lulus tanpa melanggar satu pun invarian INV-TBL-01 s.d. INV-TBL-07.	Eksekusi PHPUnit pada lingkungan test terisolasi.	Perbaiki regresi pada lapisan aplikasi sebelum melanjutkan ke Fase 7.	Med
Phase 7	T7.1	P1	Verifikasi integritas database akhir, kesiapan observabilitas, dan dokumentasi sign-off produksi.	app/Console/Commands/VerifyDatabase.php, database/sql/verify/01_fk_orphans.sql, database/sql/verify/02_unique_keys.sql, docs/implementation/implementation-status.md (Existing)	Tambahkan pemeriksaan integritas tables (store_id valid) dan orders.table_id ((tenant_id, store_id, table_id) cocok dengan tables) ke 01_fk_orphans.sql dan VerifyDatabase.php. Verifikasi metrik log audit dan prosedur rollback.	T6.1	php artisan pos:verify-database (pada DB test/staging) lulus 100%; dokumentasi status implementasi diperbarui.	VerifyDatabaseCommandTest.php	Gunakan prosedur forward-recovery jika ditemukan anomali data pada lingkungan staging.	Low
4. Desain Pengujian Wajib (15 Mandatory Invariant Test Cases)
Setiap kasus uji di bawah ini wajib diimplementasikan pada Fase 6 (T6.1) untuk membuktikan invarian bisnis:

TC-01 — Isolasi Lintas Tenant (Tenant A cannot access Tenant B's table):

Setup: Buat Tenant A (Store A1, Table T_A1) dan Tenant B (Store B1, Table T_B1).

Aksi: Login sebagai Owner/Manajer Tenant A, kirim request GET/PUT/DELETE ke TableController untuk T_B1 dan coba buat pesanan dengan table_id = T_B1.id.

Asersi: Server mengembalikan HTTP 404/403; baris T_B1 di DB tidak berubah; tidak ada pesanan yang tercipta.

TC-02 — Isolasi Lintas Cabang dalam Satu Tenant (Branch A cannot manipulate Branch B's table):

Setup: Buat Tenant A dengan Store A1 (Table T_A1) dan Store A2 (Table T_A2). Tugaskan Staf Kasir/Manajer hanya ke Store A1 di user_stores.

Aksi: Kirim request edit/hapus/rotasi QR untuk T_A2 atau tampilkan daftar meja di Store A1.

Asersi: Daftar meja Store A1 hanya memuat T_A1; aksi terhadap T_A2 ditolak dengan HTTP 403/404.

TC-03 — Penolakan Identitas Meja Palsu (A forged table identifier is rejected):

Setup: Login sebagai Kasir di Store A1.

Aksi: Kirim request pembuatan pesanan Dine-In dengan table_id = 999999 (tidak ada) atau qr_token palsu pada endpoint Self-Order.

Asersi: Ditolak dengan HTTP 422 (POS) atau HTTP 404 (Self-Order); tidak ada baris Order yang tersimpan.

TC-04 — Pencegahan Penautan Meja Lintas Cabang pada Order (A table cannot be linked to an order from a different branch):

Setup: Tenant A memiliki Store A1 (Table T_A1) dan Store A2 (Table T_A2). Konteks aktif adalah Store A1.

Aksi: Coba buat Order di Store A1 dengan menyuntikkan table_id = T_A2.id baik melalui CreateOrder Livewire, TransactionController, maupun langsung via Order::create().

Asersi: Ditolak oleh validasi aplikasi (TransactionService) DAN ditolak oleh Composite Foreign Key fk_orders_tenant_store_table di level database.

TC-05 — Independensi Penomoran Meja Antar Cabang (Two branches can independently use the same table number):

Setup: Tenant A memiliki Store A1 dan Store A2.

Aksi: Buat meja dengan name = '01' di Store A1, lalu beralih ke Store A2 dan buat meja dengan name = '01' di Store A2. Setelah itu, coba buat meja kedua dengan name = '01' di Store A2.

Asersi: Pembuatan '01' di Store A1 dan Store A2 sama-sama berhasil (HTTP 201/302), sedangkan pembuatan '01' kedua di Store A2 ditolak (HTTP 422).

TC-06 — Independensi Checkout Retail Tanpa Meja (Retail checkout works without any table records):

Setup: Buat Tenant Retail (0 baris di tabel tables, tables_enabled = false).

Aksi: Jalankan checkout Kasir melalui CreateOrder / TransactionService.

Asersi: Pesanan berhasil dibuat (status = completed/confirmed, table_id = NULL, table_number = NULL), stok berkurang secara akurat.

TC-07 — Checkout F&B Takeaway Tanpa Meja (F&B takeaway works without a table):

Setup: Buat Tenant F&B dengan Store A1 yang memiliki meja aktif, namun pilih order_type = 'takeaway' dan kosongkan pilihan meja.

Aksi: Kirim checkout pesanan Takeaway.

Asersi: Pesanan berhasil dibuat dengan order_type = 'takeaway' dan table_id = NULL; status meja di Store A1 tidak berubah menjadi occupied.

TC-08 — Resolusi Valid Dine-In Self-Order (Valid dine-in Self-Order resolves the intended table and branch):

Setup: Store A2 memiliki Table T_A2 (name = '05', qr_token = VALID_TOKEN_64).

Aksi: Pelanggan anonim mengakses /customer/t/{VALID_TOKEN_64}/menu, menambahkan produk Store A2 ke keranjang, dan melakukan checkout.

Asersi: Order yang tercipta memiliki tenant_id = TenantA.id, store_id = StoreA2.id, table_id = T_A2.id, table_number = '05', order_type = 'dine_in', dan T_A2.status berubah menjadi 'occupied'.

TC-09 — Penolakan Kredensial QR yang Dicabut/Dirotasi (Revoked/invalid QR credentials cannot create orders):

Setup: Pelanggan memuat halaman menu dengan OLD_TOKEN. Manajer Store A2 memanggil TableService::rotateQrToken(T_A2) sehingga menghasilkan NEW_TOKEN dan menaikkan qr_token_version.

Aksi: Pelanggan mencoba mengirim form checkout menggunakan URL OLD_TOKEN atau session cookie lama.

Asersi: Permintaan ditolak oleh ResolveTableFromToken / EnsureTableSession (HTTP 404/410); tidak ada pesanan yang dibuat.

TC-10 — Idempotensi Permintaan Ganda (Repeated requests do not create duplicate orders or payments):

Setup: Siapkan payload checkout (Kasir maupun Self-Order) dengan idempotency_key = 'idem-uuid-001'.

Aksi: Kirim permintaan checkout dua kali berturut-turut dengan idempotency_key yang sama.

Asersi: Hanya 1 baris Order, 1 PaymentAttempt, dan 1 kali pemotongan StockBalance yang tercatat di database; respons kedua mengembalikan referensi pesanan pertama.

TC-11 — Kalkulasi Ulang Harga Otoritatif di Server (Prices and totals are recalculated or validated server-side):

Setup: Produk Kopi berharga Rp 25.000 dengan PPN 11%.

Aksi: Pelanggan QR atau klien memodifikasi payload request/cookie dengan menyisipkan price = 1000, discount_amount = 50000, store_id = 999, payment_status = 'paid'.

Asersi: Server mengabaikan seluruh field yang dimanipulasi; Order tersimpan dengan harga satuan Rp 25.000, pajak 11%, payment_status = 'unpaid', dan store_id asli dari meja QR.

TC-12 — Konkurensi Operasi Meja & Pesanan Simultan (Simultaneous table/order operations preserve all defined invariants):

Setup: Gunakan dua koneksi database independen pada MySQL/PostgreSQL test (mengikuti pola ProvisioningRealConcurrencyTest.php).

Aksi: Koneksi 1 menjalankan TransactionService::createSelfOrder pada T_A1, sementara Koneksi 2 secara bersamaan mencoba menonaktifkan T_A1 (TableService::disableTable) atau memesan produk dengan sisa stok 1.

Asersi: Eksekusi ter-serialisasi oleh SELECT ... FOR UPDATE sesuai urutan kanonik tanpa deadlock; stok tidak pernah menjadi negatif; meja tidak pernah berada dalam state disabled sekaligus memiliki pesanan aktif baru.

TC-13 — Balapan Webhook Pembayaran vs Pembatalan/Rekonsiliasi (Payment callbacks and cancellation/reconciliation cannot create contradictory terminal states):

Setup: Pesanan Self-Order berada dalam status pending / unpaid pada meja T_A1.

Aksi: Jalankan webhook settlement Midtrans bersamaan dengan job ExpireStaleCashSelfOrders / void.

Asersi: Kunci baris Order memastikan hanya satu transisi sah yang menang; jika order telah paid, pembatalan ditolak dan meja T_A1 tetap occupied.

TC-14 — Pembersihan State Saat Perpindahan Cabang (Branch switching does not reuse stale table or order state):

Setup: Kasir memilih Meja T_A1 di Store A1 pada komponen CreateOrder, lalu memanggil StoreSwitchController untuk beralih ke Store A2.

Aksi: Kasir melanjutkan pembuatan pesanan di Store A2.

Asersi: Pilihan T_A1 telah dibersihkan; jika tab lama di browser mencoba mengirim submit dengan konteks Store A1 yang sudah basi, server menolak request tersebut dan tidak menautkan T_A1 ke pesanan Store A2.

TC-15 — Keamanan Retry Antrean & Event Pasca-Komit (Queue retries and after-commit events do not duplicate irreversible side effects):

Setup: Paksa kegagalan sementara pada listener/job setelah transaksi pesanan atau simulasikan rollback transaksi sebelum commit.

Aksi: Periksa apakah event WaiterCalled / notifikasi KDS terkirim saat transaksi di-rollback, dan jalankan retry pada job webhook (ProcessWebhookOrderJob).

Asersi: Tidak ada event yang bocor saat transaksi rollback (DB::afterCommit); retry job bersifat idempoten dan tidak menduplikasi pemotongan stok atau entri pembayaran.

