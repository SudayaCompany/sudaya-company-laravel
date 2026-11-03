import { Link, usePage } from "@inertiajs/react";
import { KeyRoundIcon, LayoutDashboardIcon, ScrollTextIcon, UsersIcon } from "lucide-react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

const NAV = [
  { href: "/admin", label: "Dashboard", icon: <LayoutDashboardIcon /> },
  { href: "/admin/users", label: "Pengguna", icon: <UsersIcon /> },
  { href: "/admin/roles", label: "Role & Permission", icon: <KeyRoundIcon /> },
  { href: "/admin/audit", label: "Audit Log", icon: <ScrollTextIcon /> },
];

export function AdminLayout({ children }: { children: React.ReactNode }) {
  const { url, props } = usePage<SharedProps>();
  const auth = props.auth;
  const pathname = url.split("?")[0];
  const items = NAV.filter((item) => auth?.navigation.includes(item.href));

  return (
    <div className="flex min-h-svh flex-1 flex-col md:flex-row">
      <aside className="flex flex-col gap-4 border-b bg-muted/30 p-4 md:sticky md:top-0 md:h-svh md:w-64 md:shrink-0 md:border-r md:border-b-0">
        <Link href="/admin" className="flex flex-col">
          <Brand className="text-lg" />
          <span className="text-xs text-muted-foreground">Sudaya Admin</span>
        </Link>
        <nav aria-label="Menu admin" className="flex gap-1 overflow-x-auto md:flex-col">
          {items.map((item) => {
            const active =
              item.href === "/admin"
                ? pathname === "/admin"
                : pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <Link
                key={item.href}
                href={item.href}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors [&_svg]:size-4",
                  active
                    ? "bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground",
                )}
              >
                {item.icon}
                {item.label}
              </Link>
            );
          })}
        </nav>
        <div className="mt-auto hidden flex-col gap-3 border-t pt-4 md:flex">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{auth?.user.fullName}</p>
            <p className="truncate text-xs text-muted-foreground">
              {auth?.user.roles.join(", ") || "Tanpa role"}
            </p>
          </div>
          <LogoutButton />
        </div>
      </aside>
      <main className="flex min-w-0 flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">{children}</main>
    </div>
  );
}
