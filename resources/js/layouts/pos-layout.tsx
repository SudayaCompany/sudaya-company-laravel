import { Link, router, usePage } from "@inertiajs/react";
import { LayoutDashboardIcon, Volume2Icon } from "lucide-react";
import { useMemo } from "react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { NewOrderAlert } from "@/components/orders/new-order-alert";
import { SectionTabs } from "@/components/section-tabs";
import { buildAlarmItems, useAlarmFeed, useNewOrderAlarm } from "@/hooks/use-order-alarm";
import { has, locate, POS_SECTIONS, visibleSections, type NavPage } from "@/lib/app-nav";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

/**
 * Kerangka aplikasi kasir: satu set menu (Pesanan · Kasir · Kas · Absen) yang sama di laptop dan HP.
 * Laptop: menu sebaris di header. HP: baris kedua header (bawah layar dipakai keranjang Kasir).
 * Halaman turunan satu menu (Pesanan → Masuk | Hari ini) tampil sebagai tab. ?outlet= ikut terbawa.
 */
export function PosLayout({
  outletId,
  tabsClassName = "px-3 pt-3 sm:px-4",
  children,
}: {
  outletId?: string;
  /** Lebar baris tab disamakan dengan lebar konten halaman. */
  tabsClassName?: string;
  children: React.ReactNode;
}) {
  const { url, props } = usePage<SharedProps>();
  const pathname = url.split("?")[0];
  const auth = props.auth;
  const sections = auth ? visibleSections(POS_SECTIONS, auth) : [];
  const here = locate(sections, pathname);
  const hrefFor = (page: NavPage) => (outletId && !page.href.startsWith("/staff") ? `${page.href}?outlet=${outletId}` : page.href);
  // Logo kembali ke menu pertama yang memang boleh dibuka (Owner tidak punya akses Kasir).
  const home = sections[0] ? hrefFor(sections[0].pages[0]) : auth && has(auth, "/admin") ? "/admin" : "/login";
  const mobileBar = sections.length > 1;

  // Alarm pesanan baru di semua halaman kasir (keputusan owner 12 November 2026). Layar Pesanan masuk
  // punya alarm sendiri dari datanya; status "sudah dilihat" & izin suara dipakai bersama.
  const onKds = pathname === "/kds" || pathname.startsWith("/kds/");
  const alarmEnabled = !!auth && !onKds && has(auth, "/kds") && auth.permissions.includes("order.update_status");
  const feed = useAlarmFeed(alarmEnabled, outletId);
  const alarmItems = useMemo(() => (feed ? buildAlarmItems(feed.queued, feed.awaiting) : null), [feed]);
  const alarm = useNewOrderAlarm(alarmItems, alarmEnabled);
  const kdsHref = outletId ? `/kds?outlet=${outletId}` : "/kds";

  return (
    <div className="flex min-h-svh flex-1 flex-col bg-muted/30">
      <header className="sticky top-0 z-30 bg-espresso text-espresso-foreground print:hidden">
        <div className="flex h-14 items-center gap-3 px-3 sm:gap-4 sm:px-4">
          <Link href={home} className="flex min-h-9 shrink-0 items-center gap-2 pointer-coarse:min-h-11">
            <Brand tone="light" className="text-base" />
            <span className="hidden text-xs text-neutral-400 sm:inline">POS</span>
          </Link>
          <nav aria-label="Menu kasir" className="hidden min-w-0 flex-1 gap-1 md:flex">
            {sections.map((section) => {
              const active = here?.section.key === section.key;
              return (
                <Link
                  key={section.key}
                  href={hrefFor(section.pages[0])}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "flex min-h-9 shrink-0 items-center gap-2 rounded-lg px-3 text-sm font-medium transition-colors pointer-coarse:min-h-11 [&_svg]:size-4",
                    active ? "bg-white text-espresso" : "text-neutral-300 hover:bg-white/10 hover:text-white",
                  )}
                >
                  <section.icon aria-hidden /> {section.label}
                </Link>
              );
            })}
          </nav>
          <div className="flex-1 md:hidden" />
          {auth && has(auth, "/admin") ? (
            <Link
              href="/admin"
              className="flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-neutral-300 transition-colors pointer-coarse:min-h-11 hover:bg-white/10 hover:text-white [&_svg]:size-4"
            >
              <LayoutDashboardIcon aria-hidden /> Admin
            </Link>
          ) : null}
          <span className="hidden text-sm text-neutral-300 lg:inline">{auth?.user.fullName}</span>
          <LogoutButton variant="ghost" />
        </div>
        {mobileBar ? (
          <nav
            aria-label="Menu kasir"
            className="grid border-t border-white/10 md:hidden"
            style={{ gridTemplateColumns: `repeat(${sections.length}, minmax(0, 1fr))` }}
          >
            {sections.map((section) => {
              const active = here?.section.key === section.key;
              return (
                <Link
                  key={section.key}
                  href={hrefFor(section.pages[0])}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "flex h-14 flex-col items-center justify-center gap-0.5 border-b-2 text-xs font-medium transition-colors [&_svg]:size-5",
                    active ? "border-brand bg-white/10 text-white" : "border-transparent text-neutral-300 hover:text-white",
                  )}
                >
                  <section.icon aria-hidden />
                  {section.label}
                </Link>
              );
            })}
          </nav>
        ) : null}
      </header>
      {alarmEnabled && !alarm.armed ? (
        <button
          type="button"
          onClick={alarm.arm}
          className="flex min-h-11 w-full items-center justify-center gap-2 border-b border-brand/40 bg-brand-soft px-4 text-sm font-semibold text-brand-strong print:hidden"
          data-testid="alarm-arm-bar"
        >
          <Volume2Icon className="size-4" aria-hidden /> Ketuk untuk mengaktifkan suara pesanan baru
        </button>
      ) : null}
      {alarmEnabled && alarm.fresh.length > 0 ? (
        <div className={cn("sticky z-20 px-3 pt-3 sm:px-4 print:hidden", mobileBar ? "top-28 md:top-14" : "top-14")}>
          <NewOrderAlert
            items={alarm.fresh}
            onLater={alarm.acknowledge}
            onView={() => {
              alarm.acknowledge();
              router.visit(kdsHref);
            }}
          />
        </div>
      ) : null}
      {here && here.section.pages.length > 1 ? (
        <SectionTabs
          pages={here.section.pages}
          active={here.page}
          hrefFor={hrefFor}
          label={`Halaman ${here.section.label}`}
          className={tabsClassName}
        />
      ) : null}
      <div className="flex flex-1 flex-col">{children}</div>
    </div>
  );
}
