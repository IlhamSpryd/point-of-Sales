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

            // Fitur diskon dihapus (dead code) karena backend tidak mengimplementasikannya,
            // untuk mencegah selisih hitungan frontend vs backend.

            barcodeBuffer: '',
            barcodeTimeout: null,

            // Fallback UUID untuk lingkungan non-HTTPS
            uuidv4() {
                if (typeof crypto !== 'undefined' && crypto.randomUUID) {
                    return crypto.randomUUID();
                }
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    const r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
            },

            init() {
                // Pre-compute lowercase names to optimize search performance
                this.products = this.products.map(p => ({ ...p, _searchKey: p.nama.toLowerCase() }));

                if (typeof window.snap === 'undefined') {
                    const script = document.createElement('script');
                    script.src = "{{ config('services.midtrans.is_production') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}";
                    script.setAttribute('data-client-key', "{{ config('services.midtrans.client_key') }}");
                    document.head.appendChild(script);
                }

                // Listener untuk global barcode scanner
                window.addEventListener('keypress', (e) => {
                    // Hanya tangkap jika bukan dari input field
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                    if (e.key === 'Enter') {
                        if (this.barcodeBuffer.length > 0) {
                            this.handleBarcodeScan(this.barcodeBuffer);
                            this.barcodeBuffer = '';
                        }
                    } else {
                        this.barcodeBuffer += e.key;
                        clearTimeout(this.barcodeTimeout);
                        this.barcodeTimeout = setTimeout(() => {
                            this.barcodeBuffer = '';
                        }, 50); // reset jika terlalu lambat
                    }
                });
                
                // Hotkey F2
                window.addEventListener('keydown', (e) => {
                    if (e.key === 'F2') {
                        e.preventDefault();
                        const searchInput = document.querySelector('input[type="text"][x-model="searchQuery"]');
                        if (searchInput) searchInput.focus();
                    }
                });

                window.addEventListener('online', () => this.syncOfflineQueue());
                // Sync on load just in case
                setTimeout(() => this.syncOfflineQueue(), 2000);
            },

            handleBarcodeScan(code) {
                const product = this.products.find(p => p.code === code);
                if (product) {
                    this.addToCart(product);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Scanned: ' + product.nama,
                        showConfirmButton: false,
                        timer: 1000
                    });
                }
            },

            // Sinkronisasi antrean offline
            async syncOfflineQueue() {
                let queue = JSON.parse(localStorage.getItem('pos_offline_queue') || '[]');
                if (queue.length === 0) return;

                if (!navigator.onLine) return; // tunggu online

                let remainingQueue = [];
                for (let payload of queue) {
                    try {
                        let response = await fetch(initialData.storeRoute, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            },
                            body: JSON.stringify(payload)
                        });
                        
                        if (!response.ok) {
                            remainingQueue.push(payload);
                        }
                    } catch (e) {
                        remainingQueue.push(payload);
                    }
                }
                
                localStorage.setItem('pos_offline_queue', JSON.stringify(remainingQueue));
                if (remainingQueue.length < queue.length) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Antrean offline berhasil disinkronisasi',
                        showConfirmButton: false,
                        timer: 3000
                    });
                }
            },

            get isPayDisabled() {
                if (this.submitting || this.cart.length === 0) return true;
                if (this.paymentMethod === 'cash' && this.uangDibayar < this.totalAmount) return true;
                return false;
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

            get taxAmount() {
                return this.cartSubtotal * this.taxRate;
            },

            // PENTING: Formula di sini HANYA untuk preview visual kasir.
            // Sumber kebenaran (source of truth) tetap TransactionService::calculateOrderTotals()
            // di backend. Jika logika backend berubah (misal: ditambah diskon),
            // formula di sini WAJIB disamakan urutannya, atau total yang tampil
            // di layar kasir akan berbeda dengan yang tersimpan di database.
            get totalAmount() {
                let rawTotal = this.cartSubtotal + this.taxAmount;
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
                if (item.qty < item.stock) {
                    item.qty++;
                } else {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'warning',
                        title: 'Stok maksimum tercapai (' + item.stock + ')',
                        showConfirmButton: false,
                        timer: 2000
                    });
                }
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
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: 'Keranjang belanja kosong!',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                    return;
                }
                
                if (this.paymentMethod === 'cash' && this.uangDibayar < this.totalAmount) {
                    this.submitting = false;
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: 'Masukan uang pembayaran! Nominal uang tunai yang dibayarkan kurang dari total pembayaran.',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                    return;
                }
                
                if (!navigator.onLine) {
                    // Offline mode queueing
                    let payload = {
                        idempotency_key: this.uuidv4(),
                        items: this.cart.map(function(item) {   
                            return {
                                product_id: item.id,
                                quantity: item.qty,
                            }
                        }),
                        payment_method: this.paymentMethod,
                        cash_received: this.uangDibayar
                    };
                    
                    let queue = JSON.parse(localStorage.getItem('pos_offline_queue') || '[]');
                    queue.push(payload);
                    localStorage.setItem('pos_offline_queue', JSON.stringify(queue));
                    
                    this.emptyCart();
                    this.submitting = false;
                    Swal.fire({
                        icon: 'info',
                        title: 'Tersimpan Offline',
                        text: 'Transaksi disimpan ke antrean offline dan akan dikirim saat koneksi pulih.',
                        confirmButtonText: 'OK'
                    });
                    
                    return;
                }

                try {
                    let payload = {
                        idempotency_key: this.uuidv4(),
                        items: this.cart.map(function(item) {   
                            return {
                                product_id: item.id,
                                quantity: item.qty,
                            }
                        }),
                        payment_method: this.paymentMethod,
                        cash_received: this.uangDibayar
                    };

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
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'error',
                            title: errorMsg,
                            showConfirmButton: false,
                            timer: 3000,
                            timerProgressBar: true
                        });
                        this.submitting = false;
                        return;
                    }

                    let responseData = await response.json();

                    if (responseData.snap_token) {
                        if (typeof window.snap === 'undefined') {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'error',
                                title: 'Midtrans Snap tidak tersedia. Pastikan server sudah di-restart.',
                                showConfirmButton: false,
                                timer: 3000,
                                timerProgressBar: true
                            });
                            this.submitting = false;
                            return;
                        }
                        
                        window.snap.pay(responseData.snap_token, {
                                onSuccess: async (result) => {
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
                                    this.showSuccessPopup(responseData.order_number, false);
                                },
                                onPending: (result) => {
                                    Swal.fire({
                                        toast: true,
                                        position: 'top-end',
                                        icon: 'warning',
                                        title: 'Menunggu pembayaran diselesaikan.',
                                        showConfirmButton: false,
                                        timer: 3000,
                                        timerProgressBar: true
                                    });
                                },
                                onError: (result) => {
                                    Swal.fire({
                                        toast: true,
                                        position: 'top-end',
                                        icon: 'error',
                                        title: 'Pembayaran gagal, silakan coba lagi.',
                                        showConfirmButton: false,
                                        timer: 3000,
                                        timerProgressBar: true
                                    });
                                    this.submitting = false;
                                },
                                onClose: async () => {
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
                                    this.showSuccessPopup(responseData.order_number, true);
                                }
                            });
                    } else {
                        this.emptyCart();
                        this.submitting = false;
                        this.showSuccessPopup(responseData.order_number, false);
                    }
                } catch (error) {
                    console.error('Error saat menghubungi server:', error);
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: 'Terjadi kesalahan jaringan.',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                    this.submitting = false;
                }
            },
            async printReceiptHardware(orderNumber) {
                if (!initialData.printerName) return false;
                if (typeof qz === 'undefined') return false;

                try {
                    if (!qz.websocket.isActive()) {
                        await qz.websocket.connect();
                    }
                    
                    let config = qz.configs.create(initialData.printerName);
                    
                    let response = await fetch(`/api/orders/${orderNumber}/print-payload`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    });
                    
                    if (!response.ok) throw new Error('Gagal memuat payload struk');
                    let printData = await response.json();
                    
                    if (printData && printData.payload) {
                        let data = [
                            { type: 'raw', format: 'command', data: printData.payload }
                        ];
                        await qz.print(config, data);
                        return true;
                    }
                    return false;
                } catch (err) {
                    console.error("Hardware Print Error:", err);
                    return false;
                }
            },
            
            showSuccessPopup(orderNumber, isPending = false) {
                let title = isPending ? 'Menunggu Pembayaran' : 'Pembayaran Sukses!';
                let text = isPending 
                    ? `Order <b>#${orderNumber}</b> telah di-generate. Silakan selesaikan instruksi pembayaran.`
                    : `Transaksi <b>#${orderNumber}</b> telah sukses dikonfirmasi oleh sistem.`;
                let icon = isPending ? 'info' : 'success';

                // Hardware Auto Print
                if (!isPending && initialData.printerName && typeof qz !== 'undefined') {
                    this.printReceiptHardware(orderNumber).catch(e => console.error(e));
                }
                
                Swal.fire({
                    width: 420,
                    padding: '2rem 1.5rem',
                    title: `<div class="text-2xl font-extrabold tracking-tight text-yovel-ink">${title}</div>`,
                    html: `<p class="text-[14px] font-medium text-yovel-muted leading-relaxed mt-3 px-2">${text}</p>`,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonText: 'Cetak Struk',
                    cancelButtonText: 'Tutup',
                    reverseButtons: true,
                    allowOutsideClick: false,
                    buttonsStyling: false,
                    customClass: {
                        popup: 'rounded-[24px] border border-yovel-border bg-white shadow-2xl',
                        title: 'p-0',
                        htmlContainer: 'p-0 m-0',
                        actions: 'mt-8 flex gap-3 w-full px-6 box-border justify-center',
                        confirmButton: 'flex-1 py-3.5 bg-primary-700 text-white rounded-[14px] font-bold text-[14px] hover:bg-primary-900 transition-colors shadow-md whitespace-nowrap',
                        cancelButton: 'flex-1 py-3.5 bg-yovel-surface text-yovel-muted rounded-[14px] font-bold text-[14px] hover:bg-primary-200 hover:text-yovel-ink transition-colors whitespace-nowrap'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Fallback browser print jika auto-print gagal/tidak ada printer
                        window.open(`/transaction/${orderNumber}/receipt`, '_blank');
                    }
                });
            },
            formatRupiah(angka) {
                return new Intl.NumberFormat('id-ID').format(Math.round(angka));
            }
        }));
    });
</script>
