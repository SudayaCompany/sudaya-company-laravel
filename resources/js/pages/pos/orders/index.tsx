import { Head, Link, router } from "@inertiajs/react";
import { ChevronLeftIcon, ChevronRightIcon, ReceiptTextIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { LiveRefresh } from "@/components/orders/live-refresh";
import { OrderStatusBadges } from "@/components/orders/order-parts";
import { Button, buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { PosLayout } from "@/layouts/pos-layout";
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

type Props = {
  outlet: { id: string; name: string; timezone: string };
  outlets: { id: string; name: string }[];
  view: "pending" | "active" | "paid" | "today";
  topic: string;
  q: string | null;
  list: { rows: Row[]; total: number; page: number; pageCount: number };
};

const VIEWS = [
  { id: "pending", label: "Menunggu bayar" },
  { id: "active", label: "Diproses" },
  { id: "paid", label: "Lunas" },
  { id: "today", label: "Semua hari ini" },
] as const;

function query(params: Record<string, string | number | null | undefined>) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) if (value !== null && value !== undefined && value !== "") search.set(key, String(value));
  const text = search.toString();
  return text ? `?${text}` : "";
}

export default function PosOrdersPage({ outlet, outlets, view, q, list, topic }: Props) {
  const time = new Intl.DateTimeFormat("id-ID", { timeStyle: "short", timeZone: outlet.timezone });

  return (
    <PosLayout outletId={outlet.id}>
      <Head title="Pesanan" />
      <main className="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold">Pesanan hari ini</h1>
            <p className="text-sm text-muted-foreground">{outlet.name}</p>
          </div>
          <LiveRefresh topic={topic} only={["list"]} />
          {outlets.length > 1 ? (
            <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/pos/orders${query({ outlet: e.target.value, view })}`)}>
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          ) : null}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {VIEWS.map((v) => (
            <Link
              key={v.id}
              href={`/pos/orders${query({ outlet: outlet.id, view: v.id })}`}
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
            className="flex w-full gap-2 sm:ml-auto sm:w-auto"
            onSubmit={(e) => {
              e.preventDefault();
              const value = String(new FormData(e.currentTarget).get("q") ?? "").trim();
              router.visit(`/pos/orders${query({ outlet: outlet.id, view, q: value })}`);
            }}
          >
            <Input
              type="search"
              name="q"
              defaultValue={q ?? ""}
              maxLength={60}
              placeholder="Kode / nomor / nama"
              aria-label="Cari pesanan"
              className="w-full bg-background sm:w-56"
            />
            <Button type="submit" variant="outline">
              Cari
            </Button>
          </form>
        </div>

        {list.rows.length === 0 ? (
          <EmptyState
            icon={ReceiptTextIcon}
            title="Tidak ada pesanan"
            description={view === "pending" ? "Pesanan self-service yang menunggu pembayaran tampil di sini." : "Belum ada pesanan pada filter ini."}
          />
        ) : (
          <ul className="flex flex-col gap-2">
            {list.rows.map((order) => (
              <li key={order.id}>
                <Link
                  href={`/pos/orders/${order.id}`}
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
                <Link href={`/pos/orders${query({ outlet: outlet.id, view, q, page: list.page - 1 })}`} className={buttonVariants({ variant: "outline", size: "sm" })}>
                  <ChevronLeftIcon /> Sebelumnya
                </Link>
              ) : null}
              {list.page < list.pageCount ? (
                <Link href={`/pos/orders${query({ outlet: outlet.id, view, q, page: list.page + 1 })}`} className={buttonVariants({ variant: "outline", size: "sm" })}>
                  Berikutnya <ChevronRightIcon />
                </Link>
              ) : null}
            </span>
          </nav>
        ) : null}
      </main>
    </PosLayout>
  );
}
