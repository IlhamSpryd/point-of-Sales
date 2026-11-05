@props([
    'companyName' => 'Apeiron Enterprise',
    'period' => '',
    'employeeName' => '',
    'employeeId' => '',
    'department' => '',
    'position' => '',
    'ptkpStatus' => 'TK/0',
    'earnings' => [],
    'deductions' => [],
])

@php
    $totalEarnings = collect($earnings)->sum('amount');
    $totalDeductions = collect($deductions)->sum('amount');
    $netSalary = $totalEarnings - $totalDeductions;
@endphp

<div {{ $attributes->twMerge('rounded-2xl border border-border bg-card p-6 sm:p-8 shadow-xs max-w-2xl mx-auto space-y-6') }}>
    {{-- Header Kop Surat Slip Gaji --}}
    <div class="flex items-start justify-between border-b border-border pb-5 gap-4">
        <div>
            <h3 class="text-lg font-black text-foreground tracking-tight">
                {{ $companyName }}
            </h3>
            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-widest mt-0.5">
                Slip Gaji Karyawan • Pay Slip
            </p>
        </div>
        <div class="text-right">
            <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider block">
                Periode
            </span>
            <span class="text-sm font-extrabold text-foreground font-mono">
                {{ $period }}
            </span>
        </div>
    </div>

    {{-- Employee Metadata Grid --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-muted p-4 rounded-xl border border-border text-xs">
        <div>
            <span class="text-muted-foreground text-[10px] uppercase font-bold block">Nama Karyawan</span>
            <span class="font-bold text-foreground truncate block mt-0.5">{{ $employeeName }}</span>
        </div>
        <div>
            <span class="text-muted-foreground text-[10px] uppercase font-bold block">ID Karyawan (NIK)</span>
            <span class="font-bold text-foreground font-mono block mt-0.5">{{ $employeeId }}</span>
        </div>
        <div>
            <span class="text-muted-foreground text-[10px] uppercase font-bold block">Departemen / Jabatan</span>
            <span class="font-bold text-foreground truncate block mt-0.5">{{ $department }} • {{ $position }}</span>
        </div>
        <div>
            <span class="text-muted-foreground text-[10px] uppercase font-bold block">Status Pajak PTKP</span>
            <span class="font-bold text-foreground font-mono block mt-0.5">{{ $ptkpStatus }}</span>
        </div>
    </div>

    {{-- Two Column Breakdown: Penghasilan vs Potongan --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        {{-- Column 1: Penghasilan / Earnings --}}
        <div class="space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-success border-b border-border pb-1.5 flex items-center justify-between">
                <span>Penerimaan / Earnings</span>
                <span class="material-symbols-rounded text-sm">add_circle</span>
            </h4>
            <div class="space-y-1.5 text-xs">
                @foreach ($earnings as $earn)
                    <div class="flex items-center justify-between py-1 border-b border-border">
                        <span class="text-muted-foreground">{{ $earn['label'] }}</span>
                        <span class="font-semibold text-foreground numeric font-mono">
                            Rp {{ number_format((float) ($earn['amount'] ?? 0), 0, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="pt-2 border-t border-border flex items-center justify-between text-xs font-bold">
                <span class="text-foreground">Total Penghasilan Kotor</span>
                <span class="text-success numeric font-mono text-sm">
                    Rp {{ number_format($totalEarnings, 0, ',', '.') }}
                </span>
            </div>
        </div>

        {{-- Column 2: Potongan / Deductions --}}
        <div class="space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-destructive border-b border-border pb-1.5 flex items-center justify-between">
                <span>Potongan / Deductions</span>
                <span class="material-symbols-rounded text-sm">remove_circle</span>
            </h4>
            <div class="space-y-1.5 text-xs">
                @foreach ($deductions as $deduct)
                    <div class="flex items-center justify-between py-1 border-b border-border">
                        <span class="text-muted-foreground">{{ $deduct['label'] }}</span>
                        <span class="font-semibold text-destructive numeric font-mono">
                            Rp {{ number_format((float) ($deduct['amount'] ?? 0), 0, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="pt-2 border-t border-border flex items-center justify-between text-xs font-bold">
                <span class="text-foreground">Total Potongan</span>
                <span class="text-destructive numeric font-mono text-sm">
                    Rp {{ number_format($totalDeductions, 0, ',', '.') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Net Salary / Take Home Pay Box --}}
    <div class="card-surface bg-muted p-4 rounded-xl border border-border flex items-center justify-between">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground block">
                Gaji Bersih Diterima (Take-Home Pay)
            </span>
            <span class="text-[11px] text-muted-foreground italic">
                Ditransfer ke rekening bank terdaftar karyawan
            </span>
        </div>
        <div class="text-2xl sm:text-3xl font-black text-foreground tracking-tight numeric font-mono">
            Rp {{ number_format($netSalary, 0, ',', '.') }}
        </div>
    </div>

    {{-- Signatures --}}
    <div class="pt-4 border-t border-border grid grid-cols-2 text-center text-xs text-muted-foreground">
        <div>
            <p>Disahkan Oleh,</p>
            <div class="h-16"></div>
            <p class="font-bold text-foreground underline">Manajer HR & Payroll</p>
        </div>
        <div>
            <p>Diterima Oleh,</p>
            <div class="h-16"></div>
            <p class="font-bold text-foreground underline">{{ $employeeName }}</p>
        </div>
    </div>
</div>
