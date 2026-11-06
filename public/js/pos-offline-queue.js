/**
 * Apeiron POS — Offline Queue & IndexedDB Manager (DEC-006 / TR-003)
 * Menangani antrean transaksi lokal, status luring peramban, dan sinkronisasi idempoten otomatis.
 */
(function (window) {
    'use strict';

    const DB_NAME = 'apeiron_pos_offline_db';
    const DB_VERSION = 1;
    const STORE_NAME = 'offline_orders';

    let dbPromise = null;

    function openDatabase() {
        if (dbPromise) return dbPromise;

        dbPromise = new Promise((resolve, reject) => {
            const request = window.indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, { keyPath: 'offline_client_uuid' });
                    store.createIndex('created_at', 'created_at', { unique: false });
                    store.createIndex('sync_status', 'sync_status', { unique: false });
                }
            };

            request.onsuccess = (event) => {
                resolve(event.target.result);
            };

            request.onerror = (event) => {
                console.error('[OFFLINE POS] Gagal membuka IndexedDB:', event.target.error);
                reject(event.target.error);
            };
        });

        return dbPromise;
    }

    const ApeironOfflinePos = {
        isOnline: navigator.onLine,

        generateUuid() {
            return 'OFFLINE-' + 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        async enqueueOrder(orderPayload) {
            // DEC-006: Batasan transaksi offline hanya boleh metode CASH
            const method = (orderPayload.payment_method || 'cash').toLowerCase();
            if (method !== 'cash') {
                throw new Error('Transaksi luring (offline) hanya mendukung metode pembayaran TUNAI (CASH).');
            }

            const db = await openDatabase();
            const uuid = orderPayload.offline_client_uuid || this.generateUuid();

            const record = {
                offline_client_uuid: uuid,
                idempotency_key: orderPayload.idempotency_key || ('offline-sync-' + uuid),
                payment_method: 'cash',
                payload: { ...orderPayload, offline_client_uuid: uuid, payment_method: 'cash' },
                sync_status: 'pending',
                created_at: new Date().toISOString()
            };

            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.put(record);

                req.onsuccess = () => {
                    console.log(`[OFFLINE POS] Order berhasil disimpan di antrean lokal [${uuid}]`);
                    this.notifyQueueChanged();
                    resolve(record);
                };

                req.onerror = (e) => reject(e.target.error);
            });
        },

        async getPendingOrders() {
            const db = await openDatabase();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readonly');
                const store = tx.objectStore(STORE_NAME);
                const req = store.getAll();

                req.onsuccess = () => {
                    const pending = (req.result || []).filter(r => r.sync_status === 'pending');
                    resolve(pending);
                };

                req.onerror = (e) => reject(e.target.error);
            });
        },

        async getPendingCount() {
            const pending = await this.getPendingOrders();
            return pending.length;
        },

        async removeOrder(uuid) {
            const db = await openDatabase();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.delete(uuid);

                req.onsuccess = () => {
                    this.notifyQueueChanged();
                    resolve();
                };

                req.onerror = (e) => reject(e.target.error);
            });
        },

        async syncPendingOrders() {
            if (!navigator.onLine) {
                console.warn('[OFFLINE POS] Sinkronisasi ditunda: perangkat masih dalam kondisi offline.');
                return { synced: 0, pending: await this.getPendingCount() };
            }

            const pending = await this.getPendingOrders();
            if (pending.length === 0) {
                return { synced: 0, pending: 0 };
            }

            console.log(`[OFFLINE POS] Memulai sinkronisasi ${pending.length} transaksi offline ke server...`);

            const batch = pending.map(p => p.payload);

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch('/api/v1/pos/sync-offline-orders', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || ''
                    },
                    body: JSON.stringify({ orders: batch })
                });

                if (!response.ok) {
                    throw new Error(`HTTP error ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                const results = data.data?.results || [];

                for (const res of results) {
                    if (res.status === 'synced' || res.status === 'already_synced') {
                        await this.removeOrder(res.offline_client_uuid);
                    }
                }

                console.log(`[OFFLINE POS] Sinkronisasi selesai: ${data.data?.synced_count || 0} berhasil disinkronkan.`);
                this.notifyQueueChanged();

                if (window.Livewire) {
                    window.Livewire.dispatch('offline-orders-synced', { count: data.data?.synced_count || 0 });
                }

                return data;
            } catch (err) {
                console.error('[OFFLINE POS] Gagal melakukan sinkronisasi batch:', err);
                throw err;
            }
        },

        notifyQueueChanged() {
            this.getPendingCount().then(count => {
                window.dispatchEvent(new CustomEvent('offline-queue-updated', { detail: { count } }));
            });
        }
    };

    // Event listener deteksi jaringan otomatis
    window.addEventListener('online', () => {
        ApeironOfflinePos.isOnline = true;
        console.log('[OFFLINE POS] Status jaringan: ONLINE. Memicu auto-sync...');
        window.dispatchEvent(new CustomEvent('pos-connectivity-changed', { detail: { online: true } }));
        ApeironOfflinePos.syncPendingOrders().catch(e => console.error(e));
    });

    window.addEventListener('offline', () => {
        ApeironOfflinePos.isOnline = false;
        console.warn('[OFFLINE POS] Status jaringan: OFFLINE. Mengaktifkan mode kasir luring.');
        window.dispatchEvent(new CustomEvent('pos-connectivity-changed', { detail: { online: false } }));
    });

    window.ApeironOfflinePos = ApeironOfflinePos;

})(window);

