@extends('layouts.guest')

@section('content')
                {{-- ====================================================
                ERROR ALERT
                Animasi: CSS slide-down (350ms expo-out) + shake (280ms).
                GSAP tidak dipakai di sini karena animasi error bersifat
                CSS-triggered (ada/tidak ada dari server), bukan entrance.
                ==================================================== --}}
                @if ($errors->any())
                    <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl relative text-sm error-alert"
                        role="alert" aria-live="assertive">
                        <strong class="font-medium mb-1 block">{{ __('Autentikasi Gagal') }}</strong> <!-- Standarisasi bahasa UjiKom -->
                        <span class="block">{{ __('Periksa kembali email dan kata sandi Anda.') }}</span>
                    </div>
                @endif

                {{-- ====================================================
                JUDUL
                Kelas .js-anim → diset opacity:0 dari JS, lalu
                dianimasikan via timeline utama.
                ==================================================== --}}
                <div class="mb-8 js-anim" id="anim-heading">
                    <h1 class="text-2xl text-zinc-900 dark:text-zinc-100 tracking-tight">{{ __('Halo,') }} <span class="font-semibold">{{ __('Selamat Datang Kembali!') }}</span></h1>
                    <p class="text-zinc-500 dark:text-zinc-400 text-sm mt-2">{{ __('Masuk untuk mengelola dashboard Anda.') }}</p>
                </div>

                {{-- ====================================================
                SOCIAL LOGIN (Google & Apple) — DISABLED
                Entrance ikut group form, TANPA hover transform/shadow
                agar tidak menyesatkan user (tombol non-aktif).
                ==================================================== --}}
                <div class="grid grid-cols-2 gap-3 mb-6 js-anim" id="anim-social">
                    <button type="button"
                        class="btn-social flex items-center justify-center gap-2 border border-zinc-200 dark:border-zinc-700 rounded-xl py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800 opacity-80"
                        title="{{ __('Segera Hadir') }}" disabled aria-disabled="true"
                        aria-label="{{ __('Masuk dengan Google — Segera Hadir') }}">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#4285F4"
                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                            <path fill="#34A853"
                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                            <path fill="#FBBC05"
                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                            <path fill="#EA4335"
                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                        </svg>
                        <span class="text-sm font-medium">Google</span>
                    </button>
                    <button type="button"
                        class="btn-social flex items-center justify-center gap-2 border border-zinc-200 dark:border-zinc-700 rounded-xl py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800 opacity-80"
                        title="{{ __('Segera Hadir') }}" disabled aria-disabled="true"
                        aria-label="{{ __('Masuk dengan Apple — Segera Hadir') }}">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path
                                d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.09 2.31-.83 3.68-.79 1.15.04 2.22.48 2.97 1.25-2.45 1.48-2.05 4.54.49 5.48-.56 1.76-1.57 3.65-3.22 6.23zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2 4.43-3.74 4.25z" />
                        </svg>
                        <span class="text-sm font-medium">Apple</span>
                    </button>
                </div>

                <!-- Divider -->
                <div class="flex items-center gap-3 mb-6 js-anim" id="anim-divider">
                    <hr class="flex-1 border-t border-zinc-100 dark:border-zinc-700">
                    <span class="text-xs text-zinc-400 font-medium tracking-wide uppercase">{{ __('Atau Lanjutkan Dengan') }}</span>
                    <hr class="flex-1 border-t border-zinc-100 dark:border-zinc-700">
                </div>

                {{-- ====================================================
                FORM — @submit dihandle Alpine untuk loading state.
                route, @csrf, dan logic @error TIDAK diubah.
                ==================================================== --}}
                <form action="{{ route('login') }}" method="post" class="space-y-4" x-data="loginForm()"
                    @submit.prevent="handleSubmit($el)" id="login-form">
                    @csrf

                    {{-- Email --}}
                    <div class="js-anim" id="anim-field-email">
                        <label for="email" class="block text-sm font-medium mb-1.5 transition-colors"
                            :class="hasError ? 'label-error' : 'text-zinc-700 dark:text-zinc-300'">{{ __('Email') }}</label>
                        <input type="email" name="email" id="email" required value="{{ old('email') }}" autocomplete="email"
                            class="form-input block w-full px-3 py-2.5 border rounded-xl leading-5 bg-zinc-50 dark:bg-zinc-800 placeholder-zinc-500 focus:bg-white dark:focus:bg-zinc-900 focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:border-zinc-900 text-[15px] {{ $errors->has('email') ? 'input-error' : '' }}"
                            :class="hasError ? 'input-error' : 'border-zinc-100 dark:border-zinc-700'" placeholder="{{ __('Masukkan email anda') }}">
                    </div>

                    {{-- Password + Toggle --}}
                    <div class="js-anim" id="anim-field-password" x-data="{ show: false }">
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Kata Sandi') }}</label>
                            <button type="button" disabled class="text-sm font-medium text-zinc-400 cursor-not-allowed" aria-disabled="true"
                                style="transition: color var(--dur-fast) var(--ease-ui);">{{ __('Lupa Password?') }}</button>
                        </div>
                        <div class="relative">
                            <input :type="show ? 'text' : 'password'" name="password" id="password" required autocomplete="current-password"
                                class="form-input block w-full pl-3 pr-10 py-2.5 border border-zinc-100 dark:border-zinc-700 rounded-xl leading-5 bg-zinc-50 dark:bg-zinc-800 placeholder-zinc-500 focus:bg-white dark:focus:bg-zinc-900 focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:border-zinc-900 text-[15px]"
                                placeholder="••••••••">

                            {{-- ============================================
                            TOGGLE PASSWORD — Cross-fade + scale
                            Dibuat dua ikon tumpuk di dalam .eye-icon-wrapper.
                            State visible/hidden dikontrol Alpine via :class.
                            CSS menangani transisi opacity + scale (micro: 120ms).
                            ============================================ --}}
                            <button type="button" @click="show = !show"
                                class="absolute inset-y-0 right-3 flex items-center text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
                                :aria-label="show ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'"
                                style="transition: color var(--dur-micro) var(--ease-ui);">
                                <span class="eye-icon-wrapper" aria-hidden="true">
                                    {{-- Ikon mata terbuka --}}
                                    <svg :class="show ? 'eye-icon hidden' : 'eye-icon visible'" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    {{-- Ikon mata tertutup --}}
                                    <svg :class="show ? 'eye-icon visible' : 'eye-icon hidden'" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                    </svg>
                                </span>
                            </button>
                        </div>
                    </div>

                    {{-- ====================================================
                    TOMBOL SUBMIT
                    - Alpine mengontrol loading state (disabled + spinner).
                    - CSS .btn-submit mengatur hover/active micro-interaction.
                    - Loading: teks berubah "Memproses...", spinner muncul.
                    ==================================================== --}}
                    <div class="pt-2 js-anim" id="anim-submit">
                        <button type="submit" id="btn-masuk"
                            class="btn-submit w-full flex justify-center items-center gap-2 bg-black text-white text-[15px] font-medium py-2.5 px-5 rounded-xl focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:ring-offset-2"
                            :disabled="isLoading" :class="{ 'is-loading': isLoading }" :aria-busy="isLoading">
                            <span class="spinner" x-show="isLoading" x-cloak aria-hidden="true"></span>
                            <span x-text="isLoading ? '{{ __('Memproses...') }}' : '{{ __('Masuk') }}'"></span>
                        </button>
                    </div>
                </form>

                <!-- Footer -->
                <div class="mt-6 text-center js-anim text-sm text-zinc-500 dark:text-zinc-400" id="anim-footer-link">
                    {{ __('Belum punya akun?') }} <button type="button" disabled
                        class="font-medium text-zinc-400 cursor-not-allowed ml-1">{{ __('Daftar') }}</button>
                </div>

                <div class="mt-8 text-center js-anim" id="anim-copyright">
                    <p class="text-[13px] text-zinc-400">Ilham Sepriyadi &copy; {{ date('Y') }}</p>
                </div>

@endsection

@section('panel')
        <!--
             KANAN: PANEL VISUAL
             hidden lg:flex dipertahankan — responsive mobile tidak berubah.
              -->
        <div class="hidden lg:flex flex-col justify-between p-12 text-white bg-cover bg-center relative overflow-hidden bg-zinc-900"
            style="background-image: url('https://images.unsplash.com/photo-1555421689-491a97ff2040?auto=format&fit=crop&q=80');" id="anim-panel-right">
            <!-- Overlay gelap untuk menjaga kontras teks -->
            <div class="absolute inset-0 bg-zinc-900/70 mix-blend-multiply"></div>
            <div class="absolute inset-0 bg-linear-to-t from-zinc-900/90 via-transparent to-transparent"></div>

            <!-- Logo / Brand -->
            <div class="flex items-center gap-3 relative z-10 js-anim" id="anim-brand-logo">
                <div class="relative flex h-8 w-12 items-center justify-center">
                    <div
                        class="absolute left-0 h-8 w-8 rounded-full border-2 border-transparent bg-white/20 dark:bg-zinc-900/20 mix-blend-overlay">
                    </div>
                    <div
                        class="absolute right-0 h-8 w-8 rounded-full border-2 border-transparent bg-white/40 dark:bg-zinc-900/40 mix-blend-overlay">
                    </div>
                </div>
                <span class="text-xl font-bold tracking-tight">{{ config('app.name', 'Spark Admin') }}</span>
            </div>
            
            <div class="relative z-10 mt-auto">
                <div class="mb-8 js-anim min-h-40" id="anim-testimonial">
                    <p class="text-2xl font-medium tracking-tight text-white/90 leading-snug mb-5 max-w-lg">
                        "Dashboard Spark Admin baru memberikan kinerja ekstrem dengan visual memukau." <!-- Standarisasi bahasa UjiKom -->
                    </p>
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-full bg-white/10 dark:bg-zinc-900/10 flex items-center justify-center border border-white/20 overflow-hidden backdrop-blur-sm">
                            <span class="text-white font-medium">A</span>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-white">Pengguna Admin</p> <!-- Standarisasi bahasa UjiKom -->
                            <p class="text-xs text-white/60 mt-0.5">Administrator Sistem</p> <!-- Standarisasi bahasa UjiKom -->
                        </div>
                    </div>
                </div>
            </div>
@endsection

@section('scripts')
    {{--
    ALPINE COMPONENT
    Diletakkan sebelum penutup body agar tersedia sebelum Alpine
    menginisialisasi komponen yang referensikan `loginForm()`.
    --}}
    <script>
        /**
         * loginForm — Alpine component untuk form login.
         *
         * Fungsi:
         * 1. Mendeteksi error dari server (untuk binding label/border merah).
         * 2. Menangani loading state saat submit:
         *    - Disable tombol + tampilkan spinner.
         *    - Ubah teks jadi "Memproses...".
         *    - Cegah double-submit.
         *    - Submit form asli ke Laravel setelah state diset.
         */
        function loginForm() {
            return {
                isLoading: false,

                // Deteksi error dari Laravel (ada class input-error di DOM)
                get hasError() {
                    return document.querySelector('.input-error') !== null;
                },

                handleSubmit(formEl) {
                    if (this.isLoading) return; // cegah double-submit

                    this.isLoading = true;

                    // Submit native setelah satu frame agar Alpine render dulu
                    requestAnimationFrame(() => {
                        formEl.submit();
                    });
                }
            };
        }

        function testimonialCarousel(data) {
            return {
                testimonials: data,
                currentIndex: 0,
                intervalId: null,
                init() {
                    if (this.testimonials.length > 1) {
                        this.startAutoPlay();
                    }
                },
                startAutoPlay() {
                    this.intervalId = setInterval(() => {
                        this.next();
                    }, 5000);
                },
                stopAutoPlay() {
                    if (this.intervalId) {
                        clearInterval(this.intervalId);
                    }
                },
                next() {
                    this.currentIndex = (this.currentIndex + 1) % this.testimonials.length;
                },
                goTo(index) {
                    this.currentIndex = index;
                    this.stopAutoPlay();
                    this.startAutoPlay();
                }
            };
        }
    </script>

    <script>
        /**
         *
         * MOTION SYSTEM — GSAP TIMELINE
         *
         *
         * Semua token durasi/easing didefinisikan di `MT` (Motion Tokens)
         * sebagai single source of truth. Hindari hardcode di tiap tween.
         *
         * Urutan entrance (sesuai spesifikasi):
         *   1. Panel kanan (background + overlay)
         *   2. Logo brand
         *   3. Judul (heading)
         *   4. Form fields (stagger 50ms)
         *   5. Tombol submit
         *   6. Footer / link daftar
         *
         * Total durasi sequence: ~1.0–1.1 detik (di bawah maksimal 1.2s).
         * Animasi TIDAK memblokir interaktivitas (pointer-events tetap aktif).
         *
         */
        document.addEventListener('DOMContentLoaded', () => {

            /* ----------------------------------------------------------
             * REDUCED MOTION CHECK
             * Jika user mengaktifkan prefers-reduced-motion, batalkan semua
             * timeline GSAP dan langsung tampilkan semua elemen.
             * ---------------------------------------------------------- */
            const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const allAnimElems = document.querySelectorAll('.js-anim');

            if (prefersReduced) {
                // Tampilkan semua elemen sekaligus, tanpa animasi
                gsap.set(allAnimElems, { opacity: 1, y: 0, x: 0 });
                return;
            }

            /* ----------------------------------------------------------
             * MOTION TOKENS — single source of truth untuk JS
             * Cermin dari CSS custom properties di :root.
             * ---------------------------------------------------------- */
            const MT = {
                dur: {
                    micro: 0.13,  // 130ms — hover/toggle ikon
                    fast: 0.22,  // 220ms — focus, border error
                    medium: 0.38,  // 380ms — entrance individual
                    stagger: 0.05, // 50ms antar item form
                },
                ease: {
                    out: 'power3.out',           // entrance elemen
                    ui: 'power2.inOut',          // state-change UI
                    panel: 'power2.out',            // panel overlay besar
                }
            };

            /* ----------------------------------------------------------
             * SET INITIAL STATE
             * Semua .js-anim di-set invisible SEBELUM timeline mulai,
             * mencegah FOUC (flash of unstyled content).
             * ---------------------------------------------------------- */
            gsap.set(allAnimElems, { opacity: 0, y: 16 });

            // Panel kanan: masuk dari kanan (x), bukan y
            const panelRight = document.getElementById('anim-panel-right');
            if (panelRight) {
                gsap.set(panelRight, { opacity: 0, x: 24, y: 0 });
            }

            /* ----------------------------------------------------------
             * MAIN TIMELINE
             * addLabel() dipakai untuk menandai checkpoint, memudahkan
             * maintenance di masa depan (cukup ubah label, bukan offset).
             * ---------------------------------------------------------- */
            const tl = gsap.timeline({ defaults: { ease: MT.ease.out } });

            // 1. Panel kanan — masuk dari kanan, durasi medium
            if (panelRight) {
                tl.to(panelRight, {
                    opacity: 1,
                    x: 0,
                    duration: MT.dur.medium + 0.07, // sedikit lebih lambat → terasa berat/substansial
                    ease: MT.ease.panel,
                }, 0);
            }

            // 2. Logo brand (di dalam panel kanan)
            const brandLogo = document.getElementById('anim-brand-logo');
            if (brandLogo) {
                tl.to(brandLogo, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.medium,
                }, 0.15); // mulai sedikit setelah panel
            }

            // 3. Judul / heading
            const heading = document.getElementById('anim-heading');
            if (heading) {
                tl.to(heading, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.medium,
                }, 0.12); // mulai hampir bersamaan, sedikit setelah panel
            }

            // 4. Form fields — stagger group
            //    Social buttons, divider, email field, password field
            //    masuk berurutan dengan jeda 50ms.
            const formGroupItems = [
                document.getElementById('anim-social'),
                document.getElementById('anim-divider'),
                document.getElementById('anim-field-email'),
                document.getElementById('anim-field-password'),
            ].filter(Boolean); // filter null (jika elemen tidak ada di DOM)

            if (formGroupItems.length > 0) {
                tl.to(formGroupItems, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.medium,
                    stagger: MT.dur.stagger, // 50ms antar item
                }, 0.22);
            }

            // 5. Tombol submit
            const submitBtn = document.getElementById('anim-submit');
            if (submitBtn) {
                tl.to(submitBtn, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.medium,
                }, 0.22 + (formGroupItems.length * MT.dur.stagger)); // setelah form fields selesai
            }

            // 6. Footer link + copyright
            const footerElems = [
                document.getElementById('anim-footer-link'),
                document.getElementById('anim-copyright'),
            ].filter(Boolean);

            if (footerElems.length > 0) {
                tl.to(footerElems, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.fast,
                    stagger: MT.dur.stagger,
                }, '>-0.1'); // sedikit overlap dengan submit button
            }

            // 7. Testimonial + dots — sinkronisasi ke timeline utama
            const testimonial = document.getElementById('anim-testimonial');
            const dots = document.getElementById('anim-dots');

            if (testimonial) {
                tl.to(testimonial, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.medium,
                }, 0.35);
            }

            if (dots) {
                tl.to(dots, {
                    opacity: 1,
                    y: 0,
                    duration: MT.dur.fast,
                }, 0.50);
            }

        }); // end DOMContentLoaded
    </script>
@endsection
