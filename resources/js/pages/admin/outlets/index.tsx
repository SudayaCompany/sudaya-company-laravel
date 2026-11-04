import { Head, Link } from "@inertiajs/react";
import { ChevronRightIcon, StoreIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { AdminLayout } from "@/layouts/admin-layout";

type Outlet = { id: string; code: string; name: string; isActive: boolean; tables: number };

export default function Outlets({ outlets }: { outlets: Outlet[] }) {
  return (
    <AdminLayout>
      <Head title="Outlet & Meja" />
      <PageHeader title="Outlet & Meja" description="Nama, alamat, prefix antrean, batas waktu bayar, status outlet, dan daftar meja resmi untuk QR meja." />
      {outlets.length === 0 ? (
        <EmptyState icon={StoreIcon} title="Tidak ada outlet yang bisa diatur" description="Butuh izin Mengubah konfigurasi outlet & sistem." />
      ) : (
        <Card>
          <CardContent className="flex flex-col divide-y">
            {outlets.map((outlet) => (
              <Link key={outlet.id} href={`/admin/outlets/${outlet.id}`} className="flex items-center gap-3 py-3 hover:text-foreground">
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium">{outlet.name}</p>
                  <p className="text-xs text-muted-foreground">
                    Kode <span className="font-mono">{outlet.code}</span> · {outlet.tables} meja aktif
                  </p>
                </div>
                {outlet.isActive ? <Badge variant="secondary">Aktif</Badge> : <Badge variant="destructive">Nonaktif</Badge>}
                <ChevronRightIcon className="size-4 text-muted-foreground" aria-hidden />
              </Link>
            ))}
          </CardContent>
        </Card>
      )}
    </AdminLayout>
  );
}
