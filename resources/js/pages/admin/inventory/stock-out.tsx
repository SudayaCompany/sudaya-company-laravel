import { Head, router } from "@inertiajs/react";
import { ArrowUpFromLineIcon, CameraIcon, Loader2Icon, XIcon } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { ItemQuantityFields, newKey, RecentMovements, type ItemOption, type Movement } from "@/components/inventory/stock-parts";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { shrinkPhoto } from "@/lib/attendance";
import { useInlineFlashError } from "@/lib/feedback";
import { cn } from "@/lib/utils";

type Props = {
  outlet: { id: string; name: string; timezone: string };
  items: ItemOption[];
  types: Record<string, string>;
  recent: Movement[];
  selected: string | null;
};

/** Alasan cepat waste (PRD §24); tetap bisa diketik bebas. */
const QUICK_REASONS = ["Tumpah", "Salah produksi", "Rusak", "Kedaluwarsa", "Dipakai untuk tes rasa"];

/** Stok keluar & waste: alasan wajib, foto bukti opsional (bucket privat). */
export default function StockOut({ outlet, items, types, recent, selected }: Props) {
  useInlineFlashError();
  const initial = items.find((i) => i.id === selected) ?? null;
  const [itemId, setItemId] = useState(initial?.id ?? "");
  const [type, setType] = useState("waste");
  const [quantity, setQuantity] = useState("");
  const [unit, setUnit] = useState(initial?.unit ?? "pcs");
  const [reason, setReason] = useState("");
  const [photo, setPhoto] = useState<{ file: File; url: string } | null>(null);
  const [key, setKey] = useState(newKey);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  useEffect(() => () => (photo ? URL.revokeObjectURL(photo.url) : undefined), [photo]);

  function chooseItem(id: string) {
    setItemId(id);
    setUnit(items.find((i) => i.id === id)?.unit ?? "pcs");
  }

  async function onPhoto(file: File | undefined) {
    if (!file) return;
    try {
      const blob = await shrinkPhoto(file, 1280);
      setPhoto({ file: new File([blob], "bukti.jpg", { type: "image/jpeg" }), url: URL.createObjectURL(blob) });
    } catch (e) {
      setError(e instanceof Error ? e.message : "Foto tidak bisa diproses.");
    }
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    router.post(
      "/admin/inventory/stock-out",
      { itemId, type, quantity, unit, reason, idempotencyKey: key, ...(photo ? { photo: photo.file } : {}) },
      {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => setPending(true),
        onFinish: () => setPending(false),
        onFlash: (flash) => {
          const f = flash as { success?: string; error?: string };
          if (f.error) setError(f.error);
          if (f.success) {
            setQuantity("");
            setReason("");
            setPhoto(null);
            if (fileInput.current) fileInput.current.value = "";
            setKey(newKey());
          }
        },
      },
    );
  }

  return (
    <AdminLayout breadcrumbs={[{ label: "Stok", href: `/admin/inventory?outlet=${outlet.id}` }, { label: "Stok keluar & waste" }]}>
      <Head title="Stok keluar & waste" />
      <PageHeader title="Stok keluar & waste" description={`${outlet.name} · bahan rusak, tumpah, kedaluwarsa, atau dipakai internal`} />
      <div className="grid gap-6 lg:grid-cols-[minmax(0,28rem)_1fr] lg:items-start">
        <Card>
          <CardContent>
            <form className="flex flex-col gap-4" onSubmit={submit}>
              <div className="flex flex-col gap-1.5">
                <Label>Jenis</Label>
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Jenis stok keluar">
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
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="stock-reason">Alasan</Label>
                <div className="flex flex-wrap gap-1.5">
                  {QUICK_REASONS.map((r) => (
                    <button
                      key={r}
                      type="button"
                      onClick={() => setReason(r)}
                      className="min-h-9 rounded-full border px-3 text-xs pointer-coarse:min-h-11 hover:border-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                      {r}
                    </button>
                  ))}
                </div>
                <Textarea id="stock-reason" value={reason} maxLength={300} rows={2} onChange={(e) => setReason(e.target.value)} placeholder="Wajib, minimal 3 karakter" />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Foto bukti (opsional)</Label>
                <input ref={fileInput} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => void onPhoto(e.target.files?.[0])} />
                {photo ? (
                  <div className="flex items-center gap-3">
                    <img src={photo.url} alt="Pratinjau foto bukti" className="size-20 rounded-lg object-cover ring-1 ring-foreground/10" />
                    <Button type="button" variant="ghost" onClick={() => setPhoto(null)}>
                      <XIcon aria-hidden /> Hapus foto
                    </Button>
                  </div>
                ) : (
                  <Button type="button" variant="outline" onClick={() => fileInput.current?.click()}>
                    <CameraIcon aria-hidden /> Ambil foto
                  </Button>
                )}
              </div>
              {error ? (
                <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
                  {error}
                </p>
              ) : null}
              <Button type="submit" size="xl" variant="destructive" disabled={pending || itemId === "" || quantity.trim() === "" || reason.trim().length < 3}>
                {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <ArrowUpFromLineIcon aria-hidden />}
                Catat stok keluar
              </Button>
            </form>
          </CardContent>
        </Card>
        <RecentMovements title="Stok keluar terakhir" rows={recent} timezone={outlet.timezone} />
      </div>
    </AdminLayout>
  );
}
