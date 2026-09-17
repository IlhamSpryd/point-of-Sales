<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Self-Order - Yovel Coffee & Cafe</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap"
        rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--color-yovel-bg);
            color: var(--color-yovel-ink);
        }
    </style>
</head>

<body class="antialiased">
    <div class="max-w-md mx-auto min-h-screen pb-24 relative bg-[#F7F7F5] shadow-xl" x-data="selfOrderApp({{ Js::from($categories) }})">

        <!-- Header -->
        <header
            class="bg-white p-5 sticky top-0 z-10 border-b border-[#E9E9E7] backdrop-blur-md bg-white/80 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#37352F]"
                    style="font-family: 'Playfair Display', serif;">Yovel Coffee & Cafe</h1>
                <p class="text-xs text-[#787774] mt-0.5">Meja {{ $table->table_number }}</p>
            </div>
        </header>

        <!-- Pesan Error -->
        @if ($errors->any())
            <div class="bg-rose-50 border border-rose-200 p-4 m-4 rounded-xl">
                <div class="flex">
                    <div class="ml-3">
                        <p class="text-sm text-rose-800 font-medium">Ada kesalahan pada pesanan Anda:</p>
                        <ul class="mt-1 list-disc list-inside text-sm text-rose-700">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <!-- Kategori Filter (Pills) -->
        <div
            class="px-4 py-3 overflow-x-auto scrollbar-none flex gap-2 sticky top-[73px] z-10 bg-[#F7F7F5]/90 backdrop-blur-md border-b border-[#E9E9E7]/50">
            <!-- Tombol Semua -->
            <button @click="activeCategory = 'all'"
                class="px-4 py-1.5 rounded-full text-sm font-medium whitespace-nowrap transition-colors"
                :class="activeCategory === 'all' ? 'bg-[#37352F] text-white shadow-sm' :
                    'bg-white border border-[#E9E9E7] text-[#787774] hover:bg-[#F1F1EF]'">
                Semua
            </button>
            <template x-for="category in categories" :key="category.id">
                <button x-show="category.products.length > 0" @click="activeCategory = category.id"
                    class="px-4 py-1.5 rounded-full text-sm font-medium whitespace-nowrap transition-colors"
                    :class="activeCategory === category.id ? 'bg-[#37352F] text-white shadow-sm' :
                        'bg-white border border-[#E9E9E7] text-[#787774] hover:bg-[#F1F1EF]'"
                    x-text="category.category_name"></button>
            </template>
        </div>

        <!-- List Kategori & Produk -->
        <div class="p-4 space-y-8">

            <!-- TAMPILAN SEMUA (Grid Menyatu tanpa pemisah Kategori) -->
            <div x-show="activeCategory === 'all'" class="animate-fade-in" style="display: none;">
                <div class="grid grid-cols-2 gap-3 items-stretch">
                    <template x-for="category in categories" :key="'all-' + category.id">
                        <template x-for="product in category.products" :key="'all-prod-' + product.id">
                            <!-- Card Product Ecommerce Style -->
                            <div class="bg-white rounded-xl shadow-[0_2px_10px_rgba(0,0,0,0.04)] border border-[#E9E9E7] overflow-hidden cursor-pointer hover:shadow-lg transition-all active:scale-95 flex flex-col h-full"
                                @click="openProductModal(product)">
                                <!-- Gambar -->
                                <div class="w-full aspect-square bg-[#F7F7F5] relative border-b border-[#F1F1EF]">
                                    <img x-show="product.product_photo" :src="`/storage/${product.product_photo}`"
                                        class="w-full h-full object-cover">
                                    <div x-show="!product.product_photo"
                                        class="w-full h-full flex items-center justify-center text-[#9B9A97]">
                                        <svg class="w-8 h-8 opacity-40" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                            </path>
                                        </svg>
                                    </div>
                                </div>
                                <!-- Info -->
                                <div class="p-3 flex flex-col flex-1 justify-between gap-2">
                                    <h3 class="font-medium text-[13px] text-[#37352F] leading-snug line-clamp-2 min-h-[36px]"
                                        x-text="product.product_name"></h3>
                                    <div class="flex items-center justify-between mt-auto">
                                        <p class="text-[#37352F] font-bold text-[13.5px] tracking-tight"
                                            x-text="formatRupiah(product.product_price)"></p>
                                        <div
                                            class="bg-[#37352F] text-white rounded-full w-6 h-6 flex items-center justify-center shadow-sm">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 4v16m8-8H4"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </div>

            <!-- TAMPILAN PER KATEGORI (Jika memilih kategori spesifik) -->
            <template x-for="category in categories" :key="category.id">
                <div x-show="category.products.length > 0 && activeCategory === category.id" class="animate-fade-in"
                    style="display: none;">
                    <h2 class="text-lg font-bold text-[#37352F] mb-4" x-text="category.category_name"></h2>
                    <div class="grid grid-cols-2 gap-3 items-stretch">
                        <template x-for="product in category.products" :key="product.id">
                            <!-- Card Product Ecommerce Style -->
                            <div class="bg-white rounded-xl shadow-[0_2px_10px_rgba(0,0,0,0.04)] border border-[#E9E9E7] overflow-hidden cursor-pointer hover:shadow-lg transition-all active:scale-95 flex flex-col h-full"
                                @click="openProductModal(product)">
                                <!-- Gambar -->
                                <div class="w-full aspect-square bg-[#F7F7F5] relative border-b border-[#F1F1EF]">
                                    <img x-show="product.product_photo" :src="`/storage/${product.product_photo}`"
                                        class="w-full h-full object-cover">
                                    <div x-show="!product.product_photo"
                                        class="w-full h-full flex items-center justify-center text-[#9B9A97]">
                                        <svg class="w-8 h-8 opacity-40" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                            </path>
                                        </svg>
                                    </div>
                                </div>
                                <!-- Info -->
                                <div class="p-3 flex flex-col flex-1 justify-between gap-2">
                                    <h3 class="font-medium text-[13px] text-[#37352F] leading-snug line-clamp-2 min-h-[36px]"
                                        x-text="product.product_name"></h3>
                                    <div class="flex items-center justify-between mt-auto">
                                        <p class="text-[#37352F] font-bold text-[13.5px] tracking-tight"
                                            x-text="formatRupiah(product.product_price)"></p>
                                        <div
                                            class="bg-[#37352F] text-white rounded-full w-6 h-6 flex items-center justify-center shadow-sm">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 4v16m8-8H4"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <!-- Floating Cart Bar -->
        <div class="fixed bottom-0 max-w-md w-full bg-white border-t border-[#E9E9E7] p-4 flex justify-between items-center z-20 shadow-[0_-10px_40px_-10px_rgba(0,0,0,0.08)]"
            x-show="cart.length > 0" x-transition:enter="ease-out duration-300"
            x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0">
            <div>
                <p class="text-xs text-[#787774] font-medium">Total Pesanan</p>
                <p class="font-bold text-lg text-[#37352F]" x-text="formatRupiah(cartTotal)"></p>
            </div>
            <button @click="showCart = true"
                class="bg-[#37352F] hover:bg-black text-white px-5 py-2.5 rounded-lg font-medium transition flex items-center gap-2 active:scale-95">
                <span>Keranjang</span>
                <span class="bg-white/20 text-white text-xs py-0.5 px-2 rounded-full font-bold"
                    x-text="cartTotalQty"></span>
            </button>
        </div>

        <!-- MODAL PRODUK (Pilih Modifier) -->
        <div x-show="activeProduct" class="fixed inset-0 z-50 flex flex-col justify-end" style="display: none;">
            <!-- Overlay Glassmorphism -->
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" x-show="activeProduct"
                x-transition.opacity.duration.300ms @click="closeProductModal()"></div>

            <!-- Panel Modal -->
            <div class="bg-[#F7F7F5] w-full max-w-md mx-auto rounded-t-3xl h-[85vh] flex flex-col relative z-10 shadow-2xl"
                x-show="activeProduct" x-transition:enter="ease-out duration-300"
                x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full">

                <div
                    class="p-4 border-b border-[#E9E9E7] flex justify-between items-center sticky top-0 bg-white rounded-t-3xl z-10">
                    <h3 class="font-bold text-lg truncate text-[#37352F]" x-text="activeProduct?.product_name"></h3>
                    <button @click="closeProductModal()"
                        class="p-2 bg-[#F1F1EF] rounded-full text-[#787774] hover:text-[#37352F] hover:bg-[#E3E2E0] transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <div class="p-4 overflow-y-auto flex-1">
                    <template x-if="activeProduct">
                        <div>
                            <!-- Varian / Modifiers -->
                            <template x-for="group in activeProduct.modifier_groups" :key="group.id">
                                <div class="mb-6 bg-white p-4 rounded-2xl border border-[#E9E9E7] shadow-sm">
                                    <div class="flex justify-between items-end mb-3 border-b border-[#F1F1EF] pb-2">
                                        <h4 class="font-bold text-[#37352F]" x-text="group.name"></h4>
                                        <span class="text-xs px-2 py-1 rounded bg-[#F7F7F5] text-[#787774] font-medium"
                                            x-text="group.is_required ? (group.selection_type === 'single' ? 'Wajib pilih 1' : 'Wajib pilih') : 'Opsional'"></span>
                                    </div>
                                    <div class="grid grid-cols-1 gap-2">
                                        <template x-for="mod in group.modifiers" :key="mod.id">
                                            <!-- Gunakan modifier-box dari app.css -->
                                            <label class="modifier-box"
                                                :class="{ 'selected': isModifierSelected(group.id, mod.id) }">
                                                <input
                                                    :type="group.selection_type === 'single' ? 'radio' : 'checkbox'"
                                                    :name="'group_' + group.id" :value="mod.id"
                                                    @change="toggleModifier(group, mod, $event.target.checked)"
                                                    :checked="isModifierSelected(group.id, mod.id)">
                                                <span class="font-medium text-sm transition-colors"
                                                    :class="isModifierSelected(group.id, mod.id) ? 'text-[#37352F]' :
                                                        'text-[#55544E]'"
                                                    x-text="mod.name"></span>
                                                <span class="text-xs font-semibold text-[#787774]"
                                                    x-show="mod.extra_price > 0"
                                                    x-text="'+' + formatRupiah(mod.extra_price)"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <!-- Catatan -->
                            <div class="bg-white p-4 rounded-2xl border border-[#E9E9E7] shadow-sm">
                                <label
                                    class="block font-bold text-[#37352F] mb-2 border-b border-[#F1F1EF] pb-2">Catatan
                                    Tambahan</label>
                                <textarea x-model="modalNotes"
                                    class="w-full bg-[#F7F7F5] border-transparent rounded-xl text-sm focus:border-[#37352F] focus:ring-[#37352F] focus:bg-white transition"
                                    rows="2" placeholder="Contoh: Kurangi gula, es dipisah..."></textarea>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Modal Action Bottom -->
                <div class="p-4 border-t border-[#E9E9E7] bg-white flex items-center justify-between gap-4 pb-8">
                    <div class="flex items-center gap-1 bg-[#F1F1EF] rounded-xl p-1 border border-[#E9E9E7]">
                        <button @click="if(modalQty > 1) modalQty--"
                            class="w-10 h-10 bg-white rounded-lg shadow-sm text-[#37352F] font-medium active:scale-95 transition">-</button>
                        <span class="w-8 text-center font-bold text-[#37352F]" x-text="modalQty"></span>
                        <button @click="modalQty++"
                            class="w-10 h-10 bg-white rounded-lg shadow-sm text-[#37352F] font-medium active:scale-95 transition">+</button>
                    </div>
                    <button @click="addToCart()"
                        class="flex-1 bg-[#37352F] hover:bg-black text-white py-3.5 rounded-xl font-medium transition active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed shadow-md"
                        :disabled="!isModalValid">
                        Tambahkan &bull; <span x-text="formatRupiah(modalTotalPrice)"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL KERANJANG (Cart) -->
        <div x-show="showCart" class="fixed inset-0 z-50 flex flex-col justify-end" style="display: none;">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" x-show="showCart" x-transition.opacity
                @click="showCart = false"></div>

            <div class="bg-[#F7F7F5] w-full max-w-md mx-auto rounded-t-3xl h-[90vh] flex flex-col relative z-10 shadow-2xl"
                x-show="showCart" x-transition:enter="ease-out duration-300"
                x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0">

                <div class="p-4 border-b border-[#E9E9E7] flex justify-between items-center bg-white rounded-t-3xl">
                    <h3 class="font-bold text-lg text-[#37352F]">Keranjang Anda</h3>
                    <button @click="showCart = false"
                        class="p-2 bg-[#F1F1EF] rounded-full text-[#787774] hover:text-[#37352F] hover:bg-[#E3E2E0] transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <div class="p-4 overflow-y-auto flex-1">
                    <template x-if="cart.length === 0">
                        <div class="text-center py-16 text-[#9B9A97] flex flex-col items-center">
                            <svg class="w-16 h-16 mb-4 opacity-50" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 0a2 2 0 100 4 2 2 0 000-4z">
                                </path>
                            </svg>
                            <p class="font-medium">Keranjang masih kosong.</p>
                        </div>
                    </template>
                    <div class="space-y-3">
                        <template x-for="(item, index) in cart" :key="index">
                            <div class="card-surface p-4 flex gap-3 relative">
                                <div class="flex-1">
                                    <h4 class="font-bold text-sm text-[#37352F]" x-text="item.product.product_name">
                                    </h4>
                                    <!-- List Varian terpilih -->
                                    <div class="text-xs text-[#787774] mt-1 space-y-0.5"
                                        x-show="item.selectedModifiers.length > 0">
                                        <template x-for="mod in item.selectedModifiers" :key="mod.id">
                                            <div x-text="'- ' + mod.name"></div>
                                        </template>
                                    </div>
                                    <div class="text-xs text-[#37352F] font-medium bg-[#F1F1EF] px-2 py-1 rounded-md inline-block mt-2"
                                        x-show="item.notes" x-text="'Catatan: ' + item.notes"></div>
                                    <p class="text-[#37352F] font-bold mt-3 text-sm"
                                        x-text="formatRupiah(item.unitPrice)"></p>
                                </div>
                                <div class="flex flex-col justify-between items-end">
                                    <button @click="removeFromCart(index)"
                                        class="text-[#9B9A97] hover:text-rose-500 p-1 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                            </path>
                                        </svg>
                                    </button>
                                    <div
                                        class="flex items-center gap-1 bg-[#F1F1EF] border border-[#E9E9E7] rounded-lg p-0.5 mt-2">
                                        <button @click="updateCartQty(index, -1)"
                                            class="w-7 h-7 bg-white rounded-md text-[#37352F] shadow-sm font-medium active:scale-95 transition">-</button>
                                        <span class="w-6 text-center font-bold text-sm text-[#37352F]"
                                            x-text="item.qty"></span>
                                        <button @click="updateCartQty(index, 1)"
                                            class="w-7 h-7 bg-white rounded-md text-[#37352F] shadow-sm font-medium active:scale-95 transition">+</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Form Submit Pesanan -->
                <div class="p-5 border-t border-[#E9E9E7] bg-white shadow-[0_-10px_40px_-10px_rgba(0,0,0,0.05)] pb-8">
                    <form method="POST" action="{{ route('self-order.store', $table->secure_token) }}">
                        @csrf
                        <!-- Render hidden inputs untuk data cart -->
                        <template x-for="(item, index) in cart" :key="index">
                            <div>
                                <input type="hidden" :name="`items[${index}][product_id]`" :value="item.product.id">
                                <input type="hidden" :name="`items[${index}][qty]`" :value="item.qty">
                                <input type="hidden" :name="`items[${index}][notes]`" :value="item.notes">
                                <template x-for="(mod, mIndex) in item.selectedModifiers" :key="mod.id">
                                    <input type="hidden" :name="`items[${index}][modifier_ids][${mIndex}]`"
                                        :value="mod.id">
                                </template>
                            </div>
                        </template>

                        <div class="flex justify-between items-center mb-5">
                            <span class="text-[#787774] font-medium text-sm">Total Tagihan</span>
                            <span class="font-bold text-xl text-[#37352F]" x-text="formatRupiah(cartTotal)"></span>
                        </div>
                        <button type="submit"
                            class="w-full bg-[#37352F] hover:bg-black text-white py-3.5 rounded-xl font-medium shadow-md transition flex items-center justify-center gap-2 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="cart.length === 0">
                            Pesan Sekarang
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('selfOrderApp', (categoriesData) => ({
                categories: categoriesData,
                cart: [],
                showCart: false,
                activeCategory: 'all',

                // State Modal
                activeProduct: null,
                modalQty: 1,
                modalNotes: '',
                // Format: { groupId: [modId1, modId2] }
                modalSelections: {},

                init() {
                    // Start in 'all' view
                },

                formatRupiah(number) {
                    return new Intl.NumberFormat('id-ID', {
                        style: 'currency',
                        currency: 'IDR',
                        maximumFractionDigits: 0
                    }).format(number);
                },

                openProductModal(product) {
                    this.activeProduct = product;
                    this.modalQty = 1;
                    this.modalNotes = '';
                    this.modalSelections = {};

                    product.modifier_groups.forEach(g => {
                        this.modalSelections[g.id] = [];
                    });
                },

                closeProductModal() {
                    this.activeProduct = null;
                },

                toggleModifier(group, modifier, isChecked) {
                    if (group.selection_type === 'single') {
                        this.modalSelections[group.id] = [modifier.id];
                    } else {
                        let current = this.modalSelections[group.id];
                        if (isChecked) {
                            if (!current.includes(modifier.id)) current.push(modifier.id);
                        } else {
                            this.modalSelections[group.id] = current.filter(id => id !== modifier.id);
                        }
                    }
                },

                isModifierSelected(groupId, modifierId) {
                    return this.modalSelections[groupId] && this.modalSelections[groupId].includes(
                        modifierId);
                },

                get isModalValid() {
                    if (!this.activeProduct) return false;
                    for (let group of this.activeProduct.modifier_groups) {
                        if (group.is_required && this.modalSelections[group.id].length === 0) {
                            return false;
                        }
                    }
                    return true;
                },

                get modalTotalPrice() {
                    if (!this.activeProduct) return 0;
                    let extra = 0;
                    Object.keys(this.modalSelections).forEach(groupId => {
                        const selectedIds = this.modalSelections[groupId];
                        const group = this.activeProduct.modifier_groups.find(g => g.id ==
                            groupId);
                        if (group) {
                            selectedIds.forEach(modId => {
                                const mod = group.modifiers.find(m => m.id ==
                                    modId);
                                if (mod) extra += mod.extra_price;
                            });
                        }
                    });
                    return (this.activeProduct.product_price + extra) * this.modalQty;
                },

                addToCart() {
                    if (!this.isModalValid) return;

                    let selectedMods = [];
                    Object.keys(this.modalSelections).forEach(groupId => {
                        const group = this.activeProduct.modifier_groups.find(g => g.id ==
                            groupId);
                        if (group) {
                            this.modalSelections[groupId].forEach(modId => {
                                const mod = group.modifiers.find(m => m.id == modId);
                                if (mod) selectedMods.push(mod);
                            });
                        }
                    });

                    this.cart.push({
                        product: this.activeProduct,
                        qty: this.modalQty,
                        notes: this.modalNotes,
                        selectedModifiers: selectedMods,
                        unitPrice: this.modalTotalPrice / this.modalQty
                    });

                    this.closeProductModal();
                },

                removeFromCart(index) {
                    this.cart.splice(index, 1);
                },

                updateCartQty(index, delta) {
                    let newQty = this.cart[index].qty + delta;
                    if (newQty >= 1) {
                        this.cart[index].qty = newQty;
                    }
                },

                get cartTotalQty() {
                    return this.cart.reduce((total, item) => total + item.qty, 0);
                },

                get cartTotal() {
                    return this.cart.reduce((total, item) => total + (item.unitPrice * item.qty),
                        0);
                }
            }));
        });
    </script>
</body>

</html>
