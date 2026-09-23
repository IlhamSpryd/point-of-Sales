<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-[#37352F] leading-tight">
            {{ __('Manajemen Modifier & Add-ons') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Toolbar -->
            <x-list-toolbar>
                <x-slot name="actions">
                    <button wire:click="createGroup" class="bg-[#37352F] text-[#F7F7F5] px-4 py-2 rounded-xl text-sm font-medium hover:bg-[#2F2D28] transition-colors">
                        + Tambah Grup Modifier
                    </button>
                </x-slot>
            </x-list-toolbar>

            <!-- Loop Groups -->
            @foreach($groups as $group)
            <div class="card-surface p-6 rounded-2xl border border-[#E9E9E7]">
                <div class="flex items-center justify-between mb-4 pb-4 border-b border-[#E9E9E7]">
                    <div>
                        <h3 class="text-lg font-bold text-[#37352F]">{{ $group->name }}</h3>
                        <p class="text-sm text-[#9C9A94]">
                            Tipe: {{ ucfirst($group->selection_type) }} | {{ $group->is_required ? 'Wajib Dipilih' : 'Opsional' }}
                        </p>
                    </div>
                    <div class="flex space-x-2">
                        <button wire:click="editGroup({{ $group->id }})" class="text-[#37352F] hover:text-[#2F2D28] p-2 bg-[#F7F7F5] rounded-xl transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </button>
                        <button wire:click="deleteGroup({{ $group->id }})" class="text-red-600 hover:text-red-800 p-2 bg-red-50 rounded-xl transition-colors" onclick="confirm('Yakin ingin menghapus grup ini?') || event.stopImmediatePropagation()">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Modifiers inside Group -->
                <div class="space-y-3">
                    @forelse($group->modifiers as $modifier)
                    <div class="flex items-center justify-between p-3 bg-[#F7F7F5] rounded-xl border border-[#E9E9E7]">
                        <div class="flex items-center space-x-4">
                            <div>
                                <span class="font-medium text-[#37352F]">{{ $modifier->name }}</span>
                                @if($modifier->is_default)
                                    <span class="ml-2 text-xs bg-[#E9E9E7] text-[#37352F] px-2 py-0.5 rounded-full">Default</span>
                                @endif
                            </div>
                            <span class="text-sm font-semibold text-[#8A5A16]">+Rp {{ number_format($modifier->extra_price, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center space-x-4">
                            @if($modifier->ingredients->isNotEmpty())
                                <span class="text-xs text-[#9C9A94]">BOM: {{ $modifier->ingredients->count() }} Bahan</span>
                            @endif
                            <button wire:click="editModifier({{ $modifier->id }})" class="text-[#37352F] hover:underline text-sm font-medium">Edit</button>
                            <button wire:click="deleteModifier({{ $modifier->id }})" class="text-red-600 hover:underline text-sm font-medium" onclick="confirm('Hapus modifier ini?') || event.stopImmediatePropagation()">Hapus</button>
                        </div>
                    </div>
                    @empty
                    <div class="text-sm text-[#9C9A94] italic p-3">Belum ada varian di grup ini.</div>
                    @endforelse
                </div>

                <div class="mt-4">
                    <button wire:click="createModifier({{ $group->id }})" class="text-sm text-[#37352F] font-medium flex items-center space-x-1 hover:underline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        <span>Tambah Opsi Varian</span>
                    </button>
                </div>
            </div>
            @endforeach

        </div>
    </div>

    <!-- Modal Group -->
    <div x-data="{ show: @entangle('showGroupForm') }" x-show="show" x-cloak class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="show" class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div x-show="show" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-[#E9E9E7]">
                <div class="card-surface px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-bold text-[#37352F] mb-4">
                        {{ $editingGroupId ? 'Edit Grup Varian' : 'Tambah Grup Varian' }}
                    </h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-[#37352F]">Nama Grup</label>
                            <input type="text" wire:model="groupForm.name" class="mt-1 block w-full rounded-xl border-[#E9E9E7] shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20" placeholder="Misal: Pilihan Susu">
                            @error('groupForm.name') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[#37352F]">Tipe Pemilihan</label>
                            <select wire:model="groupForm.selection_type" class="mt-1 block w-full rounded-xl border-[#E9E9E7] shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20">
                                <option value="single">Single (Hanya 1 pilihan)</option>
                                <option value="multiple">Multiple (Bisa lebih dari 1)</option>
                            </select>
                            @error('groupForm.selection_type') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" wire:model="groupForm.is_required" class="rounded border-[#E9E9E7] text-[#37352F] shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20">
                            <label class="ml-2 block text-sm text-[#37352F]">Wajib Dipilih (Required)</label>
                        </div>
                    </div>
                </div>
                <div class="bg-[#F7F7F5] px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-[#E9E9E7]">
                    <button wire:click="saveGroup" type="button" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-[#37352F] text-base font-medium text-[#F7F7F5] hover:bg-[#2F2D28] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#37352F] sm:ml-3 sm:w-auto sm:text-sm">
                        Simpan
                    </button>
                    <button wire:click="resetGroupForm" type="button" class="mt-3 w-full inline-flex justify-center rounded-xl border border-[#E9E9E7] shadow-sm px-4 py-2 bg-white text-base font-medium text-[#37352F] hover:bg-[#F7F7F5] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#37352F] sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Batal
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Modifier -->
    <div x-data="{ 
            show: @entangle('showModifierForm'),
            ingredients: @entangle('modifierForm.ingredients'),
            addIngredient() {
                this.ingredients.push({ id: '', quantity: 1 });
            },
            removeIngredient(index) {
                this.ingredients.splice(index, 1);
            }
        }" x-show="show" x-cloak class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="show" class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div x-show="show" class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full border border-[#E9E9E7]">
                <div class="card-surface px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-bold text-[#37352F] mb-4">
                        {{ $editingModifierId ? 'Edit Varian' : 'Tambah Varian' }}
                    </h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-[#37352F]">Nama Varian</label>
                            <input type="text" wire:model="modifierForm.name" class="mt-1 block w-full rounded-xl border-[#E9E9E7] shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20" placeholder="Misal: Oat Milk">
                            @error('modifierForm.name') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[#37352F]">Harga Tambahan (Rp)</label>
                            <input type="number" wire:model="modifierForm.extra_price" class="mt-1 block w-full rounded-xl border-[#E9E9E7] shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20" placeholder="0">
                            @error('modifierForm.extra_price') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" wire:model="modifierForm.is_default" class="rounded border-[#E9E9E7] text-[#37352F] shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20">
                            <label class="ml-2 block text-sm text-[#37352F]">Jadikan Pilihan Default</label>
                        </div>

                        <!-- BOM Setup for Modifier -->
                        <div class="mt-6 border-t border-[#E9E9E7] pt-4">
                            <label class="block text-sm font-medium text-[#37352F] mb-2">Bahan Baku Tambahan (BOM Opsional)</label>
                            <template x-for="(ing, index) in ingredients" :key="index">
                                <div class="flex space-x-2 mb-2 items-center">
                                    <select x-model="ing.id" class="flex-1 rounded-xl border-[#E9E9E7] text-sm shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20">
                                        <option value="">Pilih Bahan</option>
                                        @foreach($this->ingredients as $i)
                                            <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                                        @endforeach
                                    </select>
                                    <input type="number" step="0.01" x-model="ing.quantity" class="w-24 rounded-xl border-[#E9E9E7] text-sm shadow-sm focus:border-[#37352F] focus:ring focus:ring-[#37352F] focus:ring-opacity-20" placeholder="Qty">
                                    <button @click="removeIngredient(index)" type="button" class="text-red-500 hover:text-red-700 p-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                    </button>
                                </div>
                            </template>
                            <button @click="addIngredient" type="button" class="mt-2 text-sm text-[#37352F] hover:underline flex items-center space-x-1">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                <span>Tambah Bahan Baku</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="bg-[#F7F7F5] px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-[#E9E9E7]">
                    <button wire:click="saveModifier" type="button" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-[#37352F] text-base font-medium text-[#F7F7F5] hover:bg-[#2F2D28] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#37352F] sm:ml-3 sm:w-auto sm:text-sm">
                        Simpan
                    </button>
                    <button wire:click="resetModifierForm" type="button" class="mt-3 w-full inline-flex justify-center rounded-xl border border-[#E9E9E7] shadow-sm px-4 py-2 bg-white text-base font-medium text-[#37352F] hover:bg-[#F7F7F5] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#37352F] sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Batal
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
