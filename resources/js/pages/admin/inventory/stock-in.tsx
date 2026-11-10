import { Head, router } from "@inertiajs/react";
import { ArrowDownToLineIcon, Loader2Icon } from "lucide-react";
import { useState } from "react";

import { ItemQuantityFields, newKey, RecentMovements, type ItemOption, type Movement } from "@/components/inventory/stock-parts";
import { MoneyInput } from "@/components/money-input";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { useInlineFlashError } from "@/lib/feedback";
import { cn } from "@/lib/utils";

type Props = {
  outlet: { id: string; name: string; timezone: string };
  items: ItemOption[];
  types: Record<string, string>;
  recent: Movement[];
  selected: string | null;
};

/** Stok masuk: pembelian, retur, penyesuaian (+). Harga beli terakhir jadi harga pokok. */
export default function StockIn({ outlet, items, types, recent, selected }: Props) {
  useInlineFlashError();
  const initial = items.find((i) => i.id === selected) ?? null;
  const [itemId, setItemId] = useState(initial?.id ?? "");
  const [type, setType] = useState("purchase");
  const [quantity, setQuantity] = useState("");
  const [unit, setUnit] = useState(initial?.unit ?? "pcs");
  const [unitCost, setUnitCost] = useState("");
  const [expiryDate, setExpiryDate] = useState("");
  const [documentRef, setDocumentRef] = useState("");
  const [notes, setNotes] = useState("");
  const [key, setKey] = useState(newKey);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function chooseItem(id: string) {
    setItemId(id);
    setUnit(items.find((i) => i.id === id)?.unit ?? "pcs");
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    router.post(
      "/admin/inventory/stock-in",
      { itemId, type, quantity, unit, unitCost, expiryDate, documentRef, notes, idempotencyKey: key },
      {
        preserveScroll: true,
        onStart: () => setPending(true),
        onFinish: () => setPending(false),
        onFlash: (flash) => {
          const f = flash as { success?: string; error?: string };
          if (f.error) setError(f.error);
          if (f.success) {
            setQuantity("");
            setUnitCost("");
            setExpiryDate("");
            setDocumentRef("");
            setNotes("");
            setKey(newKey());
          }
        },
      },
    );
  }

  return (
    <AdminLayout breadcrumbs={[{ label: "Stok", href: `/admin/inventory?outlet=${outlet.id}` }, { label: "Stok masuk" }]}>
      <Head title="Stok masuk" />
      <PageHeader title="Stok masuk" description={`${outlet.name} · pembelian, retur, atau penyesuaian tambah`} />
      <div className="grid gap-6 lg:grid-cols-[minmax(0,28rem)_1fr] lg:items-start">
        <Card>
          <CardContent>
            <form className="flex flex-col gap-4" onSubmit={submit}>
              <div className="flex flex-col gap-1.5">
                <Label>Jenis</Label>
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Jenis stok masuk">
                  {Object.entries(types).map(([value, label]) => (
                    <button
                      key={value}
                      type="button"
                      role="radio"
                      aria-checked={type === value}
                      onClick={() => setType(value)}
                      className={cn(
                        "flex min-h-10 items-center rounded-full border px-4 text-sm font-medium pointer-coarse:min-h-11 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                        type === value ? "border-espresso bg-espresso text-espresso-foreground" : "hover:border-foreground",
                      )}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>
              <ItemQuantityFields items={items} itemId={itemId} onItem={chooseItem} quantity={quantity} onQuantity={setQuantity} unit={unit} onUnit={setUnit} />
              {type === "purchase" ? (
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="stock-cost">Harga beli per {unit}</Label>
                  <MoneyInput id="stock-cost" value={unitCost} onValueChange={setUnitCost} placeholder="Opsional" />
                </div>
              ) : null}
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="stock-expiry">Kedaluwarsa</Label>
                  <Input id="stock-expiry" type="date" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="stock-doc">No. nota / dokumen</Label>
                  <Input id="stock-doc" value={documentRef} maxLength={60} onChange={(e) => setDocumentRef(e.target.value)} placeholder="Opsional" />
                </div>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="stock-notes">Catatan</Label>
                <Textarea id="stock-notes" value={notes} maxLength={300} rows={2} onChange={(e) => setNotes(e.target.value)} placeholder="Opsional, mis. nama supplier" />
              </div>
              {error ? (
                <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
                  {error}
                </p>
              ) : null}
              <Button type="submit" size="xl" disabled={pending || itemId === "" || quantity.trim() === ""}>
                {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <ArrowDownToLineIcon aria-hidden />}
                Catat stok masuk
              </Button>
            </form>
          </CardContent>
        </Card>
        <RecentMovements title="Stok masuk terakhir" rows={recent} timezone={outlet.timezone} />
      </div>
    </AdminLayout>
  );
}
