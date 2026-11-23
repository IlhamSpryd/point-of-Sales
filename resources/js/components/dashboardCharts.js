/**
 * Apeiron Systems — Accessible Reactive SVG Analytics Kit (`dashboardCharts.js`)
 *
 * Principles:
 * 1. Zero External Dependencies: Pure Alpine.js v3 + SVG mathematics.
 * 2. WCAG 2.2 Level AAA Accessibility:
 *    - Root SVG has role="img", tabindex="0", dynamic :aria-label.
 *    - Full keyboard navigation: ArrowLeft/ArrowRight to inspect, Enter for drill-down.
 *    - Live aria announcer (aria-live="polite", role="status") for screen readers.
 * 3. Responsive by default via ResizeObserver updating SVG viewBox dimensions.
 * 4. Respects `prefers-reduced-motion` for transitions.
 * 5. Semantic Design Tokens:
 *    - Text: `text-foreground`, `text-muted-foreground`, `text-success`, `text-destructive`, `text-warning`.
 *    - Tooltip: `bg-popover`, `text-popover-foreground`, `border-border`.
 *    - Graphic fills/strokes: strictly uses `--chart-accent: #10A37F` as primary accent.
 *    - Zero arbitrary hardcoded hex classes (`text-[#...]`, `bg-[#...]`).
 */

/**
 * Format numbers as Indonesian Rupiah (IDR)
 */
export function formatIDR(num) {
    const n = Number(num);
    if (!Number.isFinite(n)) return 'Rp 0';
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

/**
 * Compact format for Y-axis currency ticks (e.g. Rp 1.5M, Rp 500k)
 */
export function formatCompactIDR(num) {
    const n = Number(num);
    if (!Number.isFinite(n)) return 'Rp 0';
    const abs = Math.abs(n);
    if (abs >= 1_000_000_000) {
        return 'Rp ' + (n / 1_000_000_000).toFixed(1).replace('.0', '') + 'B';
    }
    if (abs >= 1_000_000) {
        return 'Rp ' + (n / 1_000_000).toFixed(1).replace('.0', '') + 'M';
    }
    if (abs >= 1_000) {
        return 'Rp ' + (n / 1_000).toFixed(0) + 'k';
    }
    return 'Rp ' + n.toLocaleString('id-ID');
}

/**
 * Format numbers with local separators
 */
export function formatNumber(num) {
    const n = Number(num);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString('id-ID');
}

/**
 * Format percentage
 */
export function formatPercent(num) {
    const n = Number(num);
    if (!Number.isFinite(n)) return '0%';
    return n.toFixed(1) + '%';
}

/**
 * Color token resolver ensuring strict design token compliance
 */
export function resolveColorToken(token, fallbackIndex = 0) {
    const isDark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    const palette = [
        'var(--chart-accent, ' + (isDark ? '#5EEAD4' : '#0F766E') + ')',
        'var(--primary, ' + (isDark ? '#ECECF0' : '#0D0D0D') + ')',
        'var(--warning, ' + (isDark ? '#E5B942' : '#78350F') + ')',
        'var(--destructive, ' + (isDark ? '#F89B9B' : '#991B1B') + ')',
        'var(--muted-foreground, ' + (isDark ? '#B8B8C2' : '#4A4A52') + ')',
    ];

    if (!token) {
        return palette[fallbackIndex % palette.length];
    }

    const tokenMap = {
        accent: 'var(--chart-accent, #10A37F)',
        primary: 'var(--primary, #18181B)',
        muted: 'var(--muted-foreground, #71717A)',
        warning: 'var(--warning, #F59E0B)',
        destructive: 'var(--destructive, #EF4444)',
        success: 'var(--chart-accent, #10A37F)',
    };

    return tokenMap[token] || token || palette[fallbackIndex % palette.length];
}

/**
 * Check for prefers-reduced-motion preference
 */
export function prefersReducedMotion() {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Alpine.js component factory for dashboard charts
 */
export default function dashboardChart(config = {}) {
    return {
        type: config.type || 'line',
        chartData: config.data || {},
        options: {
            currency: false,
            percentage: false,
            height: config.options?.height || (config.type === 'sparkline' ? 32 : (config.type === 'stacked-bar' ? 220 : 260)),
            interactive: config.options?.interactive !== false && config.type !== 'sparkline',
            ariaLabel: config.options?.ariaLabel || '',
            onDrillDown: config.options?.onDrillDown || null,
            ...config.options,
        },

        width: 600,
        height: 260,
        activeIndex: null,
        tooltipVisible: false,
        tooltipX: 0,
        tooltipY: 0,
        announcement: '',
        resizeObserver: null,
        svgEl: null,
        tipEl: null,
        srEl: null,

        init() {
            this.height = this.options.height;
            this.normalizeData();
            this.updateDimensions();

            // Set up DOM structure if not present
            this.mountStructure();

            if (typeof window !== 'undefined' && window.ResizeObserver && this.$el) {
                this.resizeObserver = new ResizeObserver((entries) => {
                    for (const entry of entries) {
                        const newWidth = Math.floor(entry.contentRect.width);
                        if (newWidth > 0 && Math.abs(newWidth - this.width) > 2) {
                            this.width = newWidth;
                            this.render();
                        }
                    }
                });
                this.resizeObserver.observe(this.$el);
            }

            // Listen for Livewire updates if component is bound
            if (this.$wire && typeof this.$wire.on === 'function' && config.chartId) {
                this.$wire.on(`update-dashboard-chart-${config.chartId}`, (payload) => {
                    if (payload) {
                        this.chartData = payload.data || payload;
                        this.normalizeData();
                        if (payload.options) {
                            this.options = { ...this.options, ...payload.options };
                        }
                        this.render();
                    }
                });
            }

            this.render();
        },

        normalizeData() {
            if (this.type === 'sparkline') {
                if (Array.isArray(this.chartData)) {
                    this.chartData = { values: this.chartData };
                } else if (Array.isArray(this.chartData.data)) {
                    this.chartData.values = this.chartData.data;
                }
            } else if (this.type === 'line' || this.type === 'stacked-bar') {
                if (Array.isArray(this.chartData.series)) {
                    for (const s of this.chartData.series) {
                        if (!s.values && Array.isArray(s.data)) {
                            s.values = s.data;
                        }
                    }
                }
            } else if (this.type === 'combo') {
                if (!this.chartData.barSeries && Array.isArray(this.chartData.series) && this.chartData.series.length > 0) {
                    this.chartData.barSeries = this.chartData.series[0];
                    if (this.chartData.series.length > 1) {
                        this.chartData.lineSeries = this.chartData.series[1];
                    }
                }
                if (this.chartData.barSeries && !this.chartData.barSeries.values && Array.isArray(this.chartData.barSeries.data)) {
                    this.chartData.barSeries.values = this.chartData.barSeries.data;
                }
                if (this.chartData.lineSeries && !this.chartData.lineSeries.values && Array.isArray(this.chartData.lineSeries.data)) {
                    this.chartData.lineSeries.values = this.chartData.lineSeries.data;
                }
            }
        },

        destroy() {
            if (this.resizeObserver) {
                this.resizeObserver.disconnect();
                this.resizeObserver = null;
            }
        },

        updateDimensions() {
            if (this.$el && this.$el.clientWidth > 0) {
                this.width = this.$el.clientWidth;
            }
        },

        /**
         * Mount internal SVG, tooltip, and screen-reader nodes if missing
         */
        mountStructure() {
            if (!this.$el) return;

            // Apply relative positioning and containment to root container
            this.$el.classList.add('relative', 'w-full', 'select-none');
            if (this.type === 'sparkline') {
                this.$el.classList.add('overflow-visible');
            }

            // Create or reuse SVG element
            let svg = this.$el.querySelector('svg[data-chart-canvas]');
            if (!svg) {
                svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('data-chart-canvas', 'true');
                svg.setAttribute('class', 'w-full h-full overflow-visible');
                svg.setAttribute('role', 'img');
                if (this.options.interactive) {
                    svg.setAttribute('tabindex', '0');
                }
                this.$el.appendChild(svg);
            }
            this.svgEl = svg;

            // Create screen reader live region if interactive
            if (this.options.interactive) {
                let sr = this.$el.querySelector('[data-chart-sr]');
                if (!sr) {
                    sr = document.createElement('div');
                    sr.setAttribute('data-chart-sr', 'true');
                    sr.setAttribute('class', 'sr-only');
                    sr.setAttribute('aria-live', 'polite');
                    sr.setAttribute('role', 'status');
                    this.$el.appendChild(sr);
                }
                this.srEl = sr;

                // Create tooltip element
                let tip = this.$el.querySelector('[data-chart-tip]');
                if (!tip) {
                    tip = document.createElement('div');
                    tip.setAttribute('data-chart-tip', 'true');
                    const motionClasses = prefersReducedMotion() ? '' : 'transition-all duration-75';
                    tip.setAttribute(
                        'class',
                        `hidden absolute pointer-events-none z-30 bg-popover text-popover-foreground border border-border shadow-lg rounded-lg p-2.5 text-xs ${motionClasses}`
                    );
                    tip.style.top = '12px';
                    this.$el.appendChild(tip);
                }
                this.tipEl = tip;

                // Bind keyboard and pointer interaction events on SVG element
                this.bindEvents();
            }
        },

        bindEvents() {
            if (!this.svgEl) return;

            this.svgEl.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    this.inspectPrev();
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    this.inspectNext();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    this.drillDown();
                }
            });

            const onPointerMove = (e) => {
                const rect = this.svgEl.getBoundingClientRect();
                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                const relX = clientX - rect.left;

                const padLeft = this.type === 'combo' ? 60 : 54;
                const padRight = this.type === 'combo' ? 54 : 20;
                const plotW = Math.max(10, this.width - padLeft - padRight);

                const count = (this.chartData.labels || []).length;
                if (count < 1) return;

                const boundedX = Math.max(0, Math.min(plotW, relX - padLeft));
                const idx = Math.min(count - 1, Math.max(0, Math.round((boundedX / plotW) * (count - 1))));

                this.inspectIndex(idx);
            };

            const onPointerLeave = () => {
                this.handlePointerLeave();
            };

            this.svgEl.addEventListener('mousemove', onPointerMove);
            this.svgEl.addEventListener('mouseleave', onPointerLeave);
            this.svgEl.addEventListener('touchstart', onPointerMove, { passive: true });
            this.svgEl.addEventListener('touchmove', onPointerMove, { passive: true });
            this.svgEl.addEventListener('touchend', onPointerLeave);

            this.svgEl.addEventListener('focus', () => {
                if (this.activeIndex === null) {
                    this.inspectIndex(0);
                }
            });

            this.svgEl.addEventListener('blur', () => {
                this.handlePointerLeave();
            });

            this.svgEl.addEventListener('click', (e) => {
                onPointerMove(e);
                this.drillDown();
            });
        },

        formatValue(val, customUnit = null) {
            if (customUnit === '%' || this.options.percentage) {
                return formatPercent(val);
            }
            if (this.options.currency) {
                return formatIDR(val);
            }
            if (customUnit) {
                return `${formatNumber(val)} ${customUnit}`;
            }
            return formatNumber(val);
        },

        get computedAriaLabel() {
            if (this.options.ariaLabel) {
                return this.options.ariaLabel;
            }
            const labels = this.chartData.labels || [];
            const count = labels.length || (this.chartData.values || []).length;
            const typeLabel = {
                line: 'Multi-series line chart',
                'stacked-bar': 'Stacked bar chart',
                combo: 'Dual-axis combo chart',
                sparkline: 'Inline trend sparkline',
            }[this.type] || 'Chart';

            return `${typeLabel}, ${count} data points. Use left and right arrow keys to navigate data points. Press Enter to drill down.`;
        },

        inspectPrev() {
            if (!this.options.interactive) return;
            const count = (this.chartData.labels || []).length;
            if (count === 0) return;

            if (this.activeIndex === null || this.activeIndex <= 0) {
                this.inspectIndex(count - 1);
            } else {
                this.inspectIndex(this.activeIndex - 1);
            }
        },

        inspectNext() {
            if (!this.options.interactive) return;
            const count = (this.chartData.labels || []).length;
            if (count === 0) return;

            if (this.activeIndex === null || this.activeIndex >= count - 1) {
                this.inspectIndex(0);
            } else {
                this.inspectIndex(this.activeIndex + 1);
            }
        },

        inspectIndex(index) {
            const labels = this.chartData.labels || [];
            if (index < 0 || index >= labels.length) return;

            this.activeIndex = index;
            this.tooltipVisible = true;

            const label = labels[index] || '';
            const details = [];

            if (this.type === 'line' || this.type === 'stacked-bar') {
                const series = this.chartData.series || [];
                for (const s of series) {
                    const rawVal = s.values ? s.values[index] : 0;
                    const val = Number.isFinite(Number(rawVal)) ? Number(rawVal) : 0;
                    details.push(`${s.name || 'Value'}: ${this.formatValue(val)}`);
                }
            } else if (this.type === 'combo') {
                const bSeries = this.chartData.barSeries;
                const lSeries = this.chartData.lineSeries;
                if (bSeries && bSeries.values) {
                    const bVal = Number.isFinite(Number(bSeries.values[index])) ? Number(bSeries.values[index]) : 0;
                    details.push(`${bSeries.name || 'Bar'}: ${formatIDR(bVal)}`);
                }
                if (lSeries && lSeries.values) {
                    const lVal = Number.isFinite(Number(lSeries.values[index])) ? Number(lSeries.values[index]) : 0;
                    details.push(`${lSeries.name || 'Line'}: ${formatPercent(lVal)}`);
                }
            }

            this.announcement = `${label}. ${details.join(', ')}`;
            if (this.srEl) {
                this.srEl.textContent = this.announcement;
            }

            this.updateActiveVisuals(index);
            this.updateTooltip(index, label);
        },

        drillDown() {
            if (!this.options.interactive || this.activeIndex === null) return;
            const labels = this.chartData.labels || [];
            const label = labels[this.activeIndex] || '';

            const detail = {
                index: this.activeIndex,
                label,
                type: this.type,
                data: this.getDataPoint(this.activeIndex),
            };

            this.$el.dispatchEvent(
                new CustomEvent('dashboard-drill-down', {
                    bubbles: true,
                    composed: true,
                    detail,
                })
            );

            if (typeof this.options.onDrillDown === 'function') {
                this.options.onDrillDown(detail);
            }
        },

        getDataPoint(index) {
            if (this.type === 'combo') {
                const rawBar = this.chartData.barSeries?.values?.[index];
                const rawLine = this.chartData.lineSeries?.values?.[index];
                return {
                    bar: Number.isFinite(Number(rawBar)) ? Number(rawBar) : 0,
                    line: Number.isFinite(Number(rawLine)) ? Number(rawLine) : 0,
                };
            }
            if (this.chartData.series) {
                const pt = {};
                for (const s of this.chartData.series) {
                    const rawV = s.values?.[index];
                    pt[s.name || 'value'] = Number.isFinite(Number(rawV)) ? Number(rawV) : 0;
                }
                return pt;
            }
            if (this.chartData.values) {
                const rawV = this.chartData.values[index];
                return Number.isFinite(Number(rawV)) ? Number(rawV) : 0;
            }
            return null;
        },

        handlePointerLeave() {
            if (!this.options.interactive) return;
            this.tooltipVisible = false;
            this.activeIndex = null;
            if (this.tipEl) {
                this.tipEl.classList.add('hidden');
            }
            if (this.svgEl) {
                const hv = this.svgEl.querySelector('[data-chart-hover-layer]');
                if (hv) hv.innerHTML = '';
            }
        },

        updateTooltip(index, label) {
            if (!this.tipEl) return;

            let content = `<div class="font-semibold text-foreground mb-1.5 pb-1 border-b border-border">${label}</div>`;

            if (this.type === 'line' || this.type === 'stacked-bar') {
                const series = this.chartData.series || [];
                content += series
                    .map((s, sIdx) => {
                        const rawVal = s.values ? s.values[index] : 0;
                        const val = Number.isFinite(Number(rawVal)) ? Number(rawVal) : 0;
                        const col = resolveColorToken(s.colorToken, sIdx);
                        return `
                            <div class="flex items-center justify-between gap-6 py-0.5">
                                <span class="flex items-center gap-1.5 text-muted-foreground">
                                    <span class="inline-block w-2 h-2 rounded-full shrink-0" style="background-color: ${col}"></span>
                                    ${s.name || 'Series'}
                                </span>
                                <b class="font-semibold tabular-nums text-foreground">${this.formatValue(val)}</b>
                            </div>
                        `;
                    })
                    .join('');
            } else if (this.type === 'combo') {
                const bSeries = this.chartData.barSeries;
                const lSeries = this.chartData.lineSeries;
                if (bSeries) {
                    const rawBVal = bSeries.values?.[index] ?? 0;
                    const bVal = Number.isFinite(Number(rawBVal)) ? Number(rawBVal) : 0;
                    const bCol = resolveColorToken(bSeries.colorToken, 0);
                    content += `
                        <div class="flex items-center justify-between gap-6 py-0.5">
                            <span class="flex items-center gap-1.5 text-muted-foreground">
                                <span class="inline-block w-2 h-2 rounded-full shrink-0" style="background-color: ${bCol}"></span>
                                ${bSeries.name || 'Bar'}
                            </span>
                            <b class="font-semibold tabular-nums text-foreground">${formatIDR(bVal)}</b>
                        </div>
                    `;
                }
                if (lSeries) {
                    const rawLVal = lSeries.values?.[index] ?? 0;
                    const lVal = Number.isFinite(Number(rawLVal)) ? Number(rawLVal) : 0;
                    const lCol = resolveColorToken(lSeries.colorToken, 1);
                    content += `
                        <div class="flex items-center justify-between gap-6 py-0.5">
                            <span class="flex items-center gap-1.5 text-muted-foreground">
                                <span class="inline-block w-2 h-2 rounded-full shrink-0" style="background-color: ${lCol}"></span>
                                ${lSeries.name || 'Margin'}
                            </span>
                            <b class="font-semibold tabular-nums text-foreground">${formatPercent(lVal)}</b>
                        </div>
                    `;
                }
            }

            this.tipEl.innerHTML = content;
            this.tipEl.classList.remove('hidden');

            // Position tooltip dynamically
            const padLeft = this.type === 'combo' ? 60 : 54;
            const padRight = this.type === 'combo' ? 54 : 20;
            const plotW = Math.max(10, this.width - padLeft - padRight);
            const count = (this.chartData.labels || []).length;
            const targetX = padLeft + (count > 1 ? (index / (count - 1)) * plotW : plotW / 2);

            const tipWidth = this.tipEl.offsetWidth || 160;
            let leftPos = targetX + 16;
            if (leftPos + tipWidth > this.width - 8) {
                leftPos = targetX - tipWidth - 16;
            }
            if (leftPos < 8) leftPos = 8;

            this.tipEl.style.left = `${Math.round(leftPos)}px`;
            this.tipEl.style.top = '16px';
        },

        updateActiveVisuals(index) {
            if (!this.svgEl) return;
            const hv = this.svgEl.querySelector('[data-chart-hover-layer]');
            if (!hv) return;

            const padLeft = this.type === 'combo' ? 60 : 54;
            const padRight = this.type === 'combo' ? 54 : 20;
            const padTop = 20;
            const padBottom = 32;
            const plotW = Math.max(10, this.width - padLeft - padRight);
            const plotH = Math.max(10, this.height - padTop - padBottom);
            const count = (this.chartData.labels || []).length;
            if (count === 0) return;

            const cx = padLeft + (count > 1 ? (index / (count - 1)) * plotW : plotW / 2);

            if (this.type === 'line') {
                const allVals = (this.chartData.series || []).flatMap((s) => (s.values || []).map(Number).filter(Number.isFinite));
                const maxVal = Math.max(1, ...allVals);
                const series = this.chartData.series || [];

                const dots = series
                    .map((s, sIdx) => {
                        const rawV = s.values?.[index] ?? 0;
                        const v = Number.isFinite(Number(rawV)) ? Number(rawV) : 0;
                        const cy = padTop + plotH - (v / maxVal) * plotH;
                        const col = resolveColorToken(s.colorToken, sIdx);
                        return `<circle cx="${cx.toFixed(1)}" cy="${cy.toFixed(1)}" r="4" fill="var(--card, #FFFFFF)" stroke="${col}" stroke-width="2.5" />`;
                    })
                    .join('');

                hv.innerHTML = `
                    <line x1="${cx.toFixed(1)}" x2="${cx.toFixed(1)}" y1="${padTop}" y2="${padTop + plotH}" stroke="currentColor" class="text-muted-foreground/40" stroke-width="1.25" stroke-dasharray="3 3" />
                    ${dots}
                `;
            } else if (this.type === 'stacked-bar') {
                const bw = plotW / count;
                hv.innerHTML = `
                    <rect x="${(cx - bw / 2).toFixed(1)}" y="${padTop}" width="${bw.toFixed(1)}" height="${plotH}" fill="currentColor" class="text-muted/15" rx="3" />
                `;
            } else if (this.type === 'combo') {
                const bw = plotW / count;
                const lSeries = this.chartData.lineSeries || { values: [] };
                const rawLVal = lSeries.values?.[index] ?? 0;
                const lVal = Number.isFinite(Number(rawLVal)) ? Number(rawLVal) : 0;
                const cy = padTop + plotH - (lVal / 100) * plotH;
                const col = resolveColorToken(lSeries.colorToken, 1);

                hv.innerHTML = `
                    <rect x="${(cx - bw / 2).toFixed(1)}" y="${padTop}" width="${bw.toFixed(1)}" height="${plotH}" fill="currentColor" class="text-muted/15" rx="3" />
                    <line x1="${cx.toFixed(1)}" x2="${cx.toFixed(1)}" y1="${padTop}" y2="${padTop + plotH}" stroke="currentColor" class="text-muted-foreground/40" stroke-width="1" stroke-dasharray="3 3" />
                    <circle cx="${cx.toFixed(1)}" cy="${cy.toFixed(1)}" r="4.5" fill="var(--card, #FFFFFF)" stroke="${col}" stroke-width="2.5" />
                `;
            }
        },

        /**
         * Master render dispatcher
         */
        render() {
            if (!this.svgEl) return;

            this.svgEl.setAttribute('aria-label', this.computedAriaLabel);

            if (this.type === 'sparkline') {
                this.renderSparklineSvg();
            } else if (this.type === 'line') {
                this.renderLineSvg();
            } else if (this.type === 'stacked-bar') {
                this.renderStackedBarSvg();
            } else if (this.type === 'combo') {
                this.renderComboSvg();
            }
        },

        renderSparklineSvg() {
            const rawVals = Array.isArray(this.chartData)
                ? this.chartData
                : (this.chartData.values || this.chartData.data || []);
            if (!rawVals || rawVals.length === 0) {
                this.svgEl.setAttribute('viewBox', '0 0 100 30');
                this.svgEl.innerHTML = '';
                return;
            }

            const vals = rawVals.map((v) => (Number.isFinite(Number(v)) ? Number(v) : 0));
            const min = Math.min(...vals);
            const max = Math.max(...vals);
            const range = max - min || 1;
            const n = vals.length;
            const colorToken = this.options.strokeColor || this.options.colorToken || this.chartData.colorToken;
            const color = resolveColorToken(colorToken, 0);

            let points;
            if (n === 1) {
                points = 'M 2 15 L 98 15';
            } else {
                points = vals
                    .map((v, i) => {
                        const x = ((i / (n - 1)) * 96 + 2).toFixed(1);
                        const y = (26 - ((v - min) / range) * 22).toFixed(1);
                        return `${i === 0 ? 'M' : 'L'}${x} ${y}`;
                    })
                    .join(' ');
            }

            const areaPath = `${points} L 98 28 L 2 28 Z`;

            this.svgEl.setAttribute('viewBox', '0 0 100 30');
            this.svgEl.setAttribute('preserveAspectRatio', 'none');
            this.svgEl.innerHTML = `
                <path d="${areaPath}" fill="${color}" fill-opacity="0.12" />
                <path d="${points}" fill="none" stroke="${color}" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            `;
        },

        renderLineSvg() {
            const labels = this.chartData.labels || [];
            const series = this.chartData.series || [];
            const n = labels.length;

            const padLeft = 54;
            const padRight = 20;
            const padTop = 20;
            const padBottom = 32;
            const plotW = Math.max(10, this.width - padLeft - padRight);
            const plotH = Math.max(10, this.height - padTop - padBottom);

            this.svgEl.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);

            if (n === 0 || series.length === 0) {
                this.svgEl.innerHTML = `<text x="${this.width / 2}" y="${this.height / 2}" text-anchor="middle" class="text-muted-foreground text-xs fill-current">No data available</text>`;
                return;
            }

            const allVals = series.flatMap((s) => (s.values || s.data || []).map(Number).filter(Number.isFinite));
            const maxVal = Math.max(1, ...allVals);

            // 1. Grid lines and Y labels
            let gridHtml = '';
            const steps = [0, 0.25, 0.5, 0.75, 1];
            for (const pct of steps) {
                const y = (padTop + plotH - pct * plotH).toFixed(1);
                const val = maxVal * pct;
                const label = this.options.currency ? formatCompactIDR(val) : formatNumber(val);
                gridHtml += `
                    <line x1="${padLeft}" x2="${padLeft + plotW}" y1="${y}" y2="${y}" stroke="currentColor" class="text-border" stroke-width="1" stroke-dasharray="3 3" />
                    <text x="${padLeft - 8}" y="${+y + 4}" text-anchor="end" class="text-muted-foreground text-[11px] font-mono fill-current">${label}</text>
                `;
            }

            // 2. X-axis labels (prevent end-label collisions)
            let xLabelsHtml = '';
            const minLabelDist = 72;
            const maxLabels = Math.max(2, Math.floor(plotW / minLabelDist));
            const step = Math.max(1, Math.ceil(n / maxLabels));
            const lastIdx = n - 1;

            labels.forEach((lbl, i) => {
                const isStep = i % step === 0;
                const isLast = i === lastIdx;
                const shouldPrint = isLast || (isStep && (lastIdx - i) >= Math.ceil(step * 0.65));
                if (shouldPrint) {
                    const x = padLeft + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
                    xLabelsHtml += `
                        <text x="${x.toFixed(1)}" y="${this.height - 8}" text-anchor="middle" class="text-muted-foreground text-[11px] font-sans fill-current">${lbl}</text>
                    `;
                }
            });

            // 3. Series curves and area fills
            let seriesHtml = '';
            series.forEach((s, sIdx) => {
                const vals = s.values || s.data || [];
                const color = resolveColorToken(s.colorToken, sIdx);
                const pts = vals.map((rawVal, i) => {
                    const v = Number.isFinite(Number(rawVal)) ? Number(rawVal) : 0;
                    const x = padLeft + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
                    const y = padTop + plotH - (v / maxVal) * plotH;
                    return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`;
                });

                if (pts.length === 0) return;

                const linePath = pts.join(' ');

                if (s.area !== false && !s.dashed) {
                    const lastX = (padLeft + (n > 1 ? ((pts.length - 1) / (n - 1)) * plotW : plotW / 2)).toFixed(1);
                    const firstX = padLeft.toFixed(1);
                    const baseY = (padTop + plotH).toFixed(1);
                    const areaD = `${linePath} L ${lastX} ${baseY} L ${firstX} ${baseY} Z`;
                    seriesHtml += `<path d="${areaD}" fill="${color}" fill-opacity="0.10" />`;
                }

                seriesHtml += `
                    <path d="${linePath}" fill="none" stroke="${color}" stroke-width="${s.dashed ? 1.75 : 2.25}" stroke-linecap="round" stroke-linejoin="round" ${s.dashed ? 'stroke-dasharray="5 4"' : ''} />
                `;
            });

            this.svgEl.innerHTML = `
                <g class="chart-grid">${gridHtml}</g>
                <g class="chart-x-labels">${xLabelsHtml}</g>
                <g class="chart-series">${seriesHtml}</g>
                <g data-chart-hover-layer="true"></g>
                <rect x="${padLeft}" y="${padTop}" width="${plotW}" height="${plotH}" fill="transparent" class="cursor-crosshair" />
            `;
        },

        renderStackedBarSvg() {
            const labels = this.chartData.labels || [];
            const series = this.chartData.series || [];
            const n = labels.length;

            const padLeft = 54;
            const padRight = 20;
            const padTop = 20;
            const padBottom = 32;
            const plotW = Math.max(10, this.width - padLeft - padRight);
            const plotH = Math.max(10, this.height - padTop - padBottom);

            this.svgEl.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);

            if (n === 0 || series.length === 0) {
                this.svgEl.innerHTML = `<text x="${this.width / 2}" y="${this.height / 2}" text-anchor="middle" class="text-muted-foreground text-xs fill-current">No data available</text>`;
                return;
            }

            const totals = labels.map((_, i) =>
                series.reduce((sum, s) => {
                    const raw = s.values?.[i] ?? s.data?.[i];
                    const v = Number.isFinite(Number(raw)) ? Number(raw) : 0;
                    return sum + (v > 0 ? v : 0);
                }, 0)
            );
            const validTotals = totals.map(Number).filter(Number.isFinite);
            const maxVal = Math.max(1, ...validTotals);

            // 1. Grid lines and Y labels
            let gridHtml = '';
            const steps = [0, 0.25, 0.5, 0.75, 1];
            for (const pct of steps) {
                const y = (padTop + plotH - pct * plotH).toFixed(1);
                const val = maxVal * pct;
                const label = this.options.currency ? formatCompactIDR(val) : formatNumber(val);
                gridHtml += `
                    <line x1="${padLeft}" x2="${padLeft + plotW}" y1="${y}" y2="${y}" stroke="currentColor" class="text-border" stroke-width="1" stroke-dasharray="3 3" />
                    <text x="${padLeft - 8}" y="${+y + 4}" text-anchor="end" class="text-muted-foreground text-[11px] font-mono fill-current">${label}</text>
                `;
            }

            // 2. X-axis labels (prevent end-label collisions)
            let xLabelsHtml = '';
            const bw = plotW / n;
            const barW = Math.min(bw * 0.65, 36);
            const minLabelDist = 72;
            const maxLabels = Math.max(2, Math.floor(plotW / minLabelDist));
            const step = Math.max(1, Math.ceil(n / maxLabels));
            const lastIdx = n - 1;

            labels.forEach((lbl, i) => {
                const isStep = i % step === 0;
                const isLast = i === lastIdx;
                const shouldPrint = isLast || (isStep && (lastIdx - i) >= Math.ceil(step * 0.65));
                if (shouldPrint) {
                    const cx = padLeft + i * bw + bw / 2;
                    xLabelsHtml += `
                        <text x="${cx.toFixed(1)}" y="${this.height - 8}" text-anchor="middle" class="text-muted-foreground text-[11px] font-sans fill-current">${lbl}</text>
                    `;
                }
            });

            // 3. Stacked bars
            let barsHtml = '';
            for (let i = 0; i < n; i++) {
                const cx = padLeft + i * bw + bw / 2;
                let accum = 0;

                for (let sIdx = 0; sIdx < series.length; sIdx++) {
                    const s = series[sIdx];
                    const rawVal = s.values?.[i] ?? s.data?.[i];
                    const v = Number.isFinite(Number(rawVal)) ? Number(rawVal) : 0;
                    if (v > 0) {
                        const topY = padTop + plotH - ((accum + v) / maxVal) * plotH;
                        const botY = padTop + plotH - (accum / maxVal) * plotH;
                        const h = Math.max(0.5, botY - topY);
                        const col = resolveColorToken(s.colorToken, sIdx);

                        barsHtml += `
                            <rect x="${(cx - barW / 2).toFixed(1)}" y="${topY.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="2" fill="${col}" />
                        `;
                        accum += v;
                    }
                }
            }

            this.svgEl.innerHTML = `
                <g class="chart-grid">${gridHtml}</g>
                <g class="chart-x-labels">${xLabelsHtml}</g>
                <g class="chart-bars">${barsHtml}</g>
                <g data-chart-hover-layer="true"></g>
                <rect x="${padLeft}" y="${padTop}" width="${plotW}" height="${plotH}" fill="transparent" class="cursor-crosshair" />
            `;
        },

        renderComboSvg() {
            const labels = this.chartData.labels || [];
            const n = labels.length;

            const padLeft = 60;
            const padRight = 54;
            const padTop = 20;
            const padBottom = 32;
            const plotW = Math.max(10, this.width - padLeft - padRight);
            const plotH = Math.max(10, this.height - padTop - padBottom);

            this.svgEl.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);

            if (n === 0) {
                this.svgEl.innerHTML = `<text x="${this.width / 2}" y="${this.height / 2}" text-anchor="middle" class="text-muted-foreground text-xs fill-current">No data available</text>`;
                return;
            }

            const bSeries = this.chartData.barSeries || { values: [] };
            const lSeries = this.chartData.lineSeries || { values: [] };

            const bVals = (bSeries.values || bSeries.data || []).map(Number).filter(Number.isFinite);
            const bMax = Math.max(1, ...bVals);
            const lMin = 0;
            const lMax = 100;

            // 1. Grid lines, Left Y labels (IDR), Right Y labels (Margin %)
            let gridHtml = '';
            const steps = [0, 0.25, 0.5, 0.75, 1];
            for (const pct of steps) {
                const y = (padTop + plotH - pct * plotH).toFixed(1);
                const bVal = bMax * pct;
                const lVal = Math.round(pct * 100);
                gridHtml += `
                    <line x1="${padLeft}" x2="${padLeft + plotW}" y1="${y}" y2="${y}" stroke="currentColor" class="text-border" stroke-width="1" stroke-dasharray="3 3" />
                    <text x="${padLeft - 8}" y="${+y + 4}" text-anchor="end" class="text-muted-foreground text-[11px] font-mono fill-current">${formatCompactIDR(bVal)}</text>
                    <text x="${padLeft + plotW + 8}" y="${+y + 4}" text-anchor="start" class="text-muted-foreground text-[11px] font-mono fill-current">${lVal}%</text>
                `;
            }

            // 2. X-axis labels (prevent end-label collisions)
            let xLabelsHtml = '';
            const bw = plotW / n;
            const barW = Math.min(bw * 0.6, 32);
            const minLabelDist = 72;
            const maxLabels = Math.max(2, Math.floor(plotW / minLabelDist));
            const step = Math.max(1, Math.ceil(n / maxLabels));
            const lastIdx = n - 1;

            labels.forEach((lbl, i) => {
                const isStep = i % step === 0;
                const isLast = i === lastIdx;
                const shouldPrint = isLast || (isStep && (lastIdx - i) >= Math.ceil(step * 0.65));
                if (shouldPrint) {
                    const cx = padLeft + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
                    xLabelsHtml += `
                        <text x="${cx.toFixed(1)}" y="${this.height - 8}" text-anchor="middle" class="text-muted-foreground text-[11px] font-sans fill-current">${lbl}</text>
                    `;
                }
            });

            // 3. Bars for barSeries (left axis)
            let barsHtml = '';
            const bCol = resolveColorToken(bSeries.colorToken, 0);
            for (let i = 0; i < n; i++) {
                const cx = padLeft + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
                const rawBVal = bSeries.values?.[i] ?? bSeries.data?.[i];
                const bVal = Number.isFinite(Number(rawBVal)) ? Number(rawBVal) : 0;
                const bY = padTop + plotH - (bVal / bMax) * plotH;
                const bH = Math.max(0.5, padTop + plotH - bY);

                barsHtml += `
                    <rect x="${(cx - barW / 2).toFixed(1)}" y="${bY.toFixed(1)}" width="${barW.toFixed(1)}" height="${bH.toFixed(1)}" rx="2" fill="${bCol}" />
                `;
            }

            // 4. Line for lineSeries (right axis)
            const lCol = resolveColorToken(lSeries.colorToken, 1);
            const lPoints = (lSeries.values || lSeries.data || []).map((rawVal, i) => {
                const v = Number.isFinite(Number(rawVal)) ? Number(rawVal) : 0;
                const cx = padLeft + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
                const cy = padTop + plotH - ((v - lMin) / (lMax - lMin)) * plotH;
                return `${i === 0 ? 'M' : 'L'}${cx.toFixed(1)} ${cy.toFixed(1)}`;
            });

            const lineHtml = lPoints.length > 0 ? `
                <path d="${lPoints.join(' ')}" fill="none" stroke="${lCol}" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
            ` : '';

            this.svgEl.innerHTML = `
                <g class="chart-grid">${gridHtml}</g>
                <g class="chart-x-labels">${xLabelsHtml}</g>
                <g class="chart-bars">${barsHtml}</g>
                <g class="chart-line">${lineHtml}</g>
                <g data-chart-hover-layer="true"></g>
                <rect x="${padLeft}" y="${padTop}" width="${plotW}" height="${plotH}" fill="transparent" class="cursor-crosshair" />
            `;
        },
    };
}

// Global exposure
if (typeof window !== 'undefined') {
    window.dashboardChart = dashboardChart;
}
