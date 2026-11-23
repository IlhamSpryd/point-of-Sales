import focus from '@alpinejs/focus';
import Swal from './vendor/swal-bridge';
import commandPalette from './components/commandPalette';
import { erpMotion, initErpMotion, registerGsapAlpineDirectives } from './motion/erp-motion';
import { initSidebarPersistence } from './navigation/sidebar-persistence';
import dashboardChart from './components/dashboardCharts';
import dashboardEcharts from './components/dashboardEcharts';
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
 * Canonical Apeiron Design System Theme Controller (Directive 2)
 * Zero-FOUC, persistent across Livewire wire:navigate, synchronized with cookie & localStorage.
 */
const ApeironTheme = {
    getTheme() {
        const saved = localStorage.getItem('apeiron-theme');
        if (saved === 'dark' || saved === 'light') return saved;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    },
    isDark() {
        return this.getTheme() === 'dark';
    },
    setTheme(theme) {
        const isDark = theme === 'dark';
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
        try {
            localStorage.setItem('apeiron-theme', isDark ? 'dark' : 'light');
            localStorage.removeItem('theme');
            localStorage.removeItem('color-theme');
            document.cookie = 'apeiron_theme=' + (isDark ? 'dark' : 'light') + '; path=/; max-age=31536000; SameSite=Lax';
        } catch (_) {}
        window.dispatchEvent(new CustomEvent('apeiron:theme-changed', { detail: { theme: isDark ? 'dark' : 'light', isDark } }));
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: isDark ? 'dark' : 'light', isDark } }));
    },
    toggle() {
        this.setTheme(this.isDark() ? 'light' : 'dark');
    },
    sync() {
        const isDark = this.isDark();
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    }
};

if (typeof window !== 'undefined') {
    window.Swal = Swal;
    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;
    window.ApeironTheme = ApeironTheme;
    window.erpChart = erpChart;
    window.dashboardEcharts = dashboardEcharts;
    window.apeironCommandPalette = commandPalette;
    window.dashboardChart = dashboardChart;
    window.loadGsap = erpMotion.loadGsap;

    document.addEventListener('livewire:navigating', () => window.ApeironTheme.sync());
    document.addEventListener('livewire:navigate', () => window.ApeironTheme.sync());
    document.addEventListener('livewire:navigated', () => window.ApeironTheme.sync());
    window.addEventListener('storage', (e) => {
        if (e.key === 'apeiron-theme') window.ApeironTheme.sync();
    });
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
