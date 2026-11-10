import { Head, Link, router } from "@inertiajs/react";
import { CookingPotIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";

type Product = { id: string; name: string; category: string | null; isActive: boolean; lines: number; linkedFinishedGood: boolean };
type Props = { outlets: { id: string; name: string }[]; outlet: { id: string; name: string }; products: Product[] };

export default function RecipesIndex({ outlets, outlet, products }: Props) {
  const withRecipe = products.filter((p) => p.lines > 0).length;
  const groups = products.reduce<Record<string, Product[]>>((acc, p) => {
    (acc[p.category ?? "Tanpa kategori"] ??= []).push(p);
    return acc;
  }, {});

  return (
    <AdminLayout>
      <Head title="Resep (BOM)" />
      <PageHeader
        title="Resep (BOM)"
        description={`${outlet.name} · ${withRecipe} dari ${products.length} menu punya resep. Bahan dipotong otomatis saat pesanan dibayar; penjualan tidak pernah ditolak karena bahan.`}
      >
        {outlets.length > 1 ? (
          <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.get("/admin/inventory/recipes", { outlet: e.target.value })}>
            {outlets.map((o) => (
              <NativeSelectOption key={o.id} value={o.id}>
                {o.name}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        ) : null}
      </PageHeader>

      {products.length === 0 ? (
        <EmptyState icon={CookingPotIcon} title="Belum ada menu" description="Tambahkan menu di Produk → Menu, lalu atur resepnya di sini." />
      ) : (
        Object.entries(groups).map(([category, items]) => (
          <section key={category} className="flex flex-col gap-2" aria-label={category}>
            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">{category}</h2>
            <ul className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
              {items.map((p) => (
                <li key={p.id}>
                  <Link
                    href={`/admin/inventory/recipes/${p.id}?outlet=${outlet.id}`}
                    className="flex min-h-14 items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 transition-colors hover:border-foreground/40 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                  >
                    <span className="min-w-0">
                      <span className="block font-medium break-words">{p.name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {p.linkedFinishedGood ? "Produk jadi tertaut stok" : p.lines > 0 ? `${p.lines} bahan` : "Belum ada resep"}
                        {p.isActive ? "" : " · nonaktif"}
                      </span>
                    </span>
                    {p.lines > 0 ? (
                      <Badge variant="outline" className="border-success/40 bg-success/10 text-success-strong">
                        Ada resep
                      </Badge>
                    ) : (
                      <span className="text-sm font-medium text-brand-strong">Atur</span>
                    )}
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ))
      )}
    </AdminLayout>
  );
}
