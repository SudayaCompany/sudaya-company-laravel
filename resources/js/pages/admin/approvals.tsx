import { Head, Link, router } from "@inertiajs/react";
import { BadgeCheckIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { LiveRefresh } from "@/components/orders/live-refresh";
import { DecideApproval } from "@/components/orders/order-actions";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";
import { NO_NAME_LABEL } from "@/lib/orders";

type Approval = {
  id: string;
  reason: string;
  requestedByName: string | null;
  createdAt: string | null;
  canDecide: boolean;
  order: { id: string; reference: string; orderNumber: string | null; customerName: string | null; total: number; status: string; paidAt: string | null };
};

type Props = {
  outlet: { id: string; name: string; timezone: string };
  outlets: { id: string; name: string }[];
  approvals: Approval[];
  topic: string;
};

/** Kotak persetujuan Owner/Manager. Keputusan diperiksa ulang di server (izin + bukan pemohon). */
export default function ApprovalsPage({ outlet, outlets, approvals, topic }: Props) {
  const stamp = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone });

  return (
    <AdminLayout>
      <Head title="Persetujuan" />
      <PageHeader title="Persetujuan" description={`${outlet.name} · Pengajuan pembatalan pesanan yang sudah dibayar`}>
        <div className="flex flex-wrap items-center gap-3">
          <LiveRefresh topic={topic} only={["approvals"]} />
          {outlets.length > 1 ? (
            <NativeSelect aria-label="Outlet" value={outlet.id} onChange={(e) => router.visit(`/admin/approvals?outlet=${e.target.value}`)}>
              {outlets.map((o) => (
                <NativeSelectOption key={o.id} value={o.id}>
                  {o.name}
                </NativeSelectOption>
              ))}
            </NativeSelect>
          ) : null}
        </div>
      </PageHeader>

      {approvals.length === 0 ? (
        <EmptyState
          icon={BadgeCheckIcon}
          title="Tidak ada pengajuan"
          description="Pengajuan pembatalan dari kasir akan tampil di sini secara otomatis."
        />
      ) : (
        <ul className="flex max-w-4xl flex-col gap-3" aria-label="Pengajuan menunggu keputusan">
          {approvals.map((a) => (
            <li key={a.id}>
              <Card>
                <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                      <Link
                        href={`/admin/orders/${a.order.id}`}
                        className="rounded font-mono text-lg font-bold underline-offset-4 hover:underline focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                      >
                        {a.order.orderNumber ?? a.order.reference}
                      </Link>
                      <span className="font-semibold">{formatRupiah(a.order.total)}</span>
                      <span className="truncate text-sm text-muted-foreground">{a.order.customerName ?? NO_NAME_LABEL}</span>
                    </div>
                    <p className="text-sm">Alasan: “{a.reason}”</p>
                    <p className="text-xs text-muted-foreground">
                      Diajukan oleh {a.requestedByName ?? "staf"} · {a.createdAt ? stamp.format(new Date(a.createdAt)) : "—"}
                    </p>
                  </div>
                  {a.canDecide ? (
                    <DecideApproval base="/admin" approvalId={a.id} total={a.order.total} size="default" />
                  ) : (
                    <p className="text-xs text-muted-foreground sm:max-w-56 sm:text-right">Menunggu keputusan Owner atau Manager lain.</p>
                  )}
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <p className="max-w-2xl text-xs text-muted-foreground">
        Menyetujui berarti pesanan dibatalkan dan keluar dari antrean. Uang dikembalikan terpisah oleh kasir lewat tombol Catat refund tunai saat uang
        diserahkan.
      </p>
    </AdminLayout>
  );
}
