/**
 * Halaman pesanan dan kas dipakai di dua area: kasir (/pos, layout POS) dan admin (/admin,
 * sidebar admin; mis. Owner yang memantau tanpa memakai kasir). Izin tetap dicek di server.
 */
export type Area = "pos" | "admin";

export function areaBase(area: Area = "pos") {
  return area === "admin" ? "/admin" : "/pos";
}
