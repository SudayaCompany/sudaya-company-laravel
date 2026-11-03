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
 */
export function useRealtimeRefresh(topic: string | null, only: string[], pollMs = 15_000): RealtimeState {
  const { realtime } = usePage<SharedProps>().props;
  const [state, setState] = useState<RealtimeState>("connecting");
  const onlyKey = only.join(",");

  useEffect(() => {
    if (!topic) return;
    let pollTimer: ReturnType<typeof setInterval> | null = null;
    let refreshTimer: ReturnType<typeof setTimeout> | null = null;

    const refresh = () => {
      // Gabungkan beberapa sinyal beruntun menjadi satu muat ulang.
      if (refreshTimer) clearTimeout(refreshTimer);
      refreshTimer = setTimeout(() => router.reload({ only: onlyKey.split(",") }), 250);
    };
    const startPolling = () => {
      if (pollTimer) return;
      setState("polling");
      pollTimer = setInterval(refresh, pollMs);
    };
    const stopPolling = () => {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = null;
    };

    if (!realtime) {
      startPolling();
      return stopPolling;
    }

    const client = new RealtimeClient(`${realtime.url.replace(/^http/, "ws")}/realtime/v1`, { params: { apikey: realtime.key } });
    const fallback = setTimeout(startPolling, 8_000);
    const channel = client
      .channel(topic, { config: { private: false } })
      .on("broadcast", { event: REALTIME_EVENT }, refresh)
      .subscribe((status) => {
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
      clearTimeout(fallback);
      if (refreshTimer) clearTimeout(refreshTimer);
      stopPolling();
      void client.removeChannel(channel);
      void client.disconnect();
    };
  }, [topic, onlyKey, pollMs, realtime]);

  return topic ? state : "polling";
}
