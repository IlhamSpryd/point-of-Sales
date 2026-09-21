<script>
    document.addEventListener('alpine:init', () => {
        // [OMEGA-NODE2] Script E2E-compliant (Determinism & State Machine)
        Alpine.data('posApp', (initialData) => ({
            cart: [],
            categories: initialData.categories,
            products: initialData.products,
            searchQuery: '',
            activeCategory: 'all',
            paymentMethod: initialData.paymentMethod,
            uangDibayar: 0,
            uangDibayarFormatted: '',
            taxRatePercent: parseFloat(initialData.taxRatePercent) || 0,
            hardwareAutoDrawer: initialData.hardwareAutoDrawer,
            orderChange: initialData.orderChange,
            storeRoute: initialData.storeRoute,
            receiptUrlTemplate: initialData.receiptUrlTemplate || '#',
            cartPulse: false,
            pulseTimeout: null,
            submitting: false,
            txStatus: 'idle',
            txMessage: 'Sistem POS Siap',
            lastOrderNumber: null,

            get filteredProducts() {
                let p = this.products;
                if (this.activeCategory !== 'all') {
                    p = p.filter(prod => prod.kategori_id === this.activeCategory);
                }
                if (this.searchQuery.trim() !== '') {
                    const q = this.searchQuery.toLowerCase();
                    p = p.filter(prod =>
                        prod.nama.toLowerCase().includes(q) ||
                        (prod.barcode && prod.barcode.toLowerCase() === q) ||
                        (prod.kode && prod.kode.toLowerCase() === q)
                    );
                }
                return p;
            },
            get cartSubtotal() { return this.cart.reduce((total, item) => total + (item.harga * item.qty), 0); },
            get taxAmount() { return (this.cartSubtotal * this.taxRatePercent) / 100; },
            get totalAmount() { return this.cartSubtotal + this.taxAmount; },
            get isPayDisabled() { return this.cart.length === 0 || (this.paymentMethod === 'cash' && this.uangDibayar < this.totalAmount); },

            init() {
                this.$watch('paymentMethod', (val) => {
                    if (val !== 'cash') {
                        this.uangDibayar = 0;
                        this.uangDibayarFormatted = '';
                        this.calculateChange();
                    } else if (this.uangDibayar === 0) {
                        this.uangDibayarFormatted = this.formatRupiah(this.totalAmount);
                        this.uangDibayar = this.totalAmount;
                        this.calculateChange();
                    }
                });
                this.$watch('totalAmount', () => { if (this.paymentMethod === 'cash') this.calculateChange(); });
                this.$watch('cart.length', (len) => {
                    if (len === 0) {
                        this.txStatus = 'idle';
                        this.txMessage = 'Keranjang kosong';
                    }
                });
            },
            addToCart(product) {
                if (product.stock <= 0) {
                    Swal.fire({ title: 'Stok Habis', text: 'Stok produk ' + product.nama + ' tidak mencukupi.', icon: 'warning' });
                    return;
                }
                const existing = this.cart.find(item => item.id === product.id);
                if (existing) {
                    if (existing.qty < product.stock) {
                        existing.qty++;
                        this.triggerCartPulse();
                        this.txMessage = `Ditambahkan: ${product.nama} x${existing.qty}`;
                    } else {
                        Swal.fire({ title: 'Stok Terbatas', text: 'Tidak bisa menambah lebih dari stok yang tersedia.', icon: 'warning' });
                    }
                } else {
                    this.cart.push({ id: product.id, nama: product.nama, harga: product.harga, stock: product.stock, photo: product.photo, qty: 1 });
                    this.triggerCartPulse();
                    this.txMessage = `Dimasukkan: ${product.nama}`;
                }
                this.$nextTick(() => { if (this.$refs.searchInput) this.$refs.searchInput.focus(); });
            },
            triggerCartPulse() {
                this.cartPulse = true;
                if (this.pulseTimeout) clearTimeout(this.pulseTimeout);
                this.pulseTimeout = setTimeout(() => { this.cartPulse = false; }, 300);
            },
            removeFromCart(index) {
                const item = this.cart[index];
                this.cart.splice(index, 1);
                this.txMessage = `Dihapus: ${item.nama}`;
            },
            increaseQty(index) {
                if (this.cart[index].qty < this.cart[index].stock) {
                    this.cart[index].qty++;
                }
            },
            decreaseQty(index) {
                if (this.cart[index].qty > 1) {
                    this.cart[index].qty--;
                } else {
                    this.removeFromCart(index);
                }
            },
            confirmEmptyCart() {
                if (this.cart.length === 0) return;
                Swal.fire({
                    title: 'Kosongkan Keranjang?',
                    text: 'Semua item akan dihapus.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Kosongkan',
                    cancelButtonText: 'Batal'
                }).then((res) => { if (res.isConfirmed) this.emptyCart(); });
            },
            emptyCart() {
                this.cart = [];
                this.paymentMethod = 'cash';
                this.uangDibayar = 0;
                this.uangDibayarFormatted = '';
                this.calculateChange();
                this.txMessage = 'Keranjang dikosongkan';
            },
            formatRupiah(angka) {
                return parseInt(angka).toLocaleString('id-ID');
            },
            formatInputUang(e) {
                let raw = e.target.value.replace(/[^0-9]/g, '');
                if (raw === '') {
                    this.uangDibayar = 0;
                    this.uangDibayarFormatted = '';
                } else {
                    this.uangDibayar = parseInt(raw, 10);
                    this.uangDibayarFormatted = this.formatRupiah(this.uangDibayar);
                }
                this.calculateChange();
            },
            addUangDibayar(amount) {
                if (this.paymentMethod !== 'cash') this.paymentMethod = 'cash';
                this.uangDibayar += amount;
                this.uangDibayarFormatted = this.formatRupiah(this.uangDibayar);
                this.calculateChange();
            },
            calculateChange() {
                this.orderChange = this.uangDibayar - this.totalAmount;
            },
            async onSubmitForm(e) {
                e.preventDefault();
                if (this.isPayDisabled || this.submitting) return;

                this.submitting = true;
                this.txStatus = 'processing';
                this.txMessage = 'Memproses transaksi...';

                try {
                    const mappedItems = this.cart.map(item => ({
                        product_id: item.id,
                        quantity: item.qty
                    }));
                    
                    const response = await fetch(this.storeRoute, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify({
                            items: mappedItems,
                            payment_method: this.paymentMethod,
                            cash_received: this.paymentMethod === 'cash' ? this.uangDibayar : 0
                        })
                    });

                    if (response.ok) {
                        const result = await response.json();
                        this.txStatus = 'success';
                        this.lastOrderNumber = result.order_id || result.order_code || 'LATEST';
                        this.txMessage = 'Transaksi Sukses: ' + this.lastOrderNumber;

                        let successMsg = result.message || 'Transaksi berhasil!';
                        let receiptUrl = result.receipt_url || this.receiptUrlTemplate.replace('__CODE__', this.lastOrderNumber);

                        if (this.hardwareAutoDrawer) {
                            successMsg += ' Laci kasir telah terbuka otomatis (Hardware).';
                        }
                        if (this.orderChange > 0 && this.paymentMethod === 'cash') {
                            successMsg += `<br><br>Kembalian:<br><b class="text-2xl text-emerald-600">Rp ${this.formatRupiah(this.orderChange)}</b>`;
                        }

                        // Menggunakan setTimeout untuk memberi waktu DOM render txMessage sebelum Swal memblokir thread
                        setTimeout(() => {
                            Swal.fire({
                                title: 'Sukses!',
                                html: successMsg,
                                icon: 'success',
                                allowOutsideClick: false,
                                showCancelButton: true,
                                confirmButtonText: 'Cetak Struk',
                                cancelButtonText: 'Transaksi Baru',
                                confirmButtonColor: '#059669' // bg-emerald-600
                            }).then((alertRes) => {
                                if (alertRes.isConfirmed) {
                                    window.open(receiptUrl, '_blank', 'width=400,height=600');
                                }
                                window.location.reload();
                            });
                        }, 50);

                    } else {
                        const errorData = await response.json();
                        this.txStatus = 'error';
                        this.txMessage = errorData.message || 'Gagal memproses transaksi.';
                        this.submitting = false;

                        Swal.fire({
                            title: 'Gagal!',
                            text: this.txMessage,
                            icon: 'error'
                        });
                    }
                } catch (error) {
                    console.error('Error submitting order:', error);
                    this.txStatus = 'error';
                    this.txMessage = 'Terjadi kesalahan sistem.';
                    this.submitting = false;

                    Swal.fire({
                        title: 'Error!',
                        text: 'Terjadi kesalahan jaringan atau server.',
                        icon: 'error'
                    });
                }
            }
        }));
    });
</script>
