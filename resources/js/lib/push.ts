import { postJson } from "@/lib/orders";

/**
 * Web Push (permintaan owner 17 November 2026): notifikasi "Pesanan baru" tetap muncul di HP/laptop
 * kasir walau aplikasinya tertutup atau layar terkunci. Hanya jalan di HTTPS (production) dan
 * localhost; di iPhone hanya bila Sudaya dipasang ke layar utama (iOS 16.4+).
 */
export function pushSupported(): boolean {
  return (
    typeof window !== "undefined" && window.isSecureContext && "serviceWorker" in navigator && "PushManager" in window && "Notification" in window
  );
}

function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const base64 = base64url.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(base64url.length / 4) * 4, "=");
  const raw = atob(base64);
  const bytes = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

function sameKey(current: ArrayBuffer | null, publicKey: string): boolean {
  if (!current) return false;
  const a = new Uint8Array(current);
  const b = keyBytes(publicKey);
  return a.length === b.length && a.every((value, i) => value === b[i]);
}

async function registration(): Promise<ServiceWorkerRegistration> {
  // Service worker aplikasi staf juga menampilkan notifikasi; didaftarkan bila belum ada.
  if (!(await navigator.serviceWorker.getRegistration("/"))) await navigator.serviceWorker.register("/sw.js");
  return navigator.serviceWorker.ready;
}

/** Sudah tersambung di sesi aplikasi ini (pindah halaman tidak mengirim ulang). */
let synced: string | null = null;
/**
 * Pendaftaran yang sedang berjalan. Halaman dibuka dan tombol alarm diketuk hampir bersamaan memakai
 * pendaftaran yang sama; dua `subscribe()` bersamaan bisa menghasilkan dua alamat, dan alamat lama
 * tertinggal di server setelah keluar akun.
 */
let syncing: Promise<"on" | "off"> | null = null;

/**
 * Daftarkan perangkat ini untuk notifikasi pesanan baru bila izin notifikasi sudah diberikan.
 * Aman dipanggil berulang; kunci server berganti → berlangganan ulang otomatis.
 */
export async function syncPush(publicKey: string | null | undefined): Promise<"on" | "off" | "unsupported"> {
  if (!publicKey || !pushSupported()) return "unsupported";
  if (Notification.permission !== "granted") return "off";
  if (synced === publicKey) return "on";
  syncing ??= subscribeDevice(publicKey).finally(() => {
    syncing = null;
  });
  return syncing;
}

async function subscribeDevice(publicKey: string): Promise<"on" | "off"> {
  try {
    const reg = await registration();
    let subscription = await reg.pushManager.getSubscription();
    if (subscription && !sameKey(subscription.options.applicationServerKey, publicKey)) {
      await subscription.unsubscribe();
      subscription = null;
    }
    subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
    const json = subscription.toJSON();
    const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? ["aes128gcm"];
    const result = await postJson("/push/subscriptions", {
      endpoint: json.endpoint,
      keys: json.keys,
      contentEncoding: encodings.includes("aes128gcm") ? "aes128gcm" : encodings[0],
    });
    if (!result.ok) return "off";
    synced = publicKey;
    return "on";
  } catch {
    return "off";
  }
}

/** Keluar dari akun: perangkat ini berhenti menerima notifikasi akun ini. Menahan logout paling lama 1,5 detik. */
export async function disablePush(): Promise<void> {
  if (!pushSupported()) return;
  synced = null;
  const work = (async () => {
    // Pendaftaran yang masih berjalan diselesaikan dulu, supaya alamatnya ikut dicabut.
    await syncing;
    synced = null;
    const reg = await navigator.serviceWorker.getRegistration("/");
    const subscription = await reg?.pushManager.getSubscription();
    if (!subscription) return;
    await postJson("/push/subscriptions/delete", { endpoint: subscription.endpoint });
    await subscription.unsubscribe();
  })().catch(() => undefined);
  await Promise.race([work, new Promise((resolve) => setTimeout(resolve, 1500))]);
}

/**
 * Notifikasi sistem dari halaman kasir yang sedang terbuka (alarm pesanan baru). Lewat service worker
 * bila ada: Chrome Android menolak `new Notification()` dengan error, dan error itu tidak boleh
 * menjatuhkan layar kasir. Tag sama dengan Web Push, jadi tidak muncul dua kali.
 */
export function showOrderNotification(title: string, body: string): void {
  const options: NotificationOptions = { body, tag: "sudaya-pesanan-baru", requireInteraction: true, icon: "/icons/icon-192.png" };
  const fallback = () => {
    try {
      new Notification(title, options);
    } catch {
      // Notifikasi halaman tidak didukung: alarm suara, getar, dan judul tab tetap berjalan.
    }
  };
  if (!("serviceWorker" in navigator)) return fallback();
  navigator.serviceWorker
    .getRegistration("/")
    .then((reg) => (reg ? reg.showNotification(title, { ...options, data: { url: location.pathname + location.search } }) : fallback()))
    .catch(fallback);
}
