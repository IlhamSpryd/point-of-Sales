let echartsCore = null;
let echartsLoadPromise = null;

/**
 * Dynamic loader untuk Apache ECharts core dan modul chart.
 * Memastikan halaman tanpa visualisasi ECharts tidak menanggung overhead bundle synchronous.
 */
export async function loadEcharts() {
    if (echartsCore) {
        return echartsCore;
    }
    if (!echartsLoadPromise) {
        echartsLoadPromise = Promise.all([
            import('echarts/core'),
            import('echarts/charts'),
            import('echarts/components'),
            import('echarts/renderers'),
        ]).then(([core, charts, components, renderers]) => {
            core.use([
                charts.LineChart,
                charts.BarChart,
                charts.PieChart,
                components.TitleComponent,
                components.TooltipComponent,
                components.GridComponent,
                components.LegendComponent,
                components.DataZoomComponent,
                components.ToolboxComponent,
                renderers.CanvasRenderer,
            ]);
            echartsCore = core;
            return echartsCore;
        });
    }
    return echartsLoadPromise;
}

/**
 * Alpine.js v3 Data Component untuk Apache ECharts (Dynamic Lazy Loaded)
 *
 * Menerapkan:
 * 1. Zero-Proxy Canvas Optimization: let chartInstance = null disimpan di closure lokal.
 * 2. Standarisasi ref DOM: this.$refs.container.
 * 3. Lifecycle resmi Alpine.js v3: destroy() (bukan $cleanup).
 * 4. ResizeObserver pada elemen container (menyesuaikan saat sidebar toggles).
 * 5. Pembersihan listener Livewire pada teardown untuk mencegah memory leak.
 * 6. Dynamic code-splitting: ECharts dimuat asinkron saat mount.
 */
export default function erpChart({ chartId, options = {} } = {}) {
    let chartInstance = null;
    let resizeObserver = null;
    let unsubscribeLivewire = null;
    let isDisposed = false;

    return {
        chartOptions: options,
        loading: true,

        init() {
            this.$nextTick(() => {
                this.setupChart();
            });
        },

        async setupChart() {
            const container = this.$refs.container;
            if (!container) {
                return;
            }

            try {
                const echarts = await loadEcharts();
                if (isDisposed || !this.$refs.container) {
                    return;
                }

                // Inisialisasi ECharts murni tanpa dibungkus Proxy Alpine
                chartInstance = echarts.init(this.$refs.container, null, {
                    renderer: 'canvas',
                });

                const themedOptions = this.applyDesignTokens(this.chartOptions);
                chartInstance.setOption(themedOptions);
                this.loading = false;

                // Observasi perubahan ukuran kontainer (termasuk saat toggle sidebar navigasi)
                if (typeof window !== 'undefined' && window.ResizeObserver) {
                    resizeObserver = new ResizeObserver(() => {
                        if (chartInstance) {
                            chartInstance.resize();
                        }
                    });
                    resizeObserver.observe(this.$refs.container);
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
            } catch (err) {
                console.error('[erpChart] Failed to initialize dynamic chart:', err);
            }
        },

        destroy() {
            isDisposed = true;
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
