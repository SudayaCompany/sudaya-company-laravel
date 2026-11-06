import { Head, router } from "@inertiajs/react";
import { CheckCheckIcon, ChefHatIcon, InboxIcon, Loader2Icon, MaximizeIcon, MinimizeIcon, PlayIcon, TriangleAlertIcon, Undo2Icon, WifiOffIcon } from "lucide-react";
import { useEffect, useState, useSyncExternalStore } from "react";

import { ConnectionBadge, useOnline } from "@/components/orders/live-refresh";
import { Button } from "@/components/ui/button";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { useRealtimeRefresh } from "@/hooks/use-realtime-refresh";
import { PosLayout } from "@/layouts/pos-layout";
import {
  CHANNEL_LABELS,
  NO_NAME_LABEL,
  ORDER_STATUS_LABELS,
  ORDER_TYPE_LABELS,
  type OrderChannel,
  type OrderLine,
  type OrderStatus,
  type OrderType,
} from "@/lib/orders";
import { cn } from "@/lib/utils";

type KdsOrder = {
  id: string;
  orderNumber: string | null;
  status: OrderStatus;
  type: OrderType;
  channel: OrderChannel;
  customerName: string | null;
  tableNumber: string | null;
  locationNote: string | null;
  notes: string | null;
  paidAt: string | null;
  preparingAt: string | null;
  readyAt: string | null;
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

type ActiveStatus = "queued" | "preparing" | "ready";
const ACTIVE: { status: ActiveStatus; accent: string }[] = [
  { status: "queued", accent: "border-t-muted-foreground" },
  { status: "preparing", accent: "border-t-info" },
  { status: "ready", accent: "border-t-success" },
];
const TABS: OrderStatus[] = ["queued", "preparing", "ready", "completed"];

const NEXT: Partial<Record<OrderStatus, { to: OrderStatus; label: string; icon: React.ReactNode; variant: "espresso" | "success" | "default" }>> = {
  queued: { to: "preparing", label: "Mulai buat", icon: <PlayIcon />, variant: "espresso" },
  preparing: { to: "ready", label: "Tandai siap", icon: <ChefHatIcon />, variant: "success" },
  ready: { to: "completed", label: "Sudah diambil", icon: <CheckCheckIcon />, variant: "default" },
};
const BACK: Partial<Record<OrderStatus, OrderStatus>> = { preparing: "queued", ready: "preparing" };

/** Batas waktu tunggu sejak bayar (menit): kuning = perhatikan, merah = terlambat. */
const WARN_MINUTES = 5;
const LATE_MINUTES = 10;

function useNow() {
  const [now, setNow] = useState(0);
  useEffect(() => {
    setNow(Date.now());
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  return now;
}

function elapsed(fromIso: string | null, until: number) {
  if (!fromIso || until === 0) return { text: "--:--", minutes: 0 };
  const seconds = Math.max(0, Math.floor((until - new Date(fromIso).getTime()) / 1000));
  const m = Math.floor(seconds / 60);
  return { text: `${String(m).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`, minutes: m };
}

function subscribeFullscreen(callback: () => void) {
  document.addEventListener("fullscreenchange", callback);
  return () => document.removeEventListener("fullscreenchange", callback);
}

/** Barista Display. Hanya order lunas yang tampil; urutan FIFO berdasarkan waktu bayar. */
export default function KdsPage({ outlet, outlets, orders, canUpdate, canOverride, topic }: Props) {
  const now = useNow();
  // Polling cadangan lebih cepat di KDS: antrean berubah terus saat jam ramai.
  const realtime = useRealtimeRefresh(topic, ["orders"], 5_000);
  const online = useOnline();
  const [tab, setTab] = useState<OrderStatus>("queued");
  const [updatedAt, setUpdatedAt] = useState<number | null>(null);
  const fullscreen = useSyncExternalStore(subscribeFullscreen, () => document.fullscreenElement !== null, () => false);
  useEffect(() => setUpdatedAt(Date.now()), [orders]);

  const byStatus = (status: OrderStatus) => orders.filter((o) => o.status === status);
  const late = orders.filter((o) => o.status !== "completed" && elapsed(o.paidAt, now).minutes >= LATE_MINUTES).length;
  const clock = new Intl.DateTimeFormat("id-ID", { timeStyle: "short" });

  return (
    <PosLayout outletId={outlet.id}>
      <Head title="Barista display" />
      <main className="flex flex-1 flex-col gap-3 p-3 sm:p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span className="font-semibold">{outlet.name}</span>
            <span className="text-muted-foreground">Urut sesuai waktu bayar</span>
            {late ? (
              <span className="inline-flex items-center gap-1 rounded-full bg-destructive px-2.5 py-0.5 text-xs font-semibold text-white">
                <TriangleAlertIcon className="size-3.5" aria-hidden /> {late} terlambat
              </span>
            ) : null}
          </div>
          <div className="flex items-center gap-2">
            {outlets.length > 1 ? (
              <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/kds?outlet=${e.target.value}`)}>
                {outlets.map((o) => (
                  <NativeSelectOption key={o.id} value={o.id}>
                    {o.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            ) : null}
            <span className="flex items-center gap-2 rounded-full border bg-background px-3 py-1.5">
              <ConnectionBadge state={realtime} online={online} className="text-sm font-medium text-foreground" />
              {updatedAt ? <span className="text-xs text-muted-foreground">· {clock.format(updatedAt)}</span> : null}
            </span>
            {typeof document !== "undefined" && document.fullscreenEnabled ? (
              <Button
                variant="outline"
                size="icon"
                onClick={() => (fullscreen ? document.exitFullscreen() : document.documentElement.requestFullscreen())}
                aria-label={fullscreen ? "Keluar layar penuh" : "Layar penuh"}
              >
                {fullscreen ? <MinimizeIcon /> : <MaximizeIcon />}
              </Button>
            ) : null}
          </div>
        </div>

        {!online || realtime === "polling" ? (
          <p role="status" className="flex items-center gap-2 rounded-lg border border-warning/50 bg-warning/15 px-3 py-2 text-sm text-warning-strong">
            <WifiOffIcon className="size-4 shrink-0" aria-hidden />
            {online
              ? "Koneksi langsung terputus. Antrean tetap diperbarui otomatis setiap 5 detik; sambungan langsung dicoba lagi."
              : "Tablet tidak terhubung ke internet. Periksa Wi-Fi; antrean diperbarui lagi saat koneksi kembali."}
          </p>
        ) : null}

        {/* Layar < 1024 px: tab per status, satu kolom terlihat. */}
        <div role="tablist" aria-label="Status pesanan" className="grid grid-cols-4 gap-1 rounded-xl bg-muted p-1 lg:hidden">
          {TABS.map((status) => {
            const count = byStatus(status).length;
            return (
              <button
                key={status}
                type="button"
                role="tab"
                aria-selected={tab === status}
                onClick={() => setTab(status)}
                className={cn(
                  "flex min-h-11 flex-col items-center justify-center rounded-lg px-1 text-sm font-semibold transition-colors sm:flex-row sm:gap-1.5",
                  tab === status ? "bg-background shadow-sm" : "text-muted-foreground",
                )}
              >
                {ORDER_STATUS_LABELS[status]}
                <span className="tabular-nums">{count}</span>
              </button>
            );
          })}
        </div>

        {/* Layar ≥ 1024 px (tablet landscape): tiga kolom kerja; Selesai menjadi strip ringkas di bawah. */}
        <div className="grid flex-1 gap-3 lg:grid-cols-3">
          {ACTIVE.map((column) => {
            const items = byStatus(column.status);
            return (
              <section
                key={column.status}
                aria-label={ORDER_STATUS_LABELS[column.status]}
                className={cn("min-h-64 flex-col gap-3 rounded-xl border border-t-4 bg-muted/40 p-3", column.accent, tab === column.status ? "flex" : "hidden lg:flex")}
              >
                <h2 className="flex items-center justify-between text-lg font-bold tracking-wide uppercase">
                  {ORDER_STATUS_LABELS[column.status]}
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

        <CompletedStrip orders={byStatus("completed")} active={tab === "completed"} />
      </main>
    </PosLayout>
  );
}

function OrderCard({ order, now, canUpdate, canOverride }: { order: KdsOrder; now: number; canUpdate: boolean; canOverride: boolean }) {
  const [pending, setPending] = useState(false);
  const wait = elapsed(order.paidAt, now);
  const stageStart = order.status === "preparing" ? order.preparingAt : order.status === "ready" ? order.readyAt : null;
  const stage = elapsed(stageStart, now);
  const next = NEXT[order.status];
  const back = BACK[order.status];
  const isLate = wait.minutes >= LATE_MINUTES;
  const isWarn = !isLate && wait.minutes >= WARN_MINUTES;
  // `from` = status yang dilihat barista; klik ganda tidak menggeser dua langkah.
  const move = (to: OrderStatus) =>
    router.post(`/kds/orders/${order.id}/status`, { from: order.status, to }, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) });

  return (
    <article
      data-order={order.orderNumber ?? ""}
      className={cn("flex flex-col gap-3 rounded-xl border-2 bg-background p-3 shadow-sm", isLate ? "border-destructive" : isWarn ? "border-warning" : "border-transparent")}
    >
      <header className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-mono text-4xl leading-none font-black">{order.orderNumber}</p>
          <p className="mt-1.5 flex flex-wrap items-center gap-1.5 text-sm">
            <span className="rounded-md bg-secondary px-1.5 py-0.5 text-xs font-semibold">{CHANNEL_LABELS[order.channel]}</span>
            <span className="font-medium">{ORDER_TYPE_LABELS[order.type]}</span>
            {order.tableNumber ? <span className="font-semibold">· Meja {order.tableNumber}</span> : null}
          </p>
          <p className="text-sm break-words text-muted-foreground">{order.customerName ?? NO_NAME_LABEL}</p>
          {order.locationNote ? <p className="text-sm text-muted-foreground">{order.locationNote}</p> : null}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <span
            className={cn(
              "rounded-lg px-2 py-1 font-mono text-xl font-bold tabular-nums",
              isLate ? "bg-destructive text-white" : isWarn ? "bg-warning text-warning-foreground" : "bg-muted",
            )}
            aria-label={`Menunggu sejak dibayar ${wait.text}`}
          >
            {wait.text}
          </span>
          {isLate ? <span className="text-xs font-bold text-destructive-strong uppercase">Terlambat</span> : isWarn ? <span className="text-xs font-semibold text-warning-strong">Perhatikan</span> : null}
          {stageStart ? (
            <span className="text-xs text-muted-foreground tabular-nums">
              {order.status === "preparing" ? "dibuat" : "siap"} {stage.text}
            </span>
          ) : null}
        </div>
      </header>

      <ul className="flex flex-col gap-2 border-t pt-2">
        {order.lines.map((line) => (
          <li key={line.id}>
            <p className="flex items-baseline gap-2 break-words">
              <span className="font-mono text-xl font-black tabular-nums">{line.quantity}×</span>
              <span className="text-lg leading-snug font-semibold">{line.productName}</span>
            </p>
            {line.modifiers.length ? <p className="pl-9 text-base font-medium text-foreground/80">{line.modifiers.map((m) => m.name).join(" · ")}</p> : null}
            {line.notes ? <p className="mt-1 ml-9 rounded-md bg-brand-soft px-2 py-1 text-base font-semibold break-words text-brand-strong">“{line.notes}”</p> : null}
          </li>
        ))}
      </ul>
      {order.notes ? (
        <p className="rounded-lg bg-brand-soft px-3 py-2 text-base break-words text-brand-strong">
          <span className="font-bold">Catatan pesanan:</span> {order.notes}
        </p>
      ) : null}

      {canUpdate && next ? (
        <div className="mt-auto flex gap-2">
          {canOverride && back ? (
            <Button variant="outline" size="xl" className="aspect-square px-0" disabled={pending} onClick={() => move(back)} aria-label="Kembalikan ke status sebelumnya">
              <Undo2Icon />
            </Button>
          ) : null}
          <Button variant={next.variant} size="xl" className="flex-1" disabled={pending} onClick={() => move(next.to)}>
            {pending ? <Loader2Icon className="animate-spin" /> : next.icon}
            {next.label}
          </Button>
        </div>
      ) : null}
    </article>
  );
}

/** Pesanan selesai (3 jam terakhir, maks 12): ringkas agar kolom kerja tetap lebar. */
function CompletedStrip({ orders, active }: { orders: KdsOrder[]; active: boolean }) {
  const time = new Intl.DateTimeFormat("id-ID", { timeStyle: "short" });
  return (
    <section aria-label={ORDER_STATUS_LABELS.completed} className={cn("flex-col gap-2 rounded-xl border bg-muted/40 p-3", active ? "flex" : "hidden lg:flex")}>
      <h2 className="flex items-center justify-between text-sm font-bold tracking-wide uppercase">
        {ORDER_STATUS_LABELS.completed}
        <span className="rounded-full bg-background px-2.5 py-0.5 tabular-nums">{orders.length}</span>
      </h2>
      {orders.length === 0 ? (
        <p className="text-sm text-muted-foreground">Belum ada pesanan selesai.</p>
      ) : (
        <div className="flex flex-col gap-2 lg:flex-row lg:overflow-x-auto lg:pb-1">
          {orders.map((order) => (
            <article key={order.id} data-order={order.orderNumber ?? ""} className="flex shrink-0 items-center gap-3 rounded-lg border bg-background px-3 py-2 opacity-80 lg:min-w-52">
              <span className="font-mono text-lg font-bold">{order.orderNumber}</span>
              <span className="min-w-0 text-sm">
                <span className="block truncate">{order.customerName ?? NO_NAME_LABEL}</span>
                <span className="block text-xs text-muted-foreground">selesai {order.completedAt ? time.format(new Date(order.completedAt)) : "—"}</span>
              </span>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
