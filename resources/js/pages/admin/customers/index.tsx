import { Head, router } from "@inertiajs/react";
import { EyeIcon, SearchIcon, UsersRoundIcon } from "lucide-react";
import { useEffect, useState } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";

type Customer = {
  key: string;
  name: string;
  phoneMasked: string;
  phoneFull: string;
  transactions: number;
  spending: number;
  average: number;
  lastAt: string | null;
  firstAt: string | null;
  points: number;
  tier: "Gold" | "Silver" | "Member";
  favorites: { name: string; quantity: number }[];
};
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  q: string | null;
  summary: { customers: number; repeat: number; spending: number };
  customers: Customer[];
  pointValue: number;
  tiers: Record<string, number>;
};

const TIER: Record<Customer["tier"], string> = {
  Gold: "border-warning/60 bg-warning/15 text-foreground",
  Silver: "border-foreground/20 bg-muted text-foreground",
  Member: "text-muted-foreground",
};

export default function CustomersPage({ outlets, outlet, q, summary, customers, pointValue, tiers }: Props) {
  const [search, setSearch] = useState(q ?? "");
  const [shown, setShown] = useState<Record<string, boolean>>({});
  const date = new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", year: "numeric", timeZone: outlet.timezone });
  useEffect(() => {
    if (search === (q ?? "")) return;
    const t = setTimeout(() => router.get("/admin/customers", { outlet: outlet.id, q: search || undefined }, { preserveState: true, preserveScroll: true, replace: true }), 350);
    return () => clearTimeout(t);
  }, [search, q, outlet.id]);

  return (
    <AdminLayout>
      <Head title="Pelanggan" />
      <PageHeader title="Pelanggan" description={`${outlet.name} · dari pesanan lunas yang mencantumkan nomor HP · ${formatRupiah(pointValue)} = 1 poin`}>
        {outlets.length > 1 ? (
          <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.get("/admin/customers", { outlet: e.target.value })}>
            {outlets.map((o) => (
              <NativeSelectOption key={o.id} value={o.id}>
                {o.name}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        ) : null}
      </PageHeader>

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
        <Tile label="Pelanggan tercatat" value={summary.customers.toLocaleString("id-ID")} />
        <Tile label="Pelanggan kembali (≥ 2 transaksi)" value={summary.repeat.toLocaleString("id-ID")} />
        <Tile label="Total belanja pelanggan tercatat" value={formatRupiah(summary.spending)} className="col-span-2 xl:col-span-1" />
      </div>

      <div className="relative max-w-md">
        <SearchIcon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
        <Input aria-label="Cari pelanggan" className="pl-9" placeholder="Cari nama atau nomor HP" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      {customers.length === 0 ? (
        <EmptyState
          icon={UsersRoundIcon}
          title={q ? "Tidak ada pelanggan yang cocok" : "Belum ada data pelanggan"}
          description="Data terisi otomatis dari pesanan lunas yang mencantumkan nomor HP (checkout QR/kiosk atau delivery)."
        />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {customers.map((c) => (
            <li key={c.key} className="flex flex-col gap-2 rounded-xl border bg-card p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-semibold break-words">{c.name}</p>
                  <p className="flex items-center gap-1 text-sm text-muted-foreground tabular-nums">
                    {shown[c.key] ? c.phoneFull : c.phoneMasked}
                    {shown[c.key] ? null : (
                      <Button variant="ghost" size="icon-sm" aria-label={`Lihat nomor ${c.name}`} onClick={() => setShown((s) => ({ ...s, [c.key]: true }))}>
                        <EyeIcon />
                      </Button>
                    )}
                  </p>
                </div>
                <Badge variant="outline" className={TIER[c.tier]}>
                  {c.tier}
                </Badge>
              </div>
              <dl className="grid grid-cols-3 gap-2 text-sm">
                <div>
                  <dt className="text-xs text-muted-foreground">Transaksi</dt>
                  <dd className="font-semibold">{c.transactions}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted-foreground">Belanja</dt>
                  <dd className="font-semibold">{formatRupiah(c.spending)}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted-foreground">Poin</dt>
                  <dd className="font-semibold">{c.points.toLocaleString("id-ID")}</dd>
                </div>
              </dl>
              <p className="text-xs text-muted-foreground">
                Rata-rata {formatRupiah(c.average)} · terakhir {c.lastAt ? date.format(new Date(c.lastAt)) : "—"}
                {c.firstAt && c.transactions > 1 ? ` · pertama ${date.format(new Date(c.firstAt))}` : ""}
              </p>
              {c.favorites.length > 0 ? <p className="text-xs break-words">Favorit: {c.favorites.map((f) => `${f.name} (${f.quantity})`).join(", ")}</p> : null}
            </li>
          ))}
        </ul>
      )}
      <p className="text-xs text-muted-foreground">
        Tier dari total belanja: Silver ≥ {formatRupiah(tiers.Silver)}, Gold ≥ {formatRupiah(tiers.Gold)}. Poin dan tier dihitung ulang otomatis; pesanan yang dibatalkan atau direfund
        tidak dihitung. Penukaran poin belum tersedia.
      </p>
    </AdminLayout>
  );
}

function Tile({ label, value, className }: { label: string; value: string; className?: string }) {
  return (
    <div className={`flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5 ${className ?? ""}`}>
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="text-xl font-semibold tracking-tight sm:text-2xl">{value}</p>
    </div>
  );
}
