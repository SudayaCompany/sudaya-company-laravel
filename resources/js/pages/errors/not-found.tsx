import { Head, Link } from "@inertiajs/react";

import { buttonVariants } from "@/components/ui/button";

export default function NotFound() {
  return (
    <main className="flex min-h-svh flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
      <Head title="Halaman tidak ditemukan" />
      <h1 className="text-xl font-semibold">Halaman tidak ditemukan</h1>
      <p className="max-w-sm text-sm text-muted-foreground">Alamat ini tidak ada atau sudah dipindahkan.</p>
      <Link href="/" className={buttonVariants({ variant: "outline" })}>
        Kembali ke awal
      </Link>
    </main>
  );
}
