<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="mb-6" data-gsap="page-enter">
        <div class="flex items-center gap-2 text-sm text-muted-foreground mb-2">
            <a href="{{ route('price-lists.index') }}" class="hover:text-foreground" wire:navigate>Price Lists</a>
            <span>/</span>
            <span class="text-foreground font-medium">Create New</span>
        </div>
        <h4 class="text-xl font-bold text-foreground tracking-tight">Create Price List & Tiered Pricing Rules</h4>
    </div>

    @if($errors->any())
        <x-ui.alert variant="destructive" class="mb-6">
            <ul class="list-disc pl-5 space-y-1 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="card-surface p-6" x-data="{
        type: 'standard',
        items: [
            { product_id: '', min_quantity: 1, price: 0 }
        ],
        addItem() {
            this.items.push({ product_id: '', min_quantity: 1, price: 0 });
        },
        removeItem(index) {
            if (this.items.length > 1) {
                this.items.splice(index, 1);
            }
        }
    }">
        <form action="{{ route('price-lists.store') }}" method="POST" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pb-6 border-b border-border">
                <div>
                    <x-form-label for="code">Price List Code <span class="text-danger-600">*</span></x-form-label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" required maxlength="64"
                        placeholder="e.g. PL-WHOLESALE-01"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm uppercase focus:border-primary focus:ring-1 focus:ring-ring">
                    <x-input-error :messages="$errors->get('code')" class="mt-1" />
                </div>

                <div>
                    <x-form-label for="name">Price List Name <span class="text-danger-600">*</span></x-form-label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="150"
                        placeholder="e.g. Wholesale Tier Min. 10 Pcs"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm focus:border-primary focus:ring-1 focus:ring-ring">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-form-label for="type">Rule Type / Target <span class="text-danger-600">*</span></x-form-label>
                    <select id="type" name="type" x-model="type" required
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm bg-white focus:border-primary focus:ring-1 focus:ring-ring">
                        <option value="standard">Standard (Global)</option>
                        <option value="wholesale">Tiered Wholesale (Quantity-based)</option>
                        <option value="customer_tier">Loyalty Tier</option>
                        <option value="customer_specific">Specific Customer Contract</option>
                        <option value="branch">Branch Specific</option>
                        <option value="channel">Sales Channel Specific (Online Delivery, etc.)</option>
                    </select>
                </div>

                <div>
                    <x-form-label for="priority">Evaluation Priority (Lower number = higher priority)</x-form-label>
                    <input type="number" id="priority" name="priority" value="{{ old('priority', 10) }}" min="0" required
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm focus:border-primary focus:ring-1 focus:ring-ring">
                </div>

                {{-- Target Dinamis --}}
                <div x-show="type === 'customer_specific'" x-cloak>
                    <x-form-label for="customer_id">Select Contract Customer</x-form-label>
                    <select id="customer_id" name="customer_id"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm bg-white focus:border-primary focus:ring-1 focus:ring-ring">
                        <option value="">-- Select Customer --</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->phone ?? 'No Phone' }})</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'customer_tier'" x-cloak>
                    <x-form-label for="loyalty_tier_id">Select Loyalty Tier</x-form-label>
                    <select id="loyalty_tier_id" name="loyalty_tier_id"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm bg-white focus:border-primary focus:ring-1 focus:ring-ring">
                        <option value="">-- Select Tier --</option>
                        @foreach ($loyaltyTiers as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'branch'" x-cloak>
                    <x-form-label for="store_id">Select Target Branch</x-form-label>
                    <select id="store_id" name="store_id"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm bg-white focus:border-primary focus:ring-1 focus:ring-ring">
                        <option value="">-- Select Branch --</option>
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'channel'" x-cloak>
                    <x-form-label for="channel">Sales Channel (e.g. online_delivery, pos, grabfood)</x-form-label>
                    <input type="text" id="channel" name="channel" placeholder="online_delivery"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm focus:border-primary focus:ring-1 focus:ring-ring">
                </div>

                <div>
                    <x-form-label for="valid_from">Valid From (Optional)</x-form-label>
                    <input type="date" id="valid_from" name="valid_from" value="{{ old('valid_from') }}"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm focus:border-primary focus:ring-1 focus:ring-ring">
                </div>

                <div>
                    <x-form-label for="valid_until">Valid Until (Optional)</x-form-label>
                    <input type="date" id="valid_until" name="valid_until" value="{{ old('valid_until') }}"
                        class="w-full h-10 px-3 rounded-xl border border-border text-sm focus:border-primary focus:ring-1 focus:ring-ring">
                </div>

                <div class="flex items-center gap-2 pt-2 md:col-span-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="is_active" name="is_active" value="1" checked
                        class="rounded border-gray-300 text-foreground focus:ring-ring">
                    <label for="is_active" class="text-sm font-medium text-foreground">This price list is active</label>
                </div>
            </div>

            {{-- Tabel Rincian Harga Produk --}}
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h5 class="text-base font-bold text-foreground">Item Details & Tiered Pricing</h5>
                        <p class="text-xs text-muted-foreground">Specify products, minimum purchase quantity thresholds (Wholesale Tiers), and unit pricing.</p>
                    </div>
                    <button type="button" @click="addItem()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-muted hover:bg-accent text-xs font-semibold text-foreground transition-colors">
                        <span class="material-symbols-rounded text-[20px]">add</span> Add Row
                    </button>
                </div>

                <div class="overflow-x-auto border border-border rounded-2xl">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-muted border-b border-border text-xs text-muted-foreground uppercase">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3 w-40 text-right">Min. Purchase (Qty)</th>
                                <th class="px-4 py-3 w-48 text-right">Unit Price (Rp)</th>
                                <th class="px-4 py-3 w-16 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template x-for="(item, index) in items" :key="index">
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <select :name="'items[' + index + '][product_id]'" x-model="item.product_id" required
                                            class="w-full h-9 px-3 rounded-lg border border-border text-sm bg-white focus:border-primary focus:ring-1 focus:ring-ring">
                                            <option value="">-- Select Product --</option>
                                            @foreach ($products as $p)
                                                <option value="{{ $p->id }}">{{ $p->product_name }} (Rp {{ number_format($p->product_price, 0, ',', '.') }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <input type="number" :name="'items[' + index + '][min_quantity]'" x-model="item.min_quantity" min="1" required
                                            class="w-full h-9 px-3 rounded-lg border border-border text-sm text-right tabular-nums focus:border-primary focus:ring-1 focus:ring-ring">
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <input type="number" :name="'items[' + index + '][price]'" x-model="item.price" min="0" step="0.01" required
                                            class="w-full h-9 px-3 rounded-lg border border-border text-sm text-right tabular-nums focus:border-primary focus:ring-1 focus:ring-ring">
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <button type="button" @click="removeItem(index)"
                                            class="text-danger-500 hover:text-danger-700 p-1 rounded-lg hover:bg-danger-50 transition-colors">
                                            <span class="material-symbols-rounded text-[20px]">delete</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-6 border-t border-border">
                <a href="{{ route('price-lists.index') }}" wire:navigate>
                    <x-ui.button variant="secondary" type="button">Cancel</x-ui.button>
                </a>
                <x-ui.button variant="primary" type="submit" x-on:click="window.erpMotion?.pulseHighlight($el)">Save Price List</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
