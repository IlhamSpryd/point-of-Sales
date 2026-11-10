<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    @if($showModal)
    <x-erp.page-header
        :title="$isEditing ? 'Edit Raw Material' : 'Add Raw Material'"
        :description="$isEditing ? 'Update raw material information' : 'Add a new raw material to inventory'"
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">
                <span class="material-symbols-rounded text-[20px]">arrow_back</span> Back
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

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
                <x-ui.switch wire:model="is_active" label="Active Material" :checked="(bool)$is_active" />
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit" variant="primary" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    <span class="material-symbols-rounded text-[20px]">save</span> Save
                </x-ui.button>
            </div>
        </form>
    </div>
    @else
    <x-erp.page-header
        title="Raw Materials & Ingredients"
        description="Manage raw materials inventory and configure Bill of Materials (BOM)"
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="primary" wire:click="create" x-on:click="window.erpMotion?.pulseHighlight($el)">
                <span class="material-symbols-rounded text-[20px]">add</span> Add Material
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

    <x-erp.filter-bar
        search-placeholder="Search raw materials..."
        search-model="search"
        :reset-method="null"
        class="mb-4"
    />

    @if (session()->has('success'))
        <x-ui.alert variant="success" class="mb-6">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    <div class="flex flex-col flex-1 min-h-0 space-y-4">
        {{-- Desktop/tablet-landscape: existing table --}}
        <div class="hidden lg:block">
            <x-erp.data-table
                loading-target="search, filterCategory"
                :is-empty="$ingredients->isEmpty()"
                empty-title="No Raw Materials Found"
                empty-description="Add new raw materials to start tracking inventory and product recipes."
                empty-icon="inventory_2"
                :colspan="5"
            >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Raw Material</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Current Stock</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Cost/Unit</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
                </x-slot:head>
                    @foreach ($ingredients as $item)
                        <tr class="hover:bg-muted/40 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-foreground">{{ $item->name }}</span>
                                    <span class="text-xs text-muted-foreground">{{ $item->ingredient_code ?? '-' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-sans tabular-nums numeric">
                                @php
                                    $displayStock = (float) ($item->store_stock ?? $item->current_stock);
                                @endphp
                                <div class="flex items-center justify-end gap-2">
                                    <span class="text-sm font-medium tabular-nums {{ $displayStock <= $item->reorder_level ? 'text-destructive font-bold' : 'text-foreground' }}">
                                        {{ number_format($displayStock, 2) }} {{ $item->unit }}
                                    </span>
                                    @if($displayStock <= $item->reorder_level)
                                        <span class="text-xs font-bold text-destructive" title="Low Stock">(!)</span>
                                    @endif
                                </div>
                                <div class="text-[10px] text-muted-foreground mt-0.5 tabular-nums">Min: {{ number_format($item->reorder_level, 2) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-sans tabular-nums numeric">
                                <span class="text-sm text-foreground tabular-nums">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $item->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                    {{ $item->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="inventory" wire:click="adjustStock({{ $item->id }})">
                                        Adjust Stock
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item icon="edit" wire:click="edit({{ $item->id }})">
                                        Edit
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item
                                        icon="delete"
                                        variant="destructive"
                                        x-on:click="$dispatch('erp-confirm', {
                                            title: 'Delete Raw Material?',
                                            message: 'Raw material &quot;{{ $item->name }}&quot; will be permanently deleted.',
                                            variant: 'destructive',
                                            confirmText: 'Delete',
                                            onConfirm: () => $wire.delete({{ $item->id }})
                                        })"
                                    >
                                        Delete
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                            </td>
                        </tr>
                    @endforeach
                    @if($ingredients->hasPages())
                        <x-slot:pagination>
                            {{ $ingredients->links() }}
                        </x-slot:pagination>
                    @endif
            </x-erp.data-table>
        </div>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($ingredients as $item)
                <div class="card-surface p-4">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex flex-col">
                            <span class="font-bold text-foreground text-sm">{{ $item->name }}</span>
                            <span class="text-xs text-muted-foreground">{{ $item->ingredient_code ?? '-' }}</span>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $item->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                {{ $item->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-border">
                        <div class="flex-1">
                            @php
                                $displayStock = (float) ($item->store_stock ?? $item->current_stock);
                            @endphp
                            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block mb-0.5">Stock: <span class="{{ $displayStock <= $item->reorder_level ? 'text-rose-600' : 'text-foreground' }}">{{ number_format($displayStock, 2) }} {{ $item->unit }}</span></span>
                            <span class="text-xs text-foreground">Rp {{ number_format($item->cost_per_unit, 0, ',', '.') }} / {{ $item->unit }}</span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button wire:click="adjustStock({{ $item->id }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 transition-colors" title="Adjust Stock">
                                <span class="material-symbols-rounded text-[20px]">inventory</span>
                            </button>
                            <button wire:click="edit({{ $item->id }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Edit">
                                <span class="material-symbols-rounded text-[20px]">edit</span>
                            </button>
                            <button type="button"
                                data-swal-delete
                                data-swal-title="Delete Raw Material?"
                                data-swal-text="Raw material &quot;{{ $item->name }}&quot; will be permanently deleted."
                                data-wire-action="delete({{ $item->id }})"
                                class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Delete">
                                <span class="material-symbols-rounded text-[20px]">delete</span>
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
            <div class="lg:hidden p-4 border-t border-border shrink-0">
                {{ $ingredients->links() }}
            </div>
        @endif
    </div>
    @endif

    <!-- Modal Adjust Stock -->
    <x-ui.modal wire:model="showAdjustModal" maxWidth="lg">
        <x-slot:header>
            <h3 class="text-lg font-bold text-foreground">
                Adjust Stock
            </h3>
        </x-slot:header>
        <x-slot:content>
            <form wire:submit.prevent="saveAdjustment" class="space-y-5">
                <div>
                    <x-form-label for="adjustType">Adjustment Type <span class="text-rose-500">*</span></x-form-label>
                    <div class="relative">
                        <select id="adjustType" wire:model="adjustType" class="form-input w-full px-4 py-2.5 rounded-xl border border-border bg-white text-foreground appearance-none focus:outline-none focus:ring-2 focus:ring-ring focus:border-primary transition-all duration-200 shadow-sm {{ $errors->has('adjustType') ? 'input-error' : '' }}">
                            <option value="purchase_receipt">Goods Receipt (Purchase)</option>
                            <option value="adjustment">Manual Correction (+ / -)</option>
                            <option value="waste">Damaged Goods / Waste (-)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-muted-foreground">
                            <span class="material-symbols-rounded text-[20px]">keyboard_arrow_down</span>
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
                    <textarea id="adjustReason" wire:model="adjustReason" rows="3" class="form-input w-full px-4 py-2.5 rounded-xl border border-border bg-white text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-primary transition-all duration-200 shadow-sm {{ $errors->has('adjustReason') ? 'input-error' : '' }}" placeholder="Additional notes..."></textarea>
                    @error('adjustReason') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-border">
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
