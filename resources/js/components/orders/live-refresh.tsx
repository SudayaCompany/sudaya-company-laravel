import { useRealtimeRefresh } from "@/hooks/use-realtime-refresh";
import { cn } from "@/lib/utils";

// "Langsung" = sinyal Supabase Realtime; "Cek berkala" = cadangan polling bila Realtime tidak tersambung.
const LABELS = {
  live: { text: "Langsung", dot: "bg-success", hint: "Pesanan baru tampil otomatis tanpa muat ulang." },
  polling: { text: "Cek berkala", dot: "bg-warning", hint: "Koneksi langsung terputus; data diperbarui setiap beberapa detik." },
  connecting: { text: "Menyambung…", dot: "animate-pulse bg-muted-foreground", hint: "Sedang menyambung ke server." },
} as const;

/** Indikator + langganan sinyal realtime untuk halaman staf. */
export function LiveRefresh({ topic, only, pollMs, className }: { topic: string; only: string[]; pollMs?: number; className?: string }) {
  const state = useRealtimeRefresh(topic, only, pollMs);
  return (
    <span
      className={cn("inline-flex items-center gap-1.5 text-xs text-muted-foreground", className)}
      aria-live="polite"
      data-realtime={state}
      title={LABELS[state].hint}
    >
      <span className={cn("size-2 rounded-full", LABELS[state].dot)} aria-hidden />
      {LABELS[state].text}
    </span>
  );
}
