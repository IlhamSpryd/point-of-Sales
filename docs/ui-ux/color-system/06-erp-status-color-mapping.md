# 06 — ERP Status Color Mapping

> **One vocabulary, every module.** A status means the same color in Finance as it does in
> Purchasing as it does in HR. This document is the canonical status → variant table; the
> executable form is `StatusPalette` in `04-semantic-token-registry.md` §4.

---

## 1. The Five Variants

| Variant | Color | Semantic meaning | Never means |
|---|---|---|---|
| `success` | green | **Done and good.** Terminal, accepted, complete, paid, in-stock, balanced | "positive number" in a chart |
| `warning` | amber | **Needs attention, not yet wrong.** Pending, partial, low, approaching a limit | failure |
| `destructive` | red | **Bad or stopped.** Failed, cancelled, rejected, void, out-of-stock, unbalanced | "high priority" |
| `info` | blue | **Informational / in-flight.** Sent, issued, confirmed, in transit, released | success, "new" |
| `neutral` | grey | **No decision yet / not applicable.** Draft, archived, closed, refunded, unknown | disabled |

### The four distinctions that matter

1. **`warning` ≠ `destructive`.** `pending_approval` is amber. `rejected` is red.
   A queue of amber badges is normal; a queue of red badges is an incident.
2. **`draft` is neutral, not warning.** A draft is a deliberate work state, not a problem.
   Today `PurchaseOrderStatusEnum::Draft` is `gray-100` (neutral ✅) while
   `badgeType()` has no `draft` branch and falls back to `secondary` — consistent by accident,
   not by design. Make it explicit.
3. **`info` ≠ `success`.** `released`/`issued` means the document left the building,
   not that it was accepted. Presently `PurchaseOrderStatusEnum::Approved` is **blue** in
   `badgeClass()` but **success** in `badgeType()` — a real bug. `approved` → **success**.
4. **`closed` is neutral.** Closed is a completed lifecycle, not an error and not a success.

---

## 2. Canonical Status Table

The complete map. Any status not listed here resolves to `neutral` and **must** be added to
the registry before use.

### 2.1 Purchase / Procurement

| Status string | Variant | Rationale |
|---|---|---|
| `draft` | neutral | Work in progress |
| `draft_approval` | warning | Sent for review |
| `pending_approval` | warning | Awaiting decision |
| `approved` | success | Accepted by authority |
| `released` | info | Issued to supplier |
| `sent` | info | Transmitted |
| `confirmed` | info | Supplier acknowledged |
| `partially_received` | warning | Incomplete receipt |
| `received` | success | Fully Received |
| `closed` | neutral | Lifecycle complete |
| `amended` | neutral | Superseded (chips on the *old* row) |
| `rejected` | destructive | Refused |
| `cancelled` | destructive | Aborted by user |
| `void` / `voided` | destructive | Retracted post-hoc |

### 2.2 Orders / POS

| Status string | Variant |
|---|---|
| `pending` | warning |
| `paid` | success |
| `completed` | success |
| `failed` | destructive |
| `cancelled` | destructive |
| `expired` | destructive |
| `void` / `voided` | destructive |
| `refunded` | neutral |
| `partially_paid` | warning |
| `captured` | success |

> `refunded` is **neutral**, not destructive — a refund is a completed, legitimate operation.
> Destructive would imply something went wrong.

### 2.3 Inventory

| Status string | Variant | Rationale |
|---|---|---|
| `in_stock` | success | Above reorder point |
| `low_stock` | warning | Below reorder point |
| `out_of_stock` | destructive | Zero available |
| `overstock` | info | Above max par |
| `in_transit` | info | Moving between locations |
| `counting` | warning | Opname in progress |
| `adjusted` | info | Corrected |
| `variance_flagged` | destructive | Discrepancy over threshold |
| `expiring_soon` | warning | Shelf-life warning |
| `expired` | destructive | Unusable |

**Combine with icon and text.** Stock rows must show `<badge>Low stock</badge>` **and**
the numeric quantity, plus a bar. Color communicates priority; the number communicates fact.

### 2.4 Finance

| Status string | Variant | Rationale |
|---|---|---|
| `posted` | success | Posted to the ledger (final) |
| `draft` | neutral | Journal draft |
| `unposted` / `pending_post` | warning | Awaiting posting |
| `balanced` | success | Debits = credits |
| `unbalanced` | destructive | Out of balance — blocking |
| `matched` | success | 3-way match ok |
| `unmatched` | warning | Awaiting match |
| `partially_paid` | warning | |
| `paid` | success | |
| `unpaid` | destructive | Overdue obligation |
| `overdue` | destructive | Past due date |
| `settled` | success | |
| `disputed` | destructive | |
| `reconciled` | success | |
| `unreconciled` | warning | |
| `reversed` | neutral | Reversed by counter-entry — **a completed, correct action** |
| `void` / `voided` | destructive | |

**Debit / credit:** these are **not** statuses and must not be badges. They are table columns.
If visual differentiation is needed at all, use **column position** (debit | credit) and
`tabular-nums`. Under no circumstances `text-destructive` for debit — debit is not bad news.

### 2.5 HR

| Status string | Variant |
|---|---|
| `active` | success |
| `inactive` | neutral |
| `probation` | info |
| `suspended` | destructive |
| `present` | success |
| `late` | warning |
| `absent` | destructive |
| `on_leave` | info |
| `approved` (leave) | success |
| `pending` (leave) | warning |
| `payroll_draft` | neutral |
| `payroll_processed` | info |
| `payroll_paid` | success |

### 2.6 Approval / Workflow (cross-module)

| Status string | Variant |
|---|---|
| `open` | info |
| `in_progress` | info |
| `on_hold` | warning |
| `blocked` | destructive |
| `escalated` | destructive |
| `completed` | success |
| `cancelled` | destructive |

---

## 3. Module-Specific Rulings

### 3.1 POS

| Element | Color rule |
|---|---|
| Cart surface | `bg-card` (neutral). Never tinted by payment state. |
| Order status chip | `StatusPalette` badge in the cart header |
| Selected product tile | `border-foreground` (2px) + `bg-accent`. **Not** a colored border |
| Discount indicator | `text-warning` text + a small tag icon + the discount amount. Amount is mandatory — a discount shown only in amber is unauditable |
| Payment confirmation | `bg-success-muted border-success-border` panel with `text-success` check icon and `text-foreground` amount |
| Failed transaction | `bg-destructive-muted border-destructive-border`, `text-destructive` icon, actionable message |
| Split payment legs | Each leg gets a **neutral** row; only the aggregate status is colored |
| Held/parked orders | `info` badge ("Parked") |
| Cash drawer open | `warning` dot in the header (ambient status) |
| Offline mode | `warning` badge, never red — offline POS keeps working, which is not a failure |

### 3.2 Inventory

| Element | Color rule |
|---|---|
| Stock level cell | Badge (variant as §2.3) **+** numeric quantity |
| Stock bar | Track `bg-muted`; fill `bg-foreground` normally, `bg-warning` ≤ reorder, `bg-destructive` = 0 |
| Movement type | **Not colored.** Direction shown by `+`/`−` and arrow icon |
| Adjustment reason | **Not colored.** It is a category, not a status |
| Expiration | `warning` inside 30 days, `destructive` past date — always with the date printed |

### 3.3 Finance

| Element | Color rule |
|---|---|
| Debit/Credit | **No color.** Separate columns |
| Amounts | `tabular-nums`, `text-foreground`; negative amounts shown as `text-destructive` only when they represent an actual loss/correction, not for every credit |
| Journal status | badge per §2.4 |
| Reconciliation | `success` when matched, `warning` when unmatched, `destructive` when variance > tolerance |
| Financial warning banner | `bg-warning-muted` alert |
| Reversed journals | `neutral` badge "Reversed" + `line-through` on the superseded amount |
| Posted vs draft | badge, **not** row tinting |

### 3.4 Purchasing

Per §2.1. The single rule that matters: `approved` = success (green), **not** blue.
The current `PurchaseOrderStatusEnum::badgeClass()` returns `bg-blue-100` for `Approved` while
`badgeType()` does not implement approval at all — both are wrong relative to this spec.

### 3.5 HR

Per §2.5. Attendance colors appear only in the attendance table, never as avatar ring colors
or calendar tile fills — a calendar of red/green/amber tiles is the "colorful dashboard" the
brief forbids.

---

## 4. Combining Color With Non-Color Cues

**Hard requirement (WCAG 1.4.1).** Every status rendering must include at least one non-color
signal:

| Mechanism | Where |
|---|---|
| Text label | Always — badges always render text |
| Status dot | Badges (additive), ambient indicators |
| Icon | Alerts, toasts, validation, payment result |
| Position | Debit/Credit columns |
| Numeric value | Stock levels, discount amounts |
| Strikethrough | Superseded/reversed amounts |

A badge with `dot` and no label is a violation. A red row with no badge is a violation.

---

## 5. Consistency Guarantees

After `StatusPalette` lands, these invariants must hold and should be unit-tested:

| ID | Invariant | Test |
|---|---|---|
| **ST-1** | `StatusPalette::variant('paid')` is identical regardless of caller module | unit test |
| **ST-2** | No component contains a `match()` over status strings | `rg "match.*=> 'success'"` in views/components → 0 |
| **ST-3** | Every enum health status resolves to a non-neutral variant, or is explicitly declared neutral | reflective test over all `app/Enums` statuses |
| **ST-4** | `badgeClass()` no longer exists in any enum | `rg 'function badgeClass' app/Enums` → 0 |
| **ST-5** | No status color is applied to an icon-only visual with no label | manual a11y review |

**Suggested test (illustrative, not implemented):**

```php
it('resolves one canonical variant per status string', function (string $status, string $expected) {
    expect(StatusPalette::variant($status)->value)->toBe($expected);
})->with([
    ['paid', 'success'], ['pending_approval', 'warning'], ['void', 'destructive'],
    ['released', 'info'], ['draft', 'neutral'], ['approved', 'success'],
]);
```

---

## 6. Forbidden Status Patterns (currently present in the codebase)

| Pattern | Where found | Replacement |
|---|---|---|
| Per-status bespoke class strings in an enum | 3 enums, 60 hits | `StatusPalette::badgeClasses()` |
| Four divergent `match()` cascades | `ui/badge`, `erp/status-badge`, `status-text`, `x-badge` | one class |
| Tinted table rows instead of badges | purchasing/HR managers | badge cell |
| `dark:bg-{color}-900/40` badge tints | `PurchaseOrderStatusEnum::badgeClass()` | `-muted` tokens |
| Icon-only status dots on tables | dashboard lists | dot + label |
| Status color on a whole card border | various | badge or alert only |
| Invented statuses (`' converted'` with a leading space) | `erp/status-badge.blade.php` | cleaned in `StatusPalette::MAP` |
