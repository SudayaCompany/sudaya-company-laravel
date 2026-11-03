import { Head } from "@inertiajs/react";
import { MonitorSmartphoneIcon, StoreIcon } from "lucide-react";

import { Brand } from "@/components/brand";
import { EmptyState } from "@/components/empty-state";
import { type Storefront, StorefrontView } from "@/components/storefront/storefront";

// Kiosk publik di outlet. Tidak ada tautan ke area staf atau admin, dan token
// pesanan tidak disimpan di perangkat yang dipakai bergantian.
export default function KioskPage({ storefront, notice }: { storefront?: Storefront; notice?: "unconfigured" | "not_found" }) {
  if (!storefront) {
    return (
      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 px-4 py-16">
        <Head title="Kiosk" />
        <Brand className="text-2xl" />
        <EmptyState
          icon={notice === "not_found" ? StoreIcon : MonitorSmartphoneIcon}
          title={notice === "not_found" ? "Outlet tidak ditemukan" : "Kiosk belum dikonfigurasi"}
          description="Hubungi staf outlet untuk menyiapkan kiosk ini."
        />
      </main>
    );
  }
  return <StorefrontView storefront={storefront} table={null} channel="kiosk" mode="kiosk" />;
}
