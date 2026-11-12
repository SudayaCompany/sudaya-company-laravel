/** Label scope grant, mis. "Semua outlet" atau nama outlet. */
export function scopeLabel(outletId: string | null, outletNames: ReadonlyMap<string, string>) {
  if (outletId === null) return "Semua outlet";
  return outletNames.get(outletId) ?? "Outlet lain";
}

const rupiah = new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
});

/** 25000 → "Rp 25.000" */
export function formatRupiah(amount: number) {
  // Intl memakai spasi tak terputus; disamakan dengan Next.js agar teks mudah dicari.
  return rupiah.format(amount).replace(/ /g, " ");
}

/** Menu yang harganya belum diisi admin (Rp0) tampil "Harga menyusul" dan belum bisa dipesan. */
export function hasMenuPrice(price: number) {
  return price > 0;
}

export function formatMenuPrice(price: number) {
  return hasMenuPrice(price) ? formatRupiah(price) : "Harga menyusul";
}

/** Selisih harga modifier: 5000 → "+Rp 5.000", 0 → "" */
export function formatPriceDelta(delta: number) {
  if (delta === 0) return "";
  return `${delta > 0 ? "+" : "−"}${formatRupiah(Math.abs(delta))}`;
}

const qtyFormat = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 3 });

/** Kuantitas stok dari DB (numeric sebagai string): "12.500" → "12,5 kg" */
export function formatQty(value: string, unit?: string | null) {
  const text = qtyFormat.format(Number(value));
  return unit ? `${text} ${unit}` : text;
}
