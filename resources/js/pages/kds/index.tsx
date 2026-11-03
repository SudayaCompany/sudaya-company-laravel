import { Head, router } from "@inertiajs/react";
import { CheckCheckIcon, ChefHatIcon, InboxIcon, Loader2Icon, PlayIcon, Undo2Icon } from "lucide-react";
import { useEffect, useState } from "react";

import { LiveRefresh } from "@/components/orders/live-refresh";
import { Button } from "@/components/ui/button";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { PosLayout } from "@/layouts/pos-layout";
import { ORDER_TYPE_LABELS, type OrderLine, type OrderStatus, type OrderType } from "@/lib/orders";
import { cn } from "@/lib/utils";

type KdsOrder = {
  id: string;
  orderNumber: string | null;
  status: OrderStatus;
  type: OrderType;
  customerName: string | null;
  tableNumber: string | null;
  locationNote: string | null;
  notes: string | null;
  paidAt: string | null;
  completedAt: string | null;
  lines: OrderLine[];
};

type Props = {
  outlet: { id: string; name: string };
  outlets: { id: string; name: string }[];
  orders: KdsOrder[];
  canUpdate: boolean;
  canOverride: boolean;
  topic: string;
};

const COLUMNS: { status: OrderStatus; title: string; accent: string }[] = [
  { status: "queued", title: "New", accent: "border-t-sky-500" },
  { status: "preparing", title: "Preparing", accent: "border-t-amber-500" },
  { status: "ready", title: "Ready", accent: "border-t-success" },
  { status: "completed", title: "Completed", accent: "border-t-neutral-400" },
];

const NEXT: Partial<Record<OrderStatus, { to: OrderStatus; label: string; icon: React.ReactNode }>> = {
  queued: { to: "preparing", label: "Start Preparing", icon: <PlayIcon /> },
  preparing: { to: "ready", label: "Mark Ready", icon: <ChefHatIcon /> },
  ready: { to: "completed", label: "Complete", icon: <CheckCheckIcon /> },
};

const BACK: Partial<Record<OrderStatus, OrderStatus>> = { preparing: "queued", ready: "preparing" };

function useNow() {
  const [now, setNow] = useState(0);
  useEffect(() => {
    setNow(Date.now());
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  return now;
}

function elapsed(fromIso: string | null, now: number) {
  if (!fromIso || now === 0) return { text: "--:--", minutes: 0 };
  const seconds = Math.max(0, Math.floor((now - new Date(fromIso).getTime()) / 1000));
  const m = Math.floor(seconds / 60);
  return { text: `${String(m).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`, minutes: m };
}

/** Barista Display. Hanya order lunas yang tampil; urutan FIFO berdasarkan waktu bayar. */
export default function KdsPage({ outlet, outlets, orders, canUpdate, canOverride, topic }: Props) {
  const now = useNow();

  return (
    <PosLayout outletId={outlet.id}>
      <Head title="Barista Display" />
      <main className="flex flex-1 flex-col gap-4 p-4">
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
          <span>{outlet.name} · Antrean FIFO berdasarkan waktu bayar</span>
          <span className="flex items-center gap-3">
            {outlets.length > 1 ? (
              <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/kds?outlet=${e.target.value}`)}>
                {outlets.map((o) => (
                  <NativeSelectOption key={o.id} value={o.id}>
                    {o.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            ) : null}
            {/* Polling cadangan lebih cepat di KDS: antrean berubah terus saat jam ramai. */}
            <LiveRefresh topic={topic} only={["orders"]} pollMs={5_000} />
          </span>
        </div>
        <div className="grid flex-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
          {COLUMNS.map((column) => {
            const items = orders.filter((o) => o.status === column.status);
            return (
              <section key={column.status} aria-label={column.title} className={cn("flex min-h-64 flex-col gap-3 rounded-xl border border-t-4 bg-muted/40 p-3", column.accent)}>
                <h2 className="flex items-center justify-between text-lg font-bold tracking-wide uppercase">
                  {column.title}
                  <span className="rounded-full bg-background px-2.5 py-0.5 text-sm tabular-nums">{items.length}</span>
                </h2>
                {items.length === 0 ? (
                  <p className="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                    <InboxIcon className="size-6" aria-hidden />
                    Kosong
                  </p>
                ) : (
                  items.map((order) => <OrderCard key={order.id} order={order} now={now} canUpdate={canUpdate} canOverride={canOverride} />)
                )}
              </section>
            );
          })}
        </div>
      </main>
    </PosLayout>
  );
}

function OrderCard({ order, now, canUpdate, canOverride }: { order: KdsOrder; now: number; canUpdate: boolean; canOverride: boolean }) {
  const [pending, setPending] = useState(false);
  const done = order.status === "completed";
  const wait = elapsed(order.paidAt, done ? new Date(order.completedAt ?? order.paidAt ?? 0).getTime() : now);
  const next = NEXT[order.status];
  const back = BACK[order.status];
  // `from` = status yang dilihat barista; klik ganda tidak menggeser dua langkah.
  const move = (to: OrderStatus) =>
    router.post(`/kds/orders/${order.id}/status`, { from: order.status, to }, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) });

  return (
    <article
      data-order={order.orderNumber ?? ""}
      className={cn(
        "flex flex-col gap-2 rounded-xl border bg-background p-3 shadow-sm",
        !done && wait.minutes >= 10 && "border-destructive ring-2 ring-destructive/30",
        !done && wait.minutes >= 5 && wait.minutes < 10 && "border-amber-500",
        done && "opacity-70",
      )}
    >
      <header className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-mono text-2xl font-black">{order.orderNumber}</p>
          <p className="text-sm font-medium break-words">
            {order.customerName ?? "Walk-in"} — {ORDER_TYPE_LABELS[order.type]}
            {order.tableNumber ? ` · Meja ${order.tableNumber}` : ""}
          </p>
          {order.locationNote ? <p className="text-sm text-muted-foreground">{order.locationNote}</p> : null}
        </div>
        <span
          className={cn(
            "shrink-0 rounded-lg px-2 py-1 font-mono text-lg font-bold tabular-nums",
            done ? "bg-muted" : wait.minutes >= 10 ? "bg-destructive text-white" : wait.minutes >= 5 ? "bg-amber-500 text-white" : "bg-muted",
          )}
          aria-label={`Menunggu ${wait.text}`}
        >
          {wait.text}
        </span>
      </header>

      <ul className="flex flex-col gap-1.5 border-t pt-2">
        {order.lines.map((line) => (
          <li key={line.id}>
            <p className="text-base font-semibold break-words">
              {line.productName} × {line.quantity}
            </p>
            {line.modifiers.map((m) => (
              <p key={`${line.id}-${m.groupName}-${m.name}`} className="pl-3 text-sm text-muted-foreground">
                – {m.name}
              </p>
            ))}
            {line.notes ? <p className="pl-3 text-sm font-medium break-words text-brand">“{line.notes}”</p> : null}
          </li>
        ))}
      </ul>
      {order.notes ? (
        <p className="rounded-lg bg-brand/10 px-2 py-1.5 text-sm break-words">
          <span className="font-semibold">Catatan:</span> {order.notes}
        </p>
      ) : null}

      {canUpdate && next ? (
        <div className="flex gap-2">
          {canOverride && back ? (
            <Button variant="outline" size="lg" className="h-12" disabled={pending} onClick={() => move(back)} aria-label="Kembalikan status">
              <Undo2Icon />
            </Button>
          ) : null}
          <Button
            size="lg"
            className={cn("h-12 flex-1 text-base", order.status === "preparing" && "bg-success text-success-foreground hover:bg-success/90")}
            disabled={pending}
            onClick={() => move(next.to)}
          >
            {pending ? <Loader2Icon className="animate-spin" /> : next.icon}
            {next.label}
          </Button>
        </div>
      ) : null}
    </article>
  );
}
