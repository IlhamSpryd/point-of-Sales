# Apeiron POS — Multi-Branch Table Management & Self-Order Architecture

**Dokumen**: `docs/architecture/manageTableArchitecture.md`  
**Status**: Target Architecture Specification (A0 Principal Solution Architect)  
**Kompatibilitas**: `ARCHITECTURE_CONTRACT.md`, `OWNERSHIP_MODEL.md`, `AUTHORIZATION_MODEL.md`, `FINANCIAL_INVARIANTS.md`, `INVENTORY_INVARIANTS.md`

---

## A. Executive Architecture Summary

### A.1 Masalah Bisnis
Apeiron POS adalah platform ERP/POS SaaS multi-tenant dan multi-cabang (*multi-branch/multi-store*) yang melayani segmen bisnis F&B (Dine-In, Takeaway, Delivery/Omnichannel) serta Retail. Saat ini terdapat masalah struktural di mana **Table Management belum ter-scope secara utuh dan konsisten per cabang (`Store`)**. Hal ini berisiko menyebabkan:
1. Meja dari satu cabang terlihat, dapat dimodifikasi, atau dapat dipilih oleh staf di cabang lain dalam tenant yang sama.
2. Konflik penomoran meja antar cabang (misalnya Cabang A dan Cabang B tidak dapat sama-sama menggunakan nama/nomor meja "01" jika validasi keunikan di lapisan aplikasi masih terikat pada level tenant atau global).
3. Ambiguitas atribusi pesanan (`Order`) ketika pesanan ditautkan ke meja hanya menggunakan nilai string `table_number` tanpa kunci asing komposit `(tenant_id, store_id, table_id)`.
4. Risiko penyalahgunaan endpoint publik QR Self-Order apabila resolusi meja dan sesi pelanggan tidak mengunci konteks `(tenant_id, store_id, table_id)` secara otoritatif di sisi server.

### A.2 Tujuan Arsitektural
1. **Isolasi Kepemilikan Mutlak (`Tenant -> Store -> Table`)**: Menjamin setiap meja dimiliki secara eksplisit oleh satu `Tenant` dan satu `Store` (cabang), ditegakkan baik oleh *Composite Foreign Key* di database maupun global scope dan *Policy* di lapisan aplikasi.
2. **Siklus Hidup Pesanan Tunggal & Otoritatif**: Memastikan seluruh kanal pemesanan (Kasir Livewire, TransactionController, QR Self-Order pelanggan, dan Webhook Omnichannel) menggunakan aturan validasi kepemilikan, harga, pajak, diskon, dan stok yang konsisten melalui `TransactionService`.
3. **Dukungan Multi-Segmen Bisnis**: Memastikan bisnis F&B Dine-In mendapatkan manajemen meja penuh dan QR Self-Order, F&B Takeaway dapat bertransaksi tanpa memilih meja, dan bisnis Retail beroperasi sepenuhnya tanpa konfigurasi atau ketergantungan pada tabel `tables`.
4. **Keamanan & Integritas Konkurensi Kelas Enterprise**: Mencegah IDOR lintas cabang, manipulasi harga/status dari sisi klien pada QR Self-Order, serta *race condition* atau *deadlock* antara staf kasir, pelanggan QR, dan callback pembayaran.

### A.3 Solusi yang Direkomendasikan
1. **Penyatuan Model Kepemilikan Cabang pada `Store`**: Menetapkan `app/Models/Store.php` sebagai representasi kanonik cabang. Model `app/Models/Table.php` wajib menerapkan `ScopedToTenant`, `AssignsTenant`, `ScopedToStore`, dan `AssignsStore`.
2. **Penambahan Referensi Kanonik `orders.table_id` dengan *Composite Foreign Key***: Menambahkan kolom `table_id` (nullable BIGINT) pada tabel `orders` yang merujuk `(tenant_id, store_id, table_id) -> tables(tenant_id, store_id, id)`, sambil mempertahankan `orders.table_number` sebagai *immutable snapshot* untuk kebutuhan struk dan audit historis.
3. **Arsitektur *Derived Table State* dengan *Lightweight Session Guard***: Status operasional meja (`available`, `occupied`, `reserved`, `disabled`) dikelola secara transaksional bersama siklus hidup `Order` aktif, sementara `EnsureTableSession` mengamankan sesi peramban pelanggan QR tanpa menciptakan sumber kebenaran finansial kedua.
4. **Kredensial QR Berentropi Tinggi yang Dapat Dirotasi**: Setiap meja memiliki `qr_token` kriptografis (opaque token) yang diselesaikan oleh middleware `ResolveTableFromToken` untuk menetapkan konteks `Tenant` dan `Store` secara otomatis bagi pengguna anonim.

### A.4 Keputusan Kunci & Rasional
- **Menggunakan `Store` sebagai entitas cabang**: Repositori telah memiliki `Store`, `StoreResolver`, `StoreScope`, `StoreSwitchController`, dan tabel `user_stores`. Memperkenalkan model `Branch` baru akan menciptakan duplikasi sumber kebenaran.
- **Tidak membuat tabel `table_sessions` finansial yang berat**: Karena `Order`, `Payment`, dan `PaymentAttempt` sudah memiliki mesin status, trigger imutabilitas, dan rekonsiliasi Midtrans yang matang, penambahan entitas sesi finansial terpisah akan melanggar *Single Source of Truth*. Sebagai gantinya, sesi meja dibatasi sebagai *access & concurrency guard* ringan.
- **Penomoran meja unik per `(tenant_id, store_id)`**: Memungkinkan setiap cabang memiliki tata letak penomoran mandiri ("Meja 1", "VIP 1") dengan identitas primer internal (`tables.id`) yang stabil.

### A.5 Non-Goals Eksplisit
- Tidak merombak mesin akuntansi, ledger stok (`StockBalance` / `IngredientStockMovement`), atau integrasi omnichannel GoFood/GrabFood di luar batas integrasi pesanan.
- Tidak membangun desainer denah lantai grafis 2D/3D (*drag-and-drop canvas*) pada fase ini.
- Tidak mengubah atau mereset proses generator dataset 12 bulan (`PopulateApeironFullYearDataset`).

### A.6 Manfaat & Trade-Offs
- **Manfaat**: Isolasi data cabang terjamin di level SQL, penghapusan bug tabrakan nomor meja antar cabang, perlindungan penuh terhadap manipulasi parameter QR, dan kompatibilitas 100% dengan alur checkout Retail/Takeaway.
- **Trade-Off**: Penambahan *composite foreign key* `(tenant_id, store_id, id)` pada `tables` dan `(tenant_id, store_id, table_id)` pada `orders` membutuhkan indeks komposit tambahan dan migrasi backfill bertahap (*expand-and-contract*), namun biaya penyimpanan indeks ini sangat kecil dibandingkan jaminan integritas referensial lintas cabang.

---

## B. Current-State Assessment

### B.1 Peta Implementasi Saat Ini & Klasifikasi Bukti
Berdasarkan inventarisasi struktur pohon kerja repositori:
- **Verified**: Fakta yang didukung langsung oleh keberadaan file, struktur direktori, nama kelas, dan urutan migrasi di dalam repositori.
- **Inferred**: Kesimpulan arsitektural yang sangat kuat berdasarkan urutan migrasi, penamaan file pengujian/remediasi, dan pola desain modul terkait.
- **Unverified**: Detail implementasi internal baris kode yang memerlukan pembacaan file secara langsung pada Fase 0 sebelum eksekusi kode.
- **Proposed**: Desain target yang ditetapkan oleh spesifikasi arsitektur ini.

### B.2 Matriks Bukti (*Evidence Matrix*)

| No | Concern | Existing Implementation | Evidence (File Paths) | Current Gap | Target Decision (Proposed) |
| :- | :--- | :--- | :--- | :--- | :--- |
| 1 | **Tenant & Branch Ownership** | **Verified**: Hirarki `Tenant` -> `Store` dengan penugasan pengguna melalui `user_stores`. | `app/Models/Tenant.php`, `app/Models/Store.php`, `database/migrations/2026_09_25_211159_add_tenant_and_store_core.php`, `2026_10_04_154119_create_user_stores_table.php` | **Inferred**: Model `Table` belum sepenuhnya menegakkan isolasi `Store` di seluruh operasi CRUD dan query POS. | Penegakan kanonik `Tenant -> Store -> Table` di DB (Composite FK) dan aplikasi (`ScopedToStore`, `AssignsStore`, `TablePolicy`). |
| 2 | **Active-Branch Resolution & Switching** | **Verified**: Diselesaikan melalui `StoreResolver` dan diubah melalui `StoreSwitchController` serta komponen `branch-switcher`. | `app/Services/Context/StoreResolver.php`, `app/Http/Controllers/StoreSwitchController.php`, `resources/views/components/branch-switcher.blade.php` | **Inferred**: Perpindahan cabang belum membersihkan pilihan meja atau state keranjang dine-in yang sedang terbuka di sesi/Livewire. | `StoreSwitchController` wajib memvalidasi keanggotaan `user_stores`, mereset state meja/keranjang cabang lama, dan mencatat log audit. |
| 3 | **Table Schema, Migrations & Lifecycle** | **Verified**: Tabel `tables` dibuat sebelum multi-store, kemudian diperbarui lewat migrasi Desember (`branch_ownership`, `table_name_per_store`). | `database/migrations/2026_09_17_074003_create_tables_table.php`, `2026_09_18_234707_update_tables_structure_for_enterprise.php`, `2026_12_01_000000_branch_ownership.php`, `2026_12_01_000001_table_name_per_store.php`, `app/Models/Table.php`, `app/Services/TableService.php` | **Inferred**: Validasi `StoreTableRequest` / `UpdateTableRequest` dan `TableService` belum selaras dengan keunikan `(tenant_id, store_id, name)` dan scoping cabang. | Menyelaraskan skema `tables`, FormRequest, `TableService`, dan `TableController` agar 100% terikat pada `store_id` aktif dari `StoreResolver`. |
| 4 | **Order Schema & Table Linkage** | **Verified**: `orders` memiliki `table_number` sejak migrasi awal dan `tenant_id`/`store_id` dari migrasi multitenant. | `database/migrations/2026_09_17_074004_add_table_number_to_orders_table.php`, `2026_09_25_213221_add_tenant_and_store_to_transactional_tables.php`, `app/Models/Order.php` | **Inferred**: Ketergantungan pada string `table_number` tanpa FK `table_id` komposit memungkinkan ambiguitas meja lintas cabang. | Menambahkan `orders.table_id` dengan Composite FK `(tenant_id, store_id, table_id) -> tables(tenant_id, store_id, id)` dan mempertahankan `table_number` sebagai snapshot. |
| 5 | **Service Types & Channels** | **Verified**: Menggunakan enum `OrderType` dan cast `OrderTypeCast` dengan normalisasi nilai takeaway. | `app/Enums/OrderType.php`, `app/Casts/OrderTypeCast.php`, `database/migrations/2026_12_06_000002_normalize_order_type_takeaway_values.php`, `tests/Unit/OrderTypeNormalizationTest.php` | **Inferred**: Aturan kapan meja wajib vs dilarang belum ditegakkan secara seragam di semua pintu masuk pesanan. | Menegakkan invarian: `DineIn` (dengan fitur meja aktif) wajib `table_id` valid; `Takeaway`, `Delivery`/Omnichannel, dan Retail wajib `table_id = NULL`. |
| 6 | **Self-Order Routes & Middleware** | **Verified**: Rute khusus di `routes/customer.php` dengan middleware `ResolveTableFromToken` dan `EnsureTableSession`. | `routes/customer.php`, `app/Http/Middleware/ResolveTableFromToken.php`, `app/Http/Middleware/EnsureTableSession.php`, `app/Http/Controllers/Customer/{MenuController,CartController,CheckoutController,WaiterCallController}.php` | **Inferred**: Risiko pencampuran keranjang (`CartService`) jika pelanggan memindai QR dari meja/cabang berbeda pada perangkat yang sama. | Namespace session `CartService` dan `EnsureTableSession` wajib di-key berdasarkan `(tenant_id, store_id, table_id, qr_token_version)`. |
| 7 | **QR Generation & Token Storage** | **Verified**: Dikelola oleh `TableController` / `TableService` dan diuji pada `CustomerSelfOrderPatchesTest`. | `app/Http/Controllers/TableController.php`, `app/Services/TableService.php`, `tests/Feature/CustomerSelfOrderPatchesTest.php` | **Unverified**: Panjang entropi token dan mekanisme pencabutan (*revocation*) token lama saat dirotasi. | Menyimpan `qr_token` (64 karakter hex / ULID+random) unik dan `qr_token_version` / `qr_rotated_at` untuk pencabutan instan. |
| 8 | **Payments, Midtrans & Webhooks** | **Verified**: Mendukung pembayaran tunai, kartu, split tender, dan Midtrans dengan rekonsiliasi terjadwal. | `app/Models/Payment.php`, `app/Models/PaymentAttempt.php`, `app/Http/Controllers/MidtransNotificationController.php`, `app/Console/Commands/ReconcilePendingMidtransOrders.php`, `app/Console/Commands/ExpireStaleCashSelfOrders.php` | **Inferred**: Penutupan/pembebasan status meja saat pesanan kedaluwarsa (`ExpireStaleCashSelfOrders`) atau dibayar via webhook harus bebas *race condition*. | Rekonsiliasi status meja dilakukan secara atomik di dalam transaksi perubahan status `Order` menggunakan kunci baris. |
| 9 | **Inventory, Modifiers, Discounts & Pricing** | **Verified**: Memiliki layanan dan model khusus untuk produk, bahan baku, modifier, dan diskon per cabang. | `app/Services/TransactionService.php`, `app/Models/StockBalance.php`, `app/Models/Modifier.php`, `app/Models/Discount.php`, `app/Models/Scopes/DiscountStoreScope.php` | **Inferred**: Checkout Self-Order tidak boleh mempercayai kalkulasi harga, diskon, atau pajak dari request/session tanpa revalidasi. | `TransactionService` menghitung ulang seluruh harga produk, modifier, diskon (`DiscountStoreScope`), dan pajak di sisi server saat checkout. |
| 10 | **Policies, Entitlements & Isolation** | **Verified**: Memiliki `EntitlementService`, `EnforceEntitlement`, dan `TablePolicy`. | `app/Services/EntitlementService.php`, `app/Http/Middleware/EnforceEntitlement.php`, `app/Policies/TablePolicy.php`, `docs/architecture/AUTHORIZATION_MODEL.md` | **Inferred**: `TablePolicy` dan navigasi UI perlu memastikan pengecekan akses cabang (`user_stores`) serta menyembunyikan/memblokir fitur meja untuk tenant Retail. | Matriks otorisasi 3 lapis: Entitlement Plan Tenant + Pengaturan Operasional Store + `TablePolicy` (validasi `tenant_id` & akses `store_id`). |
| 11 | **Queues, Events & KDS** | **Verified**: Memiliki `WaiterCalled` event, `Kds\Board`, `KdsService`, dan job ekspor/webhook. | `app/Events/WaiterCalled.php`, `app/Livewire/Kds/Board.php`, `app/Services/KdsService.php`, `routes/channels.php` | **Inferred**: Event `WaiterCalled` dan tiket KDS wajib diisolasi per `(tenant_id, store_id)` agar panggilan pelayan tidak muncul di cabang lain. | Channel broadcast dan query KDS wajib mencantumkan `tenant_id` dan `store_id` serta dieksekusi `afterCommit()`. |
| 12 | **Tests & DB Compatibility** | **Verified**: Memiliki suite pengujian fitur dan konkurensi yang komprehensif. | `tests/Feature/Domain1/*`, `tests/Feature/Domain2/*`, `tests/Feature/SelfOrderCashCheckoutTest.php`, `tests/Feature/IdempotencyLockTest.php` | **Verified**: Belum ada file pengujian khusus yang menguji isolasi meja lintas cabang (`BranchTableIsolationTest`). | Menambahkan suite pengujian isolasi meja lintas cabang, rotasi QR, non-table retail/takeaway checkout, dan konkurensi meja. |

### B.3 Alur Data Saat Ini & Inkonsistensi yang Teridentifikasi
1. **Jalur Masuk Pesanan Ganda (*Multiple Order-Entry Paths*)**:
   - Staf POS dapat membuat pesanan melalui komponen Livewire `app/Livewire/Kasir/CreateOrder.php` maupun endpoint HTTP `app/Http/Controllers/TransactionController.php`.
   - Pelanggan Self-Order membuat pesanan melalui `app/Http/Controllers/Customer/CheckoutController.php`.
   - Integrasi pihak ketiga masuk melalui `app/Http/Controllers/Api/WebhookController.php` -> `app/Jobs/ProcessWebhookOrderJob.php`.
2. **Ketidaksinkronan Referensi Meja**:
   - Pada migrasi awal (`2026_09_17_074004_add_table_number_to_orders_table.php`), pesanan hanya mencatat `table_number`. Ketika sistem berkembang menjadi multi-cabang (`2026_09_25_211159_add_tenant_and_store_core.php` dan `2026_12_01_000001_table_name_per_store.php`), penggunaan `table_number` tanpa `table_id` yang terikat pada `(tenant_id, store_id)` menciptakan celah integritas referensial.

---

## C. Domain and Bounded-Context Design

### C.1 Pembagian Konteks dan Sumber Kebenaran Otoritatif (*Authoritative State Ownership*)

1. **Tenant & Branch Context (`Tenant`, `Store`, `TenantContext`, `StoreResolver`)**:
   - **Tanggung Jawab**: Menyimpan identitas perusahaan (`Tenant`), cabang operasional (`Store`), dan hak akses staf ke cabang (`user_stores`).
   - **Otoritas State**: Satu-satunya sumber kebenaran untuk status aktif tenant, status operasional cabang (`is_active` / archival), dan cabang aktif staf saat ini.
2. **Feature Entitlements Context (`Plan`, `PlanEntitlement`, `TenantSubscription`, `TenantEntitlementOverride`, `EntitlementService`)**:
   - **Tanggung Jawab**: Menentukan apakah suatu tenant berhak mengakses modul `table_management`, `self_order_qr`, dan `kds`.
   - **Otoritas State**: Satu-satunya sumber kebenaran untuk batas kuota dan ketersediaan modul komersial pada level tenant, yang kemudian dikombinasikan dengan pengaturan operasional per `Store`.
3. **Table Management Context (`Table`, `TableService`, `TablePolicy`, `TableController`)**:
   - **Tanggung Jawab**: Mengelola siklus hidup master meja per cabang (pembuatan, pengubahan kapasitas/nama, penonaktifan, pembuatan/rotasi kredensial QR, dan status okupansi operasional).
   - **Otoritas State**: Pemilik otoritatif entitas `Table` dan kredensial QR-nya (`qr_token`, `qr_token_version`). **Bukan** pemilik state pembayaran atau pesanan.
4. **Self-Order & Table Session Guard Context (`ResolveTableFromToken`, `EnsureTableSession`, `CartService`, `Customer\*Controller`)**:
   - **Tanggung Jawab**: Menyelesaikan identitas `Tenant`, `Store`, dan `Table` dari token QR publik, mengelola sesi pemesanan anonim di meja tersebut, mengelola keranjang sementara per `(tenant_id, store_id, table_id)`, serta memfasilitasi panggilan pelayan (`WaiterCalled`).
   - **Otoritas State**: Pemilik state keranjang sementara pelanggan sebelum checkout. Begitu checkout berhasil, otoritas berpindah sepenuhnya ke *Sales/Orders Context*.
5. **Sales / Orders Context (`Order`, `OrderItem`, `OrderItemModifier`, `TransactionService`)**:
   - **Tanggung Jawab**: Mengelola pembuatan pesanan, kalkulasi harga/pajak/diskon/service charge, validasi idempotensi (`idempotency_key`), dan transisi status pesanan (`OrderStatus`).
   - **Otoritas State**: Satu-satunya sumber kebenaran untuk eksistensi pesanan, total tagihan, item pesanan, dan keterkaitan pesanan dengan meja (`table_id` + snapshot `table_number`).
6. **Payments Context (`Payment`, `PaymentAttempt`, `PaymentRefund`, `MidtransNotificationController`, `ReturnRefundService`)**:
   - **Tanggung Jawab**: Mengelola inisiasi pembayaran, split tender, verifikasi signature webhook Midtrans, rekonsiliasi pembayaran tertunda, dan refund.
   - **Otoritas State**: Satu-satunya sumber kebenaran untuk `payment_status` (`PaymentStatusEnum`) dan `PaymentAttemptStatus`. Status visual meja tidak pernah boleh mengesampingkan status pembayaran di `Order` / `Payment`.
7. **Inventory Context (`Product`, `Ingredient`, `StockBalance`, `StockMovement`, `IngredientStockMovement`)**:
   - **Tanggung Jawab**: Mengelola ketersediaan produk dan pemotongan/kompensasi stok per cabang (`store_id`).
   - **Otoritas State**: Satu-satunya sumber kebenaran untuk saldo stok cabang.
8. **Kitchen / KDS Context (`Kds\Board`, `KdsService`, `PreparationStatus`)**:
   - **Tanggung Jawab**: Mengelola antrean persiapan makanan di dapur per cabang dan memperbarui `preparation_status` pada `OrderItem` / `Order`.

### C.2 Diagram Konteks Bounded-Context (Mermaid)

```mermaid
flowchart TB
    subgraph IdentityAndTenancy["1. Tenant, Branch & Entitlement Context"]
        TC[TenantContext]
        SR[StoreResolver]
        ES[EntitlementService]
    end

    subgraph TableDomain["2. Table Management Context (Branch-Scoped)"]
        TM[TableService]
        TB[(Table Model\nScopedToTenant + ScopedToStore)]
        TP[TablePolicy]
    end

    subgraph SelfOrderChannel["3. Self-Order Public Channel"]
        RT[ResolveTableFromToken]
        ETS[EnsureTableSession]
        CS[CartService\nBranch & Table Scoped]
        CC[Customer\CheckoutController]
    end

    subgraph StaffPOSChannel["4. Staff POS Channel"]
        LW[Livewire: Kasir\CreateOrder]
        TRC[TransactionController]
    end

    subgraph CoreOrderDomain["5. Authoritative Sales, Payment & Inventory"]
        TS[TransactionService]
        ORD[(Order & OrderItem)]
        PAY[(Payment & PaymentAttempt)]
        INV[(StockBalance & Movements)]
        KDS[KdsService & KDS Board]
    end

    TC --> SR
    SR --> TM
    ES --> TM
    TP --> TM
    TM --> TB

    RT -->|Resolves Tenant, Store, Table| ETS
    ETS --> CS
    CS --> CC

    CC -->|Normalized Checkout Command| TS
    LW -->|Staff Checkout Command| TS
    TRC -->|Staff Checkout Command| TS

    TS -->|Validates & Updates Occupancy| TM
    TS -->|Persists Authoritative Order| ORD
    TS -->|Initiates/Records Tender| PAY
    TS -->|Deducts Branch Stock| INV
    ORD -->|Dispatches After-Commit| KDS

    C.3 Diagram Urutan: Alur Dine-In QR Self-Order vs Staff POS (Mermaid)
    sequenceDiagram
    autonumber
    actor Cust as Pelanggan (QR)
    participant RT as ResolveTableFromToken
    participant ETS as EnsureTableSession
    participant CC as Customer\CheckoutController
    participant TS as TransactionService
    participant DB as Relational DB (MySQL/PgSQL)
    participant KDS as KDS / Waiter Channel

    Cust->>RT: GET /customer/t/{qr_token}/menu
    RT->>DB: Lookup Table WHERE qr_token = :token AND is_active = 1
    DB-->>RT: Table (id, tenant_id, store_id, qr_token_version, status)
    RT->>RT: Verify Tenant active, Store active, Entitlement enabled
    RT->>ETS: Bind TenantContext(tenant_id) & StoreContext(store_id)
    ETS->>ETS: Validate/Initialize Ephemeral Table Session Cookie
    ETS-->>Cust: Render Branch-Scoped Menu (MenuCacheService)

    Cust->>CC: POST /customer/t/{qr_token}/checkout (Idempotency-Key, Items)
    CC->>RT: Re-verify Token, Table active, Store active
    CC->>TS: createSelfOrder(StoreCheckoutCommand)
    TS->>DB: BEGIN TRANSACTION
    TS->>DB: SELECT Table FOR UPDATE (Verify tenant_id, store_id, active)
    TS->>DB: Check Idempotency (tenant_id, idempotency_key)
    TS->>DB: Lock & Validate Products/StockBalance in Store
    TS->>DB: Recalculate Prices, Modifiers, Discounts, Taxes
    TS->>DB: INSERT Order (tenant_id, store_id, table_id, table_number, order_type='dine_in')
    TS->>DB: INSERT OrderItems & PaymentAttempt
    TS->>DB: UPDATE Table SET status = 'occupied', current_order_id = order.id
    TS->>DB: COMMIT
    TS-->>KDS: After-Commit: Notify KDS / Kitchen Printer (Scoped to store_id)
    TS-->>Cust: Redirect to Order Status / Midtrans Payment Page

    D. Canonical Data Model
    D.1 Entity-Relationship Diagram Target (Mermaid)
    erDiagram
    TENANTS ||--o{ STORES : "owns (1:N)"
    TENANTS ||--o{ TABLES : "owns (1:N)"
    TENANTS ||--o{ ORDERS : "owns (1:N)"
    STORES ||--o{ TABLES : "scopes (1:N)"
    STORES ||--o{ ORDERS : "scopes (1:N)"
    TABLES ||--o{ ORDERS : "associated with (0:N)"
    ORDERS ||--|{ ORDER_ITEMS : "contains (1:N)"
    ORDERS ||--o{ PAYMENTS : "settled by (0:N)"
    ORDERS ||--o{ PAYMENT_ATTEMPTS : "attempted via (0:N)"

    TENANTS {
        bigint id PK
        string name
        string business_type "fnb | retail | hybrid"
        string status "active | suspended"
    }

    STORES {
        bigint id PK
        bigint tenant_id FK "Composite UK (tenant_id, id)"
        string name
        string code
        boolean is_active
        boolean tables_enabled "Branch-level operational toggle"
        boolean self_order_enabled "Branch-level QR toggle"
        timestamp deleted_at
    }

    TABLES {
        bigint id PK
        bigint tenant_id FK "NOT NULL"
        bigint store_id FK "NOT NULL, FK (tenant_id, store_id) -> stores(tenant_id, id)"
        string name "Display number/name, e.g. '01', 'VIP-1'"
        int capacity "Default 4, CHECK (capacity > 0)"
        string status "available | occupied | reserved | disabled"
        boolean is_active "Default true"
        string qr_token "CHAR(64) Nullable/Unique credential"
        int qr_token_version "Default 1, incremented on rotation"
        timestamp qr_rotated_at "Nullable"
        bigint current_order_id "Nullable, informational pointer"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }

    ORDERS {
        bigint id PK
        bigint tenant_id FK "NOT NULL"
        bigint store_id FK "NOT NULL, FK (tenant_id, store_id) -> stores(tenant_id, id)"
        bigint table_id FK "NULLABLE, FK (tenant_id, store_id, table_id) -> tables(tenant_id, store_id, id)"
        string table_number "NULLABLE, Immutable snapshot of tables.name at order creation"
        string order_type "dine_in | takeaway | delivery"
        string status "pending | confirmed | processing | completed | cancelled | voided"
        string payment_status "unpaid | partial | paid | refunded"
        string idempotency_key "NOT NULL, UK (tenant_id, idempotency_key)"
        decimal total_amount
        timestamp created_at
        timestamp updated_at
    }
    D.2 Spesifikasi Entitas & Tabel
1. Tabel stores (Adaptasi Konfigurasi Operasional Cabang)
Tujuan: Mewakili cabang fisik/operasional di bawah Tenant.

Primary Key: id (BIGINT UNSIGNED AUTO_INCREMENT).

Unique Key Pendukung Composite FK: UNIQUE idx_stores_tenant_id_id (tenant_id, id) (sudah ada dari migrasi 2026_10_09_000000_add_composite_fks_c1.php).

Kolom Konfigurasi Operasional (Proposed / Diadaptasi melalui stores atau settings per store):

Jika menggunakan settings (app/Models/Setting.php dengan store_id), kunci kanonik adalah pos.tables_enabled (boolean) dan pos.self_order_enabled (boolean). Jika disimpan pada stores, kolom tables_enabled (BOOLEAN NOT NULL DEFAULT TRUE untuk F&B, FALSE untuk Retail) menentukan apakah cabang tersebut mengoperasikan meja.

2. Tabel tables (Entitas Inti Manajemen Meja Cabang)
Tujuan: Menyimpan daftar meja fisik di suatu cabang beserta status okupansi dan kredensial QR-nya.

Primary Key: id (BIGINT UNSIGNED AUTO_INCREMENT).

Foreign Keys:

fk_tables_tenant: FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE

fk_tables_tenant_store: FOREIGN KEY (tenant_id, store_id) REFERENCES stores(tenant_id, id) ON DELETE RESTRICT (mencegah penghapusan cabang secara tidak sengaja jika masih memiliki meja, dan menolak referensi store_id milik tenant lain).

Kolom Wajib (NOT NULL):

id (BIGINT UNSIGNED)

tenant_id (BIGINT UNSIGNED)

store_id (BIGINT UNSIGNED)

name (VARCHAR(64)) — Nama atau nomor meja tampilan (mis. "01", "T-12", "Gazebo 3"). Jika skema eksisting menggunakan nama kolom table_number atau name, keduanya disinkronkan sesuai migrasi 2026_12_01_000001_table_name_per_store.php.

capacity (SMALLINT UNSIGNED DEFAULT 4)

status (VARCHAR(20) DEFAULT 'available') — Nilai yang diizinkan: 'available', 'occupied', 'reserved', 'disabled'.

is_active (BOOLEAN DEFAULT TRUE)

qr_token_version (INT UNSIGNED DEFAULT 1)

created_at, updated_at (TIMESTAMP)

Kolom Nullable:

qr_token (VARCHAR(64) NULL) — Token kriptografis 256-bit (hex 64 karakter) untuk URL Self-Order.

qr_rotated_at (TIMESTAMP NULL) — Waktu terakhir token QR dirotasi/dicabut.

deleted_at (TIMESTAMP NULL) — Untuk soft delete.

Unique Constraints:

uk_tables_tenant_store_id: UNIQUE (tenant_id, store_id, id) — Wajib sebagai target composite foreign key dari tabel orders.

uk_tables_store_name_active: Keunikan nama meja per cabang untuk baris yang belum dihapus (UNIQUE (tenant_id, store_id, name, deleted_at) atau menggunakan virtual generated column / functional index sesuai konvensi 2026_09_20_000001_harden_soft_delete_unique_constraint_on_tables.php dan 2026_12_01_000001_table_name_per_store.php).

uk_tables_qr_token: UNIQUE (qr_token) — Menjamin tidak ada dua meja di seluruh platform yang memiliki token QR aktif yang sama.

Check Constraints:

chk_tables_status: CHECK (status IN ('available', 'occupied', 'reserved', 'disabled'))

chk_tables_capacity: CHECK (capacity >= 1 AND capacity <= 500)

Indexes:

idx_tables_tenant_store_status: INDEX (tenant_id, store_id, is_active, status) — Untuk pemuatan cepat dashboard meja kasir per cabang.

3. Tabel orders (Adaptasi Linkage Meja Per Cabang)
Tujuan: Mencatat transaksi penjualan secara otoritatif untuk seluruh kanal.

Perubahan Kolom:

table_id (BIGINT UNSIGNED NULLABLE) — Referensi kunci asing ke tables.id.

table_number (VARCHAR(64) NULLABLE) — Dipertahankan sebagai denormalized immutable snapshot dari tables.name pada saat pesanan dibuat agar struk historis dan trigger 2026_12_02_000000_add_immutability_triggers.php tidak terpengaruh jika meja di kemudian hari diubah namanya.

Composite Foreign Key:

fk_orders_tenant_store_table: FOREIGN KEY (tenant_id, store_id, table_id) REFERENCES tables(tenant_id, store_id, id) ON DELETE RESTRICT

Jaminan Integritas: Karena FK menggunakan 3 kolom (tenant_id, store_id, table_id), database secara matematis menolak INSERT atau UPDATE pada orders apabila table_id berasal dari store_id atau tenant_id yang berbeda! Ketika table_id IS NULL (untuk Takeaway, Delivery, atau Retail), constraint FK multi-kolom dengan klausa MATCH SIMPLE standar di MySQL/PostgreSQL mengizinkan baris disimpan selama tenant_id dan store_id valid terhadap tabel stores.

Indexes Baru:

idx_orders_store_table_active: INDEX (tenant_id, store_id, table_id, status, payment_status) — Mempercepat kueri pengecekan pesanan aktif pada suatu meja.

E. State Machines and Invariants
E.1 State Machine Meja (Table.status)
Cuplikan kode
stateDiagram-v2
    [*] --> available : Table Created (is_active = true)
    available --> occupied : Dine-In Order Created (POS / Self-Order)
    available --> reserved : Staff Marks Reserved
    reserved --> occupied : Guest Seated & Order Created
    reserved --> available : Reservation Cancelled / Released
    occupied --> available : All Active Orders Completed/Paid OR Cancelled/Voided & Table Released
    available --> disabled : Admin Disables Table (is_active = false)
    disabled --> available : Admin Enables Table (is_active = true)
Aturan Transisi Status Meja:

Meja hanya dapat berpindah ke occupied apabila is_active = true dan status IN ('available', 'reserved') (atau sudah occupied untuk penambahan pesanan pada sesi yang sama).

Meja tidak dapat dinonaktifkan (disabled) atau dihapus (soft delete) selama masih memiliki Order dengan status non-terminal (status NOT IN ('completed', 'cancelled', 'voided') atau payment_status = 'unpaid' pada pesanan aktif).

Status visual occupied atau available pada Table tidak pernah mengubah orders.payment_status. Meja yang dibersihkan (available) hanya dapat dilakukan setelah seluruh pesanan aktif di meja tersebut mencapai status pembayaran paid (untuk alur bayar di akhir) atau telah di-void/cancelled secara sah melalui TransactionService.

E.2 State Machine Kredensial QR (Table.qr_token)
Unprovisioned (qr_token = NULL): Meja baru dibuat atau QR dicabut sepenuhnya; tidak dapat diakses melalui rute publik /customer/*.

Active (qr_token != NULL, is_active = true, status != 'disabled'): QR valid dan dapat dipindai pelanggan.

Rotated (qr_token diganti baru, qr_token_version bertambah +1, qr_rotated_at = NOW()): Token lama segera menghasilkan HTTP 404/410 pada ResolveTableFromToken. Sesi peramban pelanggan yang masih menyimpan qr_token_version lama di-invalidasi oleh EnsureTableSession.

E.3 Daftar Invarian Sistem yang Dapat Diuji (Testable System Invariants)
INV-TBL-01 (Isolasi Cabang di Database & Aplikasi): Meja milik (tenant_id = T1, store_id = S1) tidak pernah dapat dibaca, diubah, dihapus, atau ditautkan ke pesanan oleh konteks (tenant_id = T1, store_id = S2) maupun (tenant_id = T2, *).

INV-TBL-02 (Keunikan Penomoran Per Cabang): Dua cabang berbeda (S1 dan S2) di dalam tenant yang sama (T1) dapat memiliki meja aktif dengan name = '01' secara bersamaan, tetapi satu cabang (S1) tidak dapat memiliki dua meja aktif (deleted_at IS NULL) dengan name = '01'.

INV-TBL-03 (Independensi Checkout Non-Meja): Pesanan dengan order_type = 'takeaway', order_type = 'delivery', atau pesanan pada cabang Retail (tables_enabled = false) wajib berhasil diproses dengan table_id = NULL meskipun tabel tables untuk cabang tersebut kosong (0 baris).

INV-TBL-04 (Kewajiban Meja pada Dine-In F&B): Jika cabang memiliki tables_enabled = true dan permintaan checkout memiliki order_type = 'dine_in', pesanan wajib menyertakan table_id yang valid, aktif, dan dimiliki oleh cabang tersebut.

INV-TBL-05 (Ketidakpercayaan Input Publik QR): Permintaan publik pada rute Self-Order tidak pernah dapat menentukan tenant_id, store_id, table_id, harga satuan produk, harga modifier, nominal diskon, tarif pajak, atau payment_status dari payload request. Seluruh konteks kepemilikan diturunkan dari qr_token meja, dan seluruh nominal finansial dihitung ulang oleh TransactionService.

INV-TBL-06 (Idempotensi Checkout): Pengiriman ulang permintaan checkout (baik dari Kasir POS maupun Self-Order) dengan (tenant_id, idempotency_key) yang sama wajib mengembalikan pesanan yang telah ada tanpa membuat baris Order, PaymentAttempt, atau pemotongan StockBalance ganda.

INV-TBL-07 (Otoritas Tunggal Status Finansial): Pembebasan meja menjadi available wajib ditolak oleh server apabila masih terdapat Order terkait di meja tersebut yang belum dibayar (payment_status IN ('unpaid', 'partial')) dan belum dibatalkan (status NOT IN ('cancelled', 'voided')).

F. API, Livewire, and Application Boundaries
Catatan: Seluruh tanda tangan metode baru atau yang diperluas di bawah ini ditandai sebagai [Proposed Target-State] untuk membedakannya dari kode eksisting.

F.1 Middleware & Resolusi Konteks
app/Http/Middleware/SetTenantContext.php & app/Services/Context/StoreResolver.php (Existing - Diperkuat):

Untuk pengguna staf terautentikasi, StoreResolver menyelesaikan active_store_id dari sesi yang telah divalidasi terhadap tabel user_stores.

Jika cabang yang sedang dipilih dinonaktifkan (is_active = false) atau dihapus, StoreResolver otomatis mengalihkan ke cabang aktif lain yang diizinkan bagi pengguna tersebut atau menampilkan halaman resources/views/home/no-access.blade.php.

app/Http/Middleware/ResolveTableFromToken.php (Existing - Diperkuat):

Menangkap parameter {token} dari rute routes/customer.php.

Mencari Table::withoutGlobalScopes()->where('qr_token', $token)->first().

Memvalidasi:

Meja ditemukan, deleted_at IS NULL, is_active === true, dan status !== 'disabled'.

Tenant pemilik meja berstatus aktif dan memiliki entitlement Self-Order (EntitlementService).

Store pemilik meja berstatus is_active === true dan mengaktifkan self_order_enabled.

Mengikat tenant_id ke TenantContext dan store_id ke StoreResolver untuk siklus request tersebut, serta menyematkan instance Table ke $request->attributes->set('resolved_table', $table).

app/Http/Middleware/EnsureTableSession.php (Existing - Diperkuat):

Memvalidasi bahwa session pelanggan saat ini cocok dengan (tenant_id, store_id, table_id, qr_token_version) dari resolved_table.

Jika pelanggan memindai QR meja baru (berbeda table_id atau store_id) atau qr_token_version di database telah meningkat karena rotasi token oleh admin, session keranjang meja lama dibersihkan dan session meja baru diinisialisasi.

F.2 Application Services & Use Cases
app/Services/TableService.php (Existing - Diperluas [Proposed Target-State Signatures]):

public function listForActiveStore(int $tenantId, int $storeId, array $filters = []): Collection

public function createTable(int $tenantId, int $storeId, array $validatedData): Table

public function updateTable(Table $table, array $validatedData): Table

public function deleteTable(Table $table): void (Menolak jika ada pesanan aktif di meja tersebut).

public function rotateQrToken(Table $table, User $actor): Table (Menghasilkan qr_token 64-hex baru, menaikkan qr_token_version, mengisi qr_rotated_at, dan mencatat ke AuditTrailService).

public function releaseTable(Table $table, User $actor): Table (Memvalidasi INV-TBL-07 bahwa tidak ada pesanan aktif yang belum lunas sebelum mengubah status ke 'available').

app/Services/CartService.php (Existing - Diperkuat):

Kunci penyimpanan session keranjang pelanggan wajib dipartisi menjadi: customer_cart.{tenant_id}.{store_id}.{table_id} (untuk Dine-In QR) atau customer_cart.{tenant_id}.{store_id}.takeaway (jika cabang mengizinkan Takeaway Self-Order).

app/Services/TransactionService.php (Existing - Konvergensi Otoritatif):

Seluruh pembuatan pesanan dari Livewire\Kasir\CreateOrder, TransactionController, dan Customer\CheckoutController wajib memanggil pipeline transaksi yang sama di TransactionService yang melakukan:

Validasi order_type (OrderType enum) terhadap konfigurasi cabang.

Jika order_type === OrderType::DineIn dan cabang menggunakan meja: mengunci baris Table (SELECT ... FOR UPDATE) dengan filter WHERE tenant_id = ? AND store_id = ? AND id = ?, memvalidasi meja aktif, dan menyalin table.name ke orders.table_number serta table.id ke orders.table_id.

Jika order_type !== OrderType::DineIn atau cabang Retail: memaksa table_id = null dan table_number = null.

Menjalankan penguncian dan pemotongan stok (StockBalance), kalkulasi ulang harga/diskon/pajak, pembuatan Order + OrderItem, dan pembaruan status Table menjadi 'occupied' di dalam satu transaksi database.

F.3 Livewire & Controllers
app/Http/Controllers/TableController.php & StoreTableRequest.php / UpdateTableRequest.php:

Seluruh aksi menggunakan authorize() terhadap TablePolicy.

Aturan validasi keunikan name pada StoreTableRequest dan UpdateTableRequest wajib dibatasi per cabang:
Rule::unique('tables', 'name')->where('tenant_id', $tenantId)->where('store_id', $storeId)->whereNull('deleted_at')->ignore($tableId)

app/Livewire/Kasir/CreateOrder.php:

Menggunakan RequiresTenantContext dan membaca store_id aktif dari StoreResolver.

Daftar meja yang ditampilkan pada dropdown/grid Kasir hanya memuat Table::query()->where('store_id', $activeStoreId)->where('is_active', true)->get().

Mendengarkan event perpindahan cabang atau memvalidasi ulang store_id pada setiap metode aksi Livewire (submitOrder, selectTable) untuk mencegah pengiriman state meja dari cabang lama apabila pengguna mengganti cabang di tab browser lain.

app/Http/Controllers/StoreSwitchController.php:

Saat staf beralih cabang, controller menghapus session state terkait meja/keranjang kasir cabang sebelumnya (session()->forget(['kasir.selected_table_id', 'kasir.cart'])).

G. Security Architecture
G.1 Trust Boundaries
Batas Kepercayaan 1 — Pelanggan Anonim (Public QR Self-Order):

Sepenuhnya Tidak Dipercaya (Untrusted). Klien hanya diizinkan mengirimkan qr_token (melalui URL), daftar pasangan (product_id, quantity, modifier_ids, notes), metode pembayaran yang dipilih, dan idempotency_key.

Klien tidak pernah diizinkan mengirimkan tenant_id, store_id, table_id, price, discount_amount, tax_amount, atau status.

Batas Kepercayaan 2 — Staf Terautentikasi (Kasir, Pelayan, Dapur/KDS, Manajer Cabang):

Dipercaya Secara Kondisional (Conditionally Trusted within RBAC & Assigned Stores). Staf diautentikasi melalui session + 2FA/PIN (RequireTwoFactorChallenge, CashierPinService) namun dibatasi secara ketat hanya pada tenant_id miliknya dan daftar store_id yang terdaftar di user_stores (kecuali Owner/SuperAdmin tenant yang memiliki akses ke seluruh Store milik tenant tersebut).

Batas Kepercayaan 3 — Callback Eksternal (Midtrans & Omnichannel Webhooks):

Diverifikasi melalui tanda tangan kriptografis (VerifyWebhookSignature / verifikasi signature_key SHA-512 Midtrans) sebelum memproses perubahan status pembayaran.

G.2 Matriks Otorisasi (Authorization Matrix)
Aksi / Operasi	Pelanggan QR Anonim	Kasir / Waiter (Cabang A)	Manajer Cabang (Cabang A)	Staf / Manajer (Cabang B)	Owner Tenant
Lihat Menu & Buat Pesanan via QR Meja Cabang A	✅ (Hanya Meja Token Valid)	❌ (Via POS)	❌ (Via POS)	❌	❌
Lihat Daftar Meja & Status di POS Cabang A	❌	✅	✅	❌ (Ditolak StoreScope)	✅ (Jika Konteks = Cabang A)
Pilih Meja Cabang A untuk Pesanan Kasir	❌	✅	✅	❌ (Ditolak Composite FK & Policy)	✅
Buat / Ubah / Hapus Master Meja Cabang A	❌	❌	✅ (TablePolicy)	❌	✅
Rotasi / Cabut Token QR Meja Cabang A	❌	❌	✅ (TablePolicy)	❌	✅
Bebaskan Meja (Release Table) Cabang A	❌	✅ (Jika Order Lunas/Void)	✅	❌	✅
G.3 Skenario Ancaman & Mitigasi (OWASP API/Web Security)
IDOR Lintas Cabang pada Pemilihan Meja (table_id Tampering):

Skenario: Kasir di Cabang A mengubah payload Livewire/POST table_id menjadi ID meja milik Cabang B.

Mitigasi: Query pencarian meja menggunakan ScopedToStore (WHERE tenant_id = ? AND store_id = ?), divalidasi oleh TablePolicy, dan dilindungi di lapisan paling bawah oleh Composite Foreign Key (tenant_id, store_id, table_id) pada tabel orders.

Enumerasi Meja pada Self-Order:

Skenario: Penyerang mencoba menebak URL /customer/table/1, /customer/table/2 untuk memalsukan pesanan ke meja orang lain.

Mitigasi: Rute Self-Order tidak menerima id atau table_number, melainkan hanya qr_token acak sepanjang 64 karakter hex (256-bit entropi) dengan rate limiting ketat (throttle:self-order-scan dan throttle:self-order-checkout).

Penyalahgunaan Foto Stiker QR dari Rumah (Remote QR Abuse / Replay):

Skenario: Seseorang memotret QR meja dan mengirimkan pesanan palsu dari luar restoran di hari berikutnya.

Mitigasi: Manajer cabang dapat menekan "Rotasi QR" kapan saja (qr_token_version++), opsi penonaktifan meja (status = 'disabled') saat meja tidak digunakan, pembatasan kedaluwarsa pesanan tunai otomatis (ExpireStaleCashSelfOrders), serta rate limiting berbasis IP + Session.

H. Transaction and Concurrency Design
H.1 Urutan Penguncian Deterministik (Canonical Lock Graph)
Untuk mencegah deadlock pada MySQL (InnoDB) dan PostgreSQL saat beberapa transaksi berjalan serentak (misalnya Kasir dan Pelanggan QR memesan produk yang sama di meja yang sama), setiap transaksi yang memodifikasi pesanan dan meja wajib memperoleh kunci baris (SELECT ... FOR UPDATE) dalam urutan kanonik berikut:

Table (tables row untuk (tenant_id, store_id, table_id)) — Hanya jika table_id tidak null.

Product / StockBalance / Ingredient — Diurutkan secara menaik (ORDER BY id ASC) sesuai pola yang diuji pada tests/Feature/Tenancy/StockBalanceDeadlockTest.php.

Discount — Jika menggunakan kuota diskon (tests/Feature/Domain2/ConcurrentDiscountQuotaRedemptionTest.php).

Order — Baris pesanan target.

Payment / PaymentAttempt — Baris pembayaran terkait.

H.2 Analisis Skenario Konkurensi & Resolusi
No	Skenario Konkurensi	Mekanisme Pengendalian Transaksional	Hasil yang Dijamin
1	Dua staf kasir membuka meja yang sama secara bersamaan	Transaksi melakukan SELECT ... FOR UPDATE pada baris tables. Transaksi pertama mengubah status meja ke occupied dan membuat Order. Transaksi kedua membaca state terbaru setelah kunci dilepas.	Tidak ada pembukaan sesi ganda yang saling menimpa; transaksi kedua melihat meja sudah occupied dan diarahkan ke penambahan item atau ditolak dengan pesan konflik yang jelas.
2	Staf kasir menonaktifkan/menutup meja saat pelanggan QR menekan Checkout	Baik TableService::releaseTable/disable maupun TransactionService::createSelfOrder mengunci baris tables (FOR UPDATE) terlebih dahulu.	Jika staf mengunci lebih dulu dan menonaktifkan meja, checkout QR gagal secara aman dengan pesan "Meja sedang tidak aktif". Jika checkout QR mengunci lebih dulu, penonaktifan meja oleh staf ditolak karena ada pesanan aktif baru.
3	Pengiriman ganda Self-Order (Double-Tap / Network Retry)	Klien mengirimkan idempotency_key (UUID v4 yang dibuat saat halaman checkout dimuat). Tabel orders memiliki UNIQUE(tenant_id, idempotency_key).	Permintaan kedua menangkap pelanggaran constraint unik (diklasifikasikan oleh DatabaseErrorClassifier) atau menemukan pesanan eksisting dan mengembalikan halaman sukses/pembayaran dari pesanan pertama.
4	Webhook Midtrans datang bersamaan dengan pembatalan (ExpireStaleCashSelfOrders / Void)	Mengunci baris Order (FOR UPDATE) sebelum memeriksa transisi status, mengikuti pola pada tests/Feature/Domain2/WebhookVsReconcileVsVoidRaceTest.php.	Status terminal yang kontradiktif dicegah; jika pembayaran sah telah masuk, pembatalan otomatis dibatalkan, dan status meja tetap occupied sampai pesanan selesai dilayani.
5	Staf berpindah cabang (StoreSwitchController) saat request Livewire cabang lama masih berjalan	Payload aksi Livewire menyertakan expected_store_id saat komponen di-render. Server membandingkan expected_store_id dengan StoreResolver::currentStoreId().	Jika tidak cocok, server menolak eksekusi dengan error BranchContextChangedException dan meminta UI me-refresh halaman ke cabang baru.
H.3 Efek Samping Pasca-Komit (After-Commit Side Effects)
Seluruh penyiaran event (WaiterCalled), pembaruan cache menu (MenuCacheService), pencetakan dapur (KitchenPrinterService), dan job antrean wajib didaftarkan menggunakan DB::afterCommit(fn () => ...) agar worker antrean atau KDS tidak pernah membaca data pesanan/meja yang belum di-commit atau yang kemudian terkena rollback.

I. Performance and Observability
I.1 Desain Indeks & Pola Kueri
Dashboard Meja per Cabang:

Kueri: SELECT * FROM tables WHERE tenant_id = ? AND store_id = ? AND deleted_at IS NULL ORDER BY name ASC

Didukung oleh indeks komposit (tenant_id, store_id, deleted_at, name).

Pencegahan N+1 pada Daftar Meja dengan Pesanan Aktif:

Saat memuat halaman tables/index.blade.php atau grid meja di Kasir, gunakan eager loading terbatas: Table::with(['activeOrders' => fn ($q) => $q->where('store_id', $storeId)->select('id', 'tenant_id', 'store_id', 'table_id', 'order_number', 'status', 'payment_status', 'total_amount')]).

Isolasi Cache & Broadcast:

MenuCacheService wajib menyertakan tenant_id dan store_id pada setiap kunci cache (menu:tenant:{tenant_id}:store:{store_id}).

Channel broadcast di routes/channels.php untuk KDS dan panggilan pelayan wajib dipartisi per cabang: private-tenant.{tenantId}.store.{storeId}.tables.

I.2 Observabilitas & Audit Trail
Audit Trail (AuditTrailService / ActivityLog):

Wajib mencatat peristiwa: table.created, table.updated, table.deleted, table.qr_rotated, table.released, self_order.checkout_succeeded, self_order.checkout_rejected.

Keamanan Logging:

Log aplikasi dan ActivityLog dilarang mencatat nilai lengkap qr_token mentah. Gunakan potongan tersembunyi (mis. qr_token_prefix 6 karakter pertama + qr_token_version) untuk pelacakan insiden.

J. Migration and Compatibility Strategy
J.1 Strategi Transisi Data Tanpa Tebakan (Deterministic Backfill)
Untuk tabel tables eksisting yang mungkin belum memiliki store_id valid atau pesanan historis yang hanya memiliki table_number:

Aturan 1 (Inferensi dari Riwayat Order): Jika sebuah baris di tables belum memiliki store_id, periksa apakah ada pesanan di orders milik tenant_id yang sama yang merujuk meja tersebut dan seluruhnya berasal dari tepat satu store_id. Jika ya, tetapkan store_id tersebut.

Aturan 2 (Inferensi Cabang Tunggal): Jika meja belum pernah memiliki pesanan, dan tenant_id tersebut hanya memiliki tepat 1 Store di tabel stores, tetapkan store_id ke cabang tunggal tersebut.

Aturan 3 (Pemisahan Konflik Multi-Cabang): Jika satu baris meja warisan digunakan oleh pesanan di beberapa cabang berbeda (S1 dan S2), pertahankan baris meja asli untuk S1 dan buat salinan baris meja dengan nama yang sama untuk S2, lalu tautkan orders.table_id pada masing-masing cabang ke ID meja cabangnya sendiri.

Aturan 4 (Karantina Orphans Ambigu): Jika tenant memiliki >1 cabang dan terdapat meja tanpa riwayat pesanan yang tidak dapat ditentukan cabangnya secara pasti, jangan tebak cabang secara acak; nonaktifkan meja tersebut (is_active = false, status = 'disabled') dan catat ke laporan rekonsiliasi agar Owner tenant dapat menetapkan cabangnya melalui UI.

J.2 Pelindungan Generator Dataset Aktif
Seluruh migrasi ditulis secara non-destruktif (expand-first).

Dilarang menjalankan migrate:fresh, db:wipe, atau TRUNCATE.

K. Architecture Acceptance Criteria
Implementasi dinyatakan memenuhi arsitektur ini hanya apabila seluruh kriteria terukur berikut terpenuhi:

AC-01: Setiap baris pada tabel tables memiliki tenant_id dan store_id NOT NULL yang valid terhadap stores(tenant_id, id).

AC-02: Tabel orders memiliki kolom table_id nullable dengan composite foreign key (tenant_id, store_id, table_id) -> tables(tenant_id, store_id, id).

AC-03: Staf yang ditugaskan hanya ke Cabang A (user_stores) mendapatkan HTTP 403/404 saat mencoba melihat, mengubah, menghapus, merotasi QR, atau membuat pesanan pada meja milik Cabang B.

AC-04: Cabang A dan Cabang B dalam tenant yang sama berhasil membuat meja aktif dengan nama "Meja 01" secara bersamaan tanpa galat validasi atau pelanggaran constraint SQL.

AC-05: Tenant/Cabang Retail (tanpa meja) dan transaksi F&B Takeaway berhasil menyelesaikan checkout Kasir dan pembayaran tanpa memilih meja (table_id = NULL).

AC-06: Pemindaian QR Self-Order yang valid mengikat pesanan secara otomatis ke (tenant_id, store_id, table_id) dari pemilik token, menolak manipulasi parameter harga/cabang/meja dari klien, dan langsung menolak token lama setelah dirotasi oleh admin cabang.

AC-07: Uji konkurensi membuktikan tidak ada pesanan ganda pada idempotency_key yang sama dan tidak ada deadlock pada pemesanan simultan antara Kasir dan Self-Order.


