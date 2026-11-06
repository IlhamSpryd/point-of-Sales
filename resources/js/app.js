import focus from '@alpinejs/focus';
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
    if (window.Alpine) {
        try {
            window.Alpine.plugin(focus);
        } catch (_) {
            // Plugin already registered
        }
        registerGsapAlpineDirectives(window.Alpine);
    }
});
