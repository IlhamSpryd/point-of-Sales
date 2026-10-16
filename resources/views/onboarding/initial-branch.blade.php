<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Langkah 2 dari 2: Cabang Pertama — Apeiron POS</title>
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
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700">
                            ✓
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Langkah 1</p>
                            <a href="{{ route('onboarding.business-profile') }}" class="text-sm font-medium text-slate-700 hover:text-emerald-700 underline">
                                Profil Bisnis
                            </a>
                        </div>
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white shadow-sm ring-4 ring-emerald-100">
                            2
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Langkah 2</p>
                            <p class="text-sm font-medium text-slate-900">Cabang Pertama</p>
                        </div>
                    </li>
                </ol>
            </nav>

            <h1 class="text-2xl font-bold tracking-tight text-slate-900 text-center">
                Siapkan Cabang Pertama Anda
            </h1>
            <p class="mt-2 text-sm text-slate-600 text-center">
                Setiap transaksi kasir, meja, dan pergeseran shift membutuhkan outlet yang valid untuk pencatatan buku.
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

                <form action="{{ route('onboarding.initial-branch.store') }}" method="POST" class="space-y-6">
                    @csrf

                    {{-- Nama Cabang --}}
                    <div>
                        <label for="store_name" class="block text-sm font-semibold text-slate-900">
                            Nama Cabang / Outlet Pertama <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <input type="text" name="store_name" id="store_name" required
                                value="{{ old('store_name', $storeName) }}"
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="Contoh: Cabang Kemang (Pusat)">
                        </div>
                    </div>

                    {{-- Kode Cabang --}}
                    <div>
                        <label for="store_code" class="block text-sm font-semibold text-slate-900">
                            Kode Cabang <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <input type="text" name="store_code" id="store_code" required
                                value="{{ old('store_code', $storeCode) }}"
                                class="block w-full uppercase rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="MAIN-01">
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Digunakan sebagai awalan nomor struk transaksi dan identifikasi laporan cabang.</p>
                    </div>

                    {{-- Zona Waktu Cabang --}}
                    <div>
                        <label for="store_timezone" class="block text-sm font-semibold text-slate-900">
                            Zona Waktu Cabang <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="store_timezone" id="store_timezone" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="Asia/Jakarta" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jakarta')>WIB — Waktu Indonesia Barat (Asia/Jakarta)</option>
                                <option value="Asia/Makassar" @selected(old('store_timezone', $storeTimezone) === 'Asia/Makassar')>WITA — Waktu Indonesia Tengah (Asia/Makassar)</option>
                                <option value="Asia/Jayapura" @selected(old('store_timezone', $storeTimezone) === 'Asia/Jayapura')>WIT — Waktu Indonesia Timur (Asia/Jayapura)</option>
                            </select>
                        </div>
                    </div>

                    {{-- Jam Tutup Buku Harian (Business-Date Cutoff) --}}
                    <div>
                        <label for="business_date_cutoff" class="block text-sm font-semibold text-slate-900">
                            Jam Tutup Buku Harian (Cutoff Shift) <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5 grid grid-cols-2 gap-3" x-data="{ cutoff: '{{ old('business_date_cutoff', $businessDateCutoff) }}' }">
                            <label class="flex flex-col p-3 border rounded-xl cursor-pointer transition-all"
                                :class="cutoff === '00:00' ? 'border-emerald-600 bg-emerald-50/50 ring-1 ring-emerald-600' : 'border-slate-200 hover:border-slate-300'">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-bold text-slate-900">00:00</span>
                                    <input type="radio" name="business_date_cutoff" value="00:00" x-model="cutoff" class="text-emerald-600 focus:ring-emerald-500">
                                </div>
                                <span class="text-xs text-slate-500 mt-1">Standar (Tengah Malam)</span>
                            </label>
                            <label class="flex flex-col p-3 border rounded-xl cursor-pointer transition-all"
                                :class="cutoff === '04:00' ? 'border-emerald-600 bg-emerald-50/50 ring-1 ring-emerald-600' : 'border-slate-200 hover:border-slate-300'">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-bold text-slate-900">04:00</span>
                                    <input type="radio" name="business_date_cutoff" value="04:00" x-model="cutoff" class="text-emerald-600 focus:ring-emerald-500">
                                </div>
                                <span class="text-xs text-slate-500 mt-1">Kafe/Resto Buka Larut</span>
                            </label>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">Shift yang melewati tengah malam akan tetap dihitung ke hari operasional yang sama bila memilih cutoff 04:00.</p>
                    </div>

                    {{-- Saluran Pesanan Default --}}
                    <div>
                        <label for="default_channel" class="block text-sm font-semibold text-slate-900">
                            Mode Pesanan Kasir Default <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <div class="mt-1.5">
                            <select name="default_channel" id="default_channel" required
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all bg-white">
                                <option value="dine_in" @selected(old('default_channel', $defaultChannel) === 'dine_in')>Makan di Tempat (Dine-In)</option>
                                <option value="takeaway" @selected(old('default_channel', $defaultChannel) === 'takeaway')>Bawa Pulang (Takeaway)</option>
                                <option value="delivery" @selected(old('default_channel', $defaultChannel) === 'delivery')>Pesan Antar (Delivery)</option>
                            </select>
                        </div>
                    </div>

                    {{-- Alamat Cabang --}}
                    <div>
                        <label for="store_address" class="block text-sm font-semibold text-slate-900">
                            Alamat Cabang <span class="text-xs font-normal text-slate-500">(Dicetak pada struk — Opsional)</span>
                        </label>
                        <div class="mt-1.5">
                            <textarea name="store_address" id="store_address" rows="2"
                                class="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-slate-900 placeholder:text-slate-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 text-sm shadow-sm transition-all"
                                placeholder="Jl. Kemang Raya No. 10, Jakarta Selatan">{{ old('store_address', $storeAddress) }}</textarea>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="pt-4 flex items-center justify-between gap-4">
                        <a href="{{ route('onboarding.business-profile') }}"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 hover:text-slate-900 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                            <span>Kembali</span>
                        </a>

                        <button type="submit"
                            class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 transition-all cursor-pointer">
                            <span>Selesaikan & Masuk ke Dashboard POS</span>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
