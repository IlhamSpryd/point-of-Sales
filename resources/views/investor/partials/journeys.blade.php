<div x-data="{ currentWorkflow: 'workflow-a' }" class="space-y-8" data-gsap="page-enter">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-apeiron-ink dark:text-zinc-100 tracking-tight">
                5 Integrated Demo Workflows (Cross-Module Journeys)
            </h2>
            <p class="text-xs md:text-sm text-zinc-500 mt-1">
                Explore realistic end-to-end Apeiron System workflows connecting point-of-sale, stock movements, COGS calculations, and financial reporting.
            </p>
        </div>
        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold">
            <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Deterministic Consistent Data
        </div>
    </div>

    <!-- Workflow Selector Tabs -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <button type="button" @click="currentWorkflow = 'workflow-a'"
            :class="currentWorkflow === 'workflow-a' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Workflow A</span>
                <span class="text-xs font-bold mt-1 block">F&B Sales & Recipe Depletion</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Available</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-b'"
            :class="currentWorkflow === 'workflow-b' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Workflow B</span>
                <span class="text-xs font-bold mt-1 block">Retail & Barcode Scanning</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Available</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-c'"
            :class="currentWorkflow === 'workflow-c' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Workflow C</span>
                <span class="text-xs font-bold mt-1 block">Stock Receipts & Moving Average COGS</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Available</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-d'"
            :class="currentWorkflow === 'workflow-d' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Workflow D</span>
                <span class="text-xs font-bold mt-1 block">Multi-Branch Operations</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-emerald-500/20 text-emerald-300 font-semibold w-max">Available</span>
        </button>

        <button type="button" @click="currentWorkflow = 'workflow-e'"
            :class="currentWorkflow === 'workflow-e' ? 'bg-primary-600 text-white shadow-md' : 'card-surface text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
            class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 text-left transition-all flex flex-col justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider block opacity-80">Workflow E</span>
                <span class="text-xs font-bold mt-1 block">F2/F3 Feature Showcase</span>
            </div>
            <span class="text-[10px] mt-2 inline-flex px-1.5 py-0.5 rounded-md bg-purple-500/20 text-purple-300 font-semibold w-max">Roadmap</span>
        </button>
    </div>

    <!-- Workflow A Container -->
    <div x-show="currentWorkflow === 'workflow-a'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Workflow A: F&B Sales to Recipe Depletion & Financial Reporting
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Validates the lifecycle of dine-in/takeaway orders, ADD/REPLACE modifier selection, multi-tender payments, raw material inventory ledgers, and daily sales summaries.
                    </p>
                </div>
                <a href="{{ route('transaction.create') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="primary" class="gap-2">
                        <span class="material-symbols-rounded text-base">point_of_sale</span>
                        Launch Live POS Terminal
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">POS Cashier</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Select Items & Recipe Modifiers</h4>
                    <p class="text-[11px] text-zinc-500">
                        The cashier selects a menu item (e.g., "Caramel Latte"); the system presents modal modifiers for ADD ("Extra Shot Espresso") and REPLACE ("Oat Milk Substitution").
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Order Context</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Dine-In / Takeaway</h4>
                    <p class="text-[11px] text-zinc-500">
                        The cashier selects the service type. For Dine-In counter service, an optional table number can be entered without requiring a visual floor plan (Decision A).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">Payment</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Multi-Tender Checkout</h4>
                    <p class="text-[11px] text-zinc-500">
                        Supports Cash payments with rounding (OD-21), static QRIS, or debit/credit cards with server-side validation.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-primary-600 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Stock Ledger & Sales</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Inventory & Financial Impact</h4>
                    <p class="text-[11px] text-zinc-500">
                        Coffee bean and milk stock deplete atomically in <code class="font-mono text-[10px] text-primary-600">stock_ledgers</code>, recorded in the active cashier shift summary.
                    </p>
                </div>
            </div>

            <!-- Deep Links -->
            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-3 text-xs">
                <span class="font-semibold text-zinc-400">Operational Verification Links:</span>
                <a href="{{ route('transaction.create') }}" class="font-medium text-primary-600 hover:underline">1. Open POS Terminal</a>
                <span>•</span>
                <a href="{{ route('orders.index') }}" class="font-medium text-primary-600 hover:underline">2. Order History</a>
                <span>•</span>
                <a href="{{ route('inventory.ledger') }}" class="font-medium text-primary-600 hover:underline">3. Stock Movement Ledger</a>
                <span>•</span>
                <a href="{{ route('reports.sales') }}" class="font-medium text-primary-600 hover:underline">4. Sales Reports</a>
            </div>
        </div>
    </div>

    <!-- Workflow B Container -->
    <div x-show="currentWorkflow === 'workflow-b'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Workflow B: Retail Transactions, Barcode Scanning & Wholesale Pricing Rules
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Validates USB HID keyboard-wedge barcode scanning (OD-33), tiered wholesale quantity pricing (OD-19), and finished goods inventory movements.
                    </p>
                </div>
                <a href="{{ route('price-lists.index') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="secondary" class="gap-2">
                        <span class="material-symbols-rounded text-base">price_change</span>
                        Wholesale Pricing Rules
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">SKU Catalog</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Search & Barcode Scanning</h4>
                    <p class="text-[11px] text-zinc-500">
                        Retail items (e.g., "Stainless Tumbler 500ml", barcode 8991234567) are scanned via barcode scanner or located via cashier search.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Price List Engine</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Price Precedence Evaluation</h4>
                    <p class="text-[11px] text-zinc-500">
                        The pricing engine evaluates hierarchy: Branch+Channel > Branch > Channel > Customer Contract > Tenant Base Price (OD-19).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">POS Checkout</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Complete Transaction</h4>
                    <p class="text-[11px] text-zinc-500">
                        The cashier completes the retail transaction. Recipe decomposition is skipped because the item is a finished good SKU.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Stock Count</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Physical Stock Reconciliation</h4>
                    <p class="text-[11px] text-zinc-500">
                        Physical stock is verified periodically via Stock Count (OD-30) with variance thresholds and supervisor sign-off.
                    </p>
                </div>
            </div>

            <!-- Deep Links -->
            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-3 text-xs">
                <span class="font-semibold text-zinc-400">Retail Verification Links:</span>
                <a href="{{ route('products.index') }}" class="font-medium text-blue-600 hover:underline">1. Products & SKU Catalog</a>
                <span>•</span>
                <a href="{{ route('price-lists.index') }}" class="font-medium text-blue-600 hover:underline">2. Wholesale & Tiered Pricing</a>
                <span>•</span>
                <a href="{{ route('assortments.index') }}" class="font-medium text-blue-600 hover:underline">3. Store Product Assortments</a>
            </div>
        </div>
    </div>

    <!-- Workflow C Container -->
    <div x-show="currentWorkflow === 'workflow-c'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Workflow C: Direct Goods Receipt & Moving Average COGS Recalculation
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Demonstrates inventory invariant OD-04: Direct Goods Receipt (PAGE-041) updates moving average COGS immediately without batch delays.
                    </p>
                </div>
                <a href="{{ route('inventory.goods-receipts') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="secondary" class="gap-2">
                        <span class="material-symbols-rounded text-base">input</span>
                        Direct Goods Receipt
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">Supplier</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Select Verified Supplier</h4>
                    <p class="text-[11px] text-zinc-500">
                        Warehouse staff select a registered supplier from the tenant supplier directory and record the delivery order or invoice number.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Direct Receipt</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Enter Quantity & Purchase Cost</h4>
                    <p class="text-[11px] text-zinc-500">
                        Records actual received quantities and unit purchase costs, validated to 4 decimal places per rule OD-29.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">Formula OD-04</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Automated COGS Recalculation</h4>
                    <p class="text-[11px] text-zinc-500">
                        The system recalculates COGS: <br>
                        <code class="font-mono text-[10px] text-purple-600">((PreviousQty * PreviousCOGS) + (NewQty * UnitPrice)) / TotalQty</code>.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-purple-600 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Audit Ledger</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Immutable Ledger Entry</h4>
                    <p class="text-[11px] text-zinc-500">
                        A new row is appended to the immutable stock ledger with no deletion privileges allowed (OD-25).
                    </p>
                </div>
            </div>

            <!-- Distinction Alert -->
            <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                <span class="material-symbols-rounded text-base shrink-0 mt-0.5">verified_user</span>
                <div>
                    <strong>Operational Domain Distinction (MVP vs. F2):</strong>
                    The direct receipt workflow above (PAGE-041) is a standalone MVP feature. Multi-stage formal procurement based on Purchase Orders and 3-Way Match GRN (PAGE-048) is allocated to Phase F2 per the Master PRD and is not conflated with MVP functionality.
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow D Container -->
    <div x-show="currentWorkflow === 'workflow-d'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600"></span>
                        AVAILABLE AND VERIFIED
                    </span>
                    <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                        Workflow D: Multi-Branch Governance & Store Context Isolation
                    </h3>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        Validates branch data isolation (OD-02): instant branch switching for owners, restricting cashier staff to a single branch, and branch-specific catalogs (Store Assortments OD-18).
                    </p>
                </div>
                <a href="{{ route('stores.index') }}" target="_blank" class="shrink-0">
                    <x-ui.button variant="secondary" class="gap-2">
                        <span class="material-symbols-rounded text-base">store</span>
                        Manage Branches
                    </x-ui.button>
                </a>
            </div>

            <!-- Steps Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-2">
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">1</span>
                        <span class="text-[10px] font-mono text-zinc-400">Branch Switcher</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Branch Context Switching</h4>
                    <p class="text-[11px] text-zinc-500">
                        Owners and managers can switch branches instantly via the header branch switcher. The user session safely binds to the active branch ID in TenantContext.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">2</span>
                        <span class="text-[10px] font-mono text-zinc-400">Assortments (OD-18)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Branch Product Availability</h4>
                    <p class="text-[11px] text-zinc-500">
                        Specific products can be toggled per branch (e.g., premium items only sold at the Flagship outlet, not at express kiosks).
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">3</span>
                        <span class="text-[10px] font-mono text-zinc-400">Security Invariant</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Strict Staff Isolation</h4>
                    <p class="text-[11px] text-zinc-500">
                        Cashiers assigned to Branch A cannot access transactions, cash drawers, or inventory for Branch B even if URLs are manipulated.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="size-6 rounded-full bg-zinc-800 text-white font-bold text-xs flex items-center justify-center">4</span>
                        <span class="text-[10px] font-mono text-zinc-400">Owner Rollup</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-xs">Financial Consolidation</h4>
                    <p class="text-[11px] text-zinc-500">
                        The Owner Dashboard displays consolidated revenue across all branches along with side-by-side branch performance comparisons.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow E Container (Roadmap & Prototypes) -->
    <div x-show="currentWorkflow === 'workflow-e'" class="space-y-6">
        <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-4">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300">
                    <span class="size-1.5 rounded-full bg-purple-600"></span>
                    ROADMAP PREVIEWS & PROTOTYPES
                </span>
                <h3 class="text-lg font-bold text-apeiron-ink dark:text-zinc-100 mt-2">
                    Workflow E: Future Capabilities Showcase (Phases F2 & F3)
                </h3>
                <p class="text-xs text-zinc-500 mt-0.5">
                    Strategic modules architecturally scaffolded in the codebase and clearly labeled as prototypes for investor presentations.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Preview 1: KDS Station -->
                <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-orange-600 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">desktop_windows</span> Interactive KDS
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-orange-100 text-orange-800">F2 (OD-32)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Real-Time Kitchen Touchscreen</h4>
                    <p class="text-xs text-zinc-500">
                        Routing order tickets to Bar / Kitchen stations, cooking timers, bump bar interactions, and order ready alerts.
                    </p>
                    <a href="{{ url('/kds') }}" target="_blank" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:underline">
                        Open KDS Prototype &rarr;
                    </a>
                </div>

                <!-- Preview 2: Procure-to-Pay Formal -->
                <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-blue-600 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">receipt_long</span> P2P Procurement
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-blue-100 text-blue-800">F2 (PRD)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Purchase Order & 3-Way Match</h4>
                    <p class="text-xs text-zinc-500">
                        Formal PO issuance to suppliers, warehouse GRN physical verification, and invoice matching prior to AP payment.
                    </p>
                    <span class="text-xs text-zinc-400 font-medium italic block">Available in Phase F2 Architecture</span>
                </div>

                <!-- Preview 3: Table Management & Tabs -->
                <div class="p-5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-purple-600 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">table_restaurant</span> Table Management
                        </span>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-purple-100 text-purple-800">F3 (OD-37)</span>
                    </div>
                    <h4 class="font-bold text-apeiron-ink dark:text-white text-sm">Visual Floor Plan & Open Tabs</h4>
                    <p class="text-xs text-zinc-500">
                        Interactive floor plans, occupied/vacant table statuses, open tabs, split/merge tables, and QR self-ordering.
                    </p>
                    <a href="{{ url('/tables') }}" target="_blank" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:underline">
                        Open Table Prototype &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
