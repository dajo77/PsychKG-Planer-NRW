/**
 * PsychKG Service Worker – cached ausschließlich statische Assets.
 *
 * Authentifizierte Seiten (dashboard.php, admin.php, …), CSV-/PDF-Exporte und
 * alles andere, was vom Server pro Nutzer erzeugt wird, werden NIE gecacht:
 * Auf gemeinsam genutzten Dienst-PCs würde ein Cache-Eintrag sonst nach dem
 * Logout weiterhin personenbezogene Daten anzeigen (die Cache API ignoriert
 * Cache-Control: no-store).
 */
const CACHE_NAME = 'psychkg-static-v2';
const SHELL = [
  './assets/app.css',
  './assets/fonts.css',
  './assets/js/nav.js',
  './assets/js/pwa.js',
  './assets/js/login.js',
  './assets/js/dark-mode.js',
  './assets/fonts/inter-latin.woff2',
  './assets/fonts/inter-latin-ext.woff2',
  './assets/fonts/outfit-latin.woff2',
  './assets/fonts/outfit-latin-ext.woff2'
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

/** Cache only versioned static assets served from /assets/. */
function isStaticAsset(url) {
  return url.origin === self.location.origin
    && url.pathname.includes('/assets/')
    && /\.(css|js|woff2?|ttf|eot|png|jpe?g|gif|svg|webp|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Navigationen und Exporte: immer Netzwerk, niemals Cache. Ohne
  // respondWith() übernimmt der Browser den normalen Request.
  if (req.mode === 'navigate' || !isStaticAsset(url)) return;

  // Statische Assets: cache-first, im Hintergrund aktualisieren.
  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req).then((res) => {
        if (res && res.ok && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      }).catch(() => cached);
      return cached || network;
    })
  );
});

// Logout (login.js) fordert das Löschen aller Cache-Inhalte an.
self.addEventListener('message', (event) => {
  if (!event.data || event.data.type !== 'CLEAR_CACHES') return;
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.map((k) => caches.delete(k)))
    )
  );
});

// Grundhook für spätere Push-Integration (Backend noch ausstehend).
self.addEventListener('push', () => { /* noop – Daten kommen vom Server (TODO) */ });
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(clients.openWindow('./dashboard.php'));
});
