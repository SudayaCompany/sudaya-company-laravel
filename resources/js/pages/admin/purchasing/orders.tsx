import { Head, Link, router } from "@inertiajs/react";
import { PlusIcon, ShoppingCartIcon, TruckIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";

export type PoStatus = "draft" | "submitted" | "approved" | "rejected" | "cancelled" | "received";
type Order = { id: string; number: string; status: PoStatus; statusLabel: string; total: number; expectedDate: string | null; createdAt: string | null; receivedAt: string | null; supplier: string; createdBy: string | null };
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  status: string | null;
  statuses: Record<string, string>;
  orders: Order[];
  canCreate: boolean;
};

export const PO_BADGE: Record<PoStatus, string> = {
  draft: "text-muted-foreground",
  submitted: "border-warning/50 bg-warning/10 text-foreground",
  approved: "border-brand/50 bg-brand-soft text-foreground",
  rejected: "border-destructive/40 bg-destructive/10 text-destructive-strong",
  cancelled: "text-muted-foreground",
  received: "border-success/40 bg-success/10 text-success-strong",
};

export default function PurchaseOrders({ outlets, outlet, status, statuses, orders, canCreate }: Props) {
  const stamp = new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", year: "numeric", timeZone: outlet.timezone });
  const go = (params: Record<string, string | null>) => router.get("/admin/purchase-orders", { outlet: outlet.id, status, ...params }, { preserveScroll: true, replace: true });

  return (
    <AdminLayout>
      <Head title="Purchase order" />
      <PageHeader title="Purchase order" description={`${outlet.name} · ajukan pembelian → disetujui Owner/Manager → terima barang → stok bertambah otomatis`}>
        <div className="flex flex-wrap gap-2">
          <Link href="/admin/suppliers" className={buttonVariants({ variant: "outline" })}>
            <TruckIcon /> Supplier
          </Link>
          {canCreate ? (
            <Link href={`/admin/purchase-orders/create?outlet=${outlet.id}`} className={buttonVariants()}>
              <PlusIcon /> Buat PO
            </Link>
          ) : null}
        </div>
      </PageHeader>

      <div className="flex flex-wrap gap-3">
        <NativeSelect aria-label="Status" value={status ?? ""} onChange={(e) => go({ status: e.target.value || null })}>
          <NativeSelectOption value="">Semua status</NativeSelectOption>
          {Object.entries(statuses)
            .filter(([key]) => key !== "draft")
            .map(([key, label]) => (
              <NativeSelectOption key={key} value={key}>
                {label}
              </NativeSelectOption>
            ))}
        </NativeSelect>
        {outlets.length > 1 ? (
          <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => go({ outlet: e.target.value })}>
            {outlets.map((o) => (
              <NativeSelectOption key={o.id} value={o.id}>
                {o.name}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        ) : null}
      </div>

      {orders.length === 0 ? (
        <EmptyState icon={ShoppingCartIcon} title="Belum ada purchase order" description="Buat PO untuk memesan bahan ke supplier; stok bertambah saat barang diterima." />
      ) : (
        <ul className="flex flex-col gap-3">
          {orders.map((o) => (
            <li key={o.id}>
              <Link
                href={`/admin/purchase-orders/${o.id}`}
                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4 transition-colors hover:border-foreground/40 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
              >
                <div className="min-w-0">
                  <p className="font-semibold">
                    {o.number} <span className="font-normal text-muted-foreground">· {o.supplier}</span>
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Dibuat {o.createdAt ? stamp.format(new Date(o.createdAt)) : "—"}
                    {o.createdBy ? ` oleh ${o.createdBy}` : ""}
                    {o.expectedDate ? ` · perkiraan datang ${stamp.format(new Date(`${o.expectedDate}T12:00:00Z`))}` : ""}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <span className="font-semibold tabular-nums">{formatRupiah(o.total)}</span>
                  <Badge variant="outline" className={PO_BADGE[o.status]}>
                    {o.statusLabel}
                  </Badge>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </AdminLayout>
  );
}
