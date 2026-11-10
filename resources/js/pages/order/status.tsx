import { Head, Link } from "@inertiajs/react";
import { CheckIcon, ClockIcon, CoffeeIcon, SearchXIcon, XCircleIcon } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { Brand } from "@/components/brand";
import { EmptyState } from "@/components/empty-state";
import { buttonVariants } from "@/components/ui/button";
import { useRealtimeRefresh } from "@/hooks/use-realtime-refresh";
import { formatPriceDelta, formatRupiah } from "@/lib/format";
import { customerStatusLabel, ORDER_TYPE_LABELS, PAYMENT_STATUS_LABELS, type OrderLine, type OrderStatus, type OrderType, type PaymentStatus } from "@/lib/orders";
import { cn } from "@/lib/utils";

type GuestOrder = {
  id: string;
  reference: string;
  orderNumber: string | null;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  type: OrderType;
  tableNumber: string | null;
  customerName: string | null;
  total: number;
  createdAt: string;
  expiresAt: string | null;
  outletName: string;
  outletCode: string;
  timezone: string;
  paymentMethod: string | null;
  subtotal?: number;
  deliveryFee?: number;
  locationNote?: string | null;
  qrisImageUrl?: string | null;
  lines: OrderLine[];
  topic: string;
};

const STEPS: { status: OrderStatus[]; label: string }[] = [
  { status: ["queued", "paid"], label: "Diterima" },
  { status: ["preparing"], label: "Dibuat" },
  { status: ["ready"], label: "Siap" },
  { status: ["completed"], label: "Selesai" },
];

export default function OrderStatusPage({ order }: { order: GuestOrder | null }) {
  return (
    <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-6 px-4 py-6">
      <Head title="Status pesanan">
        <meta name="robots" content="noindex, nofollow" />
      </Head>
      <Link href="/" className="w-fit rounded-md text-lg focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none">
        <Brand />
      </Link>
      {order ? (
        <OrderStatusView order={order} />
      ) : (
        <EmptyState
          icon={SearchXIcon}
          title="Pesanan tidak ditemukan"
          description="Status hanya bisa dibuka dari perangkat yang dipakai saat memesan. Tanyakan ke kasir bila perlu bantuan."
        />
      )}
    </div>
  );
}

function OrderStatusView({ order }: { order: GuestOrder }) {
  const unpaid = order.status === "pending_payment";
  const expired = order.status === "cancelled" && order.paymentStatus === "expired";
  const cancelled = order.status === "cancelled" || order.status === "refunded";
  const finished = order.status === "completed" || cancelled;
  const stepIndex = STEPS.findIndex((s) => s.status.includes(order.status));
  const timeFormat = new Intl.DateTimeFormat("id-ID", { timeStyle: "short", dateStyle: "medium", timeZone: order.timezone });
  const deadline = new Intl.DateTimeFormat("id-ID", { timeStyle: "short", timeZone: order.timezone });

  // Realtime selama pesanan belum selesai; polling 15 detik bila Realtime tidak tersambung.
  const connection = useRealtimeRefresh(finished ? null : order.topic, ["order"], 15_000);
  const ready = order.status === "ready";
  const deskDelivery = order.type === "dine_in" && !!order.tableNumber;
  useReadyAlert(order.status, deskDelivery);

  return (
    <>
      {ready ? (
        <div role="status" className="flex flex-col gap-1 rounded-3xl bg-success p-5 text-success-foreground" data-testid="ready-banner">
          <p className="text-xl font-bold">Pesanan Anda sudah siap!</p>
          <p className="text-sm">{deskDelivery ? "Barista sedang mengantar ke meja Anda." : "Silakan ambil di counter."}</p>
        </div>
      ) : null}
      {!finished ? <NotifyToggle /> : null}
      <section
        data-realtime={finished ? "idle" : connection}
        className={cn("flex flex-col gap-5 rounded-3xl p-6", cancelled ? "bg-destructive/10" : unpaid ? "bg-brand/10" : "bg-espresso text-white")}
      >
        {/* flex-wrap: di HP sempit badge turun ke baris berikutnya, nomor antrean tidak terpotong. */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className={cn("text-sm", unpaid || cancelled ? "text-muted-foreground" : "text-neutral-400")}>{order.orderNumber ? "Nomor antrean" : "Kode pesanan"}</p>
            <p className="font-mono text-5xl font-bold tracking-wider whitespace-nowrap">{order.orderNumber ?? order.reference}</p>
          </div>
          <span
            className={cn(
              "inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold",
              cancelled ? "bg-destructive text-white" : unpaid ? "bg-brand text-brand-foreground" : "bg-success text-success-foreground",
            )}
          >
            {cancelled ? <XCircleIcon className="size-4" /> : unpaid ? <ClockIcon className="size-4" /> : <CoffeeIcon className="size-4" />}
            {customerStatusLabel(order.status)}
          </span>
        </div>

        {unpaid ? (
          <div className="flex flex-col gap-2">
            {order.qrisImageUrl ? (
              <div className="flex flex-col items-center gap-3 rounded-2xl border bg-white p-4 text-center text-black">
                <p className="text-sm font-medium">Scan QRIS Sudaya lalu bayar tepat</p>
                <p className="text-2xl font-bold tabular-nums" data-testid="qris-amount">
                  {formatRupiah(order.total)}
                </p>
                <img src={order.qrisImageUrl} alt="QRIS pembayaran Sudaya" className="w-full max-w-64 rounded-lg" />
                <a href={order.qrisImageUrl} download target="_blank" rel="noopener" className={cn(buttonVariants({ variant: "outline" }), "min-h-11")}>
                  Simpan gambar QRIS
                </a>
                <p className="text-xs text-neutral-600">
                  Pesan dari HP yang sama? Simpan gambar lalu pilih "scan dari galeri" di aplikasi bank/e-wallet. Setelah bayar, kasir mengonfirmasi dan halaman ini berubah sendiri.
                  Kode pesanan <span className="font-mono font-semibold">{order.reference}</span>.
                </p>
              </div>
            ) : (
              <p className="text-sm">
                Tunjukkan kode <span className="font-mono font-semibold">{order.reference}</span> ke kasir dan bayar <span className="font-semibold">{formatRupiah(order.total)}</span>.
                Nomor antrean muncul setelah pembayaran dikonfirmasi.
              </p>
            )}
            {order.expiresAt ? <PaymentDeadline expiresAt={order.expiresAt} label={deadline.format(new Date(order.expiresAt))} /> : null}
          </div>
        ) : expired ? (
          <p className="text-sm">Batas waktu pembayaran habis, pesanan dibatalkan otomatis. Silakan pesan lagi bila masih ingin memesan.</p>
        ) : cancelled ? (
          <p className="text-sm">Pesanan ini dibatalkan. Hubungi kasir untuk informasi lebih lanjut.</p>
        ) : (
          <ol className="grid grid-cols-4 gap-2" aria-label="Progres pesanan">
            {STEPS.map((step, index) => {
              const done = index <= stepIndex;
              return (
                <li key={step.label} className="flex flex-col items-center gap-1.5 text-center">
                  <span
                    className={cn("flex size-8 items-center justify-center rounded-full border text-xs font-semibold", done ? "border-brand bg-brand text-brand-foreground" : "border-white/30 text-neutral-400")}
                    aria-current={index === stepIndex ? "step" : undefined}
                  >
                    {done ? <CheckIcon className="size-4" /> : index + 1}
                  </span>
                  <span className={cn("text-xs", done ? "text-white" : "text-neutral-400")}>{step.label}</span>
                </li>
              );
            })}
          </ol>
        )}
        {order.status === "ready" ? <p className="rounded-2xl bg-brand px-4 py-3 text-center text-base font-semibold text-brand-foreground">Pesanan siap — silakan ambil di counter.</p> : null}
      </section>

      <section className="flex flex-col gap-3 rounded-3xl border p-5 text-sm">
        <dl className="grid grid-cols-[8rem_1fr] gap-y-1.5">
          <dt className="text-muted-foreground">Kode pesanan</dt>
          <dd className="font-mono">{order.reference}</dd>
          <dt className="text-muted-foreground">Nama</dt>
          <dd>{order.customerName ?? "—"}</dd>
          <dt className="text-muted-foreground">Tipe</dt>
          <dd>
            {ORDER_TYPE_LABELS[order.type]}
            {order.tableNumber ? ` · Meja ${order.tableNumber}` : ""}
          </dd>
          <dt className="text-muted-foreground">Outlet</dt>
          <dd>{order.outletName}</dd>
          <dt className="text-muted-foreground">Dipesan</dt>
          <dd>{timeFormat.format(new Date(order.createdAt))}</dd>
          <dt className="text-muted-foreground">Pembayaran</dt>
          <dd>
            {order.paymentMethod === "cash" ? "Tunai" : (order.paymentMethod ?? "—")} · {PAYMENT_STATUS_LABELS[order.paymentStatus]}
          </dd>
        </dl>

        <ul className="flex flex-col divide-y border-t">
          {order.lines.map((line) => (
            <li key={line.id} className="flex gap-3 py-2.5">
              <span className="font-medium tabular-nums">{line.quantity}×</span>
              <div className="flex-1">
                <p className="font-medium">{line.productName}</p>
                {line.modifiers.length ? (
                  <p className="text-xs text-muted-foreground">{line.modifiers.map((m) => `${m.name}${m.priceDelta ? ` (${formatPriceDelta(m.priceDelta)})` : ""}`).join(", ")}</p>
                ) : null}
                {line.notes ? <p className="text-xs text-muted-foreground italic">“{line.notes}”</p> : null}
              </div>
              <span className="tabular-nums">{formatRupiah(line.lineTotal)}</span>
            </li>
          ))}
        </ul>
        {order.deliveryFee ? (
          <div className="flex justify-between border-t pt-3 text-muted-foreground">
            <span>Biaya antar ke meja{order.locationNote ? ` · ${order.locationNote}` : ""}</span>
            <span className="tabular-nums">{formatRupiah(order.deliveryFee)}</span>
          </div>
        ) : null}
        <div className="flex justify-between border-t pt-3 text-base font-semibold">
          <span>Total</span>
          <span className="tabular-nums">{formatRupiah(order.total)}</span>
        </div>
      </section>

      <Link href={`/order?outlet=${order.outletCode}`} className={cn(buttonVariants({ variant: "outline", size: "lg" }), "h-12 rounded-full")}>
        Pesan lagi
      </Link>
    </>
  );
}

/** Hitung mundur batas bayar. Penentu sebenarnya waktu database (pg_cron); ini hanya tampilan. */
function PaymentDeadline({ expiresAt, label }: { expiresAt: string; label: string }) {
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  const remaining = now === null ? null : Math.max(0, Math.floor((Date.parse(expiresAt) - now) / 1000));

  return (
    <p className="text-sm" aria-live="polite">
      {remaining === 0 ? (
        <span className="font-semibold text-destructive">Batas waktu pembayaran habis.</span>
      ) : (
        <>
          Bayar sebelum <span className="font-semibold">{label}</span>
          {remaining !== null ? (
            <span className="text-muted-foreground">
              {" "}
              (sisa {Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, "0")})
            </span>
          ) : null}
        </>
      )}
    </p>
  );
}

/** Saat status berubah menjadi "siap": bunyi singkat, getar, dan notifikasi browser (bila diizinkan). */
function useReadyAlert(status: string, deskDelivery: boolean) {
  const previous = useRef(status);
  useEffect(() => {
    const was = previous.current;
    previous.current = status;
    if (status !== "ready" || was === "ready") return;
    // Browser memblokir bunyi & getar sebelum pelanggan menyentuh halaman (mis. tombol notifikasi);
    // banner & notifikasi browser tetap tampil.
    const activated = !("userActivation" in navigator) || navigator.userActivation.hasBeenActive;
    try {
      const AudioCtx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
      if (activated && AudioCtx) {
        const ctx = new AudioCtx();
        [0, 0.25].forEach((delay) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.frequency.value = 880;
          gain.gain.value = 0.15;
          osc.connect(gain).connect(ctx.destination);
          osc.start(ctx.currentTime + delay);
          osc.stop(ctx.currentTime + delay + 0.18);
        });
      }
    } catch {
      // Audio tidak tersedia; banner & notifikasi tetap tampil.
    }
    if (activated) navigator.vibrate?.([200, 100, 200]);
    if ("Notification" in window && Notification.permission === "granted") {
      new Notification("Pesanan Sudaya siap", { body: deskDelivery ? "Barista sedang mengantar ke meja Anda." : "Silakan ambil di counter." });
    }
  }, [status, deskDelivery]);
}

/** Izin notifikasi browser agar pelanggan dikabari walau pindah tab. */
function NotifyToggle() {
  const supported = typeof window !== "undefined" && "Notification" in window;
  const [permission, setPermission] = useState<string>(supported ? Notification.permission : "unsupported");
  if (!supported || permission !== "default") {
    return permission === "granted" ? <p className="text-center text-xs text-muted-foreground">Notifikasi aktif: Anda dikabari saat pesanan siap.</p> : null;
  }
  return (
    <button
      type="button"
      onClick={() => Notification.requestPermission().then(setPermission)}
      className="min-h-11 rounded-full border px-4 text-sm font-medium hover:border-foreground"
    >
      Aktifkan notifikasi saat pesanan siap
    </button>
  );
}
