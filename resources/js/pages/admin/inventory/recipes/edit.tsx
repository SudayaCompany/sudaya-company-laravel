import { Head, router } from "@inertiajs/react";
import { Loader2Icon, PlusIcon, SaveIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { useInlineFlashError } from "@/lib/feedback";
import { formatMenuPrice, formatRupiah } from "@/lib/format";

type Item = { id: string; name: string; unit: string; kind: string; units: string[] };
type Props = {
  outlet: { id: string; name: string };
  product: { id: string; name: string; price: number };
  canEdit: boolean;
  lines: { itemId: string; name: string; unit: string; quantity: string; cost: number | null }[];
  items: Item[];
};
type Line = { key: number; itemId: string; quantity: string; unit: string };

let seq = 0;

export default function RecipeEdit({ outlet, product, canEdit, lines: initial, items }: Props) {
  useInlineFlashError();
  const [lines, setLines] = useState<Line[]>(initial.map((l) => ({ key: ++seq, itemId: l.itemId, quantity: l.quantity, unit: l.unit })));
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const byId = new Map(items.map((i) => [i.id, i]));
  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const cost = initial.reduce((sum, l) => sum + (l.cost ?? 0), 0);

  function save() {
    setError(null);
    router.post(
      `/admin/inventory/recipes/${product.id}`,
      { outletId: outlet.id, lines: lines.filter((l) => l.itemId !== "").map((l) => ({ itemId: l.itemId, quantity: l.quantity, unit: l.unit })) },
      {
        onStart: () => setPending(true),
        onFinish: () => setPending(false),
        onFlash: (flash) => {
          const f = flash as { error?: string };
          if (f.error) setError(f.error);
        },
      },
    );
  }

  return (
    <AdminLayout breadcrumbs={[{ label: "Resep (BOM)", href: `/admin/inventory/recipes?outlet=${outlet.id}` }, { label: product.name }]}>
      <Head title={`Resep ${product.name}`} />
      <PageHeader
        title={`Resep ${product.name}`}
        description={`${outlet.name} · bahan untuk 1 porsi. Harga jual ${formatMenuPrice(product.price)}${cost > 0 ? ` · perkiraan modal bahan ${formatRupiah(cost)} (dari harga beli terakhir)` : ""}`}
      />

      <div className="flex max-w-3xl flex-col gap-3">
        {lines.length === 0 ? <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">Belum ada bahan. Menu tanpa resep tidak memotong stok bahan.</p> : null}
        {lines.map((l, index) => {
          const item = byId.get(l.itemId);
          return (
            <div key={l.key} className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor={`rcp-item-${l.key}`}>Bahan {index + 1}</Label>
                <NativeSelect
                  id={`rcp-item-${l.key}`}
                  className="w-full"
                  value={l.itemId}
                  disabled={!canEdit}
                  onChange={(e) => update(l.key, { itemId: e.target.value, unit: byId.get(e.target.value)?.unit ?? "" })}
                >
                  <NativeSelectOption value="">Pilih item stok</NativeSelectOption>
                  {items.map((i) => (
                    <NativeSelectOption key={i.id} value={i.id} disabled={lines.some((o) => o.key !== l.key && o.itemId === i.id)}>
                      {i.name}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor={`rcp-qty-${l.key}`}>Per porsi</Label>
                <Input id={`rcp-qty-${l.key}`} inputMode="decimal" value={l.quantity} disabled={!canEdit} onChange={(e) => update(l.key, { quantity: e.target.value })} placeholder="mis. 18" />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor={`rcp-unit-${l.key}`}>Satuan</Label>
                <NativeSelect id={`rcp-unit-${l.key}`} className="w-full" value={l.unit} disabled={!canEdit || !item} onChange={(e) => update(l.key, { unit: e.target.value })}>
                  {(item?.units ?? []).map((u) => (
                    <NativeSelectOption key={u} value={u}>
                      {u}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </div>
              {canEdit ? (
                <Button type="button" variant="ghost" size="icon" aria-label={`Hapus bahan ${index + 1}`} onClick={() => setLines((ls) => ls.filter((o) => o.key !== l.key))}>
                  <Trash2Icon />
                </Button>
              ) : null}
            </div>
          );
        })}
        {error ? (
          <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
            {error}
          </p>
        ) : null}
        {canEdit ? (
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => setLines((ls) => [...ls, { key: ++seq, itemId: "", quantity: "", unit: "" }])} disabled={lines.length >= 20}>
              <PlusIcon /> Tambah bahan
            </Button>
            <Button type="button" size="lg" onClick={save} disabled={pending || lines.some((l) => l.itemId !== "" && l.quantity.trim() === "")}>
              {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SaveIcon aria-hidden />} Simpan resep
            </Button>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Hanya Manager/Admin yang bisa mengubah resep.</p>
        )}
      </div>
    </AdminLayout>
  );
}
