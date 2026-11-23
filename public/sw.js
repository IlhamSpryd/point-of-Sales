/**
 * Apeiron POS — Service Worker (FE-PWA-01 / DEC-006)
 * Resilience, offline caching, and PWA standalone kiosk capabilities.
 */
const CACHE_NAME = 'apeiron-pos-v3';
const PRECACHE_ASSETS = [
    '/offline.html',
    '/fonts/material-symbols-rounded.woff2',
    '/fonts/plus-jakarta-sans.woff2',
    '/vendor/qz-tray.js',
    '/favicon.svg',
    '/manifest.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch((err) => {
                console.warn('[SW] Pre-cache partial warning:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    // Skip non-origin requests and dynamic Livewire internal endpoints
    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/livewire/')) return;

    // Static assets: /build/assets/, /fonts/, /vendor/
    if (url.pathname.startsWith('/build/assets/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/vendor/')) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    fetch(event.request).then((networkResponse) => {
                        if (networkResponse && networkResponse.status === 200) {
                            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, networkResponse));
                        }
                    }).catch(() => {});
                    return cachedResponse;
                }
                return fetch(event.request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseClone));
                    }
                    return networkResponse;
                });
            })
        );
        return;
    }

    // HTML Navigation requests: Network-first with fallback to /offline.html
    const acceptHeader = event.request.headers.get('accept') || '';
    if (event.request.mode === 'navigate' || acceptHeader.includes('text/html')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match(event.request).then((cached) => {
                    return cached || caches.match('/offline.html');
                });
            })
        );
    }
});
