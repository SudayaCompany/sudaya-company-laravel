import { Head } from "@inertiajs/react";
import {
  CoffeeIcon,
  Loader2Icon,
  MapPinIcon,
  MinusIcon,
  PlusIcon,
  QrCodeIcon,
  ShoppingBagIcon,
  StoreIcon,
  Trash2Icon,
  UtensilsIcon,
} from "lucide-react";
import { createContext, use, useEffect, useMemo, useState, useTransition } from "react";
import { toast } from "sonner";

import { Brand } from "@/components/brand";
import { ItemNotesField, ModifierGroupField, QuantityStepper, useProductSelection } from "@/components/catalog/product-options";
import { EmptyState } from "@/components/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { type CartLine, type CartQuote, requestQuote, useCartLines } from "@/lib/catalog/cart";
import { MAX_LINE_QUANTITY, type PricingProduct } from "@/lib/catalog/pricing";
import { formatPriceDelta, formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

type StorefrontProduct = PricingProduct & {
  slug: string;
  description: string | null;
  imageUrl: string | null;
  categoryId: string;
  defaultModifierIds: string[];
};

type Storefront = {
  outlet: { id: string; code: string; name: string };
  brand: { id: string; name: string };
  categories: { id: string; name: string; slug: string }[];
  products: StorefrontProduct[];
};

type Props = { storefront?: Storefront; table?: string | null; notice?: "scan" | "not_found" };

// Publik: guest boleh melihat menu tanpa login (PRD §32).
export default function OrderPage({ storefront, table = null, notice }: Props) {
  if (!storefront) {
    return (
      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 px-4 py-16">
        <Head title="Menu" />
        <Brand className="text-xl" />
        {notice === "scan" ? (
          <EmptyState icon={QrCodeIcon} title="Scan QR di outlet" description="Pindai kode QR di meja atau kasir Sudaya Coffee untuk melihat menu." />
        ) : (
          <EmptyState icon={StoreIcon} title="Outlet tidak ditemukan" description="Kode QR tidak dikenali atau outlet sedang tutup. Silakan tanya kasir." />
        )}
      </main>
    );
  }
  return <StorefrontView storefront={storefront} table={table} />;
}

type CartContextValue = {
  outlet: string;
  lines: CartLine[];
  quote: CartQuote | null;
  add: (line: CartLine) => void;
  setQuantity: (index: number, quantity: number) => void;
  remove: (index: number) => void;
};

const CartContext = createContext<CartContextValue | null>(null);
const useCart = () => use(CartContext)!;

function CartProvider({ outlet, children }: { outlet: string; children: React.ReactNode }) {
  const { lines, save } = useCartLines(outlet);
  const linesKey = JSON.stringify(lines);
  const [quote, setQuote] = useState<{ for: string; value: CartQuote } | null>(null);

  // Hitung ulang di server setiap isi keranjang berubah.
  useEffect(() => {
    if (lines.length === 0) return;
    let active = true;
    requestQuote(outlet, lines).then((value) => active && setQuote({ for: linesKey, value }));
    return () => {
      active = false;
    };
  }, [outlet, lines, linesKey]);

  const value = useMemo<CartContextValue>(
    () => ({
      outlet,
      lines,
      quote: lines.length === 0 ? null : quote?.for === linesKey ? quote.value : null,
      add: (line) => {
        const same = lines.findIndex(
          (l) => l.productId === line.productId && (l.notes ?? null) === (line.notes ?? null) && [...l.modifierIds].sort().join() === [...line.modifierIds].sort().join(),
        );
        if (same >= 0) {
          save(lines.map((l, i) => (i === same ? { ...l, quantity: Math.min(l.quantity + line.quantity, MAX_LINE_QUANTITY) } : l)));
        } else {
          save([...lines, line]);
        }
      },
      setQuantity: (index, quantity) => save(lines.map((l, i) => (i === index ? { ...l, quantity } : l))),
      remove: (index) => save(lines.filter((_, i) => i !== index)),
    }),
    [outlet, lines, linesKey, quote, save],
  );

  return <CartContext value={value}>{children}</CartContext>;
}

function StorefrontView({ storefront, table }: { storefront: Storefront; table: string | null }) {
  const [openProduct, setOpenProduct] = useState<StorefrontProduct | null>(null);
  const sections = storefront.categories
    .map((category) => ({ category, products: storefront.products.filter((p) => p.categoryId === category.id) }))
    .filter((s) => s.products.length > 0);

  return (
    <CartProvider outlet={storefront.outlet.code}>
      <Head title="Menu" />
      <div className="flex min-h-svh flex-col bg-background">
        <header className="relative overflow-hidden bg-neutral-950 text-white">
          <div className="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-brand/20 blur-3xl" aria-hidden />
          <div className="relative mx-auto w-full max-w-5xl px-4 pt-6 pb-8">
            <Brand tone="light" className="text-lg" />
            <h1 className="mt-6 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">Mau ngopi apa hari ini?</h1>
            <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-400">
              <span className="inline-flex items-center gap-1">
                <MapPinIcon className="size-3.5" aria-hidden /> {storefront.outlet.name}
              </span>
              {table ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2 py-0.5 text-white">
                  <UtensilsIcon className="size-3.5" aria-hidden /> Meja {table}
                </span>
              ) : null}
            </p>
          </div>
        </header>

        {sections.length === 0 ? (
          <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-10">
            <EmptyState icon={CoffeeIcon} title="Menu belum tersedia" description="Menu outlet ini sedang disiapkan. Silakan pesan langsung ke kasir." />
          </main>
        ) : (
          <>
            <nav aria-label="Kategori menu" className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
              <div className="mx-auto flex w-full max-w-5xl gap-2 overflow-x-auto px-4 py-3 [scrollbar-width:none]">
                {sections.map(({ category }) => (
                  <a key={category.id} href={`#kategori-${category.slug}`} className="shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition-colors hover:border-foreground">
                    {category.name}
                  </a>
                ))}
              </div>
            </nav>

            <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 px-4 pt-6 pb-32">
              {sections.map(({ category, products }) => (
                <section key={category.id} id={`kategori-${category.slug}`} className="scroll-mt-20">
                  <h2 className="mb-3 text-lg font-semibold tracking-tight">{category.name}</h2>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {products.map((product) => (
                      <ProductCard key={product.id} product={product} onOpen={() => setOpenProduct(product)} />
                    ))}
                  </div>
                </section>
              ))}
            </main>
          </>
        )}

        <ProductSheet product={openProduct} onOpenChange={(open) => !open && setOpenProduct(null)} />
        <CartBar />
      </div>
    </CartProvider>
  );
}

function ProductCard({ product, onOpen }: { product: StorefrontProduct; onOpen: () => void }) {
  const soldOut = !product.isAvailable;
  return (
    <button
      type="button"
      onClick={onOpen}
      className="group flex flex-col overflow-hidden rounded-2xl border bg-card text-left transition-shadow hover:shadow-md focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      aria-label={`${product.name}, ${formatRupiah(product.price)}${soldOut ? ", sold out" : ""}`}
    >
      <div className="relative aspect-square bg-muted">
        {product.imageUrl ? (
          <img
            src={product.imageUrl}
            alt=""
            loading="lazy"
            className={cn("size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]", soldOut && "grayscale")}
          />
        ) : (
          <CoffeeIcon className="absolute inset-0 m-auto size-10 text-muted-foreground/40" aria-hidden />
        )}
        {soldOut ? (
          <span className="absolute inset-0 flex items-center justify-center bg-black/45">
            <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-neutral-950">Sold out</span>
          </span>
        ) : null}
      </div>
      <div className="flex flex-1 flex-col gap-1 p-3">
        <p className="line-clamp-2 text-sm font-medium">{product.name}</p>
        <div className="mt-auto flex min-h-8 items-center justify-between pt-1">
          <span className="text-sm font-semibold tabular-nums">{formatRupiah(product.price)}</span>
          {soldOut ? null : (
            <span className="flex size-7 items-center justify-center rounded-full bg-neutral-950 text-white transition-colors group-hover:bg-brand group-hover:text-brand-foreground">
              <PlusIcon className="size-4" aria-hidden />
            </span>
          )}
        </div>
      </div>
    </button>
  );
}

function ProductSheet({ product, onOpenChange }: { product: StorefrontProduct | null; onOpenChange: (open: boolean) => void }) {
  return (
    <Sheet open={product !== null} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className="mx-auto max-h-[92svh] w-full max-w-lg gap-0 overflow-hidden rounded-t-2xl p-0">
        {product ? <ProductDetail key={product.id} product={product} onDone={() => onOpenChange(false)} /> : null}
      </SheetContent>
    </Sheet>
  );
}

function ProductDetail({ product, onDone }: { product: StorefrontProduct; onDone: () => void }) {
  const cart = useCart();
  const selection = useProductSelection(product);
  const { priced, quantity } = selection;
  const [adding, startAdding] = useTransition();

  function add() {
    const line = { productId: product.id, modifierIds: selection.selected, quantity, notes: selection.notes.trim() || null };
    startAdding(async () => {
      // Validasi ke server sebelum masuk keranjang (harga dan ketersediaan terbaru).
      const quote = await requestQuote(cart.outlet, [line]);
      const result = quote.ok ? quote.lines[0] : null;
      if (!result?.ok) {
        toast.error(result ? result.error : quote.ok ? "Gagal menambahkan." : quote.error);
        return;
      }
      cart.add(line);
      toast.success(`${product.name} ditambahkan.`);
      onDone();
    });
  }

  return (
    <div className="flex max-h-[92svh] flex-col">
      <div className="flex-1 overflow-y-auto">
        <div className="relative aspect-4/3 bg-muted">
          {product.imageUrl ? (
            <img src={product.imageUrl} alt={product.name} className="size-full object-cover" />
          ) : (
            <CoffeeIcon className="absolute inset-0 m-auto size-12 text-muted-foreground/50" aria-hidden />
          )}
        </div>
        <div className="flex flex-col gap-1 p-4">
          <SheetTitle className="text-xl">{product.name}</SheetTitle>
          {product.description ? <SheetDescription>{product.description}</SheetDescription> : <SheetDescription className="sr-only">Detail produk</SheetDescription>}
          <p className="mt-1 font-semibold tabular-nums">{formatRupiah(product.price)}</p>
          {!product.isAvailable ? (
            <Badge variant="destructive" className="mt-1 w-fit">
              Sold out
            </Badge>
          ) : null}
        </div>

        {product.groups.map((group) => (
          <ModifierGroupField
            key={group.id}
            group={group}
            selected={selection.selected}
            disabled={!product.isAvailable}
            onChange={(ids) => selection.setGroupSelection(group, ids)}
          />
        ))}
        <ItemNotesField value={selection.notes} onChange={selection.setNotes} disabled={!product.isAvailable} />
      </div>

      <SheetFooter className="flex-row items-center gap-3 border-t bg-background p-4">
        <QuantityStepper value={quantity} onChange={selection.setQuantity} disabled={!product.isAvailable} />
        <Button size="lg" className="h-11 flex-1" disabled={!priced.ok || adding} onClick={add}>
          {adding ? <Loader2Icon className="animate-spin" /> : null}
          {!product.isAvailable ? "Sold out" : priced.ok ? `Tambah · ${formatRupiah(priced.unitPrice * quantity)}` : priced.error}
        </Button>
      </SheetFooter>
    </div>
  );
}

function CartBar() {
  const cart = useCart();
  const [open, setOpen] = useState(false);
  if (cart.lines.length === 0) return null;
  const count = cart.lines.reduce((sum, l) => sum + l.quantity, 0);

  return (
    <>
      <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 p-3 backdrop-blur">
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="mx-auto flex w-full max-w-lg items-center justify-between rounded-xl bg-neutral-950 px-4 py-3 text-white"
        >
          <span className="inline-flex items-center gap-2 text-sm font-medium">
            <ShoppingBagIcon className="size-4" aria-hidden /> {count} item
          </span>
          <span className="font-semibold tabular-nums">
            {cart.quote?.ok ? formatRupiah(cart.quote.total) : <Loader2Icon className="size-4 animate-spin" />}
          </span>
        </button>
      </div>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="bottom" className="mx-auto max-h-[92svh] w-full max-w-lg gap-0 rounded-t-2xl p-0">
          <SheetHeader className="border-b p-4">
            <SheetTitle>Keranjang</SheetTitle>
            <SheetDescription>Harga dihitung ulang dari menu terbaru.</SheetDescription>
          </SheetHeader>
          <div className="flex-1 divide-y overflow-y-auto">
            {cart.quote && !cart.quote.ok ? (
              <p role="alert" className="p-4 text-sm text-destructive">
                {cart.quote.error}
              </p>
            ) : null}
            {cart.lines.map((line, index) => {
              const quoted = cart.quote?.ok ? cart.quote.lines[index] : null;
              return (
                <div key={`${line.productId}-${index}`} className="flex items-start gap-3 p-4">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium">{quoted?.name ?? "Memuat…"}</p>
                    {quoted?.ok && quoted.modifiers.length ? (
                      <p className="text-xs text-muted-foreground">
                        {quoted.modifiers.map((m) => `${m.name}${m.priceDelta ? ` (${formatPriceDelta(m.priceDelta)})` : ""}`).join(", ")}
                      </p>
                    ) : null}
                    {line.notes ? <p className="text-xs text-muted-foreground">Catatan: {line.notes}</p> : null}
                    {quoted && !quoted.ok ? <p className="text-xs text-destructive">{quoted.error}</p> : null}
                    <div className="mt-2 flex items-center gap-2">
                      <Button size="icon-sm" variant="outline" aria-label="Kurangi" disabled={line.quantity <= 1} onClick={() => cart.setQuantity(index, line.quantity - 1)}>
                        <MinusIcon />
                      </Button>
                      <span className="w-6 text-center text-sm tabular-nums">{line.quantity}</span>
                      <Button
                        size="icon-sm"
                        variant="outline"
                        aria-label="Tambah"
                        disabled={line.quantity >= MAX_LINE_QUANTITY}
                        onClick={() => cart.setQuantity(index, line.quantity + 1)}
                      >
                        <PlusIcon />
                      </Button>
                      <Button size="icon-sm" variant="ghost" aria-label={`Hapus ${quoted?.name ?? "item"}`} onClick={() => cart.remove(index)}>
                        <Trash2Icon className="text-destructive" />
                      </Button>
                    </div>
                  </div>
                  <span className="text-sm font-medium tabular-nums">{quoted?.ok ? formatRupiah(quoted.lineTotal) : "—"}</span>
                </div>
              );
            })}
          </div>
          <SheetFooter className="gap-2 border-t p-4">
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Total</span>
              <span className="text-base font-semibold tabular-nums">{cart.quote?.ok ? formatRupiah(cart.quote.total) : "—"}</span>
            </div>
            {/* Checkout dibangun di modul order (M3). Tidak ada order atau pembayaran yang dibuat di sini. */}
            <p role="status" className="rounded-lg border bg-muted px-3 py-2 text-xs">
              Pemesanan online belum dibuka. Tunjukkan pilihan ini ke kasir untuk memesan.
            </p>
            <Button size="lg" disabled>
              Checkout belum tersedia
            </Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </>
  );
}
