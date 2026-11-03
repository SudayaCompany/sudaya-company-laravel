import { Link, usePage } from "@inertiajs/react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

// Kas (M4) dan KDS (M4) belum dipindahkan, jadi tidak ditampilkan di navigasi.
const NAV = [
  { href: "/pos", label: "Kasir" },
  { href: "/pos/orders", label: "Pesanan" },
  { href: "/staff", label: "Staff" },
];

/** Layout POS; navigasi mempertahankan ?outlet= saat berpindah halaman. */
export function PosLayout({ outletId, children }: { outletId?: string; children: React.ReactNode }) {
  const { url, props } = usePage<SharedProps>();
  const pathname = url.split("?")[0];
  const items = NAV.filter((item) => item.href !== "/staff" || props.auth?.navigation.includes("/staff"));

  return (
    <div className="flex min-h-svh flex-1 flex-col bg-muted/30">
      <header className="sticky top-0 z-30 flex h-14 items-center gap-4 bg-neutral-950 px-4 text-white print:hidden">
        <Link href="/pos" className="flex shrink-0 items-baseline gap-2">
          <Brand tone="light" className="text-base" />
          <span className="hidden text-xs text-neutral-400 sm:inline">POS</span>
        </Link>
        <nav aria-label="Menu POS" className="flex min-w-0 flex-1 gap-1 overflow-x-auto [scrollbar-width:none]">
          {items.map((item) => {
            const active = item.href === "/pos" ? pathname === "/pos" : pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <Link
                key={item.href}
                href={outletId && item.href !== "/staff" ? `${item.href}?outlet=${outletId}` : item.href}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors",
                  active ? "bg-white text-neutral-950" : "text-neutral-300 hover:bg-white/10 hover:text-white",
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        <span className="hidden text-sm text-neutral-300 md:inline">{props.auth?.user.fullName}</span>
        <LogoutButton variant="ghost" />
      </header>
      <div className="flex flex-1 flex-col">{children}</div>
    </div>
  );
}
