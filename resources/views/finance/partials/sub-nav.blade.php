<nav class="flex items-center gap-1 overflow-x-auto pb-2 border-b border-[#E5E7EB] dark:border-zinc-800 mb-6 text-sm font-medium">
    <a href="{{ route('finance.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.index') ? 'bg-[#171717] text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-[#737373] hover:text-[#171717] dark:hover:text-white hover:bg-[#F5F5F5] dark:hover:bg-zinc-800' }}">
        Ringkasan Keuangan
    </a>
    <a href="{{ route('finance.coa.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.coa.*') ? 'bg-[#171717] text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-[#737373] hover:text-[#171717] dark:hover:text-white hover:bg-[#F5F5F5] dark:hover:bg-zinc-800' }}">
        Bagan Akun (COA)
    </a>
    <a href="{{ route('finance.journals.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.journals.*') ? 'bg-[#171717] text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-[#737373] hover:text-[#171717] dark:hover:text-white hover:bg-[#F5F5F5] dark:hover:bg-zinc-800' }}">
        Jurnal Umum
    </a>
    <a href="{{ route('finance.periods.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.periods.*') ? 'bg-[#171717] text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-[#737373] hover:text-[#171717] dark:hover:text-white hover:bg-[#F5F5F5] dark:hover:bg-zinc-800' }}">
        Periode Akuntansi
    </a>
    <a href="{{ route('finance.reconciliation.index') }}"
       class="px-3.5 py-1.5 rounded-lg transition-colors {{ request()->routeIs('finance.reconciliation.*') ? 'bg-[#171717] text-white dark:bg-white dark:text-zinc-950 font-semibold' : 'text-[#737373] hover:text-[#171717] dark:hover:text-white hover:bg-[#F5F5F5] dark:hover:bg-zinc-800' }}">
        Rekonsiliasi Bank
    </a>
</nav>
