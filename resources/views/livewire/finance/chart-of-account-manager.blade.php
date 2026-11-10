<div class="space-y-6">
    @include('finance.partials.sub-nav')

    <!-- Flash Notifications -->
    @if (session()->has('success'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">check_circle</span>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <span class="material-symbols-rounded text-sm">close</span>
            </button>
        </div>
    @endif

    <!-- Header Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-zinc-950 dark:text-white tracking-tight">Chart of Accounts</h1>
            <p class="text-xs text-zinc-500">Multi-tenant dual-entry general ledger chart of accounts hierarchy.</p>
        </div>
        <button type="button"
                wire:click="openCreateModal"
                class="inline-flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
            <span class="material-symbols-rounded text-base">add</span>
            <span>Add Account</span>
        </button>
    </div>

    <!-- Filter & Search Toolbar -->
    <x-erp.filter-bar search-placeholder="Search account code or name...">
        <x-slot:filters>
            <select wire:model.live="typeFilter" class="h-9 text-xs rounded-xl border border-input bg-background text-foreground px-3 outline-none">
                <option value="">All Account Types</option>
                @foreach ($accountTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->value }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" class="h-9 text-xs rounded-xl border border-input bg-background text-foreground px-3 outline-none">
                <option value="">All Statuses</option>
                <option value="active">Active Only</option>
                <option value="inactive">Inactive</option>
            </select>
        </x-slot:filters>
    </x-erp.filter-bar>

    <!-- Table Container -->
    <div class="card-surface rounded-2xl border border-border overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-4 py-3">Account Code</th>
                        <th class="px-4 py-3">Account Name</th>
                        <th class="px-4 py-3">Classification Type</th>
                        <th class="px-4 py-3">Normal Balance</th>
                        <th class="px-4 py-3">Postable</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($accounts as $acc)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors {{ ! $acc->is_active ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3 font-sans font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $acc->account_code }}
                            </td>
                            <td class="px-4 py-3 text-zinc-950 dark:text-white font-medium">
                                <span style="margin-left: {{ max(0, ($acc->level - 1) * 1.5) }}rem" class="inline-flex items-center gap-1.5">
                                    @if ($acc->level > 1)
                                        <span class="text-zinc-400">└─</span>
                                    @endif
                                    <span>{{ $acc->account_name }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-muted-foreground font-medium">
                                    {{ $acc->account_type->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-sans text-[11px] {{ $acc->normal_balance->value === 'DEBIT' ? 'text-indigo-600 dark:text-indigo-400' : 'text-teal-600 dark:text-teal-400' }}">
                                    {{ $acc->normal_balance->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($acc->is_postable)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400">
                                        <span class="material-symbols-rounded text-sm">check</span> Postable
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[11px] text-zinc-400">
                                        <span class="material-symbols-rounded text-sm">folder</span> Header/Parent
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $acc->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                    {{ $acc->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="edit" wire:click="openEditModal({{ $acc->id }})" aria-label="Edit Account">
                                        Edit Account
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item
                                        :icon="$acc->is_active ? 'block' : 'check_circle'"
                                        :variant="$acc->is_active ? 'destructive' : 'default'"
                                        wire:click="toggleActive({{ $acc->id }})"
                                        aria-label="{{ $acc->is_active ? 'Deactivate Account' : 'Activate Account' }}">
                                        {{ $acc->is_active ? 'Deactivate Account' : 'Activate Account' }}
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-3xl mb-1 text-zinc-300">account_tree</span>
                                <p class="text-xs">No accounts matching the search criteria found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accounts->hasPages())
            <div class="p-3 border-t border-zinc-200 dark:border-zinc-800">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>

    <!-- Form Modal (Create / Edit) -->
    <x-ui.modal wire:model="showFormModal" maxWidth="lg" onClose="$wire.set('showFormModal', false)">
        <x-slot:header>
            <h3 class="text-base font-bold text-foreground">
                {{ $editingAccountId ? 'Edit Account' : 'Add New Account' }}
            </h3>
        </x-slot:header>

        <form id="save-account-form" wire:submit="saveAccount" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-muted-foreground mb-1">Account Code</label>
                    <input type="text"
                           wire:model="accountCode"
                           placeholder="e.g. 1101"
                           class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground font-sans">
                    @error('accountCode') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground mb-1">Classification Type</label>
                    <select wire:model.live="accountType"
                            class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground">
                        @foreach ($accountTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->value }}</option>
                        @endforeach
                    </select>
                    @error('accountType') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-muted-foreground mb-1">Account Name</label>
                <input type="text"
                       wire:model="accountName"
                       placeholder="e.g. Operating Petty Cash"
                       class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground">
                @error('accountName') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-muted-foreground mb-1">Normal Balance</label>
                    <select wire:model="normalBalance"
                            class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground font-sans">
                        <option value="DEBIT">DEBIT</option>
                        <option value="CREDIT">CREDIT</option>
                    </select>
                    @error('normalBalance') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground mb-1">Parent Account</label>
                    <select wire:model="parentId"
                            class="w-full px-3 py-2 text-xs rounded-xl border border-input bg-background text-foreground">
                        <option value="">(None - Top Level Account)</option>
                        @foreach ($parentOptions as $parent)
                            <option value="{{ $parent->id }}">[{{ $parent->account_code }}] {{ $parent->account_name }}</option>
                        @endforeach
                    </select>
                    @error('parentId') <span class="text-[10px] text-destructive mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="space-y-2 pt-2 border-t border-border text-xs">
                <label class="flex items-center gap-2 text-foreground cursor-pointer">
                    <input type="checkbox" wire:model="isPostable" class="rounded border-input text-primary focus:ring-ring">
                    <span>Postable Account (Allows direct journal line posting)</span>
                </label>
                <label class="flex items-center gap-2 text-foreground cursor-pointer">
                    <input type="checkbox" wire:model="allowManualJournal" class="rounded border-input text-primary focus:ring-ring">
                    <span>Allow Manual Journal (Not restricted to system automated posting)</span>
                </label>
                <label class="flex items-center gap-2 text-foreground cursor-pointer">
                    <input type="checkbox" wire:model="isReconcilable" class="rounded border-input text-primary focus:ring-ring">
                    <span>Reconcilable (Cash/Bank Account)</span>
                </label>
                <label class="flex items-center gap-2 text-foreground cursor-pointer">
                    <input type="checkbox" wire:model="isActive" class="rounded border-input text-primary focus:ring-ring">
                    <span>Active Account</span>
                </label>
            </div>
        </form>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-2 w-full">
                <button type="button"
                        wire:click="$set('showFormModal', false)"
                        class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                    Cancel
                </button>
                <button type="submit" form="save-account-form"
                        class="px-4 py-2 text-xs font-semibold text-primary-foreground bg-primary hover:bg-primary/90 rounded-xl transition-colors shadow-xs">
                    Save Account
                </button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
