import { Head, Link, router } from "@inertiajs/react";
import { ArrowLeftIcon, CheckIcon, Loader2Icon, QrCodeIcon, XIcon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { MoneyInput } from "@/components/money-input";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { amountFromDigits } from "@/lib/money";
import { cn } from "@/lib/utils";

import { LiveRefresh } from "@/components/orders/live-refresh";
import { OrderActions, type OrderActionFlags } from "@/components/orders/order-actions";
import { OrderLines, OrderStatusBadges, PaymentStatusBadge } from "@/components/orders/order-parts";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { AreaPage } from "@/layouts/area-layout";
import { areaBase, type Area } from "@/lib/area";
import { formatRupiah } from "@/lib/format";
import { whatsappReadyLink } from "@/lib/whatsapp";
import { CHANNEL_LABELS, ORDER_TYPE_LABELS, type StaffOrder } from "@/lib/orders";

const HISTORY_LABELS: Record<string, string> = {
  "order.created": "Pesanan dibuat",
  "payment.confirmed": "Pembayaran dikonfirmasi",
  "order.queued": "Masuk antrean",
  "order.preparing": "Mulai dibuat",
  "order.ready": "Siap diambil",
  "order.completed": "Selesai",
  "order.status_override": "Koreksi status oleh Manager",
  "order.cancel_requested": "Pembatalan diajukan",
  "approval.approved": "Pembatalan disetujui",
  "approval.rejected": "Pembatalan ditolak",
  "order.cancelled": "Dibatalkan",
  "payment.refunded": "Uang dikembalikan (refund tunai)",
  "order.expired": "Kedaluwarsa (tidak dibayar tepat waktu)",
  "inventory.sale_posted": "Stok produk jadi dipotong",
  "order.custom_request_priced": "Harga permintaan di luar menu diisi",
};

const METHOD_LABELS: Record<string, string> = { cash: "Tunai", qris: "QRIS" };

/** Detail order untuk staf. Riwayat diambil dari audit log. */
export default function PosOrderPage({
  area = "pos",
  order,
  flags,
  cashSessionOpen,
  topic,
}: {
  area?: Area;
  order: StaffOrder;
  flags: OrderActionFlags;
  cashSessionOpen: boolean;
  topic: string;
}) {
  const time = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "medium", timeZone: order.outlet.timezone });
  // Bayar saat diantar: sudah di antrean barista tetapi uang belum diterima.
  const unpaid = order.status === "pending_payment" || (!!order.payOnDelivery && order.paymentStatus === "pending" && ["queued", "preparing", "ready"].includes(order.status));

  return (
    <AreaPage area={area} outletId={order.outletId} className="max-w-5xl">
      <Head title={`Pesanan ${order.orderNumber ?? order.reference}`} />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Link href={`${areaBase(area)}/orders?outlet=${order.outletId}`} className="inline-flex min-h-10 items-center gap-1 text-sm text-muted-foreground hover:text-foreground pointer-coarse:min-h-11">
            <ArrowLeftIcon className="size-4" /> Daftar pesanan
          </Link>
          <LiveRefresh topic={topic} only={["order", "flags", "cashSessionOpen"]} />
        </div>
        <header className="flex flex-wrap items-center gap-3">
          <h1 className="font-mono text-3xl font-black">{order.orderNumber ?? order.reference}</h1>
          <OrderStatusBadges status={order.status} paymentStatus={order.paymentStatus} />
        </header>

        <div className="grid gap-4 lg:grid-cols-[1fr_22rem] lg:items-start">
          {/* < 1024 px: Pembayaran (aksi kasir) di atas; ≥ 1024 px: kolom kanan. */}
          <div className="flex flex-col gap-4 lg:col-start-2 lg:row-start-1">
            <Card>
              <CardHeader>
                <CardTitle>Pembayaran</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3 text-sm">
                {order.payments.map((p) => (
                  <div key={p.id} className="flex flex-col gap-0.5 rounded-lg border p-3">
                    <div className="flex justify-between font-medium">
                      <span>{METHOD_LABELS[p.method] ?? p.method}</span>
                      <PaymentStatusBadge status={p.status} />
                    </div>
                    <p className="tabular-nums">{formatRupiah(p.amount)}</p>
                    {p.tenderedAmount !== null ? (
                      <p className="text-xs text-muted-foreground tabular-nums">
                        Diterima {formatRupiah(p.tenderedAmount)} · Kembali {formatRupiah(p.changeAmount ?? 0)}
                      </p>
                    ) : null}
                    {p.paidAt ? (
                      <p className="text-xs text-muted-foreground">
                        {time.format(new Date(p.paidAt))}
                        {p.confirmedByName ? ` · ${p.confirmedByName}` : ""}
                      </p>
                    ) : null}
                  </div>
                ))}
                {order.status === "cancelled" && order.paymentStatus === "paid" ? (
                  <p className="rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive-strong">
                    Pesanan dibatalkan setelah dibayar. Uang belum dikembalikan, catat refund saat uang diserahkan.
                  </p>
                ) : null}
                {order.customRequest ? <CustomRequestPricing order={order} area={area} canPrice={unpaid && flags.canConfirmCash} /> : null}
                {unpaid && flags.canConfirmCash && order.customRequest?.status !== "pending" && order.payments.some((p) => p.method === "qris" && p.status === "pending") ? (
                  <ConfirmQris orderId={order.id} total={order.total} area={area} />
                ) : null}
                <OrderActions area={area} order={order} flags={flags} cashSessionOpen={cashSessionOpen} />
              </CardContent>
            </Card>
          </div>
          <div className="flex min-w-0 flex-col gap-4 lg:col-start-1 lg:row-span-2 lg:row-start-1">
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
                    {order.channel === "qr_table" && order.type === "dine_in" ? "Antar ke meja" : ORDER_TYPE_LABELS[order.type]}
                    {order.tableNumber ? ` · Meja ${order.tableNumber}` : ""}
                    {order.payOnDelivery ? " · bayar tunai saat diantar" : ""}
                  </dd>
                  {order.locationNote ? (
                    <>
                      <dt className="text-muted-foreground">Lokasi</dt>
                      <dd>{order.locationNote}</dd>
                    </>
                  ) : null}
                  <dt className="text-muted-foreground">Pelanggan</dt>
                  <dd className="break-words">
                    {order.customerName ?? "—"}
                    {order.customerDepartment ? ` · ${order.customerDepartment}` : ""}
                    {order.customerPhone ? ` · ${order.customerPhone}` : ""}
                    {(() => {
                      const href = order.status === "ready" ? whatsappReadyLink(order.customerPhone, { orderNumber: order.orderNumber, customerName: order.customerName, deskDelivery: order.channel === "qr_table" && order.type === "dine_in" }) : null;
                      return href ? (
                        <a href={href} target="_blank" rel="noopener" className="ml-2 font-medium text-success-strong underline-offset-4 hover:underline">
                          Kabari via WhatsApp
                        </a>
                      ) : null;
                    })()}
                  </dd>
                  {order.deliveryAddress ? (
                    <>
                      <dt className="text-muted-foreground">Alamat</dt>
                      <dd className="break-words">{order.deliveryAddress}</dd>
                    </>
                  ) : null}
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
                  {unpaid && order.expiresAt ? (
                    <>
                      <dt className="text-muted-foreground">Batas bayar</dt>
                      <dd>{time.format(new Date(order.expiresAt))}</dd>
                    </>
                  ) : null}
                  {order.cancelReason ? (
                    <>
                      <dt className="text-muted-foreground">Alasan batal</dt>
                      <dd className="text-destructive">{order.cancelReason}</dd>
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
                <OrderLines lines={order.lines} total={order.total} customRequest={order.customRequest} />
              </CardContent>
            </Card>
          </div>
          <div className="flex flex-col gap-4 lg:col-start-2 lg:row-start-2">
            <Card>
              <CardHeader>
                <CardTitle>Riwayat</CardTitle>
              </CardHeader>
              <CardContent>
                <ol className="flex flex-col gap-3 border-l pl-4 text-sm">
                  {order.history.map((event) => (
                    <li key={event.id} className="relative">
                      <span className="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-brand" aria-hidden />
                      <p className="font-medium">{HISTORY_LABELS[event.action] ?? event.action}</p>
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
    </AreaPage>
  );
}

/**
 * Permintaan di luar menu (keputusan owner 17 November 2026): kasir mengisi harganya, atau menandai
 * tidak tersedia, sebelum pembayaran diterima. Bisa dikoreksi selama belum lunas.
 */
function CustomRequestPricing({ order, area, canPrice }: { order: StaffOrder; area: Area; canPrice: boolean }) {
  const request = order.customRequest!;
  const [changing, setChanging] = useState(false);
  const [price, setPrice] = useState(request.price ? String(request.price) : "");
  const [pending, setPending] = useState(false);
  const showForm = canPrice && (request.status === "pending" || changing);
  const amount = amountFromDigits(price);
  const submit = (data: { action: "price" | "decline"; price?: number }) =>
    router.post(`${areaBase(area)}/orders/${order.id}/custom-request`, data, {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onFlash: (flash) => {
        if ((flash as { success?: string }).success) setChanging(false);
      },
    });

  return (
    <div className={cn("flex flex-col gap-2 rounded-lg border p-3", request.status === "pending" ? "border-warning bg-warning/10" : "")} data-testid="custom-request-pricing">
      <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Permintaan di luar menu</p>
      <p className="font-medium break-words">“{request.text}”</p>
      {showForm ? (
        <form
          className="flex flex-col gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (amount !== null) submit({ action: "price", price: amount });
          }}
        >
          <Label htmlFor="request-price">Harga permintaan</Label>
          <MoneyInput id="request-price" value={price} onValueChange={setPrice} placeholder="0" className="h-11 text-base font-semibold" />
          <p className="text-xs text-muted-foreground">Total baru {formatRupiah(order.total - (request.price ?? 0) + (amount ?? 0))}. Pelanggan baru bisa membayar setelah harga disimpan.</p>
          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={amount === null || pending} className="flex-1">
              {pending ? <Loader2Icon className="animate-spin" /> : <CheckIcon />} Simpan harga
            </Button>
            <ConfirmAction
              trigger={
                <Button type="button" variant="outline" disabled={pending}>
                  <XIcon /> Tidak tersedia
                </Button>
              }
              title="Permintaan tidak bisa dibuat?"
              description="Permintaan ditandai tidak tersedia (Rp0) dan pelanggan melihatnya di halaman status. Pesanan lain tetap jalan."
              confirmLabel="Ya, tidak tersedia"
              destructive={false}
              onConfirm={() => submit({ action: "decline" })}
            />
            {changing ? (
              <Button type="button" variant="ghost" disabled={pending} onClick={() => setChanging(false)}>
                Batal
              </Button>
            ) : null}
          </div>
        </form>
      ) : (
        <div className="flex items-center justify-between gap-2 text-sm">
          <span className={request.status === "declined" ? "text-muted-foreground" : "font-semibold tabular-nums"}>
            {request.status === "pending" ? "Menunggu harga dari kasir" : request.status === "declined" ? "Tidak tersedia (Rp0)" : formatRupiah(request.price ?? 0)}
          </span>
          {canPrice && request.status !== "pending" ? (
            <Button type="button" variant="outline" size="sm" onClick={() => setChanging(true)}>
              Ubah
            </Button>
          ) : null}
        </div>
      )}
    </div>
  );
}

/** QRIS statis outlet: konfirmasi setelah dana terlihat masuk di aplikasi bank/QRIS Sudaya. */
function ConfirmQris({ orderId, total, area }: { orderId: string; total: number; area: Area }) {
  const [pending, setPending] = useState(false);
  return (
    <div className="flex flex-col gap-2 rounded-lg border border-brand/40 bg-brand-soft p-3">
      <p className="text-xs">Pelanggan memilih QRIS. Cek dana {formatRupiah(total)} sudah masuk di aplikasi QRIS/bank Sudaya sebelum konfirmasi.</p>
      <ConfirmAction
        trigger={
          <Button disabled={pending} className="w-full">
            <QrCodeIcon /> Konfirmasi QRIS diterima
          </Button>
        }
        title="Dana QRIS sudah masuk?"
        description={`Pastikan ${formatRupiah(total)} sudah tercatat di aplikasi QRIS/bank Sudaya. Pesanan lalu masuk antrean untuk dibuat.`}
        confirmLabel="Ya, dana sudah masuk"
        destructive={false}
        onConfirm={() =>
          router.post(`${areaBase(area)}/orders/${orderId}/confirm-qris`, {}, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) })
        }
      />
    </div>
  );
}
