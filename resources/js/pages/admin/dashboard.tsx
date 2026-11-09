import { Head, Link, router, usePage } from "@inertiajs/react";
import { AlertTriangleIcon, ArrowRightIcon, ClockIcon, CoffeeIcon, ReceiptTextIcon, StoreIcon, WalletIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

type Stats = {
  date: string;
  sales: { revenue: number; orders: number; average: number; items: number };
  pendingPayment: number;
  inProgress: number;
  completedToday: number;
  topProducts: { name: string; quantity: number; revenue: number }[];
  cash: { open: { openedAt: string; expected: number } | null; lastClosed: { closedAt: string; variance: number } | null } | null;
  pendingApprovals: number | null;
  soldOut: { count: number; names: string[] } | null;
};

type Props = {
  outlets: { id: string; name: string; brandName: string; code: string }[];
  outlet: { id: string; name: string; timezone: string } | null;
  stats: Stats | null;
};

function Metric({ label, value, hint, testId }: { label: string; value: string; hint?: string; testId?: string }) {
  return (
    <Card size="sm">
      <CardContent className="flex flex-col gap-1">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold tabular-nums" data-testid={testId}>
          {value}
        </p>
        {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      </CardContent>
    </Card>
  );
}

function variantLabel(variance: number) {
  if (variance === 0) return { text: "Cocok", className: "text-success-strong" };
  return variance > 0
    ? { text: `Uang lebih ${formatRupiah(variance)}`, className: "text-warning-strong" }
    : { text: `Uang kurang ${formatRupiah(-variance)}`, className: "text-destructive-strong" };
}

export default function Dashboard({ outlets, outlet, stats }: Props) {
  const { auth } = usePage<SharedProps>().props;
  const user = auth!.user;
  const canOrders = auth!.navigation.includes("/admin/orders");
  const canProducts = auth!.navigation.includes("/admin/products");
  const dateLabel = stats && outlet ? new Intl.DateTimeFormat("id-ID", { dateStyle: "full", timeZone: outlet.timezone }).format(new Date()) : null;
  const time = outlet ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone }) : null;
  const ordersHref = (view?: string) => `/admin/orders?outlet=${outlet?.id ?? ""}${view ? `&view=${view}` : ""}`;

  return (
    <AdminLayout>
      <Head title="Dasbor" />
      <PageHeader title={`Halo, ${user.fullName}`} description={outlet ? `${outlet.name}${dateLabel ? ` · ${dateLabel}` : ""}` : user.company}>
        {outlets.length > 1 && outlet ? (
          <NativeSelect aria-label="Pilih outlet" value={outlet.id} onChange={(e) => router.visit(`/admin?outlet=${e.target.value}`)}>
            {outlets.map((o) => (
              <NativeSelectOption key={o.id} value={o.id}>
                {o.name}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        ) : null}
      </PageHeader>

      {!outlet || !stats ? (
        <EmptyState icon={StoreIcon} title="Belum ada outlet" description="Akun ini belum terhubung ke outlet aktif. Hubungi Super Admin untuk mengatur peran per outlet." />
      ) : (
        <>
          <section aria-labelledby="penjualan" className="flex flex-col gap-3">
            <h2 id="penjualan" className="text-sm font-semibold text-muted-foreground">
              Penjualan hari ini
            </h2>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Metric label="Penjualan lunas" value={formatRupiah(stats.sales.revenue)} testId="dash-revenue" />
              <Metric label="Transaksi" value={String(stats.sales.orders)} testId="dash-orders" />
              <Metric label="Rata-rata per transaksi" value={formatRupiah(stats.sales.average)} testId="dash-average" />
              <Metric label="Item terjual" value={String(stats.sales.items)} testId="dash-items" />
            </div>
            {stats.sales.orders === 0 ? (
              <p className="text-sm text-muted-foreground">Belum ada transaksi lunas hari ini. Angka akan bertambah setelah kasir mengonfirmasi pembayaran.</p>
            ) : null}
          </section>

          <div className="grid gap-4 lg:grid-cols-3">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <ReceiptTextIcon className="size-4" aria-hidden /> Pesanan
                </CardTitle>
                <CardDescription>Kondisi antrean saat ini</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-2 text-sm">
                {[
                  { label: "Menunggu bayar", value: stats.pendingPayment, view: "pending", id: "dash-pending" },
                  { label: "Sedang diproses", value: stats.inProgress, view: "active", id: "dash-active" },
                  { label: "Selesai hari ini", value: stats.completedToday, view: "paid", id: "dash-completed" },
                ].map((row) => (
                  <div key={row.label} className="flex items-center justify-between">
                    <span>{row.label}</span>
                    {canOrders ? (
                      <Link href={ordersHref(row.view)} className="font-semibold tabular-nums underline-offset-4 hover:underline" data-testid={row.id}>
                        {row.value}
                      </Link>
                    ) : (
                      <span className="font-semibold tabular-nums" data-testid={row.id}>
                        {row.value}
                      </span>
                    )}
                  </div>
                ))}
              </CardContent>
            </Card>

            {stats.pendingApprovals !== null ? (
              <Card className={cn(stats.pendingApprovals > 0 && "ring-brand/50")}>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <ClockIcon className="size-4" aria-hidden /> Persetujuan
                  </CardTitle>
                  <CardDescription>Pengajuan pembatalan pesanan lunas</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3 text-sm">
                  <p>
                    <span className="text-3xl font-semibold tabular-nums" data-testid="dash-approvals">
                      {stats.pendingApprovals}
                    </span>{" "}
                    menunggu keputusan
                  </p>
                  {stats.pendingApprovals > 0 && canOrders ? (
                    <Link href={ordersHref()} className="inline-flex min-h-10 items-center gap-1 font-medium text-brand-strong pointer-coarse:min-h-11">
                      Tinjau pengajuan <ArrowRightIcon className="size-4" aria-hidden />
                    </Link>
                  ) : null}
                </CardContent>
              </Card>
            ) : null}

            {stats.cash !== null ? (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <WalletIcon className="size-4" aria-hidden /> Kas
                  </CardTitle>
                  <CardDescription>{stats.cash.open ? "Sesi kas sedang dibuka" : "Tidak ada sesi kas terbuka"}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-2 text-sm">
                  {stats.cash.open && time ? (
                    <div className="flex items-center justify-between">
                      <span>Saldo sistem</span>
                      <span className="font-semibold tabular-nums" data-testid="dash-cash-expected">
                        {formatRupiah(stats.cash.open.expected)}
                      </span>
                    </div>
                  ) : null}
                  {stats.cash.open && time ? <p className="text-xs text-muted-foreground">Dibuka {time.format(new Date(stats.cash.open.openedAt))}</p> : null}
                  {stats.cash.lastClosed && time ? (
                    <div className="flex flex-col gap-0.5 border-t pt-2">
                      <span className="text-muted-foreground">Sesi terakhir ditutup {time.format(new Date(stats.cash.lastClosed.closedAt))}</span>
                      <span className={cn("font-medium", variantLabel(stats.cash.lastClosed.variance).className)} data-testid="dash-cash-variance">
                        {variantLabel(stats.cash.lastClosed.variance).text}
                      </span>
                    </div>
                  ) : (
                    <p className="text-muted-foreground">Belum ada sesi kas yang ditutup.</p>
                  )}
                </CardContent>
              </Card>
            ) : null}
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <CoffeeIcon className="size-4" aria-hidden /> Terlaris hari ini
                </CardTitle>
              </CardHeader>
              <CardContent>
                {stats.topProducts.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Belum ada menu terjual hari ini.</p>
                ) : (
                  <ol className="flex flex-col gap-2 text-sm" data-testid="dash-top">
                    {stats.topProducts.map((p, i) => (
                      <li key={p.name} className="flex items-center gap-3">
                        <span className="w-5 text-muted-foreground tabular-nums">{i + 1}</span>
                        <span className="min-w-0 flex-1 truncate font-medium">{p.name}</span>
                        <span className="tabular-nums">{p.quantity}×</span>
                        <span className="w-28 text-right text-muted-foreground tabular-nums">{formatRupiah(p.revenue)}</span>
                      </li>
                    ))}
                  </ol>
                )}
              </CardContent>
            </Card>

            {stats.soldOut !== null ? (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <AlertTriangleIcon className="size-4" aria-hidden /> Menu habis
                  </CardTitle>
                  <CardDescription>Menu aktif yang tidak bisa dipesan di outlet ini</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-2 text-sm">
                  {stats.soldOut.count === 0 ? (
                    <p className="text-muted-foreground" data-testid="dash-soldout">
                      Semua menu aktif tersedia.
                    </p>
                  ) : (
                    <>
                      <p data-testid="dash-soldout">
                        <span className="font-semibold tabular-nums">{stats.soldOut.count}</span> menu habis: {stats.soldOut.names.join(", ")}
                        {stats.soldOut.count > stats.soldOut.names.length ? ", …" : ""}
                      </p>
                      {canProducts ? (
                        <Link
                          href={`/admin/products?outlet=${outlet.id}&availability=sold_out`}
                          className="inline-flex min-h-10 items-center gap-1 font-medium text-brand-strong pointer-coarse:min-h-11"
                        >
                          Atur ketersediaan <ArrowRightIcon className="size-4" aria-hidden />
                        </Link>
                      ) : null}
                    </>
                  )}
                </CardContent>
              </Card>
            ) : null}
          </div>
        </>
      )}
    </AdminLayout>
  );
}
