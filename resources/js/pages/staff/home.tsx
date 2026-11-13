import { Head, router, usePage } from "@inertiajs/react";
import { useEffect } from "react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { homeFor } from "@/lib/app-nav";
import type { SharedProps } from "@/types";

/**
 * Beranda staf lama (/staff). Sejak 13 November 2026 setiap peran punya satu aplikasi dengan menunya
 * sendiri (lib/app-nav.ts), jadi halaman ini hanya meneruskan ke halaman utama peran: Admin → Dasbor,
 * Kasir → Pesanan masuk, Barista → Absen. Tidak ada lagi menu pintasan yang mengulang menu lain.
 */
export default function StaffHome() {
  const auth = usePage<SharedProps>().props.auth;
  const target = auth ? homeFor(auth) : null;

  useEffect(() => {
    if (target) router.visit(target, { replace: true });
  }, [target]);

  return (
    <div className="mx-auto flex min-h-svh w-full max-w-lg flex-col items-center justify-center gap-4 px-4 text-center">
      <Head title="Beranda" />
      <Brand className="text-2xl" />
      {target ? (
        <p className="text-sm text-muted-foreground" role="status">
          Membuka halaman utama…
        </p>
      ) : (
        <>
          <p className="text-sm text-muted-foreground">Akun ini belum punya menu. Hubungi admin untuk mengatur peran.</p>
          <LogoutButton />
        </>
      )}
    </div>
  );
}
