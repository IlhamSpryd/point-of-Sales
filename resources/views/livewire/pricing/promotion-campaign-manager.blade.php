<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="pricing" />

    <!-- Header Page & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Promotion Campaigns & Automated Discounts
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Manage multi-type promotion strategies (Automated Discounts, Buy X Get Y, Bundles, and Happy Hour) with gross margin simulation protections.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[20px]">add</span>
                Create Campaign
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
        search-placeholder="Search by promotion name or code..."
        search-model="search"
        :reset-method="null"
    >
        <x-slot:filters>
            <select wire:model.live="typeFilter"
                class="text-xs font-semibold px-3 py-2 bg-card border border-border rounded-xl text-foreground outline-none focus:ring-1 focus:ring-ring">
                <option value="ALL">All Promotion Types</option>
                <option value="AUTOMATIC_DISCOUNT">Automatic Discount</option>
                <option value="BUY_X_GET_Y">Buy X Get Y (BXGY)</option>
                <option value="BUNDLE_PACKAGE">Bundle Package</option>
                <option value="HAPPY_HOUR">Happy Hour</option>
            </select>

            <select wire:model.live="statusFilter"
                class="text-xs font-semibold px-3 py-2 bg-card border border-border rounded-xl text-foreground outline-none focus:ring-1 focus:ring-ring">
                <option value="ALL">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="PAUSED">Paused</option>
                <option value="DRAFT">Draft</option>
                <option value="EXPIRED">Expired</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    <!-- Tabel Kampanye Promosi -->
    <div class="card-surface rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground divide-y divide-border">
                <thead class="bg-muted/50 text-2xs font-semibold text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3.5">Code & Campaign Name</th>
                        <th class="px-6 py-3.5">Type & Schedule</th>
                        <th class="px-6 py-3.5">Priority</th>
                        <th class="px-6 py-3.5">Quota / Used</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($campaigns as $campaign)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-bold text-foreground">{{ $campaign->name }}</div>
                                <div class="text-xs font-sans text-primary mt-0.5">{{ $campaign->campaign_code }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                    {{ $campaign->type === 'HAPPY_HOUR' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-primary/10 text-primary' }}">
                                    {{ str_replace('_', ' ', $campaign->type) }}
                                </span>
                                @if($campaign->type === 'HAPPY_HOUR' && $campaign->start_time && $campaign->end_time)
                                    <div class="text-xs text-amber-600 dark:text-amber-400 mt-1 font-medium flex items-center gap-1">
                                        <span class="material-symbols-rounded text-sm">schedule</span>
                                        {{ substr($campaign->start_time, 0, 5) }} - {{ substr($campaign->end_time, 0, 5) }}
                                    </div>
                                @endif
                                <div class="text-xs text-muted-foreground mt-1">
                                    {{ \Carbon\Carbon::parse($campaign->start_datetime)->format('d M Y') }} - {{ \Carbon\Carbon::parse($campaign->end_datetime)->format('d M Y') }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-muted text-xs font-bold text-foreground">
                                    {{ $campaign->priority }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs font-medium text-muted-foreground">
                                <div>Used: <span class="font-bold text-foreground">{{ $campaign->current_usage_count }}</span></div>
                                <div class="text-muted-foreground text-2xs">Limit: {{ $campaign->max_usage_limit ?: 'Unlimited' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <x-ui.badge :variant="strtolower($campaign->status)">
                                    {{ $campaign->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="show_chart" wire:click="triggerMarginSimulation({{ $campaign->id }})">
                                        Margin Simulation
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item icon="edit" wire:click="openEditModal({{ $campaign->id }})">
                                        Edit Campaign
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item
                                        icon="{{ $campaign->status === 'ACTIVE' ? 'pause_circle' : 'play_circle' }}"
                                        wire:click="toggleStatus({{ $campaign->id }})"
                                    >
                                        {{ $campaign->status === 'ACTIVE' ? 'Pause Campaign' : 'Activate Campaign' }}
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl text-muted-foreground/50 block mb-2">campaign</span>
                                No promotion campaigns registered yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-border">
            {{ $campaigns->links() }}
        </div>
    </div>

    <!-- Modal Form Create / Edit Promosi -->
    @if ($isFormModalOpen)
        <x-ui.modal wire:model="isFormModalOpen" :title="$editingCampaignId ? 'Edit Promotion Campaign' : 'Create Promotion Campaign'" maxWidth="2xl" onClose="$wire.set('isFormModalOpen', false)">
            <form id="campaign-form" wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Campaign Code</label>
                        <input type="text" wire:model="campaignCode" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl uppercase font-sans text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                        @error('campaignCode') <span class="text-destructive text-2xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Campaign Name</label>
                        <input type="text" wire:model="name" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" placeholder="e.g. Independence Day Special" required />
                        @error('name') <span class="text-destructive text-2xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Promotion Type</label>
                        <select wire:model.live="type" class="w-full text-sm px-3 py-2 bg-card border border-border rounded-xl font-medium text-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="AUTOMATIC_DISCOUNT">Automatic Cart Discount</option>
                            <option value="BUY_X_GET_Y">Buy X Get Y (BXGY)</option>
                            <option value="BUNDLE_PACKAGE">Bundle Package</option>
                            <option value="HAPPY_HOUR">Happy Hour (Time-Based)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Evaluation Priority (1-100)</label>
                        <input type="number" wire:model="priority" class="w-full text-sm px-3 py-2 bg-muted/50 border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" min="1" max="100" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">Start Date & Time</label>
                        <input type="datetime-local" wire:model="startDatetime" class="w-full text-sm px-3 py-2 bg-card border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-foreground mb-1">End Date & Time</label>
                        <input type="datetime-local" wire:model="endDatetime" class="w-full text-sm px-3 py-2 bg-card border border-border rounded-xl text-foreground focus:outline-none focus:ring-1 focus:ring-ring" required />
                    </div>
                </div>

                @if ($type === 'HAPPY_HOUR')
                    <div class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl space-y-3">
                        <span class="text-xs font-bold text-amber-600 dark:text-amber-400 block">Happy Hour Schedule Settings</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-2xs font-semibold text-amber-700 dark:text-amber-300 mb-1">Start Time</label>
                                <input type="time" wire:model="startTime" class="w-full text-xs px-2.5 py-1.5 bg-background border border-amber-500/30 rounded-lg text-foreground" />
                            </div>
                            <div>
                                <label class="block text-2xs font-semibold text-amber-700 dark:text-amber-300 mb-1">End Time</label>
                                <input type="time" wire:model="endTime" class="w-full text-xs px-2.5 py-1.5 bg-background border border-amber-500/30 rounded-lg text-foreground" />
                            </div>
                        </div>
                    </div>
                @endif

                <div class="p-3 bg-muted/50 border border-border rounded-xl space-y-3">
                    <span class="text-xs font-bold text-foreground block">Discount Value & Requirements</span>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-2xs font-semibold text-muted-foreground mb-1">Discount Type</label>
                            <select wire:model.live="rewardType" class="w-full text-xs px-2.5 py-1.5 bg-card border border-border rounded-lg font-medium text-foreground">
                                <option value="PERCENTAGE_DISCOUNT">Percentage (%)</option>
                                <option value="FIXED_AMOUNT_DISCOUNT">Fixed Amount (Rp)</option>
                            </select>
                        </div>
                        @if ($rewardType === 'PERCENTAGE_DISCOUNT')
                            <div>
                                <label class="block text-2xs font-semibold text-muted-foreground mb-1">Discount (%)</label>
                                <input type="number" step="0.01" wire:model="discountPercentage" class="w-full text-xs px-2.5 py-1.5 bg-background border border-border rounded-lg text-foreground" />
                            </div>
                            <div>
                                <label class="block text-2xs font-semibold text-muted-foreground mb-1">Max Discount (Rp)</label>
                                <input type="number" wire:model="maxDiscountAmount" class="w-full text-xs px-2.5 py-1.5 bg-background border border-border rounded-lg text-foreground" placeholder="Optional" />
                            </div>
                        @else
                            <div class="sm:col-span-2">
                                <label class="block text-2xs font-semibold text-muted-foreground mb-1">Discount Amount (Rp)</label>
                                <input type="number" wire:model="discountFixedAmount" class="w-full text-xs px-2.5 py-1.5 bg-background border border-border rounded-lg text-foreground" placeholder="e.g. 15000" />
                            </div>
                        @endif
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <div class="flex items-center justify-end gap-3 w-full">
                    <button type="button" wire:click="$set('isFormModalOpen', false)"
                        class="px-4 py-2 text-sm font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Cancel
                    </button>
                    <button type="submit" form="campaign-form"
                        class="px-5 py-2 text-sm font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl shadow-xs transition-colors">
                        Save Campaign
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Margin Simulation Modal --}}
    <livewire:pricing.margin-simulation-modal />
</div>
