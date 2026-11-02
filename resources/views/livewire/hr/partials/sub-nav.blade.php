<nav class="flex items-center gap-1 overflow-x-auto pb-2 border-b border-border mb-6 text-sm font-medium">
    <a href="{{ route('hr.employees.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.employees.*') ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
        Karyawan
    </a>
    <a href="{{ route('hr.schedules.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.schedules.*') ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
        Jadwal & Roster
    </a>
    <a href="{{ route('hr.attendance.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.attendance.*') ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
        Absensi & Lembur
    </a>
    <a href="{{ route('hr.leaves.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.leaves.*') ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
        Manajemen Cuti
    </a>
    <a href="{{ route('hr.commissions.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.commissions.*') ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
        Komisi Penjualan
    </a>
    <a href="{{ route('hr.payroll.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.payroll.*') ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted' }}">
        Penggajian (Payroll)
    </a>
</nav>
