import { router, usePage } from "@inertiajs/react";
import { RealtimeClient } from "@supabase/realtime-js";
import { useEffect, useState } from "react";

import type { SharedProps } from "@/types";

export type RealtimeState = "connecting" | "live" | "polling";

const REALTIME_EVENT = "changed";

/**
 * Berlangganan sinyal broadcast (tanpa data) lalu memuat ulang props halaman lewat
 * server, sehingga data tetap diambil lewat jalur yang memeriksa izin. Jika Realtime
 * gagal tersambung, beralih ke polling.
 *
 * Setelah halaman ditinggalkan semuanya berhenti total. Berhenti berlangganan memicu status
 * "CLOSED"; dulu status itu menyalakan polling lagi tanpa pernah dimatikan, sehingga halaman
 * berikutnya (mis. Kas setelah Pesanan masuk) dimuat ulang terus tiap beberapa detik, antrean
 * request menumpuk, dan menu terasa macet. Muat ulang juga tidak pernah bertumpuk: sinyal yang
 * datang saat masih memuat digabung menjadi satu muat ulang berikutnya.
 */
export function useRealtimeRefresh(topic: string | null, only: string[], pollMs = 15_000): RealtimeState {
  const { realtime } = usePage<SharedProps>().props;
  const [state, setState] = useState<RealtimeState>("connecting");
  const onlyKey = only.join(",");
  // Nilai primitif: objek props baru dengan isi sama tidak boleh memutus & menyambung ulang.
  const realtimeUrl = realtime?.url ?? null;
  const realtimeKey = realtime?.key ?? null;

  useEffect(() => {
    if (!topic) return;
    let disposed = false;
    let pollTimer: ReturnType<typeof setInterval> | null = null;
    let refreshTimer: ReturnType<typeof setTimeout> | null = null;
    let loading = false;
    let again = false;

    const reload = () => {
      refreshTimer = null;
      if (disposed) return;
      if (loading) {
        again = true;
        return;
      }
      loading = true;
      router.reload({
        only: onlyKey.split(","),
        onFinish: () => {
          loading = false;
          if (again && !disposed) {
            again = false;
            refresh();
          }
        },
      });
    };
    // Gabungkan beberapa sinyal beruntun menjadi satu muat ulang.
    const refresh = () => {
      if (disposed) return;
      if (refreshTimer) clearTimeout(refreshTimer);
      refreshTimer = setTimeout(reload, 250);
    };
    const startPolling = () => {
      if (disposed || pollTimer) return;
      setState("polling");
      pollTimer = setInterval(refresh, pollMs);
    };
    const stopPolling = () => {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = null;
    };
    const dispose = () => {
      disposed = true;
      if (refreshTimer) clearTimeout(refreshTimer);
      refreshTimer = null;
      stopPolling();
    };

    if (!realtimeUrl || !realtimeKey) {
      startPolling();
      return dispose;
    }

    const client = new RealtimeClient(`${realtimeUrl.replace(/^http/, "ws")}/realtime/v1`, { params: { apikey: realtimeKey } });
    const fallback = setTimeout(startPolling, 8_000);
    const channel = client
      .channel(topic, { config: { private: false } })
      .on("broadcast", { event: REALTIME_EVENT }, refresh)
      .subscribe((status) => {
        if (disposed) return;
        if (status === "SUBSCRIBED") {
          clearTimeout(fallback);
          stopPolling();
          setState("live");
          // Tangkap perubahan yang terjadi selama menyambung.
          refresh();
        } else if (status === "CHANNEL_ERROR" || status === "TIMED_OUT" || status === "CLOSED") {
          startPolling();
        }
      });

    return () => {
      dispose();
      clearTimeout(fallback);
      void client.removeChannel(channel);
      void client.disconnect();
    };
  }, [topic, onlyKey, pollMs, realtimeUrl, realtimeKey]);

  return topic ? state : "polling";
}
