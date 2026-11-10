<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="pricing" />

    <!-- Header Page & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Promotional Coupons & Vouchers
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Manage transaction vouchers, per-customer usage limits, and real-time quota tracking.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[20px]">add</span>
                Create Voucher
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
    <x-erp.filter-bar
        search-placeholder="Search by voucher code or name..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="statusFilter"
                class="text-xs font-semibold px-3 py-2 bg-card border border-border rounded-xl text-foreground outline-none focus:ring-1 focus:ring-ring">
                <option value="ALL">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
                <option value="EXPIRED">Expired / Depleted</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    <!-- Tabel Voucher -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-2xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Code & Voucher Name</th>
                        <th class="px-6 py-3.5">Discount Value</th>
                        <th class="px-6 py-3.5">Min. Spend</th>
                        <th class="px-6 py-3.5">Quota Usage</th>
                        <th class="px-6 py-3.5">Validity Period</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($vouchers as $voucher)
                        @php
                            $typeVal = $voucher->discount_type instanceof \App\Modules\Customers\Enums\VoucherDiscountTypeEnum ? $voucher->discount_type->value : (string) $voucher->discount_type;
                            $statusVal = $voucher->status instanceof \App\Modules\Customers\Enums\VoucherStatusEnum ? $voucher->status->value : (string) $voucher->status;
                            $percentUsed = $voucher->max_uses > 0 ? min(100, round(($voucher->used_count / $voucher->max_uses) * 100)) : 0;
                        @endphp
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-sans font-bold text-primary flex items-center gap-1.5">
                                    <span class="material-symbols-rounded text-base text-muted-foreground">confirmation_number</span>
                                    {{ $voucher->code }}
                                </div>
                                <div class="text-xs font-medium text-foreground mt-0.5">{{ $voucher->name }}</div>
                            </td>
                            <td class="px-6 py-4 font-bold text-foreground">
                                @if ($typeVal === 'PERCENTAGE')
                                    {{ (float) $voucher->discount_value }}%
                                    @if ($voucher->max_discount_amount)
                                        <div class="text-2xs font-normal text-muted-foreground">Max Rp {{ number_format((float) $voucher->max_discount_amount, 0, ',', '.') }}</div>
                                    @endif
                                @else
                                    Rp {{ number_format((float) $voucher->discount_value, 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs font-medium text-muted-foreground">
                                Rp {{ number_format((float) $voucher->min_order_amount, 0, ',', '.') }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-between text-xs font-medium text-muted-foreground mb-1">
                                    <span>{{ $voucher->used_count }} / {{ $voucher->max_uses > 0 ? $voucher->max_uses : '∞' }}</span>
                                    @if ($voucher->max_uses > 0)
                                        <span class="text-2xs text-muted-foreground">{{ $percentUsed }}%</span>
                                    @endif
                                </div>
                                @if ($voucher->max_uses > 0)
                                    <div class="w-28 h-1.5 bg-muted rounded-full overflow-hidden">
                                        <div class="h-full {{ $percentUsed >= 100 ? 'bg-destructive' : ($percentUsed >= 80 ? 'bg-amber-500' : 'bg-primary') }}" style="width: {{ $percentUsed }}%"></div>
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground">
                                <div>{{ $voucher->starts_at ? \Carbon\Carbon::parse($voucher->starts_at)->format('d M Y') : 'Now' }}</div>
                                <div class="text-2xs text-muted-foreground">to {{ $voucher->expires_at ? \Carbon\Carbon::parse($voucher->expires_at)->format('d M Y') : 'Unlimited' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <x-ui.badge :variant="strtolower($statusVal)">
                                    {{ $statusVal }}
                                </x-ui.badge>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="edit" wire:click="openEditModal({{ $voucher->id }})">
                                        Edit Voucher
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item
                                        icon="{{ $statusVal === 'ACTIVE' ? 'pause_circle' : 'play_circle' }}"
                                        wire:click="toggleStatus({{ $voucher->id }})"
                                    >
                                        {{ $statusVal === 'ACTIVE' ? 'Deactivate' : 'Activate' }}
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-muted-foreground text-xs">
                                No vouchers created yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-border">
            {{ $vouchers->links() }}
        </div>
    </div>

    <!-- Modal Form Create / Edit Voucher -->
    @if ($isFormModalOpen)
        <x-ui.modal wire:model="isFormModalOpen" :title="$editingVoucherId ? 'Edit Voucher' : 'Create New Voucher'" maxWidth="xl" onClose="$wire.set('isFormModalOpen', false)">
            <form id="voucher-form" wire:submit="save" class="space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-foreground">Voucher Code</label>
                        <button type="button" wire:click="generateCode" class="text-xs text-primary hover:text-primary/80 font-semibold flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">autorenew</span> Generate Random
                        </button>
                    </div>
                    <input type="text" wire:model="code" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl uppercase font-sans font-bold text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                    @error('code') <span class="text-destructive text-2xs mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-foreground mb-1">Voucher Name</label>
                    <input type="text" wire:model="name" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" placeholder="e.g. 20K Member Discount Voucher" required />
                    @error('name') <span class="text-destructive text-2xs mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Discount Type</label>
                        <select wire:model.live="discountType" class="w-full text-sm px-3 py-2 bg-card border border-border rounded-xl font-medium text-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="PERCENTAGE">Percentage (%)</option>
                            <option value="FIXED_AMOUNT">Fixed Amount (Rp)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Discount Value</label>
                        <input type="number" step="0.01" wire:model="discountValue" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Min. Spend (Rp)</label>
                        <input type="number" wire:model="minOrderAmount" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" min="0" />
                    </div>
                    @if ($discountType === 'PERCENTAGE')
                        <div>
                            <label class="block text-xs font-bold text-foreground mb-1">Max. Discount (Rp)</label>
                            <input type="number" wire:model="maxDiscountAmount" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" placeholder="Optional" />
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Global Usage Limit</label>
                        <input type="number" wire:model="maxUses" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" min="0" placeholder="0 = Unlimited" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Limit per Customer</label>
                        <input type="number" wire:model="maxUsesPerCustomer" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" min="1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Valid From</label>
                        <input type="datetime-local" wire:model="startsAt" class="w-full text-sm px-3 py-2 bg-card border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Expires On</label>
                        <input type="datetime-local" wire:model="expiresAt" class="w-full text-sm px-3 py-2 bg-card border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-3 w-full">
                    <button type="button" wire:click="$set('isFormModalOpen', false)"
                        class="px-4 py-2 text-sm font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Cancel
                    </button>
                    <button type="submit" form="voucher-form"
                        class="px-5 py-2 text-sm font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl shadow-xs transition-colors">
                        Save Voucher
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
