import focus from '@alpinejs/focus';
import gsap from 'gsap';
import Swal from './vendor/swal-bridge';
import erpChart from './components/erpChart';
import { erpMotion, initErpMotion, registerGsapAlpineDirectives } from './motion/erp-motion';
import { initSidebarPersistence } from './navigation/sidebar-persistence';

if (typeof window !== 'undefined') {
    window.gsap = gsap;
    window.Swal = Swal;
    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;
    window.erpChart = erpChart;
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
        window.Alpine.data('erpChart', erpChart);
        registerGsapAlpineDirectives(window.Alpine);
    }
});
