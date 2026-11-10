import { Head, Link, router } from "@inertiajs/react";
import { ChevronRightIcon, ClipboardListIcon, Loader2Icon, PlusIcon } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { cn } from "@/lib/utils";

type Opname = { id: string; status: string; statusLabel: string; createdAt: string; createdByName: string | null; items: number; counted: number; variances: number };
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  opnames: Opname[];
  canCreate: boolean;
};

export const OPNAME_BADGE: Record<string, string> = {
  draft: "bg-info/15 text-info",
  submitted: "bg-warning/20 text-foreground",
  applied: "bg-success/15 text-success-strong",
  rejected: "bg-destructive/10 text-destructive-strong",
  cancelled: "bg-muted text-muted-foreground",
};

/** Daftar stock opname + mulai opname baru (snapshot stok sistem semua item aktif). */
export default function OpnameIndex({ outlets, outlet, opnames, canCreate }: Props) {
  const [pending, setPending] = useState(false);
  const open = opnames.find((o) => o.status === "draft" || o.status === "submitted");
  const stamp = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone });

  return (
    <AdminLayout breadcrumbs={[{ label: "Stok", href: `/admin/inventory?outlet=${outlet.id}` }, { label: "Stock opname" }]}>
      <Head title="Stock opname" />
      <PageHeader title="Stock opname" description={`${outlet.name} · hitung stok fisik, bandingkan dengan sistem, ajukan selisih`}>
        <div className="flex flex-wrap items-center gap-2">
          {outlets.length > 1 ? (
            <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/admin/inventory/opname?outlet=${e.target.value}`)}>
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          ) : null}
          {canCreate && !open ? (
            <Button
              disabled={pending}
              onClick={() => router.post("/admin/inventory/opname", { outletId: outlet.id }, { onStart: () => setPending(true), onFinish: () => setPending(false) })}
            >
              {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <PlusIcon aria-hidden />} Mulai stock opname
            </Button>
          ) : null}
        </div>
      </PageHeader>
      {open ? (
        <Link
          href={`/admin/inventory/opname/${open.id}`}
          className="flex max-w-3xl items-center gap-3 rounded-xl border border-info/40 bg-info/10 p-4 text-sm transition-colors hover:border-info focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
          <ClipboardListIcon className="size-5 shrink-0 text-info" aria-hidden />
          <span className="flex-1">
            <span className="block font-medium">Opname {open.statusLabel.toLowerCase()}</span>
            <span className="block text-muted-foreground">
              {open.counted} dari {open.items} item dihitung. Satu outlet hanya boleh punya satu opname terbuka.
            </span>
          </span>
          <ChevronRightIcon className="size-4" aria-hidden />
        </Link>
      ) : null}
      {opnames.length === 0 ? (
        <EmptyState icon={ClipboardListIcon} title="Belum ada stock opname" description="Mulai opname untuk mencocokkan stok fisik dengan sistem, mis. tiap akhir bulan." />
      ) : (
        <ul className="flex max-w-3xl flex-col gap-2">
          {opnames.map((o) => (
            <li key={o.id}>
              <Link
                href={`/admin/inventory/opname/${o.id}`}
                className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3 text-sm transition-colors hover:border-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
              >
                <span className="min-w-0 flex-1">
                  <span className="block font-medium">{stamp.format(new Date(o.createdAt))}</span>
                  <span className="block text-xs text-muted-foreground">
                    {o.createdByName ?? "—"} · {o.counted}/{o.items} item dihitung · {o.variances} selisih
                  </span>
                </span>
                <Badge className={cn("font-medium", OPNAME_BADGE[o.status])}>{o.statusLabel}</Badge>
                <ChevronRightIcon className="size-4 text-muted-foreground" aria-hidden />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </AdminLayout>
  );
}
