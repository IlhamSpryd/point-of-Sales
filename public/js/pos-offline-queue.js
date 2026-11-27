/**
 * Apeiron POS — Multi-Tenant Partitioned Offline Queue & IndexedDB Manager (DEC-001 / DEC-006 / TR-003)
 * Menangani antrean transaksi lokal terisolasi per tenant_id, status luring peramban, dan sinkronisasi idempoten otomatis.
 */
(function (window) {
    'use strict';

    const DB_VERSION = 1;
    const STORE_NAME = 'offline_orders';

    let dbPromise = null;
    let currentDb = null;
    let currentTenantId = null;
    let currentUserId = null;
    let cachedPendingCount = 0;
    let syncPromise = null;

    function getActiveTenantId() {
        const metaTenant = document.querySelector('meta[name="tenant-id"]')?.getAttribute('content');
        return metaTenant || window.__APEIRON_BOOT__?.tenantId || window.ApeironTenantId || null;
    }

    function getActiveUserId() {
        const metaUser = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
        return metaUser || window.__APEIRON_BOOT__?.userId || window.ApeironUserId || '0';
    }

    function getTenantCountKey(tenantId) {
        return `apeiron_pos_pending_count_${tenantId}`;
    }

    function getScopedCountKey(tenantId, userId) {
        return `apeiron_pos_pending_count_${tenantId}_${userId || '0'}`;
    }

    function readStoredPendingCount() {
        const tenantId = getActiveTenantId();
        if (!tenantId) {
            return 0;
        }
        const scopedRaw = localStorage.getItem(getScopedCountKey(tenantId, getActiveUserId()));
        if (scopedRaw !== null) {
            return Math.max(0, parseInt(scopedRaw, 10) || 0);
        }
        return Math.max(0, parseInt(localStorage.getItem(getTenantCountKey(tenantId)) || '0', 10) || 0);
    }

    function syncCountState(count, tenantId = null, userId = null) {
        const resolvedTenant = tenantId || getActiveTenantId();
        const resolvedUser = userId || getActiveUserId();
        const normalized = Math.max(0, parseInt(String(count ?? 0), 10) || 0);
        cachedPendingCount = normalized;
        if (resolvedTenant) {
            try {
                localStorage.setItem(getTenantCountKey(resolvedTenant), String(normalized));
                localStorage.setItem(getScopedCountKey(resolvedTenant, resolvedUser), String(normalized));
            } catch (e) {
                console.warn('[OFFLINE POS] Gagal menyimpan cached pending count:', e);
            }
        }
        window.dispatchEvent(new CustomEvent('offline-queue-updated', { detail: { count: normalized } }));
    }

    cachedPendingCount = readStoredPendingCount();

    function getDbName(tenantId, userId) {
        if (!tenantId) {
            throw new Error('[OFFLINE POS] Konteks tenant_id tidak ditemukan. Akses IndexedDB dibatalkan demi keamanan multi-tenant.');
        }
        return `apeiron_pos_tenant_${tenantId}_user_${userId || '0'}`;
    }

    function closeDatabase() {
        if (currentDb) {
            try {
                currentDb.close();
            } catch (e) {
                console.warn('[OFFLINE POS] Gagal menutup koneksi IndexedDB:', e);
            }
            currentDb = null;
        }
        dbPromise = null;
        currentTenantId = null;
        currentUserId = null;
    }

    function openDatabase() {
        const tenantId = getActiveTenantId();
        const userId = getActiveUserId();
        if (!tenantId) {
            const err = new Error('[OFFLINE POS] Konteks tenant_id tidak ditemukan. Akses IndexedDB ditolak demi isolasi multi-tenant (DEC-001).');
            console.error(err.message);
            return Promise.reject(err);
        }

        // Jika tenant atau user beralih pada terminal tablet bersama, tutup database sebelumnya
        if ((currentTenantId && String(currentTenantId) !== String(tenantId)) ||
            (currentUserId && String(currentUserId) !== String(userId))) {
            closeDatabase();
        }

        if (dbPromise && currentDb && String(currentTenantId) === String(tenantId) && String(currentUserId) === String(userId)) {
            return dbPromise;
        }

        currentTenantId = tenantId;
        currentUserId = userId;
        const dbName = getDbName(tenantId, userId);

        dbPromise = new Promise((resolve, reject) => {
            const request = window.indexedDB.open(dbName, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, { keyPath: 'offline_client_uuid' });
                    store.createIndex('created_at', 'created_at', { unique: false });
                    store.createIndex('sync_status', 'sync_status', { unique: false });
                    store.createIndex('tenant_id', 'tenant_id', { unique: false });
                }
            };

            request.onsuccess = (event) => {
                currentDb = event.target.result;
                resolve(currentDb);
            };

            request.onerror = (event) => {
                console.error(`[OFFLINE POS] Gagal membuka IndexedDB [${dbName}]:`, event.target.error);
                dbPromise = null;
                currentDb = null;
                reject(event.target.error);
            };
        });

        return dbPromise;
    }

    const ApeironOfflinePos = {
        isOnline: navigator.onLine,

        getTenantId() {
            return getActiveTenantId();
        },

        closeDatabase() {
            closeDatabase();
        },

        generateUuid() {
            return 'OFFLINE-' + 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        },

        getCachedPendingCount() {
            return Math.max(cachedPendingCount, readStoredPendingCount());
        },

        async enqueueOrder(orderPayload) {
            const tenantId = getActiveTenantId();
            if (!tenantId) {
                throw new Error('[OFFLINE POS] Konteks tenant aktif tidak tersedia. Transaksi offline ditolak.');
            }

            // DEC-006: Batasan transaksi offline hanya boleh metode CASH
            const method = (orderPayload.payment_method || 'cash').toLowerCase();
            if (method !== 'cash') {
                throw new Error('Transaksi luring (offline) hanya mendukung metode pembayaran TUNAI (CASH).');
            }

            const db = await openDatabase();
            const uuid = orderPayload.offline_client_uuid || this.generateUuid();
            const userId = getActiveUserId();
            const parsedUserId = parseInt(userId, 10) || null;
            const parsedShiftId = window.__APEIRON_BOOT__?.shiftId || window.ApeironShiftId || null;

            const record = {
                offline_client_uuid: uuid,
                tenant_id: String(tenantId),
                offline_created_by_user_id: parsedUserId,
                offline_shift_id: parsedShiftId ? parseInt(parsedShiftId, 10) : null,
                idempotency_key: orderPayload.idempotency_key || ('offline-sync-' + uuid),
                payment_method: 'cash',
                payload: {
                    ...orderPayload,
                    offline_client_uuid: uuid,
                    offline_created_by_user_id: parsedUserId,
                    offline_shift_id: parsedShiftId ? parseInt(parsedShiftId, 10) : null,
                    payment_method: 'cash',
                    tenant_id: String(tenantId)
                },
                sync_status: 'pending',
                created_at: new Date().toISOString()
            };

            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.put(record);

                req.onsuccess = () => {
                    syncCountState(this.getCachedPendingCount() + 1, tenantId, userId);
                    console.log(`[OFFLINE POS] Order berhasil disimpan di antrean lokal tenant [${tenantId}] - [${uuid}]`);
                    this.notifyQueueChanged();
                    resolve(record);
                };

                req.onerror = (e) => reject(e.target.error);
            });
        },

        async getPendingOrders() {
            const tenantId = getActiveTenantId();
            if (!tenantId) {
                return [];
            }

            const db = await openDatabase();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readonly');
                const store = tx.objectStore(STORE_NAME);
                const req = store.getAll();

                req.onsuccess = () => {
                    const pending = (req.result || [])
                        .filter(r => r.sync_status === 'pending')
                        .filter(r => !r.tenant_id || String(r.tenant_id) === String(tenantId));
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
                    syncCountState(Math.max(0, this.getCachedPendingCount() - 1));
                    this.notifyQueueChanged();
                    resolve();
                };

                req.onerror = (e) => reject(e.target.error);
            });
        },

        async syncPendingOrders() {
            if (syncPromise) {
                return syncPromise;
            }

            const run = (async () => {
                const tenantId = getActiveTenantId();
                if (!tenantId) {
                    console.warn('[OFFLINE POS] Sinkronisasi ditolak: konteks tenant aktif tidak tersedia.');
                    return { synced: 0, pending: 0, error: 'missing_tenant_context' };
                }

                if (!navigator.onLine) {
                    console.warn('[OFFLINE POS] Sinkronisasi ditunda: perangkat masih dalam kondisi offline.');
                    return { synced: 0, pending: await this.getPendingCount() };
                }

                const pending = await this.getPendingOrders();
                if (pending.length === 0) {
                    syncCountState(0, tenantId);
                    return { synced: 0, pending: 0 };
                }

                // Validasi fail-closed: pastikan semua payload terverifikasi milik tenant aktif (DEC-001)
                const validBatch = pending
                    .filter(p => p.tenant_id && String(p.tenant_id) === String(tenantId))
                    .map(p => p.payload);

                if (validBatch.length === 0) {
                    return { synced: 0, pending: 0 };
                }

                console.log(`[OFFLINE POS] Memulai sinkronisasi ${validBatch.length} transaksi offline tenant [${tenantId}] ke server...`);

                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    const response = await fetch('/pos/offline/sync', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken || ''
                        },
                        body: JSON.stringify({ orders: validBatch, tenant_id: tenantId })
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();
                    const results = data.data?.results || [];

                    for (const res of results) {
                        if (res.status === 'synced' || res.status === 'already_synced' || res.status === 'duplicate_skipped' || res.sync_status === 'duplicate_skipped') {
                            await this.removeOrder(res.offline_client_uuid);
                        }
                    }

                    const remainingCount = await this.getPendingCount();
                    syncCountState(remainingCount, tenantId);

                    console.log(`[OFFLINE POS] Sinkronisasi selesai: ${data.data?.synced_count || 0} berhasil disinkronkan.`);
                    window.dispatchEvent(new CustomEvent('offline-orders-synced', { detail: { count: data.data?.synced_count || 0 } }));

                    if (window.Livewire) {
                        window.Livewire.dispatch('offline-orders-synced', { count: data.data?.synced_count || 0 });
                    }

                    return data;
                } catch (err) {
                    console.error('[OFFLINE POS] Gagal melakukan sinkronisasi batch:', err);
                    throw err;
                }
            })();

            syncPromise = run;
            try {
                return await run;
            } finally {
                syncPromise = null;
            }
        },

        notifyQueueChanged() {
            return this.getPendingCount().then(count => {
                syncCountState(count);
                return count;
            }).catch(() => cachedPendingCount);
        }
    };

    // Deteksi logout: peringatan antrean luring sinkron & tutup handle database seketika
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (form && typeof form.getAttribute === 'function') {
            const action = form.getAttribute('action') || '';
            if (action.includes('logout')) {
                const tenantId = getActiveTenantId();
                if (tenantId) {
                    const storedCount = parseInt(localStorage.getItem(`apeiron_pos_pending_count_${tenantId}`) || '0', 10) || 0;
                    const count = Math.max(cachedPendingCount, storedCount);
                    if (count > 0) {
                        const ok = window.confirm(`Peringatan: Ada ${count} transaksi offline yang belum tersinkronisasi. Pastikan sinkronisasi selesai sebelum berganti kasir. Tetap logout?`);
                        if (!ok) {
                            e.preventDefault();
                            e.stopPropagation();
                            return;
                        }
                    }
                }
                ApeironOfflinePos.closeDatabase();
            }
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            if (getActiveTenantId()) {
                ApeironOfflinePos.notifyQueueChanged();
            }
        });
    } else if (getActiveTenantId()) {
        ApeironOfflinePos.notifyQueueChanged();
    }

    window.addEventListener('apeiron-logout', () => {
        ApeironOfflinePos.closeDatabase();
    });

    window.addEventListener('beforeunload', () => {
        ApeironOfflinePos.closeDatabase();
    });

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
