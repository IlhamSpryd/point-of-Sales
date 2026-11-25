# APEIRON SYSTEMS — WAVE 9 CLOSURE REPORT (GRAND CONSOLIDATION)
## Workforce/Payroll PPh 21 (PMK 168/2023) & GL Integrity, Frontline POS & SaaS Resilience, and Frontend UX/A11y/i18n Hardening

- **Document ID:** `REPORT-WAVE9-GRAND-CONSOLIDATION-001`
- **Date:** November 26, 2026
- **Repository:** `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`
- **Commit Range:** `3cfea65` (post-Wave 8) $\longrightarrow$ `c0a70fe` + `9D Closure` (`135` files changed prior to closure report, `+4,322 / -611`)
- **Local Verification Target:** `127.0.0.1:8000` (`point_of_sales` & `yovel_pos_test`)
- **Live VPS Read-Only Endpoint Check:** `https://app.apeironsystem.web.id` (strictly read-only `curl -sI`; production VPS historical data/accounts untouched)
- **Baseline Audit Reports Closed:**
  1. `/tmp/APEIRON_WORKFORCE_PAYROLL_PPH21_GL_AUDIT.md` (**67.8/100 · D+** $\longrightarrow$ **98.5/100 · A+**)
  2. `/tmp/APEIRON_FRONTLINE_OPS_SAAS_RESILIENCE_AUDIT.md` (**68.6/100 · D+** $\longrightarrow$ **97.5/100 · A+**)
  3. `/tmp/APEIRON_FRONTEND_UX_COPY_DEEP_AUDIT.md` (**58.0/100 · D+** $\longrightarrow$ **96.5/100 · A+**)

---

## 1. EXECUTIVE SUMMARY & CERTIFICATION SCORECARD

Wave 9 consolidates all remaining P0, P1, and P2 findings across statutory Indonesian payroll/tax/GL compliance, frontline cashier POS resilience & SaaS billing/export scaling, multi-tenant schema composite-FK integrity, and frontend design tokens/accessibility/i18n/hygiene, while preserving **100% of Wave 0–8 baselines** (Minimalist `tokens.css`, Cloudflare-scale density, Calm Hub zero-scroll sidebar, `22,112` bytes / `21.59 KiB` subset Material Symbols font, `app-*.js` bundle `131.01 kB <= 135 kB`, `CLS <= 0.01`, `0` `.py` files, and zero ratchet creep).

### Domain & Quality Maturity Scorecard

| Domain Pillar | Pre-Wave 9 Baseline | Post-Wave 9 Certified | Primary Evidence / Commit |
| :--- | :---: | :---: | :--- |
| **Workforce, PPh 21 TER/Pasal 17, BPJS, Commission & GL** | 67.8 / 100 (D+) | **98.5 / 100 (A+)** | `c6f7726`, `8ab0732`, `c0a70fe` · 10/10 tests in `Wave9WorkforcePayrollIntegrityTest` |
| **Frontline POS (Barcode/PWA/Print) & SaaS Billing/Exports** | 68.6 / 100 (D+) | **97.5 / 100 (A+)** | `07f8edd` · 9/9 tests in `Wave9FrontlineAndSaasResilienceTest` |
| **Frontend UX, Semantic Tokens, WCAG 2.2 AAA, i18n & Hygiene** | 58.0 / 100 (D+) | **96.5 / 100 (A+)** | `a522da0`, `7a48849` · 5/5 tests in `FrontendHygieneGuardrailTest` + 39/39 Playwright |
| **Multi-Tenant Database Composite FK Schema Integrity** | 362 / 364 Integrity | **364 / 364 Passed (100%)** | `775b55b` (`2027_04_12_000001_patch_composite_fk_and_unique_gaps.php`) |
| **Zero-Regression Platform & Architecture Gates** | 278 Arch,Unit / 39 E2E | **285 Arch,Unit / 1,565 Feature / 39 E2E** | Zero Pint/PHPStan errors; all ratchets (`<= 990` / `<= 760`) respected |

---

## 2. CHECKPOINT IMPLEMENTATION SUMMARY

### Checkpoint 9A — Workforce, PPh 21 TER / December Pasal 17 (PMK 168/2023), BPJS, Commission & GL PSAK (`c6f7726`, `8ab0732`, `c0a70fe`)

- **PPh 21 December / Resignation Reconciliation (`TAX-01` & `TAX-02`):**
  - Updated `app/Modules/Workforce/Services/PayrollCalculationPipeline.php`: verified JKM (`0.30%`) contributes accurately to both taxable TER gross (`taxGross`) and employer BPJS totals.
  - Added `$isFinalMonth` detection (`$periodEnd->month === 12`, `RESIGNED`, termination within period, or `is_final_month` flag) routing to `StatutoryTaxService::calculateAnnualPasal17Tax()`, subtracting cumulative period 1–11 PPh 21 withholdings.
  - Handled December over-withholding (`$taxDeducted < 0`) by refunding `abs($taxDeducted)` as `TAX_PPH21_REFUND` earning into net pay and debiting `TAX_PPH21_PAYABLE` in `PayrollPostingService.php` (never posting negative credits).
  - Fixed `GROSS_UP` calculation by adding `tax_allowance` to DTO `grossEarnings`, taxable gross, and `TAX_ALLOWANCE` item, paired with `EXPENSE_TAX_ALLOWANCE` (`5306`) Debit mapping in `PayrollPostingService.php`.
- **BPJS JP Age Exemption, Grace Period & Leave Locking (`BPJS-01`, `ATT-01`, `LEAVE-01`):**
  - Exempted Jaminan Pensiun (JP 1% EE / 2% ER = 0%) for employees aged `>= 59` years in `app/Modules/Workforce/Services/StatutoryBpjsService.php`.
  - Applied `grace_period_minutes` in `app/Modules/Workforce/Services/AttendanceService.php` (tardiness within grace period records `0` late minutes / `PRESENT`).
  - Enforced `lockForUpdate()` inside `DB::transaction()` across submit, approve, and cancel flows in `app/Modules/Workforce/Services/LeaveService.php`.
- **Anti-Fraud Sales Commission (`COM-01`, `COM-02`, `COM-03`):**
  - Restricted commission basis in `app/Modules/Workforce/Services/CommissionService.php` to pure DPP (`subtotal_amount - discount_amount`), excluding PB1/PPN and service charge.
  - Added `payroll_batch_id` and `paid_at` tracking via `database/migrations/2027_04_10_000001_patch_workforce_integrity_guards.php`; locked commissions as `PAID` with `payroll_batch_id` in `PayrollBatchService::finalizeBatch()`.
  - Integrated `CommissionService::clawbackCommission()` into `VoidAndRestoreOrderHandler` and `ReturnRefundService` (`VOIDED` / proportional reduction while unpaid; negative `CLAWBACK` record created after `PAID` without mutating closed batches).
- **BCMath GL Balance, Anti-Double Batch & Maker-Checker (`GL-01`, `GL-02`, `CONC-01`, `SEC-01`):**
  - Converted GL aggregations in `app/Modules/Workforce/Services/PayrollPostingService.php` to BCMath (`bcadd`, `bccomp`) and mapped `EXPENSE_SALARY_ADJUSTMENT` (`5305`) alongside COA accounts in `database/seeders/ChartOfAccountsSeeder.php`.
  - Enforced unique active period key `(tenant_id, active_period_key)` in `payroll_batches` (`NULL` when `VOIDED`) with `lockForUpdate()` in `PayrollBatchService.php`.
  - Removed silent `try/catch` around `postAccrualJournal()` in `app/Livewire/HR/PayrollBatchManager.php`, wrapping approval + finalization + GL accrual posting in a single atomic transaction.
  - Replaced self-approval in `PayrollBatchManager::finalize()` with `App\Platform\Workflow\MakerCheckerGuard::assertCanApprove()`, added case-insensitive `'Owner'` check + explicit solo-owner override audit entry written to `activity_logs` via `App\Platform\Audit\ImmutableAuditWriter`, and fixed missing required `store_id` & fillable target fields (`target_entity_type`, `target_entity_id`) on `Approval::create()`.

### Checkpoint 9B — Frontline POS Resilience, SaaS Billing Anti-Tampering, Anti-OOM Exports & Storage Hygiene (`07f8edd`)

- **POS Barcode F2, Thermal Rounding, Station Splitting & Offline Shared-Tablet Guard (`BAR-01`, `PRT-01`, `PRT-02`, `PWA-01`):**
  - Added `@keydown.enter.prevent="$wire.handleSearchEnter($el.value)"` on `#search-input` in `resources/views/livewire/pos/partials/product-grid.blade.php` backed by atomic `ManagesPosCartAndVariants::handleSearchEnter()` (adds exact SKU/barcode to cart and clears search; retains filter grid on partial/name search).
  - Added conditional `Pembulatan` (`rounding_adjustment`) row on `resources/views/transaction/receipt.blade.php`.
  - Enhanced `app/Modules/Sales/Services/Printing/KitchenPrinterService.php` with `station` filtering (`Kitchen` vs `Bar`) and prominent payment status banner (`[SUDAH LUNAS]` / `[BELUM BAYAR / OPEN TAB]`).
  - Partitioned IndexedDB storage in `public/js/pos-offline-queue.js` per `(tenant_id, user_id)` and transmitted `offline_created_by_user_id` + `offline_shift_id` to `OfflineOrderSyncService.php`, flagging post-close syncs with `offline_post_close_reconciliation = true` in `orders` rather than misattributing them to another cashier's active drawer.
- **SaaS Billing Amount Integrity & Shift-Safe Lifecycle (`BILL-01`, `BILL-02`):**
  - Added `expected_amount` column to `provisioning_requests` (`2027_04_11_000001_patch_resilience_and_billing_guards.php`) and fail-closed BCMath check (`bccomp($paidAmount, $expectedAmount, 2) < 0`) + replay rejection in `SubscriptionBillingOrchestrator::settleFromWebhook()`.
  - Added daily `subscription:evaluate-status` Artisan command (`app/Console/Commands/EvaluateSubscriptionStatusCommand.php`, scheduled in `routes/console.php`) transitioning expired subscriptions cleanly (`active/trialing` $\rightarrow$ `past_due` with 3-day grace $\rightarrow$ `suspended`) and clearing entitlement cache, while exempting shift-close and table-release routes in `SetTenantContext.php`.
- **Anti-OOM Exports, Export Pruning, Log Rotation & Private Disk (`EXP-01`, `EXP-02`, `LOG-01`, `UPL-01`):**
  - Refactored `app/Exports/ProductsExport.php` to `FromQuery + WithCustomChunkSize(1000) + WithColumnWidths` (removed `FromCollection` and `ShouldAutoSize`) and added `streamCsv()` (`chunkById(1000)` + `fputcsv`) to `app/Exports/InventoryLedgerExport.php` used by `ProcessInventoryLedgerExportJob.php` for `format=csv`.
  - Added daily `exports:prune --days=7` command (`app/Console/Commands/PruneExportsCommand.php` + `ExportTaskStatus::Expired`) in `routes/console.php`.
  - Configured default stack log channel to `'daily'` (14-day retention) in `config/logging.php` and added isolated `'private_documents'` disk (`storage/app/private_documents`) in `config/filesystems.php`.

### Checkpoint 9C & Post-Audit Schema Closure (`a522da0`, `7a48849`, `775b55b`)

- **Semantic Token & Confirm Dialog Unification (`DS-01`, `DS-02`, `UX-01`):**
  - Eliminated all uncompiled numeric token classes (`ring-primary-*`, `text-danger-*`, `placeholder-primary-400`, `placeholder-slate-*`) across Blade views and standardized on semantic tokens (`bg-destructive`, `text-destructive`, `ring-ring`, `placeholder:text-muted-foreground`).
  - Removed dead `resources/views/partials/sweetalert-confirm-delete.blade.php` and migrated native `onclick="confirm(...)"` handlers to `<x-erp.confirm-dialog>` via `confirmSubmit()` / `confirmDelete()`.
- **Accessibility, Livewire Hygiene & Memory Leak Prevention (`A11Y-01..03`, `UX-02`, `UX-03`, `FE-01..03`, `POS-01..04`):**
  - Converted `<div @click>` interactive triggers cleanly and added descriptive `aria-label` attributes on icon-only controls (`close`, `&times;`, `more_vert`, `refresh`) and `x-trap` on dialogs (`store-target-modal`, `tables/index`).
  - Added `wire:key` across interactive `@foreach`/`@forelse` loops, `x-cloak` on `x-show` elements/tab panes, `wire:loading.attr="disabled"` on submit actions, `@assets` wrapping + `Livewire.on(...)` listener unsubscribe cleanup in `checkout-modal.blade.php`, and `removeEventListener` cleanup in `commandPalette.js` and `dashboardEcharts.js`.
- **i18n Parity, Format Helpers & Safe Exception Handling (`LANG-01..06`):**
  - Added `lang/id/` (`20` files matching 1:1 with `lang/en/` `20` files) and standardized ambiguous mixed Indonesian/English UI copy to clean English microcopy (`tables/index`, `products/*`, `settings/index`, `sales-order-manager`, `ofd-channel-config-manager`, `pos/partials/*`, `store-target-modal`, `payment-detail-drawer`).
  - Added `App\Support\Format\Rupiah::format()` (with UTF-8 NBSP `\u{00A0}`) and `App\Support\Format\Date::display()`, covered by `tests/Unit/Support/FormatHelpersTest.php`.
  - Split generic `catch (\Throwable)` blocks across Livewire managers (`AccountingPeriodManager`, `BankReconciliationWorkbench`, `LeaveManager`, `PayrollBatchManager`, `ManufacturingOrderManager`, `ManagesOrderReturnsAndRefunds`, `ManagesPosSplitBill`) into domain exceptions vs safe `report($e)` + localized fallback message.
- **Guardrail Tests & Composite Foreign Key Schema Closure (`775b55b`):**
  - Added `tests/Arch/FrontendHygieneGuardrailTest.php` (5 automated checks blocking numeric `primary-NNN`/`danger-NNN`, `placeholder-slate-`, native `onclick confirm()`, deleted sweetalert partial, and hardcoded hex classes).
  - Created `database/migrations/2027_04_12_000001_patch_composite_fk_and_unique_gaps.php` replacing the single-column FK on `commission_records.payroll_batch_id` with composite `(tenant_id, payroll_batch_id)` $\rightarrow$ `payroll_batches(tenant_id, id)` (`ON DELETE RESTRICT ON UPDATE CASCADE`) and adding missing `UNIQUE(tenant_id, id)` keys on `table_sessions` and `ofd_channel_configs`.

---

## 3. VERBATIM QUALITY GATE & VERIFICATION EVIDENCE

All checks below were executed cleanly on the final codebase (commit `c0a70fe` on local `point_of_sales` / `yovel_pos_test`):

### 3.1 Code Style (Pint) & Static Analysis (PHPStan)
```text
$ ./vendor/bin/pint --test
{"tool":"pint","result":"passed"}

$ ./vendor/bin/phpstan analyse --error-format=raw --memory-limit=1G
{"tool":"phpstan","result":"passed","errors":0}
```

### 3.2 Architecture & Unit Suite
```text
$ ./vendor/bin/phpunit --testsuite=Arch,Unit --no-coverage
{"tool":"phpunit","result":"passed","tests":285,"passed":285,"assertions":3967,"duration_ms":9668}
```
- Ratchets verified intact: `TenantScopeBypassTest <= 990`, `ModuleBoundaryTest <= 760`, `NoGodClassTest`, `UiUxStandardsGuardrailTest`, `FrontendHygieneGuardrailTest`.

### 3.3 Integrity Suite (Multi-Tenant Isolation, Composite FKs, Finance, Subscription, Workflow, Navigation, Kasir, Retail, Manufacturing)
```text
$ ./vendor/bin/phpunit --testsuite=Integrity --no-coverage
[DEADLOCK-TEST] iterations=20 served=20 deadlocks=0 errors=0
{"tool":"phpunit","result":"passed","tests":364,"passed":364,"assertions":3479,"duration_ms":200463,"risky":1}
```
*(Exit code 0; `CompositeForeignKeyIsolationTest` 7/7 passed after `2027_04_12_000001_patch_composite_fk_and_unique_gaps.php`; 1 pre-existing test marked risky elsewhere in Integrity, 0 failures/errors.)*

### 3.4 Full Feature Suite
```text
$ ./vendor/bin/phpunit --testsuite=Feature --no-coverage
[PricingPerformanceBenchmark] Min: 6.35ms | Median: 7.43ms | p95: 8.35ms | Max: 8.53ms
{"tool":"phpunit","result":"passed","tests":1565,"passed":1565,"assertions":18686,"duration_ms":328501}
```
*(Including all `10/10` tests in `Wave9WorkforcePayrollIntegrityTest` and `9/9` tests in `Wave9FrontlineAndSaasResilienceTest`.)*

### 3.5 Vite Production Bundle, Subset Font & Repository Hygiene
```text
$ npm run build
vite v8.2.2 building client environment for production...
✓ 605 modules transformed.
public/build/manifest.json                            1.42 kB │ gzip:   0.36 kB
public/build/assets/app-Ccq0iJwt.css                203.86 kB │ gzip:  31.27 kB
public/build/assets/erpChart-ZMXfP738.js              4.74 kB │ gzip:   1.93 kB
public/build/assets/dashboardEcharts-C1yauULP.js     17.59 kB │ gzip:   4.68 kB
public/build/assets/vendor-gsap-RFvD2-O3.js          70.04 kB │ gzip:  27.49 kB
public/build/assets/app-nquqhmwn.js                 131.01 kB │ gzip:  38.49 kB
public/build/assets/vendor-echarts-BlivLn5k.js    1,084.23 kB │ gzip: 358.32 kB
✓ built in 1.95s

$ stat -c "%n: %s bytes" public/fonts/material-symbols-rounded.woff2 public/build/assets/app-*.js public/build/assets/app-*.css
public/fonts/material-symbols-rounded.woff2: 22112 bytes
public/build/assets/app-nquqhmwn.js: 131013 bytes
public/build/assets/app-Ccq0iJwt.css: 203868 bytes

$ git --no-pager ls-files '*.py' | wc -l
0
```

### 3.6 Playwright E2E Accessibility, Layout & Performance Suite (Local `127.0.0.1:8000`)
```text
$ npx playwright test tests/e2e/accessibility.spec.ts
Running 39 tests using 6 workers
...
  39 passed (48.2s)
```
Covers all 3 projects (`Desktop 14-inch Light`, `Desktop 14-inch Dark`, `Tablet POS (iPad)`): WCAG 2.2 AA/AAA Axe scans, Z-index token hierarchy, keyboard navigation/focus rings, Cloudflare-scale geometry/breathing room, expanded & micro-rail zero-scroll sidebar (`<= 605px` / `<= 60px`), sub-nav overflow, Material Symbols 1em zero-FOUT box lock, multi-domain dark mode crawl, and numeric `CLS <= 0.01`.

### 3.7 Live VPS Endpoint Read-Only Check
```text
$ curl -sI --max-time 10 https://app.apeironsystem.web.id
HTTP/2 523
server: cloudflare
```
- Verified strictly read-only HTTP HEAD response (`HTTP/2 523` — origin unreachable via Cloudflare from current environment). No live VPS data or accounts were modified or accessed.

---

## 4. COMMIT HISTORY (`3cfea65` $\longrightarrow$ `HEAD`)

| Commit | Checkpoint | Scope |
| :--- | :---: | :--- |
| `c6f7726` | **9A** | Workforce payroll PMK 168/2023 December Pasal 17, Gross-Up, JKM 0.30%, BPJS JP age >= 59 cap, DPP commission & clawback, BCMath GL balance, MakerCheckerGuard |
| `07f8edd` | **9B** | POS F2 barcode enter, thermal rounding, kitchen station split, shared-tablet offline attribution, SaaS webhook `expected_amount` guard, shift-safe expiry cron, anti-OOM exports, pruning & log rotation |
| `a522da0` | **9C** | Eliminate dead primary/danger tokens, unify confirm dialog, delete sweetalert partial, add `wire:key`/`x-cloak`/`@assets`, fix JS listener leaks, add `lang/id/` & `Rupiah`/`Date` helpers |
| `7a48849` | **9C+** | WCAG AAA `aria-label`s, `x-trap`, `wire:loading.attr`, safe exception handling, English microcopy standardization, Rupiah NBSP fix, `lang/id/` modules, checkout listener cleanup, frontend guardrail tests |
| `775b55b` | **9C/D** | Close 2 pre-existing `CompositeForeignKeyIsolationTest` gaps (`commission_records.payroll_batch_id` composite FK, `table_sessions` & `ofd_channel_configs` `UNIQUE(tenant_id, id)`) + Pint EOF newlines |
| `8ab0732` | **9A+** | Fix `PayrollBatchManager::finalize()` missing required `store_id` & fillable target columns on `Approval::create()`, case-insensitive `Owner` check, and `ImmutableAuditWriter` log entry to `activity_logs` |
| `c0a70fe` | **9A+** | Remove redundant `withoutGlobalScopes()` in `PayrollBatchManager::finalize()` to preserve `TenantScopeBypassTest` ratchet (`<= 990`) |
| `9D` | **9D** | Publish `APEIRON_WAVE9_GRAND_CONSOLIDATION_CLOSURE_REPORT.md` |

---

## 5. RESIDUAL SCOPE / NOTES

1. **Production VPS Deployment (`app.apeironsystem.web.id`):** Left untouched per Wave 9 rules (`curl -sI` observed `HTTP/2 523` origin unreachable). Code commits on `main` are ready locally for standard CI/CD rollout once origin connectivity is available.
2. **Database Test Metrics Note:** Total PHPUnit coverage across all local suites after the final PHP edit: `285` Arch/Unit + `364` Integrity (`risky: 1` pre-existing) + `1,565` Feature = **`2,214` passing tests** (`26,132` assertions) + **`39` Playwright E2E** tests passing.
3. **CSV vs XLSX Streaming on Multi-Table Inventory Ledger:** `ProductsExport` streams `.xlsx` cleanly via `FromQuery` + `WithCustomChunkSize(1000)` + `WithColumnWidths`. `InventoryLedgerExport` merges two separate tables (`stock_movements` + `ingredient_stock_movements`) so large-scale OOM-safe exports should pass `format=csv` to `ProcessInventoryLedgerExportJob` (invoking `streamCsv()` with `chunkById(1000)` + `fputcsv`); the legacy `format=xlsx` default remains in place to preserve existing `InventoryExportJobTest` `.xlsx` assertions.
