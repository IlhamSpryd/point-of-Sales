# Apeiron POS — Wave 10 Principal Architecture & Enterprise Quality Audit

**Mandate:** Forensic-grade architectural audit & Wave 10 strategic blueprint benchmarked against Global Tier-1 SaaS & Hospitality/POS standards (Stripe, Toast, Square, Shopify POS) at **1,000+ tenants / 5,000+ concurrent terminals** scale.  
**Audit Mode:** Strictly Read-Only Repository & Pipeline Inspection (0 mutations to application code, schemas, tests, `.env`, or `yovel_pos_test`).  
**Audit Date:** 2026-11-26 | **Baseline Head:** `543879b` (`v0.9-wave9-closure`)

---

## 1. Executive Verdict & Tier-1 Maturity Score

**Composite Verdict: `4.8 / 10` — Conditionally Pilot-Ready (Mid-Market), NOT Global Tier-1 Production Ready.**

Wave 9 stabilized PHPUnit regression suites and landed critical Indonesian statutory payroll, basic billing webhooks, and accessibility hygiene. However, forensic inspection reveals **load-bearing structural holes** that green unit/feature tests mask: a zero-payment self-serve SaaS plan activation bypass directly exposed in Livewire UI, client-mutable Livewire POS cart state enabling open-price/discount forgery, cross-tenant accounting period IDOR, broken strict-mode offline queuing (`ReferenceError: userId is not defined`), silently 0-row/OOM-prone exports, async Workforce queue jobs that fail closed or corrupt persistent worker tenant state, and virtually **0% transactional Playwright E2E confidence**.

| Dimension | Score (1–10) | Tier-1 Gap Summary |
| :--- | :---: | :--- |
| **Multi-Tenancy & Security** | **5.5** | `TenantContext` + composite FK baseline is strong, but undermined by `990` bypass calls (`989` unannotated, 0 ratchet headroom), cross-tenant IDOR in `AccountingPeriodService`, suspended-tenant lockout mismatch in `RequiresTenantContext`, fail-open OFD HMAC in `WebhookController`, async Workforce jobs failing closed, and `RunMonthlyDepreciationJob` context poisoning. |
| **Concurrency & Financial Ledger** | **5.5** | BCMath `Money` & `PaymentInboxDispatcher` exist, yet `SubscriptionPortal::confirmInstantUpgrade` activates paid tiers with zero payment; `SubscriptionBillingOrchestrator::settleFromWebhook` lacks `lockForUpdate()`; Livewire POS `$cart`/`$itemDiscounts` lack `#[Locked]` (bypassing `StoreTransactionRequest` open-price guards); `InventoryLedgerService` clones stock across branches & short-circuits batch idempotency; `ReturnRefundService` uses floats, skips refund-allocation sum validation, and swallows outbox failures. |
| **Modularity & Coupling** | **5.0** | `ADR-102` 6-tier `module.json` & `DependencyDirectionTest` hold acyclicity, but `751 / 760` cross-module `use` statements remain (`614` = `81.8%` import `Models\` directly), `31` thin wrapper controllers in `app/Http/Controllers/` evade `ADR-114`, Midtrans webhook handling is duplicated across two routes, and `10` God Services are ratcheted up to `1,485` lines (`ReturnRefundService`: `1,484` lines). |
| **E2E & Operational Confidence** | **2.5** | `tests/e2e/` contains only `accessibility.spec.ts` (WCAG/theme/route crawl). Zero Playwright E2E coverage for POS checkout, Midtrans QRIS webhooks/timeouts, voids/refunds, shift reconciliation, or offline sync. CI (`.github/workflows/tests.yml`) never runs Playwright, omits `--fail-on-risky` (`risky: 1` passes silently), and executes `PosFullSeeder` (10–15 min) on `migrate:fresh --seed`. |
| **Performance & OOM Hardening** | **4.5** | `InventoryLedgerExport::streamCsv` returns `0` rows on all-stores exports via `StoreScope` (`WHERE 1 = 0`) and `ProcessInventoryLedgerExportJob` still defaults to OOM-prone `'xlsx'`; `SalesExport` & `ProductsExport` apply PhpSpreadsheet `ShouldAutoSize`/`allBorders` defeating chunking; 5 synchronous `Excel::download()` endpoints lack streaming/caps; `DashboardOperationsQueryService` runs `5N+1` queries; `app-*.js` (`131,013` B) sits at `97.0%` of the `135 kB` budget while `vendor-echarts` (`1.08 MB`) & `qz-tray.js` (`150.5 KB`) bloat pages. |
| **Code & Quality Hygiene** | **5.5** | Pint and Larastan run in CI, but quality ratchets sit pinned at ceiling (`990/990`, `751/760`), `NavigationGovernanceTest` emits 0 assertions (`risky: 1`), `GeneratePayslipPdfJob` contains stub comments only, 4 async export jobs mark status `Failed` before retry attempt #2, `ProcessAnalyticsExportJob` writes `exports/tenant-` (hyphen) vs `ExportTaskController` checking `exports/tenant_` (underscore), and `LoyaltyLedgerSeeder` breaks its own SHA-256 hash chain every 1,000 rows. |

---

## 2. Critical & High-Severity Architectural Findings

### P0 — Critical Severity (Revenue Loss, Security Bypass, Data/Ledger Corruption, Broken Core Flows)

#### `[P0-01]` Free Self-Serve SaaS Plan Upgrade Bypass & Subscription Activation Race Conditions
* **Location:**
  * [`app/Livewire/Subscription/SubscriptionPortal.php:70-85`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Subscription/SubscriptionPortal.php#L70-L85)
  * [`resources/views/livewire/subscription/subscription-portal.blade.php:146-148`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/views/livewire/subscription/subscription-portal.blade.php#L146-L148)
  * [`app/Modules/Subscription/Application/Services/SubscriptionBillingOrchestrator.php:98-153, 219-318`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Subscription/Application/Services/SubscriptionBillingOrchestrator.php#L98-L153)
* **Evidence:**
  * `SubscriptionPortal::confirmInstantUpgrade()` (`L70-85`) has **zero `$this->authorize()` / `AbilityEvaluator` check** inside the Livewire component and executes:
    ```php
    $upgradeOrder = $orchestrator->initiateUpgrade($tenantId, (int) $targetPlan->id, $this->billingCycle);
    $orchestrator->activatePaidSubscription($tenantId, (int) $targetPlan->id, $upgradeOrder['order_id']);
    ```
  * In `subscription-portal.blade.php:146`, clicking `"Aktivasi Instan (Self-Serve)"` immediately provisions full enterprise entitlements and quotas without waiting for any Midtrans Snap settlement or webhook callback.
  * In `SubscriptionBillingOrchestrator.php:98-100`, any Midtrans API error silently falls back to `'snap_token_sim_'.Str::random(24)`. At `L124-129`, `activatePaidSubscription()` queries `DB::table('tenant_subscriptions')` **without `->lockForUpdate()`** and derives `$cycleMonths` from `$targetPlan->billing_cycle` rather than the purchased `$billingCycle`. At `L230-310`, `settleFromWebhook()` reads `ProvisioningRequest` and `TenantSubscription` outside `DB::transaction()` and without row locks before updating `ProvisioningRequest::STATUS_COMPLETED` or calling `activatePaidSubscription()`.
* **Real-World Failure / Exploit Scenario:**
  1. Any tenant owner signs up on the Free/Starter tier, visits `/subscription`, selects the highest Enterprise plan, and clicks **"Aktivasi Instan (Self-Serve)"** (or dispatches Livewire `confirmInstantUpgrade` directly). They receive instant Enterprise quotas (`MAX_STORES`, `MAX_DEVICES`, `MAX_USERS`) for Rp 0, bypassing billing entirely.
  2. Dual concurrent Midtrans webhooks for `SUB-*` can both pass `ProvisioningRequest` status & `$existingSub` metadata checks concurrently, firing duplicate `activatePaidSubscription()` transitions.
  3. Paying an annual upgrade fee against a plan whose DB default `billing_cycle` is `monthly` grants only **1 month** instead of **12 months**.
* **Tier-1 Remediation:**
  1. Remove `confirmInstantUpgrade` self-activation immediately; `"Proceed to Payment"` must only return `initiateUpgrade()` (`payment_token` + `order_id`), opening Midtrans Snap and transitioning `TenantSubscription` **exclusively** via verified webhook settlement or `Transaction::status()` reconciliation.
  2. Enforce `$this->authorize('subscription.manage')` inside `SubscriptionPortal::boot()` and fail closed (`502/503`) on Midtrans gateway failures in non-local environments instead of minting `snap_token_sim_*`.
  3. Wrap `settleFromWebhook()` and `activatePaidSubscription()` in `DB::transaction()` with `->lockForUpdate()` on both `provisioning_requests` and `tenant_subscriptions`, persisting `purchased_billing_cycle` onto `ProvisioningRequest`.

---

#### `[P0-02]` Un-`#[Locked]` Livewire POS Cart & Discount State Enables Open-Price & Discount Forgery
* **Location:**
  * [`app/Livewire/Kasir/Concerns/ManagesPosCartAndVariants.php:25`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Kasir/Concerns/ManagesPosCartAndVariants.php#L25)
  * [`app/Livewire/Kasir/Concerns/ManagesPosPromotionsAndCustomer.php:36, 46, 50`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Kasir/Concerns/ManagesPosPromotionsAndCustomer.php#L36-L50)
  * [`app/Livewire/Kasir/Concerns/ManagesPosPaymentAndSplitBill.php:314-328`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Kasir/Concerns/ManagesPosPaymentAndSplitBill.php#L314-L328)
  * [`app/Modules/Sales/Domain/Services/OrderLinePreparationService.php:150-183`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Domain/Services/OrderLinePreparationService.php#L150-L183)
  * Contrast: [`app/Http/Requests/StoreTransactionRequest.php:138-158`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Http/Requests/StoreTransactionRequest.php#L138-L158)
* **Evidence:**
  * `ManagesPosCartAndVariants` exposes `public array $cart = [];` (`L25`) without Livewire `#[Locked]`. `ManagesPosPromotionsAndCustomer` exposes `public array $itemDiscounts = [];` (`L36`), `public int $appliedManualDiscount = 0;` (`L46`), and `public ?int $manualDiscountApprovalId = null;` (`L50`) without `#[Locked]`.
  * `ManagesPosPaymentAndSplitBill` forwards `isset($raw['custom_price']) ? (float) $raw['custom_price'] : null` (`L314, L322`) and `(int) ($raw['item_discount_amount'] ?? 0)` (`L327`) directly to `TransactionService` -> `OrderLinePreparationService::prepare()`.
  * Unlike HTTP API checkout (`StoreTransactionRequest.php:138-158`), `OrderLinePreparationService.php:150-183` passes `customPrice: $customPrice` straight to `CatalogPriceResolverService::resolvePrice()` and accepts `(float) ($item['item_discount_amount'] ?? 0)` **without checking `$product->is_open_price`, `pos.price.open_entry`, or `$manualDiscountApprovalId` validity**.
* **Real-World Failure / Exploit Scenario:**
  A cashier or compromised browser DevTools session intercepts `/livewire/update` on `/transaction` and injects `updates["cart.0.custom_price"] = 1` or `updates["cart.0.item_discount_amount"] = 150000` (or inflates `appliedManualDiscount`). Because Livewire components bypass `StoreTransactionRequest`, the server builds, settles, and prints a valid paid order for Rp 1 instead of Rp 150,000, while deducting full COGS/inventory.
* **Tier-1 Remediation:**
  1. Annotate `$cart`, `$itemDiscounts`, `$appliedManualDiscount`, `$appliedManualDiscountReason`, and `$manualDiscountApprovalId` with `#[Locked]` (all cart/discount mutations must route exclusively through authenticated Livewire action methods).
  2. Move the `is_open_price` + `pos.price.open_entry` check and manual/item discount ceiling & `Approval` verification into `OrderLinePreparationService` / `CheckoutOrderHandler` (domain layer single source of truth), never relying solely on a HTTP FormRequest.

---

#### `[P0-03]` POS Offline Queue Crashes in Strict Mode, Has Zero UI Callers & Fails to Guard Logout
* **Location:**
  * [`public/js/pos-offline-queue.js:6, 118-149, 288-305`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/public/js/pos-offline-queue.js#L118-L149)
  * [`resources/views/livewire/kasir/create-order.blade.php:215`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/views/livewire/kasir/create-order.blade.php#L215)
  * [`public/sw.js:6-13, 42, 70-77`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/public/sw.js#L6-L13)
* **Evidence:**
  * `resources/views/livewire/kasir/create-order.blade.php:215` loads `pos-offline-queue.js`, but a repository-wide search across `resources/`, `public/`, and `app/` confirms `ApeironOfflinePos.enqueueOrder()` has **zero callers anywhere in the UI** — cashiers offline never queue transactions.
  * Even if invoked, `public/js/pos-offline-queue.js` runs under `'use strict';` (`L6`), and `enqueueOrder()` (`L132`) executes:
    ```javascript
    const parsedUserId = parseInt(userId, 10) || null;
    ```
    where `userId` is **completely undeclared** inside `enqueueOrder` (`getActiveUserId()` at `L21-24` is never called), deterministically throwing `ReferenceError: userId is not defined`.
  * At `L288-305`, the logout `submit` listener calls `ApeironOfflinePos.getPendingCount().then(...)` asynchronously and invokes `e.preventDefault()` (`L297`) inside the `.then()` promise resolution while calling `ApeironOfflinePos.closeDatabase()` synchronously at `L303`.
  * In `public/sw.js:6-13, 42, 70-77`, `/transaction` and Vite `/build/assets/*` are omitted from `PRECACHE_ASSETS`, and `L42` bypasses `/livewire/`.
* **Real-World Failure / Exploit Scenario:**
  1. During a store Wi-Fi outage, Livewire checkout requests fail with a network modal; nothing calls `enqueueOrder()`, halting all cashier checkout.
  2. Wiring any caller to `enqueueOrder()` immediately crashes with `ReferenceError` in strict mode, losing the order.
  3. On logout, the form submits immediately *before* `.getPendingCount().then()` resolves, stranding unsynced transactions in the cashier's IndexedDB `apeiron_pos_tenant_<T>_user_<U>` database and losing cash drawer accountability when another cashier logs in.
* **Tier-1 Remediation:**
  1. Fix `const userId = getActiveUserId();` in `pos-offline-queue.js:132`, wire Alpine checkout fallback to `ApeironOfflinePos.enqueueOrder()` when `navigator.onLine === false` or `fetch` throws network failure (cash tender only, client UUID generated once per draft).
  2. Synchronously track `pendingCount` in memory/localStorage and call `e.preventDefault()` *synchronously* on logout forms when `cachedPendingCount > 0`, prompting sync or shift-supervisor override.
  3. Add offline POS app-shell precaching or an Alpine-only local catalog snapshot (`IndexedDB` `catalog_snapshot`) synced at `/transaction` mount so offline F5 reloads can still ring cash sales without `/livewire/update`.

---

#### `[P0-04]` Cross-Tenant Accounting Period Close & Gate Evaluation IDOR
* **Location:** [`app/Modules/Finance/Services/AccountingPeriodService.php:136-139, 418-435`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Services/AccountingPeriodService.php#L136-L139)
* **Evidence:**
  ```php
  // L136-139
  public function evaluateClosingGates(int|AccountingPeriod $period, ?User $actor = null): array
  {
      $period = $period instanceof AccountingPeriod ? $period : AccountingPeriod::withoutTenantScope()->findOrFail($period);
      $tenantId = $period->tenant_id;

  // L418-421
  public function closePeriod(int|AccountingPeriod $period, User $actor): AccountingPeriod
  {
      $period = $period instanceof AccountingPeriod ? $period : AccountingPeriod::withoutTenantScope()->findOrFail($period);
      $tenantId = $period->tenant_id;
  ```
* **Real-World Failure / Exploit Scenario:**
  Both `evaluateClosingGates` and `closePeriod` accept an integer `$period`, bypass `TenantScope` via `withoutTenantScope()`, and then derive `$tenantId` **from the fetched foreign record** instead of verifying `$period->tenant_id === $actor->tenant_id` (or `TenantContext::requireTenantId()`). Any endpoint, job, or Livewire component forwarding a client-controlled integer `$periodId` lets Tenant A close Tenant B's open fiscal accounting period — freezing Tenant B's POS & GL postings (`PeriodClosedException` at `L125`) and writing `closed_by_user_id = $actor->id` across tenant boundaries.
* **Tier-1 Remediation:**
  Require `$expectedTenantId = $actor?->tenant_id ?? app(TenantContext::class)->requireTenantId();` and scope lookup with `AccountingPeriod::withoutTenantScope()->where('tenant_id', $expectedTenantId)->findOrFail($period);` (plus strict assertion `$period->tenant_id === $expectedTenantId` when an `AccountingPeriod` model instance is passed).

---

#### `[P0-05]` Async Workforce Jobs Fail Closed (`null`), Unimplemented Payslip Stub, Worker Context Poisoning & Livewire Suspension Contradiction
* **Location:**
  * [`app/Modules/Workforce/Jobs/PostPayrollToGeneralLedgerJob.php:32-46`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Workforce/Jobs/PostPayrollToGeneralLedgerJob.php#L32-L46)
  * [`app/Modules/Workforce/Jobs/GeneratePayslipPdfJob.php:28-39`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Workforce/Jobs/GeneratePayslipPdfJob.php#L28-L39)
  * [`app/Modules/Workforce/Models/PayrollBatch.php:54`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Workforce/Models/PayrollBatch.php#L54) & [`PayrollRecord.php:55`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Workforce/Models/PayrollRecord.php#L55)
  * [`app/Modules/Finance/Jobs/RunMonthlyDepreciationJob.php:30-54`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Jobs/RunMonthlyDepreciationJob.php#L30-L54)
  * [`app/Livewire/Concerns/RequiresTenantContext.php:49-54`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Concerns/RequiresTenantContext.php#L49-L54) vs [`app/Http/Middleware/SetTenantContext.php:99-124`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Http/Middleware/SetTenantContext.php#L99-L124)
* **Evidence:**
  1. `PostPayrollToGeneralLedgerJob::handle()` (`L35`) executes `PayrollBatch::find($this->payrollBatchId)` inside a stateless queue worker where `TenantContext` is unset. Because `PayrollBatch` uses `ScopedToTenant`, `TenantScope` injects `WHERE 1 = 0`, returning `null` and silently exiting at `L37-39` with **zero exception and zero GL journal posted**.
  2. `GeneratePayslipPdfJob::handle()` (`L31`) similarly fails closed on `PayrollRecord::with(...)->find($this->payrollRecordId)` **and** lines `L37-38` are literally unimplemented stub comments (`// Simpan / render template slip gaji HTML / PDF ke storage`).
  3. `RunMonthlyDepreciationJob::handle()` (`L34`) mutates the singleton `$tenantContext->setTenantId($this->tenantId)` with **no `try/finally` restoration**. If `$actor` is missing (`L48` early return) or `postMonthlyDepreciation()` throws, the queue worker retains `$tenantId` in memory for the next non-isolated job.
  4. `SetTenantContext.php:99-109` intentionally exempts `/subscription*`, `*shifts/close*`, `*kasir/shift/close*`, `*tables/*/release*`, and `*orders/*/pay*` from `isSuspended()` lockout so suspended tenants can settle billing or close an active night shift. Yet Livewire requests to `/livewire/update` execute `RequiresTenantContext.php:49-54`, which logs the user out, invalidates the session, and aborts `403` with **zero grace exemption**.
* **Real-World Failure / Exploit Scenario:**
  * Async payroll dispatches succeed cleanly in unit/sync tests (`TenantContext` already populated by the test/HTTP request) but **100% silently drop GL accrual/payment entries and PDF slips** in production Redis/database queue workers.
  * A suspended tenant owner opens `/subscription` (allowed by `SetTenantContext`), clicks any Livewire button (`/livewire/update`), and is immediately logged out with HTTP 403 by `RequiresTenantContext` — locking them out of paying their arrears.
* **Tier-1 Remediation:**
  1. Extend `App\Platform\Tenancy\TenantAwareJob` in `PostPayrollToGeneralLedgerJob`, `GeneratePayslipPdfJob`, and `RunMonthlyDepreciationJob` (eliminating manual `setTenantId` leakage and guaranteeing fail-closed restoration in `finally`). Implement PDF rendering via `barryvdh/laravel-dompdf` (already in `composer.json`) in `GeneratePayslipPdfJob`.
  2. Align `RequiresTenantContext::bootRequiresTenantContext()` with `SetTenantContext`: allow suspended tenants only on allowlisted `SubscriptionPortal` / `ShiftManager` close / `CreateOrder` settle actions via explicit `$this->allowsSuspendedTenantAccess()` hook verified against route/referer context.

---

#### `[P0-06]` Inventory Ledger CSV Export Returns 0 Rows for All-Stores + Broken SKU + Job Retry Dead-End
* **Location:**
  * [`app/Exports/InventoryLedgerExport.php:231, 271, 298`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Exports/InventoryLedgerExport.php#L231-L298)
  * [`app/Modules/Inventory/Models/StockMovement.php:49`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Inventory/Models/StockMovement.php#L49) & [`IngredientStockMovement.php:38`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Inventory/Models/IngredientStockMovement.php#L38)
  * [`app/Jobs/ProcessInventoryLedgerExportJob.php:81, 98-99, 116, 129-134`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Jobs/ProcessInventoryLedgerExportJob.php#L81-L134)
* **Evidence:**
  * Both `StockMovement` and `IngredientStockMovement` apply `ScopedToStore` (`StoreScope`: injects `WHERE 1 = 0` whenever `TenantContext::getStoreId() === null`).
  * `InventoryLedgerExport::streamCsv()` (`L231, L271`) executes `StockMovement::query()` and `IngredientStockMovement::query()` **without `::allStores()`**. When an Owner triggers an all-branch inventory ledger export (`$this->storeId = null` in `ProcessInventoryLedgerExportJob`), `streamCsv()` yields a **header-only 0-row CSV**.
  * Line `298` reads `$im->ingredient->code ?? '-'`, but the column on `ingredients` is `ingredient_code` (`DemoDataSeeder.php:23`), rendering `'-'` on every ingredient row.
  * In `ProcessInventoryLedgerExportJob.php:98-99`, `$params['format'] ?? 'xlsx'` defaults to `'xlsx'`, routing most exports into `Excel::store($exportInstance)` -> `InventoryLedgerExport::collection()` (full in-memory hydration + PhpSpreadsheet DOM -> OOM). And lines `129-134` update `ExportTaskStatus::Failed` *before* `throw $e;`, causing attempt #2 (`public int $tries = 2`) to immediately exit at `L81` (`if ($status !== Pending && $status !== Processing) return;`).
* **Real-World Failure / Exploit Scenario:**
  Multi-branch Owners downloading a consolidated stock ledger receive an empty file (if CSV) or trigger PHP `512M` OOM exhaustion (if default XLSX). Any transient DB/storage glitch on attempt #1 permanently kills retry #2 because the task is already marked `Failed`.
* **Tier-1 Remediation:**
  Use `$targetStoreId !== null ? StockMovement::query() : StockMovement::allStores()` (same for `IngredientStockMovement::allStores()`), fix `$im->ingredient->ingredient_code ?? '-'`, default `$format` to `'csv'` (or stream XLSX via OpenSpout, see §4.3), and remove the premature `ExportTaskStatus::Failed` update from `catch (Throwable $e)` — leaving failure marking strictly to `failed(?Throwable $exception)` after `$tries` are exhausted.

---

### P1 — High Severity (Payment Desync, Authorization Gaps, Inventory Drift, OOM Vectors)

#### `[P1-01]` Duplicate Unthrottled Midtrans Endpoint, Fail-Open Gross Amount & `PaymentAttempt` State Drift
* **Location:**
  * [`routes/api.php:44-45`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/routes/api.php#L44-L45) & [`app/Modules/Integration/Controllers/PaymentNotificationController.php:38-63`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Integration/Controllers/PaymentNotificationController.php#L38-L63) vs [`routes/sales.php:27-29`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/routes/sales.php#L27-L29) & [`MidtransNotificationController.php:89-156`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Controllers/MidtransNotificationController.php#L89-L156)
  * [`app/Modules/Sales/Application/Handlers/OnlinePaymentAndWebhookHandler.php:59-65, 328-333, 425-431, 493-503`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Application/Handlers/OnlinePaymentAndWebhookHandler.php#L425-L503)
  * [`app/Console/Commands/ReconcilePendingMidtransOrders.php:21-41`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Console/Commands/ReconcilePendingMidtransOrders.php#L21-L41)
* **Evidence:**
  1. `POST /api/payment/notification` (`PaymentNotificationController`) has **no `throttle` middleware**, bypasses `PaymentInboxDispatcher` idempotency (`L62`), and fails with `404` on `SUB-*` subscription orders (whereas `POST /midtrans/notification` routes through `throttle:120,1`, `SubscriptionPaymentSettlementInterface`, and `PaymentInboxDispatcher`).
  2. `OnlinePaymentAndWebhookHandler.php:59, 425` treats `$grossAmount === null` as valid (`if ($grossAmount === null || ...)` -> fail-open if caller omits `gross_amount`) and permits up to `max(10000, round($expectedAmount * 0.15))` tolerance.
  3. `settlePaymentAttempt()` (`L328-333`) only updates `order_status = OrderStatus::Paid` while omitting `order_lifecycle_status`, `payment_status`, and `fulfillment_status` (which `updateStatusFromMidtransNotification` sets at `L92-95` and `CheckoutOrderHandler` sets at `L392-394`).
  4. `buildSnapParams()` (`L493-503`) excludes `service_charge_amount` from `$itemDetails` and calculates `$snapRounding = total - (subtotal - discount + tax)`, pushing service charges (and inclusive tax offsets) into a positive/negative `ROUNDING` line item.
  5. `ReconcilePendingMidtransOrders.php:27-32` hardcodes single global `config('services.midtrans.server_key')` and queries `Transaction::status($order->order_code)` without checking `PaymentAttempt.provider_order_id` (`*-ATTn` retry suffix).
* **Real-World Failure / Exploit Scenario:**
  Split-attempt QRIS retries (which generate `ORD-...-ATT2` as `provider_order_id`) fail Midtrans reconciliation with `404` and get **erroneously expired** at `ReconcilePendingMidtransOrders.php:43-52` even when the customer paid `-ATT2`! Settled attempts leave `payment_status = awaiting_payment` / `fulfillment_status = unfulfilled`, hiding paid orders from downstream ERP queries.
* **Tier-1 Remediation:**
  Deprecate/redirect `/api/payment/notification` to the canonical `MidtransNotificationController` pipeline; reject `null` `grossAmount`; unify `Order` completion state mutation into one domain transition (`settlePaymentAttempt` must set `order_lifecycle_status`, `payment_status`, `fulfillment_status`); add `service_charge_amount` to `buildSnapParams()` and support tax-inclusive item breakdowns; and iterate `PaymentAttempt::whereIn('status', [PendingProvider, UnknownTimeout])` using per-tenant gateway config & `provider_order_id` inside `ReconcilePendingMidtransOrders`.

---

#### `[P1-02]` Fail-Open OFD Webhook HMAC & 31 Legacy Controller Wrappers Evading `ADR-114`
* **Location:**
  * [`app/Http/Middleware/VerifyWebhookSignature.php:16-22`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Http/Middleware/VerifyWebhookSignature.php#L16-L22)
  * [`app/Http/Controllers/Api/WebhookController.php:100-129`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Http/Controllers/Api/WebhookController.php#L100-L129)
  * `app/Http/Controllers/` (`31` legacy thin wrapper classes extending `\App\Modules\*\Controllers\*`, e.g. [`app/Http/Controllers/ExportTaskController.php:12`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Http/Controllers/ExportTaskController.php#L12))
* **Evidence:**
  * `VerifyWebhookSignature` lets `grabfood`/`gofood` bypass global secret verification expecting `WebhookController` to enforce per-tenant HMAC. However, `WebhookController.php:108-128` wraps verification inside `if ($ofdConfig && ! empty($ofdConfig->signing_secret))`. If no active `ofd_channel_configs` row exists or `signing_secret` is empty, `WebhookController` proceeds to `ChannelOrderLog::create()` and returns `HTTP 202`!
  * Automated scan confirms **31 wrapper controllers** still sit in `app/Http/Controllers/`, unguarded because `ModuleBoundaryTest` only prohibits `ADR-114` wrappers in `app/Models/` and `app/Services/`.
* **Real-World Failure / Exploit Scenario:**
  An attacker targeting `/api/tenants/{id}/webhooks/grabfood/orders` for any tenant that has not configured a `signing_secret` injects fake GrabFood orders with `202 Accepted`, printing kitchen tickets and deducting ingredient inventory without signature verification.
* **Tier-1 Remediation:**
  Make `WebhookController` **fail closed (`401/403`)** when an OFD driver lacks an active `ofd_channel_configs` row or non-empty `signing_secret`, and point routes directly to canonical `App\Modules\<Context>\Controllers\*` while deleting the 31 legacy wrappers (expanding `ModuleBoundaryTest` to assert `app/Http/Controllers/` contains zero `extends \App\Modules\` wrappers).

---

#### `[P1-03]` Cross-Branch Stock Cloning, Premature Batch Idempotency Exit & Float/Allocation Flaws in Returns
* **Location:**
  * [`app/Modules/Inventory/Services/InventoryLedgerService.php:119-134, 448-467`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Inventory/Services/InventoryLedgerService.php#L119-L134)
  * [`app/Modules/Sales/Services/ReturnRefundService.php:167-174, 290-351, 378, 436, 509-525, 638-642`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/ReturnRefundService.php#L167-L174)
* **Evidence:**
  1. `InventoryLedgerService::ensureBalanceRowExists()` (`L453-467`) initializes any newly touched `(tenant_id, store_id, product_id)` balance row using tenant-global legacy `DB::table('products')->...->value('stock')` (or `ingredients.current_stock`).
  2. `InventoryLedgerService::recordBatchMovements()` (`L119-134`) iterates `$movements`, and on the **very first** item matching an existing `idempotency_key`, immediately executes `return [[...]]` — discarding every subsequent element in `$movements`.
  3. `ReturnRefundService::processReturnAndRefund()`:
     * `L167-170`: Locks `Shift::withoutGlobalScopes()->where('id', $shiftId)` **without verifying `tenant_id === $lockedOrder->tenant_id` or `store_id === $lockedOrder->store_id`**.
     * `L290-351`: Checks each refund allocation against `payment.amount`, and sets `total_refundable_amount = $totalRefundAmount` (`L378`), **without checking `SUM(refundAllocations) <= maxRefundableForReturnedItems`**; performs currency math in native PHP `float` (`L292, L308, L313, L329-334, L340`); mutates `Product::$stock` with `(int) round(...)` (`L436`); silently drops Gift Card balance credit if `$cardNumber` is missing (`L509-525`); and swallows `FinancialOutboxDispatcherService::dispatchSalesReturnRefunded()` exceptions (`L638-642`) with `Log::warning` inside the committed DB transaction.
* **Real-World Failure / Exploit Scenario:**
  * Opening Branch B and selling/adjusting Product X automatically seeds Branch B's `product_stock_balances` with **all 500 units currently sitting in Branch A's legacy `products.stock` column**, inventing phantom inventory.
  * Returning 1 Rp 15,000 item from a Rp 500,000 order while supplying a Rp 500,000 allocation in `$refundAllocations` passes validation and pays out Rp 500,000, while an outbox exception commits the refund with zero GL reversal entry.
* **Tier-1 Remediation:**
  1. Initialize new branch rows to `quantity = 0` (`ensureBalanceRowExists`) — stock enters a new branch strictly via `StockMovement` (Transfer/Purchase/Adjustment).
  2. In `recordBatchMovements()`, filter/resolve already-recorded items individually instead of returning early on the first hit.
  3. In `ReturnRefundService`: scope `Shift` lock to `(tenant_id, store_id)`, convert all math to `Money`/BCMath, assert `Money::sum(refundAllocations) == returnedTotal` (from `ReturnProportionCalculatorService`), require non-empty `cardNumber` for `gift_card` refunds before commit, and never catch-and-swallow `FinancialOutboxDispatcherService` exceptions inside `DB::transaction()`.

---

#### `[P1-04]` Non-Sequential `order_code`, Offline Shift Store Mismatch & Unprotected Livewire Components
* **Location:**
  * [`app/Modules/Sales/Application/Handlers/CheckoutOrderHandler.php:370-372`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Application/Handlers/CheckoutOrderHandler.php#L370-L372)
  * [`app/Modules/Sales/Services/OfflineOrderSyncService.php:109-120`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/OfflineOrderSyncService.php#L109-L120)
  * [`app/Livewire/Sales/ReturnCreditNoteManager.php:20`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Sales/ReturnCreditNoteManager.php#L20), [`app/Livewire/Pricing/MarginSimulationModal.php:15-55`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Pricing/MarginSimulationModal.php#L15-L55), [`app/Livewire/Customers/Concerns/ManagesCustomerStatementsAndMerge.php:83-96`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Customers/Concerns/ManagesCustomerStatementsAndMerge.php#L83-L96), [`app/Livewire/Inventory/IngredientLedger.php:24`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Inventory/IngredientLedger.php#L24)
* **Evidence:**
  * `CheckoutOrderHandler.php:370-372` generates `POS-{6chars}-{3digits}` and checks uniqueness using `Order::where('order_code', $orderCode)->exists()` (which applies `StoreScope` — a collision across two branches triggers DB `UNIQUE` failure instead of using `App\Platform\Documents\DocumentSequenceService`).
  * `OfflineOrderSyncService.php:109-120` queries `Shift::where('tenant_id', $tenantId)->where('id', $creatorShiftId)->first()` without checking `->where('store_id', $storeId)`, and routes cash settlement through live catalog/stock guards that can reject already-completed offline cash orders if price changed or stock hit zero in the meantime.
  * `ReturnCreditNoteManager` (`L20`) and `MarginSimulationModal` (`L15`) omit `use RequiresTenantContext;`; `MarginSimulationModal::open()` (`L26-55`) also performs no `authorize()` check. `ManagesCustomerStatementsAndMerge::openStatement()` (`L85`) accepts `$customerId` without `Gate::authorize('view', ...)` or explicit tenant check. Additionally, `18` Livewire components/concerns (including `SubscriptionPortal`, `CreateOrder`, `OrderHistory`, `Board`, `FixedAssetManager`, `WorkforceScheduler`, `AdvancedAnalyticsDashboard`, `IngredientLedger`) omit `authorize()` / `AbilityEvaluator` calls, relying on route middleware or hardcoded role strings (`IngredientLedger.php:24`, `AdvancedAnalyticsDashboard.php:76`) that break custom roles.
* **Tier-1 Remediation:**
  Replace random order code generation with `DocumentSequenceService` (gap-free per tenant/store/year), add `->where('store_id', $storeId)` + price/stock-variance tolerance flag on `OfflineOrderSyncService`, add `RequiresTenantContext` + explicit `AbilityEvaluator` authorization in `boot()`/`mount()` and action methods across all 18 Livewire components, and remove hardcoded role-name arrays.

---

#### `[P1-05]` Unbounded & Broken Exports Across `app/Exports/*`, Export Jobs, and Synchronous Services
* **Location:**
  * All 8 `app/Exports/*.php` classes, 6 export jobs (`app/Jobs/Process*ExportJob.php`, `GenerateFinancialStatementExportJob.php`), 5 synchronous `exportCsv()` services (`ReportService.php:92-104`, `ProductService.php:65`, `CategoryService.php:42-48`, `RoleService.php:43-49`, `UserService.php:58`), and [`app/Modules/Reporting/Controllers/ExportTaskController.php:53`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Reporting/Controllers/ExportTaskController.php#L53)
* **Evidence:**
  * **`SalesExport.php:34, 122-140`:** Despite implementing `FromQuery` + `WithChunkReading(1000)`, still implements `ShouldAutoSize` and styles `"A1:{$lastCol}{$lastRow}"` with `allBorders`, forcing PhpSpreadsheet to retain and calculate bounding/style objects for every cell (`100k * 11 = 1.1M` cell objects -> worker OOM).
  * **`ProductsExport.php:26, 149-186`:** Implements `streamCsv()`, yet `ProductService::exportCsv()` (`L65`) still calls synchronous `Excel::download(new ProductsExport($query))` (invoking `"A1:{$lastCol}{$lastRow}"` `allBorders` at `L149`).
  * **`CategoriesExport.php:22`, `UsersExport.php:19`, `RolesExport.php:19`:** All three implement `FromCollection` + `ShouldAutoSize` + synchronous `Excel::download(...)`.
  * **`ReportService::exportCsv()` (`L92-104`):** Synchronous `Excel::download(new SalesExport(...))` with **no `MAX_EXPORT_RANGE_DAYS` validation** (only `queueExport()` at `L113` checks `MAX_EXPORT_RANGE_DAYS`).
  * **`CustomerStatementExport.php:19, 33` & `FinancialStatementExport.php:15`:** Materialize `FromArray` + `ShouldAutoSize` in RAM.
  * **`ProcessAnalyticsExportJob.php:132-153`:** Writes to `exports/tenant-{$this->tenantId}/` (**hyphen** `-`), whereas `ExportTaskController.php:53` only guards `str_starts_with($filePath, 'exports/tenant_')` (**underscore** `_`), skipping the path-isolation check for analytics files; and all 4 `ExportTask` jobs (`ProcessInventoryLedgerExportJob`, `ProcessSalesReportExportJob`, `ProcessCustomerStatementExportJob`, `GenerateFinancialStatementExportJob`) set `ExportTaskStatus::Failed` in `catch` before retry attempt #2 can run (`$tries = 2`).
* **Tier-1 Remediation:**
  See §4.3 for the full streaming/OpenSpout zero-OOM architecture.

---

### P2 — Medium Severity (Scalability Bottlenecks, Asset Budget & CI Blind Spots)

#### `[P2-01]` `5N+1` Queries & Broken Store Scoping in Executive Dashboard + Livewire Hydration Bloat
* **Location:**
  * [`app/Modules/Reporting/Services/DashboardOperationsQueryService.php:176-244, 227, 371-382`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Reporting/Services/DashboardOperationsQueryService.php#L176-L244)
  * [`app/Modules/Reporting/Services/ReportService.php:241-298, 360-377`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Reporting/Services/ReportService.php#L241-L298)
  * `app/Livewire/` (`IngredientLedger.php:62`, `ModifierManager.php:65,70`, `Retail/ShelfLabelManager.php:109`, `BankReconciliationWorkbench.php:280-320`, `FixedAssetManager.php:164-165`, `EmployeeManager.php:384-395`)
* **Evidence:**
  * `DashboardOperationsQueryService.php:176-244` loops over every `Store` and executes **5 SQL queries per store** (sales sum, orders count, `store_targets`, daily sparkline, and `order_items`/`products` COGS — `50` branches = `250+` queries per dashboard load!). Line `227` joins `products as p` on `oi.product_id = p.id` **without `oi.tenant_id = p.tenant_id`**. Line `371-382` (`getInventoryIntelligence()`) **ignores `$storeId` completely**, computing valuation & low-stock metrics from legacy global `products.stock` rather than `product_stock_balances`.
  * `ReportService::closeEndOfDay()` (`L241-298`) and `getDetailedSalesSummary()` (`L360-377`) load full Eloquent collections (`Order::get()`, `Payment::get()`, `PaymentRefund::get()`, `Shift::get()`) into PHP memory and reduce in PHP instead of SQL `SUM()`/`COUNT()` + `GROUP BY`.
  * Livewire components hydrate unbounded lists (`Ingredient::orderBy('name')->get()`, `Product::where(...)->orderBy('id')->get()`) into component payloads.
* **Tier-1 Remediation:**
  Replace the per-store loop in `DashboardOperationsQueryService` with 3 set-based `GROUP BY store_id` queries, add `on('oi.tenant_id', '=', 'p.tenant_id')`, query `product_stock_balances` filtered by `$storeId` in `getInventoryIntelligence()`, push EOD/Sales summaries into SQL aggregates (`SUM/COUNT/GROUP BY`), and move Livewire dropdown collections into cached `#[Computed]` selectors or searchable async endpoints capped at `limit(100)`.

---

#### `[P2-02]` JS Bundle Sitting at 97.0% of 135 kB Budget, Unbudgeted Heavy Scripts & CI Gate Gaps
* **Location:**
  * [`public/build/assets/app-nquqhmwn.js`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/public/build/assets/app-nquqhmwn.js) (`131,013` bytes), `vendor-echarts-BlivLn5k.js` (`1,084,237` bytes), `vendor-gsap-RFvD2-O3.js` (`70,046` bytes), [`resources/views/layouts/app.blade.php:291`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/views/layouts/app.blade.php#L291) (`public/vendor/qz-tray.js` = `150,507` bytes), [`resources/js/app.js:1-10`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/js/app.js#L1-L10)
  * [`.github/workflows/tests.yml:151-169`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/.github/workflows/tests.yml#L151-L169) & [`phpunit.xml:1-9`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/phpunit.xml#L1-L9)
* **Evidence:**
  * Entry `app-nquqhmwn.js` is **`131,013` bytes** — only `3,987` bytes (`2.95%`) below the `135,000`-byte (`~135 kB`) architecture budget, because `@alpinejs/focus`, `swal-bridge`, `commandPalette`, `erp-motion`, `sidebar-persistence`, `dashboardCharts`, and `./echo` (`pusher-js`/`laravel-echo`) are all statically bundled into `app.js`. Yet `.github/workflows/tests.yml:151-157` allows up to **`153,600` bytes (`150 KiB`)**, letting `app.js` silently breach `135 kB` in CI!
  * `layouts/app.blade.php:291` loads `qz-tray.js` (`150,507` B) on **every authenticated admin page**, not just POS/KDS/Receipt views.
  * CI omits Playwright E2E completely, runs `--testsuite=Arch,Unit,Integrity` without `--fail-on-risky` (`phpunit.xml` omits `failOnRisky="true"`), and runs `PosFullSeeder` via `migrate:fresh --seed`.
* **Tier-1 Remediation:**
  1. Tighten CI bundle check in `.github/workflows/tests.yml:154` to `135000` bytes (`135 kB`), lazy-load `./echo` and `dashboardCharts` only where Echo/charts mount (saving ~25–35 kB from `app.js`), and move `qz-tray.js` exclusively into POS/KDS `@push('scripts')`.
  2. Enable `failOnRisky="true"` in `phpunit.xml`, add `--fail-on-risky` in `.github/workflows/tests.yml:163`, decouple `DatabaseSeeder` from `PosFullSeeder`, and add a blocking Playwright CI job.

---

## 3. Jalur B Deep Blueprint — End-to-End Business Flow, Payment Resilience & `<10s` Demo Seeder

### 3.1 Playwright E2E Coverage Audit & Tier-1 Test Matrix

**Current State (`playwright.config.ts` & `tests/e2e/accessibility.spec.ts`):**
`tests/e2e/` contains **only 1 spec file** (`accessibility.spec.ts`, 655 lines), testing login WCAG rules, theme switching, skip link, modal Escape key, CLS/geometry, and read-only route crawls across `/dashboard`, `/transaction`, `/products`, `/customers`, `/finance`, `/analytics`, `/reports/sales`, `/settings`. Notice:
* **Zero** assertions create an order, deduct stock, trigger Midtrans Snap/webhooks, refund items, open/close shifts, switch stores/tenants, or drop network connectivity.
* `playwright.config.ts:44-49` boots `php artisan serve --host=127.0.0.1 --port=8000`, and `.github/workflows/tests.yml` does not install Playwright or run `npx playwright test`.

**Tier-1 Playwright E2E Architecture (`tests/e2e/`):**
Use Playwright storage-state projects (`auth-owner.json`, `auth-cashier.json`, `auth-tenant-b.json` via Playwright setup project) seeded deterministically by the new `<10s` `Tier1DemoSeeder` (never touching `yovel_pos_test` — run Playwright against a dedicated `.env.e2e` / `yovel_pos_e2e` schema or isolated SQLite/MariaDB schema):

| Spec File (`tests/e2e/journeys/`) | Critical Business Journey & Fault Injections | Blocking Verification Assertions |
| :--- | :--- | :--- |
| `01-auth-tenancy-branch.spec.ts` | Login -> Store Switcher (`Main` -> `Branch 2`) -> Direct URL IDOR probe (Tenant A tries Tenant B `/orders/{id}`, `/finance` period, Livewire state replay) -> Custom role denied on `/subscription`. | Store switcher updates active `Product`/`StockBalance` grid and shift indicator; cross-tenant probes return `403`/`404` with zero PII leakage. |
| `02-pos-shift-cart-checkout-cash.spec.ts` | Open Shift (`opening_balance` Rp 500,000) -> Select customer + barcode F2 entry + variable modifiers (`Ice Level`, `Extra Shot`) + percent discount + Cash tender (Rp 100,000) -> Change & receipt modal (`ESC` + Print button). | Cart totals match server rounding (`100`), receipt modal displays `order_code`, `payment_status=paid`, product `product_stock_balances` & `ingredient_stock_balances` decrement atomically; Livewire tamper test of `$cart.0.custom_price=1` fails/is rejected (`#[Locked]`). |
| `03-midtrans-qris-resilience.spec.ts` | Intercept Snap popup (`page.route('**/snap.js*')`) -> Checkout QRIS creating `Pending` order + `PaymentAttempt` (`*-ATT1`) -> (a) Send forged `signature_key` webhook -> expect `403`; (b) Send valid SHA-512 `settlement` webhook -> UI updates to `Paid`; (c) Replay identical webhook 3x concurrently (`Promise.all`) -> idempotent; (d) Retry attempt `-ATT2` after `-ATT1` expires -> verify `ReconcilePendingMidtransOrders` does not prematurely void `-ATT2`. | Zero double payments, zero duplicate loyalty grants or `FinancialEventOutbox` rows, `payment_status=paid`, `fulfillment_status=in_preparation`, invalid amount/null `gross_amount` webhook rejected (`400`/`422`). |
| `04-void-refund-approval-gate.spec.ts` | Cashier initiates Cash Refund on paid order (`Partial Return`, Qty `1 of 2` with `Restock` disposition) -> Requires Manager/Supervisor approval PIN/gate -> Completes refund + cash drawer out -> Verify GL outbox & stock restore. | Attempting refund allocation `> returned item subtotal` rejected; stock balance increments by exact `+1`; `PaymentRefund` + `CashDrawerMovement` match; `FinancialEventOutbox` contains valid `sales.return.refunded` row inside same commit. |
| `05-shift-close-reconciliation.spec.ts` | Perform cash-in (`+100k`), cash-out (`-50k`), cash sales (`+250k`), cash refund (`-50k`) -> Close Shift with physical count variance (`-10k`) -> Verify EOD settlement summary. | `expected_cash` equals `opening + cash_in + cash_payments - cash_out - cash_refunds`; `cash_difference` persisted accurately; closing blocked if `UnknownTimeout` `PaymentAttempt` remains unresolved. |
| `06-offline-pos-resilience.spec.ts` | Toggle `context.setOffline(true)` on `/transaction` -> Complete Cash POS sale -> Verify IndexedDB `offline_orders` queue badge = `1` -> Attempt Logout -> Blocked by synchronous guard -> Toggle `context.setOffline(false)` -> Auto-sync via `/pos/offline/sync` + duplicate UUID replay. | Zero uncaught `ReferenceError` in console; IndexedDB contains 1 pending item pre-sync and 0 post-sync; exactly 1 `Order` created server-side after duplicate replay. |

---

### 3.2 Midtrans / QRIS Hardening Checklist (Pre-E2E Code Fixes Required)
1. **Unify Webhook Ingress:** Remove or proxy `POST /api/payment/notification` (`PaymentNotificationController`) into `MidtransNotificationController` so ALL Midtrans notifications inherit `throttle:120,1`, `SubscriptionPaymentSettlementInterface` (`SUB-*`), `PaymentInboxDispatcher` deduplication, and fail-closed `$serverKey === ''` checks.
2. **Enforce Exact Amount Verification:** Reject `$grossAmount === null` in `OnlinePaymentAndWebhookHandler.php:59, 425` and tighten over-payment tolerance unless explicitly tagged as gateway convenience fee.
3. **Include Service Charge & Tax-Inclusive Normalization in `buildSnapParams()`:** Add `['id' => 'SERVICE-CHARGE', ...]` and ensure Snap `gross_amount === sum(item_details.price * qty)`.
4. **Attempt-Aware Reconciliation:** Update `ReconcilePendingMidtransOrders` to lock per `PaymentAttempt`, query `Transaction::status($attempt->provider_order_id)`, and support per-tenant Midtrans credentials (`TypedConfigService` / encrypted `Integration` config).

---

### 3.3 Forensic Root-Cause Analysis of `PosFullSeeder` (10–15 min) & `<10s` Tier-1 Demo Seeder Blueprint

#### Why `PosFullSeeder` Takes 10–15 Minutes (and Breaks Verification Contracts)
1. **Unconditional Invocation in `DatabaseSeeder`:** [`database/seeders/DatabaseSeeder.php:104`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/database/seeders/DatabaseSeeder.php#L104) calls `$this->call(PosFullSeeder::class)` on every `php artisan migrate:fresh --seed`, including CI ([`.github/workflows/tests.yml:160`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/.github/workflows/tests.yml#L160)) and local bootstrap.
2. **Brute-Force 365-Day Volume (`OrderSeeder.php`):** Generates ~50,000 orders, ~150,000 `order_items`, ~55,000 `payments`, and ~150,000 `stock_movements` (`memory_limit = 2G`, `set_time_limit(0)`), running `Carbon::parse(...)` inside the inner 50k × shift (~400k+ calls, `L190, L205-206`).
3. **Excessive Metadata Reflection (`StampsTenantOnBulkInsert::withTenant`):** Called on every 500-row insert chunk (`OrderSeeder.php:554-571`, `IngredientStockMovementSeeder.php:92, 129`), hitting `Schema::hasColumn($table, 'store_id')` hundreds of times without caching.
4. **Trigger Drop + 366 Daily Join Queries (`IngredientStockMovementSeeder.php`):** Drops MySQL triggers (`L42-44`), executes `366` sequential `order_items JOIN orders` queries (`L63-72`), runs `104` restock queries (`L100-108`), and recreates DDL triggers (`L144-157`).
5. **Broken `O(N^2)` Loyalty Scan (`LoyaltyLedgerSeeder.php`):** Loads ALL paid customer orders into PHP RAM (`L40-45`), drops/recreates 5 triggers (`L28-32, L205-235`), runs a backwards linear scan over `$ledgerRows` per order (`L90-97`), **and wipes `$ledgerRows = []` every 1,000 rows (`L157`) — resetting `prev_hash` to `null` and corrupting the SHA-256 hash chain!**
6. **Domain Blind Spots Despite 15-Minute Runtime:** Single-branch only (`Main Store`), never seeds `product_stock_balances` / `ingredient_stock_balances` (leaving new balance tables empty until `ensureBalanceRowExists` clones them!), and seeds **0 Workforce payroll/attendance/commission records**. Meanwhile, [`App\Support\Dataset\DatasetOrchestrator`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Support/Dataset/DatasetOrchestrator.php#L34-L100) already exists for 12-month heavy marketing datasets (`pos:dataset:generate`) — leaving `DatabaseSeeder` with no fast, realistic baseline seeder.

#### Deterministic `<10s` `Tier1DemoSeeder` Blueprint (`Database\Seeders\Tier1DemoSeeder`)
* **Target SLA:** **< 6 seconds** total on MariaDB 10.11 / PHP 8.3 (with Query Log disabled and deterministic MT19937 seed `mt_srand(20261126)`).
* **Database Safety Guard:** Explicitly abort if active DB name ends with `_test` (e.g. `yovel_pos_test`) unless invoked explicitly inside a dedicated test helper, guaranteeing zero contamination of PHPUnit's `yovel_pos_test`. Dedicated Playwright E2E uses `APP_ENV=e2e` / `yovel_pos_e2e`.
* **Schema & Multi-Tenancy Scope (2 Tenants × 2 Branches = High Realism):**
  * **Tenant A ("Kopi Senja Nusantara" — Flagship Multi-Branch F&B + Retail Hybrid):** `Plan: Enterprise (active)` | **Branch 1 (`STR-JKT-01`, Flagship)** & **Branch 2 (`STR-BDG-02`, Bandung)** | 3 POS Devices + 1 EDC Terminal + 2 `CashBankAccount`s.
  * **Users (1 Shared Pre-Computed Hash = `1` `Hash::make` call instead of `34` -> saves ~2.5s!):** Owner (`admin@pos.test`), Manager (`manager@pos.test`), Kasir (`kasir@pos.test` + `kasir2@pos.test`), Supervisor (`supervisor@pos.test`, PIN `135790` for void/refund/discount PIN tests), Finance (`finance@pos.test`), Cook/KDS (`cook@pos.test`), Self-Order System (`selforder@system.local`), plus 6 `Employee` profiles (`Workforce` linked).
  * **Tenant B ("Warung Mba Rina" — Isolation Canary Tenant):** Minimal 1 branch, 2 products, 1 open accounting period — specifically for automated Playwright cross-tenant IDOR & isolation negative assertions.
* **Catalog, Modifiers & Authoritative Stock Balances:**
  * 24 realistic items (16 F&B items with BOM `product_ingredients`, 3 ModifierGroups (`Size`, `Sugar`, `Extra`), 6 retail SKU items with barcodes & variants, 1 `is_open_price = true` item + 1 inactive item).
  * Populate **both** `product_stock_balances` and `ingredient_stock_balances` directly per branch (`STR-JKT-01` & `STR-BDG-02` having *distinct* quantities and 2 low-stock items to feed Executive Dashboard alerts immediately).
* **30-Day Realistic Transactional Slice (~900 Orders, 1,800 Items, 0 Triggers Dropped):**
  * 30 days × ~30 orders/day = ~900 orders (Cash, QRIS Midtrans settled + 2 `Pending`/`PaymentAttempt` rows for reconciliation E2E, Split Cash+Card, Void, and Partial Return).
  * 1 **currently open Shift** on `kasir@pos.test` (`STR-JKT-01`, opening balance Rp 500,000) + 60 closed shifts across 30 days.
  * Append-only `loyalty_ledger` seeded with a per-customer PHP associative map `$lastHashByCustomer[$cid]` (O(1) lookup, chunked safely, **no trigger drops**, SHA-256 chain 100% intact!).
  * 2 `AccountingPeriod` rows (1 closed prior month, 1 open current month) + 1 finalized `PayrollBatch` (`Workforce`) with 6 `PayrollRecord` rows + balanced GL `JournalEntry` headers.
* **`DatabaseSeeder.php` Switch:** Default `DatabaseSeeder` invokes `Tier1DemoSeeder` (`< 10s`); move full stress data strictly behind `php artisan db:seed --class=PosFullSeeder` or `php artisan pos:dataset:generate`.

---

## 4. Jalur C Deep Blueprint — Quality Ratchet & Export OOM Hardening

### 4.1 Forensic Root Cause of the 1 `risky` Test in `--testsuite=Integrity`
* **Exact Failing Test:** [`Tests\Feature\Navigation\NavigationGovernanceTest::test_g1_single_canonical_home_every_non_shortcut_page_route_appears_exactly_once`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/tests/Feature/Navigation/NavigationGovernanceTest.php#L63-L93)
* **Verification Proof:**
  * `vendor/phpunit/phpunit/src/Framework/TestStatus/TestStatus.php` maps integer `5` -> `TestStatus::risky()`.
  * `.phpunit.result.cache` records `5 => Tests\Feature\Navigation\NavigationGovernanceTest::test_g1_single_canonical_home_every_non_shortcut_page_route_appears_exactly_once`.
  * Bootstrapping `NavigationRegistry` reveals `65` pages across `config/navigation/domains/*.php` with `65` unique routes (`maxCount = 1`).
* **Root Cause Mechanism:**
  In `NavigationGovernanceTest.php:75-92`:
  ```php
  foreach ($routeCounts as $route => $count) {
      if ($count <= 1) {
          continue;
      }
      ...
      $this->assertTrue($permitted, ...);
  }
  ```
  Because every route currently appears once (`maxCount = 1`), the loop executes `continue;` on all 65 iterations and **terminates with 0 assertions**. PHPUnit 12 marks tests without assertions as **Risky**. While its sibling unit test in [`tests/Unit/Navigation/NavigationRegistryTest.php:129`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/tests/Unit/Navigation/NavigationRegistryTest.php#L129) received `$this->assertNotEmpty($counts);` in Wave 9, `NavigationGovernanceTest` was missed, and neither `phpunit.xml` nor `.github/workflows/tests.yml:163` enables `failOnRisky`.
* **Exact 2-Part Remediation:**
  1. Add `$this->assertNotEmpty($routeCounts, 'NavigationRegistry must expose canonical page routes.');` at the top/end of `NavigationGovernanceTest::test_g1_single_canonical_home_every_non_shortcut_page_route_appears_exactly_once()`. (Also audit and purge stale risky tests from `Tests\Feature\DebugCheckoutTest` / `ProvisioningRealConcurrencyTest` tracked in `.phpunit.result.cache`).
  2. Set `failOnRisky="true"` in `phpunit.xml` and add `--fail-on-risky` to CI step `.github/workflows/tests.yml:163`.

---

### 4.2 Ratchet Offender Breakdown & Aggressive Wave 10 Target Plan

#### A. `TenantScopeBypassTest` (Current: `990` Total / `989` Unannotated | Ratchet Ceiling: `<= 990` — **0 Headroom**)
* **Pattern Breakdown (measured directly across `app/`):** `withoutGlobalScopes()` = `658` | `withoutTenantScope()` = `313` | `runWithoutTenant()` = `10` | `withoutGlobalScope()` = `9`.
* **Top Offending Directories & Files:**
  * By Directory: `app/Modules/Finance` (`192`), `app/Modules/Sales` (`143`), `app/Modules/Organization` (`93`), `app/Modules/Inventory` (`74`), `app/Modules/Pricing` (`57`), `app/Console/Commands` (`54`), `app/Livewire/HR` (`47`), `app/Modules/Catalog` (`45`), `app/Modules/Customers` (`39`), `app/Livewire/Finance` (`38`), `app/Livewire/Customers` (`36`), `app/Modules/Subscription` (`29`), other (`143`).
  * Top 6 Single Files (`132` bypasses = `13.3%` of repo): `ReturnRefundService.php` (`30`), `CashBankTransactionService.php` (`28`), `UpdatePosOperationalDatasetCommand.php` (`26`), `StockOpnameService.php` (`21` — calls redundant chained `withoutTenantScope()->withoutGlobalScopes()` at `L73-74`!), `AccountsReceivableService.php` (`19`), `AccountingPeriodService.php` (`17`).
* **Root Causes of Inflated Count:**
  1. **Redundant Bypass + Manual Filter Anti-Pattern (~45% of total):** Services inside active HTTP/Livewire requests already inside `TenantContext` do `Model::withoutGlobalScopes()->where('tenant_id', $tenantId)` solely to bypass `StoreScope` — when `Model::allStores()` (explicit tenant-safe helper) or `TenantContext::runAs()` eliminates the bypass cleanly without weakening tenant isolation.
  2. **Chained Redundancy & IDOR Risk:** Calls like `withoutTenantScope()->withoutGlobalScopes()` (`StockOpnameService.php:73-74`) double-count and mask missing tenant clauses (as in `AccountingPeriodService.php:138, 420`).
  3. **989 / 990 Unannotated:** Virtually none carry `// @tenant-bypass: <category>` justification (`MAX_UNANNOTATED_BYPASSES = 990` has become a rubber stamp).
* **Wave 10 Phased Reduction Targets:**
  * **Wave 10A Target:** **`MAX_ALLOWED_BYPASSES = 680`**, **`MAX_UNANNOTATED_BYPASSES = 350`** (Eliminate Livewire/Console/Redundant Store-only `withoutGlobalScopes` via `allStores()`, fix double-calls in `StockOpnameService`, `AccountingPeriodService`, `ReturnRefundService`, `FixedAssetManager`, `PayrollBatchManager`, and annotate intentional system webhooks/cron jobs with `// @tenant-bypass: <reason>`).
  * **Wave 10C Target:** **`MAX_ALLOWED_BYPASSES = 420`**, **`MAX_UNANNOTATED_BYPASSES = 0`** (Every remaining bypass in webhooks/CLI/partitioning must be explicitly annotated or fail CI).

#### B. `ModuleBoundaryTest` (Current: `751` Cross-Module Imports | Ratchet Ceiling: `<= 760` — **9 Headroom**)
* **Measured Breakdown across `app/Modules/`:**
  * By Source Module: `Sales` (`199`), `Finance` (`120`), `Pricing` (`75`), `Purchasing` (`72`), `Workforce` (`65`), `Inventory` (`63`), `Reporting` (`49`), `Catalog` (`39`), `Manufacturing` (`22`), `Organization` (`14`), `Subscription` (`14`), `Customers` (`10`), `Integration` (`9`).
  * **By Target Layer (`614 / 751 = 81.8%` are raw Eloquent `Models\`!):** `Models` (`614`), `Services` (`109`), `Enums` (`12`), `Contracts` (`10`), `DataTransferObjects` (`5`), `Exceptions` (`1`).
  * **Top Cross-Module Pairs:** `Sales -> Organization` (`91`), `Finance -> Organization` (`89`), `Purchasing -> Organization` (`62`), `Workforce -> Organization` (`54`), `Pricing -> Organization` (`37`), `Inventory -> Organization` (`33`), `Sales -> Catalog` (`33`), `Catalog -> Organization` (`29`), `Sales -> Pricing` (`24`), `Inventory -> Catalog` (`23`), `Sales -> Customers` (`23`), `Reporting -> Organization` (`18`).
* **Critical Loophole Discovered:** **31 thin-wrapper controllers** in `app/Http/Controllers/` extend `\App\Modules\*\Controllers\*`, completely evading `ModuleBoundaryTest` (`ADR-114` only scans `app/Models/` & `app/Services/`).
* **Wave 10 Phased Reduction Targets:**
  * **Why Organization Exploded (`373` imports = `49.7%` of all violations):** Almost every domain service imports `Organization\Models\{User, Store, Tenant, AuditLog, Approval}` directly instead of using Tier-0/Platform actor interfaces (`Authenticatable`/Actor DTO, `Platform\Audit\ImmutableAuditWriter`, `Contracts\OrganizationContextInterface`).
  * **Wave 10A Target:** **`MAX_CROSS_MODULE_IMPORTS = 560`** + **Controller Wrapper Ratchet = `0`** (delete the 31 `app/Http/Controllers/` thin wrappers; migrate modules from `Organization\Services\AuditTrailService` & `Organization\Models\Approval` to `App\Platform\Audit\ImmutableAuditWriter` + `MakerCheckerGuard`; introduce `UserActorInterface` / `StoreLookupContract` in `Organization\Contracts`).
  * **Wave 10C Target:** **`MAX_CROSS_MODULE_IMPORTS = 420`** (Replace direct `Catalog\Models\{Product, Category}` & `Customers\Models\Customer` model imports in `Sales`, `Inventory`, `Purchasing` with read Contracts/DTOs).

---

### 4.3 Tier-1 Zero-OOM Export Architecture (100k+ Rows Guaranteed Safe)

#### Audit Matrix of All Existing Exports & Jobs
| Export / Job / Service | Current Engine & Pattern | Memory / OOM & Correctness Flaw | Tier-1 Target Fix |
| :--- | :--- | :--- | :--- |
| `InventoryLedgerExport` + `ProcessInventoryLedgerExportJob` | `FromCollection` (xlsx default) + custom `streamCsv()` | Defaults to `'xlsx'` -> loads full collections into RAM; `streamCsv()` misses `::allStores()` (0 rows on Owner all-stores!), reads wrong `$im->ingredient->code`, job marks `Failed` before retry #2. | Fix `allStores()` & `ingredient_code`; route `.xlsx` through OpenSpout streaming; remove premature `Failed` write; default async format to `.csv` or OpenSpout `.xlsx`. |
| `SalesExport` + `ProcessSalesReportExportJob` + `ReportService::exportCsv()` | `FromQuery` + `WithChunkReading(1000)` + `ShouldAutoSize` + `WithStyles` (`allBorders`) | `ShouldAutoSize` + `"A1:{$lastCol}{$lastRow}"` borders materialize full PhpSpreadsheet DOM in RAM; sync `ReportService::exportCsv()` has **no `MAX_EXPORT_RANGE_DAYS` check**. | Remove `ShouldAutoSize` & full-sheet cell loops; apply streaming CSV or `OpenSpout`; block sync `Excel::download` over 5,000 rows (`422` -> redirect to async `ExportTask`). |
| `ProductsExport` + `ProductService::exportCsv()` | `FromQuery` + `streamCsv()` + `WithStyles` (`allBorders`) | `ProductService::exportCsv()` calls sync `Excel::download()` instead of `streamCsv()`, triggering PhpSpreadsheet cell style loops. | Replace `ProductService::exportCsv()` with `response()->streamDownload()` executing `ProductsExport::streamCsv('php://output')` via `chunkById(1000)`. |
| `CategoriesExport`, `UsersExport`, `RolesExport` + `CategoryService`, `UserService`, `RoleService` | `FromCollection` + `ShouldAutoSize` + sync `Excel::download()` | Hydrates entire collections into memory synchronously on web workers. | Enforce hard cap (`<= 2,000` rows sync via CSV `streamDownload`) or dispatch async `ExportTask`. |
| `CustomerStatementExport` + `ProcessCustomerStatementExportJob` | `FromArray` + `ShouldAutoSize` in `__construct` | Builds complete `$rows` PHP array in RAM via `generateCustomerStatement()`; job marks `Failed` before retry. | Stream directly from chunked AR ledger query with running BCMath balance accumulator (`streamCsv`/`OpenSpout`). |
| `FinancialStatementExport` + `GenerateFinancialStatementExportJob` | `FromArray` + `ShouldAutoSize` | Allocates PhpSpreadsheet sheet in memory; fails retries prematurely. | Convert to OpenSpout streaming writer for XLSX or pure streaming CSV/PDF. |
| `ProcessAnalyticsExportJob` | `php://temp` + `stream_get_contents()` | Writes to `exports/tenant-{$id}/` (**hyphen**) skipping `ExportTaskController.php:53`'s `exports/tenant_` (**underscore**) prefix guard; duplicates `csvContent` string in RAM. | Standardize path to `exports/tenant_{$this->tenantId}/`; extend `TenantAwareJob`; stream via `fopen($fullPath, 'w')`. |
| `ProcessMonthlyExportJob` | `chunkById(1000)` -> `fputcsv()` | Safe streaming CSV, but lacks `ExportTask` tracking/download links and `TenantAwareJob` base class. | Register output into `ExportTask` + inherit `TenantAwareJob`. |

#### Tier-1 Unified Export Architecture (`App\Platform\Exports\StreamingExportDriver`)
1. **Eliminate PhpSpreadsheet DOM from All Large Datasets:** Install `openspout/openspout` (`v4`) cleanly or write a lightweight `Platform\Exports\StreamingSpreadsheetWriter` supporting `.csv` (`fputcsv` with formula-injection sanitization + UTF-8 BOM option for Excel ID) and `.xlsx` (OpenSpout row-by-row streaming at **< 20 MB constant RAM** at 100k–1M rows, zero `ShouldAutoSize`, header-row style only).
2. **Hard Sync vs. Async Guardrail (`MAX_SYNC_EXPORT_ROWS = 2000`):** Every controller/service export endpoint performs an indexed `COUNT(*)` first; `> 2,000` rows automatically enqueues an `ExportTask` (`HTTP 202` + Toast notification with live polling/download link), making web-worker PHP timeouts and OOM impossible.
3. **Mandatory Job & Storage Contracts:**
   * All export jobs extend `App\Platform\Tenancy\TenantAwareJob` and implement `ShouldBeUnique` on `(tenant_id, task_id)`.
   * Never mark `ExportTaskStatus::Failed` inside `catch` when rethrowing for retry; mark `Processing -> Failed` **only** inside `failed(Throwable $e)`.
   * Enforce canonical path `exports/tenant_{$tenantId}/store_{$storeScope}/...` and reject non-matching prefixes fail-closed inside `ExportTaskController::authorizeAccess()`.
   * Explicitly emit `chunkById()` ordered by indexed primary key (`orders.id`, `stock_movements.id`) rather than `ORDER BY created_at DESC` (`OFFSET` pagination inside Maatwebsite `FromQuery` degrades `O(N^2)` at 100k rows!).

---

## 5. Wave 10 Phased Execution Plan (10A, 10B, 10C)

### Phase 10A — Security Hotfixes, P0 Financial/Tenancy Integrity & Integrity Suite Green (`risky: 0`)
* **Objective:** Close every actively exploitable `P0` flaw and push `Integrity` & `Arch` suites to zero risky tests and lowered initial ratchets.
* **Dependency Order:**
  1. **Billing & Livewire Cart Security Hotfix:** Remove free self-serve `confirmInstantUpgrade()` from [`SubscriptionPortal.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Subscription/SubscriptionPortal.php) & [`subscription-portal.blade.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/views/livewire/subscription/subscription-portal.blade.php); add `#[Locked]` to `$cart`, `$itemDiscounts`, `$appliedManualDiscount`, `$manualDiscountApprovalId` in `ManagesPos*` traits; enforce `is_open_price`, `pos.price.open_entry`, and approval validation in [`OrderLinePreparationService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Domain/Services/OrderLinePreparationService.php); lock `tenant_subscriptions` & `provisioning_requests` inside [`SubscriptionBillingOrchestrator.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Subscription/Application/Services/SubscriptionBillingOrchestrator.php).
  2. **Tenancy, IDOR & Async Worker Fixes:** Scope [`AccountingPeriodService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Services/AccountingPeriodService.php) lookups to `$expectedTenantId`; align [`RequiresTenantContext.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Concerns/RequiresTenantContext.php) suspension grace rules with `SetTenantContext`; add `RequiresTenantContext` + `authorize()` guards to [`ReturnCreditNoteManager.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Sales/ReturnCreditNoteManager.php), [`MarginSimulationModal.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Pricing/MarginSimulationModal.php), [`ManagesCustomerStatementsAndMerge.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Customers/Concerns/ManagesCustomerStatementsAndMerge.php), and [`IngredientLedger.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Livewire/Inventory/IngredientLedger.php); migrate [`PostPayrollToGeneralLedgerJob.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Workforce/Jobs/PostPayrollToGeneralLedgerJob.php), [`GeneratePayslipPdfJob.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Workforce/Jobs/GeneratePayslipPdfJob.php), and [`RunMonthlyDepreciationJob.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Finance/Jobs/RunMonthlyDepreciationJob.php) to `TenantAwareJob` + real DomPDF slip rendering.
  3. **Webhook & Return/Inventory Invariants:** Fail closed on empty OFD `signing_secret` in [`WebhookController.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Http/Controllers/Api/WebhookController.php); consolidate [`PaymentNotificationController.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Integration/Controllers/PaymentNotificationController.php) into [`MidtransNotificationController.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Controllers/MidtransNotificationController.php); fix [`OnlinePaymentAndWebhookHandler.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Application/Handlers/OnlinePaymentAndWebhookHandler.php) (`grossAmount === null`, `settlePaymentAttempt` statuses, `buildSnapParams` service charge) & [`ReconcilePendingMidtransOrders.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Console/Commands/ReconcilePendingMidtransOrders.php); fix `InventoryLedgerService::ensureBalanceRowExists` (seed `0`, not cross-branch clone) & `recordBatchMovements()`; fix [`ReturnRefundService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/ReturnRefundService.php) (`Shift` store/tenant lock, BCMath allocation sum == returned total, mandatory gift card validation, non-swallowed outbox dispatch).
  4. **Jalur C Quick Wins (`risky: 0` & Ratchet Step 1):** Fix [`NavigationGovernanceTest.php:63-93`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/tests/Feature/Navigation/NavigationGovernanceTest.php#L63-L93) assertion + `failOnRisky="true"` in `phpunit.xml` and `.github/workflows/tests.yml`; delete the 31 legacy thin wrappers in `app/Http/Controllers/`; lower ratchets to **`TenantScopeBypassTest <= 680` (`unannotated <= 350`)** and **`ModuleBoundaryTest <= 560`**.
* **Phase 10A DoD Verification Commands:**
  * `./vendor/bin/pint --test` -> `0` violations
  * `./vendor/bin/phpstan analyse --error-format=raw --memory-limit=1G` -> `0` errors
  * `./vendor/bin/phpunit --testsuite=Arch,Unit,Integrity --fail-on-risky` -> `0` failures, `0` risky tests
  * `php artisan test --parallel --exclude-group=chaos` (including new P0 exploit regression tests for billing bypass, cart price tamper, accounting period IDOR, and async payroll posting) -> `100%` green.

---

### Phase 10B — Jalur B Delivery (Offline POS Fix, `<10s` Demo Seeder & Full Playwright E2E Matrix)
* **Objective:** Establish production offline POS resilience, decouple `DatabaseSeeder` from the 15-minute `PosFullSeeder`, and automate full transactional Playwright E2E journeys in CI.
* **Dependency Order:**
  1. **Offline POS & Sync Hardening:** Fix `userId` `ReferenceError` and synchronous logout guard in [`pos-offline-queue.js`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/public/js/pos-offline-queue.js); wire offline checkout capture + `/pos/offline/sync` replay inside `create-order.blade.php` / `ManagesPosPaymentAndSplitBill.php`; enforce `store_id` on `offline_shift_id` lookup and price-lock offline cash settlement in [`OfflineOrderSyncService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Services/OfflineOrderSyncService.php); migrate [`CheckoutOrderHandler.php:370`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Sales/Application/Handlers/CheckoutOrderHandler.php#L370) to `DocumentSequenceService`.
  2. **Build & Verify `Tier1DemoSeeder` (`< 10s`):** Create `Database\Seeders\Tier1DemoSeeder` per §3.3 (multi-branch balances, 30-day orders, active shift, valid loyalty hash chain, Finance periods, Workforce payroll, DB guard preventing accidental writes to `yovel_pos_test`); swap [`DatabaseSeeder.php:104`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/database/seeders/DatabaseSeeder.php#L104) to call `Tier1DemoSeeder` instead of `PosFullSeeder`; fix `LoyaltyLedgerSeeder`'s `prev_hash` wiped-chunk bug if `PosFullSeeder` remains manually runnable.
  3. **Implement Playwright E2E Matrix:** Author specs `01` through `06` under `tests/e2e/journeys/` against isolated `yovel_pos_e2e` database; add a dedicated `e2e-playwright` job to [`.github/workflows/tests.yml`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/.github/workflows/tests.yml).
* **Phase 10B DoD Verification Commands:**
  * `time php artisan migrate:fresh --seed` (on isolated `yovel_pos_demo`, never `yovel_pos_test`) -> completes in **`< 10.0s`** with valid stock balances & intact loyalty hash chain.
  * `npx playwright test --workers=1` -> `100%` pass across all 6 business journey specs + `accessibility.spec.ts` (Desktop Light, Desktop Dark, Tablet POS viewports) with `0` console errors.
  * `./vendor/bin/phpunit --testsuite=Arch,Unit,Integrity --fail-on-risky` -> `0` failures, `0` risky.

---

### Phase 10C — Jalur C Deep Hardening (Zero-OOM Exports, Set-Based BI Queries, Asset Budget & Final Ratchets)
* **Objective:** Guarantee 100k-row exports under `< 50 MB` RAM, fix N+1 dashboard queries, enforce the `< 135 kB` JS budget cleanly, and push architectural ratchets down to long-term sustainable ceilings.
* **Dependency Order:**
  1. **Zero-OOM Export Engine:** Fix `InventoryLedgerExport::streamCsv()` (`allStores()` + `ingredient_code`) and `ProcessInventoryLedgerExportJob` / `ProcessSalesReportExportJob` / `ProcessCustomerStatementExportJob` / `GenerateFinancialStatementExportJob` / `ProcessAnalyticsExportJob` retry state + path naming + `TenantAwareJob`. Convert all 8 `app/Exports/*` classes to `Platform\Exports\StreamingExportDriver` (streaming CSV + OpenSpout `.xlsx`, cursor/indexed `chunkById()`, zero `ShouldAutoSize`, zero full-sheet `allBorders`), enforce `MAX_SYNC_EXPORT_ROWS = 2000` in [`ReportService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Reporting/Services/ReportService.php), [`ProductService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Catalog/Services/ProductService.php), [`CategoryService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Catalog/Services/CategoryService.php), [`UserService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Organization/Services/UserService.php), and [`RoleService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Organization/Services/RoleService.php).
  2. **Dashboard N+1 & Asset Hygiene:** Refactor [`DashboardOperationsQueryService.php`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/app/Modules/Reporting/Services/DashboardOperationsQueryService.php) (`5N+1` -> 3 set-based queries, `oi.tenant_id = p.tenant_id` join, store-scoped `product_stock_balances` intelligence) and SQL aggregates in `ReportService`; dynamic-import `./echo` & chart proxies in [`resources/js/app.js`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/js/app.js), move `qz-tray.js` out of global [`layouts/app.blade.php:291`](file:///home/ilhamspryd/Projects/apeironSystem/point-of-Sales/resources/views/layouts/app.blade.php#L291), and tighten `.github/workflows/tests.yml:154` budget threshold to `135000` bytes (`135 kB`).
  3. **Final Quality Ratchet Reduction:** Decouple `Organization\Models\*` & `Catalog\Models\*` cross-module imports into Contracts/DTOs (`Organization\Contracts\*`), eliminate all unannotated bypasses, and lock **`TenantScopeBypassTest <= 420` (`MAX_UNANNOTATED_BYPASSES = 0`)** and **`ModuleBoundaryTest <= 420`**.
* **Phase 10C DoD Verification Commands:**
  * Memory benchmark test seeding 100k synthetic ledger/sales rows in isolated test: streaming CSV/XLSX completes with `memory_get_peak_usage(true) < 64 MB`.
  * `npm run build && test $(stat -c%s public/build/assets/app-*.js) -le 135000` -> Exit `0` (strict `< 135 kB` headroom restored).
  * `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --error-format=raw --memory-limit=1G && ./vendor/bin/phpunit --testsuite=Arch,Unit,Integrity --fail-on-risky && php artisan test --parallel --exclude-group=chaos` -> `100%` Green, `0` risky, `0` errors.
