// Service worker aplikasi staf Sudaya (PWA): membuat web bisa dipasang di HP dan menampilkan
// halaman offline saat tidak ada internet.
//
// Sengaja minimal dan aman: halaman, data Inertia, foto, dan API TIDAK pernah disimpan di cache
// (berisi data login, pesanan, absen). Hanya halaman offline yang disimpan. Semua request
// selain navigasi halaman tidak disentuh sama sekali.
// Naikkan VERSION setiap kali offline.html diubah.
const VERSION = "sudaya-app-v1";
const OFFLINE_URL = "/offline.html";
const PRECACHE = [OFFLINE_URL];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(VERSION)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key)));
      if (self.registration.navigationPreload) {
        await self.registration.navigationPreload.enable();
      }
      await self.clients.claim();
    })(),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET" || request.mode !== "navigate") {
    return;
  }
  if (new URL(request.url).origin !== self.location.origin) {
    return;
  }
  // Halaman selalu dari jaringan (tidak pernah dari cache); offline → halaman offline.
  event.respondWith(
    (async () => {
      try {
        const preloaded = await event.preloadResponse;
        return preloaded || (await fetch(request));
      } catch {
        const offline = await caches.match(OFFLINE_URL);
        return offline || Response.error();
      }
    })(),
  );
});
