import Alpine from 'alpinejs';
import gsap from 'gsap';
import Swal from './vendor/swal-bridge';
import { erpMotion, initErpMotion, registerGsapAlpineDirectives } from './motion/erp-motion';

if (typeof window !== 'undefined') {
    window.gsap = gsap;
    window.Swal = Swal;
    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;
}

initErpMotion();

document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.Livewire === 'undefined' && !window.Alpine) {
        window.Alpine = Alpine;
        registerGsapAlpineDirectives(Alpine);
        Alpine.start();
    }
});
