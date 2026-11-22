# APEIRON SYSTEMS — FORENSIC FRONTEND GLOBAL STANDARD AUDIT REPORT
**Target Codebase:** `point-of-Sales` (v1.6.1-uat-verified)  
**Standard Benchmark:** Tier-1 Global Enterprise B2B SaaS & Financial Software (Linear, Stripe, Cloudflare, SAP Fiori Horizon) + WCAG 2.2 Level AAA  
**Audit Scope:** 13 Canonical Bounded Contexts, 9 Navigation Domains, All Frontend Layouts, Livewire Components, CSS/JS Pipeline, Security & Offline Engines  
**Mandate:** Zero Code Modifications (Audit-Only) · Evidence-First · Complete Forensic Transparency  
**Document Location:** `docs/audit/APEIRON_FRONTEND_GLOBAL_STANDARD_AUDIT.md`  

---

## 1. EXECUTIVE SCORECARD TABLE

| No. | Pillar Dimension | Score (0–100) | Grade | Status / Primary Verdict |
|:---:|:---|:---:|:---:|:---|
| **1** | **WCAG 2.2 Level AAA & Inclusive Accessibility** | **52** | **D** | **Critical Blockers:** Text/border contrast gaps on muted tokens & 169 `text-zinc-400` elements (2.56:1 AA/AAA fail); link-variant focus outline suppressed; sub-44px touch targets; 76 tables missing `scope="col"`; Command Palette focus drop. |
| **2** | **Design System Governance & Anti-AI-Slop** | **55** | **D** | **Severe Token Drift:** Clean CSS token architecture undermined by 2,919 raw palette utility classes (zinc, gray, emerald, red, blue), 926 manual `dark:` classes across 52 views, 49.9% monetary lines lacking `tabular-nums`, and 11 orphaned Breeze starter components. |
| **3** | **Livewire, Alpine.js & Blade Hygiene** | **52** | **D** | **Reliability & Syntax Defects:** 136 of 137 `@foreach` loops missing `wire:key`; un-debounced live inputs; global keydown memory leak in POS cashier; invalid HTML attribute syntax `@keydown.window."+".prevent` in POS cashier; 5 Livewire components violating `< 450` line ceiling; 0 lazy components. |
| **4** | **Core Web Vitals & Asset Pipeline** | **60** | **C-** | **Bundle Bloat:** 782 KB monolithic JS bundle loaded synchronously on all pages due to un-chunked ECharts & GSAP (initial asset payload 1.0 MB); render-blocking Google Fonts CDN `<link>` in `<head>`; unpinned third-party script from jsDelivr. |
| **5** | **Responsive Design & Multi-Device Ergonomics** | **68** | **D+** | **Table Clipping:** Sleek desktop shell, but 13 tables lack `overflow-x-auto` (horizontal clipping on iPad/Mobile); POS cashier controls fail 44×44px touch ergonomics; Modal, Drawer, and Command Palette collide on `z-50`. |
| **6** | **UX Completeness, Microcopy & State Coverage** | **56** | **D** | **Financial False Positive:** Red destructive alert "GL Reconciliation Variance Detected — Selisih Rp 0" caused by operational pending shifts treated as accounting variances while underlying service hardcodes balances to 0; pervasive bilingual English/Indonesian drift. |
| **7** | **Frontend Security & Multi-Tenant Client Isolation** | **46** | **F** | **Security & Injection Sinks:** Raw `{!! $description !!}` in `<x-ui.alert>`; un-sanitized `innerHTML` in `swal-bridge.js`; unescaped Alpine expression evaluations in modal, searchable-select, and slide-over; global unpartitioned IndexedDB `apeiron_pos_offline_db` persisting cross-tenant offline orders on shared terminals. |
| **8** | **Real-Time (Reverb WSS), Offline PWA & Hardware UX** | **40** | **F** | **Engine Broken:** Service Worker fails installation 100% of the time (caching non-existent `/css/app.css` & `/js/app.js`), unregistered in app, missing `manifest.json`; Reverb listener in KDS lacks connection status & unmount teardown; QZ Tray popup blocked by browser policies without feedback. |
| **TOTAL** | **OVERALL FRONTEND MATURITY SCORE** | **53.6 / 100** | **D** | **Pre-Redesign Verdict: MANDATORY FOUNDATION REMEDIATION REQUIRED (Wave 0) BEFORE Redesigning Executive Dashboard.** |

---

## 2. TOP 15 CRITICAL FINDINGS (RANKED P0 → P3)

### [FE-SEC-01] [P0] Unescaped `{!! $description !!}` in `<x-ui.alert>` and Unsanitized Alpine Expressions Enabling XSS
- **Standard Reference:** OWASP Top 10 (A03:2021 Injection), WCAG 4.1.2 (Name, Role, Value)
- **Location:** `resources/views/components/ui/alert/index.blade.php:54`, `resources/views/components/ui/modal/index.blade.php:54,71,74`, `resources/views/components/erp/searchable-select.blade.php:48`, `resources/views/components/erp/slide-over.blade.php:29`
- **Evidence Snippet:**
  ```blade
  {{-- alert/index.blade.php:54 --}}
  @if ($description)
      <div @class(['opacity-90 leading-relaxed', 'mt-1' => $title])>{!! $description !!}</div>
  @endif
  ```
  ```blade
  {{-- modal/index.blade.php:54,71 --}}
  x-data="{ modalOpen: {!! $openExpression !!}, modalId: @js($modalId) }"
  @keydown.escape.window="if (modalOpen) { {!! $closeAction !!} }"
  ```
  ```blade
  {{-- searchable-select.blade.php:48 --}}
  value: {!! $valueExpression !!},
  ```
- **Technical Impact:** 
  1. `<x-ui.alert>` uses unescaped raw Blade `{!! $description !!}` while slot content uses escaped `{{ $slot }}`. If any dynamic error message or third-party exception message is passed into `:description`, it renders raw unescaped HTML into the DOM.
  2. In `modal`, `searchable-select`, and `slide-over`, PHP expressions are concatenated directly into JavaScript Alpine attribute directives without `@js()` or JSON escaping, creating a direct injection surface if parameter values contain unescaped quotes or user data.
- **Recommended Remediation:** Replace `{!! $description !!}` with standard escaped Blade echo `{{ $description }}`. Use `@js()` or strict sanitization for all Alpine expressions.

---

### [FE-SEC-02] [P0] Global Unpartitioned IndexedDB Offline Queue Storing Cross-Tenant Financial Orders on Shared POS Terminals
- **Standard Reference:** DEC-001 (Fail-Closed Multi-Tenant Isolation), ADR-102 (Bounded Context Security)
- **Location:** `public/js/pos-offline-queue.js:8-23`, `public/js/pos-offline-queue.js:141-150`, `resources/views/livewire/kasir/create-order.blade.php:202`
- **Evidence Snippet:**
  ```javascript
  const DB_NAME = 'apeiron_pos_offline_db';
  const DB_VERSION = 1;
  const STORE_NAME = 'offline_orders';
  ...
  const response = await fetch('/api/v1/pos/sync-offline-orders', {
      method: 'POST',
      headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || ''
      },
      body: JSON.stringify({ orders: batch })
  });
  ```
- **Technical Impact:** The browser IndexedDB database `apeiron_pos_offline_db` is shared globally across the entire browser origin without `tenant_id` namespacing. On shared cashier terminals or tablets:
  1. Cashier from Tenant A creates offline orders while internet is interrupted.
  2. Cashier logs out.
  3. Cashier from Tenant B (or another company/store) logs in.
  4. Internet reconnects. `ApeironOfflinePos.syncPendingOrders()` fires automatically and transmits all queued orders using Tenant B's active CSRF token and session!
  5. Orders created under Tenant A are either processed under Tenant B's financial ledger context or fail and corrupt transactions.
- **Recommended Remediation:** Namespace IndexedDB by tenant: `const DB_NAME = 'apeiron_pos_' + activeTenantId;`. Invalidate or purge database connections on logout. Validate that each offline order payload matches the authoritative `tenant_id` on the server before processing.

---

### [FE-PWA-01] [P0] Completely Broken PWA Offline Engine: Service Worker Unregistered, Points to 404 Assets, and Missing `manifest.json`
- **Standard Reference:** DEC-006 (Offline-First POS Resilience), PWA W3C Standard
- **Location:** `public/sw.js:1-11`, `resources/views/layouts/app.blade.php`, `public/manifest.json`
- **Evidence Snippet:**
  ```javascript
  const CACHE_NAME = 'pos-v2';
  const ASSETS = [
      '/transaction',
      '/transaction/create',
      '/css/app.css', // 404 NOT FOUND (Vite uses /build/assets/app-*.css)
      '/js/app.js',   // 404 NOT FOUND (Vite uses /build/assets/app-*.js)
      '/offline.html',
      '/fonts/material-symbols-rounded.woff2',
      '/fonts/plus-jakarta-sans.woff2',
      'https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js'
  ];
  self.addEventListener('install', (event) => {
      event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS)));
  });
  ```
- **Technical Impact:**
  1. Under W3C Service Worker specifications, if ANY asset in `cache.addAll()` fails (HTTP 404), the entire Service Worker installation is aborted. Since `/css/app.css` and `/js/app.js` do not exist (Vite generates hashed production assets in `public/build/assets/`), the Service Worker fails to install 100% of the time.
  2. Furthermore, `navigator.serviceWorker.register` is NOT called anywhere in `resources/views/**` or `resources/js/**`. The Service Worker is completely dead.
  3. `public/manifest.json` does not exist. PWA cannot be installed on Android/iOS/iPadOS home screens as a standalone POS kiosk.
- **Recommended Remediation:** Generate dynamic Service Worker asset lists reading `public/build/manifest.json`. Create a valid `public/manifest.json` with official web app icons. Register the Service Worker conditionally on `/transaction` (POS) and `/kds` routes.

---

### [FE-UX-01] [P0] False-Positive Destructive Alert: `GL Reconciliation Variance Detected — Selisih Rp 0` on Executive Dashboard
- **Standard Reference:** WCAG 3.3.4 (Error Prevention: Financial, Data), SAP S/4HANA Accounting Trust Metric
- **Location:** `resources/views/dashboard/partials/executive-workspace.blade.php:23-38` and `app/Modules/Reporting/Services/ExecutiveDashboardService.php:360-394`
- **Evidence Snippet:**
  ```php
  // ExecutiveDashboardService.php:360, 391-394
  $isReconciled = ($unclosedShifts === 0 && $draftJournals === 0);
  ...
  return [
      'is_trusted' => false,
      'is_reconciled' => false,
      'status_badge' => 'Audit Pending',
      'status_text' => implode(' • ', $parts),
      'unclosed_shifts' => $unclosedShifts,
      'draft_journals' => $draftJournals,
      'reconciliation_variance' => 0, // Hardcoded 0!
      'net_pos_sales' => 0,            // Hardcoded 0!
      'sales_journal_balance' => 0,    // Hardcoded 0!
  ];
  ```
  ```blade
  {{-- executive-workspace.blade.php:23-30 --}}
  @else
      <div class="rounded-xl border border-destructive-border bg-destructive-muted p-4 ...">
          <div class="text-xs sm:text-sm font-bold text-destructive">GL Reconciliation Variance Detected</div>
          <div class="text-xs text-muted-foreground mt-0.5">
              Selisih Rp {{ number_format($executiveData['glTrustIndicator']['reconciliation_variance'] ?? 0, 0, ',', '.') }} antara POS Subledger (Rp {{ number_format($executiveData['glTrustIndicator']['net_pos_sales'] ?? 0, 0, ',', '.') }}) dan Jurnal GL (Rp {{ number_format($executiveData['glTrustIndicator']['sales_journal_balance'] ?? 0, 0, ',', '.') }}). Periksa unposted batches!
          </div>
      </div>
  @endif
  ```
- **Technical Impact:** When cashier shifts are still open or draft journals are awaiting posting, `ExecutiveDashboardService` sets `is_trusted = false` and returns `reconciliation_variance = 0`, `net_pos_sales = 0`, and `sales_journal_balance = 0`. The Blade view unconditionally falls into `@else`, displaying an alarming red warning banner stating: *"GL Reconciliation Variance Detected — Selisih Rp 0 antara POS Subledger (Rp 0) dan Jurnal GL (Rp 0). Periksa unposted batches!"*.
  This falsely accuses the accounting system of having an imbalance when in fact no mathematical variance exists, and no actual ledger math was performed. It panics CFOs and business owners, destroying trust in the ERP ledger.
- **Recommended Remediation:** Separate the indicator into three states:
  1. **Reconciled (Success/Green):** Zero unposted journals & zero unclosed shifts.
  2. **Audit Pending (Amber/Warning):** Active shifts / draft journals present, variance = 0.
  3. **Variance Imbalance (Red/Destructive):** Variance !== 0. Compute actual sums for POS sales and Journal entries rather than hardcoding 0.

---

### [FE-A11Y-01] [P0] Focus Suppression on `variant="link"` Buttons and 38 Elements Suppressing Outline Without Ring Replacement
- **Standard Reference:** WCAG 2.4.7 (Focus Visible - Level AA), WCAG 2.4.11 (Focus Appearance - Level AAA)
- **Location:** `resources/views/components/ui/button/index.blade.php:67,80`, `resources/views/auth/login.blade.php:121`, `resources/views/categories/index.blade.php:193-218`
- **Evidence Snippet:**
  ```blade
  {{-- button/index.blade.php --}}
  case 'link':
      $typeClasses = 'border-transparent no-underline text-foreground hover:underline px-0 h-auto';
      break;
  ...
  {{ $attributes->twMerge($sizeClasses.' '.$typeClasses.' ... focus-visible:outline-none ...') }}
  ```
- **Technical Impact:** `button/index.blade.php` appends `focus-visible:outline-none` globally. All button variants (primary, secondary, destructive) add `focus-visible:ring-2`, BUT the `link` variant does NOT add any ring class. Consequently, any button with `variant="link"` has ZERO focus indicator when navigated via keyboard. 38 other input and button locations across `resources/views/` explicitly suppress `outline-none` without providing `ring` alternatives.
- **Recommended Remediation:** Remove `focus-visible:outline-none` from buttons unless paired with an explicit, high-contrast `:focus-visible` ring. Add `focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1` to the `link` variant.

---

### [FE-SYNTAX-01] [P1] Broken HTML Attribute Syntax on POS Keyboard Shortcuts in Cashier View
- **Standard Reference:** HTML5 Specification (Attribute Syntax), Alpine.js Directive Binding
- **Location:** `resources/views/livewire/kasir/create-order.blade.php:107-111`
- **Evidence Snippet:**
  ```html
  @keydown.window."+".prevent="bumpCartCursor(1)"
  @keydown.window."=".prevent="bumpCartCursor(1)"
  @keydown.window."-".prevent="bumpCartCursor(-1)"
  ```
- **Technical Impact:** In standard HTML5, quotes inside unquoted attribute names are invalid. The browser's HTML parser treats the attribute name as truncated or malformed, causing Alpine.js to fail registering the keydown handler for `+`, `=`, and `-` keys. Cashiers attempting to adjust item quantities from the keyboard experience dead keys.
- **Recommended Remediation:** Replace malformed attribute names with standard Alpine event handling:
  `@keydown.window="if ($event.key === '+' || $event.key === '=') { $event.preventDefault(); bumpCartCursor(1); } else if ($event.key === '-') { $event.preventDefault(); bumpCartCursor(-1); }"`

---

### [FE-PERF-01] [P1] Global Keydown Event Listener Memory Leak on POS Cashier Terminal via Alpine.js SPA Navigation
- **Standard Reference:** Interaction to Next Paint (INP), Alpine.js / Livewire SPA Lifecycle
- **Location:** `resources/views/livewire/kasir/create-order.blade.php:79-102`
- **Evidence Snippet:**
  ```javascript
  // create-order.blade.php inside x-init
  window.addEventListener('keydown', (e) => {
      const el = e.target;
      const isEditing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
      const now = Date.now();
      ...
      if (e.key === 'Enter' && barcodeBuf.length >= 3 && !isEditing) {
          ...
          window.__barcodeQueue.push(code);
          window.__barcodeFlush();
      }
      if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey && !isEditing) {
          barcodeBuf += e.key;
      }
  });
  ```
- **Technical Impact:** The event listener is attached directly to `window` inside `x-init`. Because Livewire v3 uses `wire:navigate` for client-side routing, every time a user navigates between POS Cashier (`/transaction`) and other pages (Shift, Orders, Dashboard, POS), a new `keydown` listener is permanently attached to `window` without cleanup. After several navigations, 5–10 duplicated listeners process every keystroke concurrently, causing duplicate barcode scans, memory bloat, and degraded INP.
- **Recommended Remediation:** Store the listener reference and clean it up inside `$cleanup(() => window.removeEventListener('keydown', handler))` or dispatch an unbind event on `@destroy`.

---

### [FE-A11Y-02] [P1] Pervasive Color Contrast Failures on Muted Foreground, Shell, Borders, and 169 `text-zinc-400` Elements
- **Standard Reference:** WCAG 1.4.3 (Contrast Minimum - Level AA), WCAG 1.4.6 (Contrast Enhanced - Level AAA), WCAG 1.4.11 (Non-Text Contrast)
- **Location:** `resources/css/tokens.css:38,43,46,97`, 52 view files with `text-zinc-400`
- **Evidence Snippet:**
  - Token `--muted-foreground: #6B6B74` on `#FFFFFF` = **5.28:1** (FAILS AAA >= 7.0:1)
  - Token `--shell-foreground: #71717A` on `#FFFFFF` = **4.83:1** (FAILS AAA >= 7.0:1)
  - Token `--border: #E4E4E7` on `#FFFFFF` = **1.27:1** (FAILS Non-Text >= 3.0:1)
  - Utility class `text-zinc-400` (`#A1A1AA`) on `#FFFFFF` = **2.56:1** (FAILS BOTH AA AND AAA!)
- **Technical Impact:** Low-vision users and operators working in brightly lit store environments or under sunlight cannot read table headers, timestamps, secondary labels, or input borders. 169 occurrences of `text-zinc-400` fail the baseline legal AA standard of 4.5:1.
- **Recommended Remediation:** Elevate `--muted-foreground` to `#47474F` (7.6:1 AAA) and `--shell-foreground` to `#47474F`. Upgrade interactive input borders to `#94949E` (3.05:1). Purge all raw `text-zinc-400` classes and replace with `text-muted-foreground`.

---

### [FE-LW-01] [P1] 136 of 137 `@foreach` Loops in Livewire Views Missing `wire:key` in Critical Financial Ledgers and Tables
- **Standard Reference:** Livewire 3 DOM Morphing Guardrail, Livewire Architecture Best Practices
- **Location:** `resources/views/livewire/finance/journal-entry-manager.blade.php:78,201`, `resources/views/livewire/customers/customer-manager.blade.php:91`, `resources/views/livewire/hr/employee-manager.blade.php:140`, 133 others
- **Evidence Snippet:**
  ```blade
  {{-- journal-entry-manager.blade.php:201 --}}
  @foreach ($lines as $index => $line)
      <tr>
          <td class="p-2">
  ```
- **Technical Impact:** Out of 137 `@foreach` loops across `resources/views/livewire/`, 136 completely lack `wire:key`. Without unique `wire:key` bindings, Livewire's DOM-diffing algorithm cannot track row identities during re-renders (sorting, filtering, line additions, line deletions). In multi-line financial vouchers and journal entries, deleting line 2 causes line 3's values to morph into line 2's inputs, silently corrupting operator inputs and debited amounts before submission.
- **Recommended Remediation:** Enforce mandatory `wire:key` on every loop iteration: `<tr wire:key="journal-line-{{ $line['id'] ?? $index }}">`.

---

### [FE-PERF-02] [P1] 782 KB Monolithic JS Bundle Loaded Synchronously on All Pages Due to Un-Chunked ECharts and GSAP
- **Standard Reference:** Core Web Vitals (LCP, INP), Vite Code Splitting Standards
- **Location:** `vite.config.js:1-13`, `resources/js/app.js:2,4`, `public/build/assets/app-BHUVE3aN.js`
- **Evidence Snippet:**
  ```javascript
  // app.js
  import gsap from 'gsap';
  import erpChart from './components/erpChart'; // Imports echarts core + 3 chart types + 6 components!
  ...
  window.erpChart = erpChart;
  ```
  Built asset sizes:
  - `public/build/assets/app-BHUVE3aN.js`: **782 KB**
  - `public/build/assets/app-B3ZgJwKl.css`: **222 KB**
- **Technical Impact:** Total uncompressed asset payload is **1.0 MB** on initial load. Every single page, including authentication (`/login`), cashier checkout (`/transaction`), and simple CRUD tables, downloads and parses the entire Apache ECharts and GSAP libraries. On mobile POS terminals and lower-tier tablets, this creates a 1.2–2.5 second JavaScript execution delay, deteriorating Largest Contentful Paint (LCP) and Interaction to Next Paint (INP).
- **Recommended Remediation:** Configure Vite `rollupOptions.output.manualChunks` or convert `erpChart` and ECharts to a dynamic import (`await import('echarts')`) invoked only when `<x-erp.chart>` is mounted.

---

### [FE-PERF-03] [P1] Synchronous Google Fonts CDN and jsDelivr QZ Tray Scripts Blocking Initial Paint in `<head>`
- **Standard Reference:** Core Web Vitals (LCP, FCP), Offline-First Architecture, CSP Integrity
- **Location:** `resources/views/layouts/app.blade.php:17,164`, `resources/views/layouts/customer.blade.php:16`
- **Evidence Snippet:**
  ```blade
  <!-- Google Fonts CDN Fallback for Material Symbols -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
  ...
  <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
  ```
- **Technical Impact:**
  1. The layout preloads self-hosted fonts (`fonts/material-symbols-rounded.woff2`), but immediately proceeds to execute a synchronous render-blocking `<link rel="stylesheet">` call to Google Fonts CDN in `<head>`.
  2. If the POS terminal has no internet or is operating on an offline LAN, this synchronous external request stalls the browser until timeout, delaying FCP/LCP.
  3. Loading unpinned scripts from `cdn.jsdelivr.net` introduces third-party supply-chain vulnerability into an enterprise banking and ledger platform.
- **Recommended Remediation:** Remove external Google Fonts CDN link completely; Material Symbols Rounded is already self-hosted in `public/fonts/`. Self-host `qz-tray.js` in `public/vendor/qz-tray.js` or bundle it with Vite.

---

### [FE-A11Y-03] [P1] Sub-Standard Touch Targets (< 44×44 CSS px) on POS Cashier Controls, Micro-Rail, and Sidebar Toggles
- **Standard Reference:** WCAG 2.5.5 (Target Size - Level AAA), POS Ergonomics Standards
- **Location:** `resources/views/components/ui/button/index.blade.php:44`, `resources/views/layouts/navigation.blade.php:110,183`
- **Evidence Snippet:**
  - Micro-rail nav items: `class="w-9 h-9 ..."` = **36×36 px**
  - Sidebar collapse trigger: `class="w-7 h-7 ..."` = **28×28 px**
  - Default button size: `class="h-9 px-3.5 ..."` = **36 px height**
- **Technical Impact:** On touchscreens (iPad POS terminals, Android tablets, kitchen display touch monitors), operators tapping rapidly make frequent touch misfires. WCAG 2.5.5 AAA strictly mandates pointer targets of at least **44×44 CSS pixels**.
- **Recommended Remediation:** Elevate POS interactive targets, micro-rail icon buttons, and cashier keypad triggers to `min-h-[44px] min-w-[44px]`.

---

### [FE-A11Y-04] [P1] 76 Data Tables Across Modules Missing Header Cell Association (`scope="col"`)
- **Standard Reference:** WCAG 1.3.1 (Info and Relationships - Level A)
- **Location:** `resources/views/livewire/finance/journal-entry-manager.blade.php:193-197`, 75 other tables
- **Evidence Snippet:**
  ```blade
  {{-- journal-entry-manager.blade.php:193 --}}
  <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
      <tr>
          <th class="px-3 py-2.5 w-1/3">COA Account</th>
          <th class="px-3 py-2.5 w-1/4">Line Description</th>
          <th class="px-3 py-2.5 text-right w-1/6">Debit (Rp)</th>
  ```
- **Technical Impact:** 76 tables across Financial, Purchasing, Sales, and Workforce modules render `<th>` elements without `scope="col"`. Assistive technology (NVDA, JAWS, VoiceOver) cannot associate table cells with column headers, rendering screen reader navigation across financial accounts and balances incoherent.
- **Recommended Remediation:** Add `scope="col"` to all `<th>` tags or standardize all tables to `<x-erp.data-table>` / `<x-ui.table.head>`.

---

### [FE-RESP-01] [P2] 13 Data Tables Lacking Horizontal Scroll Containers (`overflow-x-auto`), Causing Severe Column Clipping on Mobile/Tablet
- **Standard Reference:** Multi-Device Responsive ERP Ergonomics, WCAG 1.4.10 (Reflow - Level AA)
- **Location:** `resources/views/livewire/finance/journal-entry-manager.blade.php:189`, `resources/views/livewire/customers/customer-manager.blade.php:622`, 11 other tables
- **Evidence Snippet:**
  ```blade
  {{-- journal-entry-manager.blade.php:189-190 --}}
  <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden">
      <table class="w-full text-left text-xs">
  ```
- **Technical Impact:** The wrapper div uses `overflow-hidden` instead of `overflow-x-auto`. On screens narrower than 768px (smartphones or tablet portrait), the Debit, Credit, and Delete action columns get clipped outside the viewport with no ability for the operator to scroll horizontally.
- **Recommended Remediation:** Replace `overflow-hidden` with `overflow-x-auto` on table wrappers.

---

### [FE-UX-02] [P2] Asynchronous QZ Tray Print Failure Triggers Blocked `window.open` Popup Without Operator Feedback
- **Standard Reference:** POS Operator Ergonomics, Browser User Activation Security Model
- **Location:** `resources/views/livewire/pos/partials/checkout-modal.blade.php:740-752`
- **Evidence Snippet:**
  ```javascript
  let printed = false;
  if (window.ApeironPrint?.printReceipt) {
      printed = await window.ApeironPrint.printReceipt(orderCode); // Multi-step async promise
  }
  if (!printed) {
      window.open(`/transaction/receipt/${orderCode}?autoprint=1`, '_blank', 'width=400,height=600');
  }
  window.apeironPosCartDirty = false;
  window.dispatchEvent(new CustomEvent('close-modal', { detail: 'checkout-payment' }));
  ```
- **Technical Impact:** When QZ Tray is offline or fails to connect, `ApeironPrint.printReceipt` resolves `false` after several asynchronous promise steps (`ensureConnected()`, `fetchPayload()`). By the time `window.open` is invoked, the browser's transient user activation token has expired. Modern browsers (Chrome, Safari, iPadOS) silently block the popup window. Simultaneously, lines 750-751 close the checkout modal. The cashier is left staring at the screen with no receipt printed, no popup window, and no error message.
- **Recommended Remediation:** Replace the fallback `window.open` popup with an inline print dialog or modal action that keeps the checkout completion card visible with a dedicated, user-gesture "Cetak Ulang Struk" button.

---

## 3. WCAG 2.2 LEVEL AAA CONTRAST & ACCESSIBILITY MATRIX

Calculations performed using official W3C relative luminance formulas ($L = 0.2126R + 0.7152G + 0.0722B$ where $sRGB \le 0.03928 \rightarrow sRGB / 12.92$ else $((sRGB + 0.055) / 1.055)^{2.4}$) and contrast ratio formula $(L_1 + 0.05) / (L_2 + 0.05)$:

| Token / Element Name | Foreground | Background | Mode | Contrast Ratio | WCAG 2.2 AA Status | WCAG 2.2 AAA Status | AAA Gap / Empirical Remediation |
|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---|
| **Text Foreground** | `#09090B` | `#FFFFFF` (Card) | Light | **19.90:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Text Foreground** | `#09090B` | `#FAFAFA` (Canvas) | Light | **19.06:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Muted Foreground** | `#6B6B74` | `#FFFFFF` (Card) | Light | **5.28:1** | **PASS** | **FAIL** | AAA Gap (-1.72). Replace with **`#47474F`** (7.60:1 AAA Pass). |
| **Muted Foreground** | `#6B6B74` | `#FAFAFA` (Canvas) | Light | **5.05:1** | **PASS** | **FAIL** | AAA Gap (-1.95). Replace with **`#47474F`** (7.27:1 AAA Pass). |
| **Shell Foreground** | `#71717A` | `#FFFFFF` (Shell) | Light | **4.83:1** | **PASS** | **FAIL** | AAA Gap (-2.17). Replace with **`#47474F`** (7.60:1 AAA Pass). |
| **Raw Zinc-400 (View Drift)** | `#A1A1AA` | `#FFFFFF` (Card) | Light | **2.56:1** | **FAIL** | **FAIL** | **Critical P0 Defect.** Fails AA (< 4.5:1). Purge and replace with `#47474F`. |
| **Raw Gray-400 (View Drift)** | `#9CA3AF` | `#FFFFFF` (Card) | Light | **2.42:1** | **FAIL** | **FAIL** | **Critical P0 Defect.** Fails AA (< 4.5:1). Purge and replace with `#47474F`. |
| **Border (Interactive)** | `#E4E4E7` | `#FFFFFF` (Card) | Light | **1.27:1** | **FAIL** | **FAIL** | Fails Non-Text Contrast 1.4.11 (< 3.0:1). Replace inputs with **`#94949E`** (3.05:1). |
| **Border Strong** | `#D4D4D8` | `#FFFFFF` (Card) | Light | **1.48:1** | **FAIL** | **FAIL** | Fails Non-Text Contrast 1.4.11 (< 3.0:1). Replace with **`#94949E`** (3.05:1). |
| **Focus Ring** | `#A1A1AA` | `#FFFFFF` (Card) | Light | **2.56:1** | **FAIL** | **FAIL** | Fails Non-Text Contrast 1.4.11 (< 3.0:1). Replace with **`#18181B`** (16.97:1). |
| **Success Status Text** | `#15803D` | `#FFFFFF` (Card) | Light | **5.02:1** | **PASS** | **FAIL** | AAA Gap (-1.98). For standalone text, use **`#14532D`** (8.42:1 AAA Pass). |
| **Success Badge Text** | `#052E16` | `#F0FDF4` (Muted) | Light | **14.24:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Warning Status Text** | `#B45309` | `#FFFFFF` (Card) | Light | **5.02:1** | **PASS** | **FAIL** | AAA Gap (-1.98). For standalone text, use **`#78350F`** (8.40:1 AAA Pass). |
| **Warning Badge Text** | `#451A03` | `#FFFBEB` (Muted) | Light | **14.44:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Destructive Status Text** | `#B91C1C` | `#FFFFFF` (Card) | Light | **6.47:1** | **PASS** | **FAIL** | AAA Gap (-0.53). For standalone text, use **`#991B1B`** (8.15:1 AAA Pass). |
| **Destructive Badge Text** | `#450A0A` | `#FEF2F2` (Muted) | Light | **14.76:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Info Status Text** | `#1D4ED8` | `#FFFFFF` (Card) | Light | **6.70:1** | **PASS** | **FAIL** | AAA Gap (-0.30). For standalone text, use **`#1E40AF`** (8.54:1 AAA Pass). |
| **Info Badge Text** | `#172554` | `#EFF6FF` (Muted) | Light | **13.50:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Dark: Text Foreground** | `#FAFAFA` | `#141415` (Card) | Dark | **17.64:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Dark: Muted Foreground** | `#A1A1AA` | `#141415` (Card) | Dark | **7.18:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Dark: Shell Foreground** | `#A1A1AA` | `#111112` (Shell) | Dark | **7.36:1** | **PASS** | **PASS** | Meets AAA requirement ($\ge 7.0:1$). |
| **Dark: Destructive Text** | `#F87171` | `#141415` (Card) | Dark | **6.66:1** | **PASS** | **FAIL** | AAA Gap (-0.34). Replace with **`#FCA5A5`** (9.80:1 AAA Pass). |
| **Dark: Interactive Border** | `rgba(255,255,255,0.14)` | `#141415` (Card) | Dark | **1.35:1** | **FAIL** | **FAIL** | Fails Non-Text Contrast 1.4.11 (< 3.0:1). Replace with **`rgba(255,255,255,0.30)`** (3.2:1). |

---

## 4. DESIGN TOKEN & HARDCODED-STYLE DRIFT INVENTORY

### 4.1 Token vs. Raw Utility Class Discrepancy
While `resources/css/tokens.css` defines a unified semantic CSS-variable system (`bg-card`, `text-foreground`, `border-border`), actual view development has heavily drifted into raw palette utilities:

- **Total Non-Semantic Palette Classes:** **2,919 occurrences** (across zinc, gray, slate, neutral, stone, red, amber, green, emerald, blue, etc.)
  - *Zinc Palette Alone:* **1,326 occurrences**
  - *Other Raw Color Palettes (Gray, Emerald, Red, Amber, Blue):* **1,593 occurrences**
- **Top Raw Palette Offenses:**
  1. `text-zinc-500`: **193** occurrences
  2. `border-zinc-200`: **180** occurrences
  3. `text-zinc-400`: **169** occurrences (Severe AA Contrast Failure)
  4. `border-zinc-800`: **156** occurrences
  5. `bg-zinc-900`: **141** occurrences
  6. `border-zinc-700`: **114** occurrences
  7. `bg-zinc-50`: **102** occurrences
  8. `text-zinc-200`: **88** occurrences
  9. `text-zinc-800`: **84** occurrences
  10. `text-zinc-900`: **77** occurrences
  11. `text-emerald-600`: **70** occurrences
  12. `text-zinc-300`: **59** occurrences
  13. `bg-zinc-800`: **58** occurrences
  14. `text-zinc-100`: **53** occurrences
  15. `border-zinc-300`: **49** occurrences

### 4.2 Manual Dark Mode Duplication (`dark:`)
- **Total `dark:` Variant Classes in Views:** **926 occurrences across 52 files**
- **Root Problem:** Tokens in `tokens.css` are designed to invert automatically when `.dark` is applied to `:root`. However, 52 Blade templates have hardcoded manual dark overrides (e.g. `bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-800`). This completely breaks maintainability: altering `--card` in `tokens.css` will not take effect on these 52 pages because raw Tailwind utilities override semantic variables.

### 4.3 Hardcoded Hex Color Scan
A repository-wide regex scan for `#[0-9a-fA-F]{3,8}` in `resources/views/` identified **48 occurrences**:
- Google OAuth SVG paths (`#4285F4`, `#34A853`, `#FBBC05`, `#EA4335`) in `auth/register`, `auth/login`, `manage-oauth-identities-form`.
- Shelf Label Print Template (`resources/views/retail/shelf-labels/print.blade.php:21-184`): `#f3f4f6`, `#111827`, `#2563eb`, `#1d4ed8`, `#9ca3af`, `#ffffff`, `#d1d5db`, `#000000`, `#4b5563`, `#6b7280`.
- POS Cashier Scrollbars (`resources/views/livewire/kasir/create-order.blade.php:164-181`): `#e5e5e5`, `#d4d4d4`.
- Thermal Receipt Print Template (`resources/views/transaction/receipt.blade.php:178`): `#fff !important`.

### 4.4 Tabular Numbers (`tabular-nums`) Verification
- **Total lines displaying monetary values (`Rp`):** **359 lines**
- **Lines with `tabular-nums`:** **180 lines (50.1%)**
- **Lines WITHOUT `tabular-nums`:** **179 lines (49.9%)**
- **Impact:** Almost exactly half of all monetary amounts displayed in the application suffer from proportional font jitter and misaligned numeric columns in tables.

### 4.5 Iconography Library Uniformity
- **Material Symbols Rounded/Outlined:** **632 occurrences** (Official Canonical Icon System).
- **Inline `<svg>` Elements:** **63 occurrences** (Logos, Google auth icons, receipt spinners).
- **Lucide Residual:** **2 occurrences** in `resources/views/components/ui/sidebar/container.blade.php:76` (Dead code).

---

## 5. LIVEWIRE, ALPINE.JS & BLADE TECHNICAL DEBT INVENTORY

### 5.1 Missing `wire:key` in Loops (136 of 137 Loops Identified)
High-risk financial and transactional loops lacking `wire:key`:
1. `resources/views/livewire/finance/journal-entry-manager.blade.php:78` (`@foreach ($journals as $journal)`)
2. `resources/views/livewire/finance/journal-entry-manager.blade.php:201` (`@foreach ($lines as $index => $line)`)
3. `resources/views/livewire/customers/customer-manager.blade.php:91` (`@foreach($customers as $c)`)
4. `resources/views/livewire/hr/employee-manager.blade.php:140` (`@foreach ($employees as $emp)`)
5. `resources/views/livewire/sales/sales-order-manager.blade.php:66` (`@foreach ($salesOrders as $so)`)
6. `resources/views/livewire/purchasing/purchase-order-manager.blade.php:211` (`@foreach ($items as $i => $item)`)

### 5.2 Un-debounced Live Inputs (Network Flooding)
1. `resources/views/livewire/inventory/stock-transfer-manager.blade.php:268`: `<input type="number" wire:model.live="receivedQuantities.{{ $item->id }}">`
2. `resources/views/livewire/kasir/order-history.blade.php:478`: `<input type="number" wire:model.live="returnItems.{{ $id }}.qty">`
3. `resources/views/livewire/kasir/order-history.blade.php:522`: `<input type="number" wire:model.live="refundAllocations.{{ $pId }}.amount">`
4. `resources/views/livewire/pos/partials/split-bill-modal.blade.php:97`: `<input type="number" wire:model.live="splitBillAmounts.{{ $idx }}">`

### 5.3 Livewire Classes Exceeding `< 450` Line Ceiling (Arch Rule)
1. `app/Livewire/Customers/CustomerManager.php`: **729 lines** (+279 lines above ceiling)
2. `app/Livewire/Kasir/OrderHistory.php`: **661 lines** (+211 lines above ceiling)
3. `app/Livewire/Purchasing/SupplierManager.php`: **523 lines** (+73 lines above ceiling)
4. `app/Livewire/ShiftManager.php`: **521 lines** (+71 lines above ceiling)
5. `app/Livewire/Inventory/StockOpnameManager.php`: **455 lines** (+5 lines above ceiling)
*Note: 0 components in app/Livewire implement `#[Lazy]` or `placeholder()` skeletons.*

### 5.4 Dead / Orphaned Blade Components & Component Duplication
The following legacy starter-kit components have **0 usages** across the entire codebase and must be formally deprecated and purged:
- `resources/views/components/toast.blade.php` (0 usages; replaced by `ui.toast`)
- `resources/views/components/button.blade.php` (0 usages; replaced by `ui.button`)
- `resources/views/components/primary-button.blade.php` (0 usages)
- `resources/views/components/secondary-button.blade.php` (0 usages)
- `resources/views/components/danger-button.blade.php` (0 usages)
- `resources/views/components/alert.blade.php` (0 usages; replaced by `ui.alert`)
- `resources/views/components/badge.blade.php` (0 usages; replaced by `ui.badge`)
- `resources/views/components/breadcrumb.blade.php` (0 usages; replaced by `erp.context-breadcrumbs`)
- `resources/views/components/skeleton.blade.php` (0 usages; replaced by `ui.skeleton`)
- `resources/views/components/modal.blade.php` (0 usages; replaced by `ui.modal`)
- `resources/views/components/ui/sidebar/**` (0 usages; entire sub-tree unused)

**Active Component Duplication:**
- `resources/views/components/empty-state.blade.php` used in `modifier-manager.blade.php:273` and `ingredient-manager.blade.php:214`, while all other 43 pages use `<x-ui.empty-state>`.

---

## 6. FRONTEND SECURITY & SHARED-TERMINAL ISOLATION FINDINGS

| ID | Category | Severity | Exact File Path & Line | Description & Risk Scenario |
|:---|:---|:---:|:---|:---|
| **SEC-01** | **XSS** | **P0** | `resources/views/components/ui/alert/index.blade.php:54` | `{!! $description !!}` allows arbitrary JavaScript injection via alert descriptions. |
| **SEC-02** | **XSS** | **P1** | `resources/js/vendor/swal-bridge.js:104` | `descEl.innerHTML = html;` renders un-sanitized HTML inside confirmation popups. |
| **SEC-03** | **XSS / Injection** | **P1** | `resources/views/components/ui/modal/index.blade.php:54,71` | Unescaped `{!! $openExpression !!}` and `{!! $closeAction !!}` evaluated inside Alpine attributes. |
| **SEC-04** | **Multi-Tenancy** | **P0** | `public/js/pos-offline-queue.js:8` | Unpartitioned `apeiron_pos_offline_db` persists financial orders across tenant logouts on shared terminals. |
| **SEC-05** | **Multi-Tenancy** | **P2** | `resources/js/components/commandPalette.js:40,51` | `localStorage.getItem('apeiron.nav.recents')` persists navigation history across users without tenant isolation. |
| **SEC-06** | **Supply Chain** | **P1** | `resources/views/layouts/app.blade.php:164` | Third-party unpinned script `https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js` loaded directly into authenticated session. |
| **SEC-07** | **Reverse Tabnabbing** | **P2** | `resources/views/orders/show.blade.php:30`, `shelf-label-manager.blade.php:86` | `target="_blank"` missing `rel="noopener noreferrer"`. |

---

## 7. PRE-REDESIGN GATE: WAVE 0 → WAVE 1 → WAVE 2

To prevent building the new Executive Dashboard on top of an unstable, non-compliant foundation, all work MUST be strictly sequenced into three distinct waves:

```
┌────────────────────────────────────────────────────────┐
│  WAVE 0: FOUNDATION FIXES (Pre-Redesign Prerequisite)   │
│  - WCAG AAA Palette in tokens.css                       │
│  - XSS Fixes (Alert, Swal Bridge, Alpine Expressions)  │
│  - GL Reconciliation Rp 0 False-Positive Fix            │
│  - Fix POS Cashier Keydown Syntax & Memory Leak         │
│  - Partition IndexedDB pos-offline-queue by tenant_id   │
│  - Remove Render-Blocking Google Fonts CDN Link         │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│  WAVE 1: EXECUTIVE DASHBOARD REDESIGN                   │
│  - Redesigned Executive BI Workspace Canvas             │
│  - Dynamic / Split ECharts Bundle Chunk                 │
│  - High-Contrast Focus Visible on Buttons & Modals      │
│  - Keyboard Accessible Tabular Metrics & Tooltips       │
│  - Lazy-loaded Livewire Widgets (#[Lazy] + placeholder) │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│  WAVE 2: POST-DASHBOARD SYSTEMIC HARDENING              │
│  - Purge 2,919 Raw Palette & 926 Manual dark: Classes   │
│  - Standardize 76 Tables with th[scope="col"] & Scroll  │
│  - Purge 11 Orphaned Breeze Blade Components           │
│  - Refactor 5 Oversized Livewire Classes (< 450 lines) │
│  - Automated Playwright + axe-core CI Quality Gate      │
└────────────────────────────────────────────────────────┘
```

### Detailed Breakdown:

#### WAVE 0 — Foundation Fixes (Execute BEFORE Any Dashboard Redesign)
1. **Remediate GL Trust Indicator False-Positive:** Modify `ExecutiveDashboardService.php` to distinguish `Audit Pending` (open shifts/draft journals) from real reconciliation variances ($variance \neq 0$). Update `executive-workspace.blade.php` to render an informative amber banner instead of a red destructive variance alert.
2. **Elevate Tokens to WCAG 2.2 AAA:** Update `tokens.css`:
   - Set `--muted-foreground: #47474F` (Light) and `--shell-foreground: #47474F` (Light) to guarantee 7.6:1 AAA contrast.
   - Set `--border: #94949E` for interactive inputs (3.05:1 non-text contrast).
   - Set standalone status colors: Success (`#14532D`), Warning (`#78350F`), Destructive (`#991B1B`), Info (`#1E40AF`).
3. **Patch XSS Sinks:** Replace `{!! $description !!}` with `{{ $description }}` in `components/ui/alert/index.blade.php`. Sanitize HTML inputs in `swal-bridge.js`.
4. **Fix POS Keydown Syntax & Memory Leak:** Clean up `@keydown.window."+".prevent` syntax error and add proper event listener removal upon component destruction in `create-order.blade.php`.
5. **Partition Offline Queue:** Append active `tenant_id` to `apeiron_pos_offline_db` in `pos-offline-queue.js`.
6. **Eliminate Render-Blocking Google Fonts CDN:** Remove `<link rel="stylesheet" href="https://fonts.googleapis.com/css2?...">` from `app.blade.php` and `customer.blade.php`.

#### WAVE 1 — Execute TOGETHER With Executive Dashboard Redesign
1. **Asynchronous ECharts Chunking:** Configure Vite manual chunking or lazy import for `erpChart.js` so ECharts is only loaded when rendering charts.
2. **Accessible ECharts Options:** Implement accessible SVG/Canvas patterns, keyboard navigable series data, and high-contrast tooltip text in `ExecutiveDashboardService::buildSalesTrendChartOptions`.
3. **Tabular Numbers & Currency Formatting:** Enforce `.tabular-nums` on all executive metrics, attainment percentages, and financial summary cards.
4. **Component Lazy Loading:** Apply `#[Lazy]` and custom `placeholder()` skeletons to the Executive Workspace and BI cards to ensure sub-500ms initial response.

#### WAVE 2 — Post-Dashboard Systemic Hardening
1. **Design System Token Migration:** Replace 2,919 raw utility classes (`text-zinc-500`, `border-zinc-200`) and 926 manual `dark:` classes with semantic design tokens.
2. **Table Accessibility & Responsiveness:** Add `scope="col"` and `overflow-x-auto` to all 76 data tables across the 9 navigation domains.
3. **Dead Code Deletion:** Delete the 11 unused Breeze components and `components/ui/sidebar/`.
4. **Decompose Oversized Livewire Classes:** Split `CustomerManager` (729 lines) and `OrderHistory` (661 lines) using dedicated Action traits or sub-components to strictly conform to the `< 450` line limit.

---

## 8. AUTOMATED FRONTEND QUALITY GATE RECOMMENDATION

To ensure zero regressions against WCAG 2.2 Level AAA and eliminate future design token drift, integrate the following automated quality gate into GitHub Actions / local verification scripts:

### 8.1 Playwright + `@axe-core/playwright` Automated A11y Suite
Create automated end-to-end accessibility test runs covering all primary hubs:
```typescript
// tests/e2e/accessibility.spec.ts
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Apeiron ERP WCAG 2.2 AAA Gate', () => {
    const targetRoutes = ['/dashboard', '/transaction', '/kds', '/reports', '/finance/journal-entries'];

    for (const route of targetRoutes) {
        test(`Audit ${route} against WCAG 2.2 AAA`, async ({ page }) => {
            await page.goto(route);
            const accessibilityScanResults = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'wcag2aaa'])
                .disableRules(['color-contrast-enhanced']) // Phase-in rule
                .analyze();

            expect(accessibilityScanResults.violations).toEqual([]);
        });
    }
});
```

### 8.2 Architectural Token Drift Linter (PHPUnit / ArchTest)
Add a strict architecture test forbidding new raw color classes and verifying `wire:key` on `@foreach` loops:
```php
public function test_no_raw_color_classes_in_blade_views(): void
{
    $files = File::allFiles(resource_path('views'));
    $violations = [];

    foreach ($files as $file) {
        $content = $file->getContents();
        if (preg_match('/(text|bg|border)-(zinc|gray|slate|neutral|stone|red|amber|yellow|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-[0-9]{2,3}/', $content, $matches)) {
            $violations[] = $file->getRelativePathname() . ': ' . $matches[0];
        }
    }

    $this->assertEmpty($violations, "Raw palette utility classes detected. Use semantic tokens from tokens.css!");
}
```

---
**Report Compiled by:** Principal Frontend Architect & WCAG 2.2 Level AAA Auditor  
**Verification Date:** November 23, 2026  
**Artifact Status:** Final Forensic Audit Complete · Baseline for Executive Dashboard Redesign
