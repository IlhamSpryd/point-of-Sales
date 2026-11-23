import focus from '@alpinejs/focus';
import Swal from './vendor/swal-bridge';
import commandPalette from './components/commandPalette';
import { erpMotion, initErpMotion, registerGsapAlpineDirectives } from './motion/erp-motion';
import { initSidebarPersistence } from './navigation/sidebar-persistence';
import dashboardChart from './components/dashboardCharts';
import './echo';

/**
 * Dynamic proxy component for erpChart.
 * Only loads ./components/erpChart (and Apache ECharts) asynchronously when an
 * x-data="erpChart(...)" component is mounted.
 */
function erpChart(...args) {
    let instance = null;
    let destroyed = false;

    return {
        chartOptions: args[0]?.options || {},
        loading: true,

        init() {
            import('./components/erpChart')
                .then(({ default: factory }) => {
                    if (destroyed) return;
                    instance = factory(...args);
                    Object.assign(this, instance);
                    if (typeof instance.init === 'function') {
                        instance.init.call(this);
                    }
                })
                .catch((err) => {
                    console.error('[erpChart] Dynamic component import failed:', err);
                });
        },

        destroy() {
            destroyed = true;
            if (instance && typeof instance.destroy === 'function') {
                instance.destroy.call(this);
                instance = null;
            }
        },
    };
}

/**
 * Dynamic proxy component for dashboardEcharts.
 * Only loads ./components/dashboardEcharts (and Apache ECharts) asynchronously when an
 * x-data="dashboardEcharts(...)" component is mounted.
 */
function dashboardEcharts(...args) {
    let instance = null;
    let destroyed = false;

    return {
        type: args[0]?.type || 'revenue',
        loading: true,

        init() {
            import('./components/dashboardEcharts')
                .then(({ default: factory }) => {
                    if (destroyed) return;
                    instance = factory(...args);
                    Object.assign(this, instance);
                    if (typeof instance.init === 'function') {
                        instance.init.call(this);
                    }
                })
                .catch((err) => {
                    console.error('[dashboardEcharts] Dynamic component import failed:', err);
                });
        },

        destroy() {
            destroyed = true;
            if (instance && typeof instance.destroy === 'function') {
                instance.destroy.call(this);
                instance = null;
            }
        },
    };
}

if (typeof window !== 'undefined') {
    window.Swal = Swal;
    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;
    window.erpChart = erpChart;
    window.dashboardEcharts = dashboardEcharts;
    window.apeironCommandPalette = commandPalette;
    window.dashboardChart = dashboardChart;
    window.loadGsap = erpMotion.loadGsap;
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
        window.Alpine.data('dashboardEcharts', dashboardEcharts);
        window.Alpine.data('dashboardChart', dashboardChart);
        window.Alpine.data('apeironCommandPalette', commandPalette);
        registerGsapAlpineDirectives(window.Alpine);
    }
});
