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

            get isPayDisabled() {
                // isPayDisabled mencegah kasir menekan tombol Bayar sebelum data benar-benar valid,
                // daripada membiarkan klik lalu menampilkan error yang mungkin tidak pernah muncul.
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

            // --- Computed: Tax ---
            get taxAmount() {
                return this.cartSubtotal * this.taxRate;
            },

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
                                onSuccess: (result) => {
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
                                    // Karena tutup paksa (onClose) bisa berarti belum bayar atau sudah bayar tapi telat callback
                                    this.showSuccessPopup(responseData.order_number, true);
                                }
                            });
                    } else {
                        // Transaksi Tunai berhasil
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
            showSuccessPopup(orderNumber, isPending = false) {
                let title = isPending ? 'Menunggu Pembayaran' : 'Pembayaran Sukses!';
                let text = isPending 
                    ? `Order <b>#${orderNumber}</b> telah di-generate. Silakan selesaikan instruksi pembayaran.`
                    : `Transaksi <b>#${orderNumber}</b> telah sukses dikonfirmasi oleh sistem.`;
                let icon = isPending ? 'info' : 'success';
                
                Swal.fire({
                    width: 420,
                    padding: '2rem 1.5rem',
                    title: `<div class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white">${title}</div>`,
                    html: `<p class="text-[14px] font-medium text-gray-500 dark:text-gray-400 leading-relaxed mt-3 px-2">${text}</p>`,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonText: 'Cetak Struk',
                    cancelButtonText: 'Tutup',
                    reverseButtons: true,
                    allowOutsideClick: false,
                    buttonsStyling: false,
                    customClass: {
                        popup: 'rounded-[24px] border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-2xl',
                        title: 'p-0',
                        htmlContainer: 'p-0 m-0',
                        actions: 'mt-8 flex gap-3 w-full px-6 box-border justify-center',
                        confirmButton: 'flex-1 py-3.5 bg-gray-900 dark:bg-zinc-100 text-white dark:text-gray-900 rounded-[14px] font-bold text-[14px] hover:bg-gray-800 dark:hover:bg-white transition-colors shadow-md whitespace-nowrap',
                        cancelButton: 'flex-1 py-3.5 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 rounded-[14px] font-bold text-[14px] hover:bg-zinc-200 dark:hover:bg-zinc-700 transition-colors whitespace-nowrap'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.open(`/transaction/${orderNumber}/receipt`, '_blank');
                    }
                });
            },
            // Fungsi cetak via WebUSB & cash drawer dihapus karena tidak pernah
            // dipanggil dari elemen UI manapun (dead code) dan di luar cakupan
            // kebutuhan UjiKom — cetak struk sudah ditangani cukup oleh halaman
            // transaction.receipt (window.print() bawaan browser).
            formatRupiah(angka) {
                return new Intl.NumberFormat('id-ID').format(Math.round(angka));
            }
        }));
    });
</script>
