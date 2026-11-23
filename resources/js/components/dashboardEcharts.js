import { loadEcharts } from './erpChart';

/**
 * Apeiron Systems — Professional Apache ECharts Dashboard Suite
 * WCAG 2.2 Level AAA compliant, Plus Jakarta Sans typography,
 * Dark/Light auto-reactive, click-to-drilldown, and per-chart filtering.
 */
const activeDashboardCharts = new Set();

if (typeof window !== 'undefined') {
    const cleanupAllDashboardCharts = () => {
        activeDashboardCharts.forEach(chartComponent => {
            try {
                if (typeof chartComponent.destroy === 'function') {
                    chartComponent.destroy();
                }
            } catch (_) {}
        });
        activeDashboardCharts.clear();
    };

    document.addEventListener('livewire:navigating', cleanupAllDashboardCharts);
    window.addEventListener('pagehide', cleanupAllDashboardCharts);
}

function getEchartsThemeTokens() {
    const isDark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    return {
        isDark,
        fontFamily: '"Plus Jakarta Sans", sans-serif',
        textColor: isDark ? '#FAFAFA' : '#09090B',
        mutedTextColor: isDark ? '#B4B4BE' : '#45454D',
        gridLineColor: isDark ? 'rgba(255, 255, 255, 0.08)' : '#E4E4E7',
        splitLineColor: isDark ? 'rgba(255, 255, 255, 0.06)' : '#F4F4F5',
        borderColor: isDark ? 'rgba(255, 255, 255, 0.10)' : '#E4E4E7',
        tooltipBg: isDark ? '#1F1F25' : '#FFFFFF',
        tooltipBorder: isDark ? 'rgba(255, 255, 255, 0.15)' : '#E4E4E7',
        accentColor: isDark ? '#4ADE80' : '#10A37F',
        primaryColor: isDark ? '#FAFAFA' : '#18181B',
        priorColor: isDark ? '#71717A' : '#8E8E98',
        targetColor: isDark ? '#FCD34D' : '#D97706',
        cogsColor: isDark ? '#F87171' : '#DC2626',
        qrisColor: isDark ? '#38BDF8' : '#0284C7',
        cardColor: isDark ? '#A78BFA' : '#7C3AED',
        cashColor: isDark ? '#4ADE80' : '#16A34A',
        invoiceColor: isDark ? '#FBBF24' : '#D97706',
        heatmapZero: isDark ? '#16161A' : '#F7F7F8',
        heatmapMid: isDark ? '#1E3A5F' : '#BAE6FD',
        heatmapMax: isDark ? '#0284C7' : '#0369A1',
    };
}

function formatCompactIDR(val) {
    const num = Math.abs(Number(val) || 0);
    if (num >= 1000000000) return (num / 1000000000).toFixed(1).replace('.0', '') + ' M';
    if (num >= 1000000) return (num / 1000000).toFixed(1).replace('.0', '') + ' jt';
    if (num >= 1000) return (num / 1000).toFixed(0) + ' rb';
    return num.toLocaleString('id-ID');
}

export default function dashboardEcharts(config = {}) {
    let chartInstance = null;
    let resizeObserver = null;
    let themeObserver = null;
    let isDisposed = false;

    return {
        type: config.type || 'revenue',
        drilldownType: config.drilldownType || config.type,
        chartData: config.data || {},
        loading: false,
        granularity: config.granularity || 'daily',
        period: config.period || '30d',
        chartStyle: config.chartStyle || 'line', // 'line' | 'bar'
        metricType: config.metricType || 'transactions', // 'transactions' | 'revenue'
        activeSeries: config.activeSeries || { gross: false, net: true, prior: true, target: false },

        init() {
            activeDashboardCharts.add(this);
            this.$nextTick(() => {
                this.setupChart();
            });
        },

        async setupChart() {
            const container = this.$refs.chartContainer;
            if (!container) return;

            // Pastikan kontainer telah ter-render dan memiliki dimensi lebar/tinggi > 0
            if (container.clientWidth === 0 || container.clientHeight === 0) {
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            }
            if (isDisposed || !this.$refs.chartContainer) return;

            try {
                const echarts = await loadEcharts();
                if (isDisposed || !this.$refs.chartContainer) return;

                if (chartInstance) {
                    chartInstance.dispose();
                    chartInstance = null;
                }

                chartInstance = echarts.init(this.$refs.chartContainer, null, {
                    renderer: 'canvas',
                });

                this.renderCurrentChart();

                // Force double resize to guarantee canvas fills full container width and height
                requestAnimationFrame(() => {
                    if (chartInstance && !isDisposed) {
                        chartInstance.resize();
                    }
                });

                // Click to drilldown listener
                chartInstance.on('click', (params) => {
                    let drillType = this.drilldownType;
                    const detailPayload = {
                        drilldownType: drillType,
                        name: params.name,
                        value: params.value,
                        seriesName: params.seriesName,
                        dataIndex: params.dataIndex,
                        data: params.data,
                        period: this.period,
                        date: params.name,
                    };
                    if (this.type === 'stacked-bar' || drillType === 'payment-method') {
                        detailPayload.drilldownType = 'payment-method';
                        detailPayload.method = params.seriesName || params.name;
                    } else if (this.type === 'profitability') {
                        detailPayload.drilldownType = 'date';
                        detailPayload.date = params.name;
                    }
                    window.dispatchEvent(new CustomEvent('open-dashboard-drilldown', {
                        detail: detailPayload
                    }));
                });

                // Responsive ResizeObserver
                if (window.ResizeObserver) {
                    resizeObserver = new ResizeObserver(() => {
                        if (chartInstance && !isDisposed) {
                            chartInstance.resize();
                        }
                    });
                    resizeObserver.observe(this.$refs.chartContainer);
                }

                // Theme Change Reactivity
                themeObserver = new MutationObserver(() => {
                    if (chartInstance && !isDisposed) {
                        this.renderCurrentChart();
                    }
                });
                themeObserver.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });

                window.addEventListener('theme-changed', () => {
                    if (chartInstance && !isDisposed) {
                        this.renderCurrentChart();
                    }
                });
            } catch (err) {
                console.error('[dashboardEcharts] Setup failed:', err);
            }
        },

        renderCurrentChart() {
            if (!chartInstance) return;
            const t = getEchartsThemeTokens();
            const option = this.buildOption(t);
            chartInstance.setOption(option, true);
        },

        buildOption(t) {
            switch (this.type) {
                case 'revenue':
                    return this.buildRevenueOption(t);
                case 'stacked-bar':
                    return this.buildStackedBarOption(t);
                case 'profitability':
                    return this.buildProfitabilityOption(t);
                case 'waterfall':
                    return this.buildWaterfallOption(t);
                case 'heatmap':
                    return this.buildHeatmapOption(t);
                case 'sparkline':
                    return this.buildSparklineOption(t);
                default:
                    return this.buildRevenueOption(t);
            }
        },

        buildRevenueOption(t) {
            const d = this.chartData || {};
            const labels = d.labels || [];
            const gross = d.gross_series || [];
            const net = d.net_series || [];
            const prior = d.prior_series || [];
            const target = d.target_series || [];

            const isBar = this.chartStyle === 'bar';

            return {
                textStyle: { fontFamily: t.fontFamily },
                animationDuration: 320,
                animationEasing: 'cubicOut',
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: t.tooltipBg,
                    borderColor: t.tooltipBorder,
                    borderWidth: 1,
                    textStyle: { color: t.textColor, fontFamily: t.fontFamily, fontSize: 12 },
                    extraCssText: 'border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 10px 12px;',
                    formatter: (items) => {
                        if (!items || items.length === 0) return '';
                        let out = `<div class="font-semibold text-xs mb-1.5">${items[0].axisValue}</div>`;
                        items.forEach(it => {
                            const val = Number(it.value) || 0;
                            const formatted = 'Rp ' + Math.round(val).toLocaleString('id-ID');
                            out += `
                                <div class="flex items-center justify-between gap-4 text-xs py-0.5">
                                    <span class="flex items-center gap-1.5" style="color: ${it.color}">
                                        <span class="w-2 h-2 rounded-full" style="background: ${it.color}"></span>
                                        ${it.seriesName}
                                    </span>
                                    <span class="font-semibold tabular-nums">${formatted}</span>
                                </div>
                            `;
                        });
                        out += `<div class="mt-1.5 pt-1 text-[10px] text-muted-foreground border-t border-border">Klik titik untuk melihat rincian transaksi</div>`;
                        return out;
                    }
                },
                grid: {
                    left: '2%',
                    right: '3%',
                    top: '12%',
                    bottom: '8%',
                    containLabel: true,
                },
                xAxis: {
                    type: 'category',
                    data: labels,
                    axisLine: { lineStyle: { color: t.borderColor } },
                    axisTick: { show: false },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        interval: Math.max(0, Math.floor(labels.length / 7) - 1),
                    },
                },
                yAxis: {
                    type: 'value',
                    splitLine: { lineStyle: { color: t.splitLineColor, type: 'dashed' } },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        formatter: (val) => formatCompactIDR(val),
                    },
                },
                series: [
                    ...(this.activeSeries.gross ? [{
                        name: 'Gross Revenue',
                        type: isBar ? 'bar' : 'line',
                        data: gross,
                        itemStyle: { color: isBar ? (t.isDark ? '#27272A' : '#E4E4E7') : t.primaryColor },
                        lineStyle: { width: 1.75, color: t.primaryColor },
                        showSymbol: false,
                        smooth: true,
                        barMaxWidth: 16,
                    }] : []),
                    ...(this.activeSeries.net ? [{
                        name: 'Net Revenue',
                        type: isBar ? 'bar' : 'line',
                        data: net,
                        itemStyle: { color: t.accentColor },
                        lineStyle: { width: 2.25, color: t.accentColor },
                        areaStyle: isBar ? undefined : {
                            color: {
                                type: 'linear',
                                x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: t.isDark ? 'rgba(74, 222, 128, 0.20)' : 'rgba(16, 163, 127, 0.16)' },
                                    { offset: 1, color: 'transparent' }
                                ]
                            }
                        },
                        showSymbol: false,
                        smooth: true,
                        barMaxWidth: 16,
                    }] : []),
                    ...(this.activeSeries.prior && prior.length > 0 ? [{
                        name: 'Prior Period',
                        type: 'line',
                        data: prior,
                        lineStyle: { width: 1.5, type: 'dashed', color: t.priorColor },
                        itemStyle: { color: t.priorColor },
                        showSymbol: false,
                        smooth: true,
                    }] : []),
                    ...(this.activeSeries.target && target.length > 0 ? [{
                        name: 'Target Penjualan',
                        type: 'line',
                        data: target,
                        lineStyle: { width: 1.5, type: 'dotted', color: t.targetColor },
                        itemStyle: { color: t.targetColor },
                        showSymbol: false,
                    }] : [])
                ]
            };
        },

        buildStackedBarOption(t) {
            const d = (this.chartData && this.chartData.stacked_bar) ? this.chartData.stacked_bar : (this.chartData || {});
            const labels = d.labels || [];
            const seriesList = d.series || [];
            const isTx = this.metricType === 'transactions';

            return {
                textStyle: { fontFamily: t.fontFamily },
                animationDuration: 320,
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    backgroundColor: t.tooltipBg,
                    borderColor: t.tooltipBorder,
                    textStyle: { color: t.textColor, fontFamily: t.fontFamily, fontSize: 12 },
                    extraCssText: 'border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 10px 12px;',
                    formatter: (items) => {
                        if (!items || items.length === 0) return '';
                        let out = `<div class="font-semibold text-xs mb-1.5">${items[0].axisValue}</div>`;
                        let total = 0;
                        items.forEach(it => {
                            const val = Number(it.value) || 0;
                            total += val;
                            const formatted = isTx
                                ? `${Math.round(val).toLocaleString('id-ID')} tx`
                                : `Rp ${Math.round(val).toLocaleString('id-ID')}`;
                            out += `
                                <div class="flex items-center justify-between gap-4 text-xs py-0.5">
                                    <span class="flex items-center gap-1.5" style="color: ${it.color}">
                                        <span class="w-2 h-2 rounded-full" style="background: ${it.color}"></span>
                                        ${it.seriesName}
                                    </span>
                                    <span class="font-semibold tabular-nums">${formatted}</span>
                                </div>
                            `;
                        });
                        const totalFormatted = isTx
                            ? `${Math.round(total).toLocaleString('id-ID')} tx`
                            : `Rp ${Math.round(total).toLocaleString('id-ID')}`;
                        out += `
                            <div class="mt-1.5 pt-1.5 border-t border-border flex items-center justify-between text-xs font-bold">
                                <span>Total Volume</span>
                                <span class="tabular-nums">${totalFormatted}</span>
                            </div>
                            <div class="mt-1 text-[10px] text-muted-foreground">Klik segmen batang untuk filter kanal pembayaran</div>
                        `;
                        return out;
                    }
                },
                legend: {
                    show: false,
                },
                grid: {
                    left: '2%',
                    right: '3%',
                    top: '8%',
                    bottom: '8%',
                    containLabel: true,
                },
                xAxis: {
                    type: 'category',
                    data: labels,
                    axisLine: { lineStyle: { color: t.borderColor } },
                    axisTick: { show: false },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        interval: Math.max(0, Math.floor(labels.length / 7) - 1),
                    },
                },
                yAxis: {
                    type: 'value',
                    splitLine: { lineStyle: { color: t.splitLineColor, type: 'dashed' } },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        formatter: (val) => isTx ? `${val} tx` : formatCompactIDR(val),
                    },
                },
                series: seriesList.map(s => {
                    const sName = String(s.name || '');
                    const color = sName === 'QRIS' ? t.qrisColor :
                                 (sName.includes('Card') ? t.cardColor :
                                 (sName.includes('Cash') ? t.cashColor : t.invoiceColor));
                    return {
                        name: sName,
                        type: 'bar',
                        stack: 'total',
                        barMaxWidth: 18,
                        itemStyle: { color },
                        data: s.data || s.values || [],
                    };
                })
            };
        },

        buildProfitabilityOption(t) {
            const d = this.chartData || {};
            const labels = d.labels || [];
            const profits = d.gross_profit_series || (d.barSeries?.data) || [];
            const margins = d.margin_pct_series || (d.lineSeries?.data) || [];

            return {
                textStyle: { fontFamily: t.fontFamily },
                animationDuration: 320,
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: t.tooltipBg,
                    borderColor: t.tooltipBorder,
                    textStyle: { color: t.textColor, fontFamily: t.fontFamily, fontSize: 12 },
                    extraCssText: 'border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 10px 12px;',
                    formatter: (items) => {
                        if (!items || items.length === 0) return '';
                        let out = `<div class="font-semibold text-xs mb-1.5">${items[0].axisValue}</div>`;
                        items.forEach(it => {
                            const isPct = it.seriesName.includes('Margin');
                            const formatted = isPct
                                ? `${Number(it.value).toFixed(1)}%`
                                : `Rp ${Math.round(Number(it.value) || 0).toLocaleString('id-ID')}`;
                            out += `
                                <div class="flex items-center justify-between gap-4 text-xs py-0.5">
                                    <span class="flex items-center gap-1.5" style="color: ${it.color}">
                                        <span class="w-2 h-2 rounded-full" style="background: ${it.color}"></span>
                                        ${it.seriesName}
                                    </span>
                                    <span class="font-semibold tabular-nums">${formatted}</span>
                                </div>
                            `;
                        });
                        out += `<div class="mt-1.5 pt-1 text-[10px] text-muted-foreground border-t border-border">Klik batang untuk melihat rincian tanggal transaksi</div>`;
                        return out;
                    }
                },
                grid: {
                    left: '2%',
                    right: '3%',
                    top: '10%',
                    bottom: '8%',
                    containLabel: true,
                },
                xAxis: {
                    type: 'category',
                    data: labels,
                    axisLine: { lineStyle: { color: t.borderColor } },
                    axisTick: { show: false },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        interval: Math.max(0, Math.floor(labels.length / 7) - 1),
                    },
                },
                yAxis: [
                    {
                        type: 'value',
                        splitLine: { lineStyle: { color: t.splitLineColor, type: 'dashed' } },
                        axisLabel: {
                            color: t.mutedTextColor,
                            fontFamily: t.fontFamily,
                            fontSize: 11,
                            formatter: (val) => formatCompactIDR(val),
                        },
                    },
                    {
                        type: 'value',
                        splitLine: { show: false },
                        min: 0,
                        max: 100,
                        axisLabel: {
                            color: t.mutedTextColor,
                            fontFamily: t.fontFamily,
                            fontSize: 11,
                            formatter: '{value}%',
                        },
                    }
                ],
                series: [
                    {
                        name: 'Gross Profit (IDR)',
                        type: 'bar',
                        yAxisIndex: 0,
                        barMaxWidth: 16,
                        itemStyle: {
                            color: t.isDark ? '#4ADE80' : '#10A37F',
                            borderRadius: [3, 3, 0, 0]
                        },
                        data: profits,
                    },
                    {
                        name: 'Margin %',
                        type: 'line',
                        yAxisIndex: 1,
                        lineStyle: { width: 2.25, color: t.isDark ? '#FAFAFA' : '#18181B' },
                        itemStyle: { color: t.isDark ? '#FAFAFA' : '#18181B' },
                        showSymbol: false,
                        smooth: true,
                        data: margins,
                    }
                ]
            };
        },

        buildWaterfallOption(t) {
            const steps = this.chartData?.steps || [];
            const labels = steps.map(s => s.name);
            const baseData = [];
            const barData = [];
            let currentBase = 0;

            steps.forEach(s => {
                const val = Number(s.amount) || 0;
                if (s.is_subtotal || s.is_total) {
                    baseData.push(0);
                    barData.push({
                        value: val,
                        itemStyle: { color: t.accentColor }
                    });
                    currentBase = val;
                } else if (val < 0) {
                    const posVal = Math.abs(val);
                    currentBase = Math.max(0, currentBase - posVal);
                    baseData.push(currentBase);
                    barData.push({
                        value: posVal,
                        itemStyle: { color: t.cogsColor }
                    });
                } else {
                    baseData.push(currentBase);
                    barData.push({
                        value: val,
                        itemStyle: { color: t.primaryColor }
                    });
                    currentBase += val;
                }
            });

            return {
                textStyle: { fontFamily: t.fontFamily },
                animationDuration: 320,
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    backgroundColor: t.tooltipBg,
                    borderColor: t.tooltipBorder,
                    textStyle: { color: t.textColor, fontFamily: t.fontFamily, fontSize: 12 },
                    formatter: (items) => {
                        const it = items.find(i => i.seriesName === 'Amount');
                        if (!it) return '';
                        const st = steps[it.dataIndex];
                        const val = Number(st?.amount) || 0;
                        return `
                            <div class="font-semibold text-xs mb-1">${it.name}</div>
                            <div class="text-xs tabular-nums font-bold" style="color: ${val < 0 ? t.cogsColor : t.accentColor}">
                                Rp ${Math.round(val).toLocaleString('id-ID')}
                            </div>
                            <div class="mt-1 text-[10px] text-muted-foreground">Klik step untuk detail akuntansi</div>
                        `;
                    }
                },
                grid: {
                    left: '2%',
                    right: '3%',
                    top: '10%',
                    bottom: '10%',
                    containLabel: true,
                },
                xAxis: {
                    type: 'category',
                    data: labels,
                    axisLine: { lineStyle: { color: t.borderColor } },
                    axisTick: { show: false },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        interval: 0,
                    },
                },
                yAxis: {
                    type: 'value',
                    splitLine: { lineStyle: { color: t.splitLineColor, type: 'dashed' } },
                    axisLabel: {
                        color: t.mutedTextColor,
                        fontFamily: t.fontFamily,
                        fontSize: 11,
                        formatter: (val) => formatCompactIDR(val),
                    },
                },
                series: [
                    {
                        name: 'Base',
                        type: 'bar',
                        stack: 'bridge',
                        itemStyle: { borderColor: 'transparent', color: 'transparent' },
                        data: baseData,
                    },
                    {
                        name: 'Amount',
                        type: 'bar',
                        stack: 'bridge',
                        barMaxWidth: 28,
                        data: barData,
                    }
                ]
            };
        },

        buildHeatmapOption(t) {
            const d = this.chartData || {};
            const days = d.days || ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
            const hours = d.hours || ['07:00','08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00','19:00','20:00','21:00'];
            const matrix = d.matrix || [];

            // Convert matrix into [hourIndex, dayIndex, value]
            const points = [];
            let maxVal = 1;
            matrix.forEach((row, dayIdx) => {
                row.forEach((val, hourIdx) => {
                    const num = Number(val) || 0;
                    if (num > maxVal) maxVal = num;
                    points.push([hourIdx, dayIdx, num]);
                });
            });

            return {
                textStyle: { fontFamily: t.fontFamily },
                animationDuration: 320,
                tooltip: {
                    position: 'top',
                    backgroundColor: t.tooltipBg,
                    borderColor: t.tooltipBorder,
                    textStyle: { color: t.textColor, fontFamily: t.fontFamily, fontSize: 12 },
                    extraCssText: 'border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 8px 12px;',
                    formatter: (params) => {
                        const h = hours[params.data[0]];
                        const day = days[params.data[1]];
                        const v = params.data[2];
                        return `
                            <div class="font-semibold text-xs mb-0.5">${day}, ${h} WIB</div>
                            <div class="text-xs"><strong class="tabular-nums font-bold">${v}</strong> transaksi</div>
                            <div class="mt-1 text-[10px] text-muted-foreground border-t border-border pt-0.5">Klik sel jam untuk melihat tiket pesanan</div>
                        `;
                    }
                },
                grid: {
                    left: '4%',
                    right: '3%',
                    top: '4%',
                    bottom: '12%',
                    containLabel: true,
                },
                xAxis: {
                    type: 'category',
                    data: hours,
                    splitArea: { show: true, areaStyle: { color: ['transparent', t.isDark ? 'rgba(255,255,255,0.02)' : 'rgba(0,0,0,0.01)'] } },
                    axisLine: { lineStyle: { color: t.borderColor } },
                    axisTick: { show: false },
                    axisLabel: { color: t.mutedTextColor, fontFamily: t.fontFamily, fontSize: 10, interval: 1 },
                },
                yAxis: {
                    type: 'category',
                    data: days,
                    splitArea: { show: true },
                    axisLine: { lineStyle: { color: t.borderColor } },
                    axisTick: { show: false },
                    axisLabel: { color: t.mutedTextColor, fontFamily: t.fontFamily, fontSize: 11 },
                },
                visualMap: {
                    min: 0,
                    max: Math.max(10, maxVal),
                    calculable: false,
                    orient: 'horizontal',
                    left: 'center',
                    bottom: 0,
                    itemWidth: 10,
                    itemHeight: 80,
                    text: ['Ramai', 'Sepi'],
                    textStyle: { color: t.mutedTextColor, fontFamily: t.fontFamily, fontSize: 10 },
                    inRange: {
                        color: [t.heatmapZero, t.heatmapMid, t.heatmapMax]
                    }
                },
                series: [{
                    name: 'Demand Heatmap',
                    type: 'heatmap',
                    data: points,
                    label: { show: false },
                    emphasis: {
                        itemStyle: {
                            shadowBlur: 8,
                            shadowColor: 'rgba(0, 0, 0, 0.4)'
                        }
                    }
                }]
            };
        },

        buildSparklineOption(t) {
            const vals = Array.isArray(this.chartData) ? this.chartData : (this.chartData?.values || []);
            const color = config.color || t.accentColor;

            return {
                grid: { left: 0, right: 0, top: 2, bottom: 2 },
                xAxis: { type: 'category', show: false },
                yAxis: { type: 'value', show: false },
                series: [{
                    type: 'line',
                    data: vals,
                    showSymbol: false,
                    smooth: true,
                    lineStyle: { width: 1.5, color },
                    areaStyle: {
                        color: {
                            type: 'linear',
                            x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color },
                                { offset: 1, color: 'transparent' }
                            ]
                        },
                        opacity: 0.15
                    }
                }]
            };
        },

        async setPeriod(p) {
            this.period = p;
            await this.fetchFilteredData();
        },

        async setGranularity(g) {
            this.granularity = g;
            await this.fetchFilteredData();
        },

        async setChartStyle(s) {
            this.chartStyle = s;
            this.renderCurrentChart();
        },

        toggleSeries(key) {
            if (this.activeSeries && this.activeSeries[key] !== undefined) {
                this.activeSeries[key] = !this.activeSeries[key];
                this.renderCurrentChart();
            }
        },

        async setMetricType(m) {
            this.metricType = m;
            await this.fetchFilteredData();
        },

        async fetchFilteredData() {
            if (!config.endpoint) {
                this.renderCurrentChart();
                return;
            }
            this.loading = true;
            try {
                const url = new URL(config.endpoint, window.location.origin);
                url.searchParams.set('period', this.period);
                url.searchParams.set('granularity', this.granularity);
                url.searchParams.set('metric', this.metricType);
                if (config.storeId) url.searchParams.set('store_id', config.storeId);

                const res = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                if (res.ok) {
                    this.chartData = await res.json();
                    this.renderCurrentChart();
                }
            } catch (e) {
                console.error('[dashboardEcharts] Fetch failed:', e);
            } finally {
                this.loading = false;
            }
        },

        destroy() {
            activeDashboardCharts.delete(this);
            isDisposed = true;
            if (resizeObserver) resizeObserver.disconnect();
            if (themeObserver) themeObserver.disconnect();
            if (chartInstance) {
                chartInstance.dispose();
                chartInstance = null;
            }
        }
    };
}

if (typeof window !== 'undefined') {
    window.dashboardEcharts = dashboardEcharts;
}

