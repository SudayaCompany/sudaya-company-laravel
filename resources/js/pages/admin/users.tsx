import { Head, usePage } from "@inertiajs/react";
import { CopyIcon, KeyRoundIcon, Loader2Icon, PlusIcon, UserPlusIcon, XIcon } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePost } from "@/hooks/use-post";
import { AdminLayout } from "@/layouts/admin-layout";
import { scopeLabel } from "@/lib/format";
import type { Option, SharedProps } from "@/types";

type Grant = { userRoleId: string; roleId: string; roleName: string; outletId: string | null };
type UserRow = { id: string; fullName: string; email: string | null; isActive: boolean; grants: Grant[] };
type Props = {
  users: UserRow[];
  roles: { id: string; name: string }[];
  outlets: { id: string; name: string }[];
};

const ALL_OUTLETS = "";

export default function Users({ users, roles, outlets }: Props) {
  const { auth } = usePage<SharedProps>().props;
  const actorId = auth!.user.id;
  const roleOptions = roles.map((r) => ({ id: r.id, label: r.name }));
  const outletOptions = outlets.map((o) => ({ id: o.id, label: o.name }));
  const outletNames = new Map(outlets.map((o) => [o.id, o.name]));
  const [link, setLink] = useState<{ title: string; url: string } | null>(null);

  return (
    <AdminLayout>
      <Head title="Pengguna" />
      <PageHeader
        title="Pengguna"
        description={`Akun staf ${auth!.user.company}. Setiap perubahan akses tercatat di audit log.`}
      />

      <Card>
        <CardHeader>
          <CardTitle>Undang staf</CardTitle>
          <CardDescription>
            Staf membuat password sendiri lewat link undangan. Anda hanya bisa memberi role yang
            permission-nya juga Anda miliki.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <InviteStaffForm
            roles={roleOptions}
            outlets={outletOptions}
            onLink={(url) => setLink(url ? { title: "Link undangan", url } : null)}
          />
        </CardContent>
      </Card>

      {link ? <OneTimeLink title={link.title} url={link.url} /> : null}

      <Card>
        <CardHeader>
          <CardTitle>Daftar akun</CardTitle>
          <CardDescription>{users.length} akun terdaftar</CardDescription>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nama</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Aksi</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.map((user) => {
                const isSelf = user.id === actorId;
                return (
                  <TableRow key={user.id} className="align-top">
                    <TableCell>
                      <p className="font-medium">{user.fullName}</p>
                      <p className="text-xs text-muted-foreground">{user.email ?? "—"}</p>
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-col gap-2">
                        <div className="flex flex-wrap gap-1.5">
                          {user.grants.length === 0 ? (
                            <span className="text-xs text-muted-foreground">Belum ada role</span>
                          ) : (
                            user.grants.map((grant) => (
                              <Badge key={grant.userRoleId} variant="secondary" className="gap-1">
                                {grant.roleName} · {scopeLabel(grant.outletId, outletNames)}
                                {isSelf ? null : (
                                  <RevokeRoleButton userRoleId={grant.userRoleId} label={grant.roleName} />
                                )}
                              </Badge>
                            ))
                          )}
                        </div>
                        {isSelf ? null : (
                          <RoleAssigner userId={user.id} roles={roleOptions} outlets={outletOptions} />
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge variant={user.isActive ? "default" : "destructive"}>
                        {user.isActive ? "Aktif" : "Nonaktif"}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {isSelf ? (
                        <span className="text-xs text-muted-foreground">Akun Anda</span>
                      ) : (
                        <div className="flex flex-wrap justify-end gap-2">
                          {user.isActive ? (
                            <RecoveryButton
                              userId={user.id}
                              onLink={(url) => setLink({ title: `Link reset password ${user.fullName}`, url })}
                            />
                          ) : null}
                          <StatusToggle userId={user.id} isActive={user.isActive} />
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </AdminLayout>
  );
}

function InviteStaffForm({
  roles,
  outlets,
  onLink,
}: {
  roles: Option[];
  outlets: Option[];
  onLink: (url: string | null) => void;
}) {
  const { pending, post } = usePost();

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const outletId = String(data.get("outletId") ?? "");
    onLink(null);
    post(
      "/admin/users/invite",
      {
        email: String(data.get("email") ?? ""),
        fullName: String(data.get("fullName") ?? ""),
        roleId: String(data.get("roleId") ?? ""),
        outletId: outletId === "" ? null : outletId,
      },
      (flash) => {
        if (flash.success) form.reset();
        if (flash.link) onLink(flash.link);
      },
    );
  }

  return (
    <form
      onSubmit={onSubmit}
      className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto_auto_auto] lg:items-end"
    >
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="invite-name">Nama lengkap</Label>
        <Input id="invite-name" name="fullName" required minLength={2} />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="invite-email">Email</Label>
        <Input id="invite-email" name="email" type="email" required />
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="invite-role">Role</Label>
        <NativeSelect id="invite-role" name="roleId" required className="w-full">
          {roles.map((role) => (
            <NativeSelectOption key={role.id} value={role.id}>
              {role.label}
            </NativeSelectOption>
          ))}
        </NativeSelect>
      </div>
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="invite-outlet">Outlet</Label>
        <NativeSelect id="invite-outlet" name="outletId" className="w-full">
          <NativeSelectOption value="">Semua outlet</NativeSelectOption>
          {outlets.map((outlet) => (
            <NativeSelectOption key={outlet.id} value={outlet.id}>
              {outlet.label}
            </NativeSelectOption>
          ))}
        </NativeSelect>
      </div>
      <Button type="submit" disabled={pending}>
        {pending ? <Loader2Icon className="animate-spin" /> : <UserPlusIcon />}
        Undang
      </Button>
    </form>
  );
}

function OneTimeLink({ title, url }: { title: string; url: string }) {
  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      toast.success("Link disalin.");
    } catch {
      toast.error("Gagal menyalin. Salin manual dari kolom di atas.");
    }
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border border-brand/40 bg-brand/5 p-3 text-sm">
      <p className="font-medium">{title} (hanya ditampilkan sekali)</p>
      <p className="text-xs text-muted-foreground">
        Kirim langsung ke staf lewat kanal pribadi. Link berlaku sesuai pengaturan masa berlaku email
        OTP di Supabase dan hanya bisa dipakai sekali.
      </p>
      <div className="flex gap-2">
        <Input readOnly value={url} aria-label={title} className="font-mono text-xs" />
        <Button type="button" variant="outline" onClick={copy}>
          <CopyIcon /> Salin
        </Button>
      </div>
    </div>
  );
}

function RoleAssigner({ userId, roles, outlets }: { userId: string; roles: Option[]; outlets: Option[] }) {
  const [roleId, setRoleId] = useState(roles[0]?.id ?? "");
  const [outletId, setOutletId] = useState(ALL_OUTLETS);
  const { pending, post } = usePost();

  return (
    <div className="flex flex-wrap items-center gap-2">
      <NativeSelect size="sm" aria-label="Role" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
        {roles.map((role) => (
          <NativeSelectOption key={role.id} value={role.id}>
            {role.label}
          </NativeSelectOption>
        ))}
      </NativeSelect>
      <NativeSelect size="sm" aria-label="Scope outlet" value={outletId} onChange={(e) => setOutletId(e.target.value)}>
        <NativeSelectOption value={ALL_OUTLETS}>Semua outlet</NativeSelectOption>
        {outlets.map((outlet) => (
          <NativeSelectOption key={outlet.id} value={outlet.id}>
            {outlet.label}
          </NativeSelectOption>
        ))}
      </NativeSelect>
      <Button
        size="sm"
        variant="outline"
        disabled={pending || !roleId}
        onClick={() =>
          post("/admin/users/assign-role", {
            userId,
            roleId,
            outletId: outletId === ALL_OUTLETS ? null : outletId,
          })
        }
      >
        {pending ? <Loader2Icon className="animate-spin" /> : <PlusIcon />}
        Tambah role
      </Button>
    </div>
  );
}

function RevokeRoleButton({ userRoleId, label }: { userRoleId: string; label: string }) {
  const { pending, post } = usePost();
  return (
    <button
      type="button"
      disabled={pending}
      onClick={() => post("/admin/users/revoke-role", { userRoleId })}
      className="rounded-full p-0.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive disabled:opacity-50"
      aria-label={`Cabut role ${label}`}
    >
      {pending ? <Loader2Icon className="size-3 animate-spin" /> : <XIcon className="size-3" />}
    </button>
  );
}

function StatusToggle({ userId, isActive }: { userId: string; isActive: boolean }) {
  const { pending, post } = usePost();
  return (
    <Button
      size="sm"
      variant={isActive ? "destructive" : "outline"}
      disabled={pending}
      onClick={() => post("/admin/users/set-active", { userId, isActive: !isActive })}
    >
      {pending ? <Loader2Icon className="animate-spin" /> : null}
      {isActive ? "Nonaktifkan" : "Aktifkan"}
    </Button>
  );
}

function RecoveryButton({ userId, onLink }: { userId: string; onLink: (url: string) => void }) {
  const { pending, post } = usePost();
  return (
    <Button
      size="sm"
      variant="outline"
      disabled={pending}
      onClick={() => post("/admin/users/recovery", { userId }, (flash) => flash.link && onLink(flash.link))}
    >
      {pending ? <Loader2Icon className="animate-spin" /> : <KeyRoundIcon />}
      Reset password
    </Button>
  );
}
