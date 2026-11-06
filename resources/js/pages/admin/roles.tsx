import { Head } from "@inertiajs/react";
import { Fragment } from "react";

import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePost } from "@/hooks/use-post";
import { AdminLayout } from "@/layouts/admin-layout";

type Role = { id: string; code: string; name: string; permissions: string[] };
type Props = {
  roles: Role[];
  permissions: { code: string; description: string | null }[];
  superAdminRole: string;
  actorPermissions: string[];
  actorRoleIds: string[];
};

export default function Roles({ roles, permissions, superAdminRole, actorPermissions, actorRoleIds }: Props) {
  // Aturan yang sama dengan server; di sini hanya untuk menonaktifkan checkbox.
  const held = new Set(actorPermissions);
  const ownRoles = new Set(actorRoleIds);

  const groups = new Map<string, typeof permissions>();
  for (const permission of permissions) {
    const group = permission.code.split(".")[0];
    groups.set(group, [...(groups.get(group) ?? []), permission]);
  }

  return (
    <AdminLayout>
      <Head title="Peran & izin" />
      <PageHeader
        title="Peran & izin"
        description="Super Admin selalu memiliki semua izin. Peran yang Anda pegang sendiri tidak bisa diubah dari akun Anda."
      />
      <Card>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="min-w-56">Izin</TableHead>
                {roles.map((role) => (
                  <TableHead key={role.id} className="text-center">
                    {role.name}
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...groups.entries()].map(([group, items]) => (
                <Fragment key={group}>
                  <TableRow className="bg-muted/50 hover:bg-muted/50">
                    <TableCell colSpan={99} className="text-xs font-semibold tracking-wide uppercase">
                      {group}
                    </TableCell>
                  </TableRow>
                  {items.map((permission) => (
                    <TableRow key={permission.code}>
                      <TableCell>
                        <p className="font-mono text-xs">{permission.code}</p>
                        <p className="text-xs text-muted-foreground">{permission.description}</p>
                      </TableCell>
                      {roles.map((role) => (
                        <TableCell key={role.id} className="text-center">
                          <PermissionToggle
                            roleId={role.id}
                            roleName={role.name}
                            permissionCode={permission.code}
                            granted={role.permissions.includes(permission.code)}
                            disabled={role.code === superAdminRole || ownRoles.has(role.id) || !held.has(permission.code)}
                          />
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </Fragment>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </AdminLayout>
  );
}

function PermissionToggle({
  roleId,
  roleName,
  permissionCode,
  granted,
  disabled,
}: {
  roleId: string;
  roleName: string;
  permissionCode: string;
  granted: boolean;
  disabled: boolean;
}) {
  const { pending, post } = usePost();

  return (
    <Checkbox
      checked={granted}
      disabled={disabled || pending}
      aria-label={`${permissionCode} untuk ${roleName}`}
      onCheckedChange={(checked) =>
        post("/admin/roles/set-permission", { roleId, permissionCode, granted: checked === true })
      }
    />
  );
}
