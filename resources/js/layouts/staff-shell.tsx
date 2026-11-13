import { Head, usePage } from "@inertiajs/react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { SectionTabs } from "@/components/section-tabs";
import { AdminLayout } from "@/layouts/admin-layout";
import { PosLayout } from "@/layouts/pos-layout";
import { ADMIN_PERSONAL, locate, shellFor, visibleSections } from "@/lib/app-nav";
import type { SharedProps } from "@/types";

/**
 * Kerangka halaman milik semua peran (Absen, Riwayat absen, Tugas luar): selalu tampil di dalam
 * aplikasi peran user sendiri, jadi membuka Absen tidak "keluar" ke tampilan lain.
 * Admin → kerangka admin, kasir → kerangka kasir, barista/staf → kerangka sederhana di bawah.
 * Tab Absen | Riwayat | Tugas luar sama di laptop dan HP.
 */
export function StaffShell({ title, children }: { title: string; children: React.ReactNode }) {
  const { url, props } = usePage<SharedProps>();
  const auth = props.auth;
  const shell = shellFor(auth);

  if (shell === "admin") {
    return (
      <AdminLayout>
        <Head title={title} />
        <div className="mx-auto flex w-full max-w-lg flex-col gap-5">{children}</div>
      </AdminLayout>
    );
  }
  if (shell === "pos") {
    return (
      <PosLayout tabsClassName="mx-auto w-full max-w-lg px-4 pt-4">
        <Head title={title} />
        <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-5 px-4 py-5">{children}</div>
      </PosLayout>
    );
  }

  const pathname = url.split("?")[0];
  const [section] = auth ? visibleSections([ADMIN_PERSONAL], auth) : [];
  const here = section ? locate([section], pathname) : null;
  return (
    <div className="flex min-h-svh flex-1 flex-col">
      <Head title={title} />
      <header className="sticky top-0 z-30 border-b bg-background/95 backdrop-blur print:hidden">
        <div className="mx-auto flex h-14 w-full max-w-lg items-center gap-3 px-4">
          <Brand className="text-lg" />
          <span className="ml-auto min-w-0 truncate text-sm text-muted-foreground">{auth?.user.fullName}</span>
          <LogoutButton variant="ghost" />
        </div>
      </header>
      <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-5 px-4 py-5">
        {section ? <SectionTabs pages={section.pages} active={here?.page ?? null} label="Halaman absen" /> : null}
        {children}
      </div>
    </div>
  );
}
