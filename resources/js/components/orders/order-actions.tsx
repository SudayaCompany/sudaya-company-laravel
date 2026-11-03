import { Link, router } from "@inertiajs/react";
import { BanknoteIcon, CheckIcon, Loader2Icon, PrinterIcon, XIcon } from "lucide-react";
import { useState } from "react";

import { Button, buttonVariants } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { formatRupiah } from "@/lib/format";
import type { StaffOrder } from "@/lib/orders";

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
export function OrderActions({ order, flags, cashSessionOpen }: { order: StaffOrder; flags: OrderActionFlags; cashSessionOpen: boolean }) {
  const unpaid = order.status === "pending_payment";
  const terminal = ["completed", "cancelled", "refunded"].includes(order.status);
  const approval = order.pendingApproval;

  return (
    <div className="flex flex-col gap-3">
      {unpaid && flags.canConfirmCash ? (
        cashSessionOpen ? (
          <ConfirmCash orderId={order.id} total={order.total} />
        ) : (
          <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Sesi kas belum dibuka.{" "}
            <Link href={`/pos/cash?outlet=${order.outletId}`} className="font-medium underline">
              Buka sesi kas
            </Link>{" "}
            untuk menerima uang tunai.
          </p>
        )
      ) : null}
      {order.status === "cancelled" && order.paymentStatus === "paid" && flags.canRefund ? <RecordRefund orderId={order.id} total={order.total} /> : null}

      {approval ? (
        <div className="flex flex-col gap-2 rounded-lg border border-brand/40 bg-brand/5 p-3 text-sm">
          <p className="font-medium">Pengajuan pembatalan menunggu persetujuan</p>
          <p className="text-muted-foreground">
            “{approval.reason}” — {approval.requestedByName}
          </p>
          {flags.canDecide ? <DecideApproval approvalId={approval.id} /> : <p className="text-xs text-muted-foreground">Menunggu keputusan Manager lain.</p>}
        </div>
      ) : null}

      <div className="flex flex-wrap gap-2">
        <a href={`/pos/orders/${order.id}/receipt`} target="_blank" rel="noopener" className={buttonVariants({ variant: "outline" })}>
          <PrinterIcon /> Cetak struk
        </a>
        {!terminal && !approval && flags.canCancel ? <CancelOrder orderId={order.id} paid={order.paymentStatus === "paid"} /> : null}
      </div>
    </div>
  );
}

function ConfirmCash({ orderId, total }: { orderId: string; total: number }) {
  const [tendered, setTendered] = useState(String(total));
  const { pending, run } = useAction();
  const amount = Number(tendered);
  const valid = tendered.trim() !== "" && Number.isInteger(amount) && amount >= total;
  const quick = [total, 50_000, 100_000].filter((v, i, arr) => v >= total && arr.indexOf(v) === i);

  return (
    <form
      className="flex flex-col gap-2 rounded-lg border p-3"
      onSubmit={(e) => {
        e.preventDefault();
        run(`/pos/orders/${orderId}/pay`, { tenderedAmount: amount });
      }}
    >
      <Label htmlFor="tendered">Uang tunai diterima</Label>
      <div className="flex flex-wrap gap-2">
        <Input id="tendered" type="number" inputMode="numeric" min={total} value={tendered} onChange={(e) => setTendered(e.target.value)} className="h-10 max-w-40 tabular-nums" />
        {quick.map((v) => (
          <Button key={v} type="button" variant="outline" className="h-10" onClick={() => setTendered(String(v))}>
            {v === total ? "Uang pas" : formatRupiah(v)}
          </Button>
        ))}
      </div>
      <p className="text-sm text-muted-foreground">
        Kembalian: <span className="font-medium text-foreground tabular-nums">{valid ? formatRupiah(amount - total) : "—"}</span>
      </p>
      <Button type="submit" className="h-11 bg-success text-success-foreground hover:bg-success/90" disabled={!valid || pending}>
        {pending ? <Loader2Icon className="animate-spin" /> : <BanknoteIcon />}
        Konfirmasi pembayaran {formatRupiah(total)}
      </Button>
    </form>
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

function DecideApproval({ approvalId }: { approvalId: string }) {
  const { pending, run } = useAction();
  return (
    <div className="flex gap-2">
      <Button size="sm" className="bg-success text-success-foreground hover:bg-success/90" disabled={pending} onClick={() => run(`/pos/approvals/${approvalId}/decide`, { approve: true })}>
        <CheckIcon /> Setujui
      </Button>
      <Button size="sm" variant="outline" disabled={pending} onClick={() => run(`/pos/approvals/${approvalId}/decide`, { approve: false })}>
        Tolak
      </Button>
    </div>
  );
}
