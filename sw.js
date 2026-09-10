// === Service Worker: Mix Player v.1 ===
// Версионирование: меняйте CACHE_VERSION при обновлении index.html
// (или автоматически через meta[app-version] из DOM)
const CACHE_BASE = 'mixplayer-shell';
const CACHE_VERSION = 'v9';
const CACHE = `${CACHE_BASE}-${CACHE_VERSION}`;
const SHELL = ['./', './index.html'];
self.addEventListener('install', e => {
e.waitUntil(
caches.open(CACHE)
.then(c => c.addAll(SHELL))
.then(() => self.skipWaiting())
.catch(() => self.skipWaiting())
);
});
self.addEventListener('activate', e => {
e.waitUntil(
caches.keys()
.then(ks => Promise.all(
ks.filter(k => k.startsWith(CACHE_BASE) && k !== CACHE).map(k => caches.delete(k))
))
.then(() => self.clients.claim())
);
});
self.addEventListener('fetch', e => {
const req = e.request;
if (req.method !== 'GET') return;
let url;
try { url = new URL(req.url); } catch { return; }
if (url.origin !== self.location.origin) return;
// Стримы, мета-данные и API-запросы — всегда live, никогда не кэшируем
if (url.pathname.includes('meta_proxy.php')) return;
// Навигация: network-first с fallback на кэш (офлайн-режим)
if (req.mode === 'navigate') {
e.respondWith(
fetch(req)
.then(resp => {
if (resp && resp.ok) {
const copy = resp.clone();
caches.open(CACHE).then(c => c.put('./index.html', copy));
}
return resp;
})
.catch(() => caches.match('./index.html'))
);
return;
}
// Всё остальное — cache-first
e.respondWith(
caches.match(req).then(cached => {
if (cached) return cached;
return fetch(req).then(resp => {
if (resp && resp.ok) {
const copy = resp.clone();
caches.open(CACHE).then(c => c.put(req, copy));
}
return resp;
});
})
);
});