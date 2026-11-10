import { Head, router } from "@inertiajs/react";
import { Loader2Icon, SaveIcon } from "lucide-react";
import { useState } from "react";

import { MoneyInput } from "@/components/money-input";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { AdminLayout } from "@/layouts/admin-layout";
import { useInlineFlashError } from "@/lib/feedback";
import { cn } from "@/lib/utils";

type Item = {
  id: string;
  sku: string;
  name: string;
  kind: string;
  category: string | null;
  unit: string;
  minimumInput: string;
  purchasePrice: number | null;
  expiryDate: string | null;
  isActive: boolean;
  linkedProductIds: string[];
  hasMovements: boolean;
  stock: string;
};
type Props = {
  outlet: { id: string; name: string };
  units: { code: string; label: string }[];
  kinds: Record<string, string>;
  item: Item | null;
  products: { id: string; name: string; linkedItemId: string | null; linkedItemName: string | null }[];
};

const KIND_HINT: Record<string, string> = {
  raw_material: "Kopi, susu, gula, sirup, matcha. Dikurangi lewat stok keluar atau opname.",
  finished_good: "Kopi botol, snack, merchandise. Bisa ditautkan ke menu: stok terpotong otomatis saat terjual.",
  packaging: "Cup, sedotan, tisu, kantong.",
};

export default function InventoryItemForm({ outlet, units, kinds, item, products }: Props) {
  useInlineFlashError();
  const editing = item !== null;
  const [v, setV] = useState({
    sku: item?.sku ?? "",
    name: item?.name ?? "",
    kind: item?.kind ?? "raw_material",
    category: item?.category ?? "",
    unit: item?.unit ?? "g",
    minimum: item?.minimumInput ?? "",
    purchasePrice: item?.purchasePrice === null || item?.purchasePrice === undefined ? "" : String(item.purchasePrice),
    expiryDate: item?.expiryDate ?? "",
    openingStock: "",
    isActive: item?.isActive ?? true,
    productIds: item?.linkedProductIds ?? [],
  });
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (key: keyof typeof v) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setV((s) => ({ ...s, [key]: e.target.value }));
  const toggleProduct = (id: string, checked: boolean) =>
    setV((s) => ({ ...s, productIds: checked ? [...s.productIds, id] : s.productIds.filter((p) => p !== id) }));

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const payload = { ...v, outletId: outlet.id, productIds: v.kind === "finished_good" ? v.productIds : [] };
    const options = {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onFlash: (flash: Record<string, unknown>) => {
        if (typeof flash.error === "string") setError(flash.error);
      },
    };
    if (editing) router.put(`/admin/inventory/items/${item.id}`, payload, options);
    else router.post("/admin/inventory/items", payload, options);
  }

  return (
    <AdminLayout breadcrumbs={[{ label: "Stok", href: `/admin/inventory?outlet=${outlet.id}` }, { label: editing ? item.name : "Item baru" }]}>
      <Head title={editing ? `Ubah ${item.name}` : "Item stok baru"} />
      <PageHeader title={editing ? "Ubah item stok" : "Item stok baru"} description={editing ? `${outlet.name} · stok sekarang ${item.stock}` : outlet.name} />
      <form className="flex max-w-3xl flex-col gap-4" onSubmit={submit}>
        <Card>
          <CardContent className="flex flex-col gap-5">
            <div className="flex flex-col gap-1.5">
              <Label>Jenis item</Label>
              <div className="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Jenis item">
                {Object.entries(kinds).map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={v.kind === value}
                    onClick={() => setV((s) => ({ ...s, kind: value }))}
                    className={cn(
                      "flex min-h-11 flex-col items-start gap-0.5 rounded-lg border p-3 text-left text-sm transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                      v.kind === value ? "border-espresso bg-espresso text-espresso-foreground" : "hover:border-foreground",
                    )}
                  >
                    <span className="font-medium">{label}</span>
                    <span className={cn("text-xs", v.kind === value ? "text-espresso-foreground/75" : "text-muted-foreground")}>{KIND_HINT[value]}</span>
                  </button>
                ))}
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Nama item" htmlFor="item-name">
                <Input id="item-name" value={v.name} onChange={set("name")} required maxLength={80} placeholder="mis. Biji kopi arabika" />
              </Field>
              <Field label="SKU / kode" htmlFor="item-sku" hint="Unik per outlet. Huruf, angka, titik, minus.">
                <Input id="item-sku" value={v.sku} onChange={set("sku")} required maxLength={32} autoCapitalize="characters" placeholder="mis. BK-ARABIKA" />
              </Field>
              <Field label="Kategori" htmlFor="item-category" hint="Opsional, mis. Kopi, Susu, Kemasan.">
                <Input id="item-category" value={v.category} onChange={set("category")} maxLength={40} />
              </Field>
              <Field label="Satuan stok" htmlFor="item-unit" hint={editing && item.hasMovements ? "Tidak bisa diubah setelah ada riwayat stok." : "Satuan terkecil yang dicatat, mis. g untuk kopi, pcs untuk cup."}>
                <NativeSelect id="item-unit" className="w-full" value={v.unit} onChange={set("unit")} disabled={editing && item.hasMovements}>
                  {units.map((u) => (
                    <NativeSelectOption key={u.code} value={u.code}>
                      {u.label}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </Field>
              <Field label={`Stok minimum (${v.unit})`} htmlFor="item-minimum" hint="Di bawah ini status jadi Menipis.">
                <Input id="item-minimum" inputMode="decimal" value={v.minimum} onChange={set("minimum")} placeholder="0" />
              </Field>
              <Field label={`Harga beli per ${v.unit}`} htmlFor="item-price" hint="Opsional. Diperbarui otomatis dari stok masuk pembelian.">
                <MoneyInput id="item-price" value={v.purchasePrice} onValueChange={(value) => setV((s) => ({ ...s, purchasePrice: value }))} placeholder="0" />
              </Field>
              <Field label="Kedaluwarsa terdekat" htmlFor="item-expiry" hint="Opsional, untuk susu, sirup, dll.">
                <Input id="item-expiry" type="date" value={v.expiryDate} onChange={set("expiryDate")} />
              </Field>
              {editing ? null : (
                <Field label={`Saldo awal (${v.unit})`} htmlFor="item-opening" hint="Stok yang ada sekarang. Bisa diisi nanti lewat stok masuk.">
                  <Input id="item-opening" inputMode="decimal" value={v.openingStock} onChange={set("openingStock")} placeholder="0" />
                </Field>
              )}
            </div>
            {editing ? (
              <label className="flex min-h-11 items-center justify-between gap-3 rounded-lg border p-3">
                <span className="flex flex-col">
                  <span className="text-sm font-medium">Item aktif</span>
                  <span className="text-xs text-muted-foreground">Item nonaktif tidak bisa dipakai untuk stok masuk/keluar dan menu tertautnya ditandai habis.</span>
                </span>
                <Switch checked={v.isActive} onCheckedChange={(checked) => setV((s) => ({ ...s, isActive: checked }))} aria-label="Item aktif" />
              </label>
            ) : null}
          </CardContent>
        </Card>

        {v.kind === "finished_good" ? (
          <Card>
            <CardHeader>
              <CardTitle>Menu yang memakai stok ini</CardTitle>
              <CardDescription>Saat menu terpilih dibayar, stok item ini berkurang otomatis dan menu tampil Habis bila stok 0.</CardDescription>
            </CardHeader>
            <CardContent>
              {products.length === 0 ? (
                <p className="text-sm text-muted-foreground">Belum ada menu di brand outlet ini.</p>
              ) : (
                <ul className="grid gap-2 sm:grid-cols-2">
                  {products.map((p) => {
                    const takenElsewhere = p.linkedItemId !== null && p.linkedItemId !== item?.id;
                    return (
                      <li key={p.id}>
                        <label className={cn("flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm", takenElsewhere && "opacity-60")}>
                          <Checkbox
                            checked={v.productIds.includes(p.id)}
                            disabled={takenElsewhere}
                            onCheckedChange={(checked) => toggleProduct(p.id, checked === true)}
                            aria-label={`Tautkan ${p.name}`}
                          />
                          <span className="flex flex-col">
                            <span className="font-medium">{p.name}</span>
                            {takenElsewhere ? <span className="text-xs text-muted-foreground">Sudah memakai stok {p.linkedItemName}</span> : null}
                          </span>
                        </label>
                      </li>
                    );
                  })}
                </ul>
              )}
            </CardContent>
          </Card>
        ) : null}

        {error ? (
          <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
            {error}
          </p>
        ) : null}
        <div>
          <Button type="submit" size="lg" disabled={pending}>
            {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SaveIcon aria-hidden />}
            {editing ? "Simpan perubahan" : "Tambah item"}
          </Button>
        </div>
      </form>
    </AdminLayout>
  );
}

function Field({ label, htmlFor, hint, children }: { label: string; htmlFor: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}
