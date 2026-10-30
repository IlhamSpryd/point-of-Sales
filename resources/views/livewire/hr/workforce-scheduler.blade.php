<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">calendar_month</span>
                Jadwal & Roster Shift Kerja
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Visualisasi dan penyusunan roster jadwal kerja mingguan staf per cabang toko.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="previousWeek" class="p-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-gray-700 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-rounded text-[20px]">chevron_left</span>
            </button>
            <span class="text-sm font-bold text-gray-800 px-3 py-1.5 bg-gray-100 rounded-xl">
                Minggu: {{ \Carbon\Carbon::parse($weekStartDate)->translatedFormat('d M Y') }}
            </span>
            <button wire:click="nextWeek" class="p-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-gray-700 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-rounded text-[20px]">chevron_right</span>
            </button>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('success') }}
        </div>
    @endif

    <!-- Store Selector Filter -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Cabang:</span>
            <select wire:model.live="selectedStoreId" class="text-sm font-semibold px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 outline-none focus:bg-white focus:border-indigo-500">
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}">{{ $store->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Kalender Roster Mingguan -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3.5 w-64">Nama Karyawan</th>
                        @foreach ($days as $day)
                            <th class="px-3 py-3.5 text-center {{ $day->isToday() ? 'bg-indigo-50 text-indigo-700 font-extrabold' : '' }}">
                                <div>{{ $day->translatedFormat('D') }}</div>
                                <div class="text-xs font-mono font-normal">{{ $day->translatedFormat('d/m') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($employees as $emp)
                        <tr class="hover:bg-gray-50/60 transition-colors">
                            <td class="px-4 py-3 font-semibold text-gray-900 flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-gray-100 text-gray-700 font-bold flex items-center justify-center text-xs">
                                    {{ strtoupper(substr($emp->full_name, 0, 2)) }}
                                </div>
                                <div class="truncate max-w-[180px]">{{ $emp->full_name }}</div>
                            </td>
                            @foreach ($days as $day)
                                @php
                                    $dateStr = $day->toDateString();
                                    $sched = $schedules->first(fn($s) => $s->employee_id === $emp->id && $s->schedule_date->toDateString() === $dateStr);
                                @endphp
                                <td class="px-2 py-3 text-center align-middle {{ $day->isToday() ? 'bg-indigo-50/30' : '' }}">
                                    @if ($sched)
                                        <button wire:click="openAssignModal({{ $emp->id }}, '{{ $dateStr }}')"
                                            class="w-full px-2 py-1.5 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg text-xs font-semibold text-indigo-800 transition-colors cursor-pointer text-left">
                                            <div class="font-bold truncate">{{ $sched->shiftTemplate?->name ?? 'Shift' }}</div>
                                            <div class="text-[10px] text-indigo-600 font-mono">{{ substr($sched->shiftTemplate?->start_time ?? '', 0, 5) }}-{{ substr($sched->shiftTemplate?->end_time ?? '', 0, 5) }}</div>
                                        </button>
                                    @else
                                        <button wire:click="openAssignModal({{ $emp->id }}, '{{ $dateStr }}')"
                                            class="w-full py-2 rounded-lg border border-dashed border-gray-200 hover:border-indigo-400 hover:bg-gray-50 text-gray-300 hover:text-indigo-600 transition-colors text-xs cursor-pointer flex items-center justify-center">
                                            <span class="material-symbols-rounded text-[18px]">add</span>
                                        </button>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-400">
                                Tidak ada data karyawan aktif untuk dijadwalkan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Penetapan Shift -->
    @if ($isAssignModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5">
                <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                    <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-rounded text-indigo-600">edit_calendar</span>
                        Tetapkan Shift Kerja
                    </h3>
                    <button wire:click="closeAssignModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Tanggal</label>
                        <input type="text" value="{{ $assignDate }}" readonly class="w-full px-3 py-2 text-sm bg-gray-100 border border-gray-200 rounded-xl font-mono text-gray-600" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Pilih Pola Shift</label>
                        <select wire:model="assignShiftTemplateId" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500">
                            <option value="">Pilih Shift...</option>
                            @foreach ($shiftTemplates as $tmpl)
                                <option value="{{ $tmpl->id }}">{{ $tmpl->name }} ({{ substr($tmpl->start_time, 0, 5) }} - {{ substr($tmpl->end_time, 0, 5) }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100">
                    <button type="button" wire:click="closeAssignModal" class="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800 cursor-pointer">
                        Batal
                    </button>
                    <button type="button" wire:click="saveScheduleAssignment" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors cursor-pointer">
                        Simpan Shift
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
