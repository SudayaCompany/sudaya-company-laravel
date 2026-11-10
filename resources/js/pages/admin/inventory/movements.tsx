import { Head, Link, router } from "@inertiajs/react";
import { ChevronLeftIcon, ChevronRightIcon, HistoryIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { RecentMovements, type ItemOption, type Movement } from "@/components/inventory/stock-parts";
import { PageHeader } from "@/components/page-header";
import { buttonVariants } from "@/components/ui/button";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { cn } from "@/lib/utils";

type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  items: ItemOption[];
  rows: Movement[];
  total: number;
  page: number;
  pageCount: number;
  params: { item: string | null; group: string | null };
};

const GROUPS = [
  { id: null, label: "Semua" },
  { id: "in", label: "Masuk" },
  { id: "out", label: "Keluar & waste" },
  { id: "sale", label: "Penjualan" },
  { id: "opname", label: "Opname" },
] as const;

/** Riwayat ledger stok (append-only): setiap perubahan stok bisa ditelusuri (PRD §106). */
export default function Movements({ outlets, outlet, items, rows, total, page, pageCount, params }: Props) {
  const href = (values: Record<string, string | number | null>) => {
    const next = { outlet: outlet.id, item: params.item, group: params.group, ...values };
    const search = new URLSearchParams(Object.entries(next).filter(([, v]) => v !== null && v !== "").map(([k, v]) => [k, String(v)]));
    return `/admin/inventory/movements?${search.toString()}`;
  };

  return (
    <AdminLayout breadcrumbs={[{ label: "Stok", href: `/admin/inventory?outlet=${outlet.id}` }, { label: "Riwayat stok" }]}>
      <Head title="Riwayat stok" />
      <PageHeader title="Riwayat stok" description={`${outlet.name} · ${total} catatan. Catatan tidak bisa diubah; koreksi lewat stok masuk/keluar atau opname.`} />
      <div className="flex flex-wrap items-center gap-2">
        {GROUPS.map((g) => (
          <Link
            key={g.label}
            href={href({ group: g.id, page: null })}
            aria-current={params.group === g.id ? "page" : undefined}
            className={cn(
              "flex min-h-10 items-center rounded-full border px-4 text-sm font-medium pointer-coarse:min-h-11",
              params.group === g.id ? "border-espresso bg-espresso text-espresso-foreground" : "bg-background hover:border-foreground",
            )}
          >
            {g.label}
          </Link>
        ))}
        <NativeSelect aria-label="Item" value={params.item ?? ""} onChange={(e) => router.visit(href({ item: e.target.value || null, page: null }))}>
          <NativeSelectOption value="">Semua item</NativeSelectOption>
          {items.map((i) => (
            <NativeSelectOption key={i.id} value={i.id}>
              {i.label}
            </NativeSelectOption>
          ))}
        </NativeSelect>
        {outlets.length > 1 ? (
          <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/admin/inventory/movements?outlet=${e.target.value}`)}>
            {outlets.map((o) => (
              <NativeSelectOption key={o.id} value={o.id}>
                {o.name}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        ) : null}
      </div>
      {rows.length === 0 ? (
        <EmptyState icon={HistoryIcon} title="Belum ada riwayat" description="Stok masuk, keluar, penjualan, dan opname tercatat di sini." />
      ) : (
        <div className="max-w-4xl">
          <RecentMovements title={`Halaman ${page} dari ${pageCount}`} rows={rows} timezone={outlet.timezone} limit={50} />
        </div>
      )}
      {pageCount > 1 ? (
        <nav aria-label="Halaman" className="flex max-w-4xl items-center justify-end gap-2">
          {page > 1 ? (
            <Link href={href({ page: page - 1 })} className={buttonVariants({ variant: "outline", size: "sm" })}>
              <ChevronLeftIcon /> Sebelumnya
            </Link>
          ) : null}
          {page < pageCount ? (
            <Link href={href({ page: page + 1 })} className={buttonVariants({ variant: "outline", size: "sm" })}>
              Berikutnya <ChevronRightIcon />
            </Link>
          ) : null}
        </nav>
      ) : null}
    </AdminLayout>
  );
}
