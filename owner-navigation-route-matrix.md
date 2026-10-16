# Apeiron POS — Owner Navigation Route Matrix
**Task ID:** `APEIRON-NAV-IMPL-001`  
**Repository:** `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`  
**Date:** 2026-10-16  
**Status:** COMPLETE — 18/18 ROUTES VERIFIED  

---

## Route and Navigation Matrix

| # | Group | Label | Actual Route Name | URI / Route Pattern | Destination Component / Controller | Authorized Roles | Tenant/Branch Scope | Active-State Matching Rule | Verification Evidence |
|---|:---|:---|:---|:---|:---|:---|:---|:---|:---|
| 1 | Overview | Dashboard | `dashboard` | `GET /dashboard` | `App\Http\Controllers\DashboardController@index` | Owner, Manager | `tenant` (with branch context) | `dashboard` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered`, `DashboardV2RedesignTest` |
| 2 | Penjualan & Pesanan | Riwayat Pesanan | `orders.index` | `GET /orders` | `App\Http\Controllers\TransactionController@index` (renders `<livewire:kasir.order-history />`) | Owner, Manager, Kasir, Waiter | `store` | `orders.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 3 | Penjualan & Pesanan | Laporan Penjualan | `reports.sales` | `GET /reports/sales` | `App\Http\Controllers\ReportController@index` | Owner, Manager | `tenant` (with multi-store filter) | `reports.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 4 | Penjualan & Pesanan | Diskon & Promo | `discounts.index` | `GET /discounts` | `App\Http\Controllers\DiscountController@index` (renders `<livewire:discount-manager />`) | Owner, Manager | `store` (`ScopedToStoreForDiscount`) | `discounts.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 5 | Katalog & Inventori | Produk | `products.index` | `GET /products` | `App\Http\Controllers\ProductController@index` | Owner, Manager, Inventory | `tenant` (Stock balance is store-scoped) | `products.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 6 | Katalog & Inventori | Kategori | `categories.index` | `GET /categories` | `App\Http\Controllers\CategoryController@index` | Owner, Manager, Inventory | `tenant` | `categories.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 7 | Katalog & Inventori | Varian & Modifier | `inventory.modifiers` | `GET /inventory/modifiers` | `App\Livewire\Inventory\ModifierManager` | Owner, Manager, Inventory | `tenant` | `inventory.modifiers` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 8 | Katalog & Inventori | Bahan Baku | `inventory.ingredients` | `GET /inventory/ingredients` | `App\Livewire\Inventory\IngredientManager` | Owner, Manager, Inventory | `store` | `inventory.ingredients` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 9 | Katalog & Inventori | Riwayat Stok | `inventory.ledger` | `GET /inventory/ledger` | `App\Livewire\Inventory\IngredientLedger` | Owner, Manager, Inventory | `store` | `inventory.ledger` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 10 | Operasional Toko | Shift Kasir | `shifts.index` | `GET /shifts` | `App\Http\Controllers\ShiftController@index` (renders `<livewire:shift-manager />`) | Owner, Manager, Kasir, Supervisor | `store` | `shifts.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 11 | Operasional Toko | Meja & QR | `tables.index` | `GET /tables` | `App\Http\Controllers\TableController@index` | Owner, Manager | `store` | `tables.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 12 | Operasional Toko | Integrasi Channel | `integrations.channel-mapping` | `GET /integrations/channel-mapping` | `App\Livewire\ChannelMappingManager` | Owner, Manager | `tenant` | `integrations.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |
| 13 | Organisasi & Pengaturan | Pengguna | `users.index` | `GET /users` | `App\Http\Controllers\UserController@index` | Owner | `tenant` | `users.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered`, `UserPolicy` |
| 14 | Organisasi & Pengaturan | Peran | `roles.index` | `GET /roles` | `App\Http\Controllers\RoleController@index` | Owner | `tenant` | `roles.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered`, `RolePolicy` |
| 15 | Organisasi & Pengaturan | Pengaturan | `settings.index` | `GET /settings` | `App\Http\Controllers\SettingController@index` | Owner | `tenant` | `settings.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered`, `SettingPolicy` |
| 16 | Organisasi & Pengaturan | Audit Log | `activity-logs.index` | `GET /activity-logs` | `App\Http\Controllers\ActivityLogController@index` | Owner, Manager | `tenant` | `activity-logs.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered`, `ActivityLogPolicy` |
| 17 | Terminal Kerja | Kasir (POS) | `transaction.create` | `GET /transaction` | `App\Livewire\Kasir\CreateOrder` | Owner, Manager, Kasir | `store` | `transaction.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered`, `UiUxDesignSystemAuditTest` |
| 18 | Terminal Kerja | Dapur (KDS) | `kds.index` | `GET /kds` | `App\Livewire\Kds\Board` | Owner, Manager, Kasir, Barista, Waiter, Cook | `store` | `kds.*` | `SidebarIntegrityTest::test_all_routes_referenced_by_sidebar_are_registered` |

---

## Secondary & Unlisted Header Actions

The following destinations are accessible via the top context header / profile menu and are verified as registered routes:

| Label | Route Name | URI | Destination Controller | Scope | Placement |
|:---|:---|:---|:---|:---|:---|
| Profil Saya | `profile.edit` | `GET /profile` | `App\Http\Controllers\ProfileController@edit` | User-scoped | Bottom Profile Popover (`resources/views/layouts/navigation.blade.php`) |
| Ganti Cabang | `store.switch` | `POST /switch-store` | `App\Http\Controllers\StoreSwitchController` | Tenant-scoped | Top Sidebar / Mobile Slot (`resources/views/components/branch-switcher.blade.php`) |
| Keluar | `logout` | `POST /logout` | `App\Http\Controllers\Auth\AuthenticatedSessionController@destroy` | Session | Bottom Profile Popover |
