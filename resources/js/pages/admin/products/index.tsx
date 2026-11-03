import { Head, Link, router } from "@inertiajs/react";
import { CoffeeIcon, ImageOffIcon, PencilIcon, PlusIcon, PowerIcon, SearchIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatQty, formatRupiah } from "@/lib/format";

type Row = {
  id: string;
  name: string;
  sku: string | null;
  slug: string;
  imageUrl: string | null;
  basePrice: number;
  isActive: boolean;
  categoryName: string;
  isAvailable: boolean;
  inventoryUnavailable: boolean;
  lowStock: boolean;
  stockQty: string | null;
  stockUnit: string | null;
};

type Params = {
  q: string | null;
  category: string | null;
  status: "all" | "active" | "inactive";
  availability: "all" | "available" | "sold_out";
  sort: "name" | "price" | "updated";
  dir: "asc" | "desc";
  page: number;
  outlet: string | null;
};

type Props = {
  brand: { id: string; name: string };
  outlets: { id: string; code: string; name: string }[];
  outlet: { id: string; name: string } | null;
  params: Params;
  list: { rows: Row[]; total: number; page: number; pageCount: number };
  categories: { id: string; name: string }[];
  can: { create: boolean; edit: boolean; delete: boolean; toggleAvailability: boolean };
};

/** Query string tanpa nilai default, agar URL tetap pendek. */
function query(values: Record<string, string | number | null | undefined>) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) {
    if (value !== null && value !== undefined && value !== "") params.set(key, String(value));
  }
  const text = params.toString();
  return text ? `?${text}` : "";
}

export default function Products({ brand, outlets, outlet, params, list, categories, can }: Props) {
  const filters = {
    q: params.q,
    category: params.category,
    status: params.status === "all" ? null : params.status,
    availability: params.availability === "all" ? null : params.availability,
    sort: params.sort === "name" ? null : params.sort,
    dir: params.dir === "asc" ? null : params.dir,
    outlet: params.outlet,
  };
  const hasFilters = Boolean(params.q || params.category || filters.status || filters.availability);

  return (
    <AdminLayout>
      <Head title="Produk" />
      <PageHeader
        title="Produk"
        description={`${brand.name} · ${list.total} produk${outlet ? ` · Ketersediaan untuk ${outlet.name}` : ""}`}
      >
        {can.create ? (
          <Link href="/admin/products/create" className={buttonVariants()}>
            <PlusIcon /> Produk baru
          </Link>
        ) : null}
      </PageHeader>

      <form
        className="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_auto_auto_auto_auto_auto]"
        onSubmit={(event) => {
          event.preventDefault();
          const data = new FormData(event.currentTarget);
          const values = Object.fromEntries([...data.entries()].map(([k, v]) => [k, String(v)]));
          router.get(`/admin/products${query(values)}`, {}, { preserveState: true });
        }}
      >
        <div className="relative">
          <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input name="q" defaultValue={params.q ?? ""} placeholder="Cari nama atau SKU" className="pl-8" aria-label="Cari produk" />
        </div>
        <NativeSelect name="category" defaultValue={params.category ?? ""} aria-label="Kategori" className="w-full">
          <NativeSelectOption value="">Semua kategori</NativeSelectOption>
          {categories.map((c) => (
            <NativeSelectOption key={c.id} value={c.id}>
              {c.name}
            </NativeSelectOption>
          ))}
        </NativeSelect>
        <NativeSelect name="status" defaultValue={params.status} aria-label="Status" className="w-full">
          <NativeSelectOption value="all">Semua status</NativeSelectOption>
          <NativeSelectOption value="active">Aktif</NativeSelectOption>
          <NativeSelectOption value="inactive">Nonaktif</NativeSelectOption>
        </NativeSelect>
        {outlet ? (
          <NativeSelect name="availability" defaultValue={params.availability} aria-label="Ketersediaan" className="w-full">
            <NativeSelectOption value="all">Semua ketersediaan</NativeSelectOption>
            <NativeSelectOption value="available">Tersedia</NativeSelectOption>
            <NativeSelectOption value="sold_out">Sold out</NativeSelectOption>
          </NativeSelect>
        ) : null}
        <NativeSelect name="sort" defaultValue={params.sort} aria-label="Urutkan" className="w-full">
          <NativeSelectOption value="name">Urut: Nama</NativeSelectOption>
          <NativeSelectOption value="price">Urut: Harga</NativeSelectOption>
          <NativeSelectOption value="updated">Urut: Terakhir diubah</NativeSelectOption>
        </NativeSelect>
        <div className="flex gap-2">
          <NativeSelect name="dir" defaultValue={params.dir} aria-label="Arah urutan" className="w-full">
            <NativeSelectOption value="asc">Naik</NativeSelectOption>
            <NativeSelectOption value="desc">Turun</NativeSelectOption>
          </NativeSelect>
          {outlets.length > 1 ? (
            <NativeSelect name="outlet" defaultValue={outlet?.id} aria-label="Outlet" className="w-full">
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          ) : null}
          <Button type="submit" variant="outline">
            Terapkan
          </Button>
        </div>
      </form>

      {list.rows.length === 0 ? (
        <EmptyState
          icon={CoffeeIcon}
          title={hasFilters ? "Tidak ada produk yang cocok" : "Belum ada produk"}
          description={
            hasFilters
              ? "Ubah kata kunci atau filter."
              : categories.length === 0
                ? "Buat kategori dulu, lalu tambahkan produk."
                : "Tambahkan produk pertama untuk menu customer."
          }
        />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-14">Foto</TableHead>
                  <TableHead>Produk</TableHead>
                  <TableHead>Kategori</TableHead>
                  <TableHead className="text-right">Harga</TableHead>
                  <TableHead>Status</TableHead>
                  {outlet ? <TableHead>Tersedia</TableHead> : null}
                  <TableHead className="text-right">Aksi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.rows.map((product) => (
                  <TableRow key={product.id}>
                    <TableCell>
                      <div className="relative size-10 overflow-hidden rounded-md bg-muted">
                        {product.imageUrl ? (
                          <img src={product.imageUrl} alt="" loading="lazy" className="size-full object-cover" />
                        ) : (
                          <ImageOffIcon className="absolute inset-0 m-auto size-4 text-muted-foreground" />
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <p className="font-medium">{product.name}</p>
                      <p className="font-mono text-xs text-muted-foreground">
                        {product.sku ?? "—"} · {product.slug}
                      </p>
                    </TableCell>
                    <TableCell className="text-sm">{product.categoryName}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatRupiah(product.basePrice)}</TableCell>
                    <TableCell>
                      <Badge variant={product.isActive ? "secondary" : "outline"}>{product.isActive ? "Aktif" : "Nonaktif"}</Badge>
                    </TableCell>
                    {outlet ? (
                      <TableCell>
                        <div className="flex flex-col items-start gap-1">
                          <AvailabilitySwitch
                            productId={product.id}
                            productName={product.name}
                            outletId={outlet.id}
                            isAvailable={product.isAvailable}
                            disabled={!can.toggleAvailability}
                          />
                          {product.stockQty !== null ? (
                            <span
                              className={
                                product.inventoryUnavailable
                                  ? "text-xs font-medium text-destructive"
                                  : product.lowStock
                                    ? "text-xs font-medium text-foreground"
                                    : "text-xs text-muted-foreground"
                              }
                            >
                              {product.inventoryUnavailable
                                ? "Stok habis — sold out otomatis"
                                : `${product.lowStock ? "Stok menipis · " : "Stok "}${formatQty(product.stockQty, product.stockUnit)}`}
                            </span>
                          ) : null}
                        </div>
                      </TableCell>
                    ) : null}
                    <TableCell>
                      <RowActions product={product} canEdit={can.edit} canDelete={can.delete} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {list.pageCount > 1 ? (
        <nav aria-label="Halaman" className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Halaman {list.page} dari {list.pageCount}
          </span>
          <div className="flex gap-2">
            {list.page > 1 ? (
              <Link className={buttonVariants({ variant: "outline", size: "sm" })} href={`/admin/products${query({ ...filters, page: list.page - 1 })}`}>
                Sebelumnya
              </Link>
            ) : null}
            {list.page < list.pageCount ? (
              <Link className={buttonVariants({ variant: "outline", size: "sm" })} href={`/admin/products${query({ ...filters, page: list.page + 1 })}`}>
                Berikutnya
              </Link>
            ) : null}
          </div>
        </nav>
      ) : null}
    </AdminLayout>
  );
}

function usePending() {
  const [pending, setPending] = useState(false);
  return { pending, options: { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) } };
}

function AvailabilitySwitch({
  productId,
  productName,
  outletId,
  isAvailable,
  disabled,
}: {
  productId: string;
  productName: string;
  outletId: string;
  isAvailable: boolean;
  disabled: boolean;
}) {
  const { pending, options } = usePending();
  const toggle = (next: boolean) => router.patch(`/admin/products/${productId}/availability`, { outletId, isAvailable: next }, options);

  const status = isAvailable ? (
    <Badge variant="secondary" className="bg-success/15 text-success">
      Tersedia
    </Badge>
  ) : (
    <Badge variant="destructive">Sold out</Badge>
  );
  if (disabled) return status;

  return (
    <div className="flex items-center gap-2">
      {status}
      {isAvailable ? (
        // Menandai sold out butuh konfirmasi; mengembalikan tersedia langsung.
        <ConfirmAction
          trigger={
            <Button variant="outline" size="xs" disabled={pending} aria-label={`Tandai ${productName} sold out`}>
              Sold out
            </Button>
          }
          title="Tandai sold out?"
          description={`${productName} tidak bisa dipesan customer di outlet ini sampai ditandai tersedia lagi.`}
          confirmLabel="Tandai sold out"
          onConfirm={() => toggle(false)}
        />
      ) : (
        <Button variant="outline" size="xs" disabled={pending} aria-label={`Tandai ${productName} tersedia`} onClick={() => toggle(true)}>
          Tersedia lagi
        </Button>
      )}
    </div>
  );
}

function RowActions({ product, canEdit, canDelete }: { product: Row; canEdit: boolean; canDelete: boolean }) {
  const { pending, options } = usePending();
  const setActive = (isActive: boolean) => router.patch(`/admin/products/${product.id}/active`, { isActive }, options);

  return (
    <div className="flex justify-end gap-1">
      {canEdit ? (
        <Link
          href={`/admin/products/${product.id}/edit`}
          className={buttonVariants({ variant: "ghost", size: "icon-sm" })}
          aria-label={`Edit ${product.name}`}
        >
          <PencilIcon />
        </Link>
      ) : null}
      {canEdit ? (
        product.isActive ? (
          <ConfirmAction
            trigger={
              <Button variant="ghost" size="icon-sm" disabled={pending} aria-label={`Nonaktifkan ${product.name}`}>
                <PowerIcon />
              </Button>
            }
            title="Nonaktifkan produk?"
            description={`${product.name} disembunyikan dari menu customer di semua outlet. Data dan histori tetap tersimpan.`}
            confirmLabel="Nonaktifkan"
            onConfirm={() => setActive(false)}
          />
        ) : (
          <Button variant="ghost" size="icon-sm" disabled={pending} aria-label={`Aktifkan ${product.name}`} onClick={() => setActive(true)}>
            <PowerIcon className="text-success" />
          </Button>
        )
      ) : null}
      {canDelete ? (
        <ConfirmAction
          trigger={
            <Button variant="ghost" size="icon-sm" disabled={pending} aria-label={`Hapus ${product.name}`}>
              <Trash2Icon className="text-destructive" />
            </Button>
          }
          title="Hapus produk?"
          description={`${product.name} dihapus dari daftar menu. Histori transaksi yang memakai produk ini tetap utuh.`}
          confirmLabel="Hapus"
          onConfirm={() => router.delete(`/admin/products/${product.id}`, options)}
        />
      ) : null}
    </div>
  );
}
