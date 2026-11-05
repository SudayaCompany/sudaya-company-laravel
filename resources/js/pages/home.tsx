import { Deferred, Head, Link } from "@inertiajs/react";
import { ArrowRightIcon, BanknoteIcon, BellRingIcon, CoffeeIcon, MapPinIcon, QrCodeIcon, ShoppingBagIcon } from "lucide-react";
import type { ReactNode } from "react";

import { Brand } from "@/components/brand";
import { buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

type Featured = { id: string; name: string; description: string | null; price: number; imageUrl: string | null; category: string | null };
type Cafe = {
  outlet: { code: string; name: string; address: string | null };
  categories: string[];
  featured: Featured[];
  productCount: number;
} | null;

const STEPS = [
  { icon: QrCodeIcon, title: "Scan QR atau buka menu", body: "QR ada di meja. Bisa juga langsung dari HP lewat halaman menu." },
  { icon: ShoppingBagIcon, title: "Pilih & kirim pesanan", body: "Atur ukuran, gula, dan topping. Total dihitung otomatis." },
  { icon: BanknoteIcon, title: "Bayar di kasir", body: "Tunjukkan kode pesananmu, kasir mengonfirmasi pembayaran." },
  { icon: BellRingIcon, title: "Ambil saat dipanggil", body: "Nomor antrean tampil di HP-mu dan berubah saat pesanan siap." },
];

const CTA = "rounded-full bg-brand text-brand-foreground hover:bg-brand/90";

/** Halaman depan Sudaya Coffee. Data menu dan outlet berasal dari sistem, bukan contoh. */
export default function Home({ cafe }: { cafe?: Cafe }) {
  const orderHref = cafe ? `/order?outlet=${cafe.outlet.code}` : "/order";

  return (
    <div className="flex min-h-svh flex-1 flex-col bg-background">
      <Head title="Kopi enak, pesan tanpa antre" />

      <header className="relative isolate overflow-hidden bg-neutral-950 text-white">
        <div className="pointer-events-none absolute -top-32 -right-24 -z-10 size-[28rem] rounded-full bg-brand/25 blur-3xl" aria-hidden />
        <div className="pointer-events-none absolute -bottom-40 -left-24 -z-10 size-[22rem] rounded-full bg-success/20 blur-3xl" aria-hidden />
        <nav aria-label="Utama" className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
          <Link href="/" className="rounded-md text-xl focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none">
            <Brand tone="light" />
          </Link>
          <div className="flex items-center gap-1 text-sm">
            {[
              ["#cara-pesan", "Cara pesan"],
              ["#menu", "Menu"],
              ["#lokasi", "Lokasi"],
            ].map(([href, label]) => (
              <a key={href} href={href} className="hidden rounded-full px-3 py-2 text-neutral-300 transition-colors hover:text-white sm:inline">
                {label}
              </a>
            ))}
            <Link href={orderHref} className={cn(buttonVariants({ size: "sm" }), CTA, "ml-2 h-9 px-4")}>
              Pesan
            </Link>
          </div>
        </nav>

        <div className="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 pt-8 pb-20 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:pt-16 lg:pb-28">
          <div className="flex flex-col gap-6">
            <p className="inline-flex w-fit items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-neutral-300">
              <QrCodeIcon className="size-3.5 text-brand" aria-hidden /> Pesan lewat QR · Bayar di kasir
            </p>
            <h1 className="font-display text-5xl leading-[1.05] font-semibold tracking-tight text-balance sm:text-6xl lg:text-7xl">
              Kopi enak, <span className="whitespace-nowrap text-brand italic">tanpa antre</span> lama.
            </h1>
            <p className="max-w-xl text-lg leading-relaxed text-neutral-300">
              Pesan dari meja lewat QR atau langsung dari HP. Bayar di kasir, lalu ambil saat nomormu dipanggil.
            </p>
            <div className="flex flex-wrap gap-3">
              <Link href={orderHref} className={cn(buttonVariants({ size: "lg" }), CTA, "h-12 px-6 text-base")}>
                Lihat menu & pesan <ArrowRightIcon />
              </Link>
              <a
                href="#cara-pesan"
                className={cn(
                  buttonVariants({ size: "lg", variant: "outline" }),
                  "h-12 rounded-full border-white/20 bg-transparent px-6 text-base text-white hover:bg-white/10 hover:text-white dark:bg-transparent",
                )}
              >
                Cara pesan
              </a>
            </div>
          </div>
          <Deferred data="cafe" fallback={<HeroGallery items={null} />}>
            <HeroGallery items={cafe?.featured ?? []} />
          </Deferred>
        </div>
      </header>

      <section id="cara-pesan" className="scroll-mt-4 bg-cream">
        <div className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
          <SectionTitle eyebrow="Cara pesan" title="Empat langkah, tanpa ribet" />
          <ol className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {STEPS.map((step, index) => (
              <li key={step.title} className="flex flex-col gap-3 rounded-3xl border bg-background p-6">
                <div className="flex items-center justify-between">
                  <span className="flex size-12 items-center justify-center rounded-2xl bg-neutral-950 text-white dark:bg-white dark:text-neutral-950">
                    <step.icon className="size-5" aria-hidden />
                  </span>
                  <span className="font-display text-3xl font-semibold text-muted-foreground/40">{index + 1}</span>
                </div>
                <h3 className="text-lg font-semibold">{step.title}</h3>
                <p className="text-sm leading-relaxed text-muted-foreground">{step.body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section id="menu" className="scroll-mt-4">
        <div className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
          <Deferred data="cafe" fallback={<MenuSkeleton />}>
            <MenuSection cafe={cafe ?? null} orderHref={orderHref} />
          </Deferred>
        </div>
      </section>

      <section id="lokasi" className="scroll-mt-4 bg-cream">
        <div className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
          <div className="grid items-center gap-8 rounded-[2rem] bg-neutral-950 p-8 text-white sm:p-12 lg:grid-cols-[1fr_auto]">
            <div className="flex flex-col gap-3">
              <p className="text-sm font-medium text-brand">Kunjungi kami</p>
              <Deferred data="cafe" fallback={<Skeleton className="h-10 w-64 bg-white/10" />}>
                <Visit cafe={cafe ?? null} />
              </Deferred>
            </div>
            <Link href={orderHref} className={cn(buttonVariants({ size: "lg" }), CTA, "h-12 w-fit px-6 text-base")}>
              Pesan sekarang <ArrowRightIcon />
            </Link>
          </div>
        </div>
      </section>

      <footer className="border-t">
        <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-8 text-sm text-muted-foreground sm:px-6">
          <Brand className="text-lg" />
          <p>© {new Date().getFullYear()} Sudaya Company</p>
          <Link href="/login" className="rounded-full px-3 py-2 transition-colors hover:text-foreground">
            Masuk staf
          </Link>
        </div>
      </footer>
    </div>
  );
}

function SectionTitle({ eyebrow, title, children }: { eyebrow: string; title: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex flex-col gap-2">
        {/* Oranye hanya sebagai garis: teks oranye di latar terang tidak memenuhi kontras 4,5:1. */}
        <p className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
          <span className="h-0.5 w-6 rounded-full bg-brand" aria-hidden />
          {eyebrow}
        </p>
        <h2 className="font-display text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{title}</h2>
      </div>
      {children}
    </div>
  );
}

/** Foto menu asli bila ada; tanpa foto, ilustrasi cangkir sederhana. */
function HeroGallery({ items }: { items: Featured[] | null }) {
  if (items === null) {
    return <Skeleton className="aspect-square w-full max-w-md justify-self-center rounded-[2rem] bg-white/5 lg:justify-self-end" />;
  }
  const photos = items.filter((p) => p.imageUrl).slice(0, 3);
  if (photos.length === 0) {
    return (
      <div className="relative hidden aspect-square w-full max-w-md justify-self-center sm:block lg:justify-self-end" aria-hidden>
        <div className="absolute inset-6 rounded-full border border-white/10" />
        <div className="absolute inset-16 rounded-full border border-white/10" />
        <div className="absolute inset-0 flex items-center justify-center">
          <span className="flex size-40 items-center justify-center rounded-full bg-gradient-to-br from-brand to-brand/60 shadow-2xl shadow-brand/30">
            <CoffeeIcon className="size-20 text-neutral-950" strokeWidth={1.5} />
          </span>
        </div>
      </div>
    );
  }
  return (
    <div className="grid w-full max-w-md grid-cols-2 gap-3 justify-self-center lg:justify-self-end">
      {photos.map((photo, index) => (
        <figure
          key={photo.id}
          className={cn(
            "relative overflow-hidden rounded-[1.75rem] bg-white/5",
            photos.length === 1 ? "col-span-2 aspect-[4/3]" : index === 0 ? "row-span-2 aspect-[3/5]" : "aspect-square",
            photos.length === 2 && index === 1 && "row-span-2 aspect-[3/5]",
          )}
        >
          <img src={photo.imageUrl!} alt={photo.name} className="size-full object-cover" />
          <figcaption className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/75 to-transparent p-3 pt-8 text-sm font-medium">{photo.name}</figcaption>
        </figure>
      ))}
    </div>
  );
}

function MenuSection({ cafe, orderHref }: { cafe: Cafe; orderHref: string }) {
  if (!cafe || cafe.featured.length === 0) {
    return (
      <div className="flex flex-col items-center gap-4 rounded-3xl border border-dashed p-10 text-center">
        <CoffeeIcon className="size-8 text-muted-foreground" aria-hidden />
        <p className="max-w-md text-muted-foreground">Menu tampil di halaman pesan setiap outlet. Scan QR di meja untuk mulai memesan.</p>
      </div>
    );
  }
  return (
    <>
      <SectionTitle eyebrow="Menu" title="Pilihan dari menu kami">
        <Link href={orderHref} className={cn(buttonVariants({ variant: "outline" }), "h-10 w-fit rounded-full px-4")}>
          Lihat semua {cafe.productCount} menu <ArrowRightIcon />
        </Link>
      </SectionTitle>
      {cafe.categories.length ? (
        <ul className="mt-6 flex flex-wrap gap-2" aria-label="Kategori menu">
          {cafe.categories.map((name) => (
            <li key={name} className="rounded-full border px-3 py-1 text-sm text-muted-foreground">
              {name}
            </li>
          ))}
        </ul>
      ) : null}
      <ul className="mt-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
        {cafe.featured.map((product) => (
          <li key={product.id}>
            <Link
              href={orderHref}
              className="group flex h-full flex-col overflow-hidden rounded-3xl border bg-card transition-shadow duration-200 hover:shadow-lg focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
            >
              <div className="relative aspect-[4/3] overflow-hidden bg-cream">
                {product.imageUrl ? (
                  <img
                    src={product.imageUrl}
                    alt=""
                    loading="lazy"
                    className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                  />
                ) : (
                  <CoffeeIcon className="absolute inset-0 m-auto size-10 text-muted-foreground/40" aria-hidden />
                )}
              </div>
              <div className="flex flex-1 flex-col gap-1 p-4">
                {product.category ? <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{product.category}</span> : null}
                <span className="font-display text-lg leading-snug font-semibold">{product.name}</span>
                {product.description ? <span className="line-clamp-2 text-sm text-muted-foreground">{product.description}</span> : null}
                <span className="mt-auto pt-2 font-semibold tabular-nums">{formatRupiah(product.price)}</span>
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </>
  );
}

function Visit({ cafe }: { cafe: Cafe }) {
  return (
    <>
      <h2 className="font-display text-3xl font-semibold tracking-tight sm:text-4xl">{cafe?.outlet.name ?? "Sudaya Coffee"}</h2>
      {cafe?.outlet.address ? (
        <p className="flex items-start gap-2 text-neutral-300">
          <MapPinIcon className="mt-1 size-4 shrink-0 text-brand" aria-hidden />
          {cafe.outlet.address}
        </p>
      ) : (
        <p className="text-neutral-300">Scan QR di meja outlet untuk langsung memesan.</p>
      )}
    </>
  );
}

function MenuSkeleton() {
  return (
    <div className="flex flex-col gap-8">
      <Skeleton className="h-10 w-72" />
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
        {Array.from({ length: 6 }, (_, i) => (
          <Skeleton key={i} className="aspect-[4/5] rounded-3xl" />
        ))}
      </div>
    </div>
  );
}
