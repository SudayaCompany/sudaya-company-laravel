import { useSyncExternalStore } from "react";

import { type RealtimeState, useRealtimeRefresh } from "@/hooks/use-realtime-refresh";
import { cn } from "@/lib/utils";

// "Langsung" = sinyal Supabase Realtime; "Cek berkala" = cadangan polling bila Realtime tidak tersambung.
const LABELS = {
  live: { text: "Langsung", dot: "bg-success", hint: "Pesanan baru tampil otomatis tanpa muat ulang." },
  polling: { text: "Cek berkala", dot: "bg-warning", hint: "Koneksi langsung terputus; data diperbarui setiap beberapa detik." },
  connecting: { text: "Menyambung…", dot: "animate-pulse bg-muted-foreground", hint: "Sedang menyambung ke server." },
} as const;

const OFFLINE = { text: "Tidak ada internet", dot: "bg-destructive", hint: "Periksa Wi-Fi. Data diperbarui lagi saat koneksi kembali." };

function subscribeOnline(callback: () => void) {
  window.addEventListener("online", callback);
  window.addEventListener("offline", callback);
  return () => {
    window.removeEventListener("online", callback);
    window.removeEventListener("offline", callback);
  };
}

/** Status jaringan perangkat (false = tidak ada internet sama sekali). */
export function useOnline() {
  return useSyncExternalStore(subscribeOnline, () => navigator.onLine, () => true);
}

/** Indikator koneksi. `data-realtime` dipakai QA untuk memastikan mode live. */
export function ConnectionBadge({ state, online = true, className }: { state: RealtimeState; online?: boolean; className?: string }) {
  const label = online ? LABELS[state] : OFFLINE;
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs text-muted-foreground", className)} aria-live="polite" data-realtime={state} title={label.hint}>
      <span className={cn("size-2 rounded-full", label.dot)} aria-hidden />
      {label.text}
    </span>
  );
}

/** Indikator + langganan sinyal realtime untuk halaman staf. */
export function LiveRefresh({ topic, only, pollMs, className }: { topic: string; only: string[]; pollMs?: number; className?: string }) {
  const state = useRealtimeRefresh(topic, only, pollMs);
  const online = useOnline();
  return <ConnectionBadge state={state} online={online} className={className} />;
}
