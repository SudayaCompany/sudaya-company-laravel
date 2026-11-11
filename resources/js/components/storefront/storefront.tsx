import { Head, Link, router } from "@inertiajs/react";
import {
  ArrowLeftIcon,
  CheckCircle2Icon,
  CoffeeIcon,
  Loader2Icon,
  MapPinIcon,
  MinusIcon,
  PlusIcon,
  SearchIcon,
  ShoppingBagIcon,
  Trash2Icon,
  UtensilsIcon,
  XIcon,
} from "lucide-react";
import { createContext, use, useEffect, useMemo, useRef, useState, useTransition } from "react";
import { toast } from "sonner";

import { Brand } from "@/components/brand";
import { ItemNotesField, ModifierGroupField, QuantityStepper, useProductSelection } from "@/components/catalog/product-options";
import { EmptyState } from "@/components/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";
import { type CartLine, type CartQuote, requestQuote, useCartLines } from "@/lib/catalog/cart";
import { MAX_LINE_QUANTITY, type PricingProduct } from "@/lib/catalog/pricing";
import { formatPriceDelta, formatRupiah } from "@/lib/format";
import { ORDER_CONFLICT_MESSAGE, ORDER_TYPE_LABELS, PAYMENT_OPTIONS, placeOrder, type OrderType } from "@/lib/orders";
import { cn } from "@/lib/utils";

export type StorefrontProduct = PricingProduct & {
  slug: string;
  description: string | null;
  imageUrl: string | null;
  categoryId: string;
  defaultModifierIds: string[];
};

export type Storefront = {
  outlet: { id: string; code: string; name: string; qrisAvailable?: boolean };
  brand: { id: string; name: string };
  categories: { id: string; name: string; slug: string }[];
  products: StorefrontProduct[];
};

export type StorefrontMode = "order" | "kiosk";
type PublicChannel = "qr_table" | "qr_counter" | "website" | "kiosk";

type CartContextValue = {
  outlet: string;
  channel: PublicChannel;
  mode: StorefrontMode;
  table: string | null;
  qrisAvailable: boolean;
  lines: CartLine[];
  quote: CartQuote | null;
  add: (line: CartLine) => void;
  setQuantity: (index: number, quantity: number) => void;
  remove: (index: number) => void;
  clear: () => void;
};

const CartContext = createContext<CartContextValue | null>(null);

function useCart() {
  const cart = use(CartContext);
  if (!cart) throw new Error("useCart harus di dalam CartProvider");
  return cart;
}

const sameLine = (a: CartLine, b: CartLine) =>
  a.productId === b.productId && (a.notes ?? "") === (b.notes ?? "") && [...a.modifierIds].sort().join() === [...b.modifierIds].sort().join();

function CartProvider({
  outlet,
  channel,
  mode,
  table,
  qrisAvailable,
  children,
}: {
  outlet: string;
  channel: PublicChannel;
  mode: StorefrontMode;
  table: string | null;
  qrisAvailable: boolean;
  children: React.ReactNode;
}) {
  const { lines, save } = useCartLines(mode === "kiosk" ? `kiosk:${outlet}` : outlet);
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
    // linesKey mewakili isi `lines`.
  }, [outlet, linesKey]);

  const value = useMemo<CartContextValue>(
    () => ({
      outlet,
      channel,
      mode,
      table,
      qrisAvailable,
      lines,
      quote: lines.length === 0 ? null : quote?.for === linesKey ? quote.value : null,
      add: (line) => {
        const existing = lines.findIndex((l) => sameLine(l, line));
        save(
          existing === -1
            ? [...lines, line]
            : lines.map((l, i) => (i === existing ? { ...l, quantity: Math.min(l.quantity + line.quantity, MAX_LINE_QUANTITY) } : l)),
        );
      },
      setQuantity: (index, quantity) => {
        if (quantity < 1) return save(lines.filter((_, i) => i !== index));
        save(lines.map((l, i) => (i === index ? { ...l, quantity: Math.min(quantity, MAX_LINE_QUANTITY) } : l)));
      },
      remove: (index) => save(lines.filter((_, i) => i !== index)),
      clear: () => save([]),
    }),
    [outlet, channel, mode, table, qrisAvailable, lines, linesKey, quote, save],
  );

  return <CartContext value={value}>{children}</CartContext>;
}

/** Menu customer untuk QR, web, dan kiosk. Semua checkout lewat order engine yang sama. */
export function StorefrontView({
  storefront,
  table,
  channel,
  mode = "order",
}: {
  storefront: Storefront;
  table: string | null;
  channel: PublicChannel;
  mode?: StorefrontMode;
}) {
  const kiosk = mode === "kiosk";
  const [openProduct, setOpenProduct] = useState<StorefrontProduct | null>(null);
  const [search, setSearch] = useState("");
  const sections = storefront.categories
    .map((category) => ({ category, products: storefront.products.filter((p) => p.categoryId === category.id) }))
    .filter((s) => s.products.length > 0);
  const query = search.trim().toLowerCase();
  const results = query
    ? storefront.products.filter((p) => p.name.toLowerCase().includes(query) || (p.description ?? "").toLowerCase().includes(query))
    : null;
  const active = useActiveSection(sections.map((s) => `kategori-${s.category.slug}`), results === null);
  const gridClass = cn("grid", kiosk ? "grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4" : "grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4");

  return (
    <CartProvider
      outlet={storefront.outlet.code}
      channel={channel}
      mode={mode}
      table={table}
      qrisAvailable={storefront.outlet.qrisAvailable ?? false}
    >
      <Head title={kiosk ? "Kiosk" : "Menu"} />
      <div className="flex min-h-svh flex-col bg-background">
        <header className="relative isolate overflow-hidden bg-espresso text-white">
          <div className="pointer-events-none absolute -top-24 -right-16 -z-10 size-72 rounded-full bg-brand/25 blur-3xl" aria-hidden />
          <div className="pointer-events-none absolute -bottom-32 -left-20 -z-10 size-64 rounded-full bg-success/15 blur-3xl" aria-hidden />
          <div className={cn("mx-auto w-full max-w-5xl px-4", kiosk ? "pt-8 pb-10" : "pt-5 pb-8")}>
            {kiosk ? (
              <Brand tone="light" className="text-2xl" />
            ) : (
              <Link href="/" className="rounded-md text-lg focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none">
                <Brand tone="light" />
              </Link>
            )}
            <h1 className={cn("mt-6 font-display font-semibold tracking-tight text-balance", kiosk ? "text-5xl sm:text-6xl" : "text-4xl sm:text-5xl")}>
              {kiosk ? "Pesan di sini, bayar di kasir" : "Mau ngopi apa hari ini?"}
            </h1>
            <p className={cn("mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-neutral-300", kiosk ? "text-base" : "text-sm")}>
              <span className="inline-flex items-center gap-1.5">
                <MapPinIcon className="size-4 text-brand" aria-hidden /> {storefront.outlet.name}
              </span>
              {table ? (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-brand px-3 py-1 font-semibold text-brand-foreground">
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
            <nav aria-label="Kategori menu" className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/80">
              <div className="mx-auto flex w-full max-w-5xl flex-col gap-3 px-4 py-3">
                {kiosk ? null : (
                  <div className="relative">
                    <SearchIcon className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                    <Input
                      type="search"
                      value={search}
                      onChange={(event) => setSearch(event.target.value)}
                      placeholder="Cari kopi, teh, camilan…"
                      aria-label="Cari menu"
                      enterKeyHint="search"
                      className="h-11 rounded-full bg-muted/60 pr-10 pl-10 text-base [&::-webkit-search-cancel-button]:hidden"
                    />
                    {search ? (
                      <button
                        type="button"
                        onClick={() => setSearch("")}
                        aria-label="Hapus pencarian"
                        className="absolute top-1/2 right-1.5 flex size-8 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                      >
                        <XIcon className="size-4" aria-hidden />
                      </button>
                    ) : null}
                  </div>
                )}
                {results === null ? (
                  <CategoryChips sections={sections.map((s) => s.category)} active={active} large={kiosk} />
                ) : null}
              </div>
            </nav>

            <main className={cn("mx-auto flex w-full max-w-5xl flex-1 flex-col px-4 pt-6 pb-32", kiosk ? "gap-10" : "gap-8")}>
              {results !== null ? (
                <section aria-live="polite">
                  <h2 className="mb-3 text-sm text-muted-foreground">
                    {results.length ? `${results.length} hasil untuk “${search.trim()}”` : `Tidak ada menu yang cocok dengan “${search.trim()}”.`}
                  </h2>
                  <div className={gridClass}>
                    {results.map((product) => (
                      <ProductCard key={product.id} product={product} large={kiosk} onOpen={() => setOpenProduct(product)} />
                    ))}
                  </div>
                </section>
              ) : (
                sections.map(({ category, products }) => (
                  <section key={category.id} id={`kategori-${category.slug}`} className={kiosk ? "scroll-mt-24" : "scroll-mt-32"}>
                    <h2 className={cn("mb-4 font-display font-semibold tracking-tight", kiosk ? "text-3xl" : "text-2xl")}>{category.name}</h2>
                    <div className={gridClass}>
                      {products.map((product) => (
                        <ProductCard key={product.id} product={product} large={kiosk} onOpen={() => setOpenProduct(product)} />
                      ))}
                    </div>
                  </section>
                ))
              )}
            </main>
          </>
        )}

        <ProductSheet product={openProduct} onOpenChange={(open) => !open && setOpenProduct(null)} />
        <CartBar />
      </div>
    </CartProvider>
  );
}

/** Kategori yang sedang terlihat di layar, untuk menandai chip navigasi. */
function useActiveSection(ids: string[], enabled: boolean) {
  const [active, setActive] = useState<string | null>(ids[0] ?? null);
  const key = ids.join("|");
  useEffect(() => {
    if (!enabled || ids.length === 0) return;
    let frame = 0;
    const update = () => {
      frame = 0;
      // Di dasar halaman seksi terakhir aktif, walau judulnya tidak sampai ke bawah nav.
      const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
      // Seksi terakhir yang judulnya sudah melewati garis di bawah nav sticky.
      const passed = ids.filter((id) => (document.getElementById(id)?.getBoundingClientRect().top ?? Infinity) <= 160);
      setActive(atBottom && window.scrollY > 0 ? ids[ids.length - 1] : (passed.at(-1) ?? ids[0]));
    };
    const onScroll = () => {
      if (!frame) frame = requestAnimationFrame(update);
    };
    update();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
    };
    // key mewakili isi `ids`.
  }, [key, enabled]);
  return active;
}

function CategoryChips({ sections, active, large }: { sections: Storefront["categories"]; active: string | null; large: boolean }) {
  const rail = useRef<HTMLDivElement>(null);
  // Chip aktif digeser ke area terlihat tanpa menggulir halaman.
  useEffect(() => {
    const chip = rail.current?.querySelector<HTMLElement>(`[data-section="${active}"]`);
    if (!chip || !rail.current) return;
    const { offsetLeft, offsetWidth } = chip;
    const target = offsetLeft - (rail.current.clientWidth - offsetWidth) / 2;
    rail.current.scrollTo({ left: Math.max(0, target), behavior: "smooth" });
  }, [active]);

  return (
    <div ref={rail} className="-mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
      {sections.map((category) => {
        const id = `kategori-${category.slug}`;
        const current = active === id;
        return (
          <a
            key={category.id}
            href={`#${id}`}
            data-section={id}
            aria-current={current ? "true" : undefined}
            className={cn(
              "shrink-0 rounded-full border font-medium transition-colors duration-200",
              large ? "px-6 py-3 text-lg" : "px-4 py-2 text-sm",
              current ? "border-espresso bg-espresso text-white dark:border-white dark:bg-white dark:text-espresso" : "hover:border-foreground",
            )}
          >
            {category.name}
          </a>
        );
      })}
    </div>
  );
}

function ProductCard({ product, large, onOpen }: { product: StorefrontProduct; large: boolean; onOpen: () => void }) {
  const soldOut = !product.isAvailable;
  return (
    <button
      type="button"
      onClick={onOpen}
      className="group flex cursor-pointer flex-col overflow-hidden rounded-3xl border bg-card text-left transition-[box-shadow,transform] duration-200 hover:shadow-lg active:scale-[0.98] focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      aria-label={`${product.name}, ${formatRupiah(product.price)}${soldOut ? ", habis" : ""}`}
    >
      <div className="relative aspect-square overflow-hidden bg-cream">
        {product.imageUrl ? (
          <img src={product.imageUrl} alt="" loading="lazy" className={cn("size-full object-cover transition-transform duration-300 group-hover:scale-[1.04]", soldOut && "grayscale")} />
        ) : (
          // Placeholder jelas selama foto asli belum ada (bukan foto palsu).
          <span className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-gradient-to-br from-cream to-brand-soft text-espresso/50" aria-hidden>
            <CoffeeIcon className="size-10" />
            <span className="text-[11px] font-medium tracking-wide uppercase">Foto menyusul</span>
          </span>
        )}
        {soldOut ? (
          <span className="absolute inset-0 flex items-center justify-center bg-black/45">
            <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-espresso">Habis</span>
          </span>
        ) : null}
      </div>
      <div className={cn("flex flex-1 flex-col gap-1", large ? "p-4" : "p-3")}>
        <p className={cn("line-clamp-2 leading-snug font-semibold", large ? "text-xl" : "text-[0.95rem]")}>{product.name}</p>
        {product.description ? <p className={cn("line-clamp-2 text-muted-foreground", large ? "text-sm" : "text-xs")}>{product.description}</p> : null}
        <div className="mt-auto flex min-h-9 items-center justify-between gap-2 pt-2">
          <span className={cn("font-semibold tabular-nums", large ? "text-xl" : "text-sm")}>{formatRupiah(product.price)}</span>
          {soldOut ? null : (
            <span
              className={cn(
                "flex shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground transition-transform duration-200 group-hover:scale-110",
                large ? "size-11" : "size-8",
              )}
            >
              <PlusIcon className={large ? "size-5" : "size-4"} aria-hidden />
            </span>
          )}
        </div>
      </div>
    </button>
  );
}

function ProductSheet({ product, onOpenChange }: { product: StorefrontProduct | null; onOpenChange: (open: boolean) => void }) {
  const { mode } = useCart();
  return (
    <Sheet open={product !== null} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className={cn("mx-auto max-h-[92svh] w-full gap-0 overflow-hidden rounded-t-3xl p-0", mode === "kiosk" ? "max-w-2xl" : "max-w-lg")}>
        {product ? <ProductDetail key={product.id} product={product} onDone={() => onOpenChange(false)} /> : null}
      </SheetContent>
    </Sheet>
  );
}

function ProductDetail({ product, onDone }: { product: StorefrontProduct; onDone: () => void }) {
  const cart = useCart();
  const large = cart.mode === "kiosk";
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
          <SheetTitle className={large ? "text-2xl" : "text-xl"}>{product.name}</SheetTitle>
          {product.description ? <SheetDescription>{product.description}</SheetDescription> : <SheetDescription className="sr-only">Detail produk</SheetDescription>}
          <p className={cn("mt-1 font-semibold tabular-nums", large && "text-lg")}>{formatRupiah(product.price)}</p>
          {!product.isAvailable ? (
            <Badge variant="destructive" className="mt-1 w-fit">
              Habis
            </Badge>
          ) : null}
        </div>

        {product.groups.map((group) => (
          <ModifierGroupField
            key={group.id}
            group={group}
            large={large}
            selected={selection.selected}
            disabled={!product.isAvailable}
            onChange={(ids) => selection.setGroupSelection(group, ids)}
          />
        ))}
        <ItemNotesField value={selection.notes} onChange={selection.setNotes} disabled={!product.isAvailable} />
      </div>

      <SheetFooter className="flex-row items-center gap-3 border-t bg-background p-4">
        <QuantityStepper value={quantity} onChange={selection.setQuantity} disabled={!product.isAvailable} large={large} />
        <Button size="lg" className={cn("flex-1", large ? "h-14 text-lg" : "h-11")} disabled={!priced.ok || adding} onClick={add}>
          {adding ? <Loader2Icon className="animate-spin" /> : null}
          {!product.isAvailable ? "Habis" : priced.ok ? `Tambah · ${formatRupiah(priced.unitPrice * quantity)}` : priced.error}
        </Button>
      </SheetFooter>
    </div>
  );
}

function CartBar() {
  const cart = useCart();
  const [open, setOpen] = useState(false);
  const [placed, setPlaced] = useState<{ reference: string; total: number } | null>(null);
  const kiosk = cart.mode === "kiosk";

  return (
    <>
      {cart.lines.length > 0 ? (
        <div className="fixed inset-x-0 bottom-0 z-40 p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <button
            type="button"
            onClick={() => setOpen(true)}
            className={cn(
              "mx-auto flex w-full max-w-lg items-center gap-3 rounded-2xl bg-espresso px-4 text-left text-white shadow-xl transition-transform active:scale-[0.99]",
              kiosk ? "max-w-2xl py-5" : "py-3",
            )}
          >
            <span className={cn("flex items-center justify-center rounded-xl bg-brand text-brand-foreground", kiosk ? "size-14" : "size-10")}>
              <ShoppingBagIcon className={kiosk ? "size-7" : "size-5"} aria-hidden />
            </span>
            <span className="flex-1">
              <span className={cn("block font-medium", kiosk ? "text-lg" : "text-sm")}>{cart.lines.reduce((sum, l) => sum + l.quantity, 0)} item</span>
              <span className={cn("block text-neutral-400", kiosk ? "text-sm" : "text-xs")}>Lihat keranjang</span>
            </span>
            <span className={cn("font-semibold tabular-nums", kiosk ? "text-xl" : "text-base")}>
              {cart.quote?.ok ? formatRupiah(cart.quote.total) : <Loader2Icon className="size-4 animate-spin" />}
            </span>
          </button>
        </div>
      ) : null}
      <CartSheet open={open} onOpenChange={setOpen} onKioskPlaced={setPlaced} />
      {placed ? <KioskConfirmation {...placed} onDone={() => setPlaced(null)} /> : null}
    </>
  );
}

function CartSheet({
  open,
  onOpenChange,
  onKioskPlaced,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onKioskPlaced: (placed: { reference: string; total: number }) => void;
}) {
  const cart = useCart();
  const [step, setStep] = useState<"cart" | "checkout">("cart");
  const quote = cart.quote;
  const kiosk = cart.mode === "kiosk";
  const canCheckout = quote?.ok === true && quote.lines.every((l) => l.ok) && cart.lines.length > 0;

  return (
    <Sheet
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) setStep("cart");
      }}
    >
      <SheetContent side="bottom" className={cn("mx-auto max-h-[92svh] w-full gap-0 rounded-t-3xl p-0", kiosk ? "max-w-2xl" : "max-w-lg")}>
        {step === "cart" ? (
          <>
            <SheetHeader className="border-b p-4">
              <SheetTitle className={kiosk ? "text-2xl" : undefined}>Keranjang</SheetTitle>
              <SheetDescription>Harga dihitung ulang dari menu terbaru.</SheetDescription>
            </SheetHeader>
            <div className="min-h-0 flex-1 overflow-y-auto">
              {quote && !quote.ok ? (
                <p role="alert" className="p-4 text-sm text-destructive">
                  {quote.error}
                </p>
              ) : null}
              {cart.lines.length === 0 ? <p className="py-6 text-center text-sm text-muted-foreground">Keranjang kosong.</p> : null}
              <ul className="flex flex-col divide-y">
                {cart.lines.map((line, index) => {
                  const quoted = quote?.ok ? quote.lines[index] : null;
                  return (
                    <li key={`${line.productId}:${line.modifierIds.join()}:${line.notes ?? ""}`} className="flex items-start gap-3 p-4">
                      <div className="min-w-0 flex-1">
                        <p className={cn("font-medium", kiosk && "text-lg")}>{quoted?.name ?? "Memuat…"}</p>
                        {quoted?.ok && quoted.modifiers.length ? (
                          <p className="text-xs text-muted-foreground">
                            {quoted.modifiers.map((m) => `${m.name}${m.priceDelta ? ` (${formatPriceDelta(m.priceDelta)})` : ""}`).join(", ")}
                          </p>
                        ) : null}
                        {line.notes ? <p className="text-xs text-muted-foreground italic">“{line.notes}”</p> : null}
                        {quoted && !quoted.ok ? <p className="text-xs text-destructive">{quoted.error}</p> : null}
                        <div className="mt-2 flex items-center gap-2">
                          <Button size={kiosk ? "icon-lg" : "icon-sm"} variant="outline" aria-label="Kurangi" onClick={() => cart.setQuantity(index, line.quantity - 1)}>
                            <MinusIcon />
                          </Button>
                          <span className="w-6 text-center text-sm tabular-nums">{line.quantity}</span>
                          <Button
                            size={kiosk ? "icon-lg" : "icon-sm"}
                            variant="outline"
                            aria-label="Tambah"
                            disabled={line.quantity >= MAX_LINE_QUANTITY}
                            onClick={() => cart.setQuantity(index, line.quantity + 1)}
                          >
                            <PlusIcon />
                          </Button>
                          <Button size={kiosk ? "icon-lg" : "icon-sm"} variant="ghost" aria-label={`Hapus ${quoted?.name ?? "item"}`} onClick={() => cart.remove(index)}>
                            <Trash2Icon className="text-destructive" />
                          </Button>
                        </div>
                      </div>
                      <p className="text-sm font-medium tabular-nums">{quoted?.ok ? formatRupiah(quoted.lineTotal) : "—"}</p>
                    </li>
                  );
                })}
              </ul>
            </div>
            <SheetFooter className="gap-2 border-t p-4">
              <div className="flex items-center justify-between text-base font-semibold">
                <span>Total</span>
                <span className="tabular-nums">
                  {quote?.ok ? formatRupiah(quote.total) : cart.lines.length ? <Loader2Icon className="size-4 animate-spin" /> : formatRupiah(0)}
                </span>
              </div>
              <Button size="lg" className={kiosk ? "h-14 text-lg" : "h-11"} disabled={!canCheckout} onClick={() => setStep("checkout")}>
                Lanjut ke checkout
              </Button>
            </SheetFooter>
          </>
        ) : (
          <CheckoutForm
            onBack={() => setStep("cart")}
            onPlaced={(result) => {
              onOpenChange(false);
              setStep("cart");
              if (kiosk) onKioskPlaced(result);
            }}
          />
        )}
      </SheetContent>
    </Sheet>
  );
}

function CheckoutForm({ onBack, onPlaced }: { onBack: () => void; onPlaced: (result: { reference: string; total: number }) => void }) {
  const cart = useCart();
  const kiosk = cart.mode === "kiosk";
  const [type, setType] = useState<OrderType>(cart.table ? "dine_in" : "takeaway");
  const [error, setError] = useState<string | null>(null);
  const [submitting, startSubmit] = useTransition();
  // Satu key per percobaan checkout: klik ganda atau retry menghasilkan order yang sama.
  const idempotencyKey = useRef<string | null>(null);
  // QR meja kantor: antar ke meja (gratis; tunai dibayar ke barista saat diantar) atau ambil sendiri di counter.
  const desk = cart.channel === "qr_table";
  const types: OrderType[] = kiosk || desk ? ["dine_in", "takeaway"] : ["dine_in", "takeaway", "delivery"];
  const typeLabel = (t: OrderType) => (desk ? (t === "dine_in" ? "Antar ke meja" : "Ambil di counter") : ORDER_TYPE_LABELS[t]);
  const [payment, setPayment] = useState<"cash" | "qris">("cash");
  const total = cart.quote?.ok ? cart.quote.total : null;

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const text = (name: string) => String(form.get(name) ?? "").trim() || undefined;
    if (desk && type === "dine_in" && (!text("floor") || !text("department") || (text("customerName") ?? "").length < 2)) {
      setError("Isi nama, lantai, dan bagian agar barista bisa mengantar ke meja Anda.");
      return;
    }
    idempotencyKey.current ??= crypto.randomUUID();
    setError(null);
    startSubmit(async () => {
      const result = await placeOrder({
        outlet: cart.outlet,
        channel: cart.channel,
        checkout: {
          idempotencyKey: idempotencyKey.current,
          type,
          customerName: text("customerName"),
          customerPhone: text("customerPhone"),
          tableNumber: type === "dine_in" ? text("tableNumber") : undefined,
          locationNote: type === "dine_in" && !desk ? text("locationNote") : undefined,
          floor: type === "dine_in" && desk ? text("floor") : undefined,
          department: type === "dine_in" && desk ? text("department") : undefined,
          deliveryAddress: type === "delivery" ? text("deliveryAddress") : undefined,
          notes: text("notes"),
          customRequest: text("customRequest"),
          paymentMethod: payment,
          lines: cart.lines.map((l) => ({ productId: l.productId, modifierIds: l.modifierIds, quantity: l.quantity, notes: l.notes ?? undefined })),
        },
      });
      if (!result.ok) {
        if (result.error === ORDER_CONFLICT_MESSAGE) idempotencyKey.current = null;
        setError(result.error);
        toast.error(result.error);
        return;
      }
      idempotencyKey.current = null;
      cart.clear();
      onPlaced(result);
      if (!kiosk) router.visit(`/order/status/${result.orderId}`);
    });
  }

  const fieldClass = kiosk ? "h-12 text-lg" : "h-10";

  return (
    <form onSubmit={submit} className="flex max-h-[92svh] min-h-0 flex-col" noValidate>
      <SheetHeader className="border-b p-4">
        <div className="flex items-center gap-2">
          <Button type="button" size="icon-sm" variant="ghost" onClick={onBack} aria-label="Kembali ke keranjang">
            <ArrowLeftIcon />
          </Button>
          <SheetTitle className={kiosk ? "text-2xl" : undefined}>Checkout</SheetTitle>
        </div>
        <SheetDescription>{desk ? "Isi nama, lantai, dan bagian agar barista bisa mengantar ke meja Anda." : "Isi data pemesan. Pembayaran dikonfirmasi kasir."}</SheetDescription>
      </SheetHeader>

      <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">Tipe pesanan</legend>
          <div className={cn("grid gap-2", types.length === 3 ? "grid-cols-3" : "grid-cols-2")}>
            {types.map((t) => (
              <button
                key={t}
                type="button"
                aria-pressed={type === t}
                onClick={() => setType(t)}
                className={cn(
                  "min-h-11 rounded-xl border px-3 text-sm font-medium transition-colors",
                  kiosk ? "py-4 text-lg" : "py-2.5",
                  type === t ? "border-espresso bg-espresso text-white" : "hover:border-foreground",
                )}
              >
                {typeLabel(t)}
              </button>
            ))}
          </div>
        </fieldset>

        {type === "dine_in" ? (
          <>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="tableNumber">Nomor meja{kiosk ? " (opsional)" : ""}</Label>
              <Input
                id="tableNumber"
                name="tableNumber"
                defaultValue={cart.table ?? ""}
                // QR meja: nomor meja dari QR yang sudah divalidasi, tidak diketik ulang.
                readOnly={cart.channel === "qr_table"}
                maxLength={10}
                className={cn(fieldClass, cart.channel === "qr_table" && "bg-muted")}
                placeholder="mis. T03"
              />
            </div>
            {desk ? (
              <div className="grid grid-cols-2 gap-3">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="floor">Lantai</Label>
                  <Input id="floor" name="floor" required maxLength={20} className={fieldClass} placeholder="mis. 3" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="department">Bagian / divisi</Label>
                  <Input id="department" name="department" required maxLength={60} className={fieldClass} placeholder="mis. Keuangan" />
                </div>
              </div>
            ) : (
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="locationNote">
                  Lokasi/keterangan <span className="text-xs font-normal text-muted-foreground">(opsional)</span>
                </Label>
                <Input id="locationNote" name="locationNote" maxLength={100} className={fieldClass} placeholder="mis. teras, dekat jendela" />
              </div>
            )}
          </>
        ) : null}

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="customerName">Nama pemesan</Label>
          <Input id="customerName" name="customerName" required minLength={2} maxLength={60} autoComplete="name" className={fieldClass} />
        </div>

        {kiosk ? null : (
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="customerPhone">
              Nomor HP {type === "delivery" ? "" : <span className="text-xs font-normal text-muted-foreground">(opsional)</span>}
            </Label>
            <Input id="customerPhone" name="customerPhone" type="tel" inputMode="tel" autoComplete="tel" className={fieldClass} placeholder="0812xxxxxxx" />
          </div>
        )}

        {type === "delivery" ? (
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="deliveryAddress">Alamat lengkap</Label>
            <Textarea id="deliveryAddress" name="deliveryAddress" maxLength={300} rows={3} autoComplete="street-address" placeholder="Jalan, nomor, patokan" />
            {/* Belum ada aturan ongkir resmi, jadi biaya antar tidak ditampilkan atau dihitung. */}
            <p className="text-xs text-muted-foreground">Ongkir & waktu antar dikonfirmasi langsung oleh outlet.</p>
          </div>
        ) : null}

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="notes">
            Catatan pesanan <span className="text-xs font-normal text-muted-foreground">(opsional)</span>
          </Label>
          <Textarea id="notes" name="notes" maxLength={300} rows={2} />
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="customRequest">
            Pesan menu di luar daftar <span className="text-xs font-normal text-muted-foreground">(opsional)</span>
          </Label>
          <Textarea id="customRequest" name="customRequest" maxLength={200} rows={2} placeholder="mis. Matcha latte oat milk 1 gelas" />
          <p className="text-xs text-muted-foreground">Dibuat bila bahannya ada; harga dikonfirmasi barista/kasir.</p>
        </div>

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">Pembayaran</legend>
          {PAYMENT_OPTIONS.map((option) => {
            // QRIS statis milik outlet aktif bila admin sudah mengunggah gambarnya; dikonfirmasi kasir.
            const available = option.method === "qris" ? cart.qrisAvailable && !kiosk : option.available;
            const note =
              option.method === "qris"
                ? available
                  ? "Scan QRIS Sudaya, dikonfirmasi kasir"
                  : option.note
                : desk
                  ? type === "dine_in"
                    ? "Bayar ke barista saat diantar"
                    : "Bayar di kasir"
                  : option.note;
            return (
              <label
                key={option.method}
                className={cn("flex min-h-11 items-center gap-3 rounded-xl border p-3 text-sm", available ? "cursor-pointer has-checked:border-espresso" : "cursor-not-allowed opacity-50")}
              >
                <input
                  type="radio"
                  name="paymentMethod"
                  value={option.method}
                  checked={payment === option.method}
                  onChange={() => setPayment(option.method)}
                  disabled={!available}
                  className="accent-espresso"
                />
                <span className="flex-1 font-medium">{option.label}</span>
                <span className="text-xs text-muted-foreground">{note}</span>
              </label>
            );
          })}
        </fieldset>


        {error ? (
          <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive-strong">
            {error}
          </p>
        ) : null}
      </div>

      <SheetFooter className="border-t p-4">
        <Button type="submit" size="lg" className={kiosk ? "h-14 text-lg" : "h-11"} disabled={submitting || total === null}>
          {submitting ? <Loader2Icon className="animate-spin" /> : null}
          {submitting ? "Mengirim…" : `Kirim pesanan · ${total === null ? "…" : formatRupiah(total)}`}
        </Button>
      </SheetFooter>
    </form>
  );
}

/** Layar konfirmasi kiosk; kembali ke menu otomatis untuk customer berikutnya. */
function KioskConfirmation({ reference, total, onDone }: { reference: string; total: number; onDone: () => void }) {
  useEffect(() => {
    const timer = setTimeout(onDone, 45_000);
    return () => clearTimeout(timer);
  }, [onDone]);

  return (
    // z-[60]: di atas sheet keranjang (z-50) yang masih beranimasi menutup.
    <div role="dialog" aria-modal="true" aria-labelledby="kiosk-done" className="fixed inset-0 z-[60] flex items-center justify-center bg-espresso/95 p-6 text-white">
      <div className="flex w-full max-w-xl flex-col items-center gap-6 text-center">
        <CheckCircle2Icon className="size-16 text-success" aria-hidden />
        <h2 id="kiosk-done" className="text-3xl font-semibold">
          Pesanan diterima
        </h2>
        <div className="rounded-2xl bg-white/10 px-8 py-5">
          <p className="text-sm text-neutral-400">Kode pesanan</p>
          <p className="font-mono text-5xl font-bold tracking-widest">{reference}</p>
        </div>
        <p className="text-lg">
          Status pembayaran: <span className="font-semibold text-brand">Menunggu pembayaran</span>
        </p>
        <p className="text-neutral-300">
          Silakan bayar <span className="font-semibold text-white">{formatRupiah(total)}</span> di kasir dengan menyebutkan kode di atas. Nomor antrean diberikan setelah
          pembayaran.
        </p>
        <Button size="lg" className="h-14 w-full max-w-xs bg-brand text-lg text-brand-foreground hover:bg-brand/90" onClick={onDone}>
          Selesai
        </Button>
      </div>
    </div>
  );
}
