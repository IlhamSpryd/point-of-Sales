import * as echarts from 'echarts/core';
import { LineChart, BarChart, PieChart } from 'echarts/charts';
import {
    TitleComponent,
    TooltipComponent,
    GridComponent,
    LegendComponent,
    DataZoomComponent,
    ToolboxComponent
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

// Registrasi modular untuk meminimalkan ukuran bundle JS
echarts.use([
    LineChart,
    BarChart,
    PieChart,
    TitleComponent,
    TooltipComponent,
    GridComponent,
    LegendComponent,
    DataZoomComponent,
    ToolboxComponent,
    CanvasRenderer
]);

/**
 * Alpine.js v3 Data Component untuk Apache ECharts
 *
 * Menerapkan:
 * 1. Zero-Proxy Canvas Optimization: let chartInstance = null disimpan di closure lokal.
 * 2. Standarisasi ref DOM: this.$refs.container.
 * 3. Lifecycle resmi Alpine.js v3: destroy() (bukan $cleanup).
 * 4. ResizeObserver pada elemen container (menyesuaikan saat sidebar toggles).
 * 5. Pembersihan listener Livewire pada teardown untuk mencegah memory leak.
 */
export default function erpChart({ chartId, options = {} } = {}) {
    let chartInstance = null;
    let resizeObserver = null;
    let unsubscribeLivewire = null;

    return {
        chartOptions: options,

        init() {
            this.$nextTick(() => {
                this.setupChart();
            });
        },

        setupChart() {
            const container = this.$refs.container;
            if (!container) {
                return;
            }

            // Inisialisasi ECharts murni tanpa dibungkus Proxy Alpine
            chartInstance = echarts.init(container, null, {
                renderer: 'canvas'
            });

            const themedOptions = this.applyDesignTokens(this.chartOptions);
            chartInstance.setOption(themedOptions);

            // Observasi perubahan ukuran kontainer (termasuk saat toggle sidebar navigasi)
            if (typeof window !== 'undefined' && window.ResizeObserver) {
                resizeObserver = new ResizeObserver(() => {
                    if (chartInstance) {
                        chartInstance.resize();
                    }
                });
                resizeObserver.observe(container);
            }

            // Integrasi reaktif dengan event Livewire tanpa reload halaman
            if (typeof window !== 'undefined' && window.Livewire && chartId) {
                if (typeof window.Livewire.on === 'function') {
                    const listenerResult = window.Livewire.on(`update-chart-${chartId}`, (payload) => {
                        if (chartInstance && payload) {
                            const newOptions = payload.options || payload;
                            chartInstance.setOption(this.applyDesignTokens(newOptions), false);
                        }
                    });

                    // Livewire 3/4 mengembalikan fungsi unsubscribe
                    if (typeof listenerResult === 'function') {
                        unsubscribeLivewire = listenerResult;
                    }
                }
            }
        },

        destroy() {
            if (resizeObserver) {
                resizeObserver.disconnect();
                resizeObserver = null;
            }

            if (typeof unsubscribeLivewire === 'function') {
                unsubscribeLivewire();
                unsubscribeLivewire = null;
            }

            if (chartInstance) {
                chartInstance.dispose();
                chartInstance = null;
            }
        },

        applyDesignTokens(baseOptions = {}) {
            let primary = '#18181B';
            let foreground = '#09090B';
            let muted = '#71717A';
            let border = '#E4E4E7';
            let cardBg = '#FFFFFF';

            if (typeof window !== 'undefined' && document?.documentElement) {
                const styles = getComputedStyle(document.documentElement);
                primary = styles.getPropertyValue('--primary').trim() || primary;
                foreground = styles.getPropertyValue('--foreground').trim() || foreground;
                muted = styles.getPropertyValue('--muted-foreground').trim() || muted;
                border = styles.getPropertyValue('--border').trim() || border;
                cardBg = styles.getPropertyValue('--card').trim() || cardBg;
            }

            return {
                textStyle: {
                    fontFamily: '"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif'
                },
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: cardBg,
                    borderColor: border,
                    borderWidth: 1,
                    textStyle: {
                        color: foreground,
                        fontSize: 12
                    },
                    extraCssText: 'box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08); border-radius: 8px;'
                },
                grid: {
                    top: 36,
                    right: 20,
                    bottom: 30,
                    left: 20,
                    containLabel: true
                },
                ...baseOptions
            };
        }
    };
}
