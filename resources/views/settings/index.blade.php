<x-app-layout>
    <div class="flex flex-col gap-6 max-w-5xl mx-auto">
        <div>
            <h1 class="text-2xl font-bold text-apeiron-ink tracking-tight">Pengaturan Sistem</h1>
            <p class="text-sm font-medium text-[#787774] mt-1">Konfigurasi preferensi Point of Sales dan Enterprise Features.</p>
        </div>

        @if (session('success'))
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-semibold">
                {{ session('success') }}
            </div>
        @endif

        <div class="card-surface bg-white rounded-2xl border border-apeiron-border p-6 shadow-sm">
            <form action="{{ route('settings.update') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <h2 class="text-lg font-bold text-apeiron-ink mb-4">Informasi Toko</h2>
                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-apeiron-ink">Nama Toko</label>
                            <input type="text" name="store_name" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all" value="{{ old('store_name', $settings['store_name'] ?? '') }}">
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-apeiron-ink">Alamat Lengkap</label>
                            <textarea rows="3" name="store_address" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all">{{ old('store_address', $settings['store_address'] ?? '') }}</textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-sm font-bold text-apeiron-ink">Nomor Telepon</label>
                                <input type="text" name="store_phone" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all" value="{{ old('store_phone', $settings['store_phone'] ?? '') }}">
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-sm font-bold text-apeiron-ink">Email</label>
                                <input type="email" name="store_email" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all" value="{{ old('store_email', $settings['store_email'] ?? '') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-6 border-t border-[#E9E9E7]">
                    <h2 class="text-lg font-bold text-apeiron-ink mb-4">Pajak & Pembulatan</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-apeiron-ink">Tarif Pajak (desimal, cth: 0.11)</label>
                            <input type="number" step="0.01" min="0" max="1" name="tax_rate" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all" value="{{ old('tax_rate', $settings['tax_rate'] ?? 0.11) }}">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-apeiron-ink">Kelipatan Pembulatan (cth: 100)</label>
                            <input type="number" step="1" min="1" name="rounding_value" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all" value="{{ old('rounding_value', $settings['rounding_value'] ?? 100) }}">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-sm font-bold text-apeiron-ink">Metode Pembulatan</label>
                            <select name="rounding_behavior" class="w-full bg-[#F9F9F8] border border-[#E9E9E7] rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all">
                                <option value="ROUND_NEAREST" @selected(($settings['rounding_behavior'] ?? 'ROUND_NEAREST') === 'ROUND_NEAREST')>Terdekat (ROUND_NEAREST)</option>
                                <option value="ROUND_UP" @selected(($settings['rounding_behavior'] ?? '') === 'ROUND_UP')>Ke Atas (ROUND_UP)</option>
                                <option value="ROUND_DOWN" @selected(($settings['rounding_behavior'] ?? '') === 'ROUND_DOWN')>Ke Bawah (ROUND_DOWN)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                    <button type="submit" class="bg-apeiron-ink text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-sm hover:-translate-y-0.5 transition-transform">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
