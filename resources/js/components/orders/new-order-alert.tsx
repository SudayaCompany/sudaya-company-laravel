import { BellRingIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import type { AlarmItem } from "@/hooks/use-order-alarm";
import { cn } from "@/lib/utils";

/** Banner merah "PESANAN BARU" (Pesanan masuk dan semua halaman kasir) sampai kasir menanggapinya. */
export function NewOrderAlert({ items, onView, onLater, className }: { items: AlarmItem[]; onView: () => void; onLater?: () => void; className?: string }) {
  return (
    <div
      role="alert"
      className={cn("flex flex-col gap-3 rounded-2xl bg-destructive p-4 text-white shadow-2xl motion-safe:animate-pulse sm:flex-row sm:items-center", className)}
      data-testid="new-order-alert"
    >
      <BellRingIcon className="size-10 shrink-0" aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="text-2xl font-black tracking-wide uppercase">{items.length} pesanan baru!</p>
        <p className="truncate text-sm font-medium">{items.map((o) => `${o.label} ${o.detail}`.trim()).join(" · ")}</p>
      </div>
      <div className="flex shrink-0 gap-2">
        {onLater ? (
          <Button size="xl" variant="ghost" className="flex-1 text-white hover:bg-white/15 hover:text-white" onClick={onLater}>
            Nanti
          </Button>
        ) : null}
        <Button size="xl" variant="outline" className="flex-1 bg-white text-destructive-strong" onClick={onView}>
          Lihat pesanan
        </Button>
      </div>
    </div>
  );
}
