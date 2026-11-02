# 01 — Screen Inventory

Metode: union dari (a) view yang direferensikan `view('...')` di `app/` + `routes/*.php`,
(b) komponen Livewire full-page (`Route::get(X::class)`), (c) file blade aktual.
Hasil mentah: **102 nama view unik** yang dirender, **230 file blade fisik**
(termasuk partial & komponen). Status: IMPLEMENTED / PARTIAL / PLACEHOLDER / DOC-ONLY.

## 1. Layout & Shell

| Layar | Path | Status | Catatan |
|---|---|---|---|
| App shell (sidebar+topbar) | `layouts/app.blade.php` + `layouts/navigation.blade.php` | IMPLEMENTED | Sidebar persist (`sidebar-persistence.js`), nav progress bar, mobile drawer dengan focus management |
| Guest/auth shell | `layouts/guest.blade.php` | IMPLEMENTED | Skip-link ada |
| Customer/self-order shell | `layouts/customer.blade.php` | IMPLEMENTED | Safe-area padding `pb-safe/pt-safe` |
| KDS TV shell | via `kds/index.blade.php`, tema `data-theme="kds-dark"` + breakpoint 3xl/4xl | IMPLEMENTED | Skala font 18px/24px di TV besar |

## 2. Modul Operasional

| Layar | Path | Status |
|---|---|---|
| Dashboard | `dashboard.blade.php` (524 baris) | IMPLEMENTED — 3 KPI card + aktivitas; hardcoded hex密集 (LI-03) |
| POS Terminal (kasir) | `livewire/kasir/create-order.blade.php` + 14 partial `livewire/pos/partials/` | IMPLEMENTED — cart sidebar, mobile cart drawer, modifier modal, park/unpark, keyboard shortcuts, checkout modal dengan idempotency key |
| Order history | `livewire/kasir/order-history.blade.php` | IMPLEMENTED |
| Shift manager | `livewire/shift-manager.blade.php`, `shifts/index.blade.php` | IMPLEMENTED |
| KDS board | `livewire/kds/board.blade.php` + partials | IMPLEMENTED (tema gelap TV) |
| Receipt cetak | `transaction/receipt.blade.php` | IMPLEMENTED (ESC/POS + QZ) |
| Table management | `tables/*.blade.php` | IMPLEMENTED |
| Waiter call | `components/waiter-call-button.blade.php` | IMPLEMENTED |
| Customer self-order (menu/cart/checkout) | `customer/{menu,cart,checkout}` | IMPLEMENTED |

## 3. Master Data (halaman Controller klasik + `<x-data-table>`)

| Layar | Path | Status |
|---|---|---|
| Products (index/create/edit) | `products/*.blade.php` | IMPLEMENTED |
| Categories, Brands, Units, Tax classes, Price lists | `categories/`, `brands/`, `units_of_measure/`, `tax_classes/`, `price_lists/` | IMPLEMENTED |
| Stores, Devices, Users, Roles, Invitations | `stores/`, `devices/`, `users/`, `roles/` | IMPLEMENTED |
| Discounts, Assortments, Activity logs | `discounts/`, `assortments/`, `activity-logs/` | IMPLEMENTED |
| Customers (manager Livewire) | `livewire/customers/*` (4 manager) | IMPLEMENTED |

## 4. Modul Gelombang 4–6 (Livewire manager full-page)

| Domain | Komponen | Status |
|---|---|---|
| Sales B2B | `sales-order-manager`, `quotation-manager`, `accounts-receivable-manager`, `return-credit-note-manager` | IMPLEMENTED |
| Purchasing | `purchase-order-manager`, `goods-received-note-manager`, `purchase-return-manager`, `supplier-invoice-manager`, `supplier-manager` | IMPLEMENTED |
| Inventory | 8 manager: GR, ingredient, ledger, modifier, opname, adjustment, transfer, supplier-return | IMPLEMENTED |
| Finance | `journal-entry-manager`, `chart-of-account-manager`, `accounting-period-manager`, `bank-reconciliation-workbench` + `finance/index.blade.php` | IMPLEMENTED |
| HR | 6 manager + partials | IMPLEMENTED |
| Pricing | `voucher-manager`, `promotion-campaign-manager`, `margin-simulation-modal` | IMPLEMENTED |
| Subscription/Settings | `settings/index.blade.php` | IMPLEMENTED |

## 5. Reporting & Analytics (Gelombang 7)

| Layar | Path | Status |
|---|---|---|
| Sales report | `reports/sales.blade.php` | IMPLEMENTED (async export + guard 366 hari) |
| Dashboard v2 metrik | `DashboardService` + test `DashboardMetricsAndButtonsTest` | IMPLEMENTED |
| RBI lanjutan (slice BI) | `SalesAnalyticsService` (untracked, WIP) | **PARTIAL / IN-PROGRESS** — tidak memiliki halaman UI sendiri saat audit |

## 6. Lain-lain

| Layar | Path | Status |
|---|---|---|
| Auth (9 layar) | `auth/*.blade.php` | IMPLEMENTED (login, register, 2FA, OTP verify, reset, invitation) |
| Onboarding | `onboarding/{business-profile,initial-branch}` | IMPLEMENTED |
| Profile | `profile/partials/*` | IMPLEMENTED |
| Investor page | `investor/index.blade.php` + 5 partials | IMPLEMENTED — **bukan bagian ERP**; dicurigai memakai gaya visual berbeda (marketing) (LI-06) |
| Error pages | tidak ditemukan file kustom 403/404/500 | **PLACEHOLDER** — memakai default Laravel (HI-04) |
| Pagination | `vendor/pagination/tailwind.blade.php` + `vendor/livewire/simple-tailwind` | IMPLEMENTED, sudah dikustomisasi (hardcoded hex) |

## 7. Statistik

- Layar fungsional teridentifikasi: **~75 layar unik** (102 view − partial/komponen).
- IMPLEMENTED: ~72 | PARTIAL: 1 (RBI UI) | PLACEHOLDER: error pages | DOC-ONLY: modul yang hanya ada di dokumen ERP (mis. integrasi marketplace UI penuh) tidak ditemukan sebagai layar.
- Sumber kebenaran navigasi: `config/navigation.php` — 7 section; sidebar HANYA merender route terdaftar; test `tests/Feature/Navigation/SidebarIntegrityTest.php` menjaga sinkronisasi (temuan positif).
