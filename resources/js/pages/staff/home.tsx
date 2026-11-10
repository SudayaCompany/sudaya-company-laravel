import { Head, Link, usePage } from "@inertiajs/react";
import {
  ChevronRightIcon,
  CalendarCheckIcon,
  ArrowUpFromLineIcon,
  BriefcaseBusinessIcon,
  HandCoinsIcon,
  LayoutDashboardIcon,
  MonitorIcon,
  ReceiptTextIcon,
  ShoppingBagIcon,
  WalletIcon,
  type LucideIcon,
} from "lucide-react";

import { Brand } from "@/components/brand";
import { LogoutButton } from "@/components/logout-button";
import { Badge } from "@/components/ui/badge";
import type { AccessibleOutlet, SharedProps } from "@/types";

type Auth = NonNullable<SharedProps["auth"]>;
type Shortcut = {
  label: string;
  description: string;
  icon: LucideIcon;
  href: string;
  visible: (held: Set<string>, auth: Auth) => boolean;
};

// Hanya fitur yang sudah tersedia. Setiap halaman tetap memeriksa izin di server.
const SHORTCUTS: Shortcut[] = [
  {
    label: "Absen",
    description: "Absen masuk & pulang dengan GPS + selfie",
    icon: CalendarCheckIcon,
    href: "/staff/attendance",
    visible: (_, auth) => auth.navigation.includes("/staff/attendance"),
  },
  {
    label: "Tugas luar",
    description: "Ajukan tugas di luar outlet; absen dari lokasi tugas",
    icon: BriefcaseBusinessIcon,
    href: "/staff/outside-duty",
    visible: (_, auth) => auth.navigation.includes("/staff/outside-duty"),
  },
  { label: "Kasir", description: "Input pesanan dan terima pembayaran tunai", icon: ShoppingBagIcon, href: "/pos", visible: (_, auth) => auth.navigation.includes("/pos") },
  { label: "Pesanan hari ini", description: "Cari pesanan, bayar, batal, struk", icon: ReceiptTextIcon, href: "/pos/orders", visible: (_, auth) => auth.navigation.includes("/pos") },
  {
    label: "Kas",
    description: "Buka, catat, dan tutup sesi kas",
    icon: WalletIcon,
    href: "/pos/cash",
    visible: (held, auth) => auth.navigation.includes("/pos") && held.has("cash.view"),
  },
  {
    label: "Catat waste / stok keluar",
    description: "Bahan rusak, tumpah, kedaluwarsa, pemakaian internal",
    icon: ArrowUpFromLineIcon,
    href: "/admin/inventory/stock-out",
    visible: (_, auth) => auth.navigation.includes("/admin/inventory/stock-out"),
  },
  {
    label: "Catat pengeluaran",
    description: "Parkir, transport, pembelian kecil + foto nota",
    icon: HandCoinsIcon,
    href: "/admin/expenses",
    visible: (_, auth) => auth.navigation.includes("/admin/expenses"),
  },
  { label: "Barista display", description: "Antrean pembuatan pesanan", icon: MonitorIcon, href: "/kds", visible: (_, auth) => auth.navigation.includes("/kds") },
  { label: "Admin", description: "Dasbor dan pengaturan sesuai peran Anda", icon: LayoutDashboardIcon, href: "/admin", visible: (_, auth) => auth.navigation.includes("/admin") },
];

export default function StaffHome({ outlets, permissions }: { outlets: AccessibleOutlet[]; permissions: string[] }) {
  const auth = usePage<SharedProps>().props.auth!;
  const held = new Set(permissions);
  const shortcuts = SHORTCUTS.filter((s) => s.visible(held, auth));

  return (
    <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-6 px-4 py-6">
      <Head title="Beranda staf" />
      <header className="flex items-center justify-between">
        <Brand className="text-lg" />
        <LogoutButton variant="ghost" />
      </header>

      <section className="flex flex-col gap-2 rounded-2xl bg-espresso p-5 text-espresso-foreground">
        <p className="text-sm text-espresso-foreground/70">Selamat bertugas,</p>
        <p className="text-xl font-semibold">{auth.user.fullName}</p>
        <div className="flex flex-wrap gap-1.5">
          {auth.user.roles.map((role) => (
            <Badge key={role} className="bg-brand text-brand-foreground">
              {role}
            </Badge>
          ))}
        </div>
        <p className="text-sm text-espresso-foreground/80">
          {outlets.length === 0 ? "Belum ada outlet aktif" : outlets.map((o) => o.name).join(", ")}
        </p>
      </section>

      {shortcuts.length === 0 ? (
        <p className="rounded-xl border p-4 text-sm text-muted-foreground">
          Akun Anda belum punya akses ke fitur operasional. Hubungi admin untuk mengatur peran.
        </p>
      ) : (
        <nav aria-label="Menu staf" className="grid gap-3">
          {shortcuts.map((shortcut) => (
            <Link
              key={shortcut.href}
              href={shortcut.href}
              className="flex min-h-16 items-center gap-3 rounded-xl border p-4 transition-colors hover:border-brand focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none active:scale-[0.99]"
            >
              <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-muted">
                <shortcut.icon className="size-5" aria-hidden />
              </span>
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="font-medium">{shortcut.label}</span>
                <span className="text-sm text-muted-foreground">{shortcut.description}</span>
              </span>
              <ChevronRightIcon className="size-5 text-muted-foreground" aria-hidden />
            </Link>
          ))}
        </nav>
      )}
    </div>
  );
}
