import { Head, Link, router } from "@inertiajs/react";
import { BanknoteIcon, CoffeeIcon, Loader2Icon, MinusIcon, PlusIcon, PrinterIcon, SearchIcon, ShoppingCartIcon, Trash2Icon } from "lucide-react";
import { useMemo, useRef, useState, useSyncExternalStore } from "react";
import { toast } from "sonner";

import { ItemNotesField, ModifierGroupField, QuantityStepper, useProductSelection } from "@/components/catalog/product-options";
import { ConfirmAction } from "@/components/confirm-action";
import type { StorefrontProduct } from "@/components/storefront/storefront";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";
import { PosLayout } from "@/layouts/pos-layout";
import { MAX_LINE_QUANTITY, priceSelection } from "@/lib/catalog/pricing";
import { formatPriceDelta, formatRupiah } from "@/lib/format";
import { ORDER_CONFLICT_MESSAGE, ORDER_TYPE_LABELS, postJson, type OrderType } from "@/lib/orders";
import { cn } from "@/lib/utils";

const DESKTOP_QUERY = "(min-width: 1024px)";
function subscribeDesktop(callback: () => void) {
  const media = window.matchMedia(DESKTOP_QUERY);
  media.addEventListener("change", callback);
  return () => media.removeEventListener("change", callback);
}
/** Keranjang dirender sekali saja: panel samping (desktop) atau sheet (HP/tablet). */
function useIsDesktop() {
  return useSyncExternalStore(subscribeDesktop, () => window.matchMedia(DESKTOP_QUERY).matches, () => true);
}

type PosLine = { key: string; product: StorefrontProduct; modifierIds: string[]; quantity: number; notes: string | null };
type Placed = { orderId: string; reference: string; total: number; replayed: boolean; orderNumber: string | null; change: number | null; warning: string | null };

type Props = {
  outlet: { id: string; name: string };
  outlets: { id: string; name: string }[];
  categories: { id: string; name: string }[];
  products: StorefrontProduct[];
  canConfirmCash: boolean;
  cashSessionOpen: boolean;
};

/** Terminal kasir. Harga di layar perkiraan; harga sah dihitung order engine di server. */
export default function PosPage({ outlet, outlets, categories, products, canConfirmCash, cashSessionOpen }: Props) {
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState<string | null>(null);
  const [picking, setPicking] = useState<StorefrontProduct | null>(null);
  const [lines, setLines] = useState<PosLine[]>([]);
  const [cartOpen, setCartOpen] = useState(false);
  const [result, setResult] = useState<Placed | null>(null);
  const desktop = useIsDesktop();

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return products.filter((p) => (!category || p.categoryId === category) && (!q || p.name.toLowerCase().includes(q)));
  }, [products, category, query]);
  const itemCount = lines.reduce((sum, l) => sum + l.quantity, 0);

  function addLine(line: Omit<PosLine, "key">) {
    setLines((current) => {
      const key = `${line.product.id}|${[...line.modifierIds].sort().join(",")}|${line.notes ?? ""}`;
      const existing = current.find((l) => l.key === key);
      if (existing) return current.map((l) => (l.key === key ? { ...l, quantity: Math.min(l.quantity + line.quantity, MAX_LINE_QUANTITY) } : l));
      return [...current, { ...line, key }];
    });
  }

  const cart = (
    <PosCart
      outletId={outlet.id}
      lines={lines}
      setLines={setLines}
      canConfirmCash={canConfirmCash}
      cashSessionOpen={cashSessionOpen}
      onPlaced={(placed) => {
        setCartOpen(false);
        setResult(placed);
      }}
    />
  );

  return (
    <PosLayout outletId={outlet.id}>
      <Head title="Kasir" />
      <div className="grid flex-1 lg:grid-cols-[1fr_26rem]">
        <section className="flex min-w-0 flex-col gap-3 p-4 pb-28 lg:pb-4">
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-48 flex-1">
              <SearchIcon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari produk" aria-label="Cari produk" className="h-11 bg-background pl-9 text-base" />
            </div>
            {outlets.length > 1 ? (
              <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/pos?outlet=${e.target.value}`)} className="h-11">
                {outlets.map((o) => (
                  <NativeSelectOption key={o.id} value={o.id}>
                    {o.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            ) : (
              <span className="text-sm text-muted-foreground">{outlet.name}</span>
            )}
          </div>

          <div className="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]">
            {[{ id: null, name: "Semua" }, ...categories].map((c) => (
              <button
                key={c.id ?? "all"}
                type="button"
                onClick={() => setCategory(c.id)}
                aria-pressed={category === c.id}
                className={cn(
                  "shrink-0 rounded-full border px-4 py-2 text-sm font-medium transition-colors",
                  category === c.id ? "border-neutral-950 bg-neutral-950 text-white" : "bg-background hover:border-foreground",
                )}
              >
                {c.name}
              </button>
            ))}
          </div>

          {visible.length === 0 ? (
            <p className="py-16 text-center text-sm text-muted-foreground">{products.length === 0 ? "Belum ada menu aktif di outlet ini." : "Produk tidak ditemukan."}</p>
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
              {visible.map((product) => (
                <button
                  key={product.id}
                  type="button"
                  disabled={!product.isAvailable}
                  onClick={() => setPicking(product)}
                  className="flex flex-col overflow-hidden rounded-xl border bg-background text-left transition-shadow hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <div className="relative aspect-[5/3] bg-muted">
                    {product.imageUrl ? (
                      <img src={product.imageUrl} alt="" loading="lazy" className="size-full object-cover" />
                    ) : (
                      <CoffeeIcon className="absolute inset-0 m-auto size-7 text-muted-foreground/40" aria-hidden />
                    )}
                    {!product.isAvailable ? (
                      <Badge variant="destructive" className="absolute top-2 left-2">
                        Sold out
                      </Badge>
                    ) : null}
                  </div>
                  <div className="flex flex-1 flex-col gap-0.5 p-3">
                    <span className="line-clamp-2 text-sm font-medium">{product.name}</span>
                    <span className="mt-auto text-sm font-semibold tabular-nums">{formatRupiah(product.price)}</span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </section>

        {desktop ? <aside className="flex flex-col border-l bg-background">{cart}</aside> : null}

        <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-background p-3 lg:hidden">
          <Button size="lg" className="h-12 w-full justify-between" onClick={() => setCartOpen(true)}>
            <span className="flex items-center gap-2">
              <ShoppingCartIcon /> {itemCount} item
            </span>
            <span className="tabular-nums">{formatRupiah(estimateTotal(lines))}</span>
          </Button>
        </div>
        <Sheet open={cartOpen && !desktop} onOpenChange={setCartOpen}>
          <SheetContent side="bottom" className="max-h-[92svh] gap-0 p-0">
            <SheetHeader className="sr-only">
              <SheetTitle>Keranjang</SheetTitle>
            </SheetHeader>
            {desktop ? null : cart}
          </SheetContent>
        </Sheet>
        <ResultDialog result={result} onClose={() => setResult(null)} />

        <Dialog open={picking !== null} onOpenChange={(open) => !open && setPicking(null)}>
          <DialogContent className="max-h-[90svh] gap-0 overflow-y-auto p-0 sm:max-w-lg">
            {picking ? (
              <ProductPicker
                key={picking.id}
                product={picking}
                onAdd={(line) => {
                  addLine(line);
                  setPicking(null);
                }}
              />
            ) : null}
          </DialogContent>
        </Dialog>
      </div>
    </PosLayout>
  );
}

function estimateTotal(lines: PosLine[]) {
  return lines.reduce((sum, l) => {
    const priced = priceSelection(l.product, l.modifierIds);
    return sum + (priced.ok ? priced.unitPrice * l.quantity : 0);
  }, 0);
}

function ProductPicker({ product, onAdd }: { product: StorefrontProduct; onAdd: (line: Omit<PosLine, "key">) => void }) {
  const selection = useProductSelection(product);
  const { priced } = selection;

  return (
    <>
      <DialogHeader className="p-4">
        <DialogTitle>{product.name}</DialogTitle>
        <DialogDescription>{formatRupiah(product.price)}</DialogDescription>
      </DialogHeader>
      {product.groups.map((group) => (
        <ModifierGroupField key={group.id} group={group} selected={selection.selected} onChange={(ids) => selection.setGroupSelection(group, ids)} />
      ))}
      <ItemNotesField value={selection.notes} onChange={selection.setNotes} />
      <DialogFooter className="m-0 flex-row items-center gap-3 border-t p-4">
        <QuantityStepper value={selection.quantity} onChange={selection.setQuantity} />
        <Button
          size="lg"
          className="h-11 flex-1"
          disabled={!priced.ok}
          onClick={() => onAdd({ product, modifierIds: selection.selected, quantity: selection.quantity, notes: selection.notes.trim() || null })}
        >
          {priced.ok ? `Tambah · ${formatRupiah(priced.unitPrice * selection.quantity)}` : priced.error}
        </Button>
      </DialogFooter>
    </>
  );
}

function PosCart({
  outletId,
  lines,
  setLines,
  canConfirmCash,
  cashSessionOpen,
  onPlaced,
}: {
  outletId: string;
  lines: PosLine[];
  setLines: React.Dispatch<React.SetStateAction<PosLine[]>>;
  canConfirmCash: boolean;
  cashSessionOpen: boolean;
  onPlaced: (result: Placed) => void;
}) {
  const [type, setType] = useState<OrderType>("takeaway");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [tableNumber, setTableNumber] = useState("");
  const [locationNote, setLocationNote] = useState("");
  const [deliveryAddress, setDeliveryAddress] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [tendered, setTendered] = useState("");
  // Satu key per isi keranjang: klik ganda atau retry tidak membuat order ganda.
  const idempotencyKey = useRef<string | null>(null);
  const total = estimateTotal(lines);
  const tenderedAmount = tendered.trim() === "" ? null : Number(tendered);
  const cashValid = tenderedAmount !== null && Number.isInteger(tenderedAmount) && tenderedAmount >= total;

  function changeLines(update: (current: PosLine[]) => PosLine[]) {
    idempotencyKey.current = null;
    setLines(update);
  }

  function reset() {
    idempotencyKey.current = null;
    setLines([]);
    setCustomerName("");
    setCustomerPhone("");
    setTableNumber("");
    setLocationNote("");
    setDeliveryAddress("");
    setNotes("");
    setError(null);
    setTendered("");
    setType("takeaway");
  }

  function submit(payNow: boolean) {
    idempotencyKey.current ??= crypto.randomUUID();
    const checkout = {
      idempotencyKey: idempotencyKey.current,
      type,
      customerName: customerName.trim() || undefined,
      customerPhone: type === "delivery" ? customerPhone.trim() || undefined : undefined,
      tableNumber: type === "dine_in" ? tableNumber.trim() || undefined : undefined,
      locationNote: type === "dine_in" ? locationNote.trim() || undefined : undefined,
      deliveryAddress: type === "delivery" ? deliveryAddress.trim() || undefined : undefined,
      notes: notes.trim() || undefined,
      paymentMethod: "cash",
      lines: lines.map((l) => ({ productId: l.product.id, modifierIds: l.modifierIds, quantity: l.quantity, notes: l.notes ?? undefined })),
    };
    setError(null);
    setSubmitting(true);
    // Validasi lengkap di server (CheckoutInput). Hasil dibaca dari respons JSON, bukan flash
    // sesi, agar reload tab lain di sesi yang sama tidak menghilangkan nomor antrean/kembalian.
    // Bila koneksi putus, idempotency key tetap: kirim ulang menghasilkan order yang sama.
    postJson<Placed>("/pos/orders", { outletId, checkout, tenderedAmount: payNow ? tenderedAmount : null })
      .then((result) => {
        if (!result.ok) {
          if (result.error === ORDER_CONFLICT_MESSAGE) idempotencyKey.current = null;
          setError(result.error);
          toast.error(result.error);
          return;
        }
        reset();
        if (result.warning) toast.warning(result.warning);
        onPlaced(result);
      })
      .finally(() => setSubmitting(false));
  }

  return (
    <div className="flex h-full max-h-[calc(100svh-3.5rem)] flex-col lg:sticky lg:top-14 [@media(max-height:560px)]:overflow-y-auto">
      {/* pr-12: ruang untuk tombol tutup sheet di bawah lg. */}
      <div className="flex shrink-0 items-center justify-between border-b py-3 pr-12 pl-4 lg:pr-4">
        <h2 className="font-semibold">Pesanan baru</h2>
        {lines.length ? (
          <ConfirmAction
            trigger={
              <Button size="sm" variant="ghost">
                Kosongkan
              </Button>
            }
            title="Kosongkan keranjang?"
            description="Semua item di keranjang akan dihapus."
            confirmLabel="Kosongkan"
            onConfirm={() => changeLines(() => [])}
          />
        ) : null}
      </div>

      {/* Item dan form satu area scroll; area simpan di bawah selalu terlihat. */}
      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain [@media(max-height:560px)]:flex-none [@media(max-height:560px)]:overflow-visible">
        <div className="grow px-4">
          {lines.length === 0 ? (
            <p className="py-10 text-center text-sm text-muted-foreground">Pilih produk untuk memulai.</p>
          ) : (
            <ul className="flex flex-col divide-y">
              {lines.map((line) => {
                const priced = priceSelection(line.product, line.modifierIds);
                return (
                  <li key={line.key} className="flex flex-col gap-1.5 py-3">
                    <div className="flex justify-between gap-2">
                      <span className="font-medium">{line.product.name}</span>
                      <span className="text-sm tabular-nums">{priced.ok ? formatRupiah(priced.unitPrice * line.quantity) : "—"}</span>
                    </div>
                    {priced.ok && priced.modifiers.length ? (
                      <p className="text-xs text-muted-foreground">
                        {priced.modifiers.map((m) => `${m.name}${m.priceDelta ? ` (${formatPriceDelta(m.priceDelta)})` : ""}`).join(", ")}
                      </p>
                    ) : null}
                    {line.notes ? <p className="text-xs text-muted-foreground italic">“{line.notes}”</p> : null}
                    <div className="flex items-center gap-2">
                      <Button
                        size="icon-sm"
                        variant="outline"
                        aria-label={`Kurangi ${line.product.name}`}
                        onClick={() => changeLines((c) => c.flatMap((l) => (l.key !== line.key ? [l] : l.quantity > 1 ? [{ ...l, quantity: l.quantity - 1 }] : [])))}
                      >
                        <MinusIcon />
                      </Button>
                      <span className="w-6 text-center text-sm tabular-nums">{line.quantity}</span>
                      <Button
                        size="icon-sm"
                        variant="outline"
                        aria-label={`Tambah ${line.product.name}`}
                        disabled={line.quantity >= MAX_LINE_QUANTITY}
                        onClick={() => changeLines((c) => c.map((l) => (l.key === line.key ? { ...l, quantity: l.quantity + 1 } : l)))}
                      >
                        <PlusIcon />
                      </Button>
                      {/* ml-auto: tombol hapus dijauhkan dari tombol jumlah agar tidak salah tekan. */}
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        className="ml-auto"
                        aria-label={`Hapus ${line.product.name}`}
                        onClick={() => changeLines((c) => c.filter((l) => l.key !== line.key))}
                      >
                        <Trash2Icon className="text-destructive" />
                      </Button>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        <div className="flex shrink-0 flex-col gap-3 border-t p-4">
          <div className="grid grid-cols-3 gap-1.5">
            {(["takeaway", "dine_in", "delivery"] as const).map((t) => (
              <button
                key={t}
                type="button"
                aria-pressed={type === t}
                onClick={() => {
                  setType(t);
                  setError(null);
                }}
                className={cn("rounded-lg border py-2 text-sm font-medium", type === t ? "border-neutral-950 bg-neutral-950 text-white" : "hover:border-foreground")}
              >
                {ORDER_TYPE_LABELS[t]}
              </button>
            ))}
          </div>
          {type === "delivery" ? (
            <div className="grid grid-cols-2 gap-2">
              <div className="flex flex-col gap-1">
                <Label htmlFor="pos-customer-name">Nama</Label>
                <Input id="pos-customer-name" value={customerName} onChange={(e) => setCustomerName(e.target.value)} maxLength={60} autoComplete="off" />
              </div>
              <div className="flex flex-col gap-1">
                <Label htmlFor="pos-customer-phone">Nomor telepon</Label>
                <Input
                  id="pos-customer-phone"
                  type="tel"
                  inputMode="tel"
                  value={customerPhone}
                  onChange={(e) => setCustomerPhone(e.target.value)}
                  maxLength={20}
                  autoComplete="off"
                  placeholder="0812xxxxxxx"
                />
              </div>
              <div className="col-span-2 flex flex-col gap-1">
                <Label htmlFor="pos-delivery-address">Alamat</Label>
                <Textarea
                  id="pos-delivery-address"
                  value={deliveryAddress}
                  onChange={(e) => setDeliveryAddress(e.target.value)}
                  maxLength={300}
                  rows={2}
                  autoComplete="off"
                  placeholder="Jalan, nomor, patokan"
                />
                <p className="text-xs text-muted-foreground">Ongkir & waktu antar dikonfirmasi langsung oleh outlet.</p>
              </div>
              <div className="col-span-2 flex flex-col gap-1">
                <Label htmlFor="pos-delivery-notes">Catatan (opsional)</Label>
                <Input id="pos-delivery-notes" value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={300} />
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-2">
              <Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Nama customer" aria-label="Nama customer" maxLength={60} />
              {type === "dine_in" ? (
                <>
                  <Input value={tableNumber} onChange={(e) => setTableNumber(e.target.value)} placeholder="No. meja" aria-label="Nomor meja" maxLength={10} />
                  <Input value={locationNote} onChange={(e) => setLocationNote(e.target.value)} placeholder="Lokasi/keterangan" aria-label="Lokasi atau keterangan" maxLength={100} />
                </>
              ) : null}
              <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Catatan order" aria-label="Catatan order" maxLength={300} />
            </div>
          )}
        </div>
      </div>

      <div className="flex shrink-0 flex-col gap-2 border-t bg-background p-4">
        <div className="flex items-center justify-between text-lg font-semibold">
          <span>Total</span>
          <span className="tabular-nums">{formatRupiah(total)}</span>
        </div>
        {error ? (
          <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {error}
          </p>
        ) : null}
        {canConfirmCash && !cashSessionOpen ? (
          <>
            <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
              Sesi kas belum dibuka.{" "}
              <Link href={`/pos/cash?outlet=${outletId}`} className="font-medium underline">
                Buka sesi kas
              </Link>{" "}
              untuk menerima uang tunai.
            </p>
            <Button variant="outline" disabled={lines.length === 0 || submitting} onClick={() => submit(false)}>
              Simpan, bayar nanti
            </Button>
          </>
        ) : canConfirmCash ? (
          <>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="pos-tendered">Uang tunai diterima</Label>
              <div className="flex gap-1.5">
                <Input
                  id="pos-tendered"
                  type="number"
                  inputMode="numeric"
                  min={0}
                  value={tendered}
                  onChange={(e) => setTendered(e.target.value)}
                  className="h-10 min-w-0 tabular-nums"
                />
                <Button type="button" variant="outline" className="h-10" disabled={total === 0} onClick={() => setTendered(String(total))}>
                  Pas
                </Button>
                {[50_000, 100_000].map((v) => (
                  <Button key={v} type="button" variant="outline" className="h-10" disabled={v < total} onClick={() => setTendered(String(v))}>
                    {v / 1000}rb
                  </Button>
                ))}
              </div>
              <p className="text-sm text-muted-foreground">
                Kembalian: <span className="font-medium text-foreground tabular-nums">{cashValid ? formatRupiah(tenderedAmount! - total) : "—"}</span>
              </p>
            </div>
            <Button
              size="lg"
              className="h-12 bg-success text-base text-success-foreground hover:bg-success/90"
              disabled={lines.length === 0 || !cashValid || submitting}
              onClick={() => submit(true)}
            >
              {submitting ? <Loader2Icon className="animate-spin" /> : <BanknoteIcon />}
              Bayar tunai & proses
            </Button>
            <Button variant="outline" disabled={lines.length === 0 || submitting} onClick={() => submit(false)}>
              Simpan, bayar nanti
            </Button>
          </>
        ) : (
          <Button size="lg" className="h-12" disabled={lines.length === 0 || submitting} onClick={() => submit(false)}>
            {submitting ? <Loader2Icon className="animate-spin" /> : null}
            Simpan pesanan (bayar di kasir)
          </Button>
        )}
      </div>
    </div>
  );
}

function ResultDialog({ result, onClose }: { result: Placed | null; onClose: () => void }) {
  return (
    <Dialog open={result !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-sm">
        {result ? (
          <>
            <DialogHeader>
              <DialogTitle>{result.orderNumber ? "Pembayaran berhasil" : result.replayed ? "Pesanan sudah tersimpan" : "Pesanan disimpan"}</DialogTitle>
              <DialogDescription>
                {result.orderNumber ? "Pesanan masuk antrean barista." : "Menunggu pembayaran di kasir. Nomor antrean diberikan setelah pembayaran dikonfirmasi."}
              </DialogDescription>
            </DialogHeader>
            <div className="flex flex-col items-center gap-1 py-2 text-center">
              <span className="text-sm text-muted-foreground">{result.orderNumber ? "Nomor antrean" : "Kode pesanan"}</span>
              <span className="font-mono text-4xl font-bold" data-testid="result-number">
                {result.orderNumber ?? result.reference}
              </span>
              <span className="tabular-nums">Total {formatRupiah(result.total)}</span>
              {result.change !== null ? <span className="text-lg font-semibold tabular-nums">Kembalian {formatRupiah(result.change)}</span> : null}
              {result.warning ? <span className="text-sm text-destructive">{result.warning}</span> : null}
            </div>
            <DialogFooter className="flex-col gap-2 sm:flex-col">
              <a href={`/pos/orders/${result.orderId}/receipt`} target="_blank" rel="noopener" className={buttonVariants({ variant: "outline", className: "w-full" })}>
                <PrinterIcon /> Cetak struk
              </a>
              <Link href={`/pos/orders/${result.orderId}`} className={buttonVariants({ variant: "outline", className: "w-full" })}>
                Lihat detail
              </Link>
              <Button className="w-full" onClick={onClose}>
                Pesanan baru
              </Button>
            </DialogFooter>
          </>
        ) : null}
      </DialogContent>
    </Dialog>
  );
}
