@extends('layouts.guest')

@section('content')
    @if ($errors->any())
        <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl relative text-sm error-alert"
            role="alert" aria-live="assertive">
            <strong class="font-medium mb-1 block">{{ __('Autentikasi Gagal') }}</strong>
            <span class="block">{{ __('Periksa kembali email dan kata sandi Anda.') }}</span>
        </div>
    @endif

    <div class="mb-8 js-anim flex flex-col items-start" id="anim-heading">
        <!-- Bagian Logo & Teks Apeiron POS (Atas, rata kiri) -->
        <div class="flex items-end gap-2 mb-6">
            <img src="{{ asset('assets/images/logo.png') }}" alt="Apeiron POS Logo" class="h-16 w-auto object-contain">
            <span class="text-lg tracking-tight font-brand mb-1">
                <span class="font-bold">Apeiron POS</span>
            </span>
        </div>
        
        <!-- Bagian Teks Sapaan (Bawah) -->
        <div>
            <h1 class="text-2xl text-apeiron-ink tracking-tight">{{ __('Halo,') }} <span
                    class="font-semibold">{{ __('Selamat Datang Kembali!') }}</span></h1>
            <p class="text-[#787774] text-sm mt-2">{{ __('Masuk untuk mengelola dashboard Anda.') }}</p>
        </div>
    </div>

    <!-- SSO Buttons -->
    <div class="flex items-center gap-3 mb-6 js-anim" id="anim-social">
        <button type="button" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-white border border-[#E9E9E7] rounded-xl text-sm font-medium text-apeiron-ink hover:bg-[#F7F7F5] focus:outline-none focus:ring-2 focus:ring-[#37352F] transition-all duration-200 shadow-sm active:scale-[0.98]">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.38005-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Google
        </button>
        
        <button type="button" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-white border border-[#E9E9E7] rounded-xl text-sm font-medium text-apeiron-ink hover:bg-[#F7F7F5] focus:outline-none focus:ring-2 focus:ring-[#37352F] transition-all duration-200 shadow-sm active:scale-[0.98]">
            <svg width="18" height="18" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/>
            </svg>
            Apple
        </button>
    </div>

    <!-- Divider -->
    <div class="flex items-center gap-4 mb-6 js-anim" id="anim-divider">
        <hr class="w-full border-[#E9E9E7]">
        <span class="text-xs text-[#9B9A97] whitespace-nowrap">{{ __('atau') }}</span>
        <hr class="w-full border-[#E9E9E7]">
    </div>

    <form action="{{ route('login') }}" method="post" class="space-y-4" x-data="loginForm()"
        @submit.prevent="handleSubmit($el)" id="login-form">
        @csrf

        {{-- Email --}}
        <div class="js-anim" id="anim-field-email">
            <label for="email" class="block text-sm font-medium mb-1.5 text-apeiron-ink"
                :class="hasError ? 'text-rose-600' : ''">{{ __('Email') }}</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}" autocomplete="email"
                class="block w-full px-3.5 py-2.5 border border-[#E9E9E7] rounded-xl leading-5 bg-[#F7F7F5] placeholder-[#9B9A97] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink text-sm transition-all duration-200 {{ $errors->has('email') ? 'border-rose-300 ring-1 ring-rose-300' : '' }}"
                placeholder="{{ __('Masukkan email anda') }}">
        </div>

        {{-- Password + Toggle --}}
        <div class="js-anim" id="anim-field-password" x-data="{ show: false }">
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="block text-sm font-medium text-apeiron-ink">{{ __('Kata Sandi') }}</label>
                <button type="button" disabled class="text-sm font-medium text-[#C4C3C0] cursor-not-allowed"
                    aria-disabled="true">{{ __('Lupa Password?') }}</button>
            </div>
            <div class="relative">
                <input :type="show ? 'text' : 'password'" name="password" id="password" required
                    autocomplete="current-password"
                    class="block w-full pl-3.5 pr-10 py-2.5 border border-[#E9E9E7] rounded-xl leading-5 bg-[#F7F7F5] placeholder-[#9B9A97] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink text-sm transition-all duration-200"
                    placeholder="••••••••">

                <button type="button" @click="show = !show"
                    class="absolute inset-y-0 right-3 flex items-center text-[#9B9A97] hover:text-apeiron-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-[#37352F] rounded transition-colors duration-200"
                    :aria-label="show ? '{{ __('Sembunyikan kata sandi') }}' : '{{ __('Tampilkan kata sandi') }}'">
                    <span class="eye-icon-wrapper" aria-hidden="true">
                        <svg :class="show ? 'eye-icon hidden' : 'eye-icon visible'" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg :class="show ? 'eye-icon visible' : 'eye-icon hidden'" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                        </svg>
                    </span>
                </button>
            </div>
        </div>

        <div class="pt-2 js-anim" id="anim-submit">
            <button type="submit" id="btn-masuk"
                class="w-full flex justify-center items-center gap-2 bg-apeiron-ink text-white text-sm font-medium py-3 px-5 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:ring-offset-2 hover:bg-apeiron-ink transition-all duration-200 active:scale-[0.98] shadow-sm"
                :disabled="isLoading" :class="{ 'opacity-70 cursor-not-allowed': isLoading }" :aria-busy="isLoading">
                <span class="spinner" x-show="isLoading" x-cloak aria-hidden="true"></span>
                <span x-text="isLoading ? '{{ __('Memproses...') }}' : '{{ __('Masuk') }}'"></span>
            </button>
        </div>
    </form>

    <!-- Footer -->
    <div class="mt-6 text-center js-anim text-sm text-[#787774]" id="anim-footer-link">
        {{ __('Belum punya akun?') }} <button type="button" disabled
            class="font-medium text-[#C4C3C0] cursor-not-allowed ml-1">{{ __('Daftar') }}</button>
    </div>

    <div class="mt-8 text-center js-anim" id="anim-copyright">
        <p class="text-xs text-[#C4C3C0]">Ilham Sepriyadi &copy; {{ date('Y') }}</p>
    </div>
@endsection

@section('panel')
    <div class="hidden lg:flex flex-col justify-between p-12 text-white bg-cover bg-center relative overflow-hidden bg-apeiron-ink"
        style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');" id="anim-panel-right">
        <div class="absolute inset-0 bg-apeiron-ink/70 mix-blend-multiply"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-[#37352F]/90 via-transparent to-transparent"></div>



        <div class="relative z-10 mt-auto">
            <div class="mb-8 js-anim min-h-40" id="anim-testimonial">
                <p class="text-2xl font-medium tracking-tight text-white/90 leading-snug mb-5 max-w-lg">
                    "Dashboard baru memberikan kinerja ekstrem dengan visual memukau."
                </p>
                <div class="flex items-center gap-3">
                    <div
                        class="h-10 w-10 rounded-full bg-white/10 flex items-center justify-center border border-white/20 overflow-hidden backdrop-blur-sm">
                        <span class="text-white font-medium">A</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">Pengguna Admin</p>
                        <p class="text-xs text-white/50 mt-0.5">Owner / Manager</p>
                    </div>
                </div>
            </div>
        </div>
    @endsection

    @section('scripts')
        <script>
            function loginForm() {
                return {
                    isLoading: false,
                    get hasError() {
                        return document.querySelector('.input-error') !== null || document.querySelector('.error-alert') !==
                            null;
                    },
                    handleSubmit(formEl) {
                        if (this.isLoading) return;
                        this.isLoading = true;
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
            document.addEventListener('DOMContentLoaded', () => {
                const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const allAnimElems = document.querySelectorAll('.js-anim');

                if (prefersReduced) {
                    gsap.set(allAnimElems, {
                        opacity: 1,
                        y: 0,
                        x: 0
                    });
                    return;
                }

                const MT = {
                    dur: {
                        micro: 0.13,
                        fast: 0.22,
                        medium: 0.38,
                        stagger: 0.05,
                    },
                    ease: {
                        out: 'power3.out',
                        ui: 'power2.inOut',
                        panel: 'power2.out',
                    }
                };

                gsap.set(allAnimElems, {
                    opacity: 0,
                    y: 16
                });

                const panelRight = document.getElementById('anim-panel-right');
                if (panelRight) {
                    gsap.set(panelRight, {
                        opacity: 0,
                        x: 24,
                        y: 0
                    });
                }

                const tl = gsap.timeline({
                    defaults: {
                        ease: MT.ease.out
                    }
                });

                if (panelRight) {
                    tl.to(panelRight, {
                        opacity: 1,
                        x: 0,
                        duration: MT.dur.medium + 0.07,
                        ease: MT.ease.panel,
                    }, 0);
                }

                const brandLogo = document.getElementById('anim-brand-logo');
                if (brandLogo) {
                    tl.to(brandLogo, {
                        opacity: 1,
                        y: 0,
                        duration: MT.dur.medium,
                    }, 0.15);
                }

                const heading = document.getElementById('anim-heading');
                if (heading) {
                    tl.to(heading, {
                        opacity: 1,
                        y: 0,
                        duration: MT.dur.medium,
                    }, 0.12);
                }

                const formGroupItems = [
                    document.getElementById('anim-social'),
                    document.getElementById('anim-divider'),
                    document.getElementById('anim-field-email'),
                    document.getElementById('anim-field-password'),
                ].filter(Boolean);

                if (formGroupItems.length > 0) {
                    tl.to(formGroupItems, {
                        opacity: 1,
                        y: 0,
                        duration: MT.dur.medium,
                        stagger: MT.dur.stagger,
                    }, 0.22);
                }

                const submitBtn = document.getElementById('anim-submit');
                if (submitBtn) {
                    tl.to(submitBtn, {
                        opacity: 1,
                        y: 0,
                        duration: MT.dur.medium,
                    }, 0.22 + (formGroupItems.length * MT.dur.stagger));
                }

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
                    }, '>-0.1');
                }

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

            });
        </script>
    @endsection
