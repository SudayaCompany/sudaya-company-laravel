import { Link, usePage } from "@inertiajs/react";
import { MenuIcon, MonitorIcon, UserRoundCheckIcon } from "lucide-react";
import { useState } from "react";

import { Brand } from "@/components/brand";
import { Breadcrumb, type Crumb } from "@/components/breadcrumb";
import { LogoutButton } from "@/components/logout-button";
import { SectionTabs } from "@/components/section-tabs";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { ADMIN_PERSONAL, ADMIN_SECTIONS, has, locate, visibleSections, type Auth, type NavSection } from "@/lib/app-nav";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

const navLink =
  "flex min-h-9 items-center gap-2.5 rounded-lg px-3 text-sm transition-colors pointer-coarse:min-h-11 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none [&_svg]:size-4 [&_svg]:shrink-0";

function AdminNav({ sections, activeKey }: { sections: NavSection[]; activeKey: string | null }) {
  return (
    <nav aria-label="Menu admin" className="flex flex-col gap-1">
      {sections.map((section) => {
        const active = section.key === activeKey;
        return (
          <Link
            key={section.key}
            href={section.pages[0].href}
            aria-current={active ? "page" : undefined}
            className={cn(navLink, active ? "bg-primary font-medium text-primary-foreground" : "text-foreground/80 hover:bg-sidebar-accent hover:text-foreground")}
          >
            <section.icon aria-hidden />
            <span className="flex-1">{section.label}</span>
          </Link>
        );
      })}
    </nav>
  );
}

/** Aplikasi lain milik user yang sama (kasir, absen sendiri): satu tempat, tidak dicampur di menu admin. */
function OtherApps({ auth, activeKey }: { auth: Auth; activeKey: string | null }) {
  const apps = [
    has(auth, "/kds") || has(auth, "/pos")
      ? { key: "pos", href: has(auth, "/kds") ? "/kds" : "/pos", label: "Buka aplikasi kasir", icon: MonitorIcon }
      : null,
    has(auth, "/staff/attendance") ? { key: "me", href: "/staff/attendance", label: "Absen saya", icon: UserRoundCheckIcon } : null,
  ].filter((app) => app !== null);
  if (apps.length === 0) return null;
  return (
    <div className="flex flex-col gap-1 border-t pt-3" data-testid="admin-other-apps">
      {apps.map((app) => (
        <Link
          key={app.key}
          href={app.href}
          aria-current={app.key === activeKey ? "page" : undefined}
          className={cn(navLink, app.key === activeKey ? "bg-primary font-medium text-primary-foreground" : "text-foreground/80 hover:bg-sidebar-accent hover:text-foreground")}
        >
          <app.icon aria-hidden /> {app.label}
        </Link>
      ))}
    </div>
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
 * Kerangka admin: 9 menu di sidebar (layar ≥ 1024 px) atau drawer (layar lebih kecil) dengan isi yang
 * sama persis; halaman turunan satu menu tampil sebagai tab di atas konten di kedua ukuran layar.
 */
export function AdminLayout({ breadcrumbs, children }: { breadcrumbs?: Crumb[]; children: React.ReactNode }) {
  const { url, props } = usePage<SharedProps>();
  const auth = props.auth;
  const pathname = url.split("?")[0];
  const [open, setOpen] = useState(false);
  const sections = auth ? visibleSections(ADMIN_SECTIONS, auth) : [];
  const personal = auth ? visibleSections([ADMIN_PERSONAL], auth) : [];
  const here = locate([...sections, ...personal], pathname);
  const activeKey = here?.section.key ?? null;

  const menu = auth ? (
    <>
      <AdminNav sections={sections} activeKey={activeKey} />
      <OtherApps auth={auth} activeKey={activeKey} />
    </>
  ) : null;

  return (
    <div className="flex min-h-svh flex-1 flex-col lg:flex-row">
      <a
        href="#konten"
        className="sr-only z-50 rounded-lg bg-background px-4 py-2 shadow-lg focus:not-sr-only focus:fixed focus:top-3 focus:left-3 print:hidden"
      >
        Langsung ke konten
      </a>

      {/* Layar kecil: bar atas + drawer berisi menu yang sama dengan sidebar. */}
      <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b bg-background/95 px-3 backdrop-blur lg:hidden print:hidden">
        <Button variant="ghost" size="icon" onClick={() => setOpen(true)} aria-label="Buka menu">
          <MenuIcon />
        </Button>
        <Link href="/admin" className="rounded-md text-lg">
          <Brand />
        </Link>
        {here ? <span className="ml-auto truncate text-sm font-medium text-muted-foreground">{here.section.label}</span> : null}
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
            <div className="flex flex-1 flex-col gap-3 overflow-y-auto p-3">
              {menu}
              <div className="mt-auto">
                <Profile auth={auth} />
              </div>
            </div>
          </SheetContent>
        </Sheet>
      ) : null}

      {/* Layar lebar: sidebar tetap. */}
      <aside className="hidden w-64 shrink-0 flex-col gap-6 border-r bg-sidebar p-4 lg:sticky lg:top-0 lg:flex lg:h-svh print:hidden">
        <Link href="/admin" className="flex flex-col rounded-md px-1">
          <Brand className="text-lg" />
          <span className="text-xs text-muted-foreground">Sudaya Admin</span>
        </Link>
        <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto">{menu}</div>
        {auth ? <Profile auth={auth} /> : null}
      </aside>

      <main id="konten" tabIndex={-1} className="flex min-w-0 flex-1 flex-col gap-6 p-4 outline-none sm:p-6 lg:p-8 print:p-0">
        {here && here.section.pages.length > 1 ? <SectionTabs pages={here.section.pages} active={here.page} label={`Halaman ${here.section.label}`} /> : null}
        {breadcrumbs?.length ? <Breadcrumb items={breadcrumbs} /> : null}
        {children}
      </main>
    </div>
  );
}
