import { Head, Link, router } from "@inertiajs/react";
import { LinkIcon, PencilIcon, PlusIcon, SearchIcon, UnlinkIcon, UsersIcon } from "lucide-react";
import { useRef } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminLayout } from "@/layouts/admin-layout";

type Row = {
  id: string;
  code: string;
  fullName: string;
  position: string | null;
  phone: string | null;
  isActive: boolean;
  joinedAt: string | null;
  outletName: string;
  accountName: string | null;
  linked: boolean;
};
type Props = {
  outlets: { id: string; name: string }[];
  params: { outlet: string | null; status: "active" | "inactive"; q: string | null };
  rows: Row[];
  canCreate: boolean;
};

export default function Employees({ outlets, params, rows, canCreate }: Props) {
  const timer = useRef<number | undefined>(undefined);
  const go = (values: Partial<Record<"outlet" | "status" | "q", string | null>>) => {
    const next = { ...params, ...values };
    router.get(
      "/admin/employees",
      Object.fromEntries(Object.entries(next).filter(([key, v]) => v !== null && v !== "" && !(key === "status" && v === "active"))),
      { preserveState: true, preserveScroll: true, replace: true },
    );
  };
  const unlinked = rows.filter((r) => !r.linked && r.isActive).length;

  return (
    <AdminLayout>
      <Head title="Karyawan" />
      <PageHeader title="Karyawan" description={`${rows.length} karyawan ${params.status === "active" ? "aktif" : "nonaktif"}. Tautkan ke akun staf agar bisa absen dari HP.`}>
        {canCreate ? (
          <Link href="/admin/employees/create" className={buttonVariants()}>
            <PlusIcon /> Karyawan baru
          </Link>
        ) : null}
      </PageHeader>

      <div className="flex flex-wrap gap-2">
        <div className="relative w-full sm:w-72">
          <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            type="search"
            defaultValue={params.q ?? ""}
            placeholder="Cari nama, kode, atau jabatan"
            aria-label="Cari karyawan"
            className="pl-8"
            onChange={(e) => {
              const value = e.target.value;
              window.clearTimeout(timer.current);
              timer.current = window.setTimeout(() => go({ q: value.trim() || null }), 400);
            }}
          />
        </div>
        <NativeSelect aria-label="Status" value={params.status} onChange={(e) => go({ status: e.target.value })}>
          <NativeSelectOption value="active">Aktif</NativeSelectOption>
          <NativeSelectOption value="inactive">Nonaktif</NativeSelectOption>
        </NativeSelect>
        {outlets.length > 1 ? (
          <NativeSelect aria-label="Outlet" value={params.outlet ?? ""} onChange={(e) => go({ outlet: e.target.value || null })}>
            <NativeSelectOption value="">Semua outlet</NativeSelectOption>
            {outlets.map((o) => (
              <NativeSelectOption key={o.id} value={o.id}>
                {o.name}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        ) : null}
      </div>

      {unlinked > 0 ? (
        <p className="flex items-center gap-2 rounded-xl border border-warning/50 bg-warning/10 p-3 text-sm">
          <UnlinkIcon className="size-4 shrink-0 text-warning-strong" aria-hidden />
          {unlinked} karyawan belum ditautkan ke akun staf, jadi belum bisa absen dari HP.
        </p>
      ) : null}

      {rows.length === 0 ? (
        <EmptyState
          icon={UsersIcon}
          title={params.q ? "Tidak ada karyawan yang cocok" : "Belum ada karyawan"}
          description={params.q ? "Ubah kata kunci atau filter." : "Tambahkan karyawan, lalu tautkan ke akun staf (menu Staf) agar bisa absen."}
        />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Karyawan</TableHead>
                  <TableHead>Outlet</TableHead>
                  <TableHead>Akun staf</TableHead>
                  <TableHead className="text-right">Aksi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell>
                      <span className="block font-medium">{r.fullName}</span>
                      <span className="block text-xs text-muted-foreground">
                        {r.code}
                        {r.position ? ` · ${r.position}` : ""}
                      </span>
                    </TableCell>
                    <TableCell className="text-sm">{r.outletName}</TableCell>
                    <TableCell>
                      {r.linked ? (
                        <span className="inline-flex items-center gap-1.5 text-sm">
                          <LinkIcon className="size-3.5 text-success-strong" aria-hidden /> {r.accountName}
                        </span>
                      ) : (
                        <Badge variant="outline">Belum ditautkan</Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <Link href={`/admin/employees/${r.id}/edit`} className={buttonVariants({ variant: "outline", size: "sm" })} aria-label={`Ubah ${r.fullName}`}>
                        <PencilIcon /> Ubah
                      </Link>
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
