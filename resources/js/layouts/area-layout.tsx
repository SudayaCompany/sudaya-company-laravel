import { AdminLayout } from "@/layouts/admin-layout";
import { PosLayout } from "@/layouts/pos-layout";
import type { Area } from "@/lib/area";
import { cn } from "@/lib/utils";

/** Kerangka halaman sesuai area: layout kasir (dengan <main> sendiri) atau sidebar admin. */
export function AreaPage({ area = "pos", outletId, className, children }: { area?: Area; outletId?: string; className?: string; children: React.ReactNode }) {
  if (area === "admin") {
    return (
      <AdminLayout>
        <div className={cn("flex w-full flex-col gap-4", className)}>{children}</div>
      </AdminLayout>
    );
  }
  const width = className?.match(/max-w-\S+/)?.[0];
  return (
    <PosLayout outletId={outletId} tabsClassName={cn("mx-auto w-full px-4 pt-4", width)}>
      <main className={cn("mx-auto flex w-full flex-col gap-4 p-4", className)}>{children}</main>
    </PosLayout>
  );
}
