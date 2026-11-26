self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Network pass-through so Livewire and live order updates are never served stale
    if (event.request.method !== 'GET') {
        return;
    }
    event.respondWith(fetch(event.request));
});
