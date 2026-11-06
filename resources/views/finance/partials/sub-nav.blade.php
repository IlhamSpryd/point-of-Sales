<nav class="flex items-center gap-1 overflow-x-auto pb-2 border-b border-border dark:border-zinc-800 mb-6 text-sm font-medium">
    <a href="{{ route('finance.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.index') ? 'bg-primary text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-muted-foreground hover:text-foreground dark:hover:text-white hover:bg-muted dark:hover:bg-zinc-800' }}">
        Financial Overview
    </a>
    <a href="{{ route('finance.coa.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.coa.*') ? 'bg-primary text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-muted-foreground hover:text-foreground dark:hover:text-white hover:bg-muted dark:hover:bg-zinc-800' }}">
        Chart of Accounts (COA)
    </a>
    <a href="{{ route('finance.journals.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.journals.*') ? 'bg-primary text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-muted-foreground hover:text-foreground dark:hover:text-white hover:bg-muted dark:hover:bg-zinc-800' }}">
        General Journal
    </a>
    <a href="{{ route('finance.periods.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.periods.*') ? 'bg-primary text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-muted-foreground hover:text-foreground dark:hover:text-white hover:bg-muted dark:hover:bg-zinc-800' }}">
        Accounting Periods
    </a>
    <a href="{{ route('finance.reconciliation.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.reconciliation.*') ? 'bg-primary text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-muted-foreground hover:text-foreground dark:hover:text-white hover:bg-muted dark:hover:bg-zinc-800' }}">
        Bank Reconciliation
    </a>
</nav>
