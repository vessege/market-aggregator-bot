// MarketCompare — minimal service worker.
// Strategy: cache-first for the app shell, network-first for API.
// Bump CACHE_NAME whenever shell assets ship breaking changes.

const CACHE_NAME = 'mc-shell-v3';
const SHELL = [
  './',
  'index.html',
  'assets/css/styles.css',
  'assets/js/app.js',
  'assets/favicon.svg',
  'manifest.webmanifest',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Don't try to cache cross-origin or admin pages.
  if (url.origin !== self.location.origin) return;
  if (url.pathname.includes('/admin/')) return;

  // Network-first for API responses so users see fresh prices when online.
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(req)
        .then((resp) => {
          // Best-effort warm cache (silent failure).
          const copy = resp.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          return resp;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  // Cache-first for the static shell.
  event.respondWith(
    caches.match(req).then(
      (cached) =>
        cached ||
        fetch(req)
          .then((resp) => {
            if (resp && resp.status === 200 && resp.type === 'basic') {
              const copy = resp.clone();
              caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
            }
            return resp;
          })
          .catch(() => caches.match('index.html'))
    )
  );
});
