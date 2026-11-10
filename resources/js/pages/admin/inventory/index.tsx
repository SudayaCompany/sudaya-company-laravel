import { Head, Link, router } from "@inertiajs/react";
import { ArrowDownToLineIcon, ArrowUpFromLineIcon, BoxesIcon, CircleCheckIcon, CircleOffIcon, PencilIcon, PlusIcon, SearchIcon, TriangleAlertIcon, XIcon } from "lucide-react";
import { useRef } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

export type StockStatus = "ok" | "low" | "out" | "inactive";
type Row = {
  id: string;
  sku: string;
  name: string;
  kind: string;
  kindLabel: string;
  category: string | null;
  unit: string;
  stock: string;
  minimum: string;
  status: StockStatus;
  purchasePrice: number | null;
  expiryDate: string | null;
  isActive: boolean;
  linkedProducts: string[];
};
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  kinds: Record<string, string>;
  rows: Row[];
  counts: { ok: number; low: number; out: number; inactive: number };
  params: { q: string | null; kind: string | null; status: StockStatus | null };
  can: { adjust: boolean; receive: boolean; issue: boolean; opname: boolean };
};

/** Warna status selalu disertai ikon + teks (PRD §25: Aman / Menipis / Habis). */
export const STATUS: Record<StockStatus, { label: string; icon: typeof CircleCheckIcon; badge: string; tile: string }> = {
  ok: { label: "Aman", icon: CircleCheckIcon, badge: "bg-success/15 text-success-strong", tile: "text-success-strong" },
  low: { label: "Menipis", icon: TriangleAlertIcon, badge: "bg-warning/20 text-foreground", tile: "text-warning-strong" },
  out: { label: "Habis", icon: CircleOffIcon, badge: "bg-destructive/10 text-destructive-strong", tile: "text-destructive-strong" },
  inactive: { label: "Nonaktif", icon: CircleOffIcon, badge: "bg-muted text-muted-foreground", tile: "text-muted-foreground" },
};

export function StatusBadge({ status }: { status: StockStatus }) {
  const s = STATUS[status];
  return (
    <span className={cn("inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap", s.badge)}>
      <s.icon className="size-3.5" aria-hidden /> {s.label}
    </span>
  );
}

export default function InventoryIndex({ outlets, outlet, kinds, rows, counts, params, can }: Props) {
  const timer = useRef<number | undefined>(undefined);
  const go = (values: Partial<Record<"q" | "kind" | "status" | "outlet", string | null>>) => {
    const next = { outlet: outlet.id, ...params, ...values };
    router.get("/admin/inventory", Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== "")), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };
  const filtered = Boolean(params.q || params.kind || params.status);
  const qs = `outlet=${outlet.id}`;

  return (
    <AdminLayout>
      <Head title="Stok" />
      <PageHeader title="Stok" description={`${outlet.name} · ${counts.ok + counts.low + counts.out} item aktif`}>
        <div className="flex flex-wrap gap-2">
          {can.receive ? (
            <Link href={`/admin/inventory/stock-in?${qs}`} className={buttonVariants({ variant: "outline" })}>
              <ArrowDownToLineIcon /> Stok masuk
            </Link>
          ) : null}
          {can.issue ? (
            <Link href={`/admin/inventory/stock-out?${qs}`} className={buttonVariants({ variant: "outline" })}>
              <ArrowUpFromLineIcon /> Stok keluar
            </Link>
          ) : null}
          {can.adjust ? (
            <Link href={`/admin/inventory/items/create?${qs}`} className={buttonVariants()}>
              <PlusIcon /> Item baru
            </Link>
          ) : null}
        </div>
      </PageHeader>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4" role="group" aria-label="Saring status stok">
        {(["ok", "low", "out", "inactive"] as const).map((key) => {
          const s = STATUS[key];
          const active = params.status === key;
          return (
            <button
              key={key}
              type="button"
              aria-pressed={active}
              onClick={() => go({ status: active ? null : key })}
              className={cn(
                "flex flex-col items-start gap-1 rounded-xl bg-card p-4 text-left ring-1 ring-foreground/10 transition-shadow hover:ring-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                active && "ring-2 ring-espresso",
              )}
            >
              <span className={cn("flex items-center gap-1.5 text-sm", s.tile)}>
                <s.icon className="size-4" aria-hidden /> {s.label}
              </span>
              <span className="text-2xl font-semibold" data-testid={`stock-count-${key}`}>
                {counts[key]}
              </span>
            </button>
          );
        })}
      </div>

      <div className="flex flex-wrap gap-2">
        <div className="relative w-full sm:w-72">
          <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            type="search"
            defaultValue={params.q ?? ""}
            placeholder="Cari nama, SKU, atau kategori"
            aria-label="Cari item stok"
            className="pl-8"
            onChange={(e) => {
              const value = e.target.value;
              window.clearTimeout(timer.current);
              timer.current = window.setTimeout(() => go({ q: value.trim() || null }), 400);
            }}
          />
        </div>
        <NativeSelect aria-label="Jenis" value={params.kind ?? ""} onChange={(e) => go({ kind: e.target.value || null })}>
          <NativeSelectOption value="">Semua jenis</NativeSelectOption>
          {Object.entries(kinds).map(([value, label]) => (
            <NativeSelectOption key={value} value={value}>
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
        {filtered ? (
          <Link href={`/admin/inventory?${qs}`} className={buttonVariants({ variant: "ghost" })}>
            <XIcon /> Hapus filter
          </Link>
        ) : null}
      </div>

      {rows.length === 0 ? (
        <EmptyState
          icon={BoxesIcon}
          title={filtered ? "Tidak ada item yang cocok" : "Belum ada item stok"}
          description={
            filtered
              ? "Ubah kata kunci atau filter."
              : "Tambahkan bahan baku (kopi, susu, cup) dan produk jadi (botol, snack). Produk jadi bisa ditautkan ke menu agar stoknya terpotong otomatis saat terjual."
          }
        />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead className="text-right">Stok</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="hidden md:table-cell">Harga beli</TableHead>
                  <TableHead className="text-right">Aksi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.id} className="align-top">
                    <TableCell className="min-w-56">
                      <span className="block font-medium">{r.name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {r.sku} · {r.kindLabel}
                        {r.category ? ` · ${r.category}` : ""}
                        {r.expiryDate ? ` · exp ${r.expiryDate}` : ""}
                      </span>
                      {r.linkedProducts.length > 0 ? (
                        <span className="mt-1 flex flex-wrap gap-1">
                          {r.linkedProducts.map((p) => (
                            <Badge key={p} variant="secondary" className="font-normal">
                              Menu: {p}
                            </Badge>
                          ))}
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell className="text-right whitespace-nowrap">
                      <span className="block font-semibold tabular-nums">{r.stock}</span>
                      <span className="block text-xs text-muted-foreground">min {r.minimum}</span>
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={r.status} />
                    </TableCell>
                    <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                      {r.purchasePrice === null ? "—" : `${formatRupiah(r.purchasePrice)}/${r.unit}`}
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap justify-end gap-1.5">
                        {can.receive && r.isActive ? (
                          <Link
                            href={`/admin/inventory/stock-in?${qs}&item=${r.id}`}
                            className={buttonVariants({ variant: "outline", size: "sm" })}
                            aria-label={`Stok masuk ${r.name}`}
                          >
                            <ArrowDownToLineIcon /> Masuk
                          </Link>
                        ) : null}
                        {can.issue && r.isActive ? (
                          <Link
                            href={`/admin/inventory/stock-out?${qs}&item=${r.id}`}
                            className={buttonVariants({ variant: "outline", size: "sm" })}
                            aria-label={`Stok keluar ${r.name}`}
                          >
                            <ArrowUpFromLineIcon /> Keluar
                          </Link>
                        ) : null}
                        {can.adjust ? (
                          <Link href={`/admin/inventory/items/${r.id}/edit`} className={buttonVariants({ variant: "ghost", size: "sm" })} aria-label={`Ubah ${r.name}`}>
                            <PencilIcon /> Ubah
                          </Link>
                        ) : null}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </AdminLayout>
  );
}
