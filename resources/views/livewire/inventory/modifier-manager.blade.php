<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-yovel-ink leading-tight">
            {{ __('Manajemen Modifier & Add-ons') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            @if($showGroupForm)
                <!-- Full Page Group Form -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-yovel-ink tracking-tight">
                            {{ $editingGroupId ? 'Edit Grup Varian' : 'Tambah Grup Varian' }}
                        </h4>
                        <p class="text-sm font-medium text-yovel-muted mt-1">
                            {{ $editingGroupId ? 'Perbarui informasi grup varian' : 'Buat grup varian baru untuk produk' }}
                        </p>
                    </div>
                    <x-button type="button" variant="secondary" wire:click="resetGroupForm">
                        <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
                    </x-button>
                </div>

                <div class="card-surface overflow-hidden w-full shrink-0">
                    <form wire:submit.prevent="saveGroup" class="p-6 md:p-8 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <x-form-label for="group_name">Nama Grup <span class="text-rose-500">*</span></x-form-label>
                                <x-form-input type="text" id="group_name" wire:model="groupForm.name" placeholder="Misal: Pilihan Susu" required class="{{ $errors->has('groupForm.name') ? 'input-error' : '' }}" />
                                @error('groupForm.name') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                            
                            <div>
                                <x-form-label for="selection_type">Tipe Pemilihan <span class="text-rose-500">*</span></x-form-label>
                                <div class="relative">
                                    <select id="selection_type" wire:model="groupForm.selection_type" class="form-input w-full px-4 py-2.5 rounded-xl border border-yovel-border bg-white text-yovel-ink appearance-none focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm {{ $errors->has('groupForm.selection_type') ? 'input-error' : '' }}" required>
                                        <option value="single">Single (Hanya 1 pilihan)</option>
                                        <option value="multiple">Multiple (Bisa lebih dari 1)</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-yovel-muted">
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
                                <div class="ml-3 text-sm font-semibold text-yovel-ink">
                                    Wajib Dipilih (Required)
                                </div>
                            </label>
                        </div>

                        <div class="pt-4 border-t border-yovel-border flex justify-end gap-3">
                            <x-button type="button" variant="secondary" wire:click="resetGroupForm">
                                Batal
                            </x-button>
                            <x-button type="submit" variant="primary">
                                <span class="material-symbols-rounded text-[18px]">save</span> Simpan Grup
                            </x-button>
                        </div>
                    </form>
                </div>

            @elseif($showModifierForm)
                <!-- Full Page Modifier Form -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-yovel-ink tracking-tight">
                            {{ $editingModifierId ? 'Edit Varian' : 'Tambah Varian' }}
                        </h4>
                        <p class="text-sm font-medium text-yovel-muted mt-1">
                            {{ $editingModifierId ? 'Perbarui informasi varian' : 'Tambahkan opsi varian baru ke dalam grup' }}
                        </p>
                    </div>
                    <x-button type="button" variant="secondary" wire:click="resetModifierForm">
                        <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
                    </x-button>
                </div>

                <div class="card-surface overflow-hidden w-full shrink-0">
                    <form wire:submit.prevent="saveModifier" class="p-6 md:p-8 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <x-form-label for="modifier_name">Nama Varian <span class="text-rose-500">*</span></x-form-label>
                                <x-form-input type="text" id="modifier_name" wire:model="modifierForm.name" placeholder="Misal: Oat Milk" required class="{{ $errors->has('modifierForm.name') ? 'input-error' : '' }}" />
                                @error('modifierForm.name') <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p> @enderror
                            </div>
                            
                            <div>
                                <x-form-label for="extra_price">Harga Tambahan (Rp) <span class="text-rose-500">*</span></x-form-label>
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
                                <div class="ml-3 text-sm font-semibold text-yovel-ink">
                                    Jadikan Pilihan Default
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
                        }" class="pt-4 border-t border-[#E9E9E7]">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h5 class="text-sm font-bold text-yovel-ink">Bahan Baku Tambahan (BOM Opsional)</h5>
                                    <p class="text-xs text-[#787774] mt-0.5">Tentukan bahan baku yang memotong stok otomatis saat varian ini dipilih.</p>
                                </div>
                                <button type="button" @click="addIngredient" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-500 bg-white border border-[#E9E9E7] rounded-xl hover:bg-[#F7F7F5] transition-colors shadow-sm">
                                    <span class="material-symbols-rounded text-[16px]">add</span> Tambah Bahan
                                </button>
                            </div>

                            <div class="space-y-3">
                                <template x-for="(ing, index) in ingredients" :key="index">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1 relative">
                                            <select x-model="ing.id" class="form-input w-full px-4 py-2.5 rounded-xl border border-yovel-border bg-white text-yovel-ink appearance-none focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm" required>
                                                <option value="">-- Pilih Bahan --</option>
                                                @foreach($this->ingredients as $i)
                                                    <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                                                @endforeach
                                            </select>
                                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-yovel-muted">
                                                <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                                            </div>
                                        </div>
                                        <div class="w-32">
                                            <input type="number" step="0.0001" x-model="ing.quantity" placeholder="Kuantitas" class="form-input w-full px-4 py-2.5 rounded-xl border border-yovel-border bg-white text-yovel-ink focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm" required>
                                        </div>
                                        <button type="button" @click="removeIngredient(index)" class="inline-flex items-center justify-center w-[46px] h-[46px] rounded-xl text-rose-500 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-colors" title="Hapus baris">
                                            <span class="material-symbols-rounded text-[18px]">close</span>
                                        </button>
                                    </div>
                                </template>
                                
                                <div x-show="ingredients.length === 0" class="text-center py-6 bg-yovel-surface rounded-xl border border-yovel-border border-dashed">
                                    <p class="text-sm text-yovel-muted">Belum ada bahan baku tambahan.</p>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-yovel-border flex justify-end gap-3">
                            <x-button type="button" variant="secondary" wire:click="resetModifierForm">
                                Batal
                            </x-button>
                            <x-button type="submit" variant="primary">
                                <span class="material-symbols-rounded text-[18px]">save</span> Simpan Varian
                            </x-button>
                        </div>
                    </form>
                </div>

            @else
            <!-- Toolbar -->
            <x-list-toolbar>
                <x-slot name="actions">
                    <x-button wire:click="createGroup" variant="primary">
                        <span class="material-symbols-rounded text-[18px]">add</span> Tambah Grup Modifier
                    </x-button>
                </x-slot>
            </x-list-toolbar>

            <!-- Loop Groups -->
            @forelse($groups as $group)
            <div class="card-surface p-6">
                <div class="flex items-center justify-between mb-4 pb-4 border-b border-yovel-border">
                    <div>
                        <h3 class="text-lg font-bold text-yovel-ink">{{ $group->name }}</h3>
                        <p class="text-sm text-yovel-muted mt-0.5">
                            Tipe: {{ ucfirst($group->selection_type) }} | {{ $group->is_required ? 'Wajib Dipilih' : 'Opsional' }}
                        </p>
                    </div>
                    <div class="flex space-x-2">
                        <button wire:click="editGroup({{ $group->id }})" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Ubah Grup">
                            <span class="material-symbols-rounded text-[18px]">edit</span>
                        </button>
                        <button type="button"
                            data-swal-delete
                            data-swal-title="Hapus Grup Varian?"
                            data-swal-text="Grup ini dan seluruh opsi variannya akan dihapus permanen."
                            data-wire-action="deleteGroup({{ $group->id }})"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Hapus Grup">
                            <span class="material-symbols-rounded text-[18px]">delete</span>
                        </button>
                    </div>
                </div>

                <!-- Modifiers inside Group -->
                <div class="space-y-3">
                    @forelse($group->modifiers as $modifier)
                    <div class="flex items-center justify-between p-3 bg-yovel-surface rounded-xl border border-yovel-border">
                        <div class="flex items-center space-x-4">
                            <div>
                                <span class="font-medium text-yovel-ink">{{ $modifier->name }}</span>
                                @if($modifier->is_default)
                                    <span class="ml-2 text-[10px] font-bold bg-primary-100 text-primary-700 px-2 py-0.5 rounded uppercase tracking-wider">Default</span>
                                @endif
                            </div>
                            <span class="text-sm font-semibold text-emerald-600">+Rp {{ number_format($modifier->extra_price, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center space-x-4">
                            @if($modifier->ingredients->isNotEmpty())
                                <span class="text-xs text-yovel-muted flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[14px]">inventory_2</span> {{ $modifier->ingredients->count() }} Bahan
                                </span>
                            @endif
                            <button wire:click="editModifier({{ $modifier->id }})" class="text-yovel-muted hover:text-yovel-ink text-sm font-medium transition-colors">Ubah</button>
                            <button type="button"
                                data-swal-delete
                                data-swal-title="Hapus Opsi Varian?"
                                data-swal-text="Opsi &quot;{{ $modifier->name }}&quot; akan dihapus permanen."
                                data-wire-action="deleteModifier({{ $modifier->id }})"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors" title="Hapus"><span class="material-symbols-rounded text-[18px]">delete</span></button>
                        </div>
                    </div>
                    @empty
                    <div class="text-sm text-yovel-muted italic p-3 text-center">Belum ada varian di grup ini.</div>
                    @endforelse
                </div>

                <div class="mt-4">
                    <button wire:click="createModifier({{ $group->id }})" class="text-sm text-primary-600 font-medium flex items-center space-x-1 hover:text-primary-800 transition-colors">
                        <span class="material-symbols-rounded text-[18px]">add</span>
                        <span>Tambah Opsi Varian</span>
                    </button>
                </div>
            </div>
            @empty
            <x-empty-state 
                icon="tune" 
                title="Belum Ada Modifier" 
                description="Tambahkan grup modifier seperti 'Ukuran Gelas' atau 'Topping' untuk membuat variasi produk." 
            />
            @endforelse
            
            @endif

        </div>
    </div>
</div>
