import { Deferred, Head, Link } from "@inertiajs/react";
import {
  ArrowRightIcon,
  ArrowUpRightIcon,
  BanknoteIcon,
  BellRingIcon,
  CalendarDaysIcon,
  CoffeeIcon,
  GraduationCapIcon,
  HeartHandshakeIcon,
  MailIcon,
  MapPinIcon,
  MessageCircleIcon,
  QrCodeIcon,
  ShoppingBagIcon,
  SproutIcon,
} from "lucide-react";
import type { ReactNode } from "react";

import { Brand } from "@/components/brand";
import { buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { formatMenuPrice, hasMenuPrice } from "@/lib/format";
import { cn } from "@/lib/utils";

type Featured = { id: string; name: string; description: string | null; price: number; imageUrl: string | null; category: string | null };
type Cafe = {
  outlet: { code: string; name: string; address: string | null };
  categories: string[];
  featured: Featured[];
  productCount: number;
} | null;
type Training = { title: string; schedule: string | null; location: string | null; description: string | null; url: string | null };
type Site = {
  about: string;
  programs: { title: string; body: string }[];
  donationUrl: string | null;
  whatsapp: string | null;
  instagram: string | null;
  email: string | null;
  trainings: Training[];
  links: { whatsapp: string | null; instagram: string | null; email: string | null };
} | null;

const STEPS = [
  { icon: QrCodeIcon, title: "Scan QR atau buka menu", body: "QR ada di meja. Bisa juga langsung dari HP lewat halaman menu." },
  { icon: ShoppingBagIcon, title: "Pilih & kirim pesanan", body: "Atur ukuran, gula, dan es. Total dihitung otomatis." },
  { icon: BanknoteIcon, title: "Bayar QRIS atau tunai", body: "QRIS dari HP, tunai di kasir, atau tunai ke barista saat pesanan diantar ke meja." },
  { icon: BellRingIcon, title: "Pesanan siap", body: "Status pesanan tampil di HP-mu dan berubah sendiri saat pesanan siap." },
];

const PROGRAM_ICONS = [GraduationCapIcon, SproutIcon];
const CTA = "rounded-full bg-brand text-brand-foreground hover:bg-brand/90";
const EXTERNAL = { target: "_blank", rel: "noopener noreferrer" } as const;

/** Halaman depan Sudaya Coffee: publik tanpa login. Konten program/kontak diatur di Admin → Konten situs. */
export default function Home({ cafe, site }: { cafe?: Cafe; site?: Site }) {
  const orderHref = cafe ? `/order?outlet=${cafe.outlet.code}` : "/order";

  return (
    <div className="flex min-h-svh flex-1 flex-col bg-background">
      <Head title="Kopi yang tumbuh bersama petani" />

      <header className="relative isolate overflow-hidden bg-espresso text-white">
        <div className="pointer-events-none absolute -top-32 -right-24 -z-10 size-[28rem] rounded-full bg-brand/30 blur-3xl" aria-hidden />
        <div className="pointer-events-none absolute -bottom-40 -left-24 -z-10 size-[24rem] rounded-full bg-leaf/25 blur-3xl" aria-hidden />
        <nav aria-label="Utama" className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
          <Link href="/" className="rounded-md text-xl focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none">
            <Brand tone="light" />
          </Link>
          <div className="flex items-center gap-1 text-sm">
            {[
              ["#tentang", "Tentang"],
              ["#program", "Program"],
              ["#pelatihan", "Pelatihan"],
              ["#menu", "Menu"],
              ["#kontak", "Kontak"],
            ].map(([href, label]) => (
              <a key={href} href={href} className="hidden rounded-full px-3 py-2 text-white/75 transition-colors hover:text-white lg:inline">
                {label}
              </a>
            ))}
            <Link href={orderHref} className={cn(buttonVariants({ size: "sm" }), CTA, "ml-2 h-10 px-4")}>
              Pesan
            </Link>
          </div>
        </nav>

        <div className="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 pt-8 pb-20 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:pt-16 lg:pb-28">
          <div className="flex min-w-0 flex-col gap-6">
            <p className="inline-flex w-fit items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-white/80">
              <SproutIcon className="size-3.5 text-leaf" aria-hidden /> Kopi · Pelatihan · Pemberdayaan petani
            </p>
            <h1 className="font-display text-[2.75rem] leading-[1.05] font-semibold tracking-tight text-balance sm:text-6xl lg:text-7xl">
              Kopi enak, <span className="text-brand italic">tumbuh bersama</span> petani
            </h1>
            <p className="max-w-xl text-lg leading-relaxed text-white/80">
              Pesan dari meja lewat QR atau langsung dari HP. Setiap cangkir ikut mendukung program pelatihan kopi dan pemberdayaan petani kopi Sudaya.
            </p>
            <div className="flex flex-wrap gap-3">
              <Link href={orderHref} className={cn(buttonVariants({ size: "lg" }), CTA, "h-12 px-6 text-base")}>
                Lihat menu & pesan <ArrowRightIcon />
              </Link>
              <a
                href="#sedekah"
                className={cn(
                  buttonVariants({ size: "lg", variant: "outline" }),
                  "h-12 rounded-full border-leaf/60 bg-transparent px-6 text-base text-white hover:bg-white/10 hover:text-white dark:bg-transparent",
                )}
              >
                <HeartHandshakeIcon className="text-leaf" /> Ikut berbagi
              </a>
            </div>
          </div>
          <Deferred data="cafe" fallback={<HeroGallery items={null} />}>
            <HeroGallery items={cafe?.featured ?? []} />
          </Deferred>
        </div>
      </header>

      <Deferred data="site" fallback={<SiteSkeleton />}>
        <SiteSections site={site ?? null} />
      </Deferred>

      <section id="cara-pesan" className="scroll-mt-4 bg-cream">
        <div className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
          <SectionTitle eyebrow="Cara pesan" title="Empat langkah, tanpa ribet" />
          <ol className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {STEPS.map((step, index) => (
              <li key={step.title} className="flex flex-col gap-3 rounded-3xl border bg-background p-6">
                <div className="flex items-center justify-between">
                  <span className={cn("flex size-12 items-center justify-center rounded-2xl", index % 2 === 0 ? "bg-brand text-brand-foreground" : "bg-leaf text-leaf-foreground")}>
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
          <div className="grid items-center gap-8 rounded-[2rem] bg-espresso p-8 text-white sm:p-12 lg:grid-cols-[1fr_auto]">
            <div className="flex flex-col gap-3">
              <p className="text-sm font-medium text-leaf">Kunjungi kami</p>
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

      <Deferred data="site" fallback={<FooterShell site={null} />}>
        <FooterShell site={site ?? null} />
      </Deferred>
    </div>
  );
}

function SectionTitle({ eyebrow, title, children, tone = "brand" }: { eyebrow: string; title: string; children?: ReactNode; tone?: "brand" | "leaf" }) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex flex-col gap-2">
        {/* Oranye/hijau hanya sebagai garis: teks berwarna di latar terang tidak memenuhi kontras 4,5:1. */}
        <p className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
          <span className={cn("h-0.5 w-6 rounded-full", tone === "leaf" ? "bg-leaf" : "bg-brand")} aria-hidden />
          {eyebrow}
        </p>
        <h2 className="font-display text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{title}</h2>
      </div>
      {children}
    </div>
  );
}

/** Tentang, program, info pelatihan, dan ajakan sedekah. Teks dari Admin → Konten situs. */
function SiteSections({ site }: { site: Site }) {
  if (!site) return null;
  return (
    <>
      <section id="tentang" className="scroll-mt-4">
        <div className="mx-auto grid w-full max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-20">
          <div className="flex flex-col gap-5">
            <SectionTitle eyebrow="Tentang Sudaya Coffee" title="Kopi yang tumbuh bersama petani" tone="leaf" />
            {site.about.split(/\n+/).map((paragraph, index) => (
              <p key={index} className="text-lg leading-relaxed text-muted-foreground">
                {paragraph}
              </p>
            ))}
          </div>
          <figure className="overflow-hidden rounded-[2rem] border bg-cream">
            <picture>
              <source type="image/webp" srcSet="/images/program-petani-kopi-900.webp 900w, /images/program-petani-kopi.webp 1600w" sizes="(min-width: 1024px) 560px, 100vw" />
              <img
                src="/images/program-petani-kopi-900.jpg"
                srcSet="/images/program-petani-kopi-900.jpg 900w, /images/program-petani-kopi.jpg 1600w"
                sizes="(min-width: 1024px) 560px, 100vw"
                width={900}
                height={600}
                loading="lazy"
                alt="Kegiatan pemberdayaan petani kopi"
                className="aspect-[3/2] w-full object-cover"
              />
            </picture>
            <figcaption className="px-5 py-3 text-sm text-muted-foreground">Kegiatan pemberdayaan petani kopi</figcaption>
          </figure>
        </div>
      </section>

      <section id="program" className="scroll-mt-4 bg-leaf-soft">
        <div className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
          <SectionTitle eyebrow="Program Sudaya" title="Lebih dari secangkir kopi" tone="leaf" />
          <ul className="mt-10 grid gap-4 md:grid-cols-2">
            {site.programs.map((program, index) => {
              const Icon = PROGRAM_ICONS[index] ?? SproutIcon;
              return (
                <li key={program.title} className="flex flex-col gap-4 rounded-3xl border bg-background p-6 sm:p-8">
                  <span className={cn("flex size-14 items-center justify-center rounded-2xl", index === 0 ? "bg-brand text-brand-foreground" : "bg-leaf text-leaf-foreground")}>
                    <Icon className="size-6" aria-hidden />
                  </span>
                  <h3 className="font-display text-2xl font-semibold">{program.title}</h3>
                  <p className="leading-relaxed text-muted-foreground">{program.body}</p>
                </li>
              );
            })}
          </ul>
        </div>
      </section>

      <section id="pelatihan" className="scroll-mt-4">
        <div className="mx-auto w-full max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
          <SectionTitle eyebrow="Info pelatihan" title="Belajar kopi bersama Sudaya" />
          {site.trainings.length ? (
            <ul className="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-3" data-testid="trainings">
              {site.trainings.map((training, index) => (
                <li key={`${training.title}-${index}`} className="flex flex-col gap-3 rounded-3xl border bg-card p-6">
                  <h3 className="text-lg leading-snug font-semibold">{training.title}</h3>
                  {training.schedule ? (
                    <p className="flex items-start gap-2 text-sm">
                      <CalendarDaysIcon className="mt-0.5 size-4 shrink-0 text-brand-strong" aria-hidden /> {training.schedule}
                    </p>
                  ) : null}
                  {training.location ? (
                    <p className="flex items-start gap-2 text-sm">
                      <MapPinIcon className="mt-0.5 size-4 shrink-0 text-leaf-strong" aria-hidden /> {training.location}
                    </p>
                  ) : null}
                  {training.description ? <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">{training.description}</p> : null}
                  {training.url ? (
                    <a href={training.url} {...EXTERNAL} className={cn(buttonVariants(), CTA, "mt-auto h-11 w-fit px-5")}>
                      Daftar <ArrowUpRightIcon />
                    </a>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <div className="mt-8 flex flex-col items-start gap-3 rounded-3xl border border-dashed p-8">
              <GraduationCapIcon className="size-8 text-muted-foreground" aria-hidden />
              <p className="max-w-xl text-muted-foreground">Jadwal pelatihan berikutnya segera diumumkan di halaman ini.</p>
              {site.links.whatsapp ? (
                <a href={site.links.whatsapp} {...EXTERNAL} className={cn(buttonVariants({ variant: "outline" }), "h-11 rounded-full px-5")}>
                  <MessageCircleIcon /> Tanya jadwal lewat WhatsApp
                </a>
              ) : null}
            </div>
          )}
        </div>
      </section>

      {site.donationUrl ? (
        <section id="sedekah" className="scroll-mt-4 px-4 pb-16 sm:px-6 lg:pb-20">
          <div className="mx-auto grid w-full max-w-6xl items-center gap-6 rounded-[2rem] bg-espresso p-8 text-white sm:p-12 lg:grid-cols-[1fr_auto]">
            <div className="flex flex-col gap-3">
              <p className="flex items-center gap-2 text-sm font-medium text-leaf">
                <HeartHandshakeIcon className="size-4" aria-hidden /> Sedekah untuk petani kopi
              </p>
              <h2 className="font-display text-3xl font-semibold tracking-tight text-balance sm:text-4xl">Ikut berbagi untuk petani kopi</h2>
              <p className="max-w-2xl text-white/80">Sedekah Anda disalurkan langsung lewat Sahabat Berbagi. Anda akan diarahkan ke situs Sahabat Berbagi untuk berdonasi.</p>
            </div>
            <a href={site.donationUrl} {...EXTERNAL} className={cn(buttonVariants({ size: "lg" }), CTA, "h-12 w-fit px-6 text-base")} data-testid="donation-link">
              Sedekah lewat Sahabat Berbagi <ArrowUpRightIcon />
            </a>
          </div>
        </section>
      ) : null}
    </>
  );
}

function SiteSkeleton() {
  return (
    <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-16 sm:px-6">
      <Skeleton className="h-10 w-72" />
      <Skeleton className="h-28 w-full max-w-2xl" />
      <div className="grid gap-4 md:grid-cols-2">
        <Skeleton className="h-48 rounded-3xl" />
        <Skeleton className="h-48 rounded-3xl" />
      </div>
    </div>
  );
}

function InstagramGlyph({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden>
      <rect x="3" y="3" width="18" height="18" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.5" cy="6.5" r="0.75" fill="currentColor" stroke="none" />
    </svg>
  );
}

function FooterShell({ site }: { site: Site }) {
  const contacts = site
    ? [
        site.links.whatsapp ? { href: site.links.whatsapp, label: `WhatsApp ${formatPhone(site.whatsapp)}`, icon: <MessageCircleIcon className="size-4" aria-hidden />, external: true } : null,
        site.links.instagram ? { href: site.links.instagram, label: `Instagram @${site.instagram}`, icon: <InstagramGlyph className="size-4" />, external: true } : null,
        site.links.email ? { href: site.links.email, label: site.email ?? "", icon: <MailIcon className="size-4" aria-hidden />, external: false } : null,
      ].filter((c) => c !== null)
    : [];

  return (
    <footer id="kontak" className="scroll-mt-4 border-t bg-cream">
      <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr]">
        <div className="flex flex-col gap-4">
          <Brand className="text-2xl" />
          <p className="max-w-sm text-sm leading-relaxed text-muted-foreground">Kedai kopi dengan program pelatihan kopi dan pemberdayaan petani kopi.</p>
        </div>
        <div className="flex flex-col gap-3">
          <h2 className="text-sm font-semibold">Kontak</h2>
          {contacts.length ? (
            <ul className="flex flex-col gap-1 text-sm">
              {contacts.map((contact) => (
                <li key={contact.href}>
                  <a
                    href={contact.href}
                    {...(contact.external ? EXTERNAL : {})}
                    className="inline-flex min-h-11 min-w-11 items-center gap-2 rounded-md break-all text-muted-foreground transition-colors hover:text-foreground"
                  >
                    {contact.icon}
                    {contact.label}
                  </a>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">Kontak segera diumumkan. Kunjungi outlet kami untuk informasi.</p>
          )}
        </div>
        <div className="flex flex-col gap-3">
          <h2 className="text-sm font-semibold">Jelajahi</h2>
          <ul className="flex flex-col text-sm">
            {[
              ["#program", "Program"],
              ["#pelatihan", "Info pelatihan"],
              ["#menu", "Menu"],
              ...(site?.donationUrl ? [["#sedekah", "Sedekah"]] : []),
            ].map(([href, label]) => (
              <li key={href}>
                <a href={href} className="inline-flex min-h-11 min-w-11 items-center text-muted-foreground transition-colors hover:text-foreground">
                  {label}
                </a>
              </li>
            ))}
            <li>
              <Link href="/login" className="inline-flex min-h-11 min-w-11 items-center text-muted-foreground transition-colors hover:text-foreground">
                Masuk staf
              </Link>
            </li>
          </ul>
        </div>
      </div>
      <div className="border-t">
        <p className="mx-auto w-full max-w-6xl px-4 py-5 text-xs text-muted-foreground sm:px-6">© {new Date().getFullYear()} Sudaya Coffee</p>
      </div>
    </footer>
  );
}

/** 6281234567890 → 0812-3456-7890 untuk dibaca manusia. */
function formatPhone(international: string | null) {
  if (!international) return "";
  const local = international.startsWith("62") ? `0${international.slice(2)}` : international;
  return local.replace(/^(\d{4})(\d{4})(\d+)$/, "$1-$2-$3");
}

/** Foto menu asli bila ada; tanpa foto, ilustrasi logo Sudaya. */
function HeroGallery({ items }: { items: Featured[] | null }) {
  if (items === null) {
    return <Skeleton className="aspect-square w-full max-w-md justify-self-center rounded-[2rem] bg-white/5 lg:justify-self-end" />;
  }
  const photos = items.filter((p) => p.imageUrl).slice(0, 3);
  if (photos.length === 0) {
    return (
      <div className="relative hidden aspect-square w-full max-w-md justify-self-center sm:block lg:justify-self-end" aria-hidden>
        <div className="absolute inset-6 rounded-full border border-white/10" />
        <div className="absolute inset-16 rounded-full border border-leaf/20" />
        <div className="absolute inset-0 flex items-center justify-center">
          <span className="flex size-56 items-center justify-center rounded-full bg-white shadow-2xl shadow-brand/30">
            <img src="/images/logo-mark.png" alt="" width={160} height={158} className="w-36" />
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
        <Link href={orderHref} className={cn(buttonVariants({ variant: "outline" }), "h-11 w-fit rounded-full px-4")}>
          Lihat semua {cafe.productCount} menu <ArrowRightIcon />
        </Link>
      </SectionTitle>
      {cafe.categories.length ? (
        <ul className="mt-6 flex flex-wrap gap-2" aria-label="Kategori menu">
          {cafe.categories.map((name) => (
            <li key={name} className="rounded-full border border-leaf/40 bg-leaf-soft px-3 py-1 text-sm text-leaf-strong">
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
                <span className={cn("mt-auto pt-2 font-semibold tabular-nums", !hasMenuPrice(product.price) && "font-medium text-muted-foreground")}>
                  {formatMenuPrice(product.price)}
                </span>
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
        <p className="flex items-start gap-2 text-white/80">
          <MapPinIcon className="mt-1 size-4 shrink-0 text-brand" aria-hidden />
          {cafe.outlet.address}
        </p>
      ) : (
        <p className="text-white/80">Scan QR di meja outlet untuk langsung memesan.</p>
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
