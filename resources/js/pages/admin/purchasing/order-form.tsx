import { Head, Link, router } from "@inertiajs/react";
import { Loader2Icon, PlusIcon, SendIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { MoneyInput } from "@/components/money-input";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { useInlineFlashError } from "@/lib/feedback";
import { formatRupiah } from "@/lib/format";

type Item = { id: string; name: string; sku: string; unit: string; units: string[]; lastCost: number | null };
type Props = {
  outlet: { id: string; name: string };
  suppliers: { id: string; name: string; paymentTerm: string | null }[];
  items: Item[];
};
type Line = { key: number; itemId: string; quantity: string; unit: string; unitCost: string };

let seq = 0;
const blank = (): Line => ({ key: ++seq, itemId: "", quantity: "", unit: "", unitCost: "" });
const num = (s: string) => Number(s.replace(",", ".")) || 0;
/** Faktor kira-kira untuk pratinjau total (server menghitung ulang dengan tepat). */
const FACTOR: Record<string, number> = { g: 1, kg: 1000, ml: 1, l: 1000 };

export default function PurchaseOrderForm({ outlet, suppliers, items }: Props) {
  useInlineFlashError();
  const [supplierId, setSupplierId] = useState("");
  const [expectedDate, setExpectedDate] = useState("");
  const [notes, setNotes] = useState("");
  const [lines, setLines] = useState<Line[]>([blank()]);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const byId = new Map(items.map((i) => [i.id, i]));
  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const filled = lines.filter((l) => l.itemId !== "" && num(l.quantity) > 0);
  // Harga diisi per satuan yang dipilih, jadi total = jumlah × harga.
  const total = filled.reduce((sum, l) => sum + num(l.quantity) * (Number(l.unitCost) || 0), 0);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    router.post(
      "/admin/purchase-orders",
      { outletId: outlet.id, supplierId, expectedDate, notes, lines: filled.map((l) => ({ itemId: l.itemId, quantity: l.quantity, unit: l.unit || byId.get(l.itemId)?.unit, unitCost: l.unitCost })) },
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
    <AdminLayout breadcrumbs={[{ label: "Purchase order", href: `/admin/purchase-orders?outlet=${outlet.id}` }, { label: "Buat PO" }]}>
      <Head title="Buat purchase order" />
      <PageHeader title="Buat purchase order" description={`${outlet.name} · diajukan ke Owner/Manager untuk disetujui`} />
      <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6">
        <Card>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5 sm:col-span-2">
              <Label htmlFor="po-supplier">Supplier</Label>
              <NativeSelect id="po-supplier" className="w-full" value={supplierId} onChange={(e) => setSupplierId(e.target.value)} required>
                <NativeSelectOption value="">Pilih supplier</NativeSelectOption>
                {suppliers.map((s) => (
                  <NativeSelectOption key={s.id} value={s.id}>
                    {s.name}
                    {s.paymentTerm ? ` (${s.paymentTerm})` : ""}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
              {suppliers.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  Belum ada supplier aktif.{" "}
                  <Link href="/admin/suppliers" className="font-medium text-brand-strong underline">
                    Tambah supplier
                  </Link>
                </p>
              ) : null}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="po-expected">Perkiraan barang datang (opsional)</Label>
              <Input id="po-expected" type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="po-notes">Catatan (opsional)</Label>
              <Textarea id="po-notes" rows={1} maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
          </CardContent>
        </Card>

        <section className="flex flex-col gap-3" aria-labelledby="po-lines">
          <h2 id="po-lines" className="text-base font-semibold">
            Barang
          </h2>
          {lines.map((l, index) => {
            const item = byId.get(l.itemId);
            return (
              <div key={l.key} className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[2fr_1fr_1fr_1.3fr_auto] sm:items-end">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`po-item-${l.key}`}>Item {index + 1}</Label>
                  <NativeSelect
                    id={`po-item-${l.key}`}
                    className="w-full"
                    value={l.itemId}
                    onChange={(e) => {
                      const next = byId.get(e.target.value);
                      update(l.key, { itemId: e.target.value, unit: next?.unit ?? "", unitCost: next?.lastCost ? String(Math.round(next.lastCost)) : "" });
                    }}
                  >
                    <NativeSelectOption value="">Pilih item stok</NativeSelectOption>
                    {items.map((i) => (
                      <NativeSelectOption key={i.id} value={i.id} disabled={lines.some((o) => o.key !== l.key && o.itemId === i.id)}>
                        {i.name} ({i.sku})
                      </NativeSelectOption>
                    ))}
                  </NativeSelect>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`po-qty-${l.key}`}>Jumlah</Label>
                  <Input id={`po-qty-${l.key}`} inputMode="decimal" value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} placeholder="0" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`po-unit-${l.key}`}>Satuan</Label>
                  <NativeSelect id={`po-unit-${l.key}`} className="w-full" value={l.unit} disabled={!item} onChange={(e) => update(l.key, { unit: e.target.value })}>
                    {(item?.units ?? []).map((u) => (
                      <NativeSelectOption key={u} value={u}>
                        {u}
                      </NativeSelectOption>
                    ))}
                  </NativeSelect>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`po-cost-${l.key}`}>Harga per {l.unit || "satuan"}</Label>
                  <MoneyInput id={`po-cost-${l.key}`} value={l.unitCost} onValueChange={(unitCost) => update(l.key, { unitCost })} placeholder="0" />
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={`Hapus baris ${index + 1}`}
                  disabled={lines.length === 1}
                  onClick={() => setLines((ls) => ls.filter((o) => o.key !== l.key))}
                >
                  <Trash2Icon />
                </Button>
                {item && FACTOR[l.unit] && FACTOR[item.unit] && l.unit !== item.unit ? (
                  <p className="text-xs text-muted-foreground sm:col-span-5">
                    Dicatat sebagai {(Math.round(num(l.quantity) * (FACTOR[l.unit] / FACTOR[item.unit]) * 1000) / 1000).toLocaleString("id-ID")} {item.unit} di stok.
                  </p>
                ) : null}
              </div>
            );
          })}
          <Button type="button" variant="outline" className="w-fit" onClick={() => setLines((ls) => [...ls, blank()])} disabled={lines.length >= 50}>
            <PlusIcon /> Tambah baris
          </Button>
        </section>

        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted/60 p-4">
          <p className="text-sm">
            Perkiraan total <span className="text-lg font-semibold tabular-nums">{formatRupiah(Math.round(total))}</span>
          </p>
          <Button type="submit" size="lg" disabled={pending || supplierId === "" || filled.length === 0}>
            {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SendIcon aria-hidden />} Ajukan PO
          </Button>
        </div>
        {error ? (
          <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
            {error}
          </p>
        ) : null}
      </form>
    </AdminLayout>
  );
}
