import { Head } from "@inertiajs/react";
import { ScrollTextIcon } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";
import { cn } from "@/lib/utils";

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

/** Nama aksi yang mudah dibaca; kode asli tetap ditampilkan kecil untuk penelusuran. */
const ACTION_LABELS: Record<string, string> = {
  "auth.login": "Masuk",
  "auth.logout": "Keluar",
  "auth.login_failed": "Gagal masuk",
  "auth.login_limited": "Masuk dibatasi (terlalu banyak percobaan)",
  "auth.password_set": "Password dibuat",
  "auth.bootstrap_super_admin": "Super Admin pertama dibuat",
  "user.invited": "Staf diundang",
  "user.linked": "Akun staf ditautkan",
  "user.status_changed": "Status akun staf diubah",
  "user.recovery_link_created": "Tautan pemulihan dibuat",
  "rbac.role_assigned": "Peran diberikan",
  "rbac.role_revoked": "Peran dicabut",
  "rbac.permission_granted": "Izin peran ditambah",
  "rbac.permission_revoked": "Izin peran dicabut",
  "outlet.updated": "Pengaturan outlet diubah",
  "outlet_table.created": "Meja ditambahkan",
  "outlet_table.status_changed": "Status meja diubah",
  "category.created": "Kategori dibuat",
  "category.updated": "Kategori diubah",
  "category.deleted": "Kategori dihapus",
  "product.created": "Menu dibuat",
  "product.updated": "Menu diubah",
  "product.deleted": "Menu dihapus",
  "product.price_changed": "Harga menu diubah",
  "product.status_changed": "Status menu diubah",
  "product.availability_changed": "Ketersediaan menu diubah",
  "product.image_changed": "Foto menu diganti",
  "product.image_removed": "Foto menu dihapus",
  "order.created": "Pesanan dibuat",
  "payment.confirmed": "Pembayaran dikonfirmasi",
  "order.queued": "Pesanan masuk antrean",
  "order.preparing": "Pesanan mulai dibuat",
  "order.ready": "Pesanan siap diambil",
  "order.completed": "Pesanan selesai",
  "order.status_override": "Koreksi status pesanan",
  "order.cancel_requested": "Pembatalan diajukan",
  "order.cancelled": "Pesanan dibatalkan",
  "order.expired": "Pesanan kedaluwarsa",
  "approval.approved": "Pengajuan disetujui",
  "approval.rejected": "Pengajuan ditolak",
  "payment.refunded": "Refund tunai dicatat",
  "inventory.sale_posted": "Stok produk jadi dipotong",
  "cash.session_opened": "Sesi kas dibuka",
  "cash.session_closed": "Sesi kas ditutup",
  "cash.money_in": "Kas masuk",
  "cash.money_out": "Kas keluar",
};

const GROUPS = [
  { id: "all", label: "Semua", prefixes: [] },
  { id: "orders", label: "Pesanan & bayar", prefixes: ["order.", "payment.", "approval.", "inventory."] },
  { id: "cash", label: "Kas", prefixes: ["cash."] },
  { id: "menu", label: "Menu", prefixes: ["product.", "category.", "modifier"] },
  { id: "access", label: "Akun & akses", prefixes: ["auth.", "user.", "rbac."] },
  { id: "outlet", label: "Outlet & meja", prefixes: ["outlet"] },
] as const;

const inGroup = (action: string, group: (typeof GROUPS)[number]) => group.prefixes.length === 0 || group.prefixes.some((p) => action.startsWith(p));

function summarize(value: unknown) {
  if (!value || typeof value !== "object") return null;
  return Object.entries(value as Record<string, unknown>)
    .map(([key, v]) => `${key}: ${v === null ? "—" : String(v)}`)
    .join(", ");
}

export default function Audit({ logs }: { logs: Log[] }) {
  const [groupId, setGroupId] = useState<(typeof GROUPS)[number]["id"]>("all");
  const group = GROUPS.find((g) => g.id === groupId) ?? GROUPS[0];
  const shown = logs.filter((log) => inGroup(log.action, group));

  return (
    <AdminLayout>
      <Head title="Log aktivitas" />
      <PageHeader
        title="Log aktivitas"
        description="100 aktivitas terakhir. Log bersifat append-only dan tidak menyimpan password atau token."
      />
      {logs.length === 0 ? (
        <EmptyState
          icon={ScrollTextIcon}
          title="Belum ada aktivitas"
          description="Aktivitas login, perubahan role, dan status akun akan muncul di sini."
        />
      ) : (
        <>
          <div className="flex flex-wrap gap-2" role="group" aria-label="Saring kategori aktivitas">
            {GROUPS.map((g) => {
              const total = logs.filter((log) => inGroup(log.action, g)).length;
              return (
                <button
                  key={g.id}
                  type="button"
                  aria-pressed={g.id === groupId}
                  onClick={() => setGroupId(g.id)}
                  className={cn(
                    "flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-sm font-medium transition-colors pointer-coarse:min-h-11 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                    g.id === groupId ? "border-espresso bg-espresso text-espresso-foreground" : "bg-background hover:border-foreground",
                  )}
                >
                  {g.label}
                  <span className={cn("text-xs", g.id === groupId ? "text-espresso-foreground/80" : "text-muted-foreground")}>{total}</span>
                </button>
              );
            })}
          </div>
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
                  {shown.map((log) => (
                    <TableRow key={log.id} className="align-top">
                      <TableCell className="whitespace-nowrap text-xs">
                        {timeFormat.format(new Date(log.createdAt))}
                      </TableCell>
                      <TableCell className="text-sm">{log.actorName ?? "Sistem"}</TableCell>
                      <TableCell className="text-sm">
                        <span className="block font-medium">{ACTION_LABELS[log.action] ?? log.action}</span>
                        <span className="block font-mono text-[11px] text-muted-foreground">{log.action}</span>
                      </TableCell>
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
              {shown.length === 0 ? <p className="py-6 text-center text-sm text-muted-foreground">Tidak ada aktivitas pada kategori ini.</p> : null}
            </CardContent>
          </Card>
        </>
      )}
    </AdminLayout>
  );
}
