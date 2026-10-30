<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <!-- Sub Navigasi Modular -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600 text-[28px]">badge</span>
                Master Karyawan & Tenaga Kerja
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Kelola data induk staf, struktur departemen, kontak, serta siklus hidup kepegawaian.
            </p>
        </div>
        <div>
            <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors cursor-pointer">
                <span class="material-symbols-rounded text-[18px]">person_add</span>
                Tambah Karyawan Baru
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
                placeholder="Cari nama, NIK, atau nomor karyawan..."
                class="w-full pl-9 pr-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl focus:bg-white focus:border-indigo-500 focus:ring-0 outline-none transition-colors" />
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <select wire:model.live="departmentFilter"
                class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-700 outline-none focus:bg-white focus:border-indigo-500">
                <option value="">Semua Departemen</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter"
                class="text-xs font-semibold px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-gray-700 outline-none focus:bg-white focus:border-indigo-500">
                <option value="ALL">Semua Status</option>
                <option value="ACTIVE">Aktif Saja</option>
                <option value="INACTIVE">Nonaktif Saja</option>
            </select>
        </div>
    </div>

    <!-- Tabel Data Karyawan -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700 divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Karyawan</th>
                        <th class="px-6 py-3.5">Departemen & Jabatan</th>
                        <th class="px-6 py-3.5">Tipe Hubungan Kerja</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($employees as $emp)
                        <tr class="hover:bg-gray-50/60 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-indigo-50 text-indigo-700 font-bold flex items-center justify-center text-xs">
                                        {{ strtoupper(substr($emp->full_name, 0, 2)) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900">{{ $emp->full_name }}</div>
                                        <div class="text-xs text-gray-500 font-mono">{{ $emp->employee_number }} • {{ $emp->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-800">{{ $emp->department?->name ?? 'Belum Ditentukan' }}</div>
                                <div class="text-xs text-gray-500">{{ $emp->jobTitle?->name ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    {{ $emp->employment_type->value }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @if ($emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                        Nonaktif
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button wire:click="toggleActiveStatus({{ $emp->id }})"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-gray-700 transition-colors cursor-pointer">
                                    {{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'Nonaktifkan' : 'Aktifkan' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-gray-300">group_off</span>
                                Tidak ada data karyawan yang cocok dengan kriteria pencarian.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-200">
            {{ $employees->links() }}
        </div>
    </div>

    <!-- Modal Tambah Karyawan -->
    @if ($isCreateModalOpen)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-6">
                <div class="flex items-center justify-between border-b border-gray-100 pb-4">
                    <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-rounded text-indigo-600">person_add</span>
                        Tambah Karyawan Baru
                    </h3>
                    <button wire:click="closeCreateModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>

                <form wire:submit="saveEmployee" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nomor Karyawan</label>
                            <input type="text" wire:model="employeeNumber" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                            @error('employeeNumber') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nama Lengkap</label>
                            <input type="text" wire:model="fullName" placeholder="mis. John Doe" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500" />
                            @error('fullName') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Email Resmi</label>
                            <input type="email" wire:model="email" placeholder="john@perusahaan.com" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500" />
                            @error('email') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nomor Telepon</label>
                            <input type="text" wire:model="phoneNumber" placeholder="0812xxxxxxxx" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500" />
                            @error('phoneNumber') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nomor Induk Kependudukan (NIK)</label>
                            <input type="text" wire:model="nationalIdNumber" placeholder="16 digit NIK KTP" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500 font-mono" />
                            @error('nationalIdNumber') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Tanggal Mulai Kerja</label>
                            <input type="date" wire:model="hireDate" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500" />
                            @error('hireDate') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Departemen</label>
                            <select wire:model="departmentId" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500">
                                <option value="">Pilih Departemen...</option>
                                @foreach ($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                            @error('departmentId') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Jabatan</label>
                            <select wire:model="jobTitleId" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500">
                                <option value="">Pilih Jabatan...</option>
                                @foreach ($jobTitles as $jt)
                                    <option value="{{ $jt->id }}">{{ $jt->name }}</option>
                                @endforeach
                            </select>
                            @error('jobTitleId') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Jenis Kelamin</label>
                            <select wire:model="gender" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500">
                                <option value="MALE">Laki-laki</option>
                                <option value="FEMALE">Perempuan</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Tipe Kepegawaian</label>
                            <select wire:model="employmentType" class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-xl outline-none focus:bg-white focus:border-indigo-500">
                                <option value="PERMANENT">Karyawan Tetap (PKWTT)</option>
                                <option value="CONTRACT">Karyawan Kontrak (PKWT)</option>
                                <option value="PROBATION">Masa Percobaan (Probation)</option>
                                <option value="INTERN">Magang (Internship)</option>
                                <option value="PART_TIME">Paruh Waktu (Part Time)</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <button type="button" wire:click="closeCreateModal" class="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800 cursor-pointer">
                            Batal
                        </button>
                        <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors cursor-pointer">
                            Simpan Karyawan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
