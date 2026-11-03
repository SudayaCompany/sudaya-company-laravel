import { Head, Link, usePage } from "@inertiajs/react";
import {
  BriefcaseIcon,
  ClipboardCheckIcon,
  MonitorIcon,
  PackageIcon,
  ScanFaceIcon,
  ShoppingBagIcon,
  type LucideIcon,
} from "lucide-react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import type { AccessibleOutlet, SharedProps } from "@/types";

type Shortcut = {
  label: string;
  description: string;
  icon: LucideIcon;
  permission: string;
  /** null = modul belum dipindahkan ke Laravel. */
  href: string | null;
};

const SHORTCUTS: Shortcut[] = [
  {
    label: "Absensi",
    description: "Check-in & check-out dengan wajah + lokasi",
    icon: ClipboardCheckIcon,
    permission: "attendance.checkin",
    href: null,
  },
  {
    label: "Tugas luar",
    description: "Ajukan tugas di luar outlet",
    icon: BriefcaseIcon,
    permission: "attendance.checkin",
    href: null,
  },
  {
    label: "Registrasi wajah",
    description: "Persetujuan & status data wajah",
    icon: ScanFaceIcon,
    permission: "attendance.checkin",
    href: null,
  },
  {
    label: "Order / POS",
    description: "Input pesanan customer & pembayaran",
    icon: ShoppingBagIcon,
    permission: "order.create",
    href: "/pos",
  },
  {
    label: "Barista Display",
    description: "Antrean produksi New → Ready",
    icon: MonitorIcon,
    permission: "kds.view",
    href: "/kds",
  },
  {
    label: "Stok",
    description: "Cek stok, catat stok keluar & waste",
    icon: PackageIcon,
    permission: "inventory.view",
    href: null,
  },
];

export default function StaffHome({ outlets, permissions }: { outlets: AccessibleOutlet[]; permissions: string[] }) {
  const user = usePage<SharedProps>().props.auth!.user;
  const held = new Set(permissions);
  const shortcuts = SHORTCUTS.filter((s) => held.has(s.permission));

  return (
    <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-6 px-4 py-6">
      <Head title="Staff" />
      <header className="flex items-center justify-between">
        <Brand className="text-lg" />
        <LogoutButton variant="ghost" />
      </header>

      <section className="flex flex-col gap-2 rounded-xl bg-neutral-950 p-5 text-white">
        <p className="text-sm text-neutral-400">Selamat bertugas,</p>
        <p className="text-xl font-semibold">{user.fullName}</p>
        <div className="flex flex-wrap gap-1.5">
          {user.roles.map((role) => (
            <Badge key={role} className="bg-brand text-brand-foreground">
              {role}
            </Badge>
          ))}
        </div>
        <p className="text-sm text-neutral-300">
          {outlets.length === 0 ? "Belum ada outlet aktif" : outlets.map((o) => o.name).join(", ")}
        </p>
      </section>

      <section className="grid gap-3">
        {shortcuts.map((shortcut) => {
          const content = (
            <>
              <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                <shortcut.icon className="size-5" aria-hidden />
              </span>
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="font-medium">{shortcut.label}</span>
                <span className="text-xs text-muted-foreground">{shortcut.description}</span>
              </span>
              {shortcut.href ? null : <Badge variant="outline">Segera hadir</Badge>}
            </>
          );
          const className = cn(
            "flex items-center gap-3 rounded-xl border p-4",
            shortcut.href ? "transition-colors hover:border-brand" : "opacity-60",
          );
          return shortcut.href ? (
            <Link key={shortcut.label} href={shortcut.href} className={className}>
              {content}
            </Link>
          ) : (
            <div key={shortcut.label} className={className} aria-disabled>
              {content}
            </div>
          );
        })}
      </section>
    </div>
  );
}
