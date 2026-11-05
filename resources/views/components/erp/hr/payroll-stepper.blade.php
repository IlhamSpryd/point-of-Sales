@props([
    'currentStep' => 1,
    'steps' => [
        ['step' => 1, 'title' => 'Kehadiran & Lembur', 'desc' => 'Rekap absensi & PP 35/2021'],
        ['step' => 2, 'title' => 'Komisi & Tunjangan', 'desc' => 'Perhitungan insentif staf'],
        ['step' => 3, 'title' => 'PPh 21 TER & BPJS', 'desc' => 'Potongan wajib statuter'],
        ['step' => 4, 'title' => 'Pengesahan & Posting', 'desc' => 'Finalisasi & Jurnal GL'],
    ],
])

@php
    $cur = (int) $currentStep;
@endphp

<div {{ $attributes->twMerge('w-full card-surface bg-card border border-border rounded-2xl p-4 sm:p-6 shadow-xs select-none') }}>
    <nav aria-label="Alur Penggajian">
        <ol class="flex items-center justify-between w-full gap-2 sm:gap-4 overflow-x-auto scrollbar-hide py-1">
            @foreach ($steps as $index => $s)
                @php
                    $stepNum = $s['step'] ?? ($index + 1);
                    $isCompleted = $stepNum < $cur;
                    $isActive = $stepNum === $cur;
                    $isPending = $stepNum > $cur;
                @endphp
                <li class="flex items-center gap-2.5 min-w-0 {{ !$loop->last ? 'flex-1' : '' }}">
                    {{-- Step Circle Indicator --}}
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="size-8 sm:size-9 rounded-full flex items-center justify-center font-bold text-xs shrink-0 transition-all duration-200 border {{
                            $isCompleted
                                ? 'bg-primary text-primary-foreground border-primary'
                                : ($isActive
                                    ? 'bg-primary text-primary-foreground border-primary ring-4 ring-ring shadow-xs'
                                    : 'bg-muted text-muted-foreground border-border')
                        }}">
                            @if ($isCompleted)
                                <span class="material-symbols-rounded text-base font-bold" aria-hidden="true">check</span>
                            @else
                                <span class="font-mono">{{ $stepNum }}</span>
                            @endif
                        </div>

                        {{-- Step Texts --}}
                        <div class="hidden md:block min-w-0">
                            <p class="text-xs font-bold leading-tight truncate {{ $isActive ? 'text-foreground' : 'text-muted-foreground' }}">
                                {{ $s['title'] }}
                            </p>
                            @if (!empty($s['desc']))
                                <p class="text-[10px] text-muted-foreground truncate leading-tight mt-0.5">
                                    {{ $s['desc'] }}
                                </p>
                            @endif
                        </div>
                    </div>

                    {{-- Connector Line --}}
                    @if (!$loop->last)
                        <div class="flex-1 h-0.5 mx-2 hidden sm:block rounded-full transition-all duration-200 {{ $isCompleted ? 'bg-primary' : 'bg-border' }}" aria-hidden="true"></div>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
</div>
