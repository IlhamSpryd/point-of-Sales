<div data-gsap="page-enter">
    @include('catalog.partials.sub-nav')

    <div class="space-y-6">
            
            @if($showGroupForm)
                <!-- Full Page Group Form -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">
                            {{ $editingGroupId ? 'Edit Modifier Group' : 'Add Modifier Group' }}
                        </h4>
                        <p class="text-sm font-medium text-apeiron-muted mt-1">
                            {{ $editingGroupId ? 'Update modifier group details' : 'Create a new modifier group for products' }}
                        </p>
                    </div>
                    <x-ui.button type="button" variant="secondary" wire:click="resetGroupForm">
                        <span class="material-symbols-rounded text-[18px]">arrow_back</span> Back
                    </x-ui.button>
                </div>

                <div class="card-surface overflow-hidden w-full shrink-0">
                    <form wire:submit.prevent="saveGroup" class="p-6 md:p-8 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <x-form-label for="group_name">Group Name <span class="text-rose-500">*</span></x-form-label>
                                <x-form-input type="text" id="group_name" wire:model="groupForm.name" placeholder="e.g. Milk Options" required class="{{ $errors->has('groupForm.name') ? 'input-error' : '' }}" />
                                @error('groupForm.name') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                            
                            <div>
                                <x-form-label for="selection_type">Selection Type <span class="text-rose-500">*</span></x-form-label>
                                <div class="relative">
                                    <select id="selection_type" wire:model="groupForm.selection_type" class="form-input w-full px-4 py-2.5 rounded-xl border border-apeiron-border bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('groupForm.selection_type') ? 'input-error' : '' }}" required>
                                        <option value="single">Single (Choose 1 option)</option>
                                        <option value="multiple">Multiple (Choose multiple options)</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-apeiron-muted">
                                        <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                                    </div>
                                </div>
                                @error('groupForm.selection_type') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex items-center">
                            <label class="flex items-center cursor-pointer">
                                <div class="relative">
                                    <input type="checkbox" wire:model="groupForm.is_required" class="sr-only">
                                    <div class="block bg-primary-200 w-10 h-6 rounded-full transition-colors duration-300"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm"></div>
                                </div>
                                <div class="ml-3 text-sm font-semibold text-apeiron-ink">
                                    Required selection
                                </div>
                            </label>
                        </div>

                        <div class="pt-4 border-t border-apeiron-border flex justify-end gap-3">
                            <x-ui.button type="button" variant="secondary" wire:click="resetGroupForm">
                                Cancel
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" x-on:click="window.erpMotion?.pulseHighlight($el)">
                                <span class="material-symbols-rounded text-[18px]">save</span> Save Group
                            </x-ui.button>
                        </div>
                    </form>
                </div>

            @elseif($showModifierForm)
                <!-- Full Page Modifier Form -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">
                            {{ $editingModifierId ? 'Edit Modifier Option' : 'Add Modifier Option' }}
                        </h4>
                        <p class="text-sm font-medium text-apeiron-muted mt-1">
                            {{ $editingModifierId ? 'Update modifier option details' : 'Add a new modifier option to the group' }}
                        </p>
                    </div>
                    <x-ui.button type="button" variant="secondary" wire:click="resetModifierForm">
                        <span class="material-symbols-rounded text-[18px]">arrow_back</span> Back
                    </x-ui.button>
                </div>

                <div class="card-surface overflow-hidden w-full shrink-0">
                    <form wire:submit.prevent="saveModifier" class="p-6 md:p-8 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <x-form-label for="modifier_name">Modifier Name <span class="text-rose-500">*</span></x-form-label>
                                <x-form-input type="text" id="modifier_name" wire:model="modifierForm.name" placeholder="e.g. Oat Milk" required class="{{ $errors->has('modifierForm.name') ? 'input-error' : '' }}" />
                                @error('modifierForm.name') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                            
                            <div>
                                <x-form-label for="extra_price">Extra Price (Rp) <span class="text-rose-500">*</span></x-form-label>
                                <x-form-input type="number" id="extra_price" wire:model="modifierForm.extra_price" placeholder="0" required class="{{ $errors->has('modifierForm.extra_price') ? 'input-error' : '' }}" />
                                @error('modifierForm.extra_price') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex items-center">
                            <label class="flex items-center cursor-pointer">
                                <div class="relative">
                                    <input type="checkbox" wire:model="modifierForm.is_default" class="sr-only">
                                    <div class="block bg-primary-200 w-10 h-6 rounded-full transition-colors duration-300"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm"></div>
                                </div>
                                <div class="ml-3 text-sm font-semibold text-apeiron-ink">
                                    Set as default option
                                </div>
                            </label>
                        </div>

                        <!-- BOM Setup for Modifier -->
                        <div x-data="{ 
                            ingredients: @entangle('modifierForm.ingredients'),
                            addIngredient() {
                                this.ingredients.push({ id: '', quantity: 1 });
                            },
                            removeIngredient(index) {
                                this.ingredients.splice(index, 1);
                            }
                        }" class="pt-4 border-t border-[#E5E7EB]">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h5 class="text-sm font-bold text-apeiron-ink">Additional Ingredients (Optional BOM)</h5>
                                    <p class="text-xs text-[#787774] mt-0.5">Specify ingredients to automatically deduct from stock when this modifier is selected.</p>
                                </div>
                                <button type="button" @click="addIngredient" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-500 bg-white border border-[#E5E7EB] rounded-xl hover:bg-[#F7F7F5] transition-colors shadow-sm">
                                    <span class="material-symbols-rounded text-[16px]">add</span> Add Ingredient
                                </button>
                            </div>

                            <div class="space-y-3">
                                <template x-for="(ing, index) in ingredients" :key="index">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1 relative">
                                            <select x-model="ing.id" class="form-input w-full px-4 py-2.5 rounded-xl border border-apeiron-border bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all duration-200 shadow-sm" required>
                                                <option value="">-- Select Ingredient --</option>
                                                @foreach($this->ingredients as $i)
                                                    <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                                                @endforeach
                                            </select>
                                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-apeiron-muted">
                                                <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                                            </div>
                                        </div>
                                        <div class="w-32">
                                            <input type="number" step="0.0001" x-model="ing.quantity" placeholder="Quantity" class="form-input w-full px-4 py-2.5 rounded-xl border border-apeiron-border bg-white text-apeiron-ink focus:outline-none focus:ring-2 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all duration-200 shadow-sm" required>
                                        </div>
                                        <button type="button" @click="removeIngredient(index)" class="inline-flex items-center justify-center w-[46px] h-[46px] rounded-xl text-rose-500 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-colors" title="Remove line">
                                            <span class="material-symbols-rounded text-[18px]">close</span>
                                        </button>
                                    </div>
                                </template>
                                
                                <div x-show="ingredients.length === 0" class="text-center py-6 bg-apeiron-surface rounded-xl border border-apeiron-border border-dashed">
                                    <p class="text-sm text-apeiron-muted">No additional ingredients configured.</p>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-apeiron-border flex justify-end gap-3">
                            <x-ui.button type="button" variant="secondary" wire:click="resetModifierForm">
                                Cancel
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" x-on:click="window.erpMotion?.pulseHighlight($el)">
                                <span class="material-symbols-rounded text-[18px]">save</span> Save Modifier
                            </x-ui.button>
                        </div>
                    </form>
                </div>

            @else
            <!-- Header Halaman & Toolbar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Modifiers & Variations</h4>
                    <p class="text-sm font-medium text-[#787774] mt-1">Manage modifier groups, flavor/size variations, and product add-ons</p>
                </div>

                <x-list-toolbar>
                    <x-slot name="actions">
                        <x-ui.button wire:click="createGroup" variant="primary" type="button" class="w-full sm:w-auto h-10" x-on:click="window.erpMotion?.pulseHighlight($el)">
                            <span class="material-symbols-rounded text-[18px]">add</span> Add Modifier Group
                        </x-ui.button>
                    </x-slot>
                </x-list-toolbar>
            </div>

            <!-- Loop Groups -->
            <div class="space-y-6">
            @forelse($groups as $group)
            <div class="card-surface p-6">
                <div class="flex items-center justify-between mb-4 pb-4 border-b border-apeiron-border">
                    <div>
                        <h3 class="text-lg font-bold text-apeiron-ink">{{ $group->name }}</h3>
                        <p class="text-sm text-apeiron-muted mt-0.5">
                            Type: {{ ucfirst($group->selection_type) }} | {{ $group->is_required ? 'Required' : 'Optional' }}
                        </p>
                    </div>
                    <div class="flex space-x-2">
                        <button wire:click="editGroup({{ $group->id }})" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Edit Group">
                            <span class="material-symbols-rounded text-[18px]">edit</span>
                        </button>
                        <button type="button"
                            data-swal-delete
                            data-swal-title="Delete Modifier Group?"
                            data-swal-text="This group and all associated options will be permanently deleted."
                            data-wire-action="deleteGroup({{ $group->id }})"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Delete Group">
                            <span class="material-symbols-rounded text-[18px]">delete</span>
                        </button>
                    </div>
                </div>

                <!-- Modifiers inside Group -->
                <div class="space-y-3">
                    @forelse($group->modifiers as $modifier)
                    <div class="flex items-center justify-between p-3 bg-apeiron-surface rounded-xl border border-apeiron-border">
                        <div class="flex items-center space-x-4">
                            <div>
                                <span class="font-medium text-apeiron-ink">{{ $modifier->name }}</span>
                                @if($modifier->is_default)
                                    <span class="ml-2 text-[10px] font-bold bg-primary-100 text-primary-700 px-2 py-0.5 rounded uppercase tracking-wider">Default</span>
                                @endif
                            </div>
                            <span class="text-sm font-semibold text-emerald-600">+Rp {{ number_format($modifier->extra_price, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center space-x-4">
                            @if($modifier->ingredients->isNotEmpty())
                                <span class="text-xs text-apeiron-muted flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[14px]">inventory_2</span> {{ $modifier->ingredients->count() }} Ingredients
                                </span>
                            @endif
                            <button wire:click="editModifier({{ $modifier->id }})" class="text-apeiron-muted hover:text-apeiron-ink text-sm font-medium transition-colors">Edit</button>
                            <button type="button"
                                data-swal-delete
                                data-swal-title="Delete Modifier Option?"
                                data-swal-text="Option &quot;{{ $modifier->name }}&quot; will be permanently deleted."
                                data-wire-action="deleteModifier({{ $modifier->id }})"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Delete"><span class="material-symbols-rounded text-[18px]">delete</span></button>
                        </div>
                    </div>
                    @empty
                    <div class="text-sm text-apeiron-muted italic p-3 text-center">No modifiers configured in this group.</div>
                    @endforelse
                </div>

                <div class="mt-4">
                    <button wire:click="createModifier({{ $group->id }})" class="text-sm text-primary-600 font-medium flex items-center space-x-1 hover:text-primary-800 transition-colors">
                        <span class="material-symbols-rounded text-[18px]">add</span>
                        <span>Add Modifier Option</span>
                    </button>
                </div>
            </div>
            @empty
            <x-empty-state 
                icon="tune" 
                title="No Modifiers Available" 
                description="Add modifier groups such as 'Cup Size' or 'Toppings' to configure product options." 
            />
            @endforelse
            </div>
            
            @endif
    </div>
</div>
