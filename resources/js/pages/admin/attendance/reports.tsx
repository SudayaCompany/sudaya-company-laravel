import { Head, router } from "@inertiajs/react";
import { FileSpreadsheetIcon, UsersIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatMinutes } from "@/lib/attendance";

type Row = { id: string; code: string; fullName: string; position: string | null; isActive: boolean; days: number; sessions: number; minutes: number; manual: number; incomplete: number };
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string };
  report: { from: string; to: string; rows: Row[] };
};

export default function AttendanceReports({ outlets, outlet, report }: Props) {
  const go = (params: Record<string, string>) =>
    router.get("/admin/attendance/reports", { outlet: outlet.id, from: report.from, to: report.to, ...params }, { preserveScroll: true, replace: true });
  const totalMinutes = report.rows.reduce((sum, r) => sum + r.minutes, 0);

  return (
    <AdminLayout>
      <Head title="Rekap absensi" />
      <PageHeader title="Rekap absensi" description={`${outlet.name} · ${report.rows.length} karyawan · total ${formatMinutes(totalMinutes)}`}>
        <a
          href={`/admin/attendance/reports/export?outlet=${outlet.id}&from=${report.from}&to=${report.to}`}
          className={buttonVariants({ variant: "outline" })}
          download
        >
          <FileSpreadsheetIcon /> Ekspor ke spreadsheet
        </a>
      </PageHeader>

      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="report-from">Dari</Label>
          <Input id="report-from" type="date" value={report.from} className="w-44" onChange={(e) => e.target.value && go({ from: e.target.value })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="report-to">Sampai</Label>
          <Input id="report-to" type="date" value={report.to} className="w-44" onChange={(e) => e.target.value && go({ to: e.target.value })} />
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
      </div>

      {report.rows.length === 0 ? (
        <EmptyState icon={UsersIcon} title="Belum ada karyawan" description="Tambahkan karyawan di menu Karyawan; rekap terisi otomatis dari absen harian." />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Karyawan</TableHead>
                  <TableHead className="text-right">Hari hadir</TableHead>
                  <TableHead className="text-right">Jam kerja</TableHead>
                  <TableHead className="text-right">Koreksi</TableHead>
                  <TableHead className="text-right">Belum pulang</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {report.rows.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell>
                      <span className="block font-medium">
                        {r.fullName} {r.isActive ? null : <Badge variant="outline">Nonaktif</Badge>}
                      </span>
                      <span className="block text-xs text-muted-foreground">
                        {r.code}
                        {r.position ? ` · ${r.position}` : ""}
                      </span>
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{r.days}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatMinutes(r.minutes)}</TableCell>
                    <TableCell className="text-right tabular-nums">{r.manual}</TableCell>
                    <TableCell className="text-right tabular-nums">{r.incomplete > 0 ? <span className="font-medium text-warning-strong">{r.incomplete}</span> : 0}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
      <p className="text-xs text-muted-foreground">
        Jam kerja = jumlah selisih absen masuk dan pulang (sesi yang belum absen pulang belum dihitung). Koreksi = sesi yang dicatat lewat pengajuan koreksi
        yang disetujui.
      </p>
    </AdminLayout>
  );
}
