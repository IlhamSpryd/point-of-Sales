<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="mb-6" data-gsap="page-enter">
        <div class="flex items-center gap-2 text-sm text-[#787774] mb-2">
            <a href="{{ route('price-lists.index') }}" class="hover:text-apeiron-ink" wire:navigate>Price Lists</a>
            <span>/</span>
            <span class="text-apeiron-ink font-medium">Edit</span>
        </div>
        <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Edit Price List: {{ $priceList->name }}</h4>
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
        type: '{{ old('type', $priceList->type) }}',
        items: {{ json_encode($priceList->items->map(fn($it) => [
            'product_id' => $it->product_id,
            'min_quantity' => $it->min_quantity,
            'price' => (float) $it->price
        ])->all()) ?: '[{ product_id: "", min_quantity: 1, price: 0 }]' }},
        addItem() {
            this.items.push({ product_id: '', min_quantity: 1, price: 0 });
        },
        removeItem(index) {
            if (this.items.length > 1) {
                this.items.splice(index, 1);
            }
        }
    }">
        <form action="{{ route('price-lists.update', $priceList->id) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pb-6 border-b border-[#E5E7EB]">
                <div>
                    <x-form-label for="code">Price List Code <span class="text-danger-600">*</span></x-form-label>
                    <input type="text" id="code" name="code" value="{{ old('code', $priceList->code) }}" required maxlength="64"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm uppercase focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    <x-input-error :messages="$errors->get('code')" class="mt-1" />
                </div>

                <div>
                    <x-form-label for="name">Price Rule Name <span class="text-danger-600">*</span></x-form-label>
                    <input type="text" id="name" name="name" value="{{ old('name', $priceList->name) }}" required maxlength="150"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-form-label for="type">Rule Type / Scope <span class="text-danger-600">*</span></x-form-label>
                    <select id="type" name="type" x-model="type" required
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="standard">Standard (Global / General)</option>
                        <option value="wholesale">Tiered Wholesale (Volume Qty)</option>
                        <option value="customer_tier">Loyalty Member Tier</option>
                        <option value="customer_specific">Custom Customer Contract</option>
                        <option value="branch">Branch Specific</option>
                        <option value="channel">Sales Channel Specific (Online Delivery, etc.)</option>
                    </select>
                </div>

                <div>
                    <x-form-label for="priority">Evaluation Priority (Lower number = Higher precedence)</x-form-label>
                    <input type="number" id="priority" name="priority" value="{{ old('priority', $priceList->priority) }}" min="0" required
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                {{-- Target Dinamis --}}
                <div x-show="type === 'customer_specific'" x-cloak>
                    <x-form-label for="customer_id">Select Contract Customer</x-form-label>
                    <select id="customer_id" name="customer_id"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="">-- Select Customer --</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}" {{ (string)old('customer_id', $priceList->customer_id) === (string)$c->id ? 'selected' : '' }}>
                                {{ $c->name }} ({{ $c->phone ?? 'No Phone' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'customer_tier'" x-cloak>
                    <x-form-label for="loyalty_tier_id">Select Loyalty Tier</x-form-label>
                    <select id="loyalty_tier_id" name="loyalty_tier_id"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="">-- Select Tier --</option>
                        @foreach ($loyaltyTiers as $t)
                            <option value="{{ $t->id }}" {{ (string)old('loyalty_tier_id', $priceList->loyalty_tier_id) === (string)$t->id ? 'selected' : '' }}>
                                {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'branch'" x-cloak>
                    <x-form-label for="store_id">Select Target Branch</x-form-label>
                    <select id="store_id" name="store_id"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="">-- Select Branch --</option>
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}" {{ (string)old('store_id', $priceList->store_id) === (string)$s->id ? 'selected' : '' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'channel'" x-cloak>
                    <x-form-label for="channel">Sales Channel (e.g. online_delivery, pos, grabfood)</x-form-label>
                    <input type="text" id="channel" name="channel" value="{{ old('channel', $priceList->channel) }}"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                <div>
                    <x-form-label for="valid_from">Valid From (Optional)</x-form-label>
                    <input type="date" id="valid_from" name="valid_from" value="{{ old('valid_from', $priceList->valid_from?->format('Y-m-d')) }}"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                <div>
                    <x-form-label for="valid_until">Valid Until (Optional)</x-form-label>
                    <input type="date" id="valid_until" name="valid_until" value="{{ old('valid_until', $priceList->valid_until?->format('Y-m-d')) }}"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                <div class="flex items-center gap-2 pt-2 md:col-span-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $priceList->is_active) ? 'checked' : '' }}
                        class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                    <label for="is_active" class="text-sm font-medium text-apeiron-ink">This price list is active</label>
                </div>
            </div>

            {{-- Tabel Rincian Harga Produk --}}
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h5 class="text-base font-bold text-apeiron-ink">Item Details & Tiered Pricing</h5>
                        <p class="text-xs text-[#787774]">Specify products, minimum purchase quantity thresholds (Wholesale Tiers), and unit pricing.</p>
                    </div>
                    <button type="button" @click="addItem()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[#F7F7F5] hover:bg-[#E9E9E7] text-xs font-semibold text-apeiron-ink transition-colors">
                        <span class="material-symbols-rounded text-[18px]">add</span> Add Row
                    </button>
                </div>

                <div class="overflow-x-auto border border-[#E5E7EB] rounded-2xl">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-[#F7F7F5] border-b border-[#E5E7EB] text-xs text-[#787774] uppercase">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3 w-40 text-right">Min. Purchase (Qty)</th>
                                <th class="px-4 py-3 w-48 text-right">Unit Price (Rp)</th>
                                <th class="px-4 py-3 w-16 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E7EB]">
                            <template x-for="(item, index) in items" :key="index">
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <select :name="'items[' + index + '][product_id]'" x-model="item.product_id" required
                                            class="w-full h-9 px-3 rounded-lg border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                            <option value="">-- Select Product --</option>
                                            @foreach ($products as $p)
                                                <option value="{{ $p->id }}">{{ $p->product_name }} (Rp {{ number_format($p->product_price, 0, ',', '.') }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <input type="number" :name="'items[' + index + '][min_quantity]'" x-model="item.min_quantity" min="1" required
                                            class="w-full h-9 px-3 rounded-lg border border-[#E5E7EB] text-sm text-right tabular-nums focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <input type="number" :name="'items[' + index + '][price]'" x-model="item.price" min="0" step="0.01" required
                                            class="w-full h-9 px-3 rounded-lg border border-[#E5E7EB] text-sm text-right tabular-nums focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
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

            <div class="flex justify-end gap-3 pt-6 border-t border-[#E5E7EB]">
                <a href="{{ route('price-lists.index') }}" wire:navigate>
                    <x-ui.button variant="secondary" type="button">Cancel</x-ui.button>
                </a>
                <x-ui.button variant="primary" type="submit" x-on:click="window.erpMotion?.pulseHighlight($el)">Update Price List</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
