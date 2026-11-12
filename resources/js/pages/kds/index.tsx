import { Head, Link, router } from "@inertiajs/react";
import {
  BanknoteIcon,
  BellRingIcon,
  BikeIcon,
  CheckCheckIcon,
  ChefHatIcon,
  InboxIcon,
  Loader2Icon,
  MaximizeIcon,
  MessageCircleIcon,
  MinimizeIcon,
  PlayIcon,
  TriangleAlertIcon,
  Undo2Icon,
  Volume2Icon,
  WifiOffIcon,
} from "lucide-react";
import { useEffect, useRef, useState, useSyncExternalStore } from "react";

import { ConnectionBadge, useOnline } from "@/components/orders/live-refresh";
import { Button, buttonVariants } from "@/components/ui/button";
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
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";
import { whatsappReadyLink } from "@/lib/whatsapp";

type KdsOrder = {
  id: string;
  orderNumber: string | null;
  status: OrderStatus;
  type: OrderType;
  channel: OrderChannel;
  customerName: string | null;
  customerPhone?: string | null;
  customerDepartment?: string | null;
  tableNumber: string | null;
  locationNote: string | null;
  notes: string | null;
  paymentStatus: string;
  /** Bayar saat diantar: masuk antrean sebelum lunas, uang diterima barista di meja. */
  payOnDelivery: boolean;
  total: number;
  paidAt: string | null;
  queuedAt: string | null;
  preparingAt: string | null;
  readyAt: string | null;
  completedAt: string | null;
  lines: OrderLine[];
};

type AwaitingOrder = {
  id: string;
  reference: string;
  channel: OrderChannel;
  type: OrderType;
  customerName: string | null;
  customerDepartment: string | null;
  tableNumber: string | null;
  locationNote: string | null;
  total: number;
  createdAt: string;
};

type Props = {
  outlet: { id: string; name: string };
  outlets: { id: string; name: string }[];
  orders: KdsOrder[];
  /** Pesanan pelanggan menunggu dibayar di kasir (ambil di counter tunai, QRIS). */
  awaiting?: AwaitingOrder[];
  canUpdate: boolean;
  canOverride: boolean;
  canTakePayment: boolean;
  topic: string;
};

type ActiveStatus = "queued" | "preparing" | "ready";
const ACTIVE: { status: ActiveStatus; accent: string }[] = [
  { status: "queued", accent: "border-t-muted-foreground" },
  { status: "preparing", accent: "border-t-info" },
  { status: "ready", accent: "border-t-success" },
];
const TABS: OrderStatus[] = ["queued", "preparing", "ready", "completed"];

// Alur kasir (keputusan owner 12 November 2026): pesanan baru diterima di kasir dulu, baru dibuat;
// pesanan antar ke meja → "Antar sekarang" (pelanggan dikabari), lalu "Sudah diantar".
const NEXT: Partial<Record<OrderStatus, { to: OrderStatus; label: string; icon: React.ReactNode; variant: "espresso" | "success" | "default" }>> = {
  queued: { to: "preparing", label: "Terima pesanan", icon: <PlayIcon />, variant: "espresso" },
  preparing: { to: "ready", label: "Siap diambil", icon: <ChefHatIcon />, variant: "success" },
  ready: { to: "completed", label: "Sudah diambil", icon: <CheckCheckIcon />, variant: "default" },
};
const COLUMN_LABELS: Record<ActiveStatus | "completed", string> = {
  queued: "Pesanan baru",
  preparing: "Sedang dibuat",
  ready: "Siap / diantar",
  completed: "Selesai",
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

/**
 * Alarm pesanan baru untuk perangkat kasir: bunyi keras berulang, getar, judul tab berkedip, dan
 * notifikasi sistem sampai kasir menekan "Lihat pesanan". Browser mewajibkan satu sentuhan untuk
 * mengizinkan suara, jadi tombol "Aktifkan suara" tampil sampai ditekan. Layar dijaga tetap menyala.
 */
type AlarmItem = { id: string; label: string; detail: string };

function useNewOrderAlarm(items: AlarmItem[], enabled: boolean) {
  const seen = useRef<Set<string> | null>(null);
  const audio = useRef<AudioContext | null>(null);
  const [fresh, setFresh] = useState<AlarmItem[]>([]);
  const [armed, setArmed] = useState(false);

  useEffect(() => {
    if (seen.current === null) {
      seen.current = new Set(items.map((o) => o.id));
      return;
    }
    const known = seen.current;
    const added = items.filter((o) => !known.has(o.id));
    added.forEach((o) => known.add(o.id));
    if (enabled && added.length) setFresh((current) => [...current, ...added]);
  }, [items, enabled]);

  useEffect(() => {
    if (fresh.length === 0) return;
    const title = document.title;
    let tick = 0;
    const ring = () => {
      const ctx = audio.current;
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

  // Layar perangkat kasir tetap menyala selama halaman ini terbuka.
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
      audio.current ??= AudioCtx ? new AudioCtx() : null;
      void audio.current?.resume();
    } catch {
      audio.current = null;
    }
    if ("Notification" in window && Notification.permission === "default") void Notification.requestPermission();
    setArmed(true);
  };

  return { fresh, armed, arm, acknowledge: () => setFresh([]) };
}

/** Pesanan masuk (perangkat kasir). Pesanan lunas atau bayar-saat-diantar; urut waktu masuk antrean. */
export default function KdsPage({ outlet, outlets, orders, awaiting = [], canUpdate, canOverride, canTakePayment, topic }: Props) {
  const now = useNow();
  const deskLabel = (channel: string, type: string) => (channel === "qr_table" && type === "dine_in" ? "Antar ke meja" : "Ambil di counter");
  // Alarm: pesanan baru di antrean + pesanan yang menunggu dibayar di kasir.
  const alarmItems: AlarmItem[] = [
    // Pesanan pelanggan saja (pesanan POS diinput kasir sendiri); id sama dengan bagian "menunggu bayar"
    // agar pesanan yang baru dibayar di kasir tidak membunyikan alarm lagi.
    ...orders.filter((o) => o.status === "queued" && o.channel !== "pos").map((o) => ({ id: o.id, label: o.orderNumber ?? "", detail: `${o.customerName ?? "Tanpa nama"} · ${deskLabel(o.channel, o.type)}` })),
    ...awaiting.map((o) => ({ id: o.id, label: o.reference, detail: `${o.customerName ?? "Tanpa nama"} · menunggu bayar · ${formatRupiah(o.total)}` })),
  ];
  const alarm = useNewOrderAlarm(alarmItems, canUpdate);
  // Polling cadangan lebih cepat di KDS: antrean berubah terus saat jam ramai.
  const realtime = useRealtimeRefresh(topic, ["orders", "awaiting"], 5_000);
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
      <Head title="Pesanan masuk" />
      <main className="flex flex-1 flex-col gap-3 p-3 sm:p-4">
        {canUpdate && !alarm.armed ? (
          <button
            type="button"
            onClick={alarm.arm}
            className="flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-brand bg-brand-soft px-4 text-base font-semibold text-brand-strong"
            data-testid="alarm-arm"
          >
            <Volume2Icon className="size-5" aria-hidden /> Ketuk untuk mengaktifkan suara & notifikasi pesanan baru
          </button>
        ) : null}
        {alarm.fresh.length > 0 ? (
          <div role="alert" className="sticky top-2 z-30 flex flex-col gap-3 rounded-2xl bg-destructive p-4 text-white shadow-2xl motion-safe:animate-pulse sm:flex-row sm:items-center" data-testid="new-order-alert">
            <BellRingIcon className="size-10 shrink-0" aria-hidden />
            <div className="min-w-0 flex-1">
              <p className="text-2xl font-black tracking-wide uppercase">{alarm.fresh.length} pesanan baru!</p>
              <p className="truncate text-sm font-medium">{alarm.fresh.map((o) => `${o.label} ${o.detail}`.trim()).join(" · ")}</p>
            </div>
            <Button
              size="xl"
              variant="outline"
              className="bg-white text-destructive-strong"
              onClick={() => {
                alarm.acknowledge();
                setTab("queued");
              }}
            >
              Lihat pesanan
            </Button>
          </div>
        ) : null}
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span className="font-semibold">{outlet.name}</span>
            <span className="text-muted-foreground">Urut sesuai waktu masuk antrean</span>
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

        {awaiting.length > 0 ? (
          <section aria-label="Menunggu bayar" className="flex flex-col gap-2 rounded-xl border-2 border-warning bg-warning/10 p-3" data-testid="awaiting-payment">
            <h2 className="flex items-center justify-between text-sm font-bold tracking-wide uppercase">
              Menunggu bayar di kasir
              <span className="rounded-full bg-background px-2.5 py-0.5 tabular-nums">{awaiting.length}</span>
            </h2>
            <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
              {awaiting.map((o) => (
                <li key={o.id} className="flex min-w-0 items-center gap-3 rounded-lg border bg-background p-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-mono text-lg font-bold">{o.reference}</p>
                    <p className="truncate text-sm">
                      {o.customerName ?? NO_NAME_LABEL}
                      {o.customerDepartment ? ` · ${o.customerDepartment}` : ""}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {deskLabel(o.channel, o.type)}
                      {o.tableNumber ? ` · Meja ${o.tableNumber}` : ""} · {formatRupiah(o.total)}
                    </p>
                  </div>
                  <Link href={`/pos/orders/${o.id}`} className={cn(buttonVariants({ variant: "success", size: "lg" }), "shrink-0")}>
                    <BanknoteIcon /> Proses bayar
                  </Link>
                </li>
              ))}
            </ul>
          </section>
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
                {COLUMN_LABELS[status as ActiveStatus | "completed"]}
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
                aria-label={COLUMN_LABELS[column.status]}
                className={cn("min-h-64 flex-col gap-3 rounded-xl border border-t-4 bg-muted/40 p-3", column.accent, tab === column.status ? "flex" : "hidden lg:flex")}
              >
                <h2 className="flex items-center justify-between text-lg font-bold tracking-wide uppercase">
                  {COLUMN_LABELS[column.status]}
                  <span className="rounded-full bg-background px-2.5 py-0.5 text-sm tabular-nums">{items.length}</span>
                </h2>
                {items.length === 0 ? (
                  <p className="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                    <InboxIcon className="size-6" aria-hidden />
                    Kosong
                  </p>
                ) : (
                  items.map((order) => <OrderCard key={order.id} order={order} now={now} canUpdate={canUpdate} canOverride={canOverride} canTakePayment={canTakePayment} />)
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

function OrderCard({
  order,
  now,
  canUpdate,
  canOverride,
  canTakePayment,
}: {
  order: KdsOrder;
  now: number;
  canUpdate: boolean;
  canOverride: boolean;
  canTakePayment: boolean;
}) {
  const [pending, setPending] = useState(false);
  const wait = elapsed(order.queuedAt ?? order.paidAt, now);
  const desk = order.channel === "qr_table" && order.type === "dine_in";
  // Bayar saat diantar dan uang belum diterima: tidak bisa ditutup sebelum dibayar.
  const unpaid = order.payOnDelivery && order.paymentStatus === "pending";
  const stageStart = order.status === "preparing" ? order.preparingAt : order.status === "ready" ? order.readyAt : null;
  const stage = elapsed(stageStart, now);
  const step = NEXT[order.status];
  const next =
    step && desk
      ? step.to === "ready"
        ? { ...step, label: "Antar sekarang", icon: <BikeIcon /> }
        : step.to === "completed"
          ? { ...step, label: "Sudah diantar" }
          : step
      : step;
  const blockedByPayment = unpaid && next?.to === "completed";
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
            <span className={order.channel === "qr_table" && order.type === "dine_in" ? "rounded-md bg-brand px-1.5 py-0.5 text-xs font-bold text-brand-foreground" : "font-medium"}>
              {order.channel === "qr_table" && order.type === "dine_in" ? "ANTAR KE MEJA" : ORDER_TYPE_LABELS[order.type]}
            </span>
            {order.tableNumber ? <span className="font-semibold">· Meja {order.tableNumber}</span> : null}
          </p>
          <p className="text-sm break-words text-muted-foreground">
            {order.customerName ?? NO_NAME_LABEL}
            {order.customerDepartment ? <span className="font-semibold text-foreground"> · {order.customerDepartment}</span> : null}
          </p>
          {order.locationNote ? <p className="text-sm text-muted-foreground">{order.locationNote}</p> : null}
          {unpaid ? (
            <p className="mt-1 inline-flex rounded-md bg-warning px-1.5 py-0.5 text-xs font-bold text-warning-foreground" data-testid="kds-unpaid">
              BELUM BAYAR · {formatRupiah(order.total)} · tunai saat diantar
            </p>
          ) : order.payOnDelivery ? (
            <p className="mt-1 inline-flex rounded-md bg-success px-1.5 py-0.5 text-xs font-bold text-success-foreground">LUNAS</p>
          ) : null}
          {order.status === "ready" ? <WhatsAppReady order={order} cashDue={unpaid ? formatRupiah(order.total) : null} /> : null}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <span
            className={cn(
              "rounded-lg px-2 py-1 font-mono text-xl font-bold tabular-nums",
              isLate ? "bg-destructive text-white" : isWarn ? "bg-warning text-warning-foreground" : "bg-muted",
            )}
            aria-label={`Menunggu sejak masuk antrean ${wait.text}`}
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
          {blockedByPayment ? null : (
            <Button variant={next.variant} size="xl" className="flex-1" disabled={pending} onClick={() => move(next.to)}>
              {pending ? <Loader2Icon className="animate-spin" /> : next.icon}
              {next.label}
            </Button>
          )}
        </div>
      ) : null}
      {unpaid ? (
        canTakePayment ? (
          // Form uang diterima + kembalian memakai halaman detail pesanan (sama dengan kasir).
          <Link
            href={`/pos/orders/${order.id}`}
            className={cn(buttonVariants({ variant: blockedByPayment ? "success" : "outline", size: "xl" }), "w-full")}
          >
            <BanknoteIcon /> Terima pembayaran · {formatRupiah(order.total)}
          </Link>
        ) : (
          <p className="rounded-lg bg-warning/15 px-3 py-2 text-sm font-medium text-warning-strong">Uang diterima kasir sebelum pesanan ditutup.</p>
        )
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

/**
 * Pesanan diantar/siap: kabari pelanggan lewat WhatsApp perangkat kasir dengan 1 ketukan (pesan siap
 * kirim, termasuk lokasi dan pengingat uang tunai). Tanpa nomor HP, pelanggan dikabari lewat halaman
 * status pesanan (notifikasi web).
 */
function WhatsAppReady({
  order,
  cashDue,
}: {
  order: { orderNumber: string | null; customerName: string | null; customerPhone?: string | null; channel: string; type: string; locationNote: string | null };
  cashDue: string | null;
}) {
  const href = whatsappReadyLink(order.customerPhone, {
    orderNumber: order.orderNumber,
    customerName: order.customerName,
    deskDelivery: order.channel === "qr_table" && order.type === "dine_in",
    locationNote: order.locationNote,
    cashDue,
  });
  if (!href) {
    return <p className="mt-1 text-xs text-muted-foreground">Tanpa nomor WhatsApp: pelanggan dikabari lewat halaman status pesanan.</p>;
  }
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener"
      className="mt-2 flex min-h-11 items-center justify-center gap-2 rounded-lg bg-success px-3 text-sm font-semibold text-success-foreground"
      data-testid="whatsapp-notify"
    >
      <MessageCircleIcon className="size-4" aria-hidden /> Kirim notif WhatsApp
    </a>
  );
}
