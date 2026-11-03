import { Head } from "@inertiajs/react";
import { QrCodeIcon, StoreIcon } from "lucide-react";

import { Brand } from "@/components/brand";
import { EmptyState } from "@/components/empty-state";
import { type Storefront, StorefrontView } from "@/components/storefront/storefront";

type Props = {
  storefront?: Storefront;
  table?: string | null;
  channel?: "qr_table" | "qr_counter" | "website";
  notice?: "scan" | "not_found";
};

// Publik: guest boleh melihat menu dan memesan tanpa login (PRD §32).
export default function OrderPage({ storefront, table = null, channel = "website", notice }: Props) {
  if (!storefront) {
    return (
      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 px-4 py-16">
        <Head title="Menu" />
        <Brand className="text-xl" />
        {notice === "scan" ? (
          <EmptyState icon={QrCodeIcon} title="Scan QR di outlet" description="Pindai kode QR di meja atau kasir Sudaya Coffee untuk melihat menu." />
        ) : (
          <EmptyState icon={StoreIcon} title="Outlet tidak ditemukan" description="Kode QR tidak dikenali atau outlet sedang tutup. Silakan tanya kasir." />
        )}
      </main>
    );
  }
  return <StorefrontView storefront={storefront} table={table} channel={channel} />;
}
