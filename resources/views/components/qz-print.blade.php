<script>
    window.YovelPrint = (function () {
        let connecting = null;

        function ensureConnected() {
            if (typeof qz === 'undefined') {
                return Promise.reject(new Error('qz-tray.js tidak termuat.'));
            }
            if (qz.websocket.isActive()) {
                return Promise.resolve();
            }
            if (connecting) {
                return connecting;
            }

            qz.security.setCertificatePromise(function (resolve, reject) {
                fetch('{{ route('qz.certificate') }}')
                    .then((res) => res.ok ? res.text() : Promise.reject(res))
                    .then(resolve).catch(reject);
            });

            qz.security.setSignaturePromise(function (toSign) {
                return function (resolve, reject) {
                    fetch('{{ route('qz.sign') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ request: toSign }),
                    })
                        .then((res) => res.ok ? res.text() : Promise.reject(res))
                        .then(resolve).catch(reject);
                };
            });

            // Retry rendah + delay 0: kios tidak boleh menggantung lama hanya
            // karena QZ Tray sedang tidak berjalan -- lebih baik cepat gagal
            // lalu fallback ke alur manual (window.open + tombol cetak).
            connecting = qz.websocket.connect({ retries: 1, delay: 0 })
                .finally(() => { connecting = null; });

            return connecting;
        }

        function fetchPayload(orderCode) {
            return fetch('{{ url('/api/orders') }}/' + orderCode + '/print-payload', {
                headers: { 'Accept': 'application/json' },
            }).then((res) => {
                if (!res.ok) throw new Error('Gagal mengambil payload struk.');
                return res.json();
            });
        }

        function kickDrawer(orderCode) {
            return ensureConnected()
                .then(() => fetchPayload(orderCode))
                .then((payload) => {
                    if (!payload.drawer_hex) return false;
                    const config = qz.configs.create('{{ config('pos.printer_name') }}');
                    return qz.print(config, [{ type: 'raw', format: 'hex', data: payload.drawer_hex }]);
                })
                .then(() => true)
                .catch((err) => {
                    console.warn('[QZ Tray] Gagal membuka laci otomatis:', err);
                    return false;
                });
        }

        function printReceipt(orderCode) {
            return ensureConnected()
                .then(() => fetchPayload(orderCode))
                .then((payload) => {
                    const config = qz.configs.create('{{ config('pos.printer_name') }}');
                    return qz.print(config, [{ type: 'raw', format: 'base64', data: payload.receipt_base64 }]);
                })
                .then(() => true)
                .catch((err) => {
                    console.warn('[QZ Tray] Silent print gagal, fallback ke cetak manual:', err);
                    return false;
                });
        }

        return { kickDrawer, printReceipt };
    })();
</script>
