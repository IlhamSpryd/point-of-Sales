const CACHE_NAME = 'pos-v1';
const ASSETS = [
    '/transaction',
    '/transaction/create',
    '/css/app.css',
    '/js/app.js',
    '/offline.html',
    'https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS);
        })
    );
});

self.addEventListener('fetch', (event) => {
    // Hanya tangkap GET requests
    if (event.request.method !== 'GET') return;

    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request).then((response) => {
                if (response) {
                    return response;
                } else if (event.request.headers.get('accept').includes('text/html')) {
                    // Fallback to a cached offline page if available, though for TALL this is tricky.
                    // For now, if offline and not in cache, let it fail natively or return offline.html
                    return caches.match('/offline.html');
                }
            });
        })
    );
});
