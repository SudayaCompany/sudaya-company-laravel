import { usePage } from "@inertiajs/react";

import { AdminLayout } from "@/layouts/admin-layout";
import { PosLayout } from "@/layouts/pos-layout";
import { shellFor } from "@/lib/app-nav";
import type { SharedProps } from "@/types";

/**
 * Halaman yang dipakai admin dan kasir (mis. Pengeluaran): tampil di kerangka aplikasi peran user,
 * jadi kasir tidak "pindah" ke tampilan admin hanya untuk mencatat pengeluaran.
 */
export function RoleLayout({ outletId, children }: { outletId?: string; children: React.ReactNode }) {
  const auth = usePage<SharedProps>().props.auth;
  if (shellFor(auth) === "admin") {
    return <AdminLayout>{children}</AdminLayout>;
  }
  return (
    <PosLayout outletId={outletId} tabsClassName="mx-auto w-full max-w-5xl px-4 pt-4">
      <main className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">{children}</main>
    </PosLayout>
  );
}
