<script>
    window.ApeironPrint = (function () {
        let connecting = null;

        function ensureConnected() {
            if (typeof qz === 'undefined') {
                return Promise.reject(new Error('qz-tray.js is not loaded.'));
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

            // Low retry + 0 delay: fail fast and fallback to manual print dialog
            connecting = qz.websocket.connect({ retries: 1, delay: 0 })
                .finally(() => { connecting = null; });

            return connecting;
        }

        function fetchPayload(orderCode) {
            return fetch('{{ url('/api/orders') }}/' + orderCode + '/print-payload', {
                headers: { 'Accept': 'application/json' },
            }).then((res) => {
                if (!res.ok) throw new Error('Failed to retrieve receipt payload.');
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
                    console.warn('[QZ Tray] Failed to open cash drawer automatically:', err);
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
                    console.warn('[QZ Tray] Silent print failed, falling back to manual print:', err);
                    return false;
                });
        }

        return { kickDrawer, printReceipt };
    })();
</script>
