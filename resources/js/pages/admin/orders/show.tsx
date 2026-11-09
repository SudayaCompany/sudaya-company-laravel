import { Head } from "@inertiajs/react";

import { ApprovalDecision } from "@/components/orders/approval-decision";
import { LiveRefresh } from "@/components/orders/live-refresh";
import { OrderLines, OrderStatusBadges, PaymentStatusBadge } from "@/components/orders/order-parts";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { CHANNEL_LABELS, HISTORY_LABELS, METHOD_LABELS, ORDER_TYPE_LABELS, type StaffOrder } from "@/lib/orders";

/** Detail pesanan hanya-baca untuk Owner/Manager, plus keputusan pembatalan bila ada pengajuan. */
export default function AdminOrderPage({ order, canDecide, topic }: { order: StaffOrder; canDecide: boolean; topic: string }) {
  const time = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: order.outlet.timezone });
  const label = order.orderNumber ?? order.reference;
  const approval = order.pendingApproval;

  return (
    <AdminLayout breadcrumbs={[{ label: "Pesanan", href: `/admin/orders?outlet=${order.outletId}` }, { label }]}>
      <Head title={`Pesanan ${label}`} />
      <header className="flex flex-wrap items-center gap-3">
        <h1 className="font-mono text-3xl font-black">{label}</h1>
        <OrderStatusBadges status={order.status} paymentStatus={order.paymentStatus} />
        <span className="text-xl font-semibold tabular-nums">{formatRupiah(order.total)}</span>
        <LiveRefresh topic={topic} only={["order", "canDecide"]} className="ml-auto" />
      </header>

      {approval ? (
        <div className="flex flex-col gap-3 rounded-xl border border-brand/40 bg-brand/5 p-4 text-sm" data-testid="admin-approval">
          <p className="font-semibold">Pengajuan pembatalan menunggu keputusan</p>
          <p className="break-words">“{approval.reason}”</p>
          <p className="text-xs text-muted-foreground">
            Diajukan {approval.requestedByName} · {time.format(new Date(approval.createdAt))}
          </p>
          {canDecide ? <ApprovalDecision approvalId={approval.id} total={order.total} /> : <p className="text-xs text-muted-foreground">Menunggu keputusan Manager lain.</p>}
        </div>
      ) : null}
      {order.status === "cancelled" && order.paymentStatus === "paid" ? (
        <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-strong">Pesanan dibatalkan setelah dibayar. Uang belum dikembalikan; kasir mencatat refund saat uang diserahkan.</p>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem] lg:items-start">
        <div className="flex min-w-0 flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Detail pesanan</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-[8rem_1fr]">
                <dt className="text-muted-foreground">Kode</dt>
                <dd className="font-mono">{order.reference}</dd>
                <dt className="text-muted-foreground">Sumber</dt>
                <dd>{CHANNEL_LABELS[order.channel]}</dd>
                <dt className="text-muted-foreground">Tipe</dt>
                <dd>
                  {ORDER_TYPE_LABELS[order.type]}
                  {order.tableNumber ? ` · Meja ${order.tableNumber}` : ""}
                </dd>
                <dt className="text-muted-foreground">Pelanggan</dt>
                <dd className="break-words">{order.customerName ?? "—"}</dd>
                {order.notes ? (
                  <>
                    <dt className="text-muted-foreground">Catatan</dt>
                    <dd className="break-words italic">“{order.notes}”</dd>
                  </>
                ) : null}
                <dt className="text-muted-foreground">Outlet</dt>
                <dd>{order.outlet.name}</dd>
                <dt className="text-muted-foreground">Dibuat</dt>
                <dd>
                  {time.format(new Date(order.createdAt))}
                  {order.createdByName ? ` · ${order.createdByName}` : " · customer"}
                </dd>
                {order.cancelReason ? (
                  <>
                    <dt className="text-muted-foreground">Alasan batal</dt>
                    <dd className="text-destructive-strong">{order.cancelReason}</dd>
                  </>
                ) : null}
              </dl>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Item</CardTitle>
            </CardHeader>
            <CardContent>
              <OrderLines lines={order.lines} total={order.total} />
            </CardContent>
          </Card>
        </div>

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Pembayaran</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 text-sm">
              {order.payments.length === 0 ? <p className="text-muted-foreground">Belum ada pembayaran.</p> : null}
              {order.payments.map((p) => (
                <div key={p.id} className="flex flex-col gap-0.5 rounded-lg border p-3">
                  <div className="flex justify-between font-medium">
                    <span>{METHOD_LABELS[p.method] ?? "Lainnya"}</span>
                    <PaymentStatusBadge status={p.status} />
                  </div>
                  <p className="tabular-nums">{formatRupiah(p.amount)}</p>
                  {p.paidAt ? (
                    <p className="text-xs text-muted-foreground">
                      {time.format(new Date(p.paidAt))}
                      {p.confirmedByName ? ` · ${p.confirmedByName}` : ""}
                    </p>
                  ) : null}
                </div>
              ))}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Riwayat</CardTitle>
            </CardHeader>
            <CardContent>
              <ol className="flex flex-col gap-3 border-l pl-4 text-sm">
                {order.history.map((event) => (
                  <li key={event.id} className="relative">
                    <span className="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-brand" aria-hidden />
                    <p className="font-medium">{HISTORY_LABELS[event.action] ?? "Aktivitas lain"}</p>
                    <p className="text-xs text-muted-foreground">
                      {time.format(new Date(event.createdAt))} · {event.actorName ?? "Customer/Sistem"}
                    </p>
                    {event.reason ? <p className="text-xs italic">“{event.reason}”</p> : null}
                  </li>
                ))}
              </ol>
            </CardContent>
          </Card>
        </div>
      </div>
    </AdminLayout>
  );
}
