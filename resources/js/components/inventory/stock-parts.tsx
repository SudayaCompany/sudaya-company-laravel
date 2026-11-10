import { ArrowDownLeftIcon, ArrowUpRightIcon, ImageIcon } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { cn } from "@/lib/utils";

export type ItemOption = { id: string; label: string; unit: string; units: string[]; stock: string };
export type Movement = {
  id: string;
  type: string;
  typeLabel: string;
  itemName: string;
  quantity: string;
  incoming: boolean;
  balance: string;
  reason: string | null;
  documentRef: string | null;
  hasPhoto: boolean;
  actorName: string;
  createdAt: string;
};

/** UUID per pengisian form: kirim ganda (double click, retry) tidak tercatat dua kali. */
export function newKey() {
  return crypto.randomUUID();
}

/** Pilih item + jumlah + satuan (hanya satuan yang setara dengan satuan item). */
export function ItemQuantityFields({
  items,
  itemId,
  onItem,
  quantity,
  onQuantity,
  unit,
  onUnit,
}: {
  items: ItemOption[];
  itemId: string;
  onItem: (id: string) => void;
  quantity: string;
  onQuantity: (value: string) => void;
  unit: string;
  onUnit: (value: string) => void;
}) {
  const item = items.find((i) => i.id === itemId);
  return (
    <>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="stock-item">Item</Label>
        <NativeSelect id="stock-item" className="w-full" value={itemId} onChange={(e) => onItem(e.target.value)} required>
          <NativeSelectOption value="">Pilih item…</NativeSelectOption>
          {items.map((i) => (
            <NativeSelectOption key={i.id} value={i.id}>
              {i.label}
            </NativeSelectOption>
          ))}
        </NativeSelect>
        {item ? <p className="text-xs text-muted-foreground">Stok sekarang: {item.stock}</p> : null}
      </div>
      <div className="grid grid-cols-[1fr_8rem] gap-2">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="stock-quantity">Jumlah</Label>
          <Input id="stock-quantity" inputMode="decimal" value={quantity} onChange={(e) => onQuantity(e.target.value)} placeholder="0" required className="text-lg font-semibold" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="stock-unit">Satuan</Label>
          <NativeSelect id="stock-unit" className="w-full" value={unit} onChange={(e) => onUnit(e.target.value)} disabled={!item}>
            {(item?.units ?? [unit]).map((u) => (
              <NativeSelectOption key={u} value={u}>
                {u}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        </div>
      </div>
    </>
  );
}

export function RecentMovements({ title, rows, timezone, limit = 8 }: { title: string; rows: Movement[]; timezone: string; limit?: number }) {
  const stamp = new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: timezone });
  return (
    <section className="flex flex-col gap-2" aria-labelledby="recent-title">
      <h2 id="recent-title" className="text-base font-semibold">
        {title}
      </h2>
      {rows.length === 0 ? (
        <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">Belum ada catatan.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {rows.slice(0, limit).map((m) => (
            <li key={m.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border bg-card p-3 text-sm">
              <span className={cn("flex size-8 shrink-0 items-center justify-center rounded-full", m.incoming ? "bg-success/15 text-success-strong" : "bg-destructive/10 text-destructive-strong")} aria-hidden>
                {m.incoming ? <ArrowDownLeftIcon className="size-4" /> : <ArrowUpRightIcon className="size-4" />}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block font-medium">
                  {m.itemName} <span className="font-normal text-muted-foreground">· {m.typeLabel}</span>
                </span>
                <span className="block text-xs text-muted-foreground">
                  {stamp.format(new Date(m.createdAt))} · {m.actorName}
                  {m.reason ? ` · “${m.reason}”` : ""}
                  {m.documentRef ? ` · ${m.documentRef}` : ""}
                </span>
              </span>
              {m.hasPhoto ? (
                <a href={`/admin/inventory/movements/${m.id}/photo`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-xs font-medium text-brand-strong underline-offset-4 hover:underline">
                  <ImageIcon className="size-3.5" aria-hidden /> Foto
                </a>
              ) : null}
              <span className="text-right tabular-nums">
                <span className={cn("block font-semibold", m.incoming ? "text-success-strong" : "text-destructive-strong")}>
                  {m.incoming ? "+" : ""}
                  {m.quantity}
                </span>
                <span className="block text-xs text-muted-foreground">saldo {m.balance}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
