import Alpine from 'alpinejs';
import gsap from 'gsap';
import Swal from './vendor/swal-bridge';
import { erpMotion, initErpMotion, registerGsapAlpineDirectives } from './motion/erp-motion';
import { initSidebarPersistence } from './navigation/sidebar-persistence';

if (typeof window !== 'undefined') {
    window.gsap = gsap;
    window.Swal = Swal;
    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;
}

initErpMotion();
initSidebarPersistence();

document.addEventListener('alpine:init', () => {
    // Livewire 4 sudah membundel & mem-boot Alpine sendiri. Daftarkan direktif
    // kami ke instance yang BENAR (window.Alpine), bukan instance import lokal.
    registerGsapAlpineDirectives(window.Alpine ?? Alpine);
});

document.addEventListener('DOMContentLoaded', () => {
    // Fallback: hanya boot Alpine sendiri bila Livewire (yang menyediakannya) absen.
    if (typeof window.Livewire === 'undefined' && !window.Alpine) {
        window.Alpine = Alpine;
        registerGsapAlpineDirectives(Alpine);
        Alpine.start();
    }
});
