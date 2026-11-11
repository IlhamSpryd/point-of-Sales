@props([
    'selected' => [],
    'readonly' => false,
    'compact' => false,
])

@php
    $rawSelected = is_array($selected) ? $selected : (is_string($selected) ? (json_decode($selected, true) ?: []) : []);
    $normalizedSelected = app(\App\Modules\Users\Services\RoleService::class)->normalizePermissions($rawSelected);
    $hasWildcard = in_array('*', $normalizedSelected, true);

    $domains = [
        [
            'id' => 'overview',
            'title' => 'Executive & Analytics',
            'icon' => 'space_dashboard',
            'scope' => 'Tenant',
            'permissions' => [
                ['code' => 'dashboard', 'label' => 'Executive Dashboard', 'desc' => 'View multi-branch executive KPIs and revenue summaries.', 'sod' => false],
                ['code' => 'reports.analytics.view', 'label' => 'BI & Advanced Analytics', 'desc' => 'Access cross-module business intelligence and trend charts.', 'sod' => false],
                ['code' => 'reports.sales.view', 'label' => 'Sales & Shift Reports', 'desc' => 'View daily sales recaps, EOD closing, and payment breakdowns.', 'sod' => false],
            ],
        ],
        [
            'id' => 'dashboard_widgets',
            'title' => 'Dashboard Widgets & Operational Scope',
            'icon' => 'dashboard_customize',
            'scope' => 'Tenant / Branch',
            'permissions' => [
                ['code' => 'dashboard.scope.all_stores', 'label' => 'Multi-Branch Scope Access', 'desc' => 'Allow viewing consolidated multi-branch data instead of single assigned branch.', 'sod' => true],
                ['code' => 'dashboard.metric.revenue', 'label' => 'Revenue & Net Sales Metric', 'desc' => 'Display gross/net sales, average transaction value, and target attainment.', 'sod' => false],
                ['code' => 'dashboard.metric.profit_margin', 'label' => 'Gross Profit & Margin Metric', 'desc' => 'Display gross profit, COGS analysis, and margin percentage metrics.', 'sod' => true],
                ['code' => 'dashboard.metric.cash_variance', 'label' => 'Cash Drawer Variance Metric', 'desc' => 'Monitor shift cash drawer overages/shortages and variance alerts.', 'sod' => true],
                ['code' => 'dashboard.metric.inventory_valuation', 'label' => 'Inventory Valuation & Low Stock', 'desc' => 'View inventory asset value, low stock warnings, and out-of-stock items.', 'sod' => false],
                ['code' => 'dashboard.metric.finance_gl', 'label' => 'General Ledger Financial Health', 'desc' => 'Access operating cash, AR/AP balances, and pending reconciliation totals.', 'sod' => true],
                ['code' => 'dashboard.metric.hr_payroll', 'label' => 'HR & Workforce Cost Metric', 'desc' => 'Display active shift headcounts, overtime hours, and monthly payroll budget.', 'sod' => true],
                ['code' => 'dashboard.action.approve_void_discount', 'label' => 'Supervisor Approval Authorization', 'desc' => 'Authorize supervisor PIN overrides for void orders, discounts, and shift variances.', 'sod' => true],
            ],
        ],
        [
            'id' => 'pos',
            'title' => 'POS & Frontline Operations',
            'icon' => 'point_of_sale',
            'scope' => 'Branch',
            'permissions' => [
                ['code' => 'orders.create', 'label' => 'POS Checkout Terminal', 'desc' => 'Create POS orders, manage carts, and process payments.', 'sod' => false],
                ['code' => 'orders.view', 'label' => 'Order & Receipt History', 'desc' => 'Browse completed transactions and reprint receipts.', 'sod' => false],
                ['code' => 'orders.void', 'label' => 'Void & Refund Authority', 'desc' => 'Authorize order cancellation, voiding, and customer refunds.', 'sod' => true],
                ['code' => 'pos.discount.manual_apply', 'label' => 'Manual POS Discounts', 'desc' => 'Apply discretionary discounts at checkout.', 'sod' => true],
                ['code' => 'shifts.own', 'label' => 'Cashier Shift & Drawer', 'desc' => 'Open/close cashier shifts and record cash movements.', 'sod' => false],
                ['code' => 'tables.view', 'label' => 'Dine-In Table & QR Management', 'desc' => 'Manage floor tables, QR tokens, and table releases.', 'sod' => false],
                ['code' => 'kds.access', 'label' => 'Kitchen Display System (KDS)', 'desc' => 'View and update kitchen/bar preparation queues.', 'sod' => false],
            ],
        ],
        [
            'id' => 'catalog',
            'title' => 'Catalog, Recipes & Pricing',
            'icon' => 'inventory_2',
            'scope' => 'Tenant',
            'permissions' => [
                ['code' => 'products.view', 'label' => 'View Product Catalog', 'desc' => 'View products, categories, brands, UoM, and modifiers.', 'sod' => false],
                ['code' => 'products.manage', 'label' => 'Manage Products & Channel Mapping', 'desc' => 'Create/edit products, recipes, tax classes, and omnichannel SKUs.', 'sod' => false],
                ['code' => 'pricing.view', 'label' => 'View Price Lists', 'desc' => 'Inspect tiered price lists and branch assortments.', 'sod' => false],
                ['code' => 'discounts.manage', 'label' => 'Manage Discounts & Vouchers', 'desc' => 'Configure automated promotion campaigns and voucher quotas.', 'sod' => true],
            ],
        ],
        [
            'id' => 'inventory',
            'title' => 'Inventory & Procurement (P2P)',
            'icon' => 'warehouse',
            'scope' => 'Branch',
            'permissions' => [
                ['code' => 'inventory.view', 'label' => 'View Stock & Ledger', 'desc' => 'Inspect raw material balances and immutable stock ledger.', 'sod' => false],
                ['code' => 'inventory.manage', 'label' => 'Execute Stock Operations', 'desc' => 'Perform goods receipts, stock counts, adjustments, and transfers.', 'sod' => true],
                ['code' => 'purchasing.view', 'label' => 'Suppliers & Purchasing Hub', 'desc' => 'Manage supplier directory, POs, GRNs, and purchase returns.', 'sod' => false],
                ['code' => 'purchasing.invoice.view', 'label' => 'Supplier Invoices (3-Way Match)', 'desc' => 'Verify and match supplier invoices against PO and GRN.', 'sod' => true],
            ],
        ],
        [
            'id' => 'sales_crm',
            'title' => 'B2B Sales & Customer CRM',
            'icon' => 'groups',
            'scope' => 'Tenant',
            'permissions' => [
                ['code' => 'customers.view', 'label' => 'Customer Directory & Loyalty', 'desc' => 'Manage customer profiles, groups, and loyalty instruments.', 'sod' => false],
                ['code' => 'customers.credit.view', 'label' => 'B2B Credit Limit Control', 'desc' => 'Monitor and adjust B2B customer credit limits and holds.', 'sod' => true],
                ['code' => 'sales.orders.view', 'label' => 'B2B Quotations, SO & AR Invoices', 'desc' => 'Manage B2B quotations, sales orders, AR invoices, and credit notes.', 'sod' => false],
            ],
        ],
        [
            'id' => 'finance',
            'title' => 'Finance & General Ledger',
            'icon' => 'account_balance',
            'scope' => 'Tenant',
            'permissions' => [
                ['code' => 'finance.view', 'label' => 'Financial Overview & Statements', 'desc' => 'Access general ledger dashboard, trial balance, and P&L.', 'sod' => false],
                ['code' => 'finance.coa.manage', 'label' => 'Chart of Accounts (COA)', 'desc' => 'Create and maintain general ledger account hierarchy.', 'sod' => true],
                ['code' => 'finance.journals.manage', 'label' => 'Journal Entries & Reconciliation', 'desc' => 'Post manual journals and perform bank reconciliations.', 'sod' => true],
                ['code' => 'finance.periods.manage', 'label' => 'Accounting Period Lock', 'desc' => 'Open, close, and lock fiscal accounting periods.', 'sod' => true],
            ],
        ],
        [
            'id' => 'hr',
            'title' => 'Human Resources & Payroll',
            'icon' => 'badge',
            'scope' => 'Tenant',
            'permissions' => [
                ['code' => 'hr.employees.view', 'label' => 'Employee Directory', 'desc' => 'View and manage employee records, contracts, and job titles.', 'sod' => false],
                ['code' => 'hr.schedules.manage', 'label' => 'Schedules, Attendance & Leave', 'desc' => 'Manage workforce shifts, overtime logs, and leave approvals.', 'sod' => false],
                ['code' => 'hr.payroll.manage', 'label' => 'Payroll & Commissions', 'desc' => 'Execute payroll batches, PPh 21/BPJS calculations, and commissions.', 'sod' => true],
            ],
        ],
        [
            'id' => 'governance',
            'title' => 'Organization & Governance',
            'icon' => 'admin_panel_settings',
            'scope' => 'Tenant',
            'permissions' => [
                ['code' => 'stores.manage', 'label' => 'Branch & Store Management', 'desc' => 'Create and configure physical store outlets and timezones.', 'sod' => true],
                ['code' => 'devices.manage', 'label' => 'POS Terminal & Device Registry', 'desc' => 'Authorize and revoke physical POS and KDS terminals.', 'sod' => false],
                ['code' => 'users.manage', 'label' => 'User & Staff Provisioning', 'desc' => 'Create staff accounts, send invitations, and assign branches.', 'sod' => true],
                ['code' => 'roles.manage', 'label' => 'RBAC Role & Permission Governance', 'desc' => 'Define roles and modify organizational access matrices.', 'sod' => true],
                ['code' => 'settings.manage', 'label' => 'System Settings', 'desc' => 'Modify enterprise tax defaults and rounding configurations.', 'sod' => true],
                ['code' => 'audit_logs.view', 'label' => 'Security Audit Trail', 'desc' => 'Inspect immutable system activity and security logs.', 'sod' => false],
            ],
        ],
    ];

    $allCodes = [];
    foreach ($domains as $domain) {
        foreach ($domain['permissions'] as $p) {
            $allCodes[] = $p['code'];
        }
    }

    $initialSelected = $hasWildcard
        ? $allCodes
        : array_values(array_intersect($allCodes, $normalizedSelected));
@endphp

<div x-data="{
    search: '',
    readonly: @js((bool) $readonly),
    selected: @js($initialSelected),
    toggleDomain(codes) {
        if (this.readonly) return;
        const allChecked = codes.every(c => this.selected.includes(c));
        if (allChecked) {
            this.selected = this.selected.filter(c => !codes.includes(c));
        } else {
            codes.forEach(c => {
                if (!this.selected.includes(c)) this.selected.push(c);
            });
        }
    },
    selectAll() {
        if (this.readonly) return;
        this.selected = @js($allCodes);
    },
    clearAll() {
        if (this.readonly) return;
        this.selected = [];
    }
}" class="space-y-4">
    {{-- Toolbar: Search + Summary + Bulk Actions --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl bg-muted/40 border border-border">
        <div class="relative flex-1 max-w-md">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-[20px]">search</span>
            <input type="text" x-model="search" placeholder="Filter permissions by module or capability..."
                class="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-border bg-background text-foreground focus:outline-hidden focus:ring-2 focus:ring-ring">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-background border border-border text-xs font-semibold text-foreground">
                <span class="material-symbols-rounded text-[16px] text-primary">verified_user</span>
                <span x-text="selected.length"></span> / {{ count($allCodes) }} Capabilities Granted
            </span>

            @if (! $readonly)
                <button type="button" @click="selectAll()"
                    class="px-2.5 py-1 rounded-lg text-xs font-semibold border border-border bg-background hover:bg-muted text-foreground transition-colors cursor-pointer">
                    Select All
                </button>
                <button type="button" @click="clearAll()"
                    class="px-2.5 py-1 rounded-lg text-xs font-semibold border border-border bg-background hover:bg-muted text-muted-foreground hover:text-foreground transition-colors cursor-pointer">
                    Clear All
                </button>
            @else
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-warning-muted text-warning border border-warning-border text-xs font-semibold">
                    <span class="material-symbols-rounded text-[14px]">lock</span>
                    System Role Locked (Immutable Wildcard)
                </span>
            @endif
        </div>
    </div>

    @if ($readonly)
        <input type="hidden" name="permissions[]" value="*">
    @endif

    {{-- Domain Cards Grid --}}
    <div class="grid grid-cols-1 {{ $compact ? '' : 'lg:grid-cols-2' }} gap-4">
        @foreach ($domains as $domain)
            @php
                $domainCodes = array_column($domain['permissions'], 'code');
            @endphp
            <div x-show="search === '' || @js(strtolower($domain['title'])).includes(search.toLowerCase()) || @js(strtolower(implode(' ', array_column($domain['permissions'], 'label')))).includes(search.toLowerCase())"
                class="rounded-xl border border-border bg-card overflow-hidden flex flex-col">
                {{-- Domain Header --}}
                <div class="px-4 py-3 bg-muted/30 border-b border-border flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="material-symbols-rounded text-[20px] text-primary shrink-0">{{ $domain['icon'] }}</span>
                        <h6 class="text-xs font-bold text-foreground truncate">{{ $domain['title'] }}</h6>
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-muted text-muted-foreground border border-border">
                            {{ $domain['scope'] }}
                        </span>
                    </div>
                    @if (! $readonly)
                        <button type="button" @click="toggleDomain(@js($domainCodes))"
                            class="text-[11px] font-semibold text-primary hover:underline shrink-0 cursor-pointer">
                            Toggle Group
                        </button>
                    @endif
                </div>

                {{-- Capabilities List --}}
                <div class="p-3.5 space-y-2.5 flex-1">
                    @foreach ($domain['permissions'] as $perm)
                        <label x-show="search === '' || @js(strtolower($perm['label'].' '.$perm['desc'].' '.$perm['code'])).includes(search.toLowerCase())"
                            class="flex items-start gap-3 p-2 rounded-lg hover:bg-muted/40 transition-colors {{ $readonly ? 'cursor-not-allowed opacity-85' : 'cursor-pointer' }}">
                            <input type="checkbox"
                                name="permissions[]"
                                value="{{ $perm['code'] }}"
                                x-model="selected"
                                @if ($readonly) disabled @endif
                                class="mt-0.5 w-4 h-4 rounded border-border text-primary focus:ring-ring transition-colors">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-xs font-semibold text-foreground">{{ $perm['label'] }}</span>
                                    @if ($perm['sod'])
                                        <span class="px-1.5 py-0.2 rounded text-[10px] font-semibold bg-warning-muted text-warning border border-warning-border" title="High-authority action subject to Segregation of Duties">
                                            SoD Control
                                        </span>
                                    @endif
                                    <code class="text-[10px] text-muted-foreground font-mono">{{ $perm['code'] }}</code>
                                </div>
                                <p class="text-[11px] text-muted-foreground mt-0.5 leading-relaxed">{{ $perm['desc'] }}</p>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

