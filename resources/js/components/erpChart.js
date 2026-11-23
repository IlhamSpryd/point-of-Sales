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
                charts.HeatmapChart,
                components.TitleComponent,
                components.TooltipComponent,
                components.GridComponent,
                components.LegendComponent,
                components.DataZoomComponent,
                components.ToolboxComponent,
                components.VisualMapComponent,
                renderers.CanvasRenderer,
            ]);
            echartsCore = core;
            return echartsCore;
        });
    }
    return echartsLoadPromise;
}

/**
 * Compact Indonesian Rupiah Formatter
 */
export function formatCompactIDR(val) {
    const num = Math.abs(Number(val) || 0);
    if (num >= 1000000000) return (num / 1000000000).toFixed(1).replace('.0', '') + ' M';
    if (num >= 1000000) return (num / 1000000).toFixed(1).replace('.0', '') + ' jt';
    if (num >= 1000) return (num / 1000).toFixed(0) + ' rb';
    return num.toLocaleString('id-ID');
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
 * 7. Theme-Adaptive 0-Reload: MutationObserver & apeiron:theme-changed listener untuk recoloring real-time.
 * 8. Compact IDR Axis formatting otomatis.
 */
export default function erpChart({ chartId, options = {} } = {}) {
    let chartInstance = null;
    let resizeObserver = null;
    let themeObserver = null;
    let unsubscribeLivewire = null;
    let handleThemeChange = null;
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
                        if (chartInstance && !isDisposed) {
                            chartInstance.resize();
                        }
                    });
                    resizeObserver.observe(this.$refs.container);
                }

                // Theme Change Reactivity tanpa reload halaman
                handleThemeChange = () => {
                    if (chartInstance && !isDisposed) {
                        const updatedThemedOptions = this.applyDesignTokens(this.chartOptions);
                        chartInstance.setOption(updatedThemedOptions, false);
                    }
                };

                if (typeof window !== 'undefined') {
                    window.addEventListener('apeiron:theme-changed', handleThemeChange);
                    window.addEventListener('theme-changed', handleThemeChange);

                    if (window.MutationObserver) {
                        themeObserver = new MutationObserver(() => {
                            handleThemeChange();
                        });
                        themeObserver.observe(document.documentElement, {
                            attributes: true,
                            attributeFilter: ['class'],
                        });
                    }
                }

                // Integrasi reaktif dengan event Livewire tanpa reload halaman
                if (typeof window !== 'undefined' && window.Livewire && chartId) {
                    if (typeof window.Livewire.on === 'function') {
                        const listenerResult = window.Livewire.on(`update-chart-${chartId}`, (payload) => {
                            if (chartInstance && payload) {
                                const newOptions = payload.options || payload;
                                this.chartOptions = newOptions;
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

            if (themeObserver) {
                themeObserver.disconnect();
                themeObserver = null;
            }

            if (typeof window !== 'undefined' && handleThemeChange) {
                window.removeEventListener('apeiron:theme-changed', handleThemeChange);
                window.removeEventListener('theme-changed', handleThemeChange);
                handleThemeChange = null;
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
            const isDark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
            const cs = typeof window !== 'undefined' && typeof document !== 'undefined' ? getComputedStyle(document.documentElement) : null;
            const getVal = (prop, fallback) => (cs ? cs.getPropertyValue(prop).trim() : '') || fallback;

            let primary = getVal('--chart-1', isDark ? '#ECECF0' : '#0D0D0D');
            let foreground = getVal('--popover-foreground', isDark ? '#ECECF0' : '#0D0D0D');
            let muted = getVal('--chart-axis-label', isDark ? '#B8B8C2' : '#4A4A52');
            let border = getVal('--border-strong', isDark ? 'rgba(255, 255, 255, 0.22)' : '#C8C8D0');
            let splitLine = getVal('--chart-grid', isDark ? 'rgba(255, 255, 255, 0.08)' : '#E8E8EC');
            let popoverBg = getVal('--popover', isDark ? '#2B2B31' : '#FFFFFF');
            let accent = getVal('--chart-accent', isDark ? '#5EEAD4' : '#0F766E');

            const merged = { ...baseOptions };

            merged.textStyle = {
                fontFamily: '"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif',
                ...(merged.textStyle || {}),
            };

            merged.grid = {
                top: 28,
                right: 20,
                bottom: 28,
                left: 20,
                containLabel: true,
                ...(merged.grid || {}),
            };

            // Tooltip standard dengan IDR formatter
            merged.tooltip = {
                trigger: 'axis',
                backgroundColor: popoverBg,
                borderColor: border,
                borderWidth: 1,
                textStyle: {
                    color: foreground,
                    fontSize: 12,
                    fontFamily: '"Plus Jakarta Sans", sans-serif',
                },
                extraCssText: isDark
                    ? 'box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.5); border-radius: 8px;'
                    : 'box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08); border-radius: 8px;',
                valueFormatter: (val) => {
                    if (typeof val === 'number') {
                        return 'Rp ' + Number(val).toLocaleString('id-ID');
                    }
                    return val;
                },
                ...(merged.tooltip || {}),
            };

            // Adaptasi xAxis
            if (merged.xAxis) {
                const processXAxis = (ax) => ({
                    ...ax,
                    axisLine: {
                        lineStyle: { color: border },
                        ...(ax.axisLine || {}),
                    },
                    axisLabel: {
                        color: muted,
                        fontFamily: '"Plus Jakarta Sans", sans-serif',
                        fontSize: 11,
                        ...(ax.axisLabel || {}),
                    },
                });

                if (Array.isArray(merged.xAxis)) {
                    merged.xAxis = merged.xAxis.map(processXAxis);
                } else {
                    merged.xAxis = processXAxis(merged.xAxis);
                }
            }

            // Adaptasi yAxis dengan Compact IDR
            if (merged.yAxis) {
                const processYAxis = (ay) => ({
                    ...ay,
                    splitLine: {
                        lineStyle: {
                            color: splitLine,
                            type: 'dashed',
                        },
                        ...(ay.splitLine || {}),
                    },
                    axisLabel: {
                        color: muted,
                        fontFamily: '"Plus Jakarta Sans", sans-serif',
                        fontSize: 11,
                        formatter: (val) => {
                            if (typeof val === 'number' && Math.abs(val) >= 1000) {
                                return 'Rp ' + formatCompactIDR(val);
                            }
                            return val;
                        },
                        ...(ay.axisLabel || {}),
                    },
                });

                if (Array.isArray(merged.yAxis)) {
                    merged.yAxis = merged.yAxis.map(processYAxis);
                } else {
                    merged.yAxis = processYAxis(merged.yAxis);
                }
            }

            // Adaptasi Series (Line/Bar)
            if (Array.isArray(merged.series)) {
                merged.series = merged.series.map((s) => {
                    const seriesCopy = { ...s };
                    const seriesColor = isDark ? accent : primary;

                    if (!seriesCopy.itemStyle || seriesCopy.itemStyle.color === '#18181B' || seriesCopy.itemStyle.color === '#09090B') {
                        seriesCopy.itemStyle = {
                            ...(seriesCopy.itemStyle || {}),
                            color: seriesColor,
                        };
                    }

                    if (seriesCopy.type === 'line' && (seriesCopy.areaStyle || !s.hasOwnProperty('areaStyle'))) {
                        seriesCopy.areaStyle = {
                            color: {
                                type: 'linear',
                                x: 0,
                                y: 0,
                                x2: 0,
                                y2: 1,
                                colorStops: isDark
                                    ? [
                                          { offset: 0, color: 'rgba(94, 234, 212, 0.22)' },
                                          { offset: 1, color: 'rgba(94, 234, 212, 0.00)' },
                                      ]
                                    : [
                                          { offset: 0, color: 'rgba(15, 118, 110, 0.16)' },
                                          { offset: 1, color: 'rgba(15, 118, 110, 0.00)' },
                                      ],
                            },
                        };
                    }
                    return seriesCopy;
                });
            }

            return merged;
        }
    };
}
