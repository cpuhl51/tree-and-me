// Service Worker: macht die App installierbar und startet sie auch bei schlechtem Netz.
// App-Dateien: zuerst Netz (immer aktuell), bei Ausfall aus dem Zwischenspeicher.
const CACHE = 'tam-v3';
const SHELL = ['./', './index.html', './datenschutz.html', './manifest.webmanifest', './icon-192.png', './icon-512.png', './logo-96.png', './favicon-64.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL))); self.skipWaiting(); });
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return; // Karten, Baumdaten, Bibliotheken: direkt aus dem Netz
  e.respondWith(fetch(e.request).then(r => {
    const copy = r.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)); return r;
  }).catch(() => caches.match(e.request).then(r => r || caches.match('./index.html'))));
});
