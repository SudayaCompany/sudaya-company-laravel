import { Link, usePage } from "@inertiajs/react";
import { LayoutDashboardIcon } from "lucide-react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

// Tampil bila user punya izinnya; setiap halaman tetap memeriksa izin di server.
const NAV: { href: string; label: string; visible: (auth: NonNullable<SharedProps["auth"]>) => boolean }[] = [
  // Hanya menu yang boleh dibuka peran ini (Owner membuka KDS tanpa akses Kasir/Kas POS → tidak ada tautan 403).
  { href: "/pos", label: "Kasir", visible: (auth) => auth.navigation.includes("/pos") },
  { href: "/pos/orders", label: "Pesanan", visible: (auth) => auth.navigation.includes("/pos") },
  { href: "/pos/cash", label: "Kas", visible: (auth) => auth.navigation.includes("/pos") && auth.permissions.includes("cash.view") },
  { href: "/kds", label: "Barista", visible: (auth) => auth.navigation.includes("/kds") },
  { href: "/staff/attendance", label: "Absen", visible: (auth) => auth.navigation.includes("/staff/attendance") },
  { href: "/staff", label: "Beranda", visible: (auth) => auth.navigation.includes("/staff") },
];

/** Layout POS; navigasi mempertahankan ?outlet= saat berpindah halaman. */
export function PosLayout({ outletId, children }: { outletId?: string; children: React.ReactNode }) {
  const { url, props } = usePage<SharedProps>();
  const pathname = url.split("?")[0];
  const auth = props.auth;
  const items = auth ? NAV.filter((item) => item.visible(auth)) : [];
  // Logo kembali ke halaman yang memang boleh dibuka (Owner tidak punya akses Kasir).
  const home = !auth || auth.navigation.includes("/pos") ? "/pos" : auth.navigation.includes("/admin") ? "/admin" : "/staff";

  return (
    <div className="flex min-h-svh flex-1 flex-col bg-muted/30">
      <header className="sticky top-0 z-30 flex h-14 items-center gap-3 bg-espresso px-3 text-espresso-foreground sm:gap-4 sm:px-4 print:hidden">
        <Link href={home} className="flex min-h-9 shrink-0 items-center gap-2 pointer-coarse:min-h-11">
          <Brand tone="light" className="text-base" />
          <span className="hidden text-xs text-neutral-400 sm:inline">POS</span>
        </Link>
        <nav aria-label="Menu POS" className="flex min-w-0 flex-1 gap-1 overflow-x-auto [scrollbar-width:none]">
          {items.map((item) => {
            const active = item.href === "/pos" ? pathname === "/pos" : pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <Link
                key={item.href}
                href={outletId && !item.href.startsWith("/staff") ? `${item.href}?outlet=${outletId}` : item.href}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "flex min-h-9 shrink-0 items-center rounded-lg px-3 text-sm font-medium transition-colors pointer-coarse:min-h-11",
                  active ? "bg-white text-espresso" : "text-neutral-300 hover:bg-white/10 hover:text-white",
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        {props.auth?.navigation.includes("/admin") ? (
          <Link
            href="/admin"
            className="flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-neutral-300 transition-colors pointer-coarse:min-h-11 hover:bg-white/10 hover:text-white [&_svg]:size-4"
          >
            <LayoutDashboardIcon aria-hidden /> Admin
          </Link>
        ) : null}
        <span className="hidden text-sm text-neutral-300 lg:inline">{props.auth?.user.fullName}</span>
        <LogoutButton variant="ghost" />
      </header>
      <div className="flex flex-1 flex-col">{children}</div>
    </div>
  );
}
