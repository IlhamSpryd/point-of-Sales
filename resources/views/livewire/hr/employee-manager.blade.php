<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular SDM & Payroll -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Master Karyawan & Tenaga Kerja"
        description="Kelola data induk staf, struktur departemen, jabatan organisasi, serta siklus hidup kepegawaian.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">SDM & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Master Karyawan</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            @can('create', \App\Modules\HR\Models\Employee::class)
            <x-ui.button variant="primary" wire:click="openCreateModal" class="gap-2">
                <span class="material-symbols-rounded text-base">person_add</span>
                <span>Tambah Karyawan Baru</span>
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Feedback Alerts -->
    @if (session()->has('success'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="Terjadi Kesalahan" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- KPI & Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-erp.stat-card
            title="Total Karyawan"
            :value="$totalEmployees"
            icon="groups"
            trend-label="Terdaftar dalam database"
        />
        <x-erp.stat-card
            title="Karyawan Aktif"
            :value="$activeEmployees"
            icon="verified_user"
            trend-label="Status kerja aktif"
            variant="success"
        />
        <x-erp.stat-card
            title="Karyawan Kontrak (PKWT)"
            :value="$contractEmployees"
            icon="history_edu"
            trend-label="Perjanjian kerja berjangka"
        />
        <x-erp.stat-card
            title="Total Departemen"
            :value="$totalDepartments"
            icon="apartment"
            trend-label="Struktur unit kerja aktif"
        />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-border">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground text-lg">search</span>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari nama, NIK, atau nomor karyawan..."
                    class="w-full pl-10 pr-4 py-2 text-xs sm:text-sm rounded-xl border border-input bg-background text-foreground placeholder:text-muted-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20 transition"
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.select wire:model.live="departmentFilter" class="w-auto text-xs sm:text-sm">
                <option value="">Semua Departemen</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="employmentTypeFilter" class="w-auto text-xs sm:text-sm">
                <option value="">Semua Tipe Kerja</option>
                <option value="PERMANENT">Karyawan Tetap (PKWTT)</option>
                <option value="CONTRACT_PKWT">Karyawan Kontrak (PKWT)</option>
                <option value="PROBATION">Masa Percobaan (Probation)</option>
                <option value="INTERN">Magang (Internship)</option>
                <option value="DAILY_WORKER">Pekerja Harian Lepas</option>
            </x-ui.select>

            <x-ui.select wire:model.live="statusFilter" class="w-auto text-xs sm:text-sm">
                <option value="ALL">Semua Status</option>
                <option value="ACTIVE">Aktif Saja</option>
                <option value="INACTIVE">Nonaktif Saja</option>
            </x-ui.select>

            @if ($search !== '' || $departmentFilter !== '' || $employmentTypeFilter !== '' || $statusFilter !== 'ALL')
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    wire:click="resetFilters"
                    title="Reset Filter"
                    class="h-9 px-3 gap-1.5 border-border bg-card hover:bg-secondary text-muted-foreground hover:text-foreground">
                    <span class="material-symbols-rounded text-base">restart_alt</span>
                    <span>Reset</span>
                </x-ui.button>
            @endif
        </div>
    </div>

    @php
        $employeeColumns = [
            ['key' => 'employee', 'label' => 'Karyawan', 'sortable' => false],
            ['key' => 'department', 'label' => 'Departemen & Jabatan', 'sortable' => false],
            ['key' => 'employment_type', 'label' => 'Tipe Hubungan Kerja', 'sortable' => false],
            ['key' => 'employment_status', 'label' => 'Status', 'align' => 'center', 'sortable' => false],
            ['key' => 'hire_date', 'label' => 'Tanggal Bergabung', 'sortable' => false],
            ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'sortable' => false],
        ];
    @endphp

    <!-- Tabel Data Karyawan -->
    <x-erp.data-table
        :columns="$employeeColumns"
        :rows="$employees"
        loading-target="search, departmentFilter, employmentTypeFilter, statusFilter"
        :is-empty="$employees->isEmpty()"
        empty-title="Tidak ada data karyawan yang cocok"
        empty-description="Coba ubah kata kunci pencarian atau daftarkan karyawan baru ke dalam sistem."
        empty-icon="group_off"
        :colspan="6"
    >
        @foreach ($employees as $emp)
            <tr class="hover:bg-muted/30 transition-colors">
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-xs shrink-0 select-none">
                            {{ strtoupper(substr($emp->full_name, 0, 2)) }}
                        </div>
                        <div class="min-w-0">
                            <div class="font-semibold text-foreground truncate">{{ $emp->full_name }}</div>
                            <div class="text-xs text-muted-foreground font-mono flex items-center gap-1.5 mt-0.5">
                                <span>{{ $emp->employee_number }}</span>
                                <span>•</span>
                                <span class="truncate">{{ $emp->email }}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4">
                    <div class="text-sm font-medium text-foreground">{{ $emp->department?->name ?? 'Belum Ditentukan' }}</div>
                    <div class="text-xs text-muted-foreground">{{ $emp->jobTitle?->name ?? '-' }}</div>
                </td>
                <td class="px-6 py-4">
                    <x-ui.badge variant="secondary" size="sm">
                        {{ $emp->employment_type->label() }}
                    </x-ui.badge>
                </td>
                <td class="px-6 py-4 text-center">
                    @if ($emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE)
                        <x-ui.badge variant="success" size="sm">
                            Aktif
                        </x-ui.badge>
                    @else
                        <x-ui.badge variant="danger" size="sm">
                            Nonaktif
                        </x-ui.badge>
                    @endif
                </td>
                <td class="px-6 py-4 font-mono text-xs text-muted-foreground">
                    {{ $emp->hire_date ? $emp->hire_date->format('d M Y') : '-' }}
                </td>
                <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        @can('update', $emp)
                        <x-ui.button
                            size="sm"
                            variant="secondary"
                            wire:click="openEditModal({{ $emp->id }})"
                            title="Edit Data Karyawan"
                            aria-label="Edit Karyawan"
                            class="h-8 px-2.5 text-xs gap-1 border-border bg-card hover:bg-secondary text-foreground shadow-2xs">
                            <span class="material-symbols-rounded text-sm">edit</span>
                            <span>Edit</span>
                        </x-ui.button>

                        <x-ui.button
                            size="sm"
                            variant="{{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'secondary' : 'primary' }}"
                            wire:click="toggleActiveStatus({{ $emp->id }})"
                            title="{{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'Nonaktifkan Karyawan' : 'Aktifkan Karyawan' }}"
                            aria-label="Ubah Status Karyawan"
                            class="h-8 px-2.5 text-xs gap-1 border-border shadow-2xs">
                            <span class="material-symbols-rounded text-sm">{{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'person_off' : 'check_circle' }}</span>
                            <span>{{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'Nonaktifkan' : 'Aktifkan' }}</span>
                        </x-ui.button>
                        @endcan
                    </div>
                </td>
            </tr>
        @endforeach
    </x-erp.data-table>

    <div class="mt-4">
        {{ $employees->links() }}
    </div>

    <!-- Modal Form (Create & Edit Karyawan) -->
    <x-ui.modal
        wire:model="isModalOpen"
        :title="$modalMode === 'edit' ? 'Edit Data Karyawan' : 'Tambah Karyawan Baru'"
        :description="$modalMode === 'edit' ? 'Perbarui informasi data induk, departemen, kontak, dan status kepegawaian.' : 'Isi formulir data induk karyawan baru untuk didaftarkan dalam sistem.'"
        max-width="2xl"
    >
        <form wire:submit="saveEmployee" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-erp.form-field label="Nomor Karyawan" name="employeeNumber" required error="{{ $errors->first('employeeNumber') }}">
                    <input
                        type="text"
                        wire:model="employeeNumber"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground font-mono focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Nama Lengkap" name="fullName" required error="{{ $errors->first('fullName') }}">
                    <input
                        type="text"
                        wire:model="fullName"
                        placeholder="mis. Budi Santoso"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Email Resmi" name="email" required error="{{ $errors->first('email') }}">
                    <input
                        type="email"
                        wire:model="email"
                        placeholder="nama@perusahaan.com"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Nomor Telepon" name="phoneNumber" required error="{{ $errors->first('phoneNumber') }}">
                    <input
                        type="text"
                        wire:model="phoneNumber"
                        placeholder="0812xxxxxxxx"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Nomor Induk Kependudukan (NIK)" name="nationalIdNumber" required error="{{ $errors->first('nationalIdNumber') }}">
                    <input
                        type="text"
                        wire:model="nationalIdNumber"
                        placeholder="16 digit NIK KTP"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground font-mono focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Tanggal Mulai Kerja" name="hireDate" required error="{{ $errors->first('hireDate') }}">
                    <input
                        type="date"
                        wire:model="hireDate"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Departemen" name="departmentId" error="{{ $errors->first('departmentId') }}">
                    <x-ui.select wire:model="departmentId" class="w-full text-xs sm:text-sm">
                        <option value="">Pilih Departemen...</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Jabatan" name="jobTitleId" error="{{ $errors->first('jobTitleId') }}">
                    <x-ui.select wire:model="jobTitleId" class="w-full text-xs sm:text-sm">
                        <option value="">Pilih Jabatan...</option>
                        @foreach ($jobTitles as $jt)
                            <option value="{{ $jt->id }}">{{ $jt->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Jenis Kelamin" name="gender" required>
                    <x-ui.select wire:model="gender" class="w-full text-xs sm:text-sm">
                        <option value="MALE">Laki-laki</option>
                        <option value="FEMALE">Perempuan</option>
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Tipe Kepegawaian" name="employmentType" required>
                    <x-ui.select wire:model="employmentType" class="w-full text-xs sm:text-sm">
                        <option value="PERMANENT">Karyawan Tetap (PKWTT)</option>
                        <option value="CONTRACT_PKWT">Karyawan Kontrak (PKWT)</option>
                        <option value="PROBATION">Masa Percobaan (Probation)</option>
                        <option value="INTERN">Magang (Internship)</option>
                        <option value="DAILY_WORKER">Pekerja Harian Lepas</option>
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Status Pernikahan" name="maritalStatus" required>
                    <x-ui.select wire:model="maritalStatus" class="w-full text-xs sm:text-sm">
                        <option value="SINGLE">Lajang (Single)</option>
                        <option value="MARRIED">Menikah (Married)</option>
                        <option value="WIDOWED">Duda / Janda (Widowed)</option>
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Agama" name="religion" required>
                    <x-ui.select wire:model="religion" class="w-full text-xs sm:text-sm">
                        <option value="ISLAM">Islam</option>
                        <option value="PROTESTANT">Kristen Protestan</option>
                        <option value="CATHOLIC">Katolik</option>
                        <option value="HINDU">Hindu</option>
                        <option value="BUDDHIST">Buddha</option>
                        <option value="CONFUCIAN">Konghucu</option>
                        <option value="OTHER">Lainnya</option>
                    </x-ui.select>
                </x-erp.form-field>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border mt-6">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">
                    Batal
                </x-ui.button>
                <x-ui.button type="submit" variant="primary">
                    {{ $modalMode === 'edit' ? 'Simpan Perubahan' : 'Simpan Karyawan' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
