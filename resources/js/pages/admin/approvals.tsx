import { Head, Link, router } from "@inertiajs/react";
import { BadgeCheckIcon, CheckIcon, Loader2Icon } from "lucide-react";
import { useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { LiveRefresh } from "@/components/orders/live-refresh";
import { DecideApproval } from "@/components/orders/order-actions";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
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

type Correction = {
  id: string;
  kind: "missing_attendance" | "missing_check_out";
  employeeName: string;
  employeeCode: string;
  businessDate: string;
  checkInAt: string | null;
  checkOutAt: string | null;
  reason: string;
  createdAt: string | null;
  canDecide: boolean;
};

type Props = {
  outlet: { id: string; name: string; timezone: string };
  outlets: { id: string; name: string }[];
  approvals: Approval[];
  corrections: Correction[];
  topic: string;
};

/** Kotak persetujuan Owner/Manager. Keputusan diperiksa ulang di server (izin + bukan pemohon). */
export default function ApprovalsPage({ outlet, outlets, approvals, corrections, topic }: Props) {
  const stamp = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone });
  const time = new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", timeZone: outlet.timezone });
  const day = new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "numeric", month: "long", timeZone: "UTC" });

  return (
    <AdminLayout>
      <Head title="Persetujuan" />
      <PageHeader title="Persetujuan" description={`${outlet.name} · Pembatalan pesanan lunas dan koreksi absen`}>
        <div className="flex flex-wrap items-center gap-3">
          <LiveRefresh topic={topic} only={["approvals", "corrections"]} />
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

      {approvals.length === 0 && corrections.length === 0 ? (
        <EmptyState
          icon={BadgeCheckIcon}
          title="Tidak ada pengajuan"
          description="Pengajuan pembatalan dari kasir dan koreksi absen dari staf akan tampil di sini."
        />
      ) : null}

      {corrections.length > 0 ? (
        <section className="flex max-w-4xl flex-col gap-3" aria-labelledby="corrections-title">
          <h2 id="corrections-title" className="text-base font-semibold">
            Koreksi absen
          </h2>
          <ul className="flex flex-col gap-3">
            {corrections.map((c) => (
              <li key={c.id}>
                <Card>
                  <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex min-w-0 flex-col gap-1">
                      <p className="font-semibold">
                        {c.employeeName} <span className="text-sm font-normal text-muted-foreground">· {c.employeeCode}</span>
                      </p>
                      <p className="text-sm">
                        {c.kind === "missing_attendance" ? "Lupa absen" : "Lupa absen pulang"} · {day.format(new Date(`${c.businessDate}T00:00:00Z`))} · masuk{" "}
                        {c.checkInAt ? time.format(new Date(c.checkInAt)) : "—"}, pulang {c.checkOutAt ? time.format(new Date(c.checkOutAt)) : "—"}
                      </p>
                      <p className="text-sm">Alasan: “{c.reason}”</p>
                      <p className="text-xs text-muted-foreground">Diajukan {c.createdAt ? stamp.format(new Date(c.createdAt)) : "—"}</p>
                    </div>
                    {c.canDecide ? (
                      <DecideCorrection approvalId={c.id} />
                    ) : (
                      <p className="text-xs text-muted-foreground sm:max-w-56 sm:text-right">Menunggu keputusan Owner atau Manager lain.</p>
                    )}
                  </CardContent>
                </Card>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {approvals.length === 0 ? null : (
        <section className="flex max-w-4xl flex-col gap-3" aria-labelledby="cancel-title">
          <h2 id="cancel-title" className="text-base font-semibold">
            Pembatalan pesanan lunas
          </h2>
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
        </section>
      )}

      <p className="max-w-2xl text-xs text-muted-foreground">
        Menyetujui pembatalan berarti pesanan dibatalkan dan keluar dari antrean; uang dikembalikan terpisah lewat tombol Catat refund tunai di detail pesanan (Manager).
        Koreksi absen yang disetujui tercatat dengan tanda Koreksi di rekap.
      </p>
    </AdminLayout>
  );
}

/** Setujui (dengan konfirmasi) atau tolak koreksi absen. */
function DecideCorrection({ approvalId }: { approvalId: string }) {
  const [pending, setPending] = useState(false);
  const run = (approve: boolean) =>
    router.post(`/admin/approvals/${approvalId}/decide`, { approve }, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) });
  return (
    <div className="flex flex-wrap gap-2">
      <ConfirmAction
        trigger={
          <Button variant="success" disabled={pending}>
            {pending ? <Loader2Icon className="animate-spin" /> : <CheckIcon />} Setujui
          </Button>
        }
        title="Setujui koreksi absen?"
        description="Absen dicatat sesuai jam yang diajukan dan diberi tanda Koreksi di rekap."
        confirmLabel="Setujui koreksi"
        onConfirm={() => run(true)}
      />
      <Button variant="outline" disabled={pending} onClick={() => run(false)}>
        Tolak
      </Button>
    </div>
  );
}
