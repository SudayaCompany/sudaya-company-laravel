/**
 * Bantuan absensi di browser: ambil posisi GPS mentah dan memperkecil foto selfie.
 * Jarak, akurasi, dan keputusan absen selalu dihitung ulang di server.
 */

export type Position = { latitude: number; longitude: number; accuracy: number; ageMs: number };

export type LocationCheck = {
  ok: boolean;
  reason: string | null;
  message: string | null;
  distance: number | null;
  accuracy: number;
  radius: number;
  maxAccuracy: number;
};

/** Posisi saat ini (bukan cache), dengan pesan error yang bisa ditindaklanjuti staf. */
export function currentPosition(): Promise<Position> {
  return new Promise((resolve, reject) => {
    if (!("geolocation" in navigator)) {
      reject(new Error("Perangkat ini tidak mendukung lokasi. Pakai HP dengan GPS."));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) =>
        resolve({
          latitude: p.coords.latitude,
          longitude: p.coords.longitude,
          accuracy: p.coords.accuracy,
          ageMs: Math.max(0, Math.round(Date.now() - p.timestamp)),
        }),
      (error) => {
        const messages: Record<number, string> = {
          1: "Izin lokasi ditolak. Izinkan lokasi untuk situs ini di pengaturan browser, lalu coba lagi.",
          2: "Lokasi tidak tersedia. Nyalakan GPS (lokasi presisi) lalu coba lagi.",
          3: "GPS terlalu lama merespons. Pindah ke dekat pintu atau jendela lalu coba lagi.",
        };
        reject(new Error(messages[error.code] ?? "Lokasi tidak bisa dibaca. Coba lagi."));
      },
      { enableHighAccuracy: true, timeout: 20_000, maximumAge: 0 },
    );
  });
}

function xsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : "";
}

/** Pratinjau jarak & akurasi dari server (tidak mencatat absen). */
export async function checkLocation(position: Position): Promise<{ ok: true; result: LocationCheck } | { ok: false; error: string }> {
  try {
    const response = await fetch("/staff/attendance/location", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
      body: JSON.stringify(position),
      credentials: "same-origin",
      cache: "no-store",
    });
    const data = (await response.json().catch(() => null)) as { ok: true; result: LocationCheck } | { ok: false; error: string } | null;
    if (data && typeof data.ok === "boolean") return data;
    if (response.status === 419) return { ok: false, error: "Halaman kedaluwarsa. Muat ulang lalu coba lagi." };
    if (response.status === 401) return { ok: false, error: "Sesi berakhir. Silakan login ulang." };
    return { ok: false, error: "Lokasi gagal dicek. Coba lagi." };
  } catch {
    return { ok: false, error: "Koneksi terputus. Periksa internet lalu coba lagi." };
  }
}

/**
 * Foto kamera HP bisa 3–8 MB; diperkecil ke sisi terpanjang 960 px (JPEG ±100–250 KB)
 * agar cepat terkirim lewat data seluler. Orientasi EXIF diikuti.
 */
export async function shrinkPhoto(file: File, maxSide = 960): Promise<Blob> {
  const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" }).catch(() => null);
  const source: CanvasImageSource & { width: number; height: number } = bitmap ?? (await loadImage(file));
  const scale = Math.min(1, maxSide / Math.max(source.width, source.height));
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(source.width * scale);
  canvas.height = Math.round(source.height * scale);
  canvas.getContext("2d")!.drawImage(source, 0, 0, canvas.width, canvas.height);
  bitmap?.close();
  return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("Foto tidak bisa diproses."))), "image/jpeg", 0.82));
}

function loadImage(file: File): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      URL.revokeObjectURL(url);
      resolve(image);
    };
    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("Foto tidak bisa dibaca. Ambil ulang."));
    };
    image.src = url;
  });
}

/** Durasi kerja: 135 → "2 j 15 mnt", 480 → "8 j", 45 → "45 mnt" ("m" dihindari agar tidak terbaca meter). */
export function formatMinutes(total: number | null) {
  if (total === null) return "—";
  const hours = Math.floor(total / 60);
  const minutes = total % 60;
  if (hours === 0) return `${minutes} mnt`;
  return minutes === 0 ? `${hours} j` : `${hours} j ${minutes} mnt`;
}
