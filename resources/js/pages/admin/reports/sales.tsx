import { Head, router } from "@inertiajs/react";
import { ArrowDownRightIcon, ArrowUpRightIcon, ChartColumnIcon, FileSpreadsheetIcon, MinusIcon, PrinterIcon, Undo2Icon } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

type Point = { transactions: number; revenue: number };
type Report = {
  from: string;
  to: string;
  days: number;
  previous: { from: string; to: string; revenue: number; transactions: number; itemsSold: number };
  summary: { revenue: number; transactions: number; average: number; itemsSold: number; voided: { count: number; amount: number }; expenses: number };
  daily: (Point & { date: string })[];
  hourly: (Point & { hour: number })[];
  products: { name: string; quantity: number; revenue: number }[];
  categories: { name: string; quantity: number; revenue: number; share: number }[];
  methods: { method: string; label: string; transactions: number; amount: number }[];
};
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  today: string;
  report: Report;
};

// Tanggal operasional dari server (YYYY-MM-DD) dihitung di UTC agar tidak bergeser zona.
const parse = (d: string) => new Date(`${d}T00:00:00Z`);
const addDays = (d: string, n: number) => {
  const x = parse(d);
  x.setUTCDate(x.getUTCDate() + n);
  return x.toISOString().slice(0, 10);
};
const fmt = (o: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat("id-ID", { ...o, timeZone: "UTC" });
const short = fmt({ day: "numeric", month: "short" });
const full = fmt({ day: "numeric", month: "short", year: "numeric" });
const weekday = fmt({ weekday: "short", day: "numeric", month: "short", year: "numeric" });
const rangeLabel = (from: string, to: string) => (from === to ? weekday.format(parse(from)) : `${short.format(parse(from))} – ${full.format(parse(to))}`);
const count = (n: number) => n.toLocaleString("id-ID");
const compact = new Intl.NumberFormat("id-ID", { notation: "compact", maximumFractionDigits: 1 });
const hourLabel = (h: number) => `${String(h).padStart(2, "0")}.00`;

function presets(today: string) {
  const monthStart = `${today.slice(0, 7)}-01`;
  const lastMonthEnd = addDays(monthStart, -1);
  return [
    { label: "Hari ini", heading: "hari ini", from: today, to: today },
    { label: "Kemarin", heading: "kemarin", from: addDays(today, -1), to: addDays(today, -1) },
    { label: "7 hari", heading: "7 hari terakhir", from: addDays(today, -6), to: today },
    { label: "30 hari", heading: "30 hari terakhir", from: addDays(today, -29), to: today },
    { label: "Bulan ini", heading: "bulan ini", from: monthStart, to: today },
    { label: "Bulan lalu", heading: "bulan lalu", from: `${lastMonthEnd.slice(0, 7)}-01`, to: lastMonthEnd },
  ];
}

export default function SalesReport({ outlets, outlet, today, report }: Props) {
  const [loading, setLoading] = useState(false);
  const go = (params: Record<string, string>) =>
    router.get(
      "/admin/reports",
      { outlet: outlet.id, from: report.from, to: report.to, ...params },
      { preserveScroll: true, preserveState: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) },
    );
  const { summary: s, previous: p } = report;
  const options = presets(today);
  const preset = options.find((o) => o.from === report.from && o.to === report.to);
  const vs = report.days === 1 ? "hari sebelumnya" : `${report.days} hari sebelumnya`;
  const empty = s.transactions === 0;

  return (
    <AdminLayout>
      <Head title="Laporan penjualan" />
      <PageHeader title="Laporan penjualan" description={`${outlet.name} · ${rangeLabel(report.from, report.to)}`}>
        <div className="flex flex-wrap gap-2 print:hidden">
          <Button variant="outline" onClick={() => window.print()}>
            <PrinterIcon /> Cetak
          </Button>
          <a href={`/admin/reports/export?outlet=${outlet.id}&from=${report.from}&to=${report.to}`} className={buttonVariants({ variant: "outline" })} download>
            <FileSpreadsheetIcon /> Ekspor ke spreadsheet
          </a>
        </div>
      </PageHeader>

      {/* Satu baris filter di atas semua angka: periode dulu, lalu outlet. */}
      <div className="flex flex-col gap-3 print:hidden">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Pilih periode">
          {options.map((o) => {
            const active = o.label === preset?.label;
            return (
              <Button key={o.label} variant={active ? "default" : "outline"} size="sm" aria-pressed={active} onClick={() => go({ from: o.from, to: o.to })}>
                {o.label}
              </Button>
            );
          })}
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="report-from">Dari</Label>
            <Input id="report-from" type="date" value={report.from} max={today} className="w-44" onChange={(e) => e.target.value && go({ from: e.target.value })} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="report-to">Sampai</Label>
            <Input id="report-to" type="date" value={report.to} max={today} className="w-44" onChange={(e) => e.target.value && go({ to: e.target.value })} />
          </div>
          {outlets.length > 1 ? (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="report-outlet">Outlet</Label>
              <NativeSelect id="report-outlet" value={outlet.id} onChange={(e) => go({ outlet: e.target.value })}>
                {outlets.map((o) => (
                  <NativeSelectOption key={o.id} value={o.id}>
                    {o.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            </div>
          ) : null}
          <p className="pb-2 text-xs text-muted-foreground">Maksimal 92 hari per laporan.</p>
        </div>
      </div>

      {/* Saat memuat ulang, angka lama tetap tampil redup — tanpa kedip. */}
      <div className={cn("flex flex-col gap-6 transition-opacity", loading && "opacity-60")} aria-busy={loading}>
        <section aria-labelledby="kpi-title" className="flex flex-col gap-3">
          <h2 id="kpi-title" className="text-base font-semibold">
            Ringkasan {preset ? preset.heading : rangeLabel(report.from, report.to)}
          </h2>
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div className="col-span-2 flex flex-col justify-between gap-2 rounded-xl bg-espresso p-5 text-espresso-foreground xl:col-span-1 print:border print:bg-transparent print:text-foreground">
              <p className="text-sm text-espresso-foreground/80 print:text-muted-foreground">Omzet bersih</p>
              <p className="text-4xl font-bold tracking-tight sm:text-5xl xl:text-4xl" data-testid="report-revenue">
                {formatRupiah(s.revenue)}
              </p>
              <Delta current={s.revenue} previous={p.revenue} label={vs} onDark />
            </div>
            <Kpi label="Transaksi" value={count(s.transactions)} current={s.transactions} previous={p.transactions} vs={vs} />
            <Kpi
              label="Rata-rata per transaksi"
              value={formatRupiah(s.average)}
              current={s.average}
              previous={p.transactions === 0 ? 0 : Math.floor(p.revenue / p.transactions)}
              vs={vs}
            />
            <Kpi label="Item terjual" value={count(s.itemsSold)} current={s.itemsSold} previous={p.itemsSold} vs={vs} className="col-span-2 xl:col-span-1" />
          </div>
          <p className="text-xs text-muted-foreground">
            Omzet = pesanan lunas, tanpa yang dibatalkan atau direfund. Pembanding: {rangeLabel(p.from, p.to)} ({formatRupiah(p.revenue)}).
          </p>
          {s.expenses > 0 ? (
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-muted/60 px-3 py-2 text-sm">
              <span>
                Pengeluaran disetujui <span className="font-medium">{formatRupiah(s.expenses)}</span>
              </span>
              <span className="text-muted-foreground">·</span>
              <span>
                Omzet dikurangi pengeluaran <span className="font-semibold">{formatRupiah(s.revenue - s.expenses)}</span>
              </span>
            </p>
          ) : null}
          {s.voided.count > 0 ? (
            <p className="flex items-center gap-2 rounded-lg bg-muted/60 px-3 py-2 text-sm">
              <Undo2Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
              <span>
                {count(s.voided.count)} pesanan lunas dibatalkan/direfund senilai <span className="font-medium">{formatRupiah(s.voided.amount)}</span> — tidak
                dihitung di omzet.
              </span>
            </p>
          ) : null}
        </section>

        {empty ? (
          <EmptyState
            icon={ChartColumnIcon}
            title="Belum ada penjualan lunas pada periode ini"
            description="Coba pilih periode lain. Grafik dan daftar menu terlaris tampil setelah ada pesanan yang lunas."
          />
        ) : (
          <>
            {report.days > 1 ? (
              <Card className="break-inside-avoid">
                <CardHeader>
                  <CardTitle>Omzet harian</CardTitle>
                  <CardDescription>Arahkan kursor atau gunakan tombol panah untuk melihat angka per hari.</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                  <ColumnChart
                    name="Omzet harian"
                    points={report.daily.map((d) => ({ key: d.date, value: d.revenue, label: weekday.format(parse(d.date)), transactions: d.transactions, axis: short.format(parse(d.date)) }))}
                  />
                  <TableView
                    caption="Omzet harian"
                    head="Tanggal"
                    rows={report.daily.map((d) => ({ key: d.date, label: weekday.format(parse(d.date)), transactions: d.transactions, revenue: d.revenue }))}
                  />
                </CardContent>
              </Card>
            ) : null}

            <HourlyCard hourly={report.hourly} days={report.days} />

            <div className="grid gap-6 xl:grid-cols-2">
              <RankedBars
                title="Menu terlaris"
                description="10 menu dengan jumlah terjual terbanyak"
                rows={report.products.map((r) => ({ name: r.name, value: r.quantity, text: `${count(r.quantity)} terjual · ${formatRupiah(r.revenue)}` }))}
                numbered
              />
              <RankedBars
                title="Per kategori"
                description="Porsi omzet tiap kategori menu"
                rows={report.categories.map((c) => ({ name: c.name, value: c.revenue, text: `${c.share.toLocaleString("id-ID")}% · ${formatRupiah(c.revenue)}` }))}
              />
            </div>

            <Card className="break-inside-avoid">
              <CardHeader>
                <CardTitle>Metode pembayaran</CardTitle>
                <CardDescription>Uang yang diterima per metode untuk pesanan lunas</CardDescription>
              </CardHeader>
              <CardContent>
                <dl className="grid gap-3 sm:grid-cols-2">
                  {report.methods.map((m) => (
                    <div key={m.method} className="flex flex-col gap-1 rounded-lg bg-muted/60 p-3">
                      <dt className="text-sm text-muted-foreground">{m.label}</dt>
                      <dd className="text-xl font-semibold">{formatRupiah(m.amount)}</dd>
                      <dd className="text-xs text-muted-foreground">
                        {count(m.transactions)} transaksi · {s.revenue > 0 ? Math.round((m.amount * 1000) / s.revenue) / 10 : 0}% dari omzet
                      </dd>
                    </div>
                  ))}
                </dl>
              </CardContent>
            </Card>
          </>
        )}
      </div>
    </AdminLayout>
  );
}

function Kpi({ label, value, current, previous, vs, className }: { label: string; value: string; current: number; previous: number; vs: string; className?: string }) {
  return (
    <div className={cn("flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5", className)}>
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="text-xl font-semibold tracking-tight sm:text-2xl">{value}</p>
      <Delta current={current} previous={previous} label={vs} />
    </div>
  );
}

/** Perubahan vs periode sebelumnya: panah + kata (bukan warna saja). Di latar gelap tanpa warna status. */
function Delta({ current, previous, label, onDark = false }: { current: number; previous: number; label: string; onDark?: boolean }) {
  const muted = onDark ? "text-espresso-foreground/75 print:text-muted-foreground" : "text-muted-foreground";
  if (previous === 0) {
    return <p className={cn("text-xs", muted)}>{current === 0 ? `Sama dengan ${label}` : `Belum ada penjualan di ${label}`}</p>;
  }
  const pct = Math.round(((current - previous) / previous) * 1000) / 10;
  if (pct === 0) {
    return (
      <p className={cn("flex items-center gap-1 text-xs", muted)}>
        <MinusIcon className="size-3.5" aria-hidden /> Sama dengan {label}
      </p>
    );
  }
  const up = pct > 0;
  const Icon = up ? ArrowUpRightIcon : ArrowDownRightIcon;
  return (
    <p className={cn("flex flex-wrap items-center gap-1 text-xs", onDark ? muted : up ? "text-success-strong" : "text-destructive-strong")}>
      <Icon className="size-3.5" aria-hidden />
      <span className="font-medium">
        {up ? "Naik" : "Turun"} {Math.abs(pct).toLocaleString("id-ID")}%
      </span>
      <span className={onDark ? "" : "text-muted-foreground"}>vs {label}</span>
    </p>
  );
}

function niceTicks(max: number) {
  if (max <= 0) return [0, 1];
  const raw = max / 4;
  const pow = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((v) => v >= raw) ?? 10 * pow;
  const steps = Math.ceil(max / step);
  return Array.from({ length: steps + 1 }, (_, i) => i * step);
}

type ChartPoint = { key: string; value: number; label: string; transactions: number; axis: string };

/**
 * Kolom satu seri (omzet): satu warna, ujung atas membulat 4px, celah 2px antarkolom, grid hairline.
 * Seluruh lajur kolom menjadi area sentuh; tooltip juga terbuka lewat keyboard (panah kiri/kanan).
 */
function ColumnChart({ name, points }: { name: string; points: ChartPoint[] }) {
  const [active, setActive] = useState<number | null>(null);
  const ticks = niceTicks(Math.max(0, ...points.map((p) => p.value)));
  const top = ticks[ticks.length - 1];
  const every = Math.max(1, Math.ceil(points.length / 8));
  const current = active === null ? null : points[active];
  const lastWithSales = points.reduce((found, p, i) => (p.value > 0 ? i : found), points.length - 1);

  const onKey = (e: React.KeyboardEvent) => {
    const last = points.length - 1;
    const next: Record<string, (i: number) => number> = {
      ArrowLeft: (i) => Math.max(0, i - 1),
      ArrowRight: (i) => Math.min(last, i + 1),
      Home: () => 0,
      End: () => last,
    };
    if (!next[e.key]) return;
    e.preventDefault();
    setActive((i) => next[e.key](i ?? lastWithSales));
  };

  return (
    <div className="flex items-start gap-2">
      <div className="flex h-48 w-11 shrink-0 flex-col-reverse justify-between text-right text-[11px] leading-none text-muted-foreground tabular-nums" aria-hidden>
        {ticks.map((t) => (
          <span key={t} className="-my-[5px]">
            {compact.format(t)}
          </span>
        ))}
      </div>
      <div className="min-w-0 flex-1 overflow-x-auto pb-1">
        <div style={{ minWidth: points.length * 5 }}>
          <div
            role="group"
            tabIndex={0}
            aria-label={`${name}. Gunakan panah kiri dan kanan untuk membaca nilai.`}
            onKeyDown={onKey}
            onFocus={() => setActive((i) => i ?? lastWithSales)}
            onBlur={() => setActive(null)}
            onPointerLeave={() => setActive(null)}
            className="relative flex h-48 items-end rounded-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
          >
            {ticks.map((t) => (
              <div key={t} aria-hidden className="pointer-events-none absolute inset-x-0 border-t border-border" style={{ bottom: `${(t / top) * 100}%` }} />
            ))}
            {points.map((p, i) => (
              <div key={p.key} className="relative flex h-full flex-1 items-end justify-center px-px" onPointerEnter={() => setActive(i)}>
                {p.value > 0 ? (
                  <div
                    className={cn("w-full max-w-6 rounded-t-[4px] bg-brand-strong transition-[filter] duration-150", active === i && "brightness-125")}
                    style={{ height: `max(2px, ${(p.value / top) * 100}%)` }}
                  />
                ) : null}
              </div>
            ))}
            {current && active !== null ? (
              <div
                className="pointer-events-none absolute top-0 z-10 w-max rounded-md bg-popover px-2.5 py-1.5 text-popover-foreground shadow-md ring-1 ring-foreground/10"
                style={{
                  left: `${((active + 0.5) / points.length) * 100}%`,
                  transform: `translateX(${active < points.length * 0.2 ? "0" : active > points.length * 0.8 ? "-100%" : "-50%"})`,
                }}
              >
                <p className="text-sm font-semibold">{formatRupiah(current.value)}</p>
                <p className="text-xs text-muted-foreground">
                  {current.label} · {count(current.transactions)} transaksi
                </p>
              </div>
            ) : null}
          </div>
          <div className="flex h-6" aria-hidden>
            {points.map((p, i) => (
              <div key={p.key} className="relative flex-1">
                {i % every === 0 ? (
                  <span className="absolute top-1.5 left-1/2 -translate-x-1/2 text-[11px] whitespace-nowrap text-muted-foreground">{p.axis}</span>
                ) : null}
              </div>
            ))}
          </div>
        </div>
      </div>
      <p className="sr-only" aria-live="polite">
        {current ? `${current.label}: ${formatRupiah(current.value)}, ${current.transactions} transaksi` : ""}
      </p>
    </div>
  );
}

/** Jam ramai: hanya rentang jam yang ada transaksinya (minimal 6 jam) agar kolom tidak tipis. */
function HourlyCard({ hourly, days }: { hourly: Report["hourly"]; days: number }) {
  const withSales = hourly.filter((h) => h.transactions > 0).map((h) => h.hour);
  if (withSales.length === 0) return null;
  let first = Math.min(...withSales);
  let last = Math.max(...withSales);
  while (last - first < 5) {
    if (first > 0) first -= 1;
    if (last - first < 5 && last < 23) last += 1;
  }
  const rows = hourly.filter((h) => h.hour >= first && h.hour <= last);
  const peak = rows.reduce((best, h) => (h.transactions > best.transactions ? h : best), rows[0]);

  return (
    <Card className="break-inside-avoid">
      <CardHeader>
        <CardTitle>Jam ramai</CardTitle>
        <CardDescription>
          Omzet per jam bayar{days > 1 ? `, dijumlah selama ${days} hari` : ""}. Tersibuk {hourLabel(peak.hour)}–{hourLabel((peak.hour + 1) % 24)} (
          {count(peak.transactions)} transaksi).
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <ColumnChart
          name="Omzet per jam"
          points={rows.map((h) => ({ key: String(h.hour), value: h.revenue, label: `${hourLabel(h.hour)}–${hourLabel((h.hour + 1) % 24)}`, transactions: h.transactions, axis: hourLabel(h.hour) }))}
        />
        <TableView
          caption="Omzet per jam"
          head="Jam"
          rows={rows.map((h) => ({ key: String(h.hour), label: `${hourLabel(h.hour)}–${hourLabel((h.hour + 1) % 24)}`, transactions: h.transactions, revenue: h.revenue }))}
        />
      </CardContent>
    </Card>
  );
}

/** Kembaran tabel dari grafik: semua angka terbaca tanpa hover. */
function TableView({ caption, head, rows }: { caption: string; head: string; rows: { key: string; label: string; transactions: number; revenue: number }[] }) {
  return (
    <details className="group rounded-lg border print:hidden">
      <summary className="flex min-h-10 cursor-pointer items-center px-3 text-sm font-medium text-brand-strong select-none pointer-coarse:min-h-11">Tampilkan sebagai tabel</summary>
      <div className="max-h-80 overflow-auto border-t">
        <Table>
          <caption className="sr-only">{caption}</caption>
          <TableHeader>
            <TableRow>
              <TableHead>{head}</TableHead>
              <TableHead className="text-right">Transaksi</TableHead>
              <TableHead className="text-right">Omzet</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((r) => (
              <TableRow key={r.key}>
                <TableCell>{r.label}</TableCell>
                <TableCell className="text-right tabular-nums">{count(r.transactions)}</TableCell>
                <TableCell className="text-right tabular-nums">{formatRupiah(r.revenue)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </details>
  );
}

/** Batang horizontal satu warna, terbesar dulu; angkanya selalu tertulis. */
function RankedBars({ title, description, rows, numbered = false }: { title: string; description: string; rows: { name: string; value: number; text: string }[]; numbered?: boolean }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <Card className="break-inside-avoid">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent>
        <ol className="flex flex-col gap-3.5">
          {rows.map((r, index) => (
            <li key={r.name} className="flex flex-col gap-1.5">
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="min-w-0 truncate font-medium">
                  {numbered ? <span className="mr-2 text-muted-foreground">{index + 1}.</span> : null}
                  {r.name}
                </span>
                <span className="shrink-0 text-muted-foreground tabular-nums">{r.text}</span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-brand-soft" aria-hidden>
                <div className="h-full rounded-full bg-brand-strong" style={{ width: `${Math.max(2, (r.value / max) * 100)}%` }} />
              </div>
            </li>
          ))}
        </ol>
      </CardContent>
    </Card>
  );
}
