<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    @if($showModal)
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">
                {{ $isEditing ? 'Edit Raw Material' : 'Add Raw Material' }}
            </h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">
                {{ $isEditing ? 'Update raw material information' : 'Add a new raw material to inventory' }}
            </p>
        </div>
        <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Back
        </x-ui.button>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <form wire:submit.prevent="save" class="p-6 md:p-8 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="ingredient_code">Material Code (Optional)</x-form-label>
                    <x-form-input type="text" id="ingredient_code" wire:model="ingredient_code" class="{{ $errors->has('ingredient_code') ? 'input-error' : '' }}" placeholder="e.g. RM-001" />
                    @error('ingredient_code') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
                
                <div>
                    <x-form-label for="name">Material Name <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="text" id="name" wire:model="name" required class="{{ $errors->has('name') ? 'input-error' : '' }}" placeholder="Enter raw material name" />
                    @error('name') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <x-form-label for="unit">Base Unit <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="text" id="unit" wire:model="unit" placeholder="g, ml, pcs..." required class="{{ $errors->has('unit') ? 'input-error' : '' }}" />
                    @error('unit') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-form-label for="cost_per_unit">Cost per Unit (Rp) <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="number" id="cost_per_unit" step="0.01" wire:model="cost_per_unit" required class="{{ $errors->has('cost_per_unit') ? 'input-error' : '' }}" placeholder="0" />
                    @error('cost_per_unit') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-form-label for="reorder_level">Reorder Level (Min. Threshold) <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="number" id="reorder_level" step="0.0001" wire:model="reorder_level" required class="{{ $errors->has('reorder_level') ? 'input-error' : '' }}" placeholder="0" />
                    @error('reorder_level') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center">
                <label class="flex items-center cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" wire:model="is_active" class="sr-only">
                        <div class="block bg-primary-200 w-10 h-6 rounded-full transition-colors duration-300"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm"></div>
                    </div>
                    <style>
                        input[type="checkbox"]:checked ~ .block { background-color: #10b981; }
                        input[type="checkbox"]:checked ~ .dot { transform: translateX(100%); }
                    </style>
                    <div class="ml-3 text-sm font-semibold text-apeiron-ink">
                        Active Material
                    </div>
                </label>
            </div>

            <div class="pt-4 border-t border-apeiron-border flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit" variant="primary" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    <span class="material-symbols-rounded text-[18px]">save</span> Save
                </x-ui.button>
            </div>
        </form>
    </div>
    @else
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Raw Materials & Ingredients</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">Manage raw materials inventory and configure Bill of Materials (BOM)</p>
        </div>
        <div class="flex items-center gap-3 w-full sm:w-auto">
            <div class="w-full sm:w-64">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Search raw materials..." />
            </div>
            <x-ui.button type="button" variant="primary" wire:click="create" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[18px]">add</span> Add Material
            </x-ui.button>
        </div>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success" class="mb-6">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0 w-full shrink-0">
        {{-- Desktop/tablet-landscape: existing table --}}
        <div class="hidden lg:block overflow-x-auto flex-1 table-scroll-shadow">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Raw Material</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Current Stock</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Cost/Unit</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>
                    @forelse ($ingredients as $item)
                        <tr class="hover:bg-apeiron-surface/50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-apeiron-ink">{{ $item->name }}</span>
                                    <span class="text-xs text-apeiron-muted">{{ $item->ingredient_code ?? '-' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                @php
                                    $displayStock = (float) ($item->store_stock ?? $item->current_stock);
                                @endphp
                                <div class="flex items-center justify-end gap-2">
                                    <span class="text-sm font-medium tabular-nums {{ $displayStock <= $item->reorder_level ? 'text-rose-600' : 'text-apeiron-ink' }}">
                                        {{ number_format($displayStock, 2) }} {{ $item->unit }}
                                    </span>
                                    @if($displayStock <= $item->reorder_level)
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-700" title="Low Stock">!</span>
                                    @endif
                                </div>
                                <div class="text-[10px] text-apeiron-muted mt-0.5 tabular-nums">Min: {{ number_format($item->reorder_level, 2) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <span class="text-sm text-apeiron-ink tabular-nums">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-ui.badge :variant="$item->is_active ? 'success' : 'secondary'">
                                    {{ $item->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right space-x-2">
                                <button wire:click="adjustStock({{ $item->id }})" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-primary-600 hover:bg-primary-50 transition-colors duration-200 active:scale-90" title="Adjust Stock">
                                    <span class="material-symbols-rounded text-[20px]">inventory</span>
                                </button>
                                <button wire:click="edit({{ $item->id }})" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-primary-600 hover:bg-primary-50 transition-colors duration-200 active:scale-90" title="Edit">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <button type="button"
                                    data-swal-delete
                                    data-swal-title="Delete Raw Material?"
                                    data-swal-text="Raw material &quot;{{ $item->name }}&quot; will be permanently deleted."
                                    data-wire-action="delete({{ $item->id }})"
                                    class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200 active:scale-90" title="Delete">
                                    <span class="material-symbols-rounded text-[20px]">delete</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <x-empty-state icon="inventory_2" title="No Raw Materials Found" description="Add new raw materials to start tracking inventory and product recipes." />
                            </td>
                        </tr>
                    @endforelse
            </x-data-table>
        </div>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($ingredients as $item)
                <div class="card-surface p-4">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex flex-col">
                            <span class="font-bold text-apeiron-ink text-sm">{{ $item->name }}</span>
                            <span class="text-xs text-apeiron-muted">{{ $item->ingredient_code ?? '-' }}</span>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <x-ui.badge :variant="$item->is_active ? 'success' : 'secondary'">
                                {{ $item->is_active ? 'Active' : 'Inactive' }}
                            </x-ui.badge>
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-apeiron-border">
                        <div class="flex-1">
                            @php
                                $displayStock = (float) ($item->store_stock ?? $item->current_stock);
                            @endphp
                            <span class="text-[11px] font-semibold text-apeiron-muted uppercase tracking-wider block mb-0.5">Stock: <span class="{{ $displayStock <= $item->reorder_level ? 'text-rose-600' : 'text-apeiron-ink' }}">{{ number_format($displayStock, 2) }} {{ $item->unit }}</span></span>
                            <span class="text-xs text-apeiron-ink">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }} / {{ $item->unit }}</span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button wire:click="adjustStock({{ $item->id }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 transition-colors" title="Adjust Stock">
                                <span class="material-symbols-rounded text-[18px]">inventory</span>
                            </button>
                            <button wire:click="edit({{ $item->id }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Edit">
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </button>
                            <button type="button"
                                data-swal-delete
                                data-swal-title="Delete Raw Material?"
                                data-swal-text="Raw material &quot;{{ $item->name }}&quot; will be permanently deleted."
                                data-wire-action="delete({{ $item->id }})"
                                class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Delete">
                                <span class="material-symbols-rounded text-[18px]">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <x-empty-state icon="inventory_2" title="No Raw Materials Found" description="Add new raw materials to start tracking inventory and product recipes." />
                </div>
            @endforelse
        </div>
        @if($ingredients->hasPages())
            <div class="p-4 border-t border-apeiron-border shrink-0">
                {{ $ingredients->links() }}
            </div>
        @endif
    </div>
    @endif

    <!-- Modal Adjust Stock -->
    <x-ui.modal wire:model="showAdjustModal" maxWidth="lg">
        <x-slot:header>
            <h3 class="text-lg font-bold text-apeiron-ink">
                Adjust Stock
            </h3>
        </x-slot:header>
        <x-slot:content>
            <form wire:submit.prevent="saveAdjustment" class="space-y-5">
                <div>
                    <x-form-label for="adjustType">Adjustment Type <span class="text-rose-500">*</span></x-form-label>
                    <div class="relative">
                        <select id="adjustType" wire:model="adjustType" class="form-input w-full px-4 py-2.5 rounded-xl border border-apeiron-border bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('adjustType') ? 'input-error' : '' }}">
                            <option value="purchase_receipt">Goods Receipt (Purchase)</option>
                            <option value="adjustment">Manual Correction (+ / -)</option>
                            <option value="waste">Damaged Goods / Waste (-)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-apeiron-muted">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('adjustType') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-form-label for="adjustQuantity">Quantity <span class="text-rose-500">*</span></x-form-label>
                    <x-form-input type="number" id="adjustQuantity" step="0.0001" wire:model="adjustQuantity" placeholder="Use minus (-) for deductions if using Manual Correction" required class="{{ $errors->has('adjustQuantity') ? 'input-error' : '' }}" />
                    @error('adjustQuantity') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-form-label for="adjustReason">Reason / Notes (Optional)</x-form-label>
                    <textarea id="adjustReason" wire:model="adjustReason" rows="3" class="form-input w-full px-4 py-2.5 rounded-xl border border-apeiron-border bg-white text-apeiron-ink placeholder-apeiron-muted focus:outline-none focus:ring-2 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('adjustReason') ? 'input-error' : '' }}" placeholder="Additional notes..."></textarea>
                    @error('adjustReason') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-apeiron-border">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showAdjustModal', false)">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary" x-on:click="window.erpMotion?.pulseHighlight($el)">
                        Save Changes
                    </x-ui.button>
                </div>
            </form>
        </x-slot:content>
    </x-ui.modal>
</div>
