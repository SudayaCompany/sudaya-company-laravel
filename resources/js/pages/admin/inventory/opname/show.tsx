import { Head, router } from "@inertiajs/react";
import { CircleCheckIcon, Loader2Icon, SaveIcon, SendIcon, TriangleAlertIcon, XIcon } from "lucide-react";
import { useMemo, useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { useInlineFlashError } from "@/lib/feedback";
import { cn } from "@/lib/utils";

import { OPNAME_BADGE } from "./index";

type Line = {
  itemId: string;
  name: string;
  sku: string;
  unit: string;
  system: string;
  systemValue: string;
  actual: string | null;
  variance: string | null;
  varianceMilli: number | null;
  reason: string | null;
};
type Props = {
  outlet: { id: string; name: string; timezone: string };
  opname: {
    id: string;
    status: string;
    statusLabel: string;
    snapshotAt: string;
    decision: { status: string; note: string | null; by: string | null } | null;
    canCount: boolean;
    lines: Line[];
  };
};

/** Selisih (aktual − sistem) dalam milli-unit, dihitung dari teks; null bila belum diisi/tidak valid. */
function milli(text: string) {
  const value = text.replace(",", ".").trim();
  if (!/^\d{1,9}(\.\d{1,3})?$/.test(value)) return null;
  const [whole, fraction = ""] = value.split(".");
  return Number(whole) * 1000 + Number(fraction.padEnd(3, "0"));
}
const show = (m: number, unit: string) =>
  `${m > 0 ? "+" : m < 0 ? "−" : ""}${(Math.abs(m) / 1000).toLocaleString("id-ID", { maximumFractionDigits: 3 })} ${unit}`;

/** Lembar hitung stock opname (PRD §23): sistem → aktual → selisih → alasan → ajukan. */
export default function OpnameShow({ outlet, opname }: Props) {
  useInlineFlashError();
  const [counts, setCounts] = useState(() => Object.fromEntries(opname.lines.map((l) => [l.itemId, { actual: l.actual ?? "", reason: l.reason ?? "" }])));
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const stamp = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: outlet.timezone });
  const editable = opname.canCount;

  const rows = useMemo(
    () =>
      opname.lines.map((l) => {
        const c = counts[l.itemId];
        const actual = c.actual.trim() === "" ? null : milli(c.actual);
        const system = milli(l.systemValue) ?? 0;
        const variance = actual === null ? null : actual - system;
        return { ...l, input: c, actualMilli: actual, varianceLive: variance, needsReason: variance !== null && variance !== 0 && c.reason.trim().length < 3 };
      }),
    [counts, opname.lines],
  );
  const counted = rows.filter((r) => r.input.actual.trim() !== "").length;
  const withVariance = rows.filter((r) => r.varianceLive !== null && r.varianceLive !== 0).length;
  const missingReason = rows.filter((r) => r.needsReason).length;
  const invalid = rows.filter((r) => r.input.actual.trim() !== "" && r.actualMilli === null).length;

  const options = (action: string) => ({
    preserveScroll: true,
    onStart: () => setPending(action),
    onFinish: () => setPending(null),
    onFlash: (flash: Record<string, unknown>) => {
      if (typeof flash.error === "string") setError(flash.error);
    },
  });
  const lines = () => rows.map((r) => ({ itemId: r.itemId, actual: r.input.actual, reason: r.input.reason }));
  const save = (then?: () => void) =>
    router.post(`/admin/inventory/opname/${opname.id}/counts`, { lines: lines() }, { ...options("save"), onSuccess: () => then?.() });

  return (
    <AdminLayout breadcrumbs={[{ label: "Stock opname", href: `/admin/inventory/opname?outlet=${outlet.id}` }, { label: stamp.format(new Date(opname.snapshotAt)) }]}>
      <Head title="Lembar stock opname" />
      <PageHeader title="Lembar stock opname" description={`${outlet.name} · stok sistem diambil ${stamp.format(new Date(opname.snapshotAt))}`}>
        <Badge className={cn("text-sm", OPNAME_BADGE[opname.status])}>{opname.statusLabel}</Badge>
      </PageHeader>

      {opname.decision ? (
        <p className={cn("flex max-w-3xl items-start gap-2 rounded-xl p-3 text-sm", opname.decision.status === "approved" ? "bg-success/10 text-success-strong" : "bg-destructive/10 text-destructive-strong")}>
          {opname.decision.status === "approved" ? <CircleCheckIcon className="mt-0.5 size-4 shrink-0" aria-hidden /> : <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden />}
          {opname.decision.status === "approved" ? "Disetujui" : "Ditolak"} oleh {opname.decision.by ?? "—"}
          {opname.decision.note ? ` · “${opname.decision.note}”` : ""}
        </p>
      ) : null}

      {editable ? (
        <div className="grid max-w-3xl grid-cols-3 gap-3 text-sm">
          <Stat label="Dihitung" value={`${counted}/${rows.length}`} />
          <Stat label="Ada selisih" value={String(withVariance)} tone={withVariance ? "text-warning-strong" : undefined} />
          <Stat label="Perlu alasan" value={String(missingReason)} tone={missingReason ? "text-destructive-strong" : undefined} />
        </div>
      ) : null}

      <Card>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead className="text-right">Sistem</TableHead>
                <TableHead className="w-36">Hitung fisik</TableHead>
                <TableHead className="text-right">Selisih</TableHead>
                <TableHead className="min-w-56">Alasan selisih</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((r) => (
                <TableRow key={r.itemId} className="align-top">
                  <TableCell>
                    <span className="block font-medium">{r.name}</span>
                    <span className="block text-xs text-muted-foreground">{r.sku}</span>
                  </TableCell>
                  <TableCell className="text-right whitespace-nowrap tabular-nums">{r.system}</TableCell>
                  <TableCell>
                    {editable ? (
                      <div className="flex items-center gap-1.5">
                        <Input
                          inputMode="decimal"
                          aria-label={`Hitung fisik ${r.name}`}
                          aria-invalid={r.input.actual.trim() !== "" && r.actualMilli === null}
                          value={r.input.actual}
                          onChange={(e) => setCounts((s) => ({ ...s, [r.itemId]: { ...s[r.itemId], actual: e.target.value } }))}
                          placeholder="—"
                          className="w-24"
                        />
                        <span className="text-xs text-muted-foreground">{r.unit}</span>
                      </div>
                    ) : (
                      <span className="tabular-nums">{r.actual === null ? "—" : `${r.actual} ${r.unit}`}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right whitespace-nowrap tabular-nums">
                    {r.varianceLive === null ? (
                      <span className="text-muted-foreground">—</span>
                    ) : r.varianceLive === 0 ? (
                      <span className="text-success-strong">Cocok</span>
                    ) : (
                      <span className={cn("font-semibold", r.varianceLive < 0 ? "text-destructive-strong" : "text-warning-strong")}>{show(r.varianceLive, r.unit)}</span>
                    )}
                  </TableCell>
                  <TableCell>
                    {editable ? (
                      <Input
                        aria-label={`Alasan selisih ${r.name}`}
                        aria-invalid={r.needsReason}
                        value={r.input.reason}
                        maxLength={300}
                        disabled={r.varianceLive === null || r.varianceLive === 0}
                        onChange={(e) => setCounts((s) => ({ ...s, [r.itemId]: { ...s[r.itemId], reason: e.target.value } }))}
                        placeholder={r.varianceLive ? "Wajib, mis. tumpah belum dicatat" : ""}
                      />
                    ) : (
                      <span className="text-sm text-muted-foreground">{r.reason ?? "—"}</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {error ? (
        <p role="alert" className="max-w-3xl rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
          {error}
        </p>
      ) : null}

      {editable ? (
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="lg" disabled={pending !== null || invalid > 0} onClick={() => save()}>
            {pending === "save" ? <Loader2Icon className="animate-spin" aria-hidden /> : <SaveIcon aria-hidden />} Simpan hitungan
          </Button>
          <ConfirmAction
            trigger={
              <Button size="lg" disabled={pending !== null || counted === 0 || missingReason > 0 || invalid > 0}>
                {pending === "submit" ? <Loader2Icon className="animate-spin" aria-hidden /> : <SendIcon aria-hidden />} Ajukan opname
              </Button>
            }
            title="Ajukan stock opname?"
            description={
              withVariance > 0
                ? `${withVariance} item punya selisih. Stok diperbarui setelah disetujui Owner atau Manager. Hitungan tidak bisa diubah lagi.`
                : "Tidak ada selisih: opname langsung selesai. Hitungan tidak bisa diubah lagi."
            }
            confirmLabel="Ajukan"
            onConfirm={() => save(() => router.post(`/admin/inventory/opname/${opname.id}/submit`, {}, options("submit")))}
          />
          <ConfirmAction
            trigger={
              <Button variant="ghost" size="lg" disabled={pending !== null}>
                <XIcon aria-hidden /> Batalkan opname
              </Button>
            }
            title="Batalkan stock opname?"
            description="Hitungan yang sudah diisi tidak dipakai. Stok tidak berubah."
            confirmLabel="Batalkan opname"
            onConfirm={() => router.post(`/admin/inventory/opname/${opname.id}/cancel`, {}, options("cancel"))}
          />
        </div>
      ) : null}
    </AdminLayout>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-xl bg-card p-3 ring-1 ring-foreground/10">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className={cn("text-xl font-semibold", tone)}>{value}</span>
    </div>
  );
}
