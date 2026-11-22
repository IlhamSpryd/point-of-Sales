import focus from '@alpinejs/focus';
import gsap from 'gsap';
import Swal from './vendor/swal-bridge';
import erpChart from './components/erpChart';
import commandPalette from './components/commandPalette';
import { erpMotion, initErpMotion, registerGsapAlpineDirectives } from './motion/erp-motion';
import { initSidebarPersistence } from './navigation/sidebar-persistence';
import './echo';

if (typeof window !== 'undefined') {
    window.gsap = gsap;
    window.Swal = Swal;
    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;
    window.erpChart = erpChart;
    window.apeironCommandPalette = commandPalette;
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
        window.Alpine.data('apeironCommandPalette', commandPalette);
        registerGsapAlpineDirectives(window.Alpine);
    }
});
