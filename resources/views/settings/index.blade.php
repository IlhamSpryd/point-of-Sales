<x-app-layout>
    <div class="flex flex-col gap-6 max-w-5xl mx-auto">
        <div>
            <h1 class="text-2xl font-bold text-[#37352F] tracking-tight">Pengaturan Sistem</h1>
            <p class="text-sm font-medium text-[#787774] mt-1">Konfigurasi preferensi Point of Sales dan Enterprise Features.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Sidebar -->
            <div class="md:col-span-1 space-y-2">
                <a href="#" class="block px-4 py-3 bg-[#37352F] text-white rounded-xl font-bold text-sm shadow-sm transition-all">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-rounded text-[20px]">storefront</span>
                        Informasi Toko
                    </div>
                </a>
                <a href="#" class="block px-4 py-3 text-[#787774] hover:bg-[#F9F9F8] rounded-xl font-semibold text-sm transition-colors border border-transparent hover:border-[#E9E9E7]">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-rounded text-[20px]">receipt_long</span>
                        Preferensi Struk
                    </div>
                </a>
                <a href="#" class="block px-4 py-3 text-[#787774] hover:bg-[#F9F9F8] rounded-xl font-semibold text-sm transition-colors border border-transparent hover:border-[#E9E9E7]">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-rounded text-[20px]">point_of_sale</span>
                        Pajak & Pembulatan
                    </div>
                </a>
                <a href="#" class="block px-4 py-3 text-[#787774] hover:bg-[#F9F9F8] rounded-xl font-semibold text-sm transition-colors border border-transparent hover:border-[#E9E9E7]">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-rounded text-[20px]">integration_instructions</span>
                        Integrasi Sistem
                    </div>
                </a>
            </div>

            <!-- Content -->
            <div class="md:col-span-2">
                <div class="card-surface bg-white rounded-2xl border border-yovel-border p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-[#37352F] mb-6">Informasi Toko</h2>
                    
                    <form class="space-y-5">
                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-[#37352F]">Nama Toko</label>
                            <input type="text" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all" value="Yovel Coffee POS">
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-[#37352F]">Alamat Lengkap</label>
                            <textarea rows="3" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all">Jl. Kopi Harapan No. 99, Jakarta Selatan</textarea>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-sm font-bold text-[#37352F]">Nomor Telepon</label>
                                <input type="text" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all" value="081234567890">
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-sm font-bold text-[#37352F]">Email</label>
                                <input type="email" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all" value="hello@yovelcoffee.com">
                            </div>
                        </div>

                        <div class="pt-4 mt-6 border-t border-[#E9E9E7] flex justify-end">
                            <button type="button" class="bg-[#37352F] text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-sm hover:-translate-y-0.5 transition-transform">
                                Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
