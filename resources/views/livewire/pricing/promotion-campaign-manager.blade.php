<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Header Page & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">campaign</span>
                Kampanye Promosi & Diskon Otomatis
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Kelola strategi promosi multi-tipe (Diskon Otomatis, Buy X Get Y, Paket Bundle, dan Happy Hour) dengan proteksi simulasi marjin.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[18px]">add</span>
                Buat Promosi Baru
            </button>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('success') }}
        </div>
    @endif

    <!-- Filter & Search Bar -->
    <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="Cari nama atau kode promosi..."
                class="w-full pl-9 pr-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl focus:bg-white focus:border-indigo-500 focus:ring-0 outline-none transition-colors" />
        </div>

        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
            <select wire:model.live="typeFilter"
                class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-700 outline-none focus:bg-white focus:border-indigo-500">
                <option value="ALL">Semua Tipe Promosi</option>
                <option value="AUTOMATIC_DISCOUNT">Diskon Otomatis</option>
                <option value="BUY_X_GET_Y">Buy X Get Y (BXGY)</option>
                <option value="BUNDLE_PACKAGE">Paket Bundle</option>
                <option value="HAPPY_HOUR">Happy Hour</option>
            </select>

            <select wire:model.live="statusFilter"
                class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-700 outline-none focus:bg-white focus:border-indigo-500">
                <option value="ALL">Semua Status</option>
                <option value="ACTIVE">Aktif</option>
                <option value="PAUSED">Ditangguhkan (Paused)</option>
                <option value="DRAFT">Draf</option>
                <option value="EXPIRED">Kedaluwarsa</option>
            </select>
        </div>
    </div>

    <!-- Tabel Kampanye Promosi -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Kode & Nama Promosi</th>
                        <th class="px-6 py-3.5">Tipe & Jadwal</th>
                        <th class="px-6 py-3.5">Prioritas</th>
                        <th class="px-6 py-3.5">Kuota / Terpakai</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($campaigns as $campaign)
                        <tr class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900">{{ $campaign->name }}</div>
                                <div class="text-xs font-mono text-indigo-600 mt-0.5">{{ $campaign->campaign_code }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                    {{ $campaign->type === 'HAPPY_HOUR' ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800' }}">
                                    {{ str_replace('_', ' ', $campaign->type) }}
                                </span>
                                @if($campaign->type === 'HAPPY_HOUR' && $campaign->start_time && $campaign->end_time)
                                    <div class="text-xs text-amber-700 mt-1 font-medium flex items-center gap-1">
                                        <span class="material-symbols-rounded text-[14px]">schedule</span>
                                        {{ substr($campaign->start_time, 0, 5) }} - {{ substr($campaign->end_time, 0, 5) }}
                                    </div>
                                @endif
                                <div class="text-xs text-gray-400 mt-1">
                                    {{ \Carbon\Carbon::parse($campaign->start_datetime)->format('d M Y') }} - {{ \Carbon\Carbon::parse($campaign->end_datetime)->format('d M Y') }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-gray-100 text-xs font-bold text-gray-700">
                                    {{ $campaign->priority }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs font-medium text-gray-600">
                                <div>Terpakai: <span class="font-bold text-gray-900">{{ $campaign->current_usage_count }}</span></div>
                                <div class="text-gray-400 text-[11px]">Batas: {{ $campaign->max_usage_limit ?: 'Tak Terbatas' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($campaign->status === 'ACTIVE')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">AKTIF</span>
                                @elseif ($campaign->status === 'PAUSED')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">PAUSED</span>
                                @elseif ($campaign->status === 'DRAFT')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600">DRAFT</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800">{{ $campaign->status }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right space-x-1">
                                <button type="button" wire:click="triggerMarginSimulation({{ $campaign->id }})"
                                    title="Simulasi Marjin Kotor"
                                    class="p-1.5 text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 rounded-lg transition-colors">
                                    <span class="material-symbols-rounded text-[20px]">show_chart</span>
                                </button>
                                <button type="button" wire:click="openEditModal({{ $campaign->id }})"
                                    title="Edit Kampanye"
                                    class="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <button type="button" wire:click="toggleStatus({{ $campaign->id }})"
                                    title="Ubah Status"
                                    class="p-1.5 {{ $campaign->status === 'ACTIVE' ? 'text-amber-600 hover:text-amber-800 hover:bg-amber-50' : 'text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50' }} rounded-lg transition-colors">
                                    <span class="material-symbols-rounded text-[20px]">
                                        {{ $campaign->status === 'ACTIVE' ? 'pause_circle' : 'play_circle' }}
                                    </span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                <span class="material-symbols-rounded text-[40px] text-gray-300 block mb-2">campaign</span>
                                Belum ada kampanye promosi yang terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-100">
            {{ $campaigns->links() }}
        </div>
    </div>

    <!-- Modal Form Create / Edit Promosi -->
    @if ($isFormModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="bg-white rounded-2xl max-w-2xl w-full p-6 space-y-5 shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ $editingCampaignId ? 'Ubah Kampanye Promosi' : 'Buat Kampanye Promosi Baru' }}
                    </h3>
                    <button wire:click="$set('isFormModalOpen', false)" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Kode Promosi</label>
                            <input type="text" wire:model="campaignCode" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl uppercase font-mono" required />
                            @error('campaignCode') <span class="text-rose-600 text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nama Promosi</label>
                            <input type="text" wire:model="name" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" placeholder="Contoh: Diskon Kemerdekaan" required />
                            @error('name') <span class="text-rose-600 text-xs">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Tipe Promosi</label>
                            <select wire:model.live="type" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl font-medium">
                                <option value="AUTOMATIC_DISCOUNT">Diskon Otomatis Keranjang</option>
                                <option value="BUY_X_GET_Y">Buy X Get Y (BXGY)</option>
                                <option value="BUNDLE_PACKAGE">Paket Bundle</option>
                                <option value="HAPPY_HOUR">Happy Hour (Jadwal Khusus)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Prioritas Evaluasi (1-100)</label>
                            <input type="number" wire:model="priority" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" min="1" max="100" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Mulai Berlaku</label>
                            <input type="datetime-local" wire:model="startDatetime" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" required />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Berakhir Pada</label>
                            <input type="datetime-local" wire:model="endDatetime" class="w-full text-sm px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl" required />
                        </div>
                    </div>

                    @if ($type === 'HAPPY_HOUR')
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl space-y-3">
                            <span class="text-xs font-bold text-amber-900 block">Pengaturan Jadwal Happy Hour</span>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold text-amber-800 mb-1">Jam Mulai</label>
                                    <input type="time" wire:model="startTime" class="w-full text-xs px-2.5 py-1.5 bg-white border border-amber-300 rounded-lg" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-amber-800 mb-1">Jam Selesai</label>
                                    <input type="time" wire:model="endTime" class="w-full text-xs px-2.5 py-1.5 bg-white border border-amber-300 rounded-lg" />
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="p-3 bg-gray-50 border border-gray-200 rounded-xl space-y-3">
                        <span class="text-xs font-bold text-gray-800 block">Besaran Diskon & Syarat</span>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-[11px] font-semibold text-gray-700 mb-1">Tipe Diskon</label>
                                <select wire:model.live="rewardType" class="w-full text-xs px-2.5 py-1.5 bg-white border border-gray-300 rounded-lg font-medium">
                                    <option value="PERCENTAGE_DISCOUNT">Persentase (%)</option>
                                    <option value="FIXED_AMOUNT_DISCOUNT">Nominal Tetap (Rp)</option>
                                </select>
                            </div>
                            @if ($rewardType === 'PERCENTAGE_DISCOUNT')
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Diskon (%)</label>
                                    <input type="number" step="0.01" wire:model="discountPercentage" class="w-full text-xs px-2.5 py-1.5 bg-white border border-gray-300 rounded-lg" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Maks Diskon (Rp)</label>
                                    <input type="number" wire:model="maxDiscountAmount" class="w-full text-xs px-2.5 py-1.5 bg-white border border-gray-300 rounded-lg" placeholder="Opsional" />
                                </div>
                            @else
                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Nominal Diskon (Rp)</label>
                                    <input type="number" wire:model="discountFixedAmount" class="w-full text-xs px-2.5 py-1.5 bg-white border border-gray-300 rounded-lg" placeholder="Contoh: 15000" />
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100">
                        <button type="button" wire:click="$set('isFormModalOpen', false)"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl transition-colors">
                            Batal
                        </button>
                        <button type="submit"
                            class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm transition-colors">
                            Simpan Kampanye
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Livewire Modal Simulasi Marjin --}}
    <livewire:pricing.margin-simulation-modal />
</div>
