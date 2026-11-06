import { Head, Link, usePage } from "@inertiajs/react";

import { buttonVariants } from "@/components/ui/button";
import type { SharedProps } from "@/types";

export default function NotFound() {
  // Staf yang login kembali ke halaman sesuai aksesnya (/login mengarahkan); publik ke beranda.
  const signedIn = Boolean(usePage<Partial<SharedProps>>().props.auth);
  return (
    <main className="flex min-h-svh flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
      <Head title="Halaman tidak ditemukan" />
      <h1 className="text-xl font-semibold">Halaman tidak ditemukan</h1>
      <p className="max-w-sm text-sm text-muted-foreground">Alamat ini tidak ada atau sudah dipindahkan.</p>
      <Link href={signedIn ? "/login" : "/"} className={buttonVariants({ variant: "outline" })}>
        {signedIn ? "Ke halaman saya" : "Kembali ke beranda"}
      </Link>
    </main>
  );
}
