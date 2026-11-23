<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

class AbilityRegistry
{
    /** @var array<string, Ability> */
    protected array $abilities = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(Ability $ability): self
    {
        $this->abilities[$ability->name] = $ability;

        return $this;
    }

    public function get(string $name): ?Ability
    {
        return $this->abilities[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->abilities[$name]);
    }

    /**
     * @return array<string, Ability>
     */
    public function all(): array
    {
        return $this->abilities;
    }

    /**
     * @return array<string, Ability>
     */
    public function byModule(string $module): array
    {
        return array_filter($this->abilities, fn (Ability $a) => $a->module === $module);
    }

    protected function registerDefaults(): void
    {
        // Sales module abilities
        $this->register(new Ability('sales.view', 'sales', Scope::BRANCH, false, 'View sales transactions'));
        $this->register(new Ability('sales.create', 'sales', Scope::BRANCH, false, 'Create new sales transactions'));
        $this->register(new Ability('sales.pos.operate', 'sales', Scope::BRANCH, false, 'Operate POS cashier terminal'));
        $this->register(new Ability('sales.void', 'sales', Scope::BRANCH, true, 'Void existing order'));
        $this->register(new Ability('sales.order.void', 'sales', Scope::BRANCH, true, 'Void existing order'));
        $this->register(new Ability('sales.refund', 'sales', Scope::BRANCH, true, 'Issue refund for order'));
        $this->register(new Ability('sales.discount', 'sales', Scope::BRANCH, false, 'Apply order or item discounts'));
        $this->register(new Ability('sales.split_bill', 'sales', Scope::BRANCH, false, 'Split bill order'));
        $this->register(new Ability('sales.all_branches', 'sales', Scope::TENANT, false, 'View cross-branch sales'));

        // Inventory module abilities
        $this->register(new Ability('inventory.view', 'inventory', Scope::BRANCH, false, 'View inventory stock'));
        $this->register(new Ability('inventory.transfer', 'inventory', Scope::COMPANY, false, 'Transfer stock between branches'));
        $this->register(new Ability('inventory.adjust', 'inventory', Scope::BRANCH, true, 'Adjust stock balance manually'));
        $this->register(new Ability('inventory.opname', 'inventory', Scope::BRANCH, false, 'Perform stock opname'));
        $this->register(new Ability('inventory.receive', 'inventory', Scope::BRANCH, false, 'Receive inventory goods'));

        // Purchasing module abilities
        $this->register(new Ability('purchasing.view', 'purchasing', Scope::COMPANY, false, 'View purchase orders'));
        $this->register(new Ability('purchasing.supplier.view', 'purchasing', Scope::COMPANY, false, 'View supplier records'));
        $this->register(new Ability('purchasing.order', 'purchasing', Scope::COMPANY, false, 'Create purchase order'));
        $this->register(new Ability('purchasing.approve', 'purchasing', Scope::COMPANY, true, 'Approve purchase orders'));
        $this->register(new Ability('purchasing.receive', 'purchasing', Scope::BRANCH, false, 'Receive purchase order shipments'));

        // Finance module abilities
        $this->register(new Ability('finance.view', 'finance', Scope::COMPANY, false, 'View financial journals and accounts'));
        $this->register(new Ability('finance.entry', 'finance', Scope::COMPANY, false, 'Create manual journal entries'));
        $this->register(new Ability('finance.journal.post', 'finance', Scope::COMPANY, false, 'Post financial journal entries'));
        $this->register(new Ability('finance.close_period', 'finance', Scope::COMPANY, true, 'Close accounting period'));
        $this->register(new Ability('finance.reports', 'finance', Scope::COMPANY, false, 'Generate financial statements'));
        $this->register(new Ability('finance.reconcile', 'finance', Scope::COMPANY, false, 'Reconcile bank accounts'));

        // HRM & Workforce module abilities
        $this->register(new Ability('hrm.view', 'hrm', Scope::COMPANY, false, 'View employees and departments'));
        $this->register(new Ability('hrm.attendance', 'hrm', Scope::BRANCH, false, 'Record attendance'));
        $this->register(new Ability('hrm.leave', 'hrm', Scope::SELF, false, 'Apply for leave'));
        $this->register(new Ability('hrm.payroll', 'hrm', Scope::COMPANY, false, 'Calculate payroll runs'));
        $this->register(new Ability('hrm.payroll_approve', 'hrm', Scope::TENANT, true, 'Authorize payroll disbursement'));
        $this->register(new Ability('workforce.payroll.approve', 'workforce', Scope::TENANT, true, 'Authorize workforce payroll disbursement'));

        // Organization module abilities
        $this->register(new Ability('organization.branch.switch', 'organization', Scope::COMPANY, false, 'Switch active branch context'));

        // Catalog module abilities
        $this->register(new Ability('catalog.view', 'catalog', Scope::BRANCH, false, 'View products and categories'));
        $this->register(new Ability('catalog.manage', 'catalog', Scope::TENANT, false, 'Manage product master data'));
        $this->register(new Ability('catalog.pricing', 'catalog', Scope::TENANT, false, 'Update product base pricing'));

        // Dashboard module abilities
        $this->register(new Ability('dashboard.view', 'dashboard', Scope::BRANCH, false, 'View operational dashboard'));
        $this->register(new Ability('dashboard.scope.all_stores', 'dashboard', Scope::TENANT, false, 'View consolidated multi-store dashboard'));
        $this->register(new Ability('dashboard.metric.revenue', 'dashboard', Scope::BRANCH, false, 'View net sales & revenue figures'));
        $this->register(new Ability('dashboard.metric.profit_margin', 'dashboard', Scope::TENANT, false, 'View COGS, gross profit & profit margin %'));
        $this->register(new Ability('dashboard.metric.finance_gl', 'dashboard', Scope::COMPANY, false, 'View GL reconciliation & accounting journals'));
        $this->register(new Ability('dashboard.action.approve_void_discount', 'dashboard', Scope::BRANCH, true, 'Authorize void orders and cash differences'));
        $this->register(new Ability('dashboard.perspective.branch_ops', 'dashboard', Scope::BRANCH, false, 'Access Branch Operations workspace'));
        $this->register(new Ability('dashboard.perspective.cashier', 'dashboard', Scope::BRANCH, false, 'Access Frontline Cashier workspace'));
        $this->register(new Ability('dashboard.perspective.inventory', 'dashboard', Scope::BRANCH, false, 'Access Inventory Intelligence workspace'));
        $this->register(new Ability('dashboard.perspective.finance', 'dashboard', Scope::COMPANY, false, 'Access Finance & GL workspace'));
    }
}
