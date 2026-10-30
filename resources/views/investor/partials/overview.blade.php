<div class="space-y-8" data-gsap="page-enter">
    <!-- Executive Banner -->
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-linear-to-br from-zinc-900 via-zinc-800 to-zinc-900 p-8 text-white shadow-xl relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-64 h-64 bg-primary-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative z-10 max-w-4xl space-y-4">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 text-xs font-semibold backdrop-blur-xs text-primary-200">
                <span class="material-symbols-rounded text-sm">hub</span>
                <span>Single Modular ERP Architecture • Single Modular Core</span>
            </div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Apeiron System: Unified F&B & Retail Operations Platform
            </h1>
            <p class="text-sm md:text-base text-zinc-300 leading-relaxed">
                Apeiron is built on a resilient multi-tenant foundation, combining high-speed POS cashier responsiveness with the governance rigor of multi-branch ERP. The platform seamlessly powers F&B businesses (Restaurants, Cafes, Bars), Modern Retail (Boutiques, Convenience Stores, Roasteries), and hybrid business models (Mixed Outlets) within a single unified database and centralized account governance.
            </p>
            <div class="pt-2 flex flex-wrap items-center gap-4 text-xs font-medium text-zinc-400">
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> Multi-Tenant DB (OD-01)</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-400"></span> Store Scoping (OD-02)</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-purple-400"></span> Moving Average COGS (OD-04)</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Offline IndexedDB Queue (OD-05)</span>
            </div>
        </div>
    </div>

    <!-- Architectural Pillars Matrix -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Platform Core -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-200 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">dns</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">Platform Core</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Single multi-tenant foundation, tenant_id data isolation, granular RBAC authorization, 7-year audit trail logging (OD-25), and physical cashier terminal registration (OD-31).
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-emerald-600">Status: Active & Verified</span>
                <a href="{{ route('settings.index') }}" class="text-primary-600 hover:underline">Manage &rarr;</a>
            </div>
        </div>

        <!-- F&B Industry Pack -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-orange-50 text-orange-600 dark:bg-orange-950/40 dark:text-orange-400 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">restaurant</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">F&B Industry Pack</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Recipes & Raw Material BOM (OD-04), ADD/REPLACE variant modifiers (OD-17), KDS kitchen routing (OD-32), Dine-In / Takeaway options, and waste/spoilage tracking (OD-11).
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-emerald-600">Status: Operational MVP</span>
                <a href="{{ route('transaction.create') }}" class="text-orange-600 hover:underline">POS Terminal &rarr;</a>
            </div>
        </div>

        <!-- Retail Industry Pack -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">shopping_cart</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">Retail Industry Pack</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Finished goods SKU management, USB HID barcode scanner (OD-33), tiered wholesale pricing rules (OD-19), branch assortment matrix (OD-18), and physical stock count (OD-30).
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-emerald-600">Status: Operational MVP</span>
                <a href="{{ route('price-lists.index') }}" class="text-blue-600 hover:underline">Pricing Rules &rarr;</a>
            </div>
        </div>

        <!-- Shared ERP & Back Office -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 flex flex-col justify-between">
            <div class="space-y-3">
                <div class="size-11 rounded-xl bg-purple-50 text-purple-600 dark:bg-purple-950/40 dark:text-purple-400 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl">account_balance</span>
                </div>
                <h3 class="font-bold text-apeiron-ink dark:text-zinc-100 text-base">Shared ERP & Back Office</h3>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Permanent stock movement ledger, direct goods receipt (PAGE-041), cashier shift reconciliation & blind shift closing (OD-10), sales & EOD reports (OD-36), and P2P & GL roadmap.
                </p>
            </div>
            <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-xs">
                <span class="font-semibold text-purple-600">Status: F2/F3 Prototyped</span>
                <a href="{{ route('inventory.ledger') }}" class="text-purple-600 hover:underline">Stock Ledger &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Deep Dive: Mixed F&B & Retail Tenant Architecture (Section 5.8) -->
    <div class="card-surface p-6 md:p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-zinc-100 dark:border-zinc-800">
            <div>
                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
                    Mixed Tenant Capabilities: F&B + Retail Without Dichotomy Constraints
                </h3>
                <p class="text-xs md:text-sm text-zinc-500 mt-1">
                    Apeiron's architecture does not restrict accounts rigidly to a single business type. A tenant can operate different business models across individual branches.
                </p>
            </div>
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-zinc-100 dark:bg-zinc-800 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                <span class="material-symbols-rounded text-sm">tune</span>
                Branch Capability Model
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
            <div class="p-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-orange-600">coffee</span>
                    <h4 class="font-bold text-apeiron-ink dark:text-zinc-200">Restaurant / Cafe Branch</h4>
                </div>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Focuses on high-speed POS cashier operations, automated recipe-based depletion, kitchen station ticket routing, and ingredient usage tracking.
                </p>
                <div class="text-[11px] font-medium text-orange-700 bg-orange-100/50 px-2.5 py-1 rounded-md inline-block">
                    Example: Apeiron Cafe Senopati
                </div>
            </div>

            <div class="p-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-blue-600">storefront</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-zinc-200">Retail / Merchandise Branch</h4>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    Focuses on rapid barcode scanning, tiered wholesale pricing, finished goods sold without recipe breakdown, and SKU stock control.
                </p>
                <div class="text-[11px] font-medium text-blue-700 bg-blue-100/50 px-2.5 py-1 rounded-md inline-block">
                    Example: Apeiron Roastery & Merch
                </div>
            </div>

            <div class="p-5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-purple-600">domain_add</span>
                    <h4 class="font-bold text-apeiron-ink dark:text-zinc-200">Hybrid Branch</h4>
                </div>
                <p class="text-xs text-zinc-500 leading-relaxed">
                    F&B cashier that also sells merchandise, packaged coffee beans, apparel, and packaged snacks. Transactions deplete recipe raw materials and retail SKU inventory concurrently within a single transaction.
                </p>
                <div class="text-[11px] font-medium text-purple-700 bg-purple-100/50 px-2.5 py-1 rounded-md inline-block">
                    Example: Apeiron Flagship Store
                </div>
            </div>
        </div>
    </div>

    <!-- Scalability from 1 Outlet to Multi-Branch Enterprise -->
    <div class="card-surface p-6 md:p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-6">
        <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100">
            Scalability Path: From 1 Store to Dozens of Integrated Branches
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 relative">
            <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">Stage 1: Single Store</span>
                    <span class="text-xs text-zinc-400 font-mono">1 Branch</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Efficient Daily Operations</h4>
                <ul class="text-xs text-zinc-500 space-y-1.5 list-disc pl-4">
                    <li>Responsive tablet / desktop POS cashier</li>
                    <li>Automated recipes & raw material stock depletion</li>
                    <li>Daily cash reconciliation & cashier shift close</li>
                    <li>Supports local ESC-POS receipt printing</li>
                </ul>
            </div>

            <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">Stage 2: Multi-Branch (Current)</span>
                    <span class="text-xs text-zinc-400 font-mono">2 - 10 Branches</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Centralized Multi-Branch Governance</h4>
                <ul class="text-xs text-zinc-500 space-y-1.5 list-disc pl-4">
                    <li>Central catalog with Store Assortments (OD-18)</li>
                    <li>Differential pricing per branch & channel (OD-19)</li>
                    <li>Strict staff data isolation per branch (OD-02)</li>
                    <li>Consolidated executive rollup reports</li>
                </ul>
            </div>

            <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800">Stage 3: Enterprise & Franchise</span>
                    <span class="text-xs text-zinc-400 font-mono">> 10 Branches</span>
                </div>
                <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Central Kitchen & P2P Supply Chain</h4>
                <ul class="text-xs text-zinc-500 space-y-1.5 list-disc pl-4">
                    <li>Central kitchen & inter-branch stock transfers</li>
                    <li>Formal Procure-to-Pay with PO & 3-way match (F2)</li>
                    <li>Double-entry general ledger accounting (GL F3)</li>
                    <li>Online delivery aggregator integrations (F3)</li>
                </ul>
            </div>
        </div>
    </div>
</div>
