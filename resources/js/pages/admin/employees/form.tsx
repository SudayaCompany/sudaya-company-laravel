import { Head, router } from "@inertiajs/react";
import { Loader2Icon, SaveIcon } from "lucide-react";
import { useState } from "react";

import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { AdminLayout } from "@/layouts/admin-layout";
import { useInlineFlashError } from "@/lib/feedback";

type Employee = {
  id: string;
  code: string;
  fullName: string;
  phone: string | null;
  position: string | null;
  outletId: string;
  joinedAt: string | null;
  userId: string | null;
  isActive: boolean;
};
type Props = {
  employee: Employee | null;
  outlets: { id: string; name: string }[];
  accounts: { id: string; fullName: string; email: string | null }[];
};

export default function EmployeeForm({ employee, outlets, accounts }: Props) {
  useInlineFlashError();
  const editing = employee !== null;
  const [values, setValues] = useState({
    employeeCode: employee?.code ?? "",
    fullName: employee?.fullName ?? "",
    phone: employee?.phone ?? "",
    position: employee?.position ?? "",
    outletId: employee?.outletId ?? outlets[0]?.id ?? "",
    joinedAt: employee?.joinedAt ?? "",
    userId: employee?.userId ?? "",
    isActive: employee?.isActive ?? true,
  });
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (key: keyof typeof values) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setValues((v) => ({ ...v, [key]: e.target.value }));

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const url = editing ? `/admin/employees/${employee.id}` : "/admin/employees";
    const options = {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onFlash: (flash: Record<string, unknown>) => {
        if (typeof flash.error === "string") setError(flash.error);
      },
    };
    if (editing) router.put(url, values, options);
    else router.post(url, values, options);
  }

  return (
    <AdminLayout breadcrumbs={[{ label: "Karyawan", href: "/admin/employees" }, { label: editing ? employee.fullName : "Karyawan baru" }]}>
      <Head title={editing ? `Ubah ${employee.fullName}` : "Karyawan baru"} />
      <PageHeader title={editing ? "Ubah karyawan" : "Karyawan baru"} description="Data ini dipakai untuk absensi dan rekap jam kerja." />
      <Card className="max-w-2xl">
        <CardContent>
          <form className="flex flex-col gap-5" onSubmit={submit}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Kode karyawan" htmlFor="employee-code" hint={editing ? "Kode tidak bisa diubah." : "Mis. SDY-001. Huruf, angka, titik, minus."}>
                <Input id="employee-code" value={values.employeeCode} onChange={set("employeeCode")} disabled={editing} required maxLength={20} autoCapitalize="characters" />
              </Field>
              <Field label="Nama lengkap" htmlFor="employee-name">
                <Input id="employee-name" value={values.fullName} onChange={set("fullName")} required maxLength={80} />
              </Field>
              <Field label="Jabatan" htmlFor="employee-position" hint="Opsional, mis. Barista, Kasir.">
                <Input id="employee-position" value={values.position} onChange={set("position")} maxLength={60} />
              </Field>
              <Field label="No. HP" htmlFor="employee-phone" hint="Opsional.">
                <Input id="employee-phone" type="tel" inputMode="tel" value={values.phone} onChange={set("phone")} maxLength={20} />
              </Field>
              <Field label="Outlet" htmlFor="employee-outlet">
                <NativeSelect id="employee-outlet" className="w-full" value={values.outletId} onChange={set("outletId")} required>
                  {outlets.map((o) => (
                    <NativeSelectOption key={o.id} value={o.id}>
                      {o.name}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </Field>
              <Field label="Tanggal bergabung" htmlFor="employee-joined" hint="Opsional.">
                <Input id="employee-joined" type="date" value={values.joinedAt} onChange={set("joinedAt")} />
              </Field>
            </div>
            <Field label="Akun staf (untuk absen dari HP)" htmlFor="employee-account" hint="Hanya akun aktif yang belum ditautkan ke karyawan lain. Buat akun dulu di menu Staf bila belum ada.">
              <NativeSelect id="employee-account" className="w-full" value={values.userId} onChange={set("userId")}>
                <NativeSelectOption value="">Belum ditautkan</NativeSelectOption>
                {accounts.map((a) => (
                  <NativeSelectOption key={a.id} value={a.id}>
                    {a.fullName}
                    {a.email ? ` · ${a.email}` : ""}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            </Field>
            {editing ? (
              <label className="flex min-h-11 items-center justify-between gap-3 rounded-lg border p-3">
                <span className="flex flex-col">
                  <span className="text-sm font-medium">Karyawan aktif</span>
                  <span className="text-xs text-muted-foreground">Karyawan nonaktif tidak bisa absen dan tidak muncul di papan kehadiran.</span>
                </span>
                <Switch checked={values.isActive} onCheckedChange={(checked) => setValues((v) => ({ ...v, isActive: checked }))} aria-label="Karyawan aktif" />
              </label>
            ) : null}
            {error ? (
              <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
                {error}
              </p>
            ) : null}
            <div>
              <Button type="submit" size="lg" disabled={pending}>
                {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SaveIcon aria-hidden />}
                {editing ? "Simpan perubahan" : "Tambah karyawan"}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </AdminLayout>
  );
}

function Field({ label, htmlFor, hint, children }: { label: string; htmlFor: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}
