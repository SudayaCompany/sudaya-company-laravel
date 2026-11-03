import { Head, Link } from "@inertiajs/react";
import { ConstructionIcon } from "lucide-react";

import { EmptyState } from "@/components/empty-state";
import { buttonVariants } from "@/components/ui/button";

/** Modul yang belum dipindahkan ke Laravel. Tidak menampilkan data atau fungsi apa pun. */
export default function Pending({ module }: { module: string }) {
  return (
    <main className="mx-auto flex w-full max-w-md flex-1 flex-col items-center justify-center gap-6 px-4 py-16">
      <Head title={module} />
      <EmptyState
        icon={ConstructionIcon}
        title={`${module} belum tersedia`}
        description="Modul ini sedang dipindahkan ke versi baru dan belum bisa dipakai."
        className="w-full"
      />
      <Link href="/" className={buttonVariants({ size: "sm", variant: "outline" })}>
        Kembali ke beranda
      </Link>
    </main>
  );
}
