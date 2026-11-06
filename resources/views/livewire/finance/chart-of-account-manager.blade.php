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
    <div class="card-surface p-4 rounded-2xl border border-border flex flex-col md:flex-row gap-3 items-center justify-between shadow-xs">
        <div class="relative w-full md:w-80">
            <span class="material-symbols-rounded absolute left-3 top-2.5 text-zinc-400 text-sm">search</span>
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="Search account code or name..."
                   class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:focus:ring-zinc-100">
        </div>

        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            <select wire:model.live="typeFilter" class="text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 px-3 py-1.5 focus:outline-none">
                <option value="">All Account Types</option>
                @foreach ($accountTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->value }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" class="text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 px-3 py-1.5 focus:outline-none">
                <option value="">All Statuses</option>
                <option value="active">Active Only</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
    </div>

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
                            <td class="px-4 py-3 font-mono font-medium text-zinc-900 dark:text-zinc-100">
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
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold tracking-wide
                                    @if($acc->account_type->value === 'ASSET') bg-blue-500/10 text-blue-600 dark:text-blue-400
                                    @elseif($acc->account_type->value === 'LIABILITY') bg-rose-500/10 text-rose-600 dark:text-rose-400
                                    @elseif($acc->account_type->value === 'EQUITY') bg-purple-500/10 text-purple-600 dark:text-purple-400
                                    @elseif($acc->account_type->value === 'REVENUE') bg-emerald-500/10 text-emerald-600 dark:text-emerald-400
                                    @else bg-amber-500/10 text-amber-600 dark:text-amber-400 @endif">
                                    {{ $acc->account_type->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-[11px] {{ $acc->normal_balance->value === 'DEBIT' ? 'text-indigo-600 dark:text-indigo-400' : 'text-teal-600 dark:text-teal-400' }}">
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
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $acc->is_active ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-zinc-500/10 text-zinc-500' }}">
                                    {{ $acc->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button"
                                            wire:click="openEditModal({{ $acc->id }})"
                                            class="p-1 rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-600 dark:text-zinc-300 transition-colors"
                                            title="Edit Account">
                                        <span class="material-symbols-rounded text-base">edit</span>
                                    </button>
                                    <button type="button"
                                            wire:click="toggleActive({{ $acc->id }})"
                                            class="p-1 rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-700 {{ $acc->is_active ? 'text-rose-500' : 'text-emerald-500' }} transition-colors"
                                            title="{{ $acc->is_active ? 'Deactivate' : 'Activate' }}">
                                        <span class="material-symbols-rounded text-base">{{ $acc->is_active ? 'block' : 'check_circle' }}</span>
                                    </button>
                                </div>
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
    @if ($showFormModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto"
                 @click.outside="$wire.set('showFormModal', false)">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="text-base font-bold text-zinc-950 dark:text-white">
                        {{ $editingAccountId ? 'Edit Account' : 'Add New Account' }}
                    </h3>
                    <button type="button" wire:click="$set('showFormModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                </div>

                <form wire:submit="saveAccount" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Account Code</label>
                            <input type="text"
                                   wire:model="accountCode"
                                   placeholder="e.g. 1101"
                                   class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-mono">
                            @error('accountCode') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Classification Type</label>
                            <select wire:model.live="accountType"
                                    class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                                @foreach ($accountTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->value }}</option>
                                @endforeach
                            </select>
                            @error('accountType') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Account Name</label>
                        <input type="text"
                               wire:model="accountName"
                               placeholder="e.g. Operating Petty Cash"
                               class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                        @error('accountName') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Normal Balance</label>
                            <select wire:model="normalBalance"
                                    class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-mono">
                                <option value="DEBIT">DEBIT</option>
                                <option value="CREDIT">CREDIT</option>
                            </select>
                            @error('normalBalance') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Parent Account</label>
                            <select wire:model="parentId"
                                    class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                                <option value="">(None - Top Level Account)</option>
                                @foreach ($parentOptions as $parent)
                                    <option value="{{ $parent->id }}">[{{ $parent->account_code }}] {{ $parent->account_name }}</option>
                                @endforeach
                            </select>
                            @error('parentId') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="space-y-2 pt-2 border-t border-zinc-200 dark:border-zinc-800 text-xs">
                        <label class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="checkbox" wire:model="isPostable" class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900">
                            <span>Postable Account (Allows direct journal line posting)</span>
                        </label>
                        <label class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="checkbox" wire:model="allowManualJournal" class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900">
                            <span>Allow Manual Journal (Not restricted to system automated posting)</span>
                        </label>
                        <label class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="checkbox" wire:model="isReconcilable" class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900">
                            <span>Reconcilable (Cash/Bank Account)</span>
                        </label>
                        <label class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="checkbox" wire:model="isActive" class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900">
                            <span>Active Account</span>
                        </label>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button type="button"
                                wire:click="$set('showFormModal', false)"
                                class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
                            Save Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
