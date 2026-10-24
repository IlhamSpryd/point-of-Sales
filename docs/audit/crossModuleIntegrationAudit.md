# APEIRON POS — CROSS-MODULE ARCHITECTURE & INTEGRATION AUDIT

**Tanggal Audit:** 23 Oktober 2026  
**Commit / Snapshot Referensi:** `bfc324519f5a98c0fed250c63b133d646a233759`  
**Peran Auditor:** Principal System Architect  

---

## 1. Ringkasan Eksekutif

Apeiron POS telah berevolusi dari aplikasi POS *single-tenant* menjadi platform SaaS *multi-tenant* dan *multi-branch (store)* dengan *hardening* tingkat database yang ekstensif (composite foreign keys, immutability triggers, stock balance authority, dan idempotency locks)[cite: 1]. Namun, verifikasi terhadap implementasi aktual menunjukkan **kesenjangan signifikan antara ruang lingkup 13 modul arsitektur (M1–M13) dengan ketersediaan kode aktual di repository**[cite: 1].

### Matriks Kematangan Modul Aktual (M1–M13)

| Modul | Status Aktual | Bukti Artefak Tersedia | Gap Implementasi Utama |
| :--- | :--- | :--- | :--- |
| **M1 Core Platform & Multi-Tenancy** | **Tersedia (Matang)** | `Tenant`, `Store`, `User`, `Role`, `Device`, `Setting`, `TenantContext`, `StoreResolver`, `SetTenantContext`, `ScopedToTenant`, `ScopedToStore`, `OwnershipTransferService`, `UserInvitationService`[cite: 1] | Beberapa model lintas cabang membutuhkan konsistensi *scope* (`ScopedToStore` vs `ScopedToStoreForDiscount`)[cite: 1]. |
| **M2 POS & Retail Operations** | **Tersedia (Matang)** | `Order`, `OrderItem`, `OrderItemModifier`, `Table`, `Shift`, `ShiftHandover`, `ParkedCart`, `ReceiptPrintLog`, `TransactionService`, `CartService`, `ShiftService`, `KdsService`, `Livewire\Kasir\CreateOrder`, `Livewire\Kasir\OrderHistory`, `Livewire\Kds\Board`, `Customer\CheckoutController`[cite: 1] | Tiga jalur entry point pembuatan order (`Livewire\Kasir\CreateOrder`, `TransactionController`, `Customer\CheckoutController`, dan `ProcessWebhookOrderJob`) berisiko mengalami divergensi kontrak validasi dan kalkulasi[cite: 1]. |
| **M3 Product & Catalog Management** | **Tersedia (Matang)** | `Category`, `Product`, `ModifierGroup`, `Modifier`, `CategoryService`, `ProductService`, `MenuCacheService`, `Livewire\Inventory\ModifierManager`[cite: 1] | Harga dan ketersediaan produk masih berada pada level `Product` / `StockBalance`, belum memiliki harga per cabang (*branch-specific pricing*) atau per kanal[cite: 1]. |
| **M4 Inventory & Warehouse Management** | **Parsial (Store-Level Ledger)** | `StockBalance`, `StockMovement`, `Ingredient`, `ProductIngredient`, `ModifierIngredient`, `IngredientStockMovement`, `IngredientRestockForecast`, `Livewire\Inventory\IngredientManager`, `IngredientLedger`[cite: 1] | **Belum ada konsep Multi-Warehouse (`Warehouse`), `StockTransfer` antar cabang/gudang, `StockOpname`/`StockTake`, atau pelacakan `Batch/Expiry`**[cite: 1]. |
| **M5 Purchasing & Supplier Management** | **Belum Tersedia (0%)** | Hanya ada prediksi kebutuhan stok di `IngredientRestockForecast` & `RestockPredictionService`[cite: 1] | **Tidak ada migrasi, model, service, controller, atau UI untuk `Supplier`, `PurchaseOrder`, `GoodsReceipt` (Receiving), maupun `SupplierInvoice`**[cite: 1]. |
| **M6 Sales & Customer Management** | **Parsial (Skema & Self-Order)** | `Customer`, `Customer/MenuController`, `Customer/CartController`, `Customer/CheckoutController`, `Customer/WaiterCallController`, `CustomerSeeder`[cite: 1] | **Tidak ada `CustomerController`, `CustomerService`, `CustomerPolicy`, atau UI manajemen pelanggan (CRM) di backoffice/kasir**[cite: 1]. |
| **M7 Finance & Accounting** | **Parsial (Kasir & EOD)** | `Payment`, `PaymentAttempt`, `PaymentRefund`, `OrderReturn`, `OrderReturnItem`, `CashDrawerMovement`, `EodSettlement`, `ReturnRefundService`[cite: 1] | **Belum ada `ChartOfAccounts`, `JournalEntry` (General Ledger), `Expense`, `AccountsPayable` (AP), `AccountsReceivable` (AR), atau rekonsiliasi bank**[cite: 1]. |
| **M8 Pricing, Promotion & Loyalty** | **Parsial (Diskon Aktif, Loyalty Pasif)** | `Discount`, `DiscountRedemption`, `DiscountController`, `Livewire\DiscountManager`; Skema & Model: `LoyaltyTier`, `LoyaltyLedger`, `CustomerLoyaltyAccount`, trigger `trg_loyalty_ledger_sync_account`[cite: 1] | **Tidak ada `LoyaltyService`, `PromotionService`, aturan *earning/redemption* poin pada saat checkout POS, maupun UI manajemen `LoyaltyTier`**[cite: 1]. |
| **M9 HR, Employee & Payroll** | **Parsial (Identitas & Shift)** | `EmployeeIdGenerator`, `CashierPinService`, `UserInvitationService`, `ShiftService`[cite: 1] | **Tidak ada modul absensi (`Attendance`), `Payroll`, komisi kasir/staf, atau manajemen cuti**[cite: 1]. |
| **M10 Reporting & BI** | **Parsial (Sales & Forecast)** | `ReportController`, `ReportService`, `DashboardController`, `DashboardService`, `RestockForecastController`, `ExportTask`, `SalesExport`, `ProcessSalesReportExportJob`, `ProcessMonthlyExportJob`[cite: 1] | **Belum ada laporan HPP/COGS, Laba Rugi (P&L), valuasi inventori, maupun laporan pembelian**[cite: 1]. |
| **M11 SaaS Subscription & Platform Billing** | **Parsial (Entitlement & Provisioning)** | `Plan`, `PlanEntitlement`, `TenantSubscription`, `TenantEntitlementOverride`, `EntitlementService`, `EnforceEntitlement`, `ProvisioningService`, `TenantProvisioningService`[cite: 1] | **Belum ada siklus tagihan langganan (`SubscriptionInvoice`), pembayaran perpanjangan SaaS, *dunning/grace period*, atau UI upgrade paket**[cite: 1]. |
| **M12 Integration & Automation** | **Tersedia (Omnichannel, Midtrans, QZ Tray)** | `WebhookController`, `MidtransNotificationController`, `ChannelOrderNormalizerFactory`, `GoFoodOrderNormalizer`, `GrabFoodOrderNormalizer`, `ChannelProductMapping`, `ProcessWebhookOrderJob`, `QzTraySigningController`, `KitchenPrinterService`, `ReceiptPrinterService`[cite: 1] | Sinkronisasi stok balik (*outbound stock sync*) ke GoFood/GrabFood dan *webhook* keluar belum tersedia[cite: 1]. |
| **M13 Security, Compliance & Reliability** | **Tersedia (Matang)** | `AuditTrailService`, `ActivityLog`, `TwoFactorAuthService`, `OAuthService`, `VerifyWebhookSignature`, `VerifyProvisioningSignature`, `SecurityHeaders`, `EnterpriseBackupCommand`, `VerifyDatabase`, `database/sql/verify/01..10`[cite: 1] | Cakupan `Policy` belum lengkap untuk beberapa entitas kritikal (`Customer`, `OrderReturn`, `Payment`, `EodSettlement`)[cite: 1]. |

---

## 2. Peta Dependency Antarmodul & Aliran Data Nyata (Area Audit 1)

### 2.1 Peta Dependency Aktual


```

[M11 SaaS Provisioning & Entitlement]
└──> menyediakan Tenant, Plan, Subscription, Entitlement ke [M1 Core Platform]
│
[M1 Core Platform: TenantContext, StoreResolver, User, Role, Device, Setting]
├──> menjadi fondasi wajib bagi seluruh modul [M2, M3, M4, M6, M7, M8, M9, M10, M12, M13]
│
[M3 Catalog: Category, Product, ModifierGroup, Modifier] <─── dipetakan oleh ─── [M12 ChannelProductMapping]
│
├──> direferensikan oleh resep [M4 ProductIngredient & ModifierIngredient]
│
└──> digunakan beserta [M8 Discount] & [M6 Customer] oleh:
│
▼
[M2 POS & Order Execution: TransactionService / CartService / CreateOrder / CheckoutController / ProcessWebhookOrderJob]
├──> 1. Mencatat pembayaran di [M7 Payment & PaymentAttempt] (serta callback [M12 MidtransNotificationController])
├──> 2. Memotong stok produk & bahan baku di [M4 StockMovement, StockBalance, IngredientStockMovement]
├──> 3. Mencatat pemakaian kuota diskon di [M8 DiscountRedemption]
├──> 4. Mengirim tiket dapur ke [M2 KdsService / Livewire\Kds\Board] & [M12 KitchenPrinterService]
├──> 5. Mengaitkan kas masuk ke [M2/M9 Shift] & [M7 CashDrawerMovement]
└──> 6. Mencatat jejak audit ke [M13 AuditTrailService / ActivityLog]
│
▼
[M7 Return & Refund: ReturnRefundService]
├──> Membuat [OrderReturn, OrderReturnItem, PaymentRefund]
└──> Mengembalikan stok ke [M4 StockBalance / StockMovement] berdasarkan `ReturnDispositionEnum`
│
▼
[M10 Reporting & Analytics: ReportService, DashboardService, RestockPredictionService, EodSettlement]
├──> Membaca agregasi dari `Order`, `Payment`, `Shift`, `CashDrawerMovement`
└──> Membaca `IngredientStockMovement` untuk menghasilkan `IngredientRestockForecast` (calon input [M5 Purchasing])

```

### 2.2 Aliran Data Nyata Berdasarkan Artefak Kode
1. **Aliran Provisioning & Onboarding (M11 → M1 → M9):** `Api\ProvisioningController` menerima request terautentikasi HMAC (`VerifyProvisioningSignature`) → `ProvisioningService` / `TenantProvisioningService` membuat `Tenant`, `OwnerOnboardingToken`, dan menulis ke `EmailOutbox` → `ProcessProvisioningOutboxJob` / `ProcessEmailOutbox` mengirim email → Owner menyelesaikan wizard (`OwnerOnboardingController`, `OnboardingWizardController`, `TenantOnboardingService`) yang membuat `Store` perdana dan menghasilkan ID karyawan melalui `EmployeeIdGenerator` (`employee_id_sequences`)[cite: 1].
2. **Aliran Multi-Channel Order Creation (M12 / M6 / M2 → M4 / M7 / M8):**
   - **Kasir POS:** `Livewire\Kasir\CreateOrder` atau `TransactionController` (`StoreTransactionRequest`) → `TransactionService`[cite: 1].
   - **Self-Order Pelanggan (QR Meja):** `ResolveTableFromToken` & `EnsureTableSession` → `Customer\MenuController` → `Customer\CartController` (`CartService`) → `Customer\CheckoutController` (`StoreCheckoutRequest`) → `TransactionService`[cite: 1].
   - **Omnichannel Webhook (GoFood/GrabFood):** `Api\WebhookController` (`VerifyWebhookSignature`) → `ChannelOrderLog` → `ProcessWebhookOrderJob` → `ChannelOrderNormalizerFactory` (`GoFoodOrderNormalizer` / `GrabFoodOrderNormalizer`) → memetakan SKU via `ChannelProductMapping` → `TransactionService`[cite: 1].

---

## 3. Audit Scoping Multi-Tenant, Store, Role, & Permission (Area Audit 2)

1. **Dual-Layer Scoping (`TenantScope` & `StoreScope`):**
   - Sistem memisahkan isolasi level tenant (`ScopedToTenant` + `AssignsTenant` menggunakan `TenantScope`) dan isolasi level cabang (`ScopedToStore` + `AssignsStore` menggunakan `StoreScope`) yang dikendalikan oleh `TenantContext` dan `StoreResolver` melalui middleware `SetTenantContext`[cite: 1].
   - Untuk komponen Livewire, isolasi ditegakkan melalui trait `App\Livewire\Concerns\RequiresTenantContext` dan diuji pada `TenantContextLivewireTest.php` serta `LivewireTenancyIsolationTest.php`[cite: 1].
2. **Pengecualian Scoping Diskon (`ScopedToStoreForDiscount`):**
   - Model `Discount` menggunakan `ScopedToStoreForDiscount` dan `DiscountStoreScope` tersendiri (bukan `StoreScope` standar), yang mengindikasikan diskon dapat bersifat *tenant-wide* (`store_id IS NULL`) atau spesifik cabang (`store_id = X`)[cite: 1].
3. **Database-Level Tenant Ownership Enforcement:**
   - Migrasi `2026_10_09_000000..02_add_composite_fks_c1..c3.php`, `2026_11_01_000000..02_make_tenant_ownership_c1..c3.php`, dan `2026_12_04_000011_restore_branch_composite_foreign_keys.php` mengunci relasi lintas tabel menggunakan *composite foreign keys* `(tenant_id, ...)` dan `(tenant_id, store_id)` sehingga kebocoran referensi lintas tenant/cabang ditolak oleh mesin database[cite: 1].
4. **Celah Otorisasi Policy (`CONFIRMED`):**
   - Hanya terdapat 15 Policy di `app/Policies/`: `ActivityLogPolicy`, `CategoryPolicy`, `DevicePolicy`, `DiscountPolicy`, `ExportTaskPolicy`, `IngredientPolicy`, `OrderPolicy`, `ProductPolicy`, `RolePolicy`, `SettingPolicy`, `ShiftPolicy`, `StorePolicy`, `TablePolicy`, `UserInvitationPolicy`, dan `UserPolicy`[cite: 1].
   - Entitas kritikal seperti `Customer`, `CustomerLoyaltyAccount`, `ChannelProductMapping`, `ModifierGroup`, `Modifier`, `OrderReturn`, `Payment`, `PaymentRefund`, `ParkedCart`, dan `EodSettlement` **tidak memiliki class Policy dedikasi** di `app/Policies/`[cite: 1].

---

## 4. Audit Konsistensi Sumber Kebenaran Kalkulasi Finansial & Stok (Area Audit 3)

1. **Kalkulasi Order (Subtotal, Diskon, Pajak, Service Charge, Total):**
   - Skema `orders` menyimpan kolom finansial (`2026_09_11_044754_add_financial_fields_to_orders_table.php`, `2026_09_18_060846_harden_currency_columns_on_orders_table.php`, `2026_09_19_101240_add_discount_fields_to_orders_table.php`, `2026_09_19_101241_add_void_audit_and_service_charge_to_orders_table.php`) dan diverifikasi oleh skrip `database/sql/verify/03_order_totals.sql` serta `database/sql/audit/order_total_reconciliation.sql`[cite: 1].
   - Keberadaan `app/Support/bcmath_polyfill.php` dan `FinancialCalculationAndParityTest.php` mengonfirmasi bahwa kalkulasi presisi menggunakan aritmatika desimal/BCMath telah diterapkan untuk menjaga paritas antara keranjang dan transaksi final[cite: 1].
   - **Risiko Sumber Kebenaran Ganda (`SUSPECTED`):** Kalkulasi keranjang dilakukan di `CartService` (untuk alur Self-Order Pelanggan), `Livewire\Kasir\CreateOrder` (untuk state UI kasir interaktif), `ChannelOrderNormalizer` (untuk order GoFood/GrabFood), dan `TransactionService` (saat persistensi akhir)[cite: 1]. Jika aturan pajak/service charge di `Setting` berubah atau ketika `ParkedCart` di-restore (`ParkedCartLifecycleAndRevalidationTest.php`), perbedaan urutan pembulatan antara `CartService`, `CreateOrder`, dan `TransactionService` dapat memicu selisih 1 sen/rupiah pada `03_order_totals.sql`[cite: 1].
2. **Kalkulasi Pembayaran, Split Tender, & Rekonsiliasi EOD:**
   - Migrasi `2026_12_11_000001_create_payment_attempts_and_split_tender_support.php` dan `2026_12_12_000001_create_returns_and_payment_refunds_tables.php` memperkenalkan dukungan *split tender* (`Payment`, `PaymentAttempt`) dan *refund* (`PaymentRefund`, `OrderReturn`)[cite: 1].
   - Paritas antara agregasi laporan (`ReportService`), penutupan shift (`ShiftService`), dan `EodSettlement` diuji di `EodSettlementAndReportParityTest.php` serta `04_payment_totals.sql`[cite: 1].
3. **Sumber Kebenaran Stok Produk vs Bahan Baku (Dual Inventory Authority):**
   - Sistem memiliki dua mekanisme inventori yang berjalan berdampingan:
     a. **Stok Produk Jadi:** `products.stock` (legacy column), `stock_movements`, dan tabel otoritatif `stock_balances` (`2026_12_03_000000_create_stock_balances_tables.php`, diuji di `StockBalanceAuthorityTest.php` dan `StockBalanceDeadlockTest.php`)[cite: 1].
     b. **Stok Bahan Baku (Resep/BOM):** `ingredients`, `product_ingredients`, `modifier_ingredients`, dan `ingredient_stock_movements` (`2026_09_22_010000..02`, `2026_09_23_232911`)[cite: 1].
   - Keberadaan command `BackfillBalances.php`, `ReconcileBalances.php`, `MigrateStockToLedger.php`, dan `database/sql/verify/05_inventory_balances.sql` membuktikan bahwa sinkronisasi antara kolom stok produk, `stock_balances`, dan ledger pergerakan stok merupakan titik rawan *drift* yang membutuhkan rekonsiliasi eksplisit[cite: 1].

---

## 5. Duplikasi Business Logic & Penempatan Layer (Area Audit 4)

1. **Dualisme Orkestrasi UI: Controller MVC vs Komponen Livewire (`CONFIRMED`):**
   - Terdapat tumpang tindih tanggung jawab antara Controller tradisional (`TransactionController`, `ShiftController`, `DiscountController`, `ReportController`) dengan komponen Livewire (`Livewire\Kasir\CreateOrder`, `Livewire\Kasir\OrderHistory`, `Livewire\ShiftManager`, `Livewire\DiscountManager`, `Livewire\AsyncExportButton`)[cite: 1].
   - Sementara `ProductController`, `CategoryController`, `StoreController`, `TableController`, `DeviceController`, dan `UserController` murni menggunakan pendekatan Controller + FormRequest + Service, domain Inventori (`IngredientManager`, `IngredientLedger`, `ModifierManager`) dan Pemetaan Kanal (`ChannelMappingManager`) justru diletakkan langsung di dalam komponen Livewire tanpa Service class khusus (`IngredientService` atau `ModifierService` **tidak ada** di `app/Services/`)[cite: 1].
2. **Logika Bisnis di Database Trigger vs Application Service (`CONFIRMED`):**
   - Migrasi `2026_10_08_092247_update_immutable_ledger_triggers.php`, `2026_10_08_095041_replace_trg_loyalty_ledger_sync_account.php`, `2026_12_02_000000_add_immutability_triggers.php`, dan `2026_12_14_000002_allow_receipt_print_count_in_orders_trigger.php` menempatkan aturan imutabilitas dan sinkronisasi saldo poin (`trg_loyalty_ledger_sync_account`) di dalam *database trigger*[cite: 1].
   - Akibatnya, setiap perubahan fitur pada `orders` (misalnya penambahan counter `receipt_print_count` pada migrasi `2026_12_14_000002`) sempat terblokir oleh trigger imutabilitas `orders` sehingga memerlukan migrasi tambalan (*patch migration*) untuk mengizinkan perubahan kolom tersebut[cite: 1].

---

## 6. Audit Event, Listener, Observer, Queue Job, & Efek Samping Ganda (Area Audit 5)

1. **Minimnya Event-Driven Decoupling (`CONFIRMED`):**
   - Direktori `app/Events/` hanya berisi satu class: `WaiterCalled.php`[cite: 1]. Tidak ada direktori `app/Listeners/` maupun `app/Observers/`[cite: 1].
   - Seluruh efek samping transaksi (pemotongan stok produk, pemotongan bahan baku resep, pencatatan pembayaran, redemsi kuota diskon, pencatatan kas shift, dan audit log) dieksekusi secara *synchronous/procedural* di dalam service (`TransactionService` / `ReturnRefundService`), bukan melalui domain events[cite: 1].
2. **Potensi Race Condition & Efek Samping Ganda antara Webhook, Reconcile Command, dan Void/Expiry (`CONFIRMED`):**
   - Terdapat 4 Queue Jobs (`ProcessWebhookOrderJob`, `ProcessProvisioningOutboxJob`, `ProcessSalesReportExportJob`, `ProcessMonthlyExportJob`) dan beberapa command terjadwal yang memutasi status transaksi: `ReconcilePendingMidtransOrders`, `ExpireStaleCashSelfOrders`, dan `ProcessEmailOutbox`[cite: 1].
   - Adanya `WebhookVsReconcileVsVoidRaceTest.php`, `IdempotencyLockTest.php`, `ConcurrentDiscountQuotaRedemptionTest.php`, dan `ConcurrentRefundLimitTest.php` membuktikan bahwa jalur konkurensi antara webhook pembayaran (`MidtransNotificationController`), command rekonsiliasi (`ReconcilePendingMidtransOrders`), dan aksi kasir (`OrderVoid` / `ExpireStaleCashSelfOrders`) berpotensi memicu pemotongan/pengembalian stok ganda apabila *pessimistic locking* (`SELECT ... FOR UPDATE`) atau *idempotency key* dilewati oleh modul baru[cite: 1].
3. **Konteks Tenant pada Queue Job & Console Command (`CONFIRMED`):**
   - Karena `SetTenantContext` adalah HTTP middleware, eksekusi *background job* (`ProcessSalesReportExportJob`, `ProcessMonthlyExportJob`, `ProcessWebhookOrderJob`) dan *console command* (`RecomputeRestockForecasts`, `ReconcilePendingMidtransOrders`, `ExpireStaleCashSelfOrders`) tidak melewati siklus HTTP request[cite: 1]. Jika suatu Job atau Command baru di M5–M12 lupa menginisialisasi `TenantContext` dan `StoreResolver` secara eksplisit (seperti yang diuji pada `ConsoleCommandsTenantContextTest.php` dan `ProcessSalesReportExportJobTest.php`), Global Scope akan gagal memfilter tenant atau memicu exception[cite: 1].

---

## 7. Audit Alur End-to-End: POS → Payment → Inventory → Purchasing → Reporting (Area Audit 6)


```

[POS: CreateOrder / SelfOrder / Webhook]
│  (Validasi Shift, Meja, Kuota Diskon, Idempotency Key)
▼
[Payment: PaymentAttempt -> Payment (Cash / Card / QRIS Midtrans / Split Tender)]
│  (MidtransNotificationController / ReconcilePendingMidtransOrders)
▼
[Inventory: StockBalance & StockMovement + Ingredient & IngredientStockMovement]
│  (Kompensasi stok saat Void / ExpireStaleCashSelfOrders / ReturnRefundService)
▼
[Forecast: RecomputeRestockForecasts -> RestockPredictionService -> IngredientRestockForecast]
│
╳  <-- PUTUS DI SINI (BROKEN CHAIN): Modul M5 (Purchasing & Supplier) belum ada!
│      Tidak ada alur konversi dari IngredientRestockForecast ke PurchaseOrder,
│      dan tidak ada GoodsReceipt (Receiving) yang menambah StockBalance / Ingredient.
▼
[Reporting: ReportService / DashboardService / EodSettlement / SalesExport]
<-- Hanya melaporkan omzet penjualan kotor/bersih & metode pembayaran;
belum menghitung HPP (COGS) dari harga beli bahan/produk (M5) maupun Jurnal Akuntansi (M7).

```

1. **Rantai yang Terbukti Berfungsi:** POS → Payment (termasuk Split Tender & Midtrans Webhook) → Pemotongan Inventory (`StockBalance` & `IngredientStockMovement`) → Kompensasi Stok saat Void/Return (`ReturnRefundService`) → Prediksi Restock (`RestockPredictionService`) dan Laporan Penjualan/EOD (`ReportService`, `EodSettlement`)[cite: 1].
2. **Rantai yang Terputus (`CONFIRMED`):**
   - **Inventory → Purchasing (M4 → M5):** `IngredientRestockForecast` menghasilkan rekomendasi restock, tetapi tidak ada entitas `Supplier`, `PurchaseOrder`, atau `GoodsReceipt` untuk menindaklanjuti rekomendasi tersebut menjadi penambahan stok masuk (*inbound stock movement*)[cite: 1]. Saat ini penambahan stok bahan baku hanya dapat dilakukan secara manual melalui `Livewire\Inventory\IngredientManager`[cite: 1].
   - **Purchasing & Inventory → Finance & Reporting (M4/M5 → M7/M10):** Karena tidak ada harga beli per batch/PO dari M5, `OrderItem` dan `IngredientStockMovement` tidak membawa nilai HPP (*Cost of Goods Sold* / COGS) ke `ReportService` maupun `EodSettlement`[cite: 1].

---

## 8. Ketergantungan pada Hardcoded Rules, Asumsi Schema, & Kontrak Tak Terdokumentasi (Area Audit 7)

1. **Immutability Triggers di Database (`2026_10_08_092247`, `2026_12_02_000000`, `2026_12_14_000002`):**
   - Tabel ledger dan transaksi (`orders`, `stock_movements`, `ingredient_stock_movements`, `loyalty_ledger`, `activity_logs`, `cash_drawer_movements`) dilindungi oleh trigger database yang menolak `UPDATE`/`DELETE` sembarangan[cite: 1]. Developer yang menambahkan kolom status/atribut baru pada `orders` (misalnya status pengiriman di M6 atau status posting jurnal di M7) akan mengalami kegagalan query di level database kecuali trigger diperbarui[cite: 1].
2. **Database CHECK Constraints pada Kolom Enum (`2026_09_23_120004`, `2026_09_24_130000`, `2026_09_24_140000`, `2026_10_07_093751`):**
   - Nilai enum untuk status order, tipe order (`OrderTypeCast`, migrasi normalisasi `2026_12_06_000002_normalize_order_type_takeaway_values.php`), status pembayaran, dan status preparasi KDS dikunci menggunakan `CHECK` constraint SQL[cite: 1]. Menambahkan case baru pada PHP Enum di `app/Enums/` tanpa migrasi perubahan `CHECK` constraint akan menyebabkan crash saat `INSERT`/`UPDATE`[cite: 1].
3. **Trigger Sinkronisasi Loyalty (`2026_10_08_095041_replace_trg_loyalty_ledger_sync_account.php`):**
   - Saldo `customer_loyalty_accounts` diperbarui secara otomatis oleh trigger database `trg_loyalty_ledger_sync_account` saat baris baru masuk ke `loyalty_ledger`[cite: 1]. Kontrak ini tersembunyi di level migrasi SQL; jika ke depan dibuat `LoyaltyService` di PHP yang juga melakukan `increment('points_balance')` pada `CustomerLoyaltyAccount`, maka **poin pelanggan akan terhitung dua kali lipat (double-counting)**[cite: 1].
4. **System User Resolver (`SystemUserResolver.php` & `ProvisionSystemUsers.php`):**
   - Proses otomatis (webhook, cron job) mengandalkan `SystemUserResolver` untuk mengisi `user_id` / `processed_by` pada tabel yang mewajibkan foreign key `(tenant_id, user_id)`[cite: 1]. Jika tenant baru dibuat tanpa menjalankan provisioning system user yang lengkap, job latar belakang seperti `ProcessWebhookOrderJob` akan gagal akibat pelanggaran FK[cite: 1].

---

## 9. Daftar Temuan Audit (Confirmed, Suspected, Not Verified)

### F-01: Putusnya Siklus Procure-to-Pay (M5 Purchasing & Supplier Belum Ada Sama Sekali)
- **Status:** `CONFIRMED`[cite: 1]
- **Severity:** **CRITICAL**
- **Bukti File/Artefak:** Direktori `app/Models/`, `app/Services/`, `app/Http/Controllers/`, dan `database/migrations/` tidak memiliki satu pun file terkait `Supplier`, `PurchaseOrder`, `GoodsReceipt`, atau `PurchaseInvoice`; hanya ada `IngredientRestockForecast.php` dan `RestockPredictionService.php`[cite: 1].
- **Dampak:** Siklus POS → Inventory → Purchasing → Reporting terputus[cite: 1]. Restock bahan baku dan produk hanya bisa dilakukan lewat penyesuaian manual (`IngredientManager`), tanpa jejak harga beli supplier, PO, atau perhitungan HPP (COGS)[cite: 1].
- **Rekomendasi:** Bangun fondasi M5 (`Supplier`, `PurchaseOrder`, `PurchaseOrderItem`, `GoodsReceipt`, `GoodsReceiptItem`) yang terintegrasi langsung dengan `StockMovement` dan `IngredientStockMovement` menggunakan tipe pergerakan `purchase_receive`[cite: 1].

### F-02: Risiko Double-Counting Saldo Loyalty antara Trigger DB dan Application Layer (M6 / M8)
- **Status:** `CONFIRMED`[cite: 1]
- **Severity:** **CRITICAL**
- **Bukti File/Artefak:** `database/migrations/2026_10_08_095041_replace_trg_loyalty_ledger_sync_account.php`, `app/Models/LoyaltyLedger.php`, `app/Models/CustomerLoyaltyAccount.php`, `app/Models/LoyaltyTier.php`[cite: 1].
- **Dampak:** Tabel dan trigger sinkronisasi `loyalty_ledger` → `customer_loyalty_accounts` sudah aktif di database, namun belum ada `LoyaltyService` di aplikasi[cite: 1]. Ketika developer mengimplementasikan M6/M8 dan menulis logika update saldo poin di Eloquent/Service selain insert ke `LoyaltyLedger`, saldo poin akan terakumulasi ganda oleh trigger `trg_loyalty_ledger_sync_account`[cite: 1].
- **Rekomendasi:** Tetapkan kontrak arsitektur tunggal di `ARCHITECTURE_CONTRACT.md` dan `LoyaltyService`: `CustomerLoyaltyAccount` harus bersifat *read-only* bagi kode PHP, dan seluruh mutasi poin wajib berupa *append-only insert* ke `LoyaltyLedger`[cite: 1].

### F-03: Fragmentasi Service Layer pada Domain Inventory, Modifier, dan Channel Mapping (M3 / M4 / M12)
- **Status:** `CONFIRMED`[cite: 1]
- **Severity:** **HIGH**
- **Bukti File/Artefak:** `app/Livewire/Inventory/IngredientManager.php`, `app/Livewire/Inventory/IngredientLedger.php`, `app/Livewire/Inventory/ModifierManager.php`, `app/Livewire/ChannelMappingManager.php`, `app/Livewire/DiscountManager.php`, dibandingkan dengan daftar file di `app/Services/`[cite: 1].
- **Dampak:** Tidak terdapat `IngredientService`, `ModifierService`, `DiscountService`, atau `ChannelMappingService` di `app/Services/`[cite: 1]. Logika mutasi stok bahan baku, resep modifier, kuota diskon, dan pemetaan SKU omnichannel terkunci di dalam komponen Livewire sehingga tidak dapat digunakan ulang secara konsisten oleh modul Purchasing (M5), API eksternal (M12), atau job otomatisasi[cite: 1].
- **Rekomendasi:** Ekstrak logika bisnis dari `IngredientManager`, `ModifierManager`, `DiscountManager`, dan `ChannelMappingManager` ke dalam class Service tersendiri di `app/Services/`[cite: 1].

### F-04: Absennya Policy Otorisasi pada 10+ Model Transaksional dan Master (M1 / M2 / M6 / M7 / M12)
- **Status:** `CONFIRMED`[cite: 1]
- **Severity:** **HIGH**
- **Bukti File/Artefak:** Perbandingan `app/Models/` (41 model entitas) dengan `app/Policies/` (15 policy)[cite: 1]. Model tanpa Policy meliputi: `Customer`, `CustomerLoyaltyAccount`, `LoyaltyTier`, `ModifierGroup`, `Modifier`, `ChannelProductMapping`, `OrderReturn`, `Payment`, `PaymentRefund`, `ParkedCart`, `EodSettlement`[cite: 1].
- **Dampak:** Pemeriksaan izin akses (*authorization*) untuk retur/refund, penutupan EOD, parkir keranjang, modifikasi resep/modifier, dan pemetaan kanal bergantung pada pengecekan manual di komponen/controller atau middleware route, berisiko terlewat ketika endpoint baru ditambahkan[cite: 1].
- **Rekomendasi:** Buat Policy eksplisit untuk `CustomerPolicy`, `ModifierGroupPolicy`, `OrderReturnPolicy`, `EodSettlementPolicy`, dan `ChannelProductMappingPolicy` serta daftarkan pada matriks RBAC[cite: 1].

### F-05: Empat Jalur Masuk Pembuatan Order Berisiko Divergensi Kalkulasi & Validasi (M2 / M6 / M8 / M12)
- **Status:** `SUSPECTED` (Struktur multi-entry terkonfirmasi pada file, detail implementasi internal fungsi berstatus *suspected*)[cite: 1]
- **Severity:** **HIGH**
- **Bukti File/Artefak:**
  1. Kasir Livewire: `app/Livewire/Kasir/CreateOrder.php`[cite: 1]
  2. Kasir HTTP Controller: `app/Http/Controllers/TransactionController.php` + `StoreTransactionRequest.php`[cite: 1]
  3. Self-Order QR: `app/Http/Controllers/Customer/CheckoutController.php` + `StoreCheckoutRequest.php` + `CartService.php`[cite: 1]
  4. Omnichannel Webhook: `app/Jobs/ProcessWebhookOrderJob.php` + `ChannelOrderNormalizerFactory.php`[cite: 1]
- **Dampak:** Setiap penambahan fitur baru di M6 (atribusi `customer_id` dan poin loyalty) atau M8 (promo bertingkat/voucher) harus diintegrasikan ke keempat jalur tersebut[cite: 1]. Jika kalkulasi diskon/pajak/loyalty hanya ditambahkan di `CreateOrder` atau `CartService` tanpa disentralisasi penuh di `TransactionService`, maka order dari Self-Order atau Webhook akan memiliki struktur finansial/poin yang berbeda[cite: 1].
- **Rekomendasi:** Pastikan `TransactionService` menjadi satu-satunya *domain gatekeeper* untuk kalkulasi ulang finansial, validasi diskon, atribusi pelanggan, dan pencatatan loyalty, sementara `CartService` dan `CreateOrder` memanggil *shared calculation engine* yang sama[cite: 1].

### F-06: Immutability Triggers & Check Constraints Kaku yang Mempersulit Ekstensi Modul (M1 / M2 / M7)
- **Status:** `CONFIRMED`[cite: 1]
- **Severity:** **MEDIUM**
- **Bukti File/Artefak:** `database/migrations/2026_12_02_000000_add_immutability_triggers.php`, `2026_12_14_000002_allow_receipt_print_count_in_orders_trigger.php`, `2026_09_24_130000_fix_enum_check_constraints.php`, `2026_09_24_140000_fix_payment_and_preparation_constraints.php`, `2026_10_07_093751_ensure_single_payments_status_check.php`[cite: 1].
- **Dampak:** Riwayat migrasi menunjukkan berulangnya migrasi perbaikan (*fix migrations*) setiap kali kolom atau status enum baru ditambahkan karena terbentur `CHECK` constraint dan trigger imutabilitas `orders`[cite: 1]. Saat M6, M7, dan M8 dikembangkan, perubahan pada `orders` atau `payments` akan kembali memicu exception SQL jika trigger tidak ikut dimigrasi[cite: 1].
- **Rekomendasi:** Dokumentasikan daftar seluruh tabel yang memiliki trigger imutabilitas dan `CHECK` constraint di `docs/architecture/ARCHITECTURE_CONTRACT.md`, serta pisahkan data siklus hidup tambahan (seperti status pengiriman, status jurnal akuntansi, atau log cetak) ke tabel terpisah (seperti pola `receipt_print_logs` dan `order_returns`) alih-alih memutasi baris `orders`[cite: 1].

### F-07: Dualisme Tabel Inventori Produk (`StockBalance`) vs Bahan Baku (`Ingredient`) Tanpa Konversi Unit (M3 / M4)
- **Status:** `CONFIRMED`[cite: 1]
- **Severity:** **MEDIUM**
- **Bukti File/Artefak:** `app/Models/StockBalance.php`, `app/Models/StockMovement.php`, `app/Models/Ingredient.php`, `app/Models/ProductIngredient.php`, `app/Models/ModifierIngredient.php`, `app/Models/IngredientStockMovement.php`, `app/Console/Commands/ReconcileBalances.php`[cite: 1].
- **Dampak:** Stok produk jadi disimpan di `stock_balances` (`StockMovement`), sedangkan stok bahan baku disimpan di `ingredients` (`IngredientStockMovement`) tanpa tabel `IngredientBalance` terpisah yang setara atau tabel konversi satuan (*Unit of Measure* / UoM conversion, misal beli dalam Karton/Kg di M5, pakai dalam Gram/Ml di resep M3)[cite: 1].
- **Rekomendasi:** Sebelum membangun M5 (Purchasing), tambahkan dukungan konversi satuan (`purchase_unit` vs `consumption_unit` + `conversion_factor`) dan selaraskan mekanisme kunci konkurensi stok `Ingredient` agar setara dengan `StockBalance`[cite: 1].

### F-08: Detail Implementasi Baris Kode Internal pada File Terkompresi & Status Runtime Git
- **Status:** `NOT VERIFIED`[cite: 1]
- **Severity:** **INFO / AUDIT LIMITATION**
- **Bukti File/Artefak:** Stream Deflate di dalam arsip `point-of-Sales-bfc324519f5a98c0fed250c63b133d646a233759`[cite: 1].
- **Dampak:** Urutan baris kode di dalam method internal PHP dan output runtime `php artisan test` / `git status` tidak dieksekusi secara live pada lingkungan pembacaan statis ini[cite: 1].
- **Rekomendasi:** Jalankan `php artisan pos:verify-database` (`VerifyDatabase.php`) dan `vendor/bin/phpunit` secara langsung pada environment CI/lokal setelah perubahan kode dilakukan[cite: 1].

---

## 10. Risiko Regresi Lintas Modul (Area Audit 8)

| Perubahan pada Modul Sumber | Modul Terdampak | Mekanisme Kerusakan / Regresi |
| :--- | :--- | :--- |
| **M1 (Perubahan `TenantContext` / `StoreResolver`)** | M2, M3, M4, M7, M8, M10, M12 | Kegagalan resolusi konteks pada Queue Job (`ProcessWebhookOrderJob`, `ProcessSalesReportExportJob`, `ProcessMonthlyExportJob`) atau Livewire (`RequiresTenantContext`) akan menyebabkan query kosong atau tertolaknya transaksi oleh composite FK `(tenant_id, store_id)`[cite: 1]. |
| **M3 (Perubahan `Modifier` / `ProductIngredient`)** | M2 (`CartService`, `TransactionService`), M4 (`IngredientStockMovement`), M12 (`ProcessWebhookOrderJob`) | Perubahan struktur resep atau modifier dapat mematahkan pemotongan stok bahan baku saat checkout atau menyebabkan gagalnya pemetaan pesanan dari GoFood/GrabFood (`ChannelProductMapping`)[cite: 1]. |
| **M8 (Perubahan Aturan `Discount` / Penambahan Promo Baru)** | M2 (`CreateOrder`, `CheckoutController`), M7 (`ReturnRefundService`), M10 (`ReportService`, `EodSettlement`) | Jika `ReturnRefundService` menghitung nilai refund item tanpa memperhitungkan alokasi diskon proporsional dari M8, maka nominal `PaymentRefund` dapat melebihi jumlah bersih yang dibayar pelanggan (`ConcurrentRefundLimitTest`)[cite: 1]. |
| **M2 / M7 (Penambahan Metode Pembayaran di `PaymentMethod` Enum)** | Database Constraint, M2 (`ShiftService`), M7 (`EodSettlement`), M10 (`ReportService`, `SalesExport`) | Menambah metode pembayaran di `PaymentMethod.php` tanpa memperbarui `CHECK` constraint (`2026_09_24_140000_fix_payment_and_preparation_constraints.php`) dan query agregasi di `ShiftService` / `ReportService` akan merusak paritas laporan EOD (`EodSettlementAndReportParityTest`)[cite: 1]. |
| **M11 (Perubahan Kuota `PlanEntitlement`)** | M1 (`StoreController`, `UserController`, `DeviceController`), M3 (`ProductController`) | Middleware `EnforceEntitlement` dan `EntitlementService` membatasi pembuatan cabang, pengguna, perangkat, atau produk; perubahan nama kunci *entitlement* akan memblokir operasi CRUD di M1 dan M3[cite: 1]. |

---

## 11. Cakupan Pengujian Saat Ini & Gap Pengujian Paling Berisiko (Area Audit 9)

### 11.1 Pengujian Integrasi yang Sudah Tersedia (59 File Test — `CONFIRMED`)
Repository memiliki fondasi pengujian yang sangat kuat pada Domain 1, Domain 2, Domain 3, Domain 4, Auth, dan Security[cite: 1]:
- **Multi-Tenancy & Provisioning (M1, M11):** `MultiTenantFoundationTest`, `TenantContextTest`, `TenantContextLivewireTest`, `LivewireTenancyIsolationTest`, `Domain1TenantIsolationTest`, `Domain1AuditRemediationTest`, `ProvisioningDomainTest`, `ProvisioningHardenedIntegrityTest`, `ProvisioningRealConcurrencyTest`, `TenantLifecycleEnforcementTest`, `TenantEntitlementAndQuotaTest`, `BaselineTest`, `CustomerModifiersIsolationTest`, `SettingIsolationTest`, `TenantScopedUniqueTest`[cite: 1].
- **POS, Payment, Inventory, & Concurrency (M2, M4, M7, M8):** `TransactionIntegrityTest`, `FinancialCalculationAndParityTest`, `CardPaymentRecordingTest`, `PaymentAttemptIdempotencyAndSplitTest`, `PaymentRefundIntegrityTest`, `OrderReturnAndDispositionTest`, `OrderVoidIntegrityTest`, `OrderLifecycleTransitionTest`, `ConcurrentDiscountQuotaRedemptionTest`, `ConcurrentRefundLimitTest`, `ParkedCartLifecycleAndRevalidationTest`, `StockBalanceAuthorityTest`, `StockBalanceDeadlockTest`, `StockCompensationHardeningTest`, `StrictInventoryAndWebhookRejectionTest`, `TransactionConcurrencyTest`, `TransactionRollbackTest`, `ShiftDeviceHandoverAndConcurrencyTest`, `ShiftServiceTest`, `SelfOrderCashCheckoutTest`, `CustomerSelfOrderPatchesTest`, `CheckoutGuardTest`[cite: 1].
- **Omnichannel, KDS, & Reporting (M2, M10, M12):** `MidtransWebhookTest`, `ReconcilePendingMidtransOrdersTest`, `ProcessWebhookOrderJobIdempotencyTest`, `WebhookVsReconcileVsVoidRaceTest`, `WebhookMultiTenantRoutingTest`, `WebhookSecurityAuditTest`, `KdsLifecycleTest`, `KdsGuardExceptionTest`, `KdsServiceSecurityTest`, `EodSettlementAndReportParityTest`, `ReportServiceMultiStoreTest`, `SalesExportJobTest`, `SalesReportExportRetryTest`, `ProcessSalesReportExportJobTest`, `ProcessMonthlyExportJobDirectoryTest`, `RestockForecastControllerTest`[cite: 1].

### 11.2 Gap Pengujian Paling Berisiko (Test yang Perlu Dibuat)
1. **`LoyaltyEarningAndRedemptionParityTest` (M2 + M6 + M8):** Belum ada satu pun test yang memverifikasi siklus penautan `Customer` pada order POS/Self-Order, pencatatan `LoyaltyLedger`, sinkronisasi trigger `trg_loyalty_ledger_sync_account` ke `CustomerLoyaltyAccount`, kenaikan `LoyaltyTier`, dan pembatalan/penarikan kembali poin saat `OrderVoid` atau `OrderReturn`[cite: 1].
2. **`CrossChannelCalculationParityTest` (M2 + M6 + M8 + M12):** Menguji skenario keranjang identik (produk + modifier berbayar + diskon + pajak + service charge) yang diproses melalui 4 jalur (`CreateOrder` Livewire, `TransactionController`, `Customer\CheckoutController`, dan `ProcessWebhookOrderJob`) untuk menjamin hasil `Order` dan `OrderItem` 100% identik hingga satuan terkecil[cite: 1].
3. **`PartialReturnWithDiscountAndIngredientRestockTest` (M2 + M4 + M7 + M8):** Menguji apakah retur sebagian (*partial return*) pada item yang memiliki resep bahan baku (`ProductIngredient` + `ModifierIngredient`) dan diskon order-level menghitung batas maksimal refund secara proporsional serta menangani disposisi stok bahan baku dengan benar[cite: 1].
4. **`PurchasingToInventoryAndCogsIntegrationTest` (M4 + M5 + M7 + M10):** Wajib dibuat saat M5 diimplementasikan untuk memastikan penerimaan barang (`GoodsReceipt`) memperbarui `StockBalance` / `Ingredient`, mencatat hutang/kas di M7, dan memperbarui kalkulasi COGS di M10[cite: 1].

---

## 12. Urutan Implementasi M6–M12 Berdasarkan Dependency Aktual (Area Audit 10)

Berdasarkan ketergantungan skema, aliran data, dan *shared services* yang nyata di repository, berikut adalah urutan implementasi yang paling aman untuk meminimalkan *rework* dan risiko regresi:

1. **Tahap 1 — M6 (Sales & Customer Management) & M8 (Pricing, Promotion & Loyalty):**
   - **Alasan Dependency:** Skema database dan model untuk `Customer`, `LoyaltyTier`, `LoyaltyLedger`, `CustomerLoyaltyAccount`, `Discount`, dan `DiscountRedemption` **sudah tersedia di database** (`2026_09_22_030000..03`, `2026_12_13_000001`), termasuk trigger `trg_loyalty_ledger_sync_account`[cite: 1].
   - **Pekerjaan Utama:** Ekstrak `DiscountService`, bangun `CustomerService` + `LoyaltyService` + `CustomerPolicy`, integrasikan pemilihan pelanggan & kalkulasi poin ke `TransactionService` (saat order lunas) dan `ReturnRefundService` (clawback poin saat void/retur), serta sediakan UI manajemen pelanggan[cite: 1].
2. **Tahap 2 — Penyelesaian M4 (Inventory Refinement) & Implementasi M5 (Purchasing & Supplier Management):**
   - **Alasan Dependency:** M7 (Finance/COGS) dan M10 (Reporting Laba Rugi) tidak dapat menghitung HPP (COGS) atau hutang dagang (AP) sebelum ada harga beli dari `Supplier`, `PurchaseOrder`, dan `GoodsReceipt` di M5[cite: 1]. Selain itu, `IngredientRestockForecast` yang sudah ada di M4/M10 membutuhkan M5 sebagai hilir eksekusinya[cite: 1].
   - **Pekerjaan Utama:** Ekstrak `IngredientService` dari `IngredientManager`, tambahkan konversi satuan (UoM) dan `unit_cost`, lalu bangun `Supplier`, `PurchaseOrder`, dan `GoodsReceipt` yang bermuara ke `StockMovement` / `IngredientStockMovement`[cite: 1].
3. **Tahap 3 — M7 (Finance & Accounting Expansion):**
   - **Alasan Dependency:** Setelah aliran pendapatan/retur (M2/M6/M8) dan aliran pembelian/HPP (M4/M5) lengkap, barulah buku besar akuntansi (`ChartOfAccounts`, `JournalEntry`), pencatatan biaya operasional (`Expense`), serta `AccountsPayable` (dari M5) dan `AccountsReceivable` (dari M6) memiliki sumber transaksi yang utuh[cite: 1].
4. **Tahap 4 — M9 (HR, Employee & Payroll):**
   - **Alasan Dependency:** Memanfaatkan fondasi `User`, `Role`, `Shift`, `ShiftHandover`, dan `EmployeeIdGenerator` yang sudah matang untuk membangun absensi (`Attendance`), komisi penjualan (berdasarkan data `Order` M2/M6), dan penggajian (`Payroll`) yang terhubung ke pengeluaran kas/jurnal di M7[cite: 1].
5. **Tahap 5 — M11 (SaaS Subscription & Platform Billing Lifecycle) & M12 (Advanced Integration & Automation):**
   - **Alasan Dependency:** Fondasi `Plan`, `PlanEntitlement`, `TenantSubscription`, dan `EnforceEntitlement` sudah berjalan[cite: 1]. Penagihan otomatis langganan SaaS (M11) dan *outbound webhook* / sinkronisasi stok balik ke GoFood & GrabFood (M12) sebaiknya diselesaikan setelah kontrak inventori (M4/M5) dan finansial (M7) stabil[cite: 1].
6. **Tahap 6 — M10 (Reporting & BI Tahap Akhir):**
   - **Alasan Dependency:** M10 adalah *consumer* paling hilir dari seluruh modul (M2, M4, M5, M6, M7, M8, M9)[cite: 1]. Laporan Laba Rugi (P&L), HPP/COGS, Valuasi Inventori, Aging AP/AR, dan Analitik Loyalitas Pelanggan diselesaikan pada tahap ini di atas `ReportService` dan infrastruktur `ExportTask` yang sudah ada[cite: 1].

---

## 13. Daftar Temuan yang Membutuhkan Keputusan Arsitektur (ADR Candidates)

1. **ADR-01: Otoritas Mutasi Saldo Loyalty (Database Trigger vs Application Service):**
   - Apakah tim akan mempertahankan trigger SQL `trg_loyalty_ledger_sync_account` (`2026_10_08_095041`) sebagai satu-satunya pemutakhir `customer_loyalty_accounts` (sehingga model `CustomerLoyaltyAccount` dikunci *read-only* di PHP), atau memindahkan logika tersebut ke `LoyaltyService` dengan *pessimistic row locking* seperti yang dilakukan pada `StockBalance`?[cite: 1]
2. **ADR-02: Standarisasi Layer Antara Livewire dan Service Layer:**
   - Apakah komponen Livewire (`IngredientManager`, `ModifierManager`, `DiscountManager`, `ChannelMappingManager`) diizinkan mengakses Model/DB secara langsung, atau wajib mendelegasikan seluruh mutasi ke `app/Services/` sebagaimana ditegakkan pada `TransactionService` dan `ShiftService`?[cite: 1]
3. **ADR-03: Strategi Inventori Terpadu (Unified Stock Ledger vs Dual Product/Ingredient Ledger):**
   - Apakah `StockBalance` (untuk `Product`) dan `Ingredient` (untuk bahan baku resep) akan tetap dipisah dalam dua skema ledger berbeda (`stock_movements` vs `ingredient_stock_movements`), atau disatukan dalam satu abstraksi *Inventory Item* ketika modul M5 (Purchasing) dan Multi-Warehouse dibangun?[cite: 1]
4. **ADR-04: Metode Perhitungan HPP / COGS (Moving Average vs FIFO) di M4/M5/M7:**
   - Sebelum membangun M5 (`GoodsReceipt`) dan M7 (Jurnal HPP), metode valuasi stok apa yang dipilih (Moving Average per `Store` vs FIFO per batch penerimaan)? Keputusan ini menentukan skema tabel penerimaan barang di M5 dan struktur kolom biaya pada `order_items`[cite: 1].
5. **ADR-05: Kebijakan Ekstensi Tabel `orders` yang Dilindungi Trigger Imutabilitas:**
   - Apakah atribut pasca-pembayaran di masa depan (status pengiriman M6, status posting jurnal M7, poin loyalty) boleh disimpan sebagai kolom pada tabel `orders` (yang mengharuskan pembaruan trigger `block_orders_mutation` setiap kali ada kolom baru), atau wajib disimpan pada tabel pendamping (*sidecar tables*) seperti `receipt_print_logs` dan `order_returns`?[cite: 1]

```

---

## Laporan Singkat Hasil Pekerjaan Audit

1. **File & Artefak yang Diperiksa:**
* **Dokumentasi Arsitektur & Audit (11 file):** `AGENTS.md`, `CLAUDE.md`, `README.md`, `README_SEEDER.md`, `docs/architecture/ARCHITECTURE_CONTRACT.md`, `ARCHITECTURE_DECISIONS.md`, `AUTHORIZATION_MODEL.md`, `FINANCIAL_INVARIANTS.md`, `INVENTORY_INVARIANTS.md`, `OWNERSHIP_MODEL.md`, `PROVISIONING.md`, `docs/audit/DOMAIN_1_VERIFICATION_EVIDENCE.md`, `docs/audit/claude/domain-01-db-multitenancy.md`, `docs/implementation/implementation-status.md`.


* **Database Migrations & SQL Verification (89 file):** Seluruh 77 file migrasi di `database/migrations/` (`0001_01_00` hingga `2026_12_14_000002`) serta 12 skrip audit/verifikasi di `database/sql/audit/` dan `database/sql/verify/01..10`.


* **Models, Scopes, & Concerns (54 file):** Seluruh 41 model di `app/Models/`, 5 concern di `app/Models/Concerns/`, dan 3 global scope di `app/Models/Scopes/`.


* **Services, Controllers, Livewire, Jobs, Commands, Policies, & Enums (165 file):** Seluruh 39 file di `app/Services/`, 43 controller di `app/Http/Controllers/`, 11 komponen Livewire di `app/Livewire/`, 4 job di `app/Jobs/`, 19 command di `app/Console/Commands/`, 15 policy di `app/Policies/`, dan 13 enum di `app/Enums/`.


* **Test Suite (59 file):** Seluruh feature dan unit test di `tests/Feature/` (`Auth`, `Core`, `Domain1`, `Domain2`, `Domain3`, `Domain4`, `Navigation`, `Reports`, `Security`, `Services`, `Settings`, `Tenancy`) dan `tests/Unit/`.




2. **File yang Dibuat / Disusun:**
* `docs/audit/crossModuleIntegrationAudit.md` (disajikan secara lengkap di atas untuk disimpan ke dalam repository tanpa menyentuh file *seeder* atau migrasi milik agent lain).




3. **Temuan Paling Kritis:**
* **[CRITICAL — F-01] Rantai M4 → M5 → M7 → M10 Terputus Karena Modul M5 (Purchasing & Supplier) Belum Ada:** Prediksi stok (`IngredientRestockForecast`) sudah berjalan, tetapi belum ada satu pun tabel/model/service untuk `Supplier`, `PurchaseOrder`, atau `GoodsReceipt`, sehingga penambahan stok dari pembelian dan perhitungan HPP (COGS) belum dapat berjalan.


* **[CRITICAL — F-02] Risiko Double-Counting Poin Pelanggan (M6/M8):** Database sudah memiliki trigger aktif `trg_loyalty_ledger_sync_account` (`2026_10_08_095041`) yang meng-update `customer_loyalty_accounts` otomatis saat `loyalty_ledger` di-insert, sementara `LoyaltyService` di PHP belum dibuat. Jika implementasi M6/M8 di aplikasi nanti juga meng-update kolom saldo di `CustomerLoyaltyAccount`, poin pelanggan akan terhitung dua kali lipat.


* **[HIGH — F-03 & F-05] Absennya Service Layer pada Domain Inventory/Modifier/Discount & Risiko Divergensi di 4 Entry Point Order:** Logika `IngredientManager`, `ModifierManager`, `DiscountManager`, dan `ChannelMappingManager` masih berada di komponen Livewire tanpa Service class di `app/Services/`, sementara pembuatan order masuk melalui 4 pintu (`CreateOrder` Livewire, `TransactionController`, `Customer\CheckoutController`, `ProcessWebhookOrderJob`) yang rawan mengalami ketidaksinkronan saat fitur M6/M8 ditambahkan.


* **[HIGH — F-04] 10+ Model Tanpa Policy Eksplisit:** Entitas penting seperti `Customer`, `OrderReturn`, `Payment`, `PaymentRefund`, `EodSettlement`, `ParkedCart`, `ModifierGroup`, dan `ChannelProductMapping` belum memiliki Policy di `app/Policies/`.




4. **Hal yang Belum Dapat Diverifikasi (`NOT VERIFIED`):**
* Isi baris-demi-baris kode internal pada file yang terkompresi *Deflate* di dalam bundel arsip statis (di luar struktur direktori, nama class, nama migrasi, dan nama test yang terlihat jelas pada header arsip).


* Eksekusi runtime `git status` dan `php artisan test` secara langsung karena lingkungan interaksi ini bersifat statis tanpa akses shell/database aktif.