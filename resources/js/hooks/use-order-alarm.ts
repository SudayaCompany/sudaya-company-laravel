import { useEffect, useState } from "react";

import { formatRupiah } from "@/lib/format";

/**
 * Alarm pesanan baru untuk akun kasir (keputusan owner 12 November 2026): bunyi keras berulang,
 * getar, judul tab berkedip, notifikasi sistem, dan banner sampai kasir menekan "Lihat pesanan".
 * Dipakai layar Pesanan masuk dan layout kasir (halaman Kasir/Pesanan/Kas), jadi status "sudah
 * dilihat" dan izin suara disimpan sekali per sesi aplikasi: pindah halaman tidak membunyikan
 * pesanan yang sama dua kali dan tidak perlu mengaktifkan suara lagi. Browser mewajibkan satu
 * sentuhan untuk mengizinkan suara; muat ulang halaman penuh mengulang langkah itu.
 */
export type AlarmItem = { id: string; label: string; detail: string };

/** Pesanan pelanggan di antrean (belum diterima kasir). */
export type AlarmQueued = { id: string; orderNumber: string | null; customerName: string | null; channel: string; type: string };

/** Pesanan pelanggan yang menunggu dibayar di kasir. */
export type AlarmAwaiting = { id: string; reference: string; customerName: string | null; total: number };

export const deskLabel = (channel: string, type: string) => (channel === "qr_table" && type === "dine_in" ? "Antar ke meja" : "Ambil di counter");

/**
 * Item alarm: antrean dari pelanggan (pesanan kasir sendiri tidak dibunyikan) + menunggu bayar.
 * Id sama untuk kedua bagian agar pesanan yang baru dibayar di kasir tidak berbunyi lagi.
 */
export function buildAlarmItems(queued: AlarmQueued[], awaiting: AlarmAwaiting[]): AlarmItem[] {
  return [
    ...queued
      .filter((o) => o.channel !== "pos")
      .map((o) => ({ id: o.id, label: o.orderNumber ?? "", detail: `${o.customerName ?? "Tanpa nama"} · ${deskLabel(o.channel, o.type)}` })),
    ...awaiting.map((o) => ({ id: o.id, label: o.reference, detail: `${o.customerName ?? "Tanpa nama"} · menunggu bayar · ${formatRupiah(o.total)}` })),
  ];
}

// Bersama untuk seluruh sesi aplikasi (Inertia tidak memuat ulang JavaScript saat pindah halaman).
let seen: Set<string> | null = null;
let audio: AudioContext | null = null;
/** Kasir sudah mengetuk "aktifkan suara" di sesi ini: tombolnya tidak ditampilkan lagi di halaman lain. */
let armedInSession = false;

/** `items` null = data belum ada (umpan belum termuat): jangan anggap semua pesanan baru. */
export function useNewOrderAlarm(items: AlarmItem[] | null, enabled: boolean) {
  const [fresh, setFresh] = useState<AlarmItem[]>([]);
  const [armed, setArmed] = useState(() => armedInSession);

  useEffect(() => {
    if (items === null) return;
    if (seen === null) {
      // Pertama kali dalam sesi ini: pesanan yang sudah ada tidak dibunyikan (sudah terlihat di layar).
      seen = new Set(items.map((o) => o.id));
      return;
    }
    const known = seen;
    const added = items.filter((o) => !known.has(o.id));
    added.forEach((o) => known.add(o.id));
    if (enabled && added.length) setFresh((current) => [...current, ...added]);
  }, [items, enabled]);

  useEffect(() => {
    if (fresh.length === 0) return;
    const title = document.title;
    let tick = 0;
    const ring = () => {
      const ctx = audio;
      if (ctx && ctx.state === "running") {
        [0, 0.22, 0.44].forEach((delay, i) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.type = "square";
          osc.frequency.value = i % 2 ? 1320 : 880;
          gain.gain.value = 0.5;
          osc.connect(gain).connect(ctx.destination);
          osc.start(ctx.currentTime + delay);
          osc.stop(ctx.currentTime + delay + 0.18);
        });
      }
      if (navigator.userActivation?.hasBeenActive) navigator.vibrate?.([400, 150, 400]);
      document.title = tick++ % 2 ? title : `(${fresh.length}) PESANAN BARU`;
    };
    ring();
    const timer = window.setInterval(ring, 1600);
    if ("Notification" in window && Notification.permission === "granted") {
      const last = fresh[fresh.length - 1];
      new Notification(`Pesanan baru ${last.label}`, {
        body: last.detail,
        tag: "sudaya-pesanan-baru",
        requireInteraction: true,
      });
    }
    return () => {
      window.clearInterval(timer);
      document.title = title;
    };
  }, [fresh]);

  // Layar perangkat kasir tetap menyala selama halaman kasir terbuka.
  useEffect(() => {
    if (!enabled || !armed || !("wakeLock" in navigator)) return;
    let lock: WakeLockSentinel | null = null;
    const request = () => {
      if (document.visibilityState === "visible") {
        navigator.wakeLock.request("screen").then((l) => (lock = l), () => undefined);
      }
    };
    request();
    document.addEventListener("visibilitychange", request);
    return () => {
      document.removeEventListener("visibilitychange", request);
      lock?.release().catch(() => undefined);
    };
  }, [enabled, armed]);

  const arm = () => {
    try {
      const AudioCtx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
      audio ??= AudioCtx ? new AudioCtx() : null;
      void audio?.resume();
    } catch {
      audio = null;
    }
    if ("Notification" in window && Notification.permission === "default") void Notification.requestPermission();
    armedInSession = true;
    setArmed(true);
  };

  return { fresh, armed, arm, acknowledge: () => setFresh([]) };
}

/**
 * Umpan alarm untuk halaman kasir selain Pesanan masuk: diambil berkala (5 detik, tanpa tumpang
 * tindih) dari server, yang memeriksa izin. Null sampai termuat; berhenti bila tidak berizin.
 */
export function useAlarmFeed(enabled: boolean, outletId?: string) {
  const [feed, setFeed] = useState<{ queued: AlarmQueued[]; awaiting: AlarmAwaiting[] } | null>(null);

  useEffect(() => {
    if (!enabled) return;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const load = async () => {
      try {
        const response = await fetch(`/kds/alarm${outletId ? `?outlet=${encodeURIComponent(outletId)}` : ""}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        if (response.status === 401 || response.status === 403 || response.status === 419) {
          stopped = true;
          return;
        }
        if (response.ok) {
          const body = (await response.json()) as { queued?: AlarmQueued[]; awaiting?: AlarmAwaiting[] };
          if (!stopped) setFeed({ queued: body.queued ?? [], awaiting: body.awaiting ?? [] });
        }
      } catch {
        // Jaringan putus sebentar: coba lagi di putaran berikutnya.
      }
    };
    const tick = async () => {
      await load();
      if (!stopped) timer = setTimeout(tick, 5_000);
    };
    void tick();
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
    };
  }, [enabled, outletId]);

  return enabled ? feed : null;
}
