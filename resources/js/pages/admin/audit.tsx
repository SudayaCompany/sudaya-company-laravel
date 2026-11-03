import { Head } from "@inertiajs/react";
import { ScrollTextIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";

type Log = {
  id: string;
  createdAt: string;
  action: string;
  reason: string | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  actorName: string | null;
};

const timeFormat = new Intl.DateTimeFormat("id-ID", {
  dateStyle: "medium",
  timeStyle: "medium",
  timeZone: "Asia/Jakarta",
});

function summarize(value: unknown) {
  if (!value || typeof value !== "object") return null;
  return Object.entries(value as Record<string, unknown>)
    .map(([key, v]) => `${key}: ${v === null ? "—" : String(v)}`)
    .join(", ");
}

export default function Audit({ logs }: { logs: Log[] }) {
  return (
    <AdminLayout>
      <Head title="Audit Log" />
      <PageHeader
        title="Audit Log"
        description="100 aktivitas terakhir. Log bersifat append-only dan tidak menyimpan password atau token."
      />
      {logs.length === 0 ? (
        <EmptyState
          icon={ScrollTextIcon}
          title="Belum ada aktivitas"
          description="Aktivitas login, perubahan role, dan status akun akan muncul di sini."
        />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Waktu (WIB)</TableHead>
                  <TableHead>Pelaku</TableHead>
                  <TableHead>Aksi</TableHead>
                  <TableHead>Detail</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {logs.map((log) => (
                  <TableRow key={log.id} className="align-top">
                    <TableCell className="whitespace-nowrap text-xs">
                      {timeFormat.format(new Date(log.createdAt))}
                    </TableCell>
                    <TableCell className="text-sm">{log.actorName ?? "Sistem"}</TableCell>
                    <TableCell className="font-mono text-xs">{log.action}</TableCell>
                    <TableCell className="max-w-md text-xs text-muted-foreground">
                      {[
                        summarize(log.before) && `sebelum { ${summarize(log.before)} }`,
                        summarize(log.after) && `sesudah { ${summarize(log.after)} }`,
                        log.reason,
                      ]
                        .filter(Boolean)
                        .join(" · ") || "—"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </AdminLayout>
  );
}
