{{-- ========================================================================= --}}
{{-- ARCHETYPE VIEW: EXECUTIVE BI WORKSPACE (CANVAS DESIGNED WCAG 2.2 AAA)      --}}
{{-- SECTIONS A–G + 3-STATE GL NOTICE + SLIDE-OVER INSPECTOR DRAWERS           --}}
{{-- ========================================================================= --}}
@if($activeArchetype === 'executive' || empty($activeArchetype))
    <div class="space-y-6"
         aria-label="Executive BI Workspace"
         x-data="{
             kpiDrawerOpen: false,
             selectedKpi: 'grossRevenue',

             productDrawerOpen: false,
             selectedProduct: null,
             productBomData: null,
             isLoadingBom: false,

             receiptDrawerOpen: false,
             selectedReceipt: null,
             receiptDetailData: null,
             isLoadingReceipt: false,

             openKpiDrawer(kpiKey) {
                 this.selectedKpi = kpiKey;
                 this.kpiDrawerOpen = true;
             },
             closeKpiDrawer() {
                 this.kpiDrawerOpen = false;
             },
             get kpiMeta() {
                 const kpi = this.selectedKpi;
                 const k = @js($executiveData['kpi'] ?? $executiveData['accrualSales'] ?? []);
                 const gross = Number(k.gross_sales || 0);
                 const net = Number(k.net_sales || 0);
                 const tx = Number(k.total_orders || 0);
                 const atv = Number(k.aov || 0);
                 const profit = Number(k.gross_profit || 0);
                 const margin = Number(k.gross_margin_pct || 0);
                 const formatIDR = (v) => 'Rp ' + Math.round(v).toLocaleString('id-ID');

                 switch(kpi) {
                     case 'grossRevenue':
                         return {
                             title: 'Gross Revenue Breakdown',
                             subtitle: 'Total sales before discounts, including PB1 tax and service charge',
                             label: 'Gross Sales',
                             valueFormatted: formatIDR(gross),
                             delta: k.net_sales_delta || '+0.0%',
                             isPositive: !String(k.net_sales_delta || '').startsWith('-'),
                             description: 'Konsolidasi seluruh transaksi kasir sebelum potongan diskon dan voucher.'
                         };
                     case 'netRevenue':
                         return {
                             title: 'Net Revenue (PSAK Accrual)',
                             subtitle: 'Accrual net sales recognized according to financial accounting standards',
                             label: 'Net Sales',
                             valueFormatted: formatIDR(net),
                             delta: k.net_sales_delta || '+0.0%',
                             isPositive: !String(k.net_sales_delta || '').startsWith('-'),
                             description: 'Pendapatan bersih riil yang menjadi dasar pencatatan buku besar GL.'
                         };
                     case 'transactions':
                         return {
                             title: 'Total Transactions Volume',
                             subtitle: 'Completed POS order count across all active outlets',
                             label: 'Transactions',
                             valueFormatted: tx.toLocaleString('id-ID') + ' tx',
                             delta: k.orders_delta || '+0.0%',
                             isPositive: !String(k.orders_delta || '').startsWith('-'),
                             description: 'Jumlah pesanan berbayar yang berhasil diselesaikan di kasir.'
                         };
                     case 'atv':
                         return {
                             title: 'Average Transaction Value (ATV / AOV)',
                             subtitle: 'Average spending per customer transaction basket',
                             label: 'Basket Size (ATV)',
                             valueFormatted: formatIDR(atv),
                             delta: '+1.8%',
                             isPositive: true,
                             description: 'Rata-rata nilai belanja per transaksi di seluruh gerai cabang.'
                         };
                     case 'grossProfit':
                         return {
                             title: 'Gross Profit & Margin %',
                             subtitle: 'Net sales minus Cost of Goods Sold (BOM / HPP)',
                             label: 'Gross Profit',
                             valueFormatted: formatIDR(profit),
                             delta: margin + '% Margin',
                             isPositive: margin >= 40,
                             description: 'Keuntungan kotor operasional setelah dikurangi biaya bahan baku.'
                         };
                     default:
                         return {
                             title: 'Active Orders & Live Shifts',
                             subtitle: 'Current orders in progress in kitchen and dining area',
                             label: 'Live Orders',
                             valueFormatted: '7 Active',
                             delta: 'Optimal',
                             isPositive: true,
                             description: 'Pesanan yang sedang disiapkan dan shift kasir aktif saat ini.'
                         };
                 }
             },

             async openProductDrawer(item) {
                 this.selectedProduct = item;
                 this.productBomData = null;
                 this.isLoadingBom = true;
                 this.productDrawerOpen = true;
                 try {
                     const res = await fetch('{{ url('/dashboard/products') }}/' + item.id + '/recipe-bom', {
                         headers: { 'Accept': 'application/json' }
                     });
                     if (res.ok) {
                         this.productBomData = await res.json();
                     }
                 } catch (e) {
                     console.error('Failed to load recipe BOM:', e);
                 } finally {
                     this.isLoadingBom = false;
                 }
             },
             closeProductDrawer() {
                 this.productDrawerOpen = false;
                 this.selectedProduct = null;
                 this.productBomData = null;
             },

             async openReceiptDrawer(tx) {
                 this.selectedReceipt = tx;
                 this.receiptDetailData = null;
                 this.isLoadingReceipt = true;
                 this.receiptDrawerOpen = true;
                 try {
                     const res = await fetch('{{ url('/dashboard/orders') }}/' + tx.id + '/receipt', {
                         headers: { 'Accept': 'application/json' }
                     });
                     if (res.ok) {
                         this.receiptDetailData = await res.json();
                     }
                 } catch (e) {
                     console.error('Failed to load receipt:', e);
                 } finally {
                     this.isLoadingReceipt = false;
                 }
             },
             closeReceiptDrawer() {
                 this.receiptDrawerOpen = false;
                 this.selectedReceipt = null;
                 this.receiptDetailData = null;
             }
         }"
         @keydown.escape.window="closeKpiDrawer(); closeProductDrawer(); closeReceiptDrawer()">

        {{-- 1. HEADER BAR --}}
        @include('dashboard.partials.executive.header-bar')

        {{-- 2. 3-STATE GL RECONCILIATION & PLAN VS ACTUAL BANNER (KPI-PLN-01) --}}
        @include('dashboard.partials.executive.gl-status-notice')

        {{-- 3. SECTION A: 12-COL ASYMMETRIC KPI GRID --}}
        @include('dashboard.partials.executive.kpi-grid')

        {{-- 4. SECTION B: REVENUE ANALYTICS (REACTIVE SVG MULTI-SERIES CHART) --}}
        @include('dashboard.partials.executive.revenue-analytics')

        {{-- 5. SECTION C1: VOLUME & PROFITABILITY (DUAL-AXIS COMBO & PAYMENT MIX) --}}
        @include('dashboard.partials.executive.volume-profitability')

        {{-- 6. SECTION C2: PROFITABILITY WATERFALL BRIDGE & DAY×HOUR DEMAND HEATMAP --}}
        @include('dashboard.partials.executive.bridge-and-demand')

        {{-- 7. SECTION C3: OUTLET COMPARISON & SALES MIX --}}
        @include('dashboard.partials.executive.outlet-comparison')

        {{-- 8. SECTION D: PRODUCT PERFORMANCE & UNIT ECONOMICS --}}
        @include('dashboard.partials.executive.product-economics')

        {{-- 9. SECTION E: INVENTORY INTELLIGENCE --}}
        @include('dashboard.partials.executive.inventory-intelligence')

        {{-- 10. SECTION F: RECENT TRANSACTIONS TABLE --}}
        @include('dashboard.partials.executive.recent-transactions')

        {{-- 11. SECTION G: OPERATIONAL ALERTS & BUSINESS INSIGHTS --}}
        @include('dashboard.partials.executive.operational-alerts')

        {{-- 12. SLIDE-OVER INSPECTOR DRAWERS --}}
        @include('dashboard.partials.executive.kpi-drilldown-drawer')
        @include('dashboard.partials.executive.product-recipe-bom-drawer')
        @include('dashboard.partials.executive.digital-receipt-drawer')

    </div>
@endif
