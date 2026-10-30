<nav class="flex items-center gap-1 overflow-x-auto pb-2 border-b border-[#E5E7EB] mb-6 text-sm font-medium">
    <a href="{{ route('hr.employees.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.employees.*') ? 'bg-[#171717] text-white' : 'text-[#737373] hover:text-[#171717] hover:bg-[#F5F5F5]' }}">
        Karyawan
    </a>
    <a href="{{ route('hr.schedules.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.schedules.*') ? 'bg-[#171717] text-white' : 'text-[#737373] hover:text-[#171717] hover:bg-[#F5F5F5]' }}">
        Jadwal & Roster
    </a>
    <a href="{{ route('hr.attendance.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.attendance.*') ? 'bg-[#171717] text-white' : 'text-[#737373] hover:text-[#171717] hover:bg-[#F5F5F5]' }}">
        Absensi & Lembur
    </a>
    <a href="{{ route('hr.leaves.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.leaves.*') ? 'bg-[#171717] text-white' : 'text-[#737373] hover:text-[#171717] hover:bg-[#F5F5F5]' }}">
        Manajemen Cuti
    </a>
    <a href="{{ route('hr.commissions.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.commissions.*') ? 'bg-[#171717] text-white' : 'text-[#737373] hover:text-[#171717] hover:bg-[#F5F5F5]' }}">
        Komisi Penjualan
    </a>
    <a href="{{ route('hr.payroll.index') }}" class="px-3.5 py-1.5 rounded-lg {{ request()->routeIs('hr.payroll.*') ? 'bg-[#171717] text-white' : 'text-[#737373] hover:text-[#171717] hover:bg-[#F5F5F5]' }}">
        Penggajian (Payroll)
    </a>
</nav>
