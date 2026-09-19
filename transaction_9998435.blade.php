<x-app-layout>

<!-- Wrapper POS fullscreen-ish inside layout padding -->
{{-- x-data="posApp()" mengaktifkan Alpine.js: semua logic interaktif (keranjang, kalkulasi,
     filter produk, dll) didefinisikan dalam fungsi posApp() di bagian <script> paling bawah --}}
<div class="bg-white dark:bg-gray-900 flex flex-col h-full overflow-hidden min-h-0" x-data="posApp({{ \Illuminate\Support\Js::from([
    'paymentMethod' => 'cash',
    'products' => $products->map(function($p) {
        return [
            'id' => $p->id,
            'nama' => $p->product_name, // di Alpine diikat ke 'nama' dan _searchKey
            'harga' => $p->product_price, // format JS butuh nama key yang sama dgn referensi
            'stock' => $p->stock,
            'photo' => $p->product_photo ? asset('storage/' . $p->product_photo) : null,
            'category_id' => $p->category_id,
            'category_name' => $p->category ? $p->category->category_name : 'Umum',
        ];
    }),
    'categories' => $categories->map(function($c) use ($products) {
        $cProducts = $products->where('category_id', $c->id);
        return [
            'id' => $c->id,
            'nama' => $c->category_name,
            'count' => $cProducts->count(),
            'has_empty_stock' => $cProducts->where('stock', '<=', 0)->count() > 0
        ];
    }),
    'taxRate' => $taxRate,
    'roundingBehavior' => $roundingBehavior,
    'roundingValue' => $roundingValue,
    'activePaymentMethods' => $activePaymentMethods,
    'hardwareAutoDrawer' => $hardwareAutoDrawer,
    'orderChange' => old('order_change', 0),
    'storeRoute' => route('transaction.store')
]) }})"
    x-init="window.addEventListener('keydown', (e) => {
        if (e.key === 'F2') { e.preventDefault(); $refs.searchInput?.focus(); }
    })">

    <!-- Bagian Header: Menampilkan informasi singkat dan tombol kosongkan keranjang -->
    <header class="bg-white dark:bg-gray-900 flex justify-between items-center px-6 h-[72px] border-b border-gray-200 dark:border-gray-700 shrink-0 sticky top-0 z-30">
        <div class="flex items-center gap-4">
            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 leading-none">{{ __('Buat Pesanan') }}</h1>
            <div class="h-6 w-px bg-gray-200"></div>
            {{-- Badge kecil menampilkan ringkasan transaksi & omzet HARI INI,
                 datanya dikirim dari OrderController@create --}}
            <span class="text-sm font-medium text-gray-800 dark:text-gray-200 bg-white dark:bg-gray-900 py-1.5 px-3 rounded-full border border-gray-200 dark:border-gray-700 shadow-sm flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse border border-white"></span>
                <span>{{ $todayCount }} {{ __('Transaksi (Rp') }} {{ number_format($todayOmzet, 0, ',', '.') }})</span>
            </span>
        </div>
        <div class="flex items-center gap-4">
            {{-- Menampilkan tanggal hari ini, disembunyikan di layar kecil (hidden sm:inline-block) --}}
            <span class="text-sm font-medium text-gray-800 dark:text-gray-200 hidden sm:inline-block">
                {{ \Carbon\Carbon::now()->format('d M Y') }}
            </span>
            {{-- Tombol kosongkan keranjang. Ditambah konfirmasi supaya sentuhan/klik tidak sengaja
                 tidak menghapus seluruh pesanan yang sedang disusun kasir, dan dinonaktifkan kalau
                 keranjang memang sudah kosong --}}
            <button type="button"
                    @click="if (cart.length && confirm('{{ __('Kosongkan semua item di keranjang?') }}')) emptyCart()"
                    :disabled="cart.length === 0"
                    :class="cart.length === 0 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-white dark:hover:bg-gray-900 hover:text-rose-600 hover:border-rose-200'"
                    class="text-sm font-semibold text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 rounded-lg px-4 h-11 transition-colors flex items-center gap-2 shadow-sm">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                {{ __('Kosongkan Keranjang') }}
            </button>
        </div>
    </header>

    <!-- Struktur Utama: Layout Kiri (Produk) & Kanan (Keranjang) -->
    <div class="flex flex-col lg:flex-row flex-1 w-full overflow-hidden relative min-h-0">

        <!-- PANEL KIRI: Pencarian, Kategori & Daftar Produk -->
        <section class="flex-1 min-w-0 min-h-0 flex flex-col bg-white dark:bg-gray-900 border-b lg:border-b-0 lg:border-r border-gray-200 dark:border-gray-700 overflow-y-auto">
            <!-- Navigasi Bar Lengket (Sticky) untuk Cari & Filter -->
            <div class="sticky top-0 p-4 lg:p-6 bg-white dark:bg-gray-900 shrink-0 border-b border-gray-200 dark:border-gray-700 z-20 shadow-[var(--shadow-surface)]">
                <div class="flex flex-col xl:flex-row-reverse xl:items-center gap-4 xl:gap-6">

                    <!-- Search Bar -->
                    {{-- x-model="searchQuery" menghubungkan input ini langsung ke variabel
                         searchQuery di Alpine.js, jadi setiap ketikan otomatis memfilter produk --}}
                    <div class="shrink-0 w-full xl:w-72">
                        <div class="flex items-center w-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl focus-within:border-gray-900 focus-within:ring-1 focus-within:ring-gray-900 focus-within:bg-white dark:bg-gray-900 transition-colors overflow-hidden h-11">
                            <div class="pl-3 pr-2 text-gray-400 flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            </div>
                            {{-- x-ref dipakai supaya F2 (shortcut global di atas) bisa memindahkan fokus ke sini.
                                 @keydown.enter menangani pola scanner barcode: alat scan biasanya mengetik kode
                                 lalu otomatis menekan Enter, jadi kalau hasil filter tinggal 1 produk & stoknya ada,
                                 produk itu langsung masuk keranjang dan kolom pencarian dikosongkan untuk scan berikutnya --}}
                            <input type="text"
                                   x-ref="searchInput"
                                   x-model="searchQuery"
                                   @keydown.enter.prevent="if (filteredProducts.length === 1 && filteredProducts[0].stock > 0) { addToCart(filteredProducts[0]); searchQuery = ''; $nextTick(() => $refs.searchInput.focus()); }"
                                   aria-label="{{ __('Cari produk atau scan barcode') }}"
                                   class="flex-1 w-full h-full pr-3 bg-transparent outline-none text-gray-900 dark:text-gray-100 text-[14px] font-medium placeholder:text-gray-400 placeholder:font-normal"
                                   placeholder="{{ __('Cari atau scan barcode... (F2)') }}" />
                        </div>
                    </div>

                    <!-- Filter Categories (Seamless Google Chip Style) -->
                    <div class="flex-1 overflow-hidden">
                        <div class="flex gap-2 overflow-x-auto pb-2 pt-1 px-1 scrollbar-hide items-center relative">
                            
                            <!-- Tombol "Semua" -->
                            <button type="button" @click="activeCategory = 'all'"
                                    :aria-pressed="activeCategory === 'all'"
                                    :class="activeCategory === 'all' ? 'bg-primary-900 text-white shadow-md' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 hover:text-gray-900 dark:hover:text-gray-100'"
                                    class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-full text-[13px] font-medium transition-[transform,background-color,color] duration-200 [transition-timing-function:var(--ease-spring)] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-900 focus-visible:ring-offset-2">
                                
                                <span class="tracking-tight">{{ __('Semua') }}</span>
                                
                                <!-- Badge Jumlah Item -->
                                <span :class="activeCategory === 'all' ? 'bg-white/20 dark:bg-gray-900/20 text-white' : 'bg-white dark:bg-gray-900 text-gray-500 dark:text-gray-400'" 
                                    class="px-1.5 py-0.5 rounded-full text-[9px] font-bold" 
                                    x-text="products.length">
                                </span>

                                <!-- Red Dot Indicator (Pengganti tanda seru) -->
                                <span x-show="products.some(p => p.stock <= 0)" 
                                    class="w-2 h-2 rounded-full bg-rose-500 shadow-sm" 
                                    style="display: none;" 
                                    title="Ada stok habis">
                                </span>
                            </button>

                            <!-- Kategori Loop -->
                            <template x-for="category in categories" :key="category.id">
                                <button type="button" @click="activeCategory = category.id"
                                        :aria-pressed="activeCategory === category.id"
                                        :class="activeCategory === category.id ? 'bg-primary-900 text-white shadow-md' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 hover:text-gray-900 dark:hover:text-gray-100'"
                                        class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-full text-[13px] font-medium transition-[transform,background-color,color] duration-200 [transition-timing-function:var(--ease-spring)] active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-900 focus-visible:ring-offset-2">
                                    
                                    <span class="tracking-tight" x-text="category.nama"></span>
                                    
                                    <!-- Badge Jumlah Item -->
                                    <span :class="activeCategory === category.id ? 'bg-white/20 dark:bg-gray-900/20 text-white' : 'bg-white dark:bg-gray-900 text-gray-500 dark:text-gray-400'" 
                                        class="px-1.5 py-0.5 rounded-full text-[10px] font-bold" 
                                        x-text="category.count">
                                    </span>

                                    <!-- Red Dot Indicator -->
                                    <span x-show="category.has_empty_stock" 
                                        class="w-2 h-2 rounded-full bg-rose-500 shadow-sm" 
                                        style="display: none;" 
                                        title="Ada stok habis">
                                    </span>
                                </button>
                            </template>
                            
                        </div>
                    </div>
                </div>
            </div>

            <!-- Area Grid: Menampilkan kumpulan galeri produk -->
            <div class="p-4 lg:p-6 lg:pt-2">
                <div class="grid grid-cols-[repeat(auto-fill,minmax(140px,1fr))] sm:grid-cols-[repeat(auto-fill,minmax(180px,1fr))] xl:grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-4 lg:gap-6">
                    {{-- Loop produk yang sudah difilter (lihat computed property filteredProducts di JS) --}}
                    <template x-for="product in filteredProducts" :key="product.id">
                        <!-- Product Card -->
                        {{-- Kalau stok 0, kartu produk dibuat abu-abu (grayscale) dan tidak bisa diklik --}}
                        {{-- role="button" + tabindex + @keydown menjadikan kartu produk bisa dioperasikan
                             pakai keyboard (Tab lalu Enter/Space), bukan cuma klik mouse/tap --}}
                        <div @click="addToCart(product)"
                             @keydown.enter="product.stock > 0 && addToCart(product)"
                             @keydown.space.prevent="product.stock > 0 && addToCart(product)"
                             role="button"
                             :tabindex="product.stock > 0 ? 0 : -1"
                             :aria-disabled="product.stock <= 0"
                             :aria-label="product.nama + ', Rp ' + formatRupiah(product.harga) + (product.stock > 0 ? '' : ', stok habis')"
                             :class="product.stock > 0 ? 'hover:border-gray-900 hover:shadow-[var(--shadow-floating)] hover:-translate-y-1 cursor-pointer active:scale-[0.98] focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2' : 'opacity-60 grayscale-[0.8] cursor-not-allowed'"
                             class="group bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-[var(--shadow-surface)] transition-all duration-200 flex flex-col h-full relative outline-none">

                            <!-- Label pita penanda status stok habis atau menipis -->
                            <div class="absolute top-2 left-2 z-20 flex flex-col gap-1 items-start">
                                {{-- Badge merah "Habis" kalau stok <= 0 --}}
                                <template x-if="product.stock <= 0">
                                    <span class="shadow-sm bg-rose-100 text-rose-700 text-xs px-2 py-1 rounded">{{ __('Habis') }}</span>
                                </template>
                                {{-- Badge kuning "Stok Menipis" kalau stok masih ada tapi <= 5 --}}
                                <template x-if="product.stock > 0 && product.stock <= 5">
                                    <span class="shadow-sm bg-yellow-100 text-yellow-700 text-[10px] px-2 py-1 rounded">{{ __('Stok Menipis') }}</span>
                                </template>
                            </div>

                            <!-- Lencana (Badge) indikator jumlah barang di keranjang yang melayang -->
                            {{-- Muncul kalau produk ini sudah ada di keranjang, menampilkan jumlah (qty)-nya --}}
                            <div x-show="cart.find(i => i.id === product.id)" x-transition.scale.origin.top.right class="absolute -top-2 -right-2 z-20 flex" style="display: none;">
                                <span class="bg-white dark:bg-gray-900 border-2 border-white text-gray-900 dark:text-gray-100 text-[11px] font-bold min-w-[24px] h-6 px-1 rounded-full flex items-center justify-center shadow-md shadow-gray-900/30" x-text="cart.find(i => i.id === product.id)?.qty"></span>
                            </div>

                            {{-- Foto produk: kalau ada foto tampilkan gambar, kalau tidak tampilkan ikon placeholder --}}
                            <div class="w-full aspect-square sm:aspect-[4/3] bg-gray-50 dark:bg-gray-800 relative overflow-hidden group-hover:opacity-95 transition-opacity rounded-t-2xl shrink-0 border-b border-gray-100 dark:border-gray-800">
                                <template x-if="product.photo">
                                    <img :src="product.photo" :alt="product.nama" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!product.photo">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="p-3 lg:p-4 flex flex-col flex-1 relative rounded-b-2xl">
                                <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-widest" x-text="product.category_name"></span>
                                <h3 class="text-[13px] lg:text-[14px] font-bold text-gray-900 dark:text-gray-100 mb-3 leading-tight line-clamp-2" x-text="product.nama"></h3>

                                <div class="mt-auto flex flex-col gap-3">
                                    <div class="flex items-end justify-between">
                                        <span class="block text-[14px] lg:text-[15px] font-black text-gray-900 dark:text-gray-100" x-text="'Rp ' + formatRupiah(product.harga)"></span>
                                        <span class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-0.5" x-text="'Stok: ' + product.stock"></span>
                                    </div>

                                    <!-- Kartu berfungsi sbg tombol Add-To-Cart langsung berkat @click di container luar -->
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <!-- PANEL KANAN: Daftar Keranjang Belanja -->
        <!-- PANEL KANAN: Daftar Keranjang Belanja -->
        <section class="w-full lg:w-96 xl:w-[400px] bg-white dark:bg-gray-900 flex flex-col shrink-0 h-[50vh] lg:h-full lg:border-l border-gray-200 dark:border-gray-700 z-30">
            <!-- Judul Keranjang & Kode Pesanan -->
            <div class="p-4 lg:p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between shrink-0 h-[72px]">
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 leading-none">{{ __('Keranjang') }}</h2>
                    <span :class="{'scale-[1.15] bg-emerald-600': cartPulse, 'bg-primary-900': !cartPulse}" class="text-white text-[11px] font-bold px-2.5 py-0.5 rounded-full transition-all duration-300 transform" x-text="cart.length"></span>
                </div>
                <span title="Kode final dibuat otomatis oleh sistem saat transaksi disimpan" class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 px-3 py-1 rounded-full uppercase tracking-wider border border-gray-200 dark:border-gray-700">#{{ strtoupper(\Illuminate\Support\Str::random(8)) }}</span>
            </div>

            <!-- Form dijadikan kontainer relatif -->
            <form action="{{ route('transaction.store') }}" method="POST" class="relative flex-1 w-full min-h-0" @submit="onSubmitForm">
                @csrf
                <input type="hidden" name="cash_received" :value="paymentMethod === 'cash' ? (uangDibayar || null) : null">
                <input type="hidden" name="status" :value="paymentMethod === 'midtrans' ? 'pending' : 'paid'">
                <input type="hidden" name="payment_method" :value="paymentMethod">

                <template x-for="(item, index) in cart" :key="item.id">
                    <div>
                        <input type="hidden" :name="'items['+index+'][product_id]'" :value="item.id">
                        <input type="hidden" :name="'items['+index+'][quantity]'" :value="item.qty">
                    </div>
                </template>

                <!-- KUNCI PERBAIKAN: absolute inset-0 memaksa div ini tidak melebihi tinggi form -->
                <div class="absolute inset-0 flex flex-col">
                    
                    <!-- AREA PRODUK YANG DI-SCROLL -->
                    <div x-ref="cartScrollBox"
                         x-data="{ nearBottom: true }"
                         x-init="$watch('cart.length', () => { if (nearBottom) { $nextTick(() => $refs.cartScrollBox.scrollTo({ top: $refs.cartScrollBox.scrollHeight, behavior: 'smooth' })) } })"
                         @scroll.debounce.50ms="nearBottom = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 56"
                         class="flex-1 overflow-y-auto custom-scrollbar p-4 lg:p-5 space-y-3 bg-gray-50/30 dark:bg-gray-800/30">
                        
                        <template x-for="(item, index) in cart" :key="item.id">
                            <div class="flex gap-4 p-3 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm relative group">
                                <button type="button" @click="removeFromCart(index)" :aria-label="'Hapus ' + item.nama + ' dari keranjang'" class="absolute -top-2 -right-2 bg-white dark:bg-gray-900 rounded-full p-1 border border-gray-200 dark:border-gray-700 text-gray-400 hover:text-rose-500 hover:border-rose-200 shadow-sm transition-opacity">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                                <div class="w-16 h-16 rounded-lg bg-gray-100 dark:bg-gray-800 overflow-hidden shrink-0 flex items-center justify-center border border-gray-200/50 dark:border-gray-700/50">
                                    <template x-if="item.photo">
                                        <img :src="item.photo" class="w-full h-full object-cover">
                                    </template>
                                </div>
                                <div class="flex-1 flex flex-col justify-between">
                                    <div class="flex justify-between items-start">
                                        <h4 class="text-[13.5px] font-semibold text-gray-900 dark:text-gray-100 line-clamp-2 pr-2 leading-tight" x-text="item.nama"></h4>
                                    </div>
                                    <div class="flex items-end justify-between mt-2">
                                        <div>
                                            <div class="text-[11px] text-gray-600 dark:text-gray-400 mb-1" x-text="'Rp ' + formatRupiah(item.harga) + ' / Item'"></div>
                                            <span class="text-[14px] font-black text-gray-900 dark:text-gray-100 whitespace-nowrap" x-text="'Rp ' + formatRupiah(item.harga * item.qty)"></span>
                                        </div>
                                        <div class="flex items-center gap-1.5 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-full px-1.5 py-1">
                                            <button type="button" @click="decreaseQty(index)" class="w-6 h-6 rounded-full flex items-center justify-center bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100 transition-colors shadow-sm">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M20 12H4" /></svg>
                                            </button>
                                            <span class="text-[13px] font-bold text-gray-900 dark:text-gray-100 w-5 text-center leading-none" x-text="item.qty"></span>
                                            <button type="button" @click="increaseQty(index)" class="w-6 h-6 rounded-full flex items-center justify-center bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-100 transition-colors shadow-sm">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div x-show="cart.length === 0" class="h-full flex flex-col justify-center items-center opacity-70" style="display: none;">
                            <svg class="w-16 h-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                            <p class="text-[14px] font-semibold text-gray-900 dark:text-gray-100">{{ __('Keranjang Kosong') }}</p>
                            <p class="text-[12px] text-gray-500 dark:text-gray-400 mt-1">{{ __('Pilih produk dari menu di sebelah kiri') }}</p>
                        </div>
                    </div>

                    <!-- AREA KALKULASI: TETAP DI BAWAH -->
                    <div class="p-3 lg:p-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shrink-0 z-10 shadow-[0_-4px_20px_rgba(0,0,0,0.03)] flex flex-col gap-3">
                        <div class="flex flex-col gap-1.5 p-3 rounded-xl border border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/60">
                            <div class="flex justify-between items-center">
                                <span class="text-[12px] text-gray-500 dark:text-gray-400 font-medium">{{ __('Subtotal') }}</span>
                                <span class="text-[12px] font-medium text-gray-600 dark:text-gray-400 tabular-nums" x-text="'Rp ' + formatRupiah(cartSubtotal)"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[12px] text-gray-500 dark:text-gray-400 font-medium">{{ __('Pajak') }} ({{ $taxRatePercent }}%)</span>
                                <span class="text-[12px] font-medium text-gray-600 dark:text-gray-400 tabular-nums" x-text="'Rp ' + formatRupiah(taxAmount)"></span>
                            </div>
                        </div>

                        {{-- Total dipisah jadi focal point tersendiri, bukan baris ke-4 di kotak abu-abu --}}
                        <div class="flex justify-between items-baseline px-1 pt-1">
                            <span class="text-[13px] font-semibold text-gray-700 dark:text-gray-300">{{ __('Total Pembayaran') }}</span>
                            <span class="text-[24px] font-black text-gray-900 dark:text-gray-100 tabular-nums tracking-tight"
                                  x-text="'Rp ' + formatRupiah(totalAmount)"></span>
                        </div>
                        
                        <div class="flex justify-between items-center px-1">
                            <span class="text-[12px] text-gray-500 dark:text-gray-400 font-medium">{{ __('Kembalian') }}</span>
                            <span class="font-bold text-[14px] tabular-nums tracking-tight" :class="orderChange >= 0 && uangDibayar > 0 ? 'text-emerald-600' : 'text-gray-900 dark:text-gray-100'" x-text="orderChange < 0 ? 'Rp 0' : 'Rp ' + formatRupiah(orderChange)"></span>
                        </div>

                        <!-- UPDATE: Pilihan Metode Pembayaran (Dropdown UI) -->
                        <div>
                            <label for="paymentMethod" class="block text-[11px] font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2 px-1">{{ __('Metode Pembayaran') }}</label>
                            <div class="relative w-full mb-1">
                                <select id="paymentMethod" x-model="paymentMethod" class="w-full h-11 pl-4 pr-10 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none text-[13px] font-bold text-gray-900 dark:text-gray-100 appearance-none shadow-sm cursor-pointer transition-colors hover:border-gray-300 dark:hover:border-gray-700">
                                    <option value="cash">{{ __('Tunai di Kasir') }}</option>
                                    <option value="qris" x-show="activePaymentMethods.qris">QRIS</option>
                                    <option value="ewallet" x-show="activePaymentMethods.ewallet">E-Wallet</option>
                                    <!-- Opsi metode pembayaran ini dihapus karena tidak pernah diaktifkan lewat
                                         $activePaymentMethods (TransactionController::create()) — menyederhanakan
                                         kode agar sesuai cakupan kebutuhan UjiKom. 
                                         TODO: aktifkan setelah $activePaymentMethods mendukung. -->
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 dark:text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                            </div>
                        </div>

                        <!-- Tunai Input & Quick Cash (Hanya muncul jika pilih Cash) -->
                        <div x-show="paymentMethod === 'cash'" x-transition>
                            <div class="relative w-full mb-2">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-800 dark:text-gray-200 text-[14px] font-bold">Rp</span>
                                <input type="text" x-model="uangDibayarFormatted" @input="formatInputUang($event)" class="w-full h-11 pl-12 pr-4 text-right bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none text-[16px] font-black text-gray-900 dark:text-gray-100 shadow-sm" placeholder="0" />
                            </div>
                            <div class="flex gap-1.5">
                                <button type="button" @click="uangDibayarFormatted = formatRupiah(totalAmount); calculateChange()"
                                    class="flex-1 text-[11px] font-black py-2 rounded-xl bg-primary-900 text-white hover:bg-primary-800 shadow-sm">PAS</button>
                                <button type="button" @click="addUangDibayar(50000)"
                                    class="flex-1 text-[12px] font-bold py-2 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition-colors">+50K</button>
                                <button type="button" @click="addUangDibayar(100000)"
                                    class="flex-1 text-[12px] font-bold py-2 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition-colors">+100K</button>
                            </div>
                        </div>

                        <!-- Fitur diskon dihapus karena backend (TransactionService) tidak pernah
                             mengimplementasikannya — kode di frontend sebelumnya adalah fitur mati (dead
                             code) yang berisiko menyebabkan selisih pembayaran jika diaktifkan tanpa
                             perubahan backend yang sepadan. -->

                        <button type="submit" :disabled="submitting || cart.length === 0" :class="submitting || cart.length === 0 ? 'bg-gray-200 cursor-not-allowed text-gray-400' : 'bg-primary-900 hover:bg-primary-950 text-white hover:-translate-y-0.5'" class="w-full h-12 mt-1 shrink-0 rounded-xl font-black text-[13px] transition-all duration-200 shadow-sm flex items-center justify-center gap-2">
                            <span x-text="submitting ? '{{ __('Memproses...') }}' : paymentMethodText"></span>
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>

<style>
    /* Menyembunyikan scrollbar horizontal pada daftar kategori supaya tampilan lebih rapi */
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }
    .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    /* Scrollbar tipis & senada tema netral untuk daftar keranjang (dipakai via class
       "custom-scrollbar" pada panel keranjang) — dulu class ini dipanggil tapi belum
       pernah didefinisikan, jadi browser jatuh ke scrollbar bawaan yang tebal/kontras */
    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: #d1d5db transparent;
    }
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #d1d5db; /* gray-300, senada palet netral */
        border-radius: 9999px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #9ca3af; /* gray-400 */
    }

    /* Menghormati preferensi pengguna yang mematikan animasi di OS-nya.
       Ditulis "@@media" (dobel @) supaya Blade tidak salah mengira ini sebuah
       directive Blade dan menghapusnya saat kompilasi — @media CSS asli
       butuh escaping ini di dalam file .blade.php */
    @@media (prefers-reduced-motion: reduce) {
        * {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }
</style>

<x-pos-script />
</x-app-layout>
