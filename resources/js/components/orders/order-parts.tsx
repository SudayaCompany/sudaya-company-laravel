import { Badge } from "@/components/ui/badge";
import { formatPriceDelta, formatRupiah } from "@/lib/format";
import { ORDER_STATUS_LABELS, PAYMENT_STATUS_LABELS, type CustomRequest, type OrderLine, type OrderStatus, type PaymentStatus } from "@/lib/orders";
import { cn } from "@/lib/utils";

// Peta status tunggal (docs/UI_UX_DESIGN_SYSTEM.md §2.2). Teks memakai token "-strong" agar
// kontras di atas tint tetap ≥ 4,5:1.
const STATUS_STYLE: Partial<Record<OrderStatus, string>> = {
  pending_payment: "bg-brand-soft text-brand-strong",
  queued: "bg-secondary text-secondary-foreground",
  paid: "bg-secondary text-secondary-foreground",
  preparing: "bg-info/15 text-info",
  ready: "bg-success/15 text-success-strong",
  completed: "bg-muted text-muted-foreground",
  cancelled: "bg-destructive/10 text-destructive-strong",
  refunded: "border-destructive/40 bg-transparent text-destructive-strong",
};

const PAYMENT_STYLE: Partial<Record<PaymentStatus, string>> = {
  paid: "bg-success/15 text-success-strong",
  failed: "bg-destructive/10 text-destructive-strong",
  expired: "bg-destructive/10 text-destructive-strong",
  refunded: "border-destructive/40 bg-transparent text-destructive-strong",
  partial_refund: "border-destructive/40 bg-transparent text-destructive-strong",
};

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  return (
    <Badge variant="secondary" className={cn(STATUS_STYLE[status])}>
      {ORDER_STATUS_LABELS[status]}
    </Badge>
  );
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  return (
    <Badge variant={PAYMENT_STYLE[status] ? "secondary" : "outline"} className={cn(PAYMENT_STYLE[status])}>
      {PAYMENT_STATUS_LABELS[status]}
    </Badge>
  );
}

/**
 * Status order + status pembayaran. Pembayaran hanya ditampilkan bila menambah informasi:
 * "Menunggu bayar" sudah berarti belum dibayar, jadi tidak perlu badge kedua.
 */
export function OrderStatusBadges({ status, paymentStatus }: { status: OrderStatus; paymentStatus: PaymentStatus }) {
  const redundant = status === "pending_payment" && paymentStatus === "pending";
  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <OrderStatusBadge status={status} />
      {redundant ? null : <PaymentStatusBadge status={paymentStatus} />}
    </span>
  );
}

/** Baris permintaan di luar menu: harganya diisi kasir sebelum pembayaran (keputusan owner 17 November 2026). */
export function CustomRequestRow({ request }: { request: CustomRequest }) {
  return (
    <li className="flex gap-3 py-2.5" data-testid="custom-request-line">
      <span className="w-8 font-medium tabular-nums">1×</span>
      <div className="min-w-0 flex-1">
        <p className="font-medium">Permintaan di luar menu</p>
        <p className={cn("text-xs break-words text-muted-foreground italic", request.status === "declined" && "line-through")}>“{request.text}”</p>
      </div>
      {request.status === "pending" ? (
        <span className="h-fit shrink-0 rounded-md bg-warning/20 px-1.5 py-0.5 text-xs font-semibold text-warning-strong">menunggu harga</span>
      ) : request.status === "declined" ? (
        <span className="shrink-0 text-xs text-muted-foreground">tidak tersedia</span>
      ) : (
        <span className="tabular-nums">{formatRupiah(request.price ?? 0)}</span>
      )}
    </li>
  );
}

/** Daftar item order (snapshot harga saat order dibuat) + permintaan di luar menu bila ada. */
export function OrderLines({ lines, total, customRequest }: { lines: OrderLine[]; total: number; customRequest?: CustomRequest | null }) {
  return (
    <div className="flex flex-col text-sm">
      <ul className="flex flex-col divide-y">
        {lines.map((line) => (
          <li key={line.id} className="flex gap-3 py-2.5">
            <span className="w-8 font-medium tabular-nums">{line.quantity}×</span>
            <div className="min-w-0 flex-1">
              <p className="font-medium">{line.productName}</p>
              {line.modifiers.length ? (
                <p className="text-xs text-muted-foreground">
                  {line.modifiers.map((m) => `${m.name}${m.priceDelta ? ` (${formatPriceDelta(m.priceDelta)})` : ""}`).join(", ")}
                </p>
              ) : null}
              {line.notes ? <p className="text-xs text-muted-foreground italic">“{line.notes}”</p> : null}
            </div>
            <span className="tabular-nums">{formatRupiah(line.lineTotal)}</span>
          </li>
        ))}
        {customRequest ? <CustomRequestRow request={customRequest} /> : null}
      </ul>
      <div className="flex justify-between border-t pt-3 text-base font-semibold">
        <span>Total</span>
        <span className="tabular-nums">{formatRupiah(total)}</span>
      </div>
      {customRequest?.status === "pending" ? (
        <p className="pt-1 text-xs text-muted-foreground">Belum termasuk permintaan di luar menu; harganya diisi kasir.</p>
      ) : null}
    </div>
  );
}
