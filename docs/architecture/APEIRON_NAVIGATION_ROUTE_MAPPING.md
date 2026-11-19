# APEIRON NAVIGATION → ROUTE MAPPING (TRACEABLE)

## Companion to `APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md`

| Field       | Value                                                                                                           |
| ----------- | --------------------------------------------------------------------------------------------------------------- |
| Document ID | `ARCH-NAV-002`                                                                                                  |
| Version     | 1.0.0 (Proposed)                                                                                                |
| Status      | Traceability artefact — **no implementation performed**                                                         |
| Purpose     | One row per registered destination, showing **where it lives today** and **where it must live** after migration |
| Repository  | `/home/ilhamspryd/Projects/apeironSystem/point-of-Sales`                                                        |

> **How to read this file.** Section A is a flat, alphabetically sorted table keyed by **route name** (the one identifier that cannot change without breaking the app). Section B is the inverse view keyed by **menu ID** (the identifier that must never change). Section C lists every route that is deliberately _not_ a menu destination and why. Section D is the "moves only" delta list an implementation agent can execute mechanically.

---

## A. ROUTE → MENU (authoritative lookup)

Legend:

- **Today** — where a user can find it right now: `S1` sidebar, `S3` module sub-nav, `S5` palette, `S7` user popover, `—` no surface (orphan), `detail` reached from a parent page, `guest` separate shell.
- **Proposed Menu ID** — see blueprint §10.1.
- **Δ** — `=` unchanged placement, `MOVE` new parent/hub, `RENAME` label only, `MERGE` becomes a tab, `PROMOTE` from orphan, `NEWPLACE` newly added to nav.
- **Status** — `FI` fully implemented, `PI` partially implemented, `GUEST` non-staff shell, `SYS` system/auth, `DETAIL` resource detail.

| Route name                        | URI                                | Guard (`role:`)                              | Today                       | Proposed Menu ID                             | Proposed Parent     | Domain            | Δ                   | Status |
| --------------------------------- | ---------------------------------- | -------------------------------------------- | --------------------------- | -------------------------------------------- | ------------------- | ----------------- | ------------------- | ------ |
| `activity-logs.index`             | `/activity-logs`                   | Owner,Manager                                | S1 (Settings)               | `nav.admin.governance.audit_logs`            | Audit & Approvals   | D9 Administration | MOVE                | FI     |
| `analytics.restock-forecasts`     | `/api/analytics/restock-forecasts` | Owner,Manager,Inventory + `restock_forecast` | — (API)                     | — (not a page; consumed by dashboard widget) | —                   | —                 | —                   | SYS    |
| `api.order.print-kitchen-payload` | `/api/orders/…`                    | Owner,Manager,Kasir                          | —                           | —                                            | —                   | SYS               |
| `api.order.print-payload`         | `/api/orders/…`                    | Owner,Manager,Kasir                          | —                           | —                                            | —                   | SYS               |
| `api.order.sync-status`           | `/api/orders/…`                    | Owner,Manager,Kasir                          | —                           | —                                            | —                   | SYS               |
| `api.v1.pos.sync-offline-orders`  | `/api/v1/pos/sync-offline-orders`  | Owner,Manager,Kasir                          | —                           | —                                            | —                   | SYS               |
| `assortments.index`               | `/assortments`                     | Owner,Manager,Inventory                      | S1 + S3(catalog)            | `nav.catalog.master.assortment`              | Product Catalog     | D3                | =                   | FI     |
| `assortments.update`              | `/assortments` (POST)              | Owner,Manager,Inventory                      | —                           | —                                            | —                   | SYS               |
| `brands.index`                    | `/brands`                          | Owner,Manager,Inventory                      | S3(catalog)                 | `nav.catalog.master.brands`                  | Product Catalog     | D3                | =                   | FI     |
| `categories.index`                | `/categories`                      | Owner,Manager,Inventory                      | S3(catalog)                 | `nav.catalog.master.categories`              | Product Catalog     | D3                | =                   | FI     |
| `confirm-password`                | `/confirm-password`                | auth                                         | —                           | —                                            | —                   | SYS               |
| `customer.cart.*`                 | `/cart/*`                          | guest + throttle                             | guest                       | —                                            | —                   | GUEST             |
| `customer.checkout.*`             | `/checkout/*`                      | guest + throttle                             | guest                       | —                                            | —                   | GUEST             |
| `customer.menu.*`                 | `/menu/{token}`                    | guest + `table.token`                        | guest                       | —                                            | —                   | GUEST             |
| `customer.waiter.call`            | `/waiter-call`                     | guest + throttle                             | guest                       | —                                            | —                   | GUEST             |
| `customers.credit.index`          | `/customers/credit`                | Owner,Manager,Finance,Sales                  | S3(customers)               | `nav.sales.customers.credit`                 | Customers & Loyalty | D5                | =                   | FI     |
| `customers.groups.index`          | `/customers/groups`                | Owner,Manager                                | S3(customers)               | `nav.sales.customers.groups`                 | Customers & Loyalty | D5                | =                   | FI     |
| `customers.index`                 | `/customers`                       | Owner,Manager                                | S1 ("Customers & CRM") + S3 | `nav.sales.customers.directory`              | Customers & Loyalty | D5                | MOVE (section → D5) | FI     |

> **Role-guard note (IMPORTANT for Phase 5).** `routes/customers.php:18-23` guards `customers.index`, `customers.groups.index` and `customers.instruments.index` with `role:Owner,Manager`, while `RoleService:208` grants the `Sales` role `customers.*` and `sub_nav.customers` (`config/navigation.php:531,547,555`) lists `Sales` only on `customers.credit`. The registry must mirror the **middleware** (Owner,Manager) — the `Sales` persona therefore cannot see Customer Directory after Phase 5 unless the route guard is widened. This is a **route change**, deliberately out of scope for the navigation initiative; it is recorded as OQ-19 and shown in the §7.3 target contract as an open dependency rather than silently assumed.
> | `customers.instruments.index` | `/customers/instruments` | Owner,Manager | S3(customers) | `nav.sales.customers.loyalty` | Customers & Loyalty | D5 | RENAME ("Loyalty & Gift Cards") | FI |
> | `dashboard` | `/dashboard` | Owner,Manager (+ dashboard permission fallback) | S1 + S5 | `nav.overview.dashboard` | — (hub) | D1 Overview | = | FI |
> | `devices.index` | `/devices` | Owner,Manager | S1 (Settings) + S3(organization) | `nav.admin.org.devices` | Organization | D9 | = | FI |
> | `discounts.index` | `/discounts` | Owner,Manager | S1 ("Discounts & Promotions") + S3(pricing) | `nav.catalog.pricing.discounts` | Pricing & Promotions | D3 | MOVE (→D3) | FI |
> | `erp.workflow.approvals` | `/workflow/approvals` | Owner,Manager | S1 (Settings) | `nav.admin.governance.approvals` | Audit & Approvals | D9 | MOVE | FI |
> | `exports.download` | `/exports/{task}/download` | Owner,Manager + `async_export` | — | — | — | — | — | SYS |
> | `exports.status` | `/exports/{task}/status` | Owner,Manager + `async_export` | — | — | — | SYS |
> | `finance.coa.index` | `/finance/chart-of-accounts` | Owner,Manager,Finance,Admin | S3(finance) | `nav.finance.gl.coa` | Finance & Accounting | D6 | = | FI |
> | `finance.fixed-assets.index` | `/finance/fixed-assets` | Owner,Manager,Finance,Admin | **— (ORPHAN)** | `nav.finance.gl.fixed_assets` | Finance & Accounting | D6 | **PROMOTE** | FI |
> | `finance.index` | `/finance` | Owner,Manager,Finance,Admin | S1 + S3 | `nav.finance.gl.overview` | Finance & Accounting | D6 | = | FI |
> | `finance.journals.index` | `/finance/journal-entries` | Owner,Manager,Finance,Admin | S3(finance) | `nav.finance.gl.journals` | Finance & Accounting | D6 | = | FI |
> | `finance.periods.index` | `/finance/accounting-periods` | Owner,Manager,Finance,Admin | S3(finance) | `nav.finance.gl.periods` | Finance & Accounting | D6 | = | FI |
> | `finance.reconciliation.index` | `/finance/bank-reconciliation` | Owner,Manager,Finance,Admin | S3(finance) | `nav.finance.gl.reconciliation` | Finance & Accounting | D6 | = | FI |
> | `health.live` | `/health/live` | none | — | — | — | SYS |
> | `health.ready` | `/health/ready` | none | — | — | — | SYS |
> | `home` | `/home` | auth + tenant | — (redirect only) | — (resolver) | — | — | — | SYS |
> | `hr.attendance.index` | `/hr/attendance` | Owner,Manager | S1 + S3(hr) | `nav.workforce.people.attendance` | Workforce & Payroll | D7 | MERGE (L1→L2) | FI |
> | `hr.commissions.index` | `/hr/commissions` | Owner,Manager | S1 + S3(hr) | `nav.workforce.people.commissions` | Workforce & Payroll | D7 | MERGE | PI |
> | `hr.employees.index` | `/hr/employees` | Owner,Manager | S1 + S3(hr) | `nav.workforce.people.employees` | Workforce & Payroll | D7 | MERGE | FI |
> | `hr.index` | `/hr` | Owner,Manager | — (302 → employees) | — | — | — | — | SYS |
> | `hr.leaves.index` | `/hr/leaves` | Owner,Manager | S1 + S3(hr) | `nav.workforce.people.leaves` | Workforce & Payroll | D7 | MERGE | FI |
> | `hr.payroll.index` | `/hr/payroll` | Owner,Manager | S1 + S3(hr) | `nav.workforce.people.payroll` | Workforce & Payroll | D7 | MERGE | FI |
> | `hr.schedules.index` | `/hr/schedules` | Owner,Manager | S1 + S3(hr) | `nav.workforce.people.schedules` | Workforce & Payroll | D7 | MERGE | FI |
> | `integrations.channel-mapping` | `/integrations/channel-mapping` | Owner,Manager + `omnichannel` | S3(pos-ops) + S3(organization) | `nav.frontline.desk.channel` **and** `nav.admin.org.channel` | Frontline Desk / Organization | D2 + D9 | MOVE (**SC-05** dual placement) | PI |
> | `inventory.adjustments` | `/inventory/adjustments` | Owner,Manager,Inventory | S3(inventory) | `nav.supply.inventory.adjustments` | Inventory | D4 | = | FI |
> | `inventory.goods-receipts` | `/inventory/goods-receipts` | Owner,Manager,Inventory | S1 ("Stock Operations") + S3 | `nav.supply.inventory.direct_receipts` | Inventory | D4 | RENAME ("Direct Receipts") | FI |
> | `inventory.ingredients` | `/inventory/ingredients` | Owner,Manager,Inventory | S1 + S3 | `nav.supply.inventory.stock` | Inventory | D4 | = | FI |
> | `inventory.ledger` | `/inventory/ledger` | Owner,Manager,Inventory | S1 + S3 | `nav.supply.inventory.ledger` | Inventory | D4 | = | PI |
> | `inventory.modifiers` | `/inventory/modifiers` | Owner,Manager,Inventory | S3(catalog) | `nav.catalog.master.modifiers` | Product Catalog | D3 | = (namespace debt OQ-06) | FI |
> | `inventory.opnames` | `/inventory/opnames` | Owner,Manager,Inventory | S3(inventory) | `nav.supply.inventory.opnames` | Inventory | D4 | = | FI |
> | `inventory.supplier-returns` | `/inventory/supplier-returns` | Owner,Manager,Inventory | S3(inventory) | `nav.supply.inventory.direct_returns` | Inventory | D4 | RENAME ("Direct Supplier Returns") | FI |
> | `inventory.transfers` | `/inventory/transfers` | Owner,Manager,Inventory | S3(inventory) | `nav.supply.inventory.transfers` | Inventory | D4 | = | FI |
> | `invitations.accept` | `/invitations/{token}/accept` | throttle | — | — | — | SYS |
> | `invitations.process-accept` | (POST) | throttle | — | — | — | SYS |
> | `kasir` | `/kasir` | — (301 → /transaction) | — | — | — | SYS |
> | `kds.index` | `/kds` | Owner,Manager,Kasir,Barista,Waiter,Cook | S1 + S3(pos-ops) | `nav.frontline.desk.kds` | Frontline Desk | D2 | = | FI |
> | `login` | `/login` | guest | — | — | — | SYS |
> | `logout` | (POST) | auth | S7 | — | — | — | — | SYS |
> | `manufacturing.orders.index` | `/manufacturing/orders` | Owner,Manager,Inventory | S3(inventory) | `nav.supply.mfg.orders` | Manufacturing | D4 | MOVE (own hub) | PI |
> | `midtrans.notification` | (POST) | throttle | — | — | — | SYS |
> | `oauth.callback.apple` | (POST) | none | — | — | — | SYS |
> | `oauth.callback.google` | `/auth/oauth/google/callback` | none | — | — | — | SYS |
> | `oauth.redirect` | `/auth/oauth/{provider}/redirect` | none | — | — | — | SYS |
> | `onboarding.business-profile` | `/onboarding/business-profile` | Owner | — (middleware redirect) | — | — | — | — | SYS |
> | `onboarding.initial-branch` | `/onboarding/initial-branch` | Owner | — | — | — | SYS |
> | `onboarding.resend` | (POST) | throttle | — | — | — | SYS |
> | `onboarding.set-password` | `/onboarding/set-password/{token}` | throttle | — | — | — | SYS |
> | `orders.index` | `/orders` | Owner,Manager,Kasir,Waiter,Supervisor | S1 + S3(pos-ops) | `nav.frontline.desk.orders` | Frontline Desk | D2 | = (**SC-02**) | FI |
> | `orders.return` | (POST) | Owner,Manager,Kasir,Waiter,Supervisor | — | — | — | SYS |
> | `orders.show` | `/orders/{orderNumber}` | Owner,Manager,Kasir,Waiter,Supervisor | detail | — (resource crumb) | Order History | D2 | — | DETAIL |
> | `orders.void` | (POST) | Owner,Manager,Kasir,Waiter,Supervisor | — | — | — | SYS |
> | `password.confirm` | `/confirm-password` | auth | — | — | — | SYS |
> | `password.email` | (POST) | guest | — | — | — | SYS |
> | `password.request` | `/forgot-password` | guest | — | — | — | SYS |
> | `password.reset` | `/reset-password/{token}` | guest | — | — | — | SYS |
> | `password.store` | (POST) | guest | — | — | — | SYS |
> | `password.update` | (PUT) | auth | — | — | — | SYS |
> | `price-lists.index` | `/price-lists` | Owner,Manager,Inventory | S1 (Catalog) + S3(catalog) + S3(pricing) | `nav.catalog.pricing.price_lists` | Pricing & Promotions | D3 | MOVE (remove 2 placements) | FI |
> | `pricing.promotions.index` | `/pricing/promotions` | Owner,Manager | S3(pricing) | `nav.catalog.pricing.promotions` | Pricing & Promotions | D3 | = | FI |
> | `pricing.vouchers.index` | `/pricing/vouchers` | Owner,Manager | S3(pricing) | `nav.catalog.pricing.vouchers` | Pricing & Promotions | D3 | = | FI |
> | `products.index` | `/products` | Owner,Manager,Inventory | S1 + S3(catalog) | `nav.catalog.master.products` | Product Catalog | D3 | = | FI |
> | `profile.edit` | `/profile` | auth | S7 | — (user popover; not a menu node) | — | — | — | SYS |
> | `purchasing.grns.index` | `/purchasing/grns` | Owner,Manager,Inventory | S3(purchasing) | `nav.supply.procurement.grns` | Procurement | D4 | RENAME ("PO Goods Receipt (GRN)") | FI |
> | `purchasing.invoices.index` | `/purchasing/invoices` | Owner,Manager,Inventory,Finance | S3(purchasing) | `nav.supply.procurement.invoices` | Procurement | D4 | **NEWPLACE for Finance** (**SC-04**) | FI |
> | `purchasing.orders.index` | `/purchasing/orders` | Owner,Manager,Inventory | S3(purchasing) | `nav.supply.procurement.orders` | Procurement | D4 | MOVE (becomes hub landing) | FI |
> | `purchasing.returns.index` | `/purchasing/returns` | Owner,Manager,Inventory | S3(purchasing) | `nav.supply.procurement.returns` | Procurement | D4 | RENAME ("PO Purchase Returns") | FI |
> | `purchasing.suppliers.index` | `/purchasing/suppliers` | Owner,Manager,Inventory | S1 ("Purchasing") + S3 | `nav.supply.procurement.suppliers` | Procurement | D4 | MOVE (no longer the hub landing) | FI |
> | `qz.certificate` | `/qz/certificate` | Owner,Manager,Kasir | — | — | — | SYS |
> | `qz.sign` | (POST) | Owner,Manager,Kasir | — | — | — | SYS |
> | `register` | `/register` | none | — | — | — | SYS |
> | `reports.analytics` | `/analytics` | Owner,Manager | S1 (Analytics) + S3(reports) | `nav.analytics.bi.overview` | Business Intelligence | D8 | MOVE (own domain) | FI |
> | `reports.eod-close` | (POST) | Owner,Manager | — | — | — | — | — | SYS |
> | `reports.sales` | `/reports/sales` | Owner,Manager | S1 + S3(reports) | `nav.analytics.sales_reports.list` | Sales Reports & EOD | D8 | MOVE (own domain) | FI |
> | `retail.barcodes.index` | `/retail/barcodes` | Owner,Manager,Inventory,Kasir,Sales | S3(catalog) | `nav.catalog.merch.barcodes` | Product Catalog | D3 | = | PI |
> | `retail.exchanges.index` | `/retail/exchanges` | Owner,Manager,Inventory,Kasir,Sales | S3(catalog) | `nav.catalog.merch.exchanges` | Product Catalog | D3 | = | PI |
> | `retail.shelf-labels.index` | `/retail/shelf-labels` | Owner,Manager,Inventory,Kasir,Sales | S3(catalog) | `nav.catalog.merch.shelf_labels` | Product Catalog | D3 | = | FI |
> | `retail.shelf-labels.print-batch` | `/retail/shelf-labels/print-batch/{batch}` | Owner,Manager,Inventory,Kasir,Sales | detail | — | — | DETAIL |
> | `roles.index` | `/roles` | Owner | S1 (Settings) + S3(governance) | `nav.admin.access.roles` | Users & Access | D9 | MOVE | FI |
> | `sales.invoices.index` | `/sales/invoices` | Owner,Manager,Sales,Admin,Finance | S3(sales) | `nav.sales.orders.invoices` | Sales Orders (B2B) | D5 | = | FI |
> | `sales.orders.index` | `/sales/orders` | Owner,Manager,Sales,Admin,Finance | S1 ("B2B Sales Orders") + S3 | `nav.sales.orders.list` | Sales Orders (B2B) | D5 | MOVE (own domain) | FI |
> | `sales.quotations.index` | `/sales/quotations` | Owner,Manager,Sales,Admin,Finance | S3(sales) | `nav.sales.orders.quotations` | Sales Orders (B2B) | D5 | = | FI |
> | `sales.returns.index` | `/sales/returns` | Owner,Manager,Sales,Admin,Finance | S3(sales) | `nav.sales.orders.returns` | Sales Orders (B2B) | D5 | = | PI |
> | `settings.index` | `/settings` | Owner | S1 (Settings) + S3(organization) + S7 | `nav.admin.settings.general` | System Settings | D9 | MOVE | FI |
> | `shifts.index` | `/shifts` | Owner,Manager,Kasir,Supervisor | S1 + S3(pos-ops) | `nav.frontline.desk.shifts` | Frontline Desk | D2 | = (**SC-02**) | FI |
> | `shifts.show` | `/shifts/{shift}` | Owner,Manager,Kasir,Supervisor | detail | — | — | — | — | DETAIL |
> | `stores.index` | `/stores` | Owner | S1 (Settings) + S3(organization) | `nav.admin.org.branches` | Organization | D9 | MOVE | FI |
> | `store.switch` | (POST) | auth | S7/header | — | — | — | — | SYS |
> | `subscription.portal` | `/subscription` | Owner,Manager | S1 (Settings) + S3(organization) | `nav.admin.subscription.portal` | Subscription | D9 | MOVE (own hub) | FI |
> | `tables.index` | `/tables` | Owner,Manager + entitlement | S1 (entitlement-gated) + S3(pos-ops) | `nav.frontline.desk.tables` | Frontline Desk | D2 | = | FI |
> | `tax-classes.index` | `/tax-classes` | Owner,Manager,Inventory | S3(catalog) | `nav.catalog.master.tax_classes` | Product Catalog | D3 | = (remove from Settings active) | FI |
> | `transaction.create` | `/transaction` | Owner,Manager,Kasir | S1 + S3 + S5 | `nav.frontline.pos` | — (hub) | D2 | = (**SC-01**) | FI |
> | `transaction.receipt` | `/transaction/receipt/{orderCode}` | Owner,Manager,Kasir | detail | — | — | — | — | DETAIL |
> | `two-factor.challenge` | `/two-factor-challenge` | none | — | — | — | SYS |
> | `units-of-measure.index` | `/units-of-measure` | Owner,Manager,Inventory | S3(catalog) | `nav.catalog.master.uom` | Product Catalog | D3 | = | FI |
> | `users.index` | `/users` | Owner | S1 (Settings) + S3(governance) | `nav.admin.access.users` | Users & Access | D9 | MOVE | FI |
> | `users.invitations.index` | `/users-invitations` | Owner | S3(governance) | `nav.admin.access.invitations` | Users & Access (**page tab**) | D9 | MERGE (tab) | FI |
> | `verification.notice` | `/verify-email` | auth | — | — | — | SYS |
> | `verification.otp` | (POST) | auth + tenant | — | — | — | SYS |
> | `verification.verify` | `/email/verify/{id}/{hash}` | signed | — | — | — | SYS |

---

## B. MENU → ROUTE (inverse, for implementation)

Sorted by the registry's display order. This is the order the implementation agent must produce in `config/navigation/domains/*.php`.

| #     | Menu ID                                | Label                     | Type | Parent                        | Route                          | Icon (hubs only)          | Order |
| ----- | -------------------------------------- | ------------------------- | ---- | ----------------------------- | ------------------------------ | ------------------------- | ----- |
| 1     | `nav.overview.dashboard`               | Dashboard                 | hub  | —                             | `dashboard`                    | `space_dashboard`         | 10    |
| 2     | `nav.frontline.pos`                    | POS Terminal              | hub  | —                             | `transaction.create`           | `point_of_sale`           | 20    |
| 3     | `nav.frontline.desk`                   | Frontline Desk            | hub  | —                             | `orders.index`                 | `receipt_long`            | 30    |
| 3.1   | `nav.frontline.desk.orders`            | Order History             | page | `nav.frontline.desk`          | `orders.index`                 | —                         | 31    |
| 3.2   | `nav.frontline.desk.shifts`            | Cashier Shifts            | page | `nav.frontline.desk`          | `shifts.index`                 | —                         | 32    |
| 3.3   | `nav.frontline.desk.tables`            | Table & QR                | page | `nav.frontline.desk`          | `tables.index`                 | —                         | 33    |
| 3.4   | `nav.frontline.desk.kds`               | Kitchen Display (KDS)     | page | `nav.frontline.desk`          | `kds.index`                    | —                         | 34    |
| 3.5   | `nav.frontline.desk.channel`           | Omnichannel Mapping       | page | `nav.frontline.desk`          | `integrations.channel-mapping` | —                         | 35    |
| 4     | `nav.catalog.master`                   | Product Catalog           | hub  | —                             | `products.index`               | `inventory_2`             | 40    |
| 4.1   | `nav.catalog.master.products`          | Products                  | page | `nav.catalog.master`          | `products.index`               | —                         | 41    |
| 4.2   | `nav.catalog.master.categories`        | Categories                | page | `nav.catalog.master`          | `categories.index`             | —                         | 42    |
| 4.3   | `nav.catalog.master.brands`            | Brands                    | page | `nav.catalog.master`          | `brands.index`                 | —                         | 43    |
| 4.4   | `nav.catalog.master.uom`               | Units of Measure          | page | `nav.catalog.master`          | `units-of-measure.index`       | —                         | 44    |
| 4.5   | `nav.catalog.master.modifiers`         | Variants & Modifiers      | page | `nav.catalog.master`          | `inventory.modifiers`          | —                         | 45    |
| 4.6   | `nav.catalog.master.assortment`        | Branch Assortment         | page | `nav.catalog.master`          | `assortments.index`            | —                         | 46    |
| 4.7   | `nav.catalog.master.tax_classes`       | Tax Classes               | page | `nav.catalog.master`          | `tax-classes.index`            | —                         | 47    |
| 4.8   | `nav.catalog.merch.shelf_labels`       | Shelf Labels              | page | `nav.catalog.master`          | `retail.shelf-labels.index`    | —                         | 48    |
| 4.9   | `nav.catalog.merch.barcodes`           | Barcode Management        | page | `nav.catalog.master`          | `retail.barcodes.index`        | —                         | 49    |
| 4.10  | `nav.catalog.merch.exchanges`          | Item Exchange             | page | `nav.catalog.master`          | `retail.exchanges.index`       | —                         | 50    |
| 5     | `nav.catalog.pricing`                  | Pricing & Promotions      | hub  | —                             | `price-lists.index`            | `sell`                    | 60    |
| 5.1   | `nav.catalog.pricing.price_lists`      | Price Lists               | page | `nav.catalog.pricing`         | `price-lists.index`            | —                         | 61    |
| 5.2   | `nav.catalog.pricing.discounts`        | Discounts                 | page | `nav.catalog.pricing`         | `discounts.index`              | —                         | 62    |
| 5.3   | `nav.catalog.pricing.promotions`       | Promotion Campaigns       | page | `nav.catalog.pricing`         | `pricing.promotions.index`     | —                         | 63    |
| 5.4   | `nav.catalog.pricing.vouchers`         | Vouchers & Coupons        | page | `nav.catalog.pricing`         | `pricing.vouchers.index`       | —                         | 64    |
| 6     | `nav.supply.inventory`                 | Inventory                 | hub  | —                             | `inventory.ingredients`        | `warehouse`               | 70    |
| 6.1   | `nav.supply.inventory.stock`           | Raw Materials & Stock     | page | `nav.supply.inventory`        | `inventory.ingredients`        | —                         | 71    |
| 6.2   | `nav.supply.inventory.direct_receipts` | Direct Receipts           | page | `nav.supply.inventory`        | `inventory.goods-receipts`     | —                         | 72    |
| 6.3   | `nav.supply.inventory.adjustments`     | Stock Adjustments         | page | `nav.supply.inventory`        | `inventory.adjustments`        | —                         | 73    |
| 6.4   | `nav.supply.inventory.opnames`         | Stock Count (Opname)      | page | `nav.supply.inventory`        | `inventory.opnames`            | —                         | 74    |
| 6.5   | `nav.supply.inventory.transfers`       | Inter-Branch Transfers    | page | `nav.supply.inventory`        | `inventory.transfers`          | —                         | 75    |
| 6.6   | `nav.supply.inventory.direct_returns`  | Direct Supplier Returns   | page | `nav.supply.inventory`        | `inventory.supplier-returns`   | —                         | 76    |
| 6.7   | `nav.supply.inventory.ledger`          | Stock Ledger              | page | `nav.supply.inventory`        | `inventory.ledger`             | —                         | 77    |
| 7     | `nav.supply.procurement`               | Procurement               | hub  | —                             | `purchasing.orders.index`      | `shopping_cart_checkout`  | 80    |
| 7.1   | `nav.supply.procurement.orders`        | Purchase Orders           | page | `nav.supply.procurement`      | `purchasing.orders.index`      | —                         | 81    |
| 7.2   | `nav.supply.procurement.suppliers`     | Suppliers                 | page | `nav.supply.procurement`      | `purchasing.suppliers.index`   | —                         | 82    |
| 7.3   | `nav.supply.procurement.grns`          | PO Goods Receipt (GRN)    | page | `nav.supply.procurement`      | `purchasing.grns.index`        | —                         | 83    |
| 7.4   | `nav.supply.procurement.invoices`      | Supplier Invoices (3-Way) | page | `nav.supply.procurement`      | `purchasing.invoices.index`    | —                         | 84    |
| 7.5   | `nav.supply.procurement.returns`       | PO Purchase Returns       | page | `nav.supply.procurement`      | `purchasing.returns.index`     | —                         | 85    |
| 8     | `nav.supply.mfg`                       | Manufacturing             | hub  | —                             | `manufacturing.orders.index`   | `precision_manufacturing` | 90    |
| 8.1   | `nav.supply.mfg.orders`                | Manufacturing Orders      | page | `nav.supply.mfg`              | `manufacturing.orders.index`   | —                         | 91    |
| 9     | `nav.sales.orders`                     | Sales Orders (B2B)        | hub  | —                             | `sales.orders.index`           | `request_quote`           | 100   |
| 9.1   | `nav.sales.orders.list`                | Sales Orders (SO)         | page | `nav.sales.orders`            | `sales.orders.index`           | —                         | 101   |
| 9.2   | `nav.sales.orders.quotations`          | Quotations                | page | `nav.sales.orders`            | `sales.quotations.index`       | —                         | 102   |
| 9.3   | `nav.sales.orders.invoices`            | AR Invoices               | page | `nav.sales.orders`            | `sales.invoices.index`         | —                         | 103   |
| 9.4   | `nav.sales.orders.returns`             | Returns & Credit Notes    | page | `nav.sales.orders`            | `sales.returns.index`          | —                         | 104   |
| 10    | `nav.sales.customers`                  | Customers & Loyalty       | hub  | —                             | `customers.index`              | `groups`                  | 110   |
| 10.1  | `nav.sales.customers.directory`        | Customer Directory        | page | `nav.sales.customers`         | `customers.index`              | —                         | 111   |
| 10.2  | `nav.sales.customers.groups`           | Customer Groups           | page | `nav.sales.customers`         | `customers.groups.index`       | —                         | 112   |
| 10.3  | `nav.sales.customers.credit`           | B2B Credit Control        | page | `nav.sales.customers`         | `customers.credit.index`       | —                         | 113   |
| 10.4  | `nav.sales.customers.loyalty`          | Loyalty & Gift Cards      | page | `nav.sales.customers`         | `customers.instruments.index`  | —                         | 114   |
| 11    | `nav.finance.gl`                       | Finance & Accounting      | hub  | —                             | `finance.index`                | `account_balance`         | 120   |
| 11.1  | `nav.finance.gl.overview`              | Financial Overview        | page | `nav.finance.gl`              | `finance.index`                | —                         | 121   |
| 11.2  | `nav.finance.gl.coa`                   | Chart of Accounts         | page | `nav.finance.gl`              | `finance.coa.index`            | —                         | 122   |
| 11.3  | `nav.finance.gl.journals`              | General Journal           | page | `nav.finance.gl`              | `finance.journals.index`       | —                         | 123   |
| 11.4  | `nav.finance.gl.periods`               | Accounting Periods        | page | `nav.finance.gl`              | `finance.periods.index`        | —                         | 124   |
| 11.5  | `nav.finance.gl.reconciliation`        | Bank Reconciliation       | page | `nav.finance.gl`              | `finance.reconciliation.index` | —                         | 125   |
| 11.6  | `nav.finance.gl.fixed_assets`          | Fixed Assets              | page | `nav.finance.gl`              | `finance.fixed-assets.index`   | —                         | 126   |
| 12    | `nav.workforce.people`                 | Workforce & Payroll       | hub  | —                             | `hr.employees.index`           | `badge`                   | 130   |
| 12.1  | `nav.workforce.people.employees`       | Employees                 | page | `nav.workforce.people`        | `hr.employees.index`           | —                         | 131   |
| 12.2  | `nav.workforce.people.schedules`       | Schedules & Shifts        | page | `nav.workforce.people`        | `hr.schedules.index`           | —                         | 132   |
| 12.3  | `nav.workforce.people.attendance`      | Attendance & Overtime     | page | `nav.workforce.people`        | `hr.attendance.index`          | —                         | 133   |
| 12.4  | `nav.workforce.people.leaves`          | Leave Management          | page | `nav.workforce.people`        | `hr.leaves.index`              | —                         | 134   |
| 12.5  | `nav.workforce.people.commissions`     | Sales Commissions         | page | `nav.workforce.people`        | `hr.commissions.index`         | —                         | 135   |
| 12.6  | `nav.workforce.people.payroll`         | Payroll                   | page | `nav.workforce.people`        | `hr.payroll.index`             | —                         | 136   |
| 13    | `nav.analytics.bi`                     | Business Intelligence     | hub  | —                             | `reports.analytics`            | `query_stats`             | 140   |
| 13.1  | `nav.analytics.bi.overview`            | Business Intelligence     | page | `nav.analytics.bi`            | `reports.analytics`            | —                         | 141   |
| 14    | `nav.analytics.sales_reports`          | Sales Reports & EOD       | hub  | —                             | `reports.sales`                | `analytics`               | 150   |
| 14.1  | `nav.analytics.sales_reports.list`     | Sales Reports & EOD       | page | `nav.analytics.sales_reports` | `reports.sales`                | —                         | 151   |
| 15    | `nav.admin.org`                        | Organization              | hub  | —                             | `stores.index`                 | `storefront`              | 160   |
| 15.1  | `nav.admin.org.branches`               | Branches                  | page | `nav.admin.org`               | `stores.index`                 | —                         | 161   |
| 15.2  | `nav.admin.org.devices`                | POS Devices               | page | `nav.admin.org`               | `devices.index`                | —                         | 162   |
| 15.3  | `nav.admin.org.channel`                | Channel Mapping           | page | `nav.admin.org`               | `integrations.channel-mapping` | —                         | 163   |
| 16    | `nav.admin.access`                     | Users & Access            | hub  | —                             | `users.index`                  | `admin_panel_settings`    | 170   |
| 16.1  | `nav.admin.access.users`               | Users                     | page | `nav.admin.access`            | `users.index`                  | —                         | 171   |
| 16.2  | `nav.admin.access.roles`               | Roles & Permissions       | page | `nav.admin.access`            | `roles.index`                  | —                         | 172   |
| 16.t1 | `nav.admin.access.users.invitations`   | Pending Invitations       | tab  | `nav.admin.access.users`      | `users.invitations.index`      | —                         | 171.5 |
| 17    | `nav.admin.settings`                   | System Settings           | hub  | —                             | `settings.index`               | `settings`                | 180   |
| 17.1  | `nav.admin.settings.general`           | System Settings           | page | `nav.admin.settings`          | `settings.index`               | —                         | 181   |
| 18    | `nav.admin.subscription`               | Subscription              | hub  | —                             | `subscription.portal`          | `workspace_premium`       | 190   |
| 18.1  | `nav.admin.subscription.portal`        | Subscription & Plans      | page | `nav.admin.subscription`      | `subscription.portal`          | —                         | 191   |
| 19    | `nav.admin.governance`                 | Audit & Approvals         | hub  | —                             | `erp.workflow.approvals`       | `fact_check`              | 200   |
| 19.1  | `nav.admin.governance.approvals`       | Approval Inbox            | page | `nav.admin.governance`        | `erp.workflow.approvals`       | —                         | 201   |
| 19.2  | `nav.admin.governance.audit_logs`      | Audit Logs                | page | `nav.admin.governance`        | `activity-logs.index`          | —                         | 202   |

**Hubs: 19. Pages: 45. Page-tabs: 1.** Every hub except `nav.frontline.pos`, `nav.analytics.bi`, `nav.analytics.sales_reports`, `nav.supply.mfg`, `nav.admin.settings` and `nav.admin.subscription` has ≥2 pages.

> **Single-page hubs.** Six hubs render exactly one page. Per R-5 the L2 bar is suppressed for them, and their L1 row navigates directly. This is intentional: they are hubs for _governance and future growth_, not for tabs.

---

## C. Routes deliberately NOT in navigation

| Category         | Routes                                                                                                                                                           | Why                                                        |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| Authentication   | `login`, `logout`, `password.*`, `two-factor.*`, `verification.*`, `oauth.*`, `confirm-password`, `register`, `invitations.accept`, `invitations.process-accept` | Never menu destinations (§3.2.4)                           |
| Onboarding       | `onboarding.business-profile*`, `onboarding.initial-branch*`, `onboarding.set-password*`, `onboarding.resend`                                                    | Reached only by middleware redirect                        |
| Health/machine   | `health.live`, `health.ready`, `api.*`, `midtrans.notification`, `qz.*`, `api.order.*`, `api.v1.pos.*`, `exports.*`, `analytics.restock-forecasts`               | Not human destinations                                     |
| Resource detail  | `orders.show`, `shifts.show`, `transaction.receipt`, `retail.shelf-labels.print-batch`                                                                           | Reached from parent pages; breadcrumb appends the resource |
| CRUD forms       | `*.create` (GET), `*.edit` (GET), `stores.show` (exception: resource excluded)                                                                                   | Workflow steps                                             |
| Guest self-order | `customer.menu.*`, `customer.cart.*`, `customer.checkout.*`, `customer.waiter.call`                                                                              | Separate shell; unauthenticated                            |
| Legacy aliases   | `kasir` (301), `hr.index` (302), `store.switch` (POST)                                                                                                           | Redirects                                                  |
| Self-service     | `profile.edit`                                                                                                                                                   | User popover, not a menu node (S7)                         |

---

## D. Mechanical migration delta (what the implementation agent must actually do)

### D.1 Additions to the registry (4 orphaned / missing placements)

| Route                        | Action                                                                            |
| ---------------------------- | --------------------------------------------------------------------------------- |
| `finance.fixed-assets.index` | Add node `nav.finance.gl.fixed_assets` (closes the only true orphan)              |
| `purchasing.invoices.index`  | Ensure visibility rule admits `Finance` (single-page Procurement hub for Finance) |
| `users.invitations.index`    | Add as a page **tab** under `nav.admin.access.users`                              |
| `subscription.portal`        | Promote to its own single-page hub `nav.admin.subscription`                       |

### D.2 Removals from the sidebar (relocations only — the pages remain reachable)

| Remove from                                                         | Keep in                                          |
| ------------------------------------------------------------------- | ------------------------------------------------ |
| `sections['Human Resources & Payroll']` (6 rows)                    | Workforce hub's 6 L2 tabs                        |
| `sections['Catalog & Recipes']`'s `price-lists.index`               | Pricing hub                                      |
| `sections['Catalog & Recipes']`'s `assortments.index`               | Catalog hub's L2 tab                             |
| `sections['Inventory & Supply']`'s `inventory.goods-receipts` row   | Inventory hub's "Direct Receipts" tab            |
| `sections['Inventory & Supply']`'s `inventory.ledger` row           | Inventory hub's "Stock Ledger" tab               |
| `sections['Inventory & Supply']`'s `purchasing.suppliers.index` row | Procurement hub's "Suppliers" tab                |
| `sections['Analytics & Customers']`'s `discounts.index` row         | Pricing hub's "Discounts" tab                    |
| `sub_nav['catalog']`'s `price-lists.index` tab                      | Pricing hub                                      |
| `sections['Inventory & Supply']`'s `manufacturing.orders.index` tab | Manufacturing hub                                |
| `sub_nav['pos-ops']`'s `integrations.channel-mapping` (one of two)  | Frontline Desk + Organization (SC-05, both kept) |

### D.3 Label changes (rendered text only)

| Route                         | Old label                            | New label                                          |
| ----------------------------- | ------------------------------------ | -------------------------------------------------- |
| `inventory.goods-receipts`    | Stock Operations / Goods Receipt     | **Direct Receipts**                                |
| `purchasing.grns.index`       | Goods Receipt (GRN)                  | **PO Goods Receipt (GRN)**                         |
| `inventory.supplier-returns`  | Supplier Returns                     | **Direct Supplier Returns**                        |
| `purchasing.returns.index`    | Purchase Returns                     | **PO Purchase Returns**                            |
| `purchasing.suppliers.index`  | Purchasing                           | **Suppliers**                                      |
| `customers.index`             | Customers & CRM / Customer Directory | **Customer Directory** (hub = Customers & Loyalty) |
| `customers.instruments.index` | Loyalty, Vouchers & Gift Cards       | **Loyalty & Gift Cards**                           |
| `reports.analytics`           | BI & Analytics                       | **Business Intelligence**                          |
| `reports.sales`               | Sales Reports                        | **Sales Reports & EOD**                            |
| `settings.index`              | Settings / System Settings           | **System Settings**                                |
| `activity-logs.index`         | Audit Logs                           | **Audit Logs** (hub = Audit & Approvals)           |
| `price-lists.index`           | Price Lists                          | **Price Lists** (hub = Pricing & Promotions)       |

### D.4 Active-pattern corrections

| Node             | Fix                                                                                                                                                                                                                 |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- | ----------- | ---------------- | ------------------- | ---------------- | ------------------ | ---------- |
| System Settings  | **Remove** `tax-classes.*` from `active` (INT-02)                                                                                                                                                                   |
| Table Management | **Remove** `integrations.channel-mapping` from `active` (INT-04)                                                                                                                                                    |
| B2B Sales Orders | `active` must cover `sales.*` so sub-tabs keep the hub highlighted (currently `sales.orders.*` in `modules`, `sales.*` in `sections`; unify to `sales.*` on the **hub**, and `sales.orders.*` on the **list page**) |
| Procurement      | `active` = `purchasing.*`                                                                                                                                                                                           |
| Inventory        | `active` = `inventory.ingredients                                                                                                                                                                                   | goods-receipts | adjustments | opnames          | transfers           | supplier-returns | ledger` (explicit) |
| Catalog          | `active` = `products                                                                                                                                                                                                | categories     | brands      | units-of-measure | inventory.modifiers | assortments      | tax-classes        | retail.\*` |

### D.5 Views that must receive `<x-erp.module-sub-nav>`

Per Phase 3, these 12 destinations currently render **no** L2 navigation and must gain it:

1. `reports.analytics`
2. `reports.sales`
3. `pricing.promotions.index`
4. `pricing.vouchers.index`
5. `discounts.index`
6. `stores.index` (+ create/edit)
7. `devices.index` (+ create/edit/show)
8. `settings.index`
9. `users.index`
10. `roles.index` (+ create/edit)
11. `activity-logs.index`
12. `subscription.portal`
13. `erp.workflow.approvals`
14. `manufacturing.orders.index`

### D.6 Files to delete after Phase 5

| File                                                     | Replacement                                              |
| -------------------------------------------------------- | -------------------------------------------------------- |
| `config/navigation.php`                                  | `config/navigation/domains/*.php` + `NavigationRegistry` |
| `resources/views/catalog/partials/sub-nav.blade.php`     | `<x-erp.module-sub-nav hub="catalog.master" />`          |
| `resources/views/inventory/partials/sub-nav.blade.php`   | `<x-erp.module-sub-nav hub="supply.inventory" />`        |
| `resources/views/purchasing/partials/sub-nav.blade.php`  | `<x-erp.module-sub-nav hub="supply.procurement" />`      |
| `resources/views/sales/partials/sub-nav.blade.php`       | `<x-erp.module-sub-nav hub="sales.orders" />`            |
| `resources/views/customers/partials/sub-nav.blade.php`   | `<x-erp.module-sub-nav hub="sales.customers" />`         |
| `resources/views/finance/partials/sub-nav.blade.php`     | `<x-erp.module-sub-nav hub="finance.gl" />`              |
| `resources/views/livewire/hr/partials/sub-nav.blade.php` | `<x-erp.module-sub-nav hub="workforce.people" />`        |
| `resources/views/components/breadcrumb.blade.php`        | `<x-common.page-breadcrumb>`                             |
| `resources/views/components/erp/app-launcher.blade.php`  | _(deprecated, ADR-NAV-011)_                              |

---

## E. Verification checklist for the implementation agent

Run after each phase; every line must be observable.

```
# 1. No orphan: every page-level GET route is in the registry or the allow-list
php artisan test --filter=NavigationOrphanTest

# 2. No broken link
php artisan test --filter=NavigationRegistryTest

# 3. Role contract matches middleware
php artisan test --filter=NavigationContractTest

# 4. Every visible hub renders HTTP 200 for each persona
php artisan test --filter=NavigationReachabilityTest

# 5. Every page renders exactly one active hub + one active pill
php artisan test --filter=NavigationSmokeTest

# 6. No hardcoded dashboard breadcrumb remains
grep -rn "route('dashboard')" resources/views | wc -l   # must be 0

# 7. No component reads the legacy navigation config
grep -rn "navigation.sections\|navigation.sub_nav\|navigation.modules" app/ resources/ | wc -l   # must be 0 after Phase 5

# 8. No hardcoded hex in navigation views
grep -rnE "#[0-9a-fA-F]{6}" resources/views/layouts/navigation.blade.php resources/views/components/erp/*.blade.php | wc -l   # must be 0

# 9. Quality gate
./vendor/bin/phpunit --testsuite=Arch,Unit
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pint --test
```

---

## F. Mapping completeness statement

| Check                                   | Result                                                                       |
| --------------------------------------- | ---------------------------------------------------------------------------- |
| Page-level GET routes inventoried       | **45** (of which 44 reachable today, 1 orphan)                               |
| Menu nodes in proposed registry         | **65** (19 hubs + 45 pages + 1 tab)                                          |
| Routes dropped from navigation entirely | **0**                                                                        |
| Destinations whose route changes        | **0**                                                                        |
| Destinations whose feature changes      | **0**                                                                        |
| Destinations whose label changes        | **12** (§D.3)                                                                |
| Destinations whose parent hub changes   | **10** (§D.2)                                                                |
| Newly reachable destinations            | **1** (`finance.fixed-assets.index`)                                         |
| Newly reachable for a persona           | **2** (`purchasing.invoices.index` for Finance, `customers.index` for Sales) |
| Deliberate duplicate placements         | **3** (SC-01, SC-02, SC-04) + **1** dual-context (SC-05)                     |
