import { Head, Link, router } from "@inertiajs/react";
import { ChevronLeftIcon, ChevronRightIcon, ClockIcon, ReceiptTextIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { ApprovalDecision } from "@/components/orders/approval-decision";
import { LiveRefresh } from "@/components/orders/live-refresh";
import { OrderStatusBadges } from "@/components/orders/order-parts";
import { PageHeader } from "@/components/page-header";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { CHANNEL_LABELS, NO_NAME_LABEL, ORDER_TYPE_LABELS, type OrderChannel, type OrderStatus, type OrderType, type PaymentStatus } from "@/lib/orders";
import { cn } from "@/lib/utils";

type Row = {
  id: string;
  reference: string;
  orderNumber: string | null;
  channel: OrderChannel;
  type: OrderType;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  customerName: string | null;
  tableNumber: string | null;
  total: number;
  createdAt: string;
};

export type PendingApproval = {
  id: string;
  reason: string;
  requestedByName: string;
  ownRequest: boolean;
  createdAt: string;
  orderId: string;
  reference: string;
  orderNumber: string | null;
  orderStatus: OrderStatus;
  customerName: string | null;
  total: number;
};

type View = "all" | "pending" | "active" | "paid";

type Props = {
  outlet: { id: string; name: string; timezone: string };
  outlets: { id: string; name: string }[];
  view: View;
  q: string | null;
  date: string;
  today: string;
  list: { rows: Row[]; total: number; page: number; pageCount: number };
  approvals: PendingApproval[] | null;
  canDecide: boolean;
  topic: string;
};

const VIEWS: { id: View; label: string }[] = [
  { id: "all", label: "Semua" },
  { id: "pending", label: "Menunggu bayar" },
  { id: "active", label: "Diproses" },
  { id: "paid", label: "Lunas" },
];

function query(params: Record<string, string | number | null | undefined>) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) if (value !== null && value !== undefined && value !== "") search.set(key, String(value));
  const text = search.toString();
  return text ? `?${text}` : "";
}

/** Pesanan untuk Owner/Manager: pantau per tanggal dan putuskan pembatalan. Tanpa aksi kasir. */
export default function AdminOrdersPage({ outlet, outlets, view, q, date, today, list, approvals, canDecide, topic }: Props) {
  const time = new Intl.DateTimeFormat("id-ID", { timeStyle: "short", timeZone: outlet.timezone });
  const base = { outlet: outlet.id, view: view === "all" ? null : view, q, date: date === today ? null : date };
  const visit = (params: Record<string, string | number | null | undefined>) => router.visit(`/admin/orders${query({ ...base, ...params })}`);

  return (
    <AdminLayout breadcrumbs={[{ label: "Dasbor", href: "/admin" }, { label: "Pesanan" }]}>
      <Head title="Pesanan" />
      <PageHeader title="Pesanan" description={`${outlet.name} · ${date === today ? "hari ini" : new Intl.DateTimeFormat("id-ID", { dateStyle: "long" }).format(new Date(`${date}T00:00:00`))}`}>
        <div className="flex flex-wrap items-center gap-3">
          <LiveRefresh topic={topic} only={["list", "approvals"]} />
          {outlets.length > 1 ? (
            <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => visit({ outlet: e.target.value, page: null })}>
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          ) : null}
        </div>
      </PageHeader>

      {approvals !== null ? (
        <Card className={cn(approvals.length > 0 && "ring-brand/50")}>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ClockIcon className="size-4" aria-hidden /> Pengajuan pembatalan
            </CardTitle>
            <CardDescription>
              {approvals.length === 0 ? "Tidak ada pengajuan yang menunggu keputusan." : `${approvals.length} pengajuan menunggu keputusan, terlama di atas.`}
            </CardDescription>
          </CardHeader>
          {approvals.length > 0 ? (
            <CardContent>
              <ul className="flex flex-col gap-3" data-testid="approval-inbox">
                {approvals.map((a) => (
                  <li key={a.id} className="flex flex-col gap-3 rounded-lg border p-3 sm:flex-row sm:items-center">
                    <div className="min-w-0 flex-1 text-sm">
                      <p className="flex flex-wrap items-center gap-2">
                        <Link href={`/admin/orders/${a.orderId}`} className="font-mono text-lg font-bold underline-offset-4 hover:underline">
                          {a.orderNumber ?? a.reference}
                        </Link>
                        <span className="font-semibold tabular-nums">{formatRupiah(a.total)}</span>
                        <span className="text-muted-foreground">{a.customerName ?? NO_NAME_LABEL}</span>
                      </p>
                      <p className="break-words">“{a.reason}”</p>
                      <p className="text-xs text-muted-foreground">
                        Diajukan {a.requestedByName} · {time.format(new Date(a.createdAt))}
                      </p>
                    </div>
                    {a.ownRequest ? (
                      <p className="text-xs text-muted-foreground">Pengajuan Anda sendiri, menunggu Manager lain.</p>
                    ) : canDecide ? (
                      <ApprovalDecision approvalId={a.id} total={a.total} size="default" />
                    ) : null}
                  </li>
                ))}
              </ul>
            </CardContent>
          ) : null}
        </Card>
      ) : null}

      <div className="flex flex-wrap items-center gap-2">
        {VIEWS.map((v) => (
          <Link
            key={v.id}
            href={`/admin/orders${query({ ...base, view: v.id === "all" ? null : v.id })}`}
            aria-current={v.id === view ? "page" : undefined}
            className={cn(
              "flex min-h-10 items-center rounded-full border px-4 text-sm font-medium pointer-coarse:min-h-11",
              v.id === view ? "border-espresso bg-espresso text-espresso-foreground" : "bg-background hover:border-foreground",
            )}
          >
            {v.label}
          </Link>
        ))}
        <form
          className="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto"
          onSubmit={(e) => {
            e.preventDefault();
            const data = new FormData(e.currentTarget);
            visit({ q: String(data.get("q") ?? "").trim(), date: String(data.get("date") ?? "") || null, page: null });
          }}
        >
          <Input type="date" name="date" defaultValue={date} max={today} aria-label="Tanggal" className="w-full bg-background sm:w-40" />
          <Input type="search" name="q" defaultValue={q ?? ""} maxLength={60} placeholder="Kode / nomor / nama" aria-label="Cari pesanan" className="w-full bg-background sm:w-52" />
          <Button type="submit" variant="outline">
            Tampilkan
          </Button>
        </form>
      </div>

      {list.rows.length === 0 ? (
        <EmptyState icon={ReceiptTextIcon} title="Tidak ada pesanan" description="Belum ada pesanan pada tanggal dan filter ini." />
      ) : (
        <ul className="flex flex-col gap-2">
          {list.rows.map((order) => (
            <li key={order.id}>
              <Link
                href={`/admin/orders/${order.id}`}
                className="flex flex-wrap items-center gap-3 rounded-xl border bg-background p-3 transition-colors hover:border-foreground"
              >
                <span className="w-24 font-mono text-lg font-bold">{order.orderNumber ?? order.reference}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{order.customerName ?? NO_NAME_LABEL}</span>
                  <span className="block text-xs text-muted-foreground">
                    {CHANNEL_LABELS[order.channel]} · {ORDER_TYPE_LABELS[order.type]}
                    {order.tableNumber ? ` · Meja ${order.tableNumber}` : ""} · {time.format(new Date(order.createdAt))}
                  </span>
                </span>
                <OrderStatusBadges status={order.status} paymentStatus={order.paymentStatus} />
                <span className="w-24 text-right font-semibold tabular-nums">{formatRupiah(order.total)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {list.pageCount > 1 ? (
        <nav aria-label="Halaman" className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            {list.total} pesanan · halaman {list.page} dari {list.pageCount}
          </span>
          <span className="flex gap-2">
            {list.page > 1 ? (
              <Link href={`/admin/orders${query({ ...base, page: list.page - 1 })}`} className={buttonVariants({ variant: "outline", size: "sm" })}>
                <ChevronLeftIcon /> Sebelumnya
              </Link>
            ) : null}
            {list.page < list.pageCount ? (
              <Link href={`/admin/orders${query({ ...base, page: list.page + 1 })}`} className={buttonVariants({ variant: "outline", size: "sm" })}>
                Berikutnya <ChevronRightIcon />
              </Link>
            ) : null}
          </span>
        </nav>
      ) : null}
    </AdminLayout>
  );
}
