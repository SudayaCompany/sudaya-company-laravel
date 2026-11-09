import { Deferred, Head, Link, router, usePage, usePoll } from "@inertiajs/react";
import {
  ArrowRightIcon,
  BadgeCheckIcon,
  CalendarCheckIcon,
  ChefHatIcon,
  CircleCheckIcon,
  ClipboardCheckIcon,
  CoffeeIcon,
  HourglassIcon,
  PackageXIcon,
  RefreshCwIcon,
  StoreIcon,
  TriangleAlertIcon,
  WalletIcon,
  type LucideIcon,
} from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah, scopeLabel } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

type Auth = NonNullable<SharedProps["auth"]>;
type OutletOption = { id: string; name: string; brandName: string; code: string };
type ActiveOutlet = { id: string; name: string; timezone: string };

type Summary = {
  businessDate: string;
  generatedAt: string;
  sales: {
    revenue: number;
    transactions: number;
    average: number;
    itemsSold: number;
    topProducts: { name: string; quantity: number; amount: number }[];
  } | null;
  orders: { pendingPayment: number; inQueue: number; completedToday: number } | null;
  cash: {
    open: { openedAt: string; openedByName: string | null; expected: number } | null;
    lastClosed: { closedAt: string | null; expected: number; actual: number; variance: number } | null;
  } | null;
  pendingApprovals: number | null;
  soldOut: number | null;
  pendingCorrections: number | null;
  attendance: { employees: number; working: number; done: number; absent: number } | null;
};

type Props = {
  outlets: OutletOption[];
  outlet: ActiveOutlet | null;
  grants: { userRoleId: string; roleName: string; outletId: string | null }[];
  /** Dimuat setelah halaman tampil (deferred) dan diperbarui berkala. */
  summary?: Summary | null;
};

/** Pembaruan otomatis angka dasbor; berhenti saat tab tidak terlihat. */
const POLL_MS = 60_000;

/** Intl dengan zona waktu outlet; zona tidak valid jatuh ke zona perangkat. */
function dateFormat(options: Intl.DateTimeFormatOptions, timeZone?: string) {
  try {
    return new Intl.DateTimeFormat("id-ID", { ...options, timeZone });
  } catch {
    return new Intl.DateTimeFormat("id-ID", options);
  }
}

const count = (n: number) => n.toLocaleString("id-ID");

export default function Dashboard({ outlets, outlet, grants, summary }: Props) {
  const auth = usePage<SharedProps>().props.auth!;
  const outletNames = new Map(outlets.map((o) => [o.id, o.name]));
  usePoll(POLL_MS, { only: ["summary"] }, { background: "pause", autoStart: outlet !== null });

  return (
    <AdminLayout>
      <Head title="Dasbor" />
      <PageHeader title={`Halo, ${auth.user.fullName}`} description={outlet ? `${outlet.name} · ${auth.user.company}` : auth.user.company}>
        {outlet && outlets.length > 1 ? (
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="dashboard-outlet">Outlet</Label>
            <NativeSelect
              id="dashboard-outlet"
              value={outlet.id}
              className="w-full sm:w-64"
              onChange={(e) => router.get("/admin", { outlet: e.target.value }, { preserveScroll: true })}
            >
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name} · {o.brandName}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          </div>
        ) : null}
      </PageHeader>

      {outlet === null ? (
        <EmptyState
          icon={StoreIcon}
          title="Belum ada outlet untuk dasbor"
          description="Akun ini belum punya akses dasbor di outlet mana pun. Minta Super Admin menambahkan peran untuk outlet Anda."
        />
      ) : (
        <Deferred data="summary" fallback={<SummarySkeleton />}>
          {({ reloading }) =>
            summary ? <SummaryView summary={summary} outlet={outlet} auth={auth} reloading={reloading} /> : <SummarySkeleton />
          }
        </Deferred>
      )}

      <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
        <span>Peran Anda:</span>
        {grants.map((grant) => (
          <Badge key={grant.userRoleId} variant="secondary">
            {grant.roleName} · {scopeLabel(grant.outletId, outletNames)}
          </Badge>
        ))}
      </p>
    </AdminLayout>
  );
}

function SummaryView({ summary, outlet, auth, reloading }: { summary: Summary; outlet: ActiveOutlet; auth: Auth; reloading: boolean }) {
  const { sales, orders, cash } = summary;
  const time = dateFormat({ timeStyle: "short" }, outlet.timezone);
  const stamp = dateFormat({ day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }, outlet.timezone);
  // Tanggal operasional dari server (YYYY-MM-DD); diformat apa adanya tanpa geser zona.
  const businessDay = dateFormat({ weekday: "long", day: "numeric", month: "long", year: "numeric" }, "UTC").format(new Date(`${summary.businessDate}T00:00:00Z`));

  // Tautan mengikuti area yang bisa dibuka user: kasir (/pos) atau area admin.
  const has = (href: string) => auth.navigation.includes(href);
  const ordersHref = has("/pos") ? `/pos/orders?outlet=${outlet.id}` : has("/admin/orders") ? `/admin/orders?outlet=${outlet.id}` : null;
  const cashHref = has("/pos") ? `/pos/cash?outlet=${outlet.id}` : has("/admin/cash") ? `/admin/cash?outlet=${outlet.id}` : null;
  const approvalsHref = has("/admin/approvals") ? `/admin/approvals?outlet=${outlet.id}` : null;
  const productsHref = has("/admin/products") ? `/admin/products?outlet=${outlet.id}&availability=sold_out` : null;
  const attendanceHref = has("/admin/attendance") ? `/admin/attendance?outlet=${outlet.id}` : null;
  const { attendance } = summary;

  const nothing = sales === null && orders === null && cash === null && attendance === null && summary.pendingApprovals === null && summary.soldOut === null;

  return (
    <div className="flex flex-col gap-6" aria-busy={reloading}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Tanggal operasional <span className="font-medium text-foreground">{businessDay}</span>
        </p>
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span aria-live="polite">Diperbarui {time.format(new Date(summary.generatedAt))}</span>
          <Button
            variant="outline"
            size="sm"
            disabled={reloading}
            onClick={() => router.reload({ only: ["summary"] })}
            aria-label="Perbarui angka dasbor"
          >
            <RefreshCwIcon className={cn(reloading && "animate-spin motion-reduce:animate-none")} aria-hidden /> Perbarui
          </Button>
        </div>
      </div>

      {nothing ? (
        <EmptyState icon={ClipboardCheckIcon} title="Belum ada ringkasan untuk peran Anda" description="Menu di samping menampilkan halaman yang bisa Anda buka." />
      ) : null}

      {sales ? (
        <section aria-labelledby="sales-title" className="flex flex-col gap-3">
          <h2 id="sales-title" className="text-base font-semibold">
            Penjualan hari ini
          </h2>
          {/* HP & tablet: omzet selebar layar, angka lain dua kolom; ≥ 1280 px: satu baris. */}
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {/* Angka utama dasbor: omzet. */}
            <div className="col-span-2 flex flex-col justify-between gap-2 rounded-xl bg-espresso p-5 text-espresso-foreground xl:col-span-1">
              <p className="text-sm text-espresso-foreground/80">Omzet bersih</p>
              <p className="text-4xl font-bold tracking-tight sm:text-5xl xl:text-4xl" data-testid="revenue">
                {formatRupiah(sales.revenue)}
              </p>
              <p className="text-xs text-espresso-foreground/70">Pesanan lunas, tanpa yang dibatalkan atau direfund</p>
            </div>
            <StatTile label="Transaksi" value={count(sales.transactions)} hint="Pesanan lunas hari ini" />
            <StatTile label="Rata-rata per transaksi" value={formatRupiah(sales.average)} hint="Omzet dibagi jumlah transaksi" />
            <StatTile label="Item terjual" value={count(sales.itemsSold)} hint="Semua item di pesanan lunas" className="col-span-2 xl:col-span-1" />
          </div>
        </section>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-5">
        {sales ? <TopProducts items={sales.topProducts} className="lg:col-span-3" /> : null}
        <NeedsAction
          className={sales ? "lg:col-span-2" : "lg:col-span-5"}
          pendingApprovals={summary.pendingApprovals}
          pendingCorrections={summary.pendingCorrections}
          pendingPayment={orders?.pendingPayment ?? null}
          soldOut={summary.soldOut}
          cashOpen={cash ? cash.open !== null : null}
          links={{ approvals: approvalsHref, orders: ordersHref, products: productsHref, cash: cashHref }}
        />
      </div>

      {orders || cash || attendance ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {orders ? (
            <Card>
              <CardHeader>
                <CardTitle>Pesanan</CardTitle>
                <CardDescription>Status antrean saat ini</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <dl className="grid grid-cols-3 gap-3">
                  <MiniStat icon={HourglassIcon} label="Menunggu bayar" value={orders.pendingPayment} />
                  <MiniStat icon={ChefHatIcon} label="Dalam antrean" value={orders.inQueue} />
                  <MiniStat icon={CircleCheckIcon} label="Selesai hari ini" value={orders.completedToday} />
                </dl>
                {ordersHref ? <CardLink href={ordersHref}>Lihat pesanan</CardLink> : null}
              </CardContent>
            </Card>
          ) : null}
          {cash ? (
            <Card>
              <CardHeader>
                <CardTitle>Kas</CardTitle>
                <CardDescription>{cash.open ? "Sesi kas sedang terbuka" : "Tidak ada sesi kas terbuka"}</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-4 text-sm">
                {cash.open ? (
                  <div className="flex flex-col gap-1">
                    <p className="text-muted-foreground">Saldo sistem (uang yang seharusnya ada di laci)</p>
                    <p className="text-2xl font-semibold" data-testid="cash-expected">
                      {formatRupiah(cash.open.expected)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      Dibuka {stamp.format(new Date(cash.open.openedAt))}
                      {cash.open.openedByName ? ` oleh ${cash.open.openedByName}` : ""}
                    </p>
                  </div>
                ) : (
                  <p className="text-muted-foreground">Kasir membuka sesi kas sebelum menerima pembayaran tunai.</p>
                )}
                {cash.lastClosed ? <LastClosed closed={cash.lastClosed} stamp={stamp} /> : null}
                {cashHref ? <CardLink href={cashHref}>Lihat kas</CardLink> : null}
              </CardContent>
            </Card>
          ) : null}
          {attendance ? (
            <Card>
              <CardHeader>
                <CardTitle>Kehadiran hari ini</CardTitle>
                <CardDescription>{attendance.employees} karyawan aktif</CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <dl className="grid grid-cols-3 gap-3">
                  <MiniStat icon={CalendarCheckIcon} label="Bekerja" value={attendance.working} />
                  <MiniStat icon={CircleCheckIcon} label="Sudah pulang" value={attendance.done} />
                  <MiniStat icon={HourglassIcon} label="Belum absen" value={attendance.absent} />
                </dl>
                {attendanceHref ? <CardLink href={attendanceHref}>Lihat kehadiran</CardLink> : null}
              </CardContent>
            </Card>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function StatTile({ label, value, hint, className }: { label: string; value: string; hint: string; className?: string }) {
  return (
    <div className={cn("flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5", className)}>
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="text-xl font-semibold tracking-tight sm:text-2xl">{value}</p>
      <p className="text-xs text-muted-foreground">{hint}</p>
    </div>
  );
}

function MiniStat({ icon: Icon, label, value }: { icon: LucideIcon; label: string; value: number }) {
  return (
    <div className="flex flex-col gap-1 rounded-lg bg-muted/60 p-3">
      <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
        <Icon className="size-3.5 shrink-0" aria-hidden /> {label}
      </dt>
      <dd className="text-2xl font-semibold">{count(value)}</dd>
    </div>
  );
}

function CardLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link
      href={href}
      className="inline-flex min-h-9 w-fit items-center gap-1 rounded-md text-sm font-medium text-brand-strong underline-offset-4 hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none pointer-coarse:min-h-11"
    >
      {children} <ArrowRightIcon className="size-4" aria-hidden />
    </Link>
  );
}

/** Menu terlaris: batang horizontal satu warna, terbesar dulu; angkanya tetap tertulis. */
function TopProducts({ items, className }: { items: { name: string; quantity: number; amount: number }[]; className?: string }) {
  const max = Math.max(1, ...items.map((i) => i.quantity));
  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle>Menu terlaris hari ini</CardTitle>
        <CardDescription>Berdasarkan jumlah terjual di pesanan lunas</CardDescription>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <EmptyState icon={CoffeeIcon} title="Belum ada penjualan hari ini" description="Menu terlaris tampil setelah ada pesanan yang lunas." />
        ) : (
          <ol className="flex flex-col gap-3.5">
            {items.map((item, index) => (
              <li key={item.name} className="flex flex-col gap-1.5">
                <div className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="min-w-0 truncate font-medium">
                    <span className="mr-2 text-muted-foreground">{index + 1}.</span>
                    {item.name}
                  </span>
                  <span className="shrink-0 text-muted-foreground tabular-nums">
                    {count(item.quantity)} terjual · {formatRupiah(item.amount)}
                  </span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-brand-soft" aria-hidden>
                  <div className="h-full rounded-full bg-brand-strong" style={{ width: `${Math.max(4, (item.quantity / max) * 100)}%` }} />
                </div>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

type ActionLinks = { approvals: string | null; orders: string | null; products: string | null; cash: string | null };

/** Hal yang perlu ditindaklanjuti; status selalu dengan ikon + teks, tidak hanya warna. */
function NeedsAction({
  pendingApprovals,
  pendingCorrections,
  pendingPayment,
  soldOut,
  cashOpen,
  links,
  className,
}: {
  pendingApprovals: number | null;
  pendingCorrections: number | null;
  pendingPayment: number | null;
  soldOut: number | null;
  cashOpen: boolean | null;
  links: ActionLinks;
  className?: string;
}) {
  const tracked = pendingApprovals !== null || pendingPayment !== null || soldOut !== null || cashOpen !== null;
  if (!tracked) return null;
  const items: { key: string; icon: LucideIcon; text: string; href: string | null; urgent: boolean }[] = [];
  if (pendingApprovals) items.push({ key: "approvals", icon: BadgeCheckIcon, text: `${count(pendingApprovals)} pembatalan menunggu persetujuan`, href: links.approvals, urgent: true });
  if (pendingCorrections) items.push({ key: "corrections", icon: CalendarCheckIcon, text: `${count(pendingCorrections)} koreksi absen menunggu persetujuan`, href: links.approvals, urgent: true });
  if (pendingPayment) items.push({ key: "payment", icon: HourglassIcon, text: `${count(pendingPayment)} pesanan menunggu pembayaran`, href: links.orders, urgent: false });
  if (soldOut) items.push({ key: "soldout", icon: PackageXIcon, text: `${count(soldOut)} menu ditandai habis`, href: links.products, urgent: false });
  if (cashOpen === false) items.push({ key: "cash", icon: WalletIcon, text: "Kas belum dibuka", href: links.cash, urgent: false });

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle>Perlu tindakan</CardTitle>
        <CardDescription>Diperbarui otomatis setiap menit</CardDescription>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <p className="flex items-center gap-2 rounded-lg bg-success/10 p-3 text-sm font-medium text-success-strong">
            <CircleCheckIcon className="size-4 shrink-0" aria-hidden /> Semua beres, tidak ada yang menunggu.
          </p>
        ) : (
          <ul className="flex flex-col gap-2" data-testid="needs-action">
            {items.map((item) => {
              const body = (
                <>
                  <item.icon className={cn("size-4 shrink-0", item.urgent ? "text-warning-strong" : "text-muted-foreground")} aria-hidden />
                  <span className="flex-1">{item.text}</span>
                  {item.href ? <ArrowRightIcon className="size-4 shrink-0 text-muted-foreground" aria-hidden /> : null}
                </>
              );
              const box = cn("flex min-h-11 items-center gap-2.5 rounded-lg border p-3 text-sm", item.urgent ? "border-warning/50 bg-warning/10 font-medium" : "bg-background");
              return (
                <li key={item.key}>
                  {item.href ? (
                    <Link href={item.href} className={cn(box, "transition-colors hover:border-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none")}>
                      {body}
                    </Link>
                  ) : (
                    <div className={box}>{body}</div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function LastClosed({ closed, stamp }: { closed: NonNullable<NonNullable<Summary["cash"]>["lastClosed"]>; stamp: Intl.DateTimeFormat }) {
  const match = closed.variance === 0;
  const label = match ? "Uang cocok" : closed.variance > 0 ? `Uang lebih ${formatRupiah(closed.variance)}` : `Uang kurang ${formatRupiah(-closed.variance)}`;
  return (
    <div className="flex flex-col gap-1.5 border-t pt-3">
      <p className="text-muted-foreground">Sesi terakhir ditutup{closed.closedAt ? ` ${stamp.format(new Date(closed.closedAt))}` : ""}</p>
      <p
        className={cn(
          "flex w-fit items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium",
          match ? "bg-success/10 text-success-strong" : "bg-destructive/10 text-destructive-strong",
        )}
        data-testid="last-variance"
      >
        {match ? <CircleCheckIcon className="size-4" aria-hidden /> : <TriangleAlertIcon className="size-4" aria-hidden />}
        {label}
      </p>
      <p className="text-xs text-muted-foreground">
        Saldo sistem {formatRupiah(closed.expected)} · Uang fisik {formatRupiah(closed.actual)}
      </p>
    </div>
  );
}

function SummarySkeleton() {
  return (
    <div className="flex flex-col gap-6" aria-busy aria-label="Memuat ringkasan">
      <Skeleton className="h-5 w-72 max-w-full" />
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <Skeleton className="col-span-2 h-36 rounded-xl xl:col-span-1" />
        <Skeleton className="h-36 rounded-xl" />
        <Skeleton className="h-36 rounded-xl" />
        <Skeleton className="col-span-2 h-36 rounded-xl xl:col-span-1" />
      </div>
      <div className="grid gap-4 lg:grid-cols-5">
        <Skeleton className="h-64 rounded-xl lg:col-span-3" />
        <Skeleton className="h-64 rounded-xl lg:col-span-2" />
      </div>
    </div>
  );
}
