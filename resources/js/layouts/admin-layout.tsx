import { Link, usePage } from "@inertiajs/react";
import {
  ArrowUpRightIcon,
  BadgeCheckIcon,
  CoffeeIcon,
  KeyRoundIcon,
  LayoutDashboardIcon,
  MenuIcon,
  MonitorIcon,
  ReceiptTextIcon,
  ScrollTextIcon,
  ShoppingBagIcon,
  SlidersHorizontalIcon,
  StoreIcon,
  TagsIcon,
  UsersIcon,
  WalletIcon,
  type LucideIcon,
} from "lucide-react";
import { useState } from "react";

import { Brand } from "@/components/brand";
import { Breadcrumb, type Crumb } from "@/components/breadcrumb";
import { LogoutButton } from "@/components/logout-button";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

type Auth = NonNullable<SharedProps["auth"]>;
type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Tampil bila user punya aksesnya; setiap halaman tetap memeriksa izin di server. */
  visible: (auth: Auth) => boolean;
  /** Membuka area operasional (layout kasir), bukan halaman admin. */
  leavesAdmin?: boolean;
};

const nav = (href: string) => (auth: Auth) => auth.navigation.includes(href);
/** Pengguna tanpa akses kasir (mis. Owner) memantau pesanan dan kas dari area admin. */
const adminOnly = (href: string) => (auth: Auth) => !auth.navigation.includes("/pos") && auth.navigation.includes(href);

// Grup mengikuti docs/UI_UX_SITEMAP.md §1. Hanya halaman yang sudah ada yang ditautkan.
const GROUPS: { label: string; items: NavItem[] }[] = [
  { label: "Ringkasan", items: [{ href: "/admin", label: "Dasbor", icon: LayoutDashboardIcon, visible: nav("/admin") }] },
  {
    label: "Operasional",
    items: [
      { href: "/pos", label: "Kasir", icon: ShoppingBagIcon, visible: nav("/pos"), leavesAdmin: true },
      { href: "/pos/orders", label: "Pesanan hari ini", icon: ReceiptTextIcon, visible: nav("/pos"), leavesAdmin: true },
      { href: "/admin/orders", label: "Pesanan hari ini", icon: ReceiptTextIcon, visible: adminOnly("/admin/orders") },
      { href: "/admin/approvals", label: "Persetujuan", icon: BadgeCheckIcon, visible: nav("/admin/approvals") },
      {
        href: "/pos/cash",
        label: "Kas",
        icon: WalletIcon,
        visible: (auth) => auth.navigation.includes("/pos") && auth.permissions.includes("cash.view"),
        leavesAdmin: true,
      },
      { href: "/admin/cash", label: "Kas", icon: WalletIcon, visible: adminOnly("/admin/cash") },
      { href: "/kds", label: "Barista display", icon: MonitorIcon, visible: nav("/kds"), leavesAdmin: true },
    ],
  },
  {
    label: "Produk",
    items: [
      { href: "/admin/products", label: "Menu", icon: CoffeeIcon, visible: nav("/admin/products") },
      { href: "/admin/categories", label: "Kategori", icon: TagsIcon, visible: nav("/admin/categories") },
      { href: "/admin/modifiers", label: "Varian & tambahan", icon: SlidersHorizontalIcon, visible: nav("/admin/modifiers") },
    ],
  },
  {
    label: "Organisasi",
    items: [
      { href: "/admin/outlets", label: "Outlet & meja", icon: StoreIcon, visible: nav("/admin/outlets") },
      { href: "/admin/users", label: "Staf", icon: UsersIcon, visible: nav("/admin/users") },
      { href: "/admin/roles", label: "Peran & izin", icon: KeyRoundIcon, visible: nav("/admin/roles") },
    ],
  },
  { label: "Administrasi", items: [{ href: "/admin/audit", label: "Log aktivitas", icon: ScrollTextIcon, visible: nav("/admin/audit") }] },
];

function isActive(pathname: string, href: string) {
  return href === "/admin" || href === "/pos" ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);
}

function AdminNav({ auth, pathname }: { auth: Auth; pathname: string }) {
  const groups = GROUPS.map((group) => ({ ...group, items: group.items.filter((item) => item.visible(auth)) })).filter((g) => g.items.length > 0);
  return (
    <nav aria-label="Menu admin" className="flex flex-col gap-4">
      {groups.map((group) => (
        <div key={group.label} className="flex flex-col gap-1">
          <p className="px-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">{group.label}</p>
          {group.items.map((item) => {
            const active = isActive(pathname, item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "flex min-h-9 items-center gap-2.5 rounded-lg px-3 text-sm transition-colors pointer-coarse:min-h-11 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none [&_svg]:size-4 [&_svg]:shrink-0",
                  active ? "bg-primary font-medium text-primary-foreground" : "text-foreground/80 hover:bg-sidebar-accent hover:text-foreground",
                )}
              >
                <item.icon aria-hidden />
                <span className="flex-1">{item.label}</span>
                {item.leavesAdmin ? <ArrowUpRightIcon className={active ? "" : "text-muted-foreground"} aria-label="buka area operasional" /> : null}
              </Link>
            );
          })}
        </div>
      ))}
    </nav>
  );
}

function Profile({ auth }: { auth: Auth }) {
  return (
    <div className="flex flex-col gap-3 border-t pt-4">
      <div className="min-w-0 px-1">
        <p className="truncate text-sm font-medium">{auth.user.fullName}</p>
        <p className="truncate text-xs text-muted-foreground">{auth.user.roles.join(", ") || "Tanpa peran"}</p>
      </div>
      <LogoutButton />
    </div>
  );
}

/**
 * Shell admin: sidebar bergrup di layar lebar (≥ 1024 px), drawer di layar lebih kecil.
 * Profil dan Keluar selalu tersedia di keduanya.
 */
export function AdminLayout({ breadcrumbs, children }: { breadcrumbs?: Crumb[]; children: React.ReactNode }) {
  const { url, props } = usePage<SharedProps>();
  const auth = props.auth;
  const pathname = url.split("?")[0];
  const [open, setOpen] = useState(false);

  return (
    <div className="flex min-h-svh flex-1 flex-col lg:flex-row">
      <a
        href="#konten"
        className="sr-only z-50 rounded-lg bg-background px-4 py-2 shadow-lg focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
      >
        Langsung ke konten
      </a>

      {/* Layar kecil: bar atas + drawer. */}
      <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b bg-background/95 px-3 backdrop-blur lg:hidden">
        <Button variant="ghost" size="icon" onClick={() => setOpen(true)} aria-label="Buka menu">
          <MenuIcon />
        </Button>
        <Link href="/admin" className="rounded-md text-lg">
          <Brand />
        </Link>
      </header>
      {auth ? (
        <Sheet open={open} onOpenChange={setOpen}>
          <SheetContent side="left" className="w-72 gap-0 bg-sidebar p-0">
            <SheetHeader className="border-b p-4">
              <SheetTitle className="text-lg">
                <Brand />
              </SheetTitle>
              <SheetDescription>Sudaya Admin</SheetDescription>
            </SheetHeader>
            <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-3">
              <AdminNav auth={auth} pathname={pathname} />
              <div className="mt-auto">
                <Profile auth={auth} />
              </div>
            </div>
          </SheetContent>
        </Sheet>
      ) : null}

      {/* Layar lebar: sidebar tetap. */}
      <aside className="hidden w-64 shrink-0 flex-col gap-6 border-r bg-sidebar p-4 lg:sticky lg:top-0 lg:flex lg:h-svh">
        <Link href="/admin" className="flex flex-col rounded-md px-1">
          <Brand className="text-lg" />
          <span className="text-xs text-muted-foreground">Sudaya Admin</span>
        </Link>
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto">{auth ? <AdminNav auth={auth} pathname={pathname} /> : null}</div>
        {auth ? <Profile auth={auth} /> : null}
      </aside>

      <main id="konten" tabIndex={-1} className="flex min-w-0 flex-1 flex-col gap-6 p-4 outline-none sm:p-6 lg:p-8">
        {breadcrumbs?.length ? <Breadcrumb items={breadcrumbs} /> : null}
        {children}
      </main>
    </div>
  );
}
