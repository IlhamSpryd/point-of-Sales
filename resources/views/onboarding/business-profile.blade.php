<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Langkah 1 dari 2: Profil Bisnis — Apeiron POS</title>
    <link rel="icon" href="{{ asset('assets/images/logo.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-slate-800 bg-slate-50">
    <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        {{-- Header & Stepper --}}
        <div class="sm:mx-auto sm:w-full sm:max-w-xl">
            <div class="flex items-center justify-center gap-3 mb-6">
                <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron System" class="h-12 w-auto object-contain">
                <span class="text-xl font-bold tracking-tight text-slate-900">Apeiron POS</span>
            </div>

            {{-- Stepper Indicator --}}
            <nav aria-label="Progress Onboarding" class="mb-8">
                <ol role="list" class="flex items-center justify-between border-b border-slate-200 pb-5">
                    <li class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white shadow-sm ring-4 ring-emerald-100">
                            1
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Langkah 1</p>
                            <p class="text-sm font-medium text-slate-900">Profil & Standar Bisnis</p>
                        </div>
                    </li>
                    <li class="flex items-center gap-3 opacity-60">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600">
                            2
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Langkah 2</p>
                            <p class="text-sm font-medium text-slate-600">Cabang Pertama</p>
                        </div>
                    </li>
                </ol>
            </nav>

            <h1 class="text-2xl font-bold tracking-tight text-slate-900 text-center">
                Atur Profil Bisnis Anda
            </h1>
            <p class="mt-2 text-sm text-slate-600 text-center">
                Konfirmasikan nama merek dagang, jenis usaha, dan mata uang dasar sebelum membuka register kasir.
            </p>
        </div>

        {{-- Main Form Card --}}
        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-xl px-4 sm:px-0">
            <div class="bg-white py-8 px-6 shadow-sm ring-1 ring-slate-900/5 sm:rounded-2xl sm:px-10">
                @if (session('success'))
                    <div class="mb-6 rounded-xl bg-emerald-50 p-4 border border-emerald-200" role="status">
                        <p class="text-sm font-medium text-emerald-800">{{ session('success') }}</p>
                    </div>
                @endif

                @if (session('info'))
                    <div class="mb-6 rounded-xl bg-blue-50 p-4 border border-blue-200" role="status">
                        <p class="text-sm font-medium text-blue-800">{{ session('info') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-xl bg-rose-50 p-4 border border-rose-200" role="alert">
                        <p class="text-sm font-semibold text-rose-800 mb-1">Terdapat kesalahan pada isian form:</p>
                        <ul class="list-disc list-inside text-xs text-rose-700 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('onboarding.business-profile.store') }}" method="POST" class="space-y-6">
                    @csrf

                    {{-- Nama Bisnis --}}
                    <div>
                        <label for="business_name" class="block text-sm font-semibold text-slate-900">
                            Nama Bisnis / Brand <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <input type="text" name="business_name" id="business_name" required
                                value="{{ old('business_name', $businessName) }}"
                                autocomplete="organization"
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="Contoh: Kopi Senja Nusantara">
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Nama merek dagang yang akan tampil pada laporan dan struk transaksi.</p>
                    </div>

                    {{-- Kategori Usaha --}}
                    <div>
                        <label for="business_category" class="block text-sm font-semibold text-slate-900">
                            Kategori Usaha <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="business_category" id="business_category" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="fnb_cafe" @selected(old('business_category', $businessCategory) === 'fnb_cafe')>Kafe & Kedai Kopi (Coffee Shop)</option>
                                <option value="fnb_restaurant" @selected(old('business_category', $businessCategory) === 'fnb_restaurant')>Restoran & Rumah Makan</option>
                                <option value="fnb_bakery" @selected(old('business_category', $businessCategory) === 'fnb_bakery')>Bakery & Pastry</option>
                                <option value="fnb_cloud_kitchen" @selected(old('business_category', $businessCategory) === 'fnb_cloud_kitchen')>Cloud Kitchen / Booth Makanan</option>
                                <option value="retail_other" @selected(old('business_category', $businessCategory) === 'retail_other')>Ritel & Usaha Lainnya</option>
                            </select>
                        </div>
                    </div>

                    {{-- Telepon Pemilik (WhatsApp) --}}
                    <div>
                        <label for="owner_phone" class="block text-sm font-semibold text-slate-900">
                            Nomor WhatsApp / HP Pemilik <span class="text-xs font-normal text-slate-500">(Opsional)</span>
                        </label>
                        <div class="mt-1.5 flex rounded-xl shadow-sm">
                            <span class="inline-flex items-center px-3.5 rounded-l-xl border border-r-0 border-slate-300 bg-slate-50 text-slate-600 text-sm font-medium">
                                +62
                            </span>
                            <input type="tel" name="owner_phone" id="owner_phone"
                                value="{{ old('owner_phone', $ownerPhone) }}"
                                autocomplete="tel"
                                class="block w-full flex-1 rounded-none rounded-r-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm transition-all"
                                placeholder="81234567890">
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Digunakan untuk notifikasi keamanan penting dan dukungan teknis.</p>
                    </div>

                    {{-- Zona Waktu Utama --}}
                    <div>
                        <label for="tenant_timezone" class="block text-sm font-semibold text-slate-900">
                            Zona Waktu Utama <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="tenant_timezone" id="tenant_timezone" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="Asia/Jakarta" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Jakarta')>WIB — Waktu Indonesia Barat (Asia/Jakarta)</option>
                                <option value="Asia/Makassar" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Makassar')>WITA — Waktu Indonesia Tengah (Asia/Makassar)</option>
                                <option value="Asia/Jayapura" @selected(old('tenant_timezone', $tenantTimezone) === 'Asia/Jayapura')>WIT — Waktu Indonesia Timur (Asia/Jayapura)</option>
                            </select>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Cabang baru akan mewarisi zona waktu ini sebagai nilai standar.</p>
                    </div>

                    {{-- Mata Uang & Negara --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="default_currency" class="block text-sm font-semibold text-slate-900">
                                Mata Uang <span class="text-rose-500" aria-hidden="true">*</span>
                            </label>
                            <input type="text" name="default_currency" id="default_currency" readonly required
                                value="{{ old('default_currency', $defaultCurrency) }}"
                                class="mt-1.5 block w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-slate-700 text-sm font-medium cursor-not-allowed">
                        </div>
                        <div>
                            <label for="country_code" class="block text-sm font-semibold text-slate-900">
                                Negara <span class="text-rose-500" aria-hidden="true">*</span>
                            </label>
                            <input type="text" name="country_code" id="country_code" readonly required
                                value="{{ old('country_code', $countryCode) }}"
                                class="mt-1.5 block w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-slate-700 text-sm font-medium cursor-not-allowed">
                        </div>
                    </div>

                    {{-- Submit Button --}}
                    <div class="pt-4">
                        <button type="submit"
                            class="w-full flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 transition-all cursor-pointer">
                            <span>Simpan & Lanjut ke Cabang Pertama</span>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Logout Option --}}
            <div class="mt-6 text-center">
                <form action="{{ route('logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="text-xs text-slate-500 hover:text-slate-800 underline focus:outline-none">
                        Keluar sementara (kemajuan Anda tersimpan)
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
