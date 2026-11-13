import { Link } from "@inertiajs/react";

import type { NavPage } from "@/lib/app-nav";
import { cn } from "@/lib/utils";

/**
 * Tab halaman di dalam satu menu (mis. Stok → Masuk | Keluar | Opname). Sama di laptop dan HP:
 * baris yang bisa digeser bila tidak muat. Tidak tampil bila menu itu hanya punya satu halaman.
 */
export function SectionTabs({
  pages,
  active,
  hrefFor = (page) => page.href,
  label,
  className,
}: {
  pages: NavPage[];
  active: NavPage | null;
  hrefFor?: (page: NavPage) => string;
  label: string;
  className?: string;
}) {
  if (pages.length < 2) return null;
  return (
    <nav aria-label={label} className={cn("print:hidden", className)} data-testid="section-tabs">
      <ul className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 [scrollbar-width:none]">
        {pages.map((page) => {
          const current = active?.href === page.href;
          return (
            <li key={page.href} className="shrink-0">
              <Link
                href={hrefFor(page)}
                aria-current={current ? "page" : undefined}
                className={cn(
                  "flex min-h-9 items-center rounded-full border px-3.5 text-sm font-medium whitespace-nowrap transition-colors pointer-coarse:min-h-11 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                  current ? "border-primary bg-primary text-primary-foreground" : "border-border bg-background text-foreground/80 hover:bg-muted hover:text-foreground",
                )}
              >
                {page.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
