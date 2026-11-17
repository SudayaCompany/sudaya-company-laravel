// Service worker aplikasi staf Sudaya (PWA): membuat web bisa dipasang di HP dan menampilkan
// halaman offline saat tidak ada internet.
//
// Sengaja minimal dan aman: halaman, data Inertia, foto, dan API TIDAK pernah disimpan di cache
// (berisi data login, pesanan, absen). Hanya halaman offline yang disimpan. Semua request
// selain navigasi halaman tidak disentuh sama sekali.
//
// Sejak 17 November 2026 juga menampilkan notifikasi "Pesanan baru" dari server (Web Push) saat
// aplikasi kasir tertutup; ketuk notifikasi → layar Pesanan masuk.
// Naikkan VERSION setiap kali offline.html diubah.
const VERSION = "sudaya-app-v2";
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

// Web Push: isi dari server (judul, isi, tautan ke Pesanan masuk). Notifikasi bertag sama saling
// mengganti tetapi tetap berbunyi/bergetar lagi (renotify), dan bertahan sampai diketuk.
self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : "" };
  }
  const title = typeof data.title === "string" && data.title ? data.title : "Pesanan baru";
  const url = typeof data.url === "string" && data.url.startsWith("/") && !data.url.startsWith("//") ? data.url : "/kds";
  event.waitUntil(
    self.registration.showNotification(title, {
      body: typeof data.body === "string" ? data.body : "",
      tag: typeof data.tag === "string" && data.tag ? data.tag : "sudaya-pesanan-baru",
      renotify: true,
      requireInteraction: true,
      icon: "/icons/icon-192.png",
      badge: "/icons/icon-192.png",
      vibrate: [400, 150, 400, 150, 400],
      data: { url },
    }),
  );
});

// Ketuk notifikasi: pakai jendela Sudaya yang sudah terbuka bila ada, kalau tidak buka baru.
// Hanya alamat di domain sendiri yang dibuka.
self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || "/kds", self.location.origin);
  const url = target.origin === self.location.origin ? target.href : new URL("/kds", self.location.origin).href;
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
      const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
      if (open) {
        await open.focus();
        // Sudah di layar itu (mis. notifikasi dari alarm halaman): cukup ditampilkan, tidak dimuat ulang.
        if (open.url !== url) await open.navigate(url).catch(() => undefined);
        return;
      }
      await self.clients.openWindow(url);
    })(),
  );
});
