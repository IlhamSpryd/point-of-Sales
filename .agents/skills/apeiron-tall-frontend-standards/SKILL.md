---
name: apeiron-tall-frontend-standards
description: Enforces Apeiron Systems frontend standards across Blade, Livewire 3/4, Alpine.js 3, Tailwind CSS semantic tokens, WCAG 2.2 AAA accessibility, multi-tenant offline PWA, POS touch ergonomics, and Laravel Reverb KDS real-time events. Trigger whenever writing, modifying, auditing, or refactoring Blade templates, Livewire components, Alpine.js interactions, POS cashier views, offline caching, or frontend styles in Apeiron Systems.
---

# Apeiron TALL Frontend Standards

MANDATORY standards for all Blade, Livewire, Alpine.js, Tailwind CSS, POS offline PWA, and KDS real-time UI engineering in Apeiron Systems. Sourced from `docs/audit/APEIRON_FRONTEND_GLOBAL_STANDARD_AUDIT.md`.

## 1. WCAG 2.2 Level AAA & Semantic Token Law
- **Design Tokens Only:** Exclusively use semantic CSS variables defined in `resources/css/tokens.css` (`bg-background`, `text-foreground`, `bg-card`, `border-border`, `text-muted-foreground`, `bg-primary`, `text-primary-foreground`, `bg-accent`, `ring-ring`). Tokens auto-invert across Light/Dark modes.
- **Strict Color Prohibitions:**
  - FORBIDDEN: Raw palette utility classes (`text-zinc-*`, `bg-gray-*`, `border-emerald-*`, `bg-blue-*`, etc.).
  - FORBIDDEN: Manual `dark:` overrides in Blade (`dark:bg-gray-800`, `dark:text-white`). Theme inversion is token-driven.
  - FORBIDDEN: Arbitrary hex colors (`text-[#...]`, `bg-[#...]`, `border-[#...]`).
- **Contrast Ratios:** Normal text contrast must be $\ge 7.0:1$ (AAA standard); input borders and graphical controls must be $\ge 3.0:1$.
- **Tabular Figures:** Mandatory `tabular-nums` on 100% of monetary values (`Rp`), percentages, timestamps, quantities, and numeric table cells to prevent layout jitter.

## 2. Livewire 3/4 Hygiene & Architectural Ceiling
- **Key Binding:** Mandatory unique `wire:key` on EVERY interactive element and item in `@foreach` loops (e.g., `wire:key="order-item-{{ $item->id }}"`). Never omit `wire:key` in tables or dynamic lists.
- **Input Debouncing:** Mandatory `.debounce.300ms` or `.blur` on all numeric, search, and live-updating inputs (e.g., `wire:model.live.debounce.300ms="search"`). Never leave raw unrestricted live inputs.
- **Architectural Line Ceiling:** Livewire component classes must stay strictly `< 450` lines of code (enforced by `tests/Arch/`). Extract complex logic into dedicated domain services or form objects.
- **Lazy Loading:** Use `#[Lazy]` with an explicit `placeholder()` method for heavy analytical dashboards, charts, and secondary metric widgets.

## 3. Alpine.js 3 & SPA Lifecycle Safety
- **Event & Timer Teardown:** Every global event listener (`window`, `document`) and timer (`setInterval`, `setTimeout`) initialized in `x-init` MUST retain its reference and register cleanup via `$cleanup(() => ...)` and `livewire:navigating` listeners to prevent catastrophic memory leaks during SPA navigation (`wire:navigate`).
- **Directive Syntax Safety:**
  - FORBIDDEN: Unquoted special characters or double quotes in event directives (e.g., `@keydown.window."+"` is invalid HTML). Use keycodes or properly escaped event handlers (`@keydown.window="handleShortcut($event)"`).
- **XSS & Injection Protection:**
  - FORBIDDEN: Unescaped raw Blade output `{!! $description !!}` or `{!! $var !!}` for dynamic text, titles, descriptions, or user data. Use escaped `{{ $var }}`.
  - FORBIDDEN: Concatenating unescaped PHP into Alpine expressions (`x-data="{ val: {{ $var }} }"`). Always use `@js($var)` or `json_encode()`.
  - FORBIDDEN: `innerHTML` assignment in custom JS. Always use `textContent` or sanitized DOM node creation.

## 4. Table, Navigation & Touch Ergonomics
- **Accessible Data Tables:** Every data table must include explicit column scopes (`<th scope="col">`) on headers and must be wrapped in an `overflow-x-auto` container to eliminate horizontal viewport clipping on tablets and mobile displays.
- **Touch Targets:** Interactive controls on POS cashier screens, tablet views, and mobile interfaces must meet minimum target dimensions of `min-h-[44px] min-w-[44px]` (WCAG 2.5.5 / 2.5.8).
- **Navigation Architecture:** Maintain the canonical 9x19x45 navigation matrix (`NavigationRegistry` and tests G-1 through G-6).
- **No Orphan Starter Components:** Reject legacy Breeze starter components (e.g. `x-input-error`, `x-text-input`, `x-primary-button` from Breeze); use canonical Apeiron UI components (`x-ui.*` / `x-erp.*`).

## 5. Multi-Tenant Offline PWA & Reverb Real-Time
- **Fail-Closed IndexedDB Isolation:** IndexedDB instances MUST be tenant-partitioned: `apeiron_pos_tenant_${tenantId}`. On user logout, session expiry, or tenant mismatch, immediately close and purge offline DB connections to prevent cross-tenant order leakage on shared POS registers.
- **LocalStorage Partitioning:** All browser storage (recents, cached filters, UI preferences) must partition keys by `${tenantId}_${userId}_key`.
- **Zero External CDNs:** Fonts (`woff2`), icons, and external libraries (such as QZ Tray) must be self-hosted in `public/`. External render-blocking CDN links (`fonts.googleapis.com`, `cdn.jsdelivr.net`) are strictly prohibited.
- **Laravel Reverb KDS Safety:** Real-time listeners (such as Kitchen Display System order streams) must display visual connection status badges (Connected / Reconnecting / Offline) and must execute clean channel unsubscriptions upon component teardown/navigation.
