import { Head, usePage } from "@inertiajs/react";
import { StoreIcon } from "lucide-react";

import { LogoutButton } from "@/components/logout-button";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { AdminLayout } from "@/layouts/admin-layout";
import { scopeLabel } from "@/lib/format";
import type { AccessibleOutlet, SharedProps } from "@/types";

type Props = {
  outlets: AccessibleOutlet[];
  grants: { userRoleId: string; roleName: string; outletId: string | null }[];
};

export default function Dashboard({ outlets, grants }: Props) {
  const user = usePage<SharedProps>().props.auth!.user;
  const outletNames = new Map(outlets.map((o) => [o.id, o.name]));
  // Belum ada pemilih outlet; outlet pertama yang bisa diakses jadi outlet aktif.
  const activeOutlet = outlets[0] ?? null;

  return (
    <AdminLayout>
      <Head title="Dashboard" />
      <PageHeader title={`Halo, ${user.fullName}`} description={`${user.company} · Dashboard operasional`}>
        <div className="md:hidden">
          <LogoutButton />
        </div>
      </PageHeader>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Akun</CardTitle>
            <CardDescription>{user.email ?? "—"}</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3 text-sm">
            <dl className="grid grid-cols-[7rem_1fr] gap-y-2">
              <dt className="text-muted-foreground">Company</dt>
              <dd>{user.company}</dd>
              <dt className="text-muted-foreground">Role</dt>
              <dd className="flex flex-wrap gap-1.5">
                {grants.map((grant) => (
                  <Badge key={grant.userRoleId} variant="secondary">
                    {grant.roleName} · {scopeLabel(grant.outletId, outletNames)}
                  </Badge>
                ))}
              </dd>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <StoreIcon className="size-4" aria-hidden /> Outlet aktif
            </CardTitle>
            <CardDescription>
              {outlets.length > 1 ? `${outlets.length} outlet dapat diakses` : "Outlet yang dapat diakses"}
            </CardDescription>
          </CardHeader>
          <CardContent className="text-sm">
            {activeOutlet ? (
              <dl className="grid grid-cols-[7rem_1fr] gap-y-2">
                <dt className="text-muted-foreground">Brand</dt>
                <dd>{activeOutlet.brandName}</dd>
                <dt className="text-muted-foreground">Outlet</dt>
                <dd>{activeOutlet.name}</dd>
                <dt className="text-muted-foreground">Kode</dt>
                <dd className="font-mono text-xs">{activeOutlet.code}</dd>
              </dl>
            ) : (
              <p className="text-muted-foreground">Belum ada outlet aktif.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </AdminLayout>
  );
}
