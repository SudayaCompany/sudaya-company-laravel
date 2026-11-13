import {
  BadgeCheckIcon,
  ChartColumnIcon,
  CoffeeIcon,
  LayoutDashboardIcon,
  ReceiptTextIcon,
  SettingsIcon,
  ShoppingBagIcon,
  UserRoundCheckIcon,
  UsersIcon,
  WalletIcon,
  WarehouseIcon,
  type LucideIcon,
} from "lucide-react";

import type { SharedProps } from "@/types";

/**
 * Peta menu satu sumber untuk semua tampilan (keputusan owner 13 November 2026: satu aplikasi per peran,
 * tanpa menu yang berulang, sama persis di laptop dan HP). Setiap peran punya satu kerangka:
 * - Kasir   : Pesanan (Masuk | Hari ini) · Kasir · Kas (Kas | Pengeluaran) · Absen
 * - Barista : Absen (Absen | Riwayat | Tugas luar)
 * - Admin   : 9 menu di sidebar; halaman turunannya jadi tab di dalam menu itu.
 * Menu hanya tampil bila user punya aksesnya; setiap halaman tetap memeriksa izin di server.
 */
export type Auth = NonNullable<SharedProps["auth"]>;

export type NavPage = {
  href: string;
  label: string;
  visible: (auth: Auth) => boolean;
  /** Hanya aktif di path ini persis (halaman induk yang punya halaman turunan sendiri). */
  exact?: boolean;
  /** Path lain yang menampilkan halaman yang sama (mis. /barista = Absen). */
  aliases?: string[];
};

export type NavSection = { key: string; label: string; icon: LucideIcon; pages: NavPage[] };

export const has = (auth: Auth, path: string) => auth.navigation.includes(path);
const can = (path: string) => (auth: Auth) => has(auth, path);

/** Absen milik sendiri; dipakai kasir, barista, dan admin di kerangka masing-masing. */
export const STAFF_PAGES: NavPage[] = [
  { href: "/staff/attendance", label: "Absen", visible: can("/staff/attendance"), exact: true, aliases: ["/barista"] },
  { href: "/staff/attendance/history", label: "Riwayat", visible: can("/staff/attendance") },
  { href: "/staff/outside-duty", label: "Tugas luar", visible: can("/staff/outside-duty") },
];

/** Kerangka kasir. Absen hanya untuk akun tanpa area admin (admin membuka Absen dari kerangka admin). */
export const POS_SECTIONS: NavSection[] = [
  {
    key: "orders",
    label: "Pesanan",
    icon: ReceiptTextIcon,
    pages: [
      { href: "/kds", label: "Masuk", visible: can("/kds") },
      { href: "/pos/orders", label: "Hari ini", visible: can("/pos") },
    ],
  },
  { key: "pos", label: "Kasir", icon: ShoppingBagIcon, pages: [{ href: "/pos", label: "Kasir", visible: can("/pos"), exact: true }] },
  {
    key: "cash",
    label: "Kas",
    icon: WalletIcon,
    pages: [
      { href: "/pos/cash", label: "Kas", visible: (auth) => has(auth, "/pos") && auth.permissions.includes("cash.view") },
      // Kasir mencatat pengeluaran kecil (parkir, transport, belanja + foto nota); admin lewat Keuangan.
      { href: "/admin/expenses", label: "Pengeluaran", visible: (auth) => !has(auth, "/admin") && has(auth, "/admin/expenses") },
    ],
  },
  {
    key: "attendance",
    label: "Absen",
    icon: UserRoundCheckIcon,
    pages: STAFF_PAGES.map((page) => ({ ...page, visible: (auth: Auth) => !has(auth, "/admin") && page.visible(auth) })),
  },
];

/** Kerangka admin: 9 menu. "Absen saya" tidak di sidebar (dibuka dari tombol di bawah profil). */
export const ADMIN_SECTIONS: NavSection[] = [
  { key: "dashboard", label: "Dasbor", icon: LayoutDashboardIcon, pages: [{ href: "/admin", label: "Dasbor", visible: can("/admin"), exact: true }] },
  { key: "orders", label: "Pesanan", icon: ReceiptTextIcon, pages: [{ href: "/admin/orders", label: "Pesanan hari ini", visible: can("/admin/orders") }] },
  {
    key: "finance",
    label: "Keuangan",
    icon: WalletIcon,
    pages: [
      { href: "/admin/cash", label: "Kas", visible: can("/admin/cash") },
      { href: "/admin/expenses", label: "Pengeluaran", visible: can("/admin/expenses") },
    ],
  },
  { key: "approvals", label: "Persetujuan", icon: BadgeCheckIcon, pages: [{ href: "/admin/approvals", label: "Persetujuan", visible: can("/admin/approvals") }] },
  {
    key: "reports",
    label: "Laporan",
    icon: ChartColumnIcon,
    pages: [
      { href: "/admin/reports", label: "Penjualan", visible: can("/admin/reports") },
      { href: "/admin/customers", label: "Pelanggan", visible: can("/admin/customers") },
    ],
  },
  {
    key: "menu",
    label: "Menu",
    icon: CoffeeIcon,
    pages: [
      { href: "/admin/products", label: "Menu", visible: can("/admin/products") },
      { href: "/admin/categories", label: "Kategori", visible: can("/admin/categories") },
      { href: "/admin/modifiers", label: "Varian & tambahan", visible: can("/admin/modifiers") },
    ],
  },
  {
    key: "inventory",
    label: "Stok",
    icon: WarehouseIcon,
    pages: [
      { href: "/admin/inventory", label: "Stok", visible: can("/admin/inventory"), exact: true },
      { href: "/admin/inventory/stock-in", label: "Masuk", visible: can("/admin/inventory/stock-in") },
      { href: "/admin/inventory/stock-out", label: "Keluar & waste", visible: can("/admin/inventory/stock-out") },
      { href: "/admin/inventory/opname", label: "Opname", visible: can("/admin/inventory/opname") },
      { href: "/admin/inventory/movements", label: "Riwayat", visible: can("/admin/inventory/movements") },
      { href: "/admin/inventory/recipes", label: "Resep", visible: can("/admin/inventory/recipes") },
      { href: "/admin/purchase-orders", label: "Purchase order", visible: can("/admin/purchase-orders") },
      { href: "/admin/suppliers", label: "Supplier", visible: can("/admin/suppliers") },
    ],
  },
  {
    key: "employees",
    label: "Karyawan",
    icon: UsersIcon,
    pages: [
      { href: "/admin/attendance", label: "Kehadiran", visible: can("/admin/attendance"), exact: true },
      { href: "/admin/attendance/reports", label: "Rekap absensi", visible: can("/admin/attendance/reports") },
      { href: "/admin/employees", label: "Data karyawan", visible: can("/admin/employees") },
      { href: "/admin/attendance/settings", label: "Pengaturan absen", visible: can("/admin/attendance/settings") },
    ],
  },
  {
    key: "settings",
    label: "Pengaturan",
    icon: SettingsIcon,
    pages: [
      { href: "/admin/outlets", label: "Outlet & meja", visible: can("/admin/outlets") },
      { href: "/admin/site", label: "Konten situs", visible: can("/admin/site") },
      { href: "/admin/users", label: "Staf", visible: can("/admin/users") },
      { href: "/admin/roles", label: "Peran & izin", visible: can("/admin/roles") },
      { href: "/admin/audit", label: "Log aktivitas", visible: can("/admin/audit") },
    ],
  },
];

/** Absen milik admin sendiri: tidak di sidebar, tetapi halaman /staff/* tetap punya tab di kerangka admin. */
export const ADMIN_PERSONAL: NavSection = { key: "me", label: "Absen saya", icon: UserRoundCheckIcon, pages: STAFF_PAGES };

function matches(page: NavPage, pathname: string): number {
  for (const path of [page.href, ...(page.aliases ?? [])]) {
    if (pathname === path) return path.length + 1;
    if (!page.exact && pathname.startsWith(`${path}/`)) return path.length;
  }
  return -1;
}

/** Bagian yang terlihat oleh user, halaman yang tidak boleh dibuka dibuang. */
export function visibleSections(sections: NavSection[], auth: Auth): NavSection[] {
  return sections.map((s) => ({ ...s, pages: s.pages.filter((p) => p.visible(auth)) })).filter((s) => s.pages.length > 0);
}

/** Halaman (dan bagiannya) yang paling cocok dengan path saat ini; path terpanjang menang. */
export function locate(sections: NavSection[], pathname: string): { section: NavSection; page: NavPage } | null {
  let best: { section: NavSection; page: NavPage; score: number } | null = null;
  for (const section of sections) {
    for (const page of section.pages) {
      const score = matches(page, pathname);
      if (score >= 0 && (best === null || score > best.score)) best = { section, page, score };
    }
  }
  return best ? { section: best.section, page: best.page } : null;
}

/** Kerangka yang dipakai halaman milik semua peran (Absen): admin → kasir → staf. */
export function shellFor(auth: Auth | null | undefined): "admin" | "pos" | "staff" {
  if (!auth) return "staff";
  if (has(auth, "/admin")) return "admin";
  if (has(auth, "/pos") || has(auth, "/kds")) return "pos";
  return "staff";
}

/** Halaman pertama sesuai peran (sama urutannya dengan pintu /login). */
export function homeFor(auth: Auth): string | null {
  for (const path of ["/admin", "/kds", "/pos", "/staff/attendance"]) {
    if (has(auth, path)) return path;
  }
  return null;
}
