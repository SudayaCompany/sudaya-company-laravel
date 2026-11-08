import { Link, router } from "@inertiajs/react";
import { BanknoteIcon, CheckCircle2Icon, CheckIcon, Loader2Icon, PrinterIcon, XIcon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { MoneyInput } from "@/components/money-input";
import { Button, buttonVariants } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { areaBase, type Area } from "@/lib/area";
import { formatRupiah } from "@/lib/format";
import { amountFromDigits, shortRupiah, tenderSuggestions } from "@/lib/money";
import type { StaffOrder } from "@/lib/orders";
import { cn } from "@/lib/utils";

export type OrderActionFlags = { canConfirmCash: boolean; canCancel: boolean; canDecide: boolean; canRefund: boolean };

/** Kirim aksi ke server; pesan sukses/error tampil lewat flash. */
function useAction() {
  const [pending, setPending] = useState(false);
  const run = (url: string, data: Record<string, unknown>, onSuccess?: () => void) =>
    router.post(url, data as never, {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onFlash: (flash) => {
        if ((flash as { success?: string }).success) onSuccess?.();
      },
    });
  return { pending, run };
}

/** Aksi kasir/manager pada detail order. Semua dicek ulang di server. */
export function OrderActions({
  area = "pos",
  order,
  flags,
  cashSessionOpen,
}: {
  area?: Area;
  order: StaffOrder;
  flags: OrderActionFlags;
  cashSessionOpen: boolean;
}) {
  const base = areaBase(area);
  const unpaid = order.status === "pending_payment";
  const terminal = ["completed", "cancelled", "refunded"].includes(order.status);
  const approval = order.pendingApproval;
  // Kembalian dihitung dari uang yang diketik kasir (tampilan saja; nilai sah dicatat server).
  const [paidChange, setPaidChange] = useState<number | null>(null);

  return (
    <div className="flex flex-col gap-3">
      <PaidDialog order={order} change={paidChange} onClose={() => setPaidChange(null)} />
      {unpaid && flags.canConfirmCash ? (
        cashSessionOpen ? (
          <ConfirmCash orderId={order.id} total={order.total} onPaid={setPaidChange} />
        ) : (
          <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-strong">
            Sesi kas belum dibuka.{" "}
            <Link href={`/pos/cash?outlet=${order.outletId}`} className="font-medium underline">
              Buka sesi kas
            </Link>{" "}
            untuk menerima uang tunai.
          </p>
        )
      ) : null}
      {order.status === "cancelled" && order.paymentStatus === "paid" && flags.canRefund ? (
        cashSessionOpen ? (
          <RecordRefund orderId={order.id} total={order.total} />
        ) : (
          // Refund keluar dari sesi kas terbuka; server juga menolak bila belum ada sesi.
          <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-strong">
            Refund butuh sesi kas terbuka.{" "}
            <Link href={`/pos/cash?outlet=${order.outletId}`} className="font-medium underline">
              Buka sesi kas
            </Link>{" "}
            lalu catat refund saat uang diserahkan.
          </p>
        )
      ) : null}

      {approval ? (
        <div className="flex flex-col gap-2 rounded-lg border border-brand/40 bg-brand/5 p-3 text-sm">
          <p className="font-medium">Pengajuan pembatalan menunggu persetujuan</p>
          <p className="text-muted-foreground">
            “{approval.reason}” — {approval.requestedByName}
          </p>
          {approval.createdAt ? (
            <p className="text-xs text-muted-foreground">Diajukan {new Intl.DateTimeFormat("id-ID", { timeStyle: "short" }).format(new Date(approval.createdAt))}</p>
          ) : null}
          {flags.canDecide ? (
            <DecideApproval base={base} approvalId={approval.id} total={order.total} />
          ) : (
            <p className="text-xs text-muted-foreground">Menunggu keputusan Manager lain.</p>
          )}
        </div>
      ) : null}

      {area === "admin" && !terminal ? (
        <p className="text-xs text-muted-foreground">Pembayaran, pembatalan, dan refund dilakukan dari halaman Kasir.</p>
      ) : null}
      <div className="flex flex-wrap gap-2">
        <a href={`${base}/orders/${order.id}/receipt?print=1`} target="_blank" rel="noopener" className={buttonVariants({ variant: "outline" })}>
          <PrinterIcon /> Cetak struk
        </a>
        {!terminal && !approval && flags.canCancel ? <CancelOrder orderId={order.id} paid={order.paymentStatus === "paid"} /> : null}
      </div>
    </div>
  );
}

function ConfirmCash({ orderId, total, onPaid }: { orderId: string; total: number; onPaid: (change: number) => void }) {
  const [tendered, setTendered] = useState(String(total));
  const { pending, run } = useAction();
  const amount = amountFromDigits(tendered);
  const valid = amount !== null && amount >= total;

  return (
    <form
      className="flex flex-col gap-3 rounded-lg border p-3"
      onSubmit={(e) => {
        e.preventDefault();
        if (amount !== null) run(`/pos/orders/${orderId}/pay`, { tenderedAmount: amount }, () => onPaid(amount - total));
      }}
    >
      <div className="flex items-baseline justify-between">
        <span className="font-semibold">Total</span>
        <span className="text-2xl font-bold tabular-nums">{formatRupiah(total)}</span>
      </div>
      <div className="flex flex-col gap-2">
        <Label htmlFor="tendered">Uang tunai diterima</Label>
        <MoneyInput id="tendered" value={tendered} onValueChange={setTendered} className="h-12 text-lg font-semibold" />
        <div className="flex flex-wrap gap-1.5">
          <Button type="button" variant="outline" onClick={() => setTendered(String(total))}>
            Uang pas
          </Button>
          {tenderSuggestions(total).map((v) => (
            <Button key={v} type="button" variant="outline" className="tabular-nums" onClick={() => setTendered(String(v))}>
              {shortRupiah(v)}
            </Button>
          ))}
        </div>
      </div>
      <div className={cn("flex items-baseline justify-between rounded-xl px-4 py-3", valid ? "bg-success/10 text-success-strong" : "bg-muted text-muted-foreground")} aria-live="polite">
        <span className="text-sm font-semibold">Kembalian</span>
        <span className="text-2xl font-bold tabular-nums" data-testid="detail-change">
          {valid ? formatRupiah(amount - total) : "—"}
        </span>
      </div>
      <Button type="submit" variant="success" size="xl" disabled={!valid || pending}>
        {pending ? <Loader2Icon className="animate-spin" /> : <BanknoteIcon />}
        Konfirmasi pembayaran {formatRupiah(total)}
      </Button>
    </form>
  );
}

/** Hasil bayar dari detail pesanan: nomor antrean dan kembalian dibuat besar seperti di kasir. */
function PaidDialog({ order, change, onClose }: { order: StaffOrder; change: number | null; onClose: () => void }) {
  const open = change !== null && order.orderNumber !== null;
  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader className="items-center text-center">
          <CheckCircle2Icon className="size-10 text-success" aria-hidden />
          <DialogTitle className="text-xl">Pembayaran berhasil</DialogTitle>
          <DialogDescription>Pesanan masuk antrean barista.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col items-center gap-3 text-center">
          <div>
            <p className="text-sm text-muted-foreground">Nomor antrean</p>
            <p className="font-mono text-6xl font-black tracking-wider">{order.orderNumber}</p>
          </div>
          <div className="w-full rounded-xl bg-success/10 px-4 py-3 text-success-strong">
            <p className="text-sm font-semibold">Kembalian</p>
            <p className="text-4xl font-bold tabular-nums">{formatRupiah(change ?? 0)}</p>
          </div>
        </div>
        <DialogFooter className="flex-col gap-2 sm:flex-col">
          <a href={`/pos/orders/${order.id}/receipt?print=1`} target="_blank" rel="noopener" className={buttonVariants({ variant: "outline", size: "lg", className: "w-full" })}>
            <PrinterIcon /> Cetak struk
          </a>
          <Button size="xl" className="w-full" onClick={onClose}>
            Selesai
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function CancelOrder({ orderId, paid }: { orderId: string; paid: boolean }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const { pending, run } = useAction();

  return (
    <>
      <Button variant="outline" className="text-destructive" onClick={() => setOpen(true)}>
        <XIcon /> {paid ? "Ajukan pembatalan" : "Batalkan pesanan"}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{paid ? "Ajukan pembatalan?" : "Batalkan pesanan?"}</DialogTitle>
            <DialogDescription>
              {paid
                ? "Pesanan sudah dibayar. Pembatalan butuh persetujuan Manager. Setelah disetujui, pengembalian uang dicatat terpisah saat uang diserahkan."
                : "Pesanan belum dibayar dan akan dibatalkan."}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="cancel-reason">Alasan</Label>
            <Textarea id="cancel-reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} placeholder="mis. salah input, customer batal, order duplikat" />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Kembali
            </Button>
            <Button variant="destructive" disabled={pending || reason.trim().length < 3} onClick={() => run(`/pos/orders/${orderId}/cancel`, { reason }, () => setOpen(false))}>
              {pending ? <Loader2Icon className="animate-spin" /> : null}
              {paid ? "Ajukan" : "Batalkan"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function RecordRefund({ orderId, total }: { orderId: string; total: number }) {
  const [open, setOpen] = useState(false);
  const { pending, run } = useAction();

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <BanknoteIcon /> Catat refund tunai {formatRupiah(total)}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Catat refund tunai?</DialogTitle>
            <DialogDescription>
              Tekan setelah uang {formatRupiah(total)} diserahkan ke customer. Uang keluar dari sesi kas yang sedang terbuka dan catatan ini tidak bisa dibatalkan.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Kembali
            </Button>
            <Button disabled={pending} onClick={() => run(`/pos/orders/${orderId}/refund`, {}, () => setOpen(false))}>
              {pending ? <Loader2Icon className="animate-spin" /> : null}
              Uang sudah diserahkan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

export function DecideApproval({ base = "/pos", approvalId, total, size = "lg" }: { base?: string; approvalId: string; total: number; size?: "default" | "lg" }) {
  const { pending, run } = useAction();
  return (
    <div className="flex flex-wrap gap-2">
      {/* Menyetujui membatalkan pesanan lunas (tidak bisa diulang), jadi dikonfirmasi; menolak tidak mengubah pesanan. */}
      <ConfirmAction
        trigger={
          <Button variant="success" size={size} disabled={pending}>
            {pending ? <Loader2Icon className="animate-spin" /> : <CheckIcon />} Setujui
          </Button>
        }
        title="Setujui pembatalan?"
        description={`Pesanan dibatalkan dan keluar dari antrean. Uang ${formatRupiah(total)} dikembalikan terpisah lewat tombol Catat refund tunai saat diserahkan.`}
        confirmLabel="Setujui pembatalan"
        onConfirm={() => run(`${base}/approvals/${approvalId}/decide`, { approve: true })}
      />
      <Button size={size} variant="outline" disabled={pending} onClick={() => run(`${base}/approvals/${approvalId}/decide`, { approve: false })}>
        Tolak
      </Button>
    </div>
  );
}
