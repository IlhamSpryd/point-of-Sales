<div class="space-y-6" data-gsap="page-enter">
    <!-- Sub Navigasi Modular SDM & Payroll -->
    @include('livewire.hr.partials.sub-nav')

    <!-- Header & Action Halaman -->
    <x-erp.page-header
        title="Employee Directory & Workforce"
        description="Manage staff master profiles, department hierarchy, job titles, and employee lifecycles.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('hr.employees.index') }}">HR & Payroll</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Employee Directory</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            @can('create', \App\Modules\HR\Models\Employee::class)
            <x-ui.button variant="primary" wire:click="openCreateModal" class="gap-2">
                <span class="material-symbols-rounded text-base">person_add</span>
                <span>Add New Employee</span>
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Feedback Alerts -->
    @if (session()->has('success'))
        <x-ui.alert type="success" title="Success" class="rounded-2xl">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="Error" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- KPI & Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-erp.stat-card
            title="Total Employees"
            :value="$totalEmployees"
            icon="groups"
            trend-label="Registered in directory"
        />
        <x-erp.stat-card
            title="Active Employees"
            :value="$activeEmployees"
            icon="verified_user"
            trend-label="Currently active"
            variant="success"
        />
        <x-erp.stat-card
            title="Contract Employees"
            :value="$contractEmployees"
            icon="history_edu"
            trend-label="Fixed-term contracts"
        />
        <x-erp.stat-card
            title="Total Departments"
            :value="$totalDepartments"
            icon="apartment"
            trend-label="Active organizational units"
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
                    placeholder="Search name, national ID, or employee number..."
                    class="w-full pl-10 pr-4 py-2 text-xs sm:text-sm rounded-xl border border-input bg-background text-foreground placeholder:text-muted-foreground focus:outline-hidden focus:ring-2 focus:ring-primary/20 transition"
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.select wire:model.live="departmentFilter" class="w-auto text-xs sm:text-sm">
                <option value="">All Departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="employmentTypeFilter" class="w-auto text-xs sm:text-sm">
                <option value="">All Employment Types</option>
                <option value="PERMANENT">Permanent (PKWTT)</option>
                <option value="CONTRACT_PKWT">Contract (PKWT)</option>
                <option value="PROBATION">Probation</option>
                <option value="INTERN">Internship</option>
                <option value="DAILY_WORKER">Daily Worker</option>
            </x-ui.select>

            <x-ui.select wire:model.live="statusFilter" class="w-auto text-xs sm:text-sm">
                <option value="ALL">All Statuses</option>
                <option value="ACTIVE">Active Only</option>
                <option value="INACTIVE">Inactive Only</option>
            </x-ui.select>

            @if ($search !== '' || $departmentFilter !== '' || $employmentTypeFilter !== '' || $statusFilter !== 'ALL')
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    wire:click="resetFilters"
                    title="Reset Filters"
                    class="h-9 px-3 gap-1.5 border-border bg-card hover:bg-secondary text-muted-foreground hover:text-foreground">
                    <span class="material-symbols-rounded text-base">restart_alt</span>
                    <span>Reset</span>
                </x-ui.button>
            @endif
        </div>
    </div>

    @php
        $employeeColumns = [
            ['key' => 'employee', 'label' => 'Employee', 'sortable' => false],
            ['key' => 'department', 'label' => 'Department & Job Title', 'sortable' => false],
            ['key' => 'employment_type', 'label' => 'Employment Type', 'sortable' => false],
            ['key' => 'employment_status', 'label' => 'Status', 'align' => 'center', 'sortable' => false],
            ['key' => 'hire_date', 'label' => 'Hire Date', 'sortable' => false],
            ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
        ];
    @endphp

    <!-- Employee Data Table -->
    <x-erp.data-table
        :columns="$employeeColumns"
        :rows="$employees"
        loading-target="search, departmentFilter, employmentTypeFilter, statusFilter"
        :is-empty="$employees->isEmpty()"
        empty-title="No matching employee records found"
        empty-description="Try adjusting your search criteria or register a new employee."
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
                            <div class="text-xs text-muted-foreground tabular-nums flex items-center gap-1.5 mt-0.5">
                                <span>{{ $emp->employee_number }}</span>
                                <span>•</span>
                                <span class="truncate">{{ $emp->email }}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4">
                    <div class="text-sm font-medium text-foreground">{{ $emp->department?->name ?? 'Unassigned' }}</div>
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
                            Active
                        </x-ui.badge>
                    @else
                        <x-ui.badge variant="danger" size="sm">
                            Inactive
                        </x-ui.badge>
                    @endif
                </td>
                <td class="px-6 py-4 tabular-nums text-xs text-muted-foreground">
                    {{ $emp->hire_date ? $emp->hire_date->format('d M Y') : '-' }}
                </td>
                <td class="px-6 py-4 text-right whitespace-nowrap">
                    @can('update', $emp)
                    <x-ui.table-actions>
                        <x-ui.table-action-item icon="edit" wire:click="openEditModal({{ $emp->id }})">
                            Edit Employee
                        </x-ui.table-action-item>
                        <x-ui.table-action-item
                            icon="{{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'person_off' : 'check_circle' }}"
                            variant="{{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'destructive' : 'default' }}"
                            wire:click="toggleActiveStatus({{ $emp->id }})"
                        >
                            {{ $emp->employment_status === \App\Modules\HR\Enums\EmploymentStatusEnum::ACTIVE ? 'Deactivate' : 'Activate' }}
                        </x-ui.table-action-item>
                    </x-ui.table-actions>
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-erp.data-table>

    <div class="mt-4">
        {{ $employees->links() }}
    </div>

    <!-- Modal Form (Create & Edit Employee) -->
    <x-ui.modal
        wire:model="isModalOpen"
        :title="$modalMode === 'edit' ? 'Edit Employee Profile' : 'Add New Employee'"
        :description="$modalMode === 'edit' ? 'Update employee master details, department assignment, contact information, and employment status.' : 'Fill in employee details to register a new employee in the directory.'"
        max-width="2xl"
    >
        <form wire:submit="saveEmployee" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-erp.form-field label="Employee Number" name="employeeNumber" required error="{{ $errors->first('employeeNumber') }}">
                    <input
                        type="text"
                        wire:model="employeeNumber"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground tabular-nums focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Full Name" name="fullName" required error="{{ $errors->first('fullName') }}">
                    <input
                        type="text"
                        wire:model="fullName"
                        placeholder="e.g. John Doe"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Official Email" name="email" required error="{{ $errors->first('email') }}">
                    <input
                        type="email"
                        wire:model="email"
                        placeholder="employee@company.com"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Phone Number" name="phoneNumber" required error="{{ $errors->first('phoneNumber') }}">
                    <input
                        type="text"
                        wire:model="phoneNumber"
                        placeholder="+62812xxxxxxxx"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="National Identity Number (NIK)" name="nationalIdNumber" required error="{{ $errors->first('nationalIdNumber') }}">
                    <input
                        type="text"
                        wire:model="nationalIdNumber"
                        placeholder="16-digit National ID"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground tabular-nums focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Hire Date" name="hireDate" required error="{{ $errors->first('hireDate') }}">
                    <input
                        type="date"
                        wire:model="hireDate"
                        class="h-9 w-full rounded-medium border border-input bg-background px-3 text-xs sm:text-sm text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-primary/10 outline-none"
                    />
                </x-erp.form-field>

                <x-erp.form-field label="Department" name="departmentId" error="{{ $errors->first('departmentId') }}">
                    <x-ui.select wire:model="departmentId" class="w-full text-xs sm:text-sm">
                        <option value="">Select Department...</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Job Title" name="jobTitleId" error="{{ $errors->first('jobTitleId') }}">
                    <x-ui.select wire:model="jobTitleId" class="w-full text-xs sm:text-sm">
                        <option value="">Select Job Title...</option>
                        @foreach ($jobTitles as $jt)
                            <option value="{{ $jt->id }}">{{ $jt->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Gender" name="gender" required>
                    <x-ui.select wire:model="gender" class="w-full text-xs sm:text-sm">
                        <option value="MALE">Male</option>
                        <option value="FEMALE">Female</option>
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Employment Type" name="employmentType" required>
                    <x-ui.select wire:model="employmentType" class="w-full text-xs sm:text-sm">
                        <option value="PERMANENT">Permanent (PKWTT)</option>
                        <option value="CONTRACT_PKWT">Contract (PKWT)</option>
                        <option value="PROBATION">Probation</option>
                        <option value="INTERN">Internship</option>
                        <option value="DAILY_WORKER">Daily Worker</option>
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Marital Status" name="maritalStatus" required>
                    <x-ui.select wire:model="maritalStatus" class="w-full text-xs sm:text-sm">
                        <option value="SINGLE">Single</option>
                        <option value="MARRIED">Married</option>
                        <option value="WIDOWED">Widowed</option>
                    </x-ui.select>
                </x-erp.form-field>

                <x-erp.form-field label="Religion" name="religion" required>
                    <x-ui.select wire:model="religion" class="w-full text-xs sm:text-sm">
                        <option value="ISLAM">Islam</option>
                        <option value="PROTESTANT">Protestant</option>
                        <option value="CATHOLIC">Catholic</option>
                        <option value="HINDU">Hindu</option>
                        <option value="BUDDHIST">Buddhist</option>
                        <option value="CONFUCIAN">Confucian</option>
                        <option value="OTHER">Other</option>
                    </x-ui.select>
                </x-erp.form-field>
            </div>

            @if ($modalMode === 'create')
                <div class="pt-4 border-t border-border mt-4 space-y-4">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="createSystemAccount" wire:model.live="createSystemAccount" class="rounded border-border text-primary focus:ring-ring">
                        <label for="createSystemAccount" class="text-xs sm:text-sm font-semibold text-foreground cursor-pointer flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base text-primary">key</span>
                            Grant System Login & Cashier Access (Berikan Akses Login Sistem & Kasir)
                        </label>
                    </div>

                    @if ($createSystemAccount)
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 rounded-xl bg-muted/40 border border-border">
                            <x-erp.form-field label="System Role" name="systemRoleId" required>
                                <x-ui.select wire:model="systemRoleId" class="w-full text-xs sm:text-sm">
                                    <option value="">Select Role</option>
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->id }}">{{ $role->name }} ({{ $role->display_name ?? $role->name }})</option>
                                    @endforeach
                                </x-ui.select>
                            </x-erp.form-field>

                            <x-erp.form-field label="Web Password" name="systemPassword" required>
                                <x-ui.input type="password" wire:model="systemPassword" placeholder="Min. 8 characters" class="w-full text-xs sm:text-sm" />
                            </x-erp.form-field>

                            <x-erp.form-field label="Cashier PIN (Optional)" name="cashierPin">
                                <x-ui.input type="password" wire:model="cashierPin" maxlength="6" placeholder="4-6 digits" class="w-full text-xs sm:text-sm" />
                            </x-erp.form-field>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border mt-6">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit" variant="primary">
                    {{ $modalMode === 'edit' ? 'Save Changes' : 'Save Employee' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
