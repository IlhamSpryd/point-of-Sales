<div class="space-y-8" data-gsap="page-enter">
    <!-- Lifecycle Legend Banner -->
    <div class="card-surface p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-3">
            <h3 class="text-base font-bold text-apeiron-ink dark:text-zinc-100">
                Lifecycle Status Taxonomy Standards (Master Audit Legend)
            </h3>
            <span class="text-xs text-zinc-400 font-mono">100% Audit Specification §6.1 Compliant</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 text-xs">
            <div class="p-2.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/50 flex flex-col justify-between">
                <span class="font-bold text-emerald-800 dark:text-emerald-300">AVAILABLE AND VERIFIED</span>
                <span class="text-[11px] text-zinc-500 mt-1">Code behavior technically verified & invariants passed</span>
            </div>
            <div class="p-2.5 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800/50 flex flex-col justify-between">
                <span class="font-bold text-blue-800 dark:text-blue-300">IMPLEMENTED BUT NOT VERIFIED</span>
                <span class="text-[11px] text-zinc-500 mt-1">Code operational, comprehensive verification ongoing</span>
            </div>
            <div class="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 flex flex-col justify-between">
                <span class="font-bold text-amber-800 dark:text-amber-300">IN DEVELOPMENT</span>
                <span class="text-[11px] text-zinc-500 mt-1">Implementation exists at prototype level</span>
            </div>
            <div class="p-2.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-800/50 flex flex-col justify-between">
                <span class="font-bold text-indigo-800 dark:text-indigo-300">PLANNED — F2</span>
                <span class="text-[11px] text-zinc-500 mt-1">Approved for Phase 2 release (PO/GRN, interactive KDS)</span>
            </div>
            <div class="p-2.5 rounded-lg bg-purple-50 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800/50 flex flex-col justify-between">
                <span class="font-bold text-purple-800 dark:text-purple-300">PLANNED — F3</span>
                <span class="text-[11px] text-zinc-500 mt-1">Approved for Phase 3 release (Floor map, QR self-ordering)</span>
            </div>
            <div class="p-2.5 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 flex flex-col justify-between">
                <span class="font-bold text-zinc-700 dark:text-zinc-300">FUTURE ROADMAP</span>
                <span class="text-[11px] text-zinc-500 mt-1">Long-term strategic roadmap beyond current releases</span>
            </div>
            <div class="p-2.5 rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800/50 flex flex-col justify-between">
                <span class="font-bold text-rose-800 dark:text-rose-300">DECISION REQUIRED</span>
                <span class="text-[11px] text-zinc-500 mt-1">Requires formal product owner approval (Decision A / B)</span>
            </div>
        </div>
    </div>

    <!-- 12 Strategic Domains Matrix Table -->
    <div class="card-surface overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
        <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50">
            <h3 class="font-bold text-base text-apeiron-ink dark:text-zinc-100">
                Apeiron 12 Strategic Domains Product Coverage Matrix
            </h3>
            <p class="text-xs text-zinc-500 mt-0.5">Comprehensive platform capability mapping against Master PRD and Claude Module Vision</p>
        </div>

        <div class="overflow-x-auto">
            <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-800 text-xs font-semibold text-zinc-500 uppercase tracking-wider bg-zinc-50/30 dark:bg-zinc-900/30">
                        <th class="px-6 py-3.5">Business Domain</th>
                        <th class="px-6 py-3.5">Module Scope</th>
                        <th class="px-6 py-3.5">Lifecycle Status</th>
                        <th class="px-6 py-3.5">PRD Phase</th>
                        <th class="px-6 py-3.5">Navigation / Access</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <!-- Domain 1: Platform Core -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            1. Platform Core
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Single multi-tenancy (OD-01), Branch Scoping (OD-02), TOTP 2FA authentication (OD-27), RBAC with granular permissions, 7-year audit trail (OD-25).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('settings.index') }}" class="text-primary-600 hover:underline">Settings</a>
                        </td>
                    </tr>

                    <!-- Domain 2: Commerce Core -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            2. Commerce Core
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            High-speed POS Cashier terminal, catalog menu/products, cash/non-cash checkout, tax & service charge engine (OD-03), digital/WhatsApp receipts (OD-35).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('transaction.create') }}" class="text-primary-600 hover:underline">POS Terminal</a>
                        </td>
                    </tr>

                    <!-- Domain 3: F&B Industry Pack -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            3. F&B Industry Pack
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Recipes & Bill of Materials (BOM), ADD/REPLACE modifiers (OD-17), Dine-In & Takeaway workflows, waste & spoilage approval (OD-11).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('products.index') }}" class="text-primary-600 hover:underline">Product Catalog</a>
                        </td>
                    </tr>

                    <!-- Domain 4: Retail Industry Pack -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            4. Retail Industry Pack
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Finished goods SKU catalog, USB HID barcode scanner support (OD-33), tiered wholesale pricing (OD-19), branch assortment (OD-18), stock counts (OD-30).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('price-lists.index') }}" class="text-primary-600 hover:underline">Price Lists</a>
                        </td>
                    </tr>

                    <!-- Domain 5: Inventory & Supply -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            5. Inventory & Supply
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Direct goods receipt (PAGE-041), Moving Average COGS (OD-04), perpetual stock ledger, supplier directory, physical returns (PAGE-045).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('inventory.ledger') }}" class="text-primary-600 hover:underline">Stock Ledger</a>
                        </td>
                    </tr>

                    <!-- Domain 6: Analytics & Financial -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            6. Analytics & Financial
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Sales reports & EOD settlement (OD-36), blind shift cash count (OD-10), proportional net refunding (OD-06), cash rounding (OD-21).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                Available & Verified
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('reports.sales') }}" class="text-primary-600 hover:underline">Sales Reports</a>
                        </td>
                    </tr>

                    <!-- Domain 7: CRM & Loyalty -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            7. CRM & Loyalty
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Walk-in & member directory, customer order history, loyalty tiers for automated promotional pricing.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-blue-700 dark:text-blue-400">
                                Implemented
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP / F2</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('customers.index') }}" class="text-primary-600 hover:underline">Customers</a>
                        </td>
                    </tr>

                    <!-- Domain 8: KDS & Kitchen Routing -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            8. Kitchen Display System (KDS)
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Touchscreen order routing across kitchen/bar stations, bump bar controls, and preparation duration timers.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-indigo-700 dark:text-indigo-400">
                                Planned — F2
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F2 (OD-32)</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ url('/kds') }}" class="text-indigo-600 hover:underline">KDS Prototype</a>
                        </td>
                    </tr>

                    <!-- Domain 9: Table Management -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            9. Table Management
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Visual dining floor plan, open tabs, bill splitting & merging, and customer QR self-ordering.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-purple-700 dark:text-purple-400">
                                Planned — F3
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F3 (OD-37)</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ url('/tables') }}" class="text-purple-600 hover:underline">Tables Prototype</a>
                        </td>
                    </tr>

                    <!-- Domain 10: Procure-to-Pay (P2P) -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            10. Procure-to-Pay (P2P)
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Formal Purchase Orders (PAGE-047), multi-stage GRN (PAGE-048), 3-way matching, and AP supplier debit notes (PAGE-050).
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-indigo-700 dark:text-indigo-400">
                                Planned — F2
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F2 (PRD)</td>
                        <td class="px-6 py-4 text-xs text-zinc-400">Roadmap</td>
                    </tr>

                    <!-- Domain 11: General Ledger & Accounting -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            11. Finance & General Ledger
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            Chart of Accounts (COA), Double-Entry General Ledger, trial balances, bank reconciliation, and financial statements.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                Future Roadmap
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">F3+</td>
                        <td class="px-6 py-4 text-xs text-zinc-400">Roadmap</td>
                    </tr>

                    <!-- Domain 12: Integrations & Omnichannel -->
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40">
                        <td class="px-6 py-4 font-bold text-apeiron-ink dark:text-zinc-100 whitespace-nowrap">
                            12. Integrations & Offline Sync
                        </td>
                        <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                            IndexedDB offline transaction queue (OD-05), QZ Tray silent raw ESC-POS printing (OD-24), online food delivery aggregators.
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                In Development
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs font-semibold text-zinc-500">MVP / F2</td>
                        <td class="px-6 py-4 text-xs">
                            <a href="{{ route('devices.index') }}" class="text-primary-600 hover:underline">Devices</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
                </tbody>
            </table>
        </div>
    </div>
</div>
