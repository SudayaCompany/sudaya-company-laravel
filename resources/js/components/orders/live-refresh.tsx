import { useRealtimeRefresh } from "@/hooks/use-realtime-refresh";
import { cn } from "@/lib/utils";

/** Indikator + langganan sinyal realtime untuk halaman staf. */
export function LiveRefresh({ topic, only, pollMs, className }: { topic: string; only: string[]; pollMs?: number; className?: string }) {
  const state = useRealtimeRefresh(topic, only, pollMs);
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs text-muted-foreground", className)} aria-live="polite" data-realtime={state}>
      <span className={cn("size-2 rounded-full", state === "live" ? "bg-success" : "animate-pulse bg-brand")} aria-hidden />
      {state === "live" ? "Realtime" : state === "polling" ? "Polling" : "Menyambung…"}
    </span>
  );
}
