/* SecOps CRM — service worker: offline shell for a LAN install.
 *
 * Static assets are cache-first (they change only when the app is updated, and
 * the cache is versioned). Page navigations are network-first and fall back to
 * offline.html; data requests are never cached, because every page is rendered
 * for the signed-in session and caching it would leak one account's records to
 * the next person at that machine.
 *
 * Bump VERSION when app.css / app.js change so old copies are dropped.
 *
 * This file lives at the app root on purpose: a worker under /assets/ may only
 * control /assets/, and the offline fallback has to answer page navigations.
 */

const VERSION = 'secops-2026-11-28d';
const OFFLINE_URL = new URL('offline.html', self.location).href;
const PRECACHE = ['assets/app.css', 'assets/app.js', OFFLINE_URL];
const STATIC = /\.(css|js|png|jpe?g|svg|ico|webp|woff2?)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;               // POSTs are never queued

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;    // nothing external exists

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() =>
        caches.match(OFFLINE_URL).then((page) => page || new Response(
          'You are offline and this page is not cached.',
          { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
        ))
      )
    );
    return;
  }

  if (!STATIC.test(url.pathname) && !/\/assets\//.test(url.pathname)) {
    return; // index.php?… data requests go to the network; the page reports failure
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      const fresh = fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(VERSION).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => cached);
      return cached || fresh;
    })
  );
});
