<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('posApp', (initialData) => ({
            products: initialData.products || [],
            categories: initialData.categories || [],
            activeCategory: 'all',
            searchQuery: '',
            cart: [],
            cartPulse: false,
            submitting: false,
            taxRate: initialData.taxRate || 0,
            roundingBehavior: initialData.roundingBehavior || 'ROUND_NEAREST',
            roundingValue: initialData.roundingValue || 100,
            activePaymentMethods: initialData.activePaymentMethods || {},
            hardwareAutoDrawer: initialData.hardwareAutoDrawer || false,
            orderChange: initialData.orderChange || 0,
            uangDibayarFormatted: '',
            paymentMethod: initialData.paymentMethod || 'cash',

            // --- Discount State ---
            canApplyDiscount: initialData.canApplyDiscount || false,
            showDiscountPanel: false,
            discountType: 'none',         // 'none' | 'fixed' | 'percentage'
            discountValueFormatted: '',   // Formatted input string
            discountValueRaw: 0,          // Raw numeric value

            init() {
                // Pre-compute lowercase names to optimize search performance
                this.products = this.products.map(p => ({ ...p, _searchKey: p.nama.toLowerCase() }));

                if (typeof window.snap === 'undefined') {
                    const script = document.createElement('script');
                    script.src = "{{ config('services.midtrans.is_production') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}";
                    script.setAttribute('data-client-key', "{{ config('services.midtrans.client_key') }}");
                    document.head.appendChild(script);
                }
            },

            get uangDibayar() {
                if (!this.uangDibayarFormatted) return 0;
                return parseFloat(this.uangDibayarFormatted.toString().replace(/\./g, '')) || 0;
            },
            get paymentMethodText() {
                const methods = {
                    'cash': 'Proses Transaksi',
                    'qris': 'Proses Via QRIS',
                    'ewallet': 'Proses Via E-Wallet',
                    'bank_transfer': 'Proses Via Bank Transfer (VA)',
                    'credit_card': 'Proses Via Kartu Kredit',
                    'cstore': 'Proses Via Gerai Retail'
                };
                return methods[this.paymentMethod] || 'Proses Transaksi';
            },
            formatInputUang(e) {
                let val = this.uangDibayarFormatted.toString().replace(/[^0-9]/g, '');
                this.uangDibayarFormatted = val ? this.formatRupiah(val) : '';
                this.calculateChange();
            },
            addUangDibayar(nominal) {
                let current = this.uangDibayar;
                this.uangDibayarFormatted = this.formatRupiah(current + nominal);
                this.calculateChange();
            },
            get filteredProducts() {
                if (this.activeCategory === 'all' && !this.searchQuery) return this.products;
                
                const search = this.searchQuery.toLowerCase();
                return this.products.filter(p => {
                    if (this.activeCategory !== 'all' && p.category_id != this.activeCategory) return false;
                    if (search === '') return true;
                    return (p._searchKey || p.nama.toLowerCase()).includes(search);
                });
            },
            get cartSubtotal() {
                return this.cart.reduce((sum, item) => sum + (item.harga * item.qty), 0);
            },

            // --- Computed: Discount Amount (Pre-Tax) ---
            get discountAmount() {
                if (this.discountType === 'none' || this.discountValueRaw <= 0) return 0;

                let amount = 0;
                if (this.discountType === 'percentage') {
                    let pct = Math.min(this.discountValueRaw, 100); // Hard-cap 100%
                    amount = Math.round(this.cartSubtotal * pct / 100);
                } else if (this.discountType === 'fixed') {
                    amount = Math.min(this.discountValueRaw, this.cartSubtotal); // Hard-cap di subtotal
                }

                // Final hard-cap: never exceed subtotal
                return Math.min(amount, this.cartSubtotal);
            },

            // --- Computed: Tax (Pre-Tax Discount Standard) ---
            // Pajak dihitung dari (subtotal - diskon), bukan subtotal penuh
            get taxAmount() {
                let discountedSubtotal = this.cartSubtotal - this.discountAmount;
                return discountedSubtotal * this.taxRate;
            },

            get totalAmount() {
                let discountedSubtotal = this.cartSubtotal - this.discountAmount;
                let rawTotal = discountedSubtotal + this.taxAmount;
                let finalTotal = rawTotal;
                
                if (this.roundingBehavior === 'ROUND_NEAREST') {
                    finalTotal = Math.round(rawTotal / this.roundingValue) * this.roundingValue;
                } else if (this.roundingBehavior === 'ROUND_UP') {
                    finalTotal = Math.ceil(rawTotal / this.roundingValue) * this.roundingValue;
                } else if (this.roundingBehavior === 'ROUND_DOWN') {
                    finalTotal = Math.floor(rawTotal / this.roundingValue) * this.roundingValue;
                } else {
                    finalTotal = Math.round(rawTotal);
                }
                
                return finalTotal;
            },

            // --- Discount Input Handlers ---
            formatInputDiskon(e) {
                if (this.discountType === 'fixed') {
                    let val = this.discountValueFormatted.toString().replace(/[^0-9]/g, '');
                    this.discountValueRaw = parseInt(val) || 0;
                    this.discountValueFormatted = val ? this.formatRupiah(val) : '';
                } else if (this.discountType === 'percentage') {
                    // Allow decimals for percentage
                    let val = this.discountValueFormatted.toString().replace(/[^0-9.,]/g, '').replace(',', '.');
                    this.discountValueRaw = parseFloat(val) || 0;
                    // Don't format percentage — keep as plain number
                }
                this.calculateChange();
            },

            toggleDiscountPanel() {
                this.showDiscountPanel = !this.showDiscountPanel;
                if (!this.showDiscountPanel) {
                    this.clearDiscount();
                }
            },

            setDiscountType(type) {
                // Reset value when switching type to prevent confusion
                this.discountValueFormatted = '';
                this.discountValueRaw = 0;
                this.discountType = type;
                this.calculateChange();
            },

            clearDiscount() {
                this.discountType = 'none';
                this.discountValueFormatted = '';
                this.discountValueRaw = 0;
                this.showDiscountPanel = false;
                this.calculateChange();
            },

            // --- Discount Display Helpers ---
            get discountLabel() {
                if (this.discountType === 'percentage' && this.discountValueRaw > 0) {
                    return `Diskon (${Math.min(this.discountValueRaw, 100)}%)`;
                }
                return 'Diskon';
            },

            addToCart(product) {
                if (product.stock <= 0) return;
                let existing = this.cart.find(i => i.id === product.id);
                if (existing) {
                    if (existing.qty < product.stock) {
                        existing.qty++;
                    }
                } else {
                    this.cart.push({
                        ...product,
                        qty: 1
                    });
                }
                this.calculateChange();
                this.cartPulse = true;
                setTimeout(() => this.cartPulse = false, 300);
            },
            increaseQty(index) {
                let item = this.cart[index];
                if (item.qty < item.stock) item.qty++;
                this.calculateChange();
            },
            decreaseQty(index) {
                let item = this.cart[index];
                if (item.qty > 1) {
                    item.qty--;
                } else {
                    this.cart.splice(index, 1);
                }
                this.calculateChange();
            },
            removeFromCart(index) {
                this.cart.splice(index, 1);
                this.calculateChange();
            },
            emptyCart() {
                this.cart = [];
                this.uangDibayarFormatted = '';
                this.orderChange = 0;
                // Reset diskon saat keranjang dikosongkan
                this.clearDiscount();
            },
            calculateChange() {
                let total = this.totalAmount;
                let paid = parseFloat(this.uangDibayar) || 0;
                this.orderChange = paid >= total ? paid - total : 0;
            },
            async onSubmitForm(e) {
                e.preventDefault();
                this.submitting = true;
                if (this.cart.length === 0) {
                    this.submitting = false;
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: 'Keranjang belanja kosong!' } }));
                    return;
                }
                
                if (this.paymentMethod === 'cash' && this.uangDibayar < this.totalAmount) {
                    this.submitting = false;
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: 'Masukan uang pembayaran! Nominal uang tunai yang dibayarkan kurang dari total pembayaran.' } }));
                    return;
                }
                
                try {
                    // Build request payload including discount data
                    let payload = {
                        items: this.cart.map(function(item) {   
                            return {
                                product_id: item.id,
                                quantity: item.qty,
                            }
                        }),
                        payment_method: this.paymentMethod,
                        cash_received: this.uangDibayar
                    };

                    // Sertakan data diskon hanya jika ada dan pengguna diizinkan
                    if (this.canApplyDiscount && this.discountType !== 'none' && this.discountValueRaw > 0) {
                        payload.discount_type = this.discountType;
                        payload.discount_value = this.discountValueRaw;
                    }

                    let response = await fetch(initialData.storeRoute, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content')
                        },
                        body: JSON.stringify(payload)
                    });

                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    } 
                    
                    if (!response.ok) {
                        let errorData = await response.json();
                        let errorMsg = 'Gagal: ' + (errorData.message || 'Terjadi kesalahan sistem');
                        alert(errorMsg);
                        this.submitting = false;
                        return;
                    }

                    let responseData = await response.json();

                    if (responseData.snap_token) {
                        if (typeof window.snap === 'undefined') {
                            alert('Midtrans Snap tidak tersedia. Pastikan server sudah di-restart.');
                            this.submitting = false;
                            return;
                        }
                        
                        window.snap.pay(responseData.snap_token, {
                                onSuccess: (result) => {
                                    this.emptyCart();
                                    this.submitting = false;
                                    window.location.href = `{{ route('payment.success') }}?order_id=${responseData.order_number}`;
                                },
                                onPending: (result) => {
                                    alert('Menunggu pembayaran diselesaikan.');
                                },
                                onError: (result) => {
                                    alert('Pembayaran gagal, silakan coba lagi.');
                                    this.submitting = false;
                                },
                                onClose: async () => {
                                    // Sync status pembayaran ke Midtrans (untuk localhost tanpa webhook)
                                    try {
                                        await fetch(`/api/orders/${responseData.order_number}/sync-status`, {
                                            method: 'POST',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                                'Accept': 'application/json'
                                            }
                                        });
                                    } catch(e) {}
                                    
                                    this.emptyCart();
                                    this.submitting = false;
                                    // Redirect ke halaman sukses agar bisa cetak struk
                                    window.location.href = `{{ route('payment.success') }}?order_id=${responseData.order_number}`;
                                }
                            });
                    } else {
                        // Transaksi Tunai berhasil — redirect ke halaman sukses
                        this.emptyCart();
                        this.submitting = false;
                        window.location.href = `{{ route('payment.success') }}?order_id=${responseData.order_number}`;
                    }
                } catch (error) {
                    console.error('Error saat menghubungi server:', error);
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', text: 'Terjadi kesalahan jaringan.' } }));
                    this.submitting = false;
                }
            },
            async printReceiptWebUSB(orderNumber) {
                try {
                    let isDev = "{{ config('app.env') }}" === 'local';
                    
                    if (!navigator.usb) {
                        if (isDev) {
                            window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'warning', text: 'Print Bypassed: WebUSB not supported (Dev Mode)' } }));
                            return;
                        }
                        throw new Error('WebUSB tidak didukung browser ini.');
                    }
            
                    const devices = await navigator.usb.getDevices();
                    let device = devices.length > 0 ? devices[0] : null;
                    
                    if (!device) {
                        if (isDev) {
                            window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'warning', text: 'Print Bypassed: Hardware not connected (Dev Mode)' } }));
                            return;
                        }
                        // In production, try to request device. Note: this requires user gesture, 
                        // which might fail if not called directly from a click event.
                        device = await navigator.usb.requestDevice({ filters: [] }).catch(e => null);
                    }
            
                    if (!device) throw new Error('Printer USB tidak dipilih.');
            
                    await device.open();
                    
                    if (device.configuration === null) {
                        await device.selectConfiguration(1);
                    }
                    
                    await device.claimInterface(0);
            
                    let response = await fetch(`/api/orders/${orderNumber}/receipt-raw`);
                    let rawData = await response.text(); 
                    
                    const encoder = new TextEncoder();
                    const data = encoder.encode(rawData);
            
                    let endpointNumber = 1;
                    for (const iface of device.configuration.interfaces) {
                        for (const alt of iface.alternates) {
                            for (const ep of alt.endpoints) {
                                if (ep.direction === 'out') {
                                    endpointNumber = ep.endpointNumber;
                                    break;
                                }
                            }
                        }
                    }
            
                    await device.transferOut(endpointNumber, data);
                    await device.close();
            
                    await device.transferOut(endpointNumber, data);
                    await device.close();
            
                } catch (e) {
                    console.warn('WebUSB Print Failed, attempting iframe fallback:', e);
                    try {
                        this.printIframe(orderNumber);
                    } catch (fallbackError) {
                        console.error('Iframe fallback print also failed:', fallbackError);
                    }
                }
            },
            async triggerCashDrawer() {
                try {
                    let isDev = "{{ config('app.env') }}" === 'local';
                    if (!navigator.usb) {
                         if (isDev) console.log('Drawer Bypassed: No WebUSB');
                         return;
                    }
            
                    const devices = await navigator.usb.getDevices();
                    let device = devices.length > 0 ? devices[0] : null;
                    if (!device) {
                        if (isDev) console.log('Drawer Bypassed: Hardware not connected');
                        return; // Do not prompt, it should be silent or pre-configured
                    }
            
                    await device.open();
                    
                    if (device.configuration === null) {
                        await device.selectConfiguration(1);
                    }
                    
                    await device.claimInterface(0);
            
                    let response = await fetch(`/api/orders/open-drawer`);
                    let rawData = await response.text(); 
                    
                    const encoder = new TextEncoder();
                    const data = encoder.encode(rawData);
            
                    let endpointNumber = 1;
                    for (const iface of device.configuration.interfaces) {
                        for (const alt of iface.alternates) {
                            for (const ep of alt.endpoints) {
                                if (ep.direction === 'out') {
                                    endpointNumber = ep.endpointNumber;
                                    break;
                                }
                            }
                        }
                    }
            
                    await device.transferOut(endpointNumber, data);
                    await device.close();
            
                } catch (e) {
                    console.error('WebUSB Drawer Error:', e);
                }
            },
            printIframe(orderNumber) {
                try {
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    iframe.src = `/order/${orderNumber}/receipt`;
                    document.body.appendChild(iframe);
                    
                    iframe.onload = () => {
                        try {
                            iframe.contentWindow.print();
                        } catch (e) {
                            console.error('Iframe contentWindow print error', e);
                        }
                        setTimeout(() => {
                            if(document.body.contains(iframe)) {
                                document.body.removeChild(iframe);
                            }
                        }, 10000);
                    };
                } catch (e) {
                    console.error('Print Iframe creation error:', e);
                }
            },
            formatRupiah(angka) {
                return new Intl.NumberFormat('id-ID').format(Math.round(angka));
            }
        }));
    });
</script>
