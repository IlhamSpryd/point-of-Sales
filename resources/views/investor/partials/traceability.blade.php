<div x-data="{
    searchQuery: '',
    selectedClassification: '',
    pages: [
        { id: 'PAGE-001', name: 'Login Gateway', class: 'KEEP', canvas: 'Standalone Canvas', route: 'login', prd: 'MVP' },
        { id: 'PAGE-002', name: 'Owner Self-Registration', class: 'KEEP', canvas: 'Standalone Canvas', route: 'register', prd: 'MVP' },
        { id: 'PAGE-003', name: 'Forgot Password Request', class: 'DRAWER/MODAL', canvas: 'Modal on PAGE-001 (`Login Gateway`)', route: 'password.request', prd: 'MVP' },
        { id: 'PAGE-004', name: 'Reset Password', class: 'KEEP', canvas: 'Standalone Canvas', route: 'password.reset', prd: 'MVP' },
        { id: 'PAGE-005', name: 'Two-Factor TOTP Challenge', class: 'KEEP', canvas: 'Standalone Canvas', route: 'two-factor.challenge', prd: 'MVP' },
        { id: 'PAGE-006', name: 'Email Verification Notice', class: 'KEEP', canvas: 'Standalone Canvas', route: 'verification.notice', prd: 'MVP' },
        { id: 'PAGE-007', name: 'Owner Set Password', class: 'MERGE', canvas: 'PAGE-008 (`Unified Account Setup`)', route: 'onboarding.set-password', prd: 'MVP' },
        { id: 'PAGE-008', name: 'Employee Accept Invitation', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-007)', route: 'invitations.accept', prd: 'MVP' },
        { id: 'PAGE-009', name: 'Confirm Password', class: 'DRAWER/MODAL', canvas: 'Modal on PAGE-080 / PAGE-081', route: 'password.confirm', prd: 'MVP' },
        { id: 'PAGE-010', name: 'Onboarding Step 1: Business Profile', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-011)', route: 'onboarding.business-profile', prd: 'MVP' },
        { id: 'PAGE-011', name: 'Onboarding Step 2: Initial Branch', class: 'MERGE', canvas: 'PAGE-010 (`Unified Onboarding Stepper`)', route: 'onboarding.initial-branch', prd: 'MVP' },
        { id: 'PAGE-012', name: 'Executive Business Dashboard', class: 'KEEP', canvas: 'Standalone Canvas', route: 'dashboard', prd: 'MVP' },
        { id: 'PAGE-013', name: 'Role Dispatcher & Landing', class: 'NAVIGATION CONSOLIDATION', canvas: 'Utility Redirector (Hidden from menu)', route: 'home', prd: 'MVP' },
        { id: 'PAGE-014', name: 'Sales Analytics & EOD Reports', class: 'KEEP', canvas: 'Standalone Canvas', route: 'reports.sales', prd: 'MVP' },
        { id: 'PAGE-015', name: 'POS Cashier Terminal', class: 'KEEP', canvas: 'Standalone Canvas', route: 'transaction.create', prd: 'MVP' },
        { id: 'PAGE-016', name: 'Thermal Receipt Print Spooler', class: 'DRAWER/MODAL', canvas: 'Modal on PAGE-015 / PAGE-019', route: 'transaction.receipt', prd: 'MVP' },
        { id: 'PAGE-017', name: 'Shift Management & Cash Drawer', class: 'KEEP', canvas: 'Standalone Canvas', route: 'shifts.index', prd: 'MVP (`OD-30`)' },
        { id: 'PAGE-018', name: 'Kitchen Display System (KDS)', class: 'DEFER F2', canvas: 'F2 Operations Suite (Roadmap)', route: 'kds.index', prd: 'F2 (`OD-32`)' },
        { id: 'PAGE-019', name: 'Order & Transaction History', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-020)', route: 'orders.index', prd: 'MVP' },
        { id: 'PAGE-020', name: 'Order Detail & Audit Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-019', route: 'orders.show', prd: 'MVP' },
        { id: 'PAGE-021', name: 'Customer QR Digital Menu', class: 'DEFER F3', canvas: 'F3 Self-Ordering Suite (Roadmap)', route: 'customer.menu.index', prd: 'F3 (`OD-37`)' },
        { id: 'PAGE-022', name: 'Customer Mobile Cart', class: 'DEFER F3', canvas: 'F3 Self-Ordering Suite (Roadmap)', route: 'customer.cart.index', prd: 'F3 (`OD-37`)' },
        { id: 'PAGE-023', name: 'Customer Checkout & Payment', class: 'DEFER F2', canvas: 'F2 Self-Ordering Suite (Roadmap)', route: 'customer.checkout.create', prd: 'F2 (`OD-03`)' },
        { id: 'PAGE-024', name: 'Customer Order Success & Status', class: 'DEFER F3', canvas: 'F3 Self-Ordering Suite (Roadmap)', route: 'customer.checkout.success', prd: 'F3 (`OD-37`)' },
        { id: 'PAGE-025', name: 'Products Master Catalog List', class: 'KEEP', canvas: 'Standalone Canvas', route: 'products.index', prd: 'MVP' },
        { id: 'PAGE-026', name: 'Create Product Form', class: 'MERGE', canvas: 'PAGE-027 (`Unified Product Editor`)', route: 'products.create', prd: 'MVP' },
        { id: 'PAGE-027', name: 'Edit Product Form', class: 'KEEP', canvas: 'Standalone Canvas (Unified Product Editor)', route: 'products.edit', prd: 'MVP' },
        { id: 'PAGE-028', name: 'Product Categories Master', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-029/030)', route: 'categories.index', prd: 'MVP' },
        { id: 'PAGE-029', name: 'Create Category Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-028', route: 'categories.create', prd: 'MVP' },
        { id: 'PAGE-030', name: 'Edit Category Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-028', route: 'categories.edit', prd: 'MVP' },
        { id: 'PAGE-031', name: 'Brands Master Tab', class: 'NAVIGATION CONSOLIDATION', canvas: 'Tab on Catalog Master Hub', route: 'brands.index', prd: 'MVP' },
        { id: 'PAGE-032', name: 'Units of Measure (UOM) Tab', class: 'NAVIGATION CONSOLIDATION', canvas: 'Tab on Catalog Master Hub', route: 'units-of-measure.index', prd: 'MVP' },
        { id: 'PAGE-033', name: 'Tax Classes Master Tab', class: 'NAVIGATION CONSOLIDATION', canvas: 'Tab on Tenant Settings (`PAGE-080`)', route: 'tax-classes.index', prd: 'MVP' },
        { id: 'PAGE-034', name: 'Price Lists Master List', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-035/036)', route: 'price-lists.index', prd: 'MVP (`OD-19`)' },
        { id: 'PAGE-035', name: 'Create Price List Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-034', route: 'price-lists.create', prd: 'MVP' },
        { id: 'PAGE-036', name: 'Edit Price List Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-034', route: 'price-lists.edit', prd: 'MVP' },
        { id: 'PAGE-037', name: 'Store Product Assortments', class: 'KEEP', canvas: 'Standalone Canvas', route: 'assortments.index', prd: 'MVP' },
        { id: 'PAGE-038', name: 'Raw Ingredients & Stock', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.ingredients', prd: 'MVP' },
        { id: 'PAGE-039', name: 'Stock Movement Ledger', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.ledger', prd: 'MVP' },
        { id: 'PAGE-040', name: 'Recipe Modifiers & BOM', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.modifiers', prd: 'MVP (`OD-17`)' },
        { id: 'PAGE-041', name: 'Goods Receipts (Direct)', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.goods-receipts', prd: 'MVP' },
        { id: 'PAGE-042', name: 'Stock Adjustments & Waste', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.adjustments', prd: 'MVP (`OD-11`)' },
        { id: 'PAGE-043', name: 'Stock Opnames & Count', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.opnames', prd: 'MVP (`OD-30`)' },
        { id: 'PAGE-044', name: 'Inter-Branch Stock Transfers', class: 'DEFER F2', canvas: 'F2 Inventory Logistics Suite (Roadmap)', route: 'inventory.transfers', prd: 'F2' },
        { id: 'PAGE-045', name: 'Supplier Returns Management', class: 'KEEP', canvas: 'Standalone Canvas', route: 'inventory.supplier-returns', prd: 'MVP' },
        { id: 'PAGE-046', name: 'Suppliers & Vendor Directory', class: 'KEEP', canvas: 'Standalone Canvas', route: 'purchasing.suppliers.index', prd: 'MVP' },
        { id: 'PAGE-047', name: 'Purchase Orders (PO)', class: 'DEFER F2', canvas: 'F2 Procure-to-Pay Suite (Roadmap)', route: 'purchasing.orders.index', prd: 'F2' },
        { id: 'PAGE-048', name: 'Goods Received Notes (GRN)', class: 'DEFER F2', canvas: 'F2 Procure-to-Pay Suite (Roadmap)', route: 'purchasing.grns.index', prd: 'F2' },
        { id: 'PAGE-049', name: 'Supplier Invoices (3-Way Match)', class: 'DEFER F2', canvas: 'F2 Procure-to-Pay Suite (Roadmap)', route: 'purchasing.invoices.index', prd: 'F2' },
        { id: 'PAGE-050', name: 'Purchasing Returns & Debit Note', class: 'DEFER F2', canvas: 'F2 Procure-to-Pay Suite (Roadmap)', route: 'purchasing.returns.index', prd: 'F2' },
        { id: 'PAGE-051', name: 'Customer Master Directory', class: 'KEEP', canvas: 'Standalone Canvas', route: 'customers.index', prd: 'MVP (`OD-22`)' },
        { id: 'PAGE-052', name: 'Customer Groups & Tiers', class: 'DEFER F2', canvas: 'F2 Customer Suite (Roadmap)', route: 'customers.groups.index', prd: 'F2' },
        { id: 'PAGE-053', name: 'B2B Customer Credit Control', class: 'DEFER F3', canvas: 'F3 Wholesale ERP Suite (Roadmap)', route: 'customers.credit.index', prd: 'F3' },
        { id: 'PAGE-054', name: 'Loyalty, Vouchers & Gift Cards', class: 'DEFER F2', canvas: 'F2 Loyalty Suite (Roadmap)', route: 'customers.instruments.index', prd: 'F2 (`OD-31`)' },
        { id: 'PAGE-055', name: 'Discounts & Promotions Engine', class: 'KEEP', canvas: 'Standalone Canvas', route: 'discounts.index', prd: 'MVP (`OD-13`)' },
        { id: 'PAGE-056', name: 'B2B Sales Quotations', class: 'DEFER F3', canvas: 'F3 Wholesale ERP Suite (Roadmap)', route: 'sales.quotations.index', prd: 'F3' },
        { id: 'PAGE-057', name: 'B2B Sales Orders (SO)', class: 'DEFER F3', canvas: 'F3 Wholesale ERP Suite (Roadmap)', route: 'sales.orders.index', prd: 'F3' },
        { id: 'PAGE-058', name: 'Accounts Receivable Invoices', class: 'DEFER F3', canvas: 'F3 Wholesale ERP Suite (Roadmap)', route: 'sales.invoices.index', prd: 'F3' },
        { id: 'PAGE-059', name: 'B2B Sales Returns & Credit Notes', class: 'DEFER F3', canvas: 'F3 Wholesale ERP Suite (Roadmap)', route: 'sales.returns.index', prd: 'F3' },
        { id: 'PAGE-060', name: 'Finance & General Ledger Hub', class: 'DEFER F3', canvas: 'F3 Advanced Finance Suite (Roadmap)', route: 'finance.index', prd: 'F3' },
        { id: 'PAGE-061', name: 'Dining Tables Master List', class: 'DEFER F3', canvas: 'F3 Table Management Suite (Roadmap)', route: 'tables.index', prd: 'F3 (`OD-37`)' },
        { id: 'PAGE-062', name: 'Create Dining Table Modal', class: 'DEFER F3', canvas: 'Modal on PAGE-061 (Roadmap)', route: 'tables.create', prd: 'F3 (`OD-37`)' },
        { id: 'PAGE-063', name: 'Edit Dining Table Modal', class: 'DEFER F3', canvas: 'Modal on PAGE-061 (Roadmap)', route: 'tables.edit', prd: 'F3 (`OD-37`)' },
        { id: 'PAGE-064', name: 'POS Hardware Devices Hub', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-065–067)', route: 'devices.index', prd: 'MVP (`OD-24`)' },
        { id: 'PAGE-065', name: 'Create Device Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-064', route: 'devices.create', prd: 'MVP' },
        { id: 'PAGE-066', name: 'Device Detail & Token Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-064', route: 'devices.show', prd: 'MVP' },
        { id: 'PAGE-067', name: 'Edit Device Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-064', route: 'devices.edit', prd: 'MVP' },
        { id: 'PAGE-068', name: 'Omnichannel Channel Mapping', class: 'DEFER F3', canvas: 'F3 Omnichannel Suite (Roadmap)', route: 'integrations.channel-mapping', prd: 'F3' },
        { id: 'PAGE-069', name: 'Activity & Audit Trail Logs', class: 'KEEP', canvas: 'Standalone Canvas', route: 'activity-logs.index', prd: 'MVP (`OD-25`)' },
        { id: 'PAGE-070', name: 'Store Branches Administration', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-071/072)', route: 'stores.index', prd: 'MVP (`OD-15`)' },
        { id: 'PAGE-071', name: 'Create Store Branch Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-070', route: 'stores.create', prd: 'MVP' },
        { id: 'PAGE-072', name: 'Edit Store Branch Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-070', route: 'stores.edit', prd: 'MVP' },
        { id: 'PAGE-073', name: 'User & Employee Directory', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-074–076)', route: 'users.index', prd: 'MVP' },
        { id: 'PAGE-074', name: 'Create Employee Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-073', route: 'users.create', prd: 'MVP' },
        { id: 'PAGE-075', name: 'Edit Employee Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-073', route: 'users.edit', prd: 'MVP' },
        { id: 'PAGE-076', name: 'Pending Invitations Sub-Tab', class: 'MERGE', canvas: 'Sub-Tab on PAGE-073 (`User Directory`)', route: 'users.invitations.index', prd: 'MVP' },
        { id: 'PAGE-077', name: 'RBAC Roles & Permissions', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-078/079)', route: 'roles.index', prd: 'MVP (`OD-07`)' },
        { id: 'PAGE-078', name: 'Create Custom Role Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-077', route: 'roles.create', prd: 'MVP' },
        { id: 'PAGE-079', name: 'Edit Custom Role Drawer', class: 'DRAWER/MODAL', canvas: 'Slide-Over Drawer on PAGE-077', route: 'roles.edit', prd: 'MVP' },
        { id: 'PAGE-080', name: 'Tenant System Settings', class: 'KEEP', canvas: 'Standalone Canvas (Absorbs PAGE-033 Tax)', route: 'settings.index', prd: 'MVP' },
        { id: 'PAGE-081', name: 'User Profile & Security Center', class: 'KEEP', canvas: 'Standalone Canvas', route: 'profile.edit', prd: 'MVP' },
    ],
    filteredPages() {
        return this.pages.filter(p => {
            const matchesSearch = !this.searchQuery || 
                p.id.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                p.name.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                p.canvas.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                p.route.toLowerCase().includes(this.searchQuery.toLowerCase());
            const matchesClass = !this.selectedClassification || p.class === this.selectedClassification;
            return matchesSearch && matchesClass;
        });
    }
}" class="space-y-6" data-gsap="page-enter">

    <!-- Summary KPI Header (Exact Audit §3.2 Counts: Sum = 81) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">KEEP (MVP)</span>
            <span class="text-2xl font-bold text-emerald-600 mt-1">34</span>
            <span class="text-[10px] text-zinc-400">Kanvas Mandiri</span>
        </div>
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">DRAWER / MODAL</span>
            <span class="text-2xl font-bold text-blue-600 mt-1">17</span>
            <span class="text-[10px] text-zinc-400">Aksi Kontekstual</span>
        </div>
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">MERGE</span>
            <span class="text-2xl font-bold text-amber-600 mt-1">4</span>
            <span class="text-[10px] text-zinc-400">Form Gabungan</span>
        </div>
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">NAV CONSOLIDATION</span>
            <span class="text-2xl font-bold text-indigo-600 mt-1">4</span>
            <span class="text-[10px] text-zinc-400">Sub-Nav / Tab</span>
        </div>
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">DEFER F2</span>
            <span class="text-2xl font-bold text-purple-600 mt-1">9</span>
            <span class="text-[10px] text-zinc-400">Roadmap Fase 2</span>
        </div>
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider">DEFER F3</span>
            <span class="text-2xl font-bold text-zinc-600 mt-1">13</span>
            <span class="text-[10px] text-zinc-400">Roadmap Fase 3</span>
        </div>
        <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-emerald-50 dark:bg-emerald-950/20 shadow-xs flex flex-col justify-between">
            <span class="text-[11px] font-semibold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">TOTAL INVENTORI</span>
            <span class="text-2xl font-bold text-emerald-700 dark:text-emerald-400 mt-1">81</span>
            <span class="text-[10px] text-emerald-600 font-semibold">100% Tervalidasi</span>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card-surface p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="relative flex-1 max-w-md">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
            <input type="text" x-model="searchQuery" placeholder="Cari PAGE-ID, nama layar, route, atau PRD..."
                class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
        </div>

        <div class="flex items-center gap-2">
            <span class="text-xs text-zinc-400 font-semibold uppercase">Filter Status:</span>
            <select x-model="selectedClassification" class="px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-apeiron-ink dark:text-zinc-100 focus:outline-none">
                <option value="">Semua Klasifikasi (81)</option>
                <option value="KEEP">KEEP (34)</option>
                <option value="DRAWER/MODAL">DRAWER/MODAL (17)</option>
                <option value="MERGE">MERGE (4)</option>
                <option value="NAVIGATION CONSOLIDATION">NAVIGATION CONSOLIDATION (4)</option>
                <option value="DEFER F2">DEFER F2 (9)</option>
                <option value="DEFER F3">DEFER F3 (13)</option>
            </select>
        </div>
    </div>

    <!-- Traceability Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-800 text-xs font-semibold text-zinc-500 uppercase tracking-wider bg-zinc-50/50 dark:bg-zinc-900/50">
                        <th class="px-6 py-3.5">PAGE-ID</th>
                        <th class="px-6 py-3.5">Nama Layar Master Audit</th>
                        <th class="px-6 py-3.5">Klasifikasi Utama</th>
                        <th class="px-6 py-3.5">Perlakuan / Kanvas Konsolidasi</th>
                        <th class="px-6 py-3.5">Route / Deep Link</th>
                        <th class="px-6 py-3.5">Ref. PRD</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 text-xs">
                    <template x-for="p in filteredPages()" :key="p.id">
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-6 py-3.5 font-mono font-bold text-apeiron-ink dark:text-zinc-200" x-text="p.id"></td>
                            <td class="px-6 py-3.5 font-semibold text-zinc-800 dark:text-zinc-100" x-text="p.name"></td>
                            <td class="px-6 py-3.5">
                                <span class="text-xs font-medium"
                                    :class="{
                                        'text-emerald-700 dark:text-emerald-400': p.class === 'KEEP',
                                        'text-blue-700 dark:text-blue-400': p.class === 'DRAWER/MODAL',
                                        'text-amber-700 dark:text-amber-400': p.class === 'MERGE',
                                        'text-indigo-700 dark:text-indigo-400': p.class === 'NAVIGATION CONSOLIDATION',
                                        'text-purple-700 dark:text-purple-400': p.class === 'DEFER F2',
                                        'text-gray-500 dark:text-gray-400': p.class === 'DEFER F3'
                                    }"
                                    x-text="p.class">
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-zinc-500 dark:text-zinc-400" x-text="p.canvas"></td>
                            <td class="px-6 py-3.5 font-mono text-[11px] text-primary-600 dark:text-primary-400" x-text="p.route"></td>
                            <td class="px-6 py-3.5 text-zinc-400 font-medium" x-text="p.prd"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
