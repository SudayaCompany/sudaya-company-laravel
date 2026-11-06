/** Utilitas tampilan uang tunai. Validasi dan perhitungan sah tetap di server. */

/** Ambil angka dari input bebas ("Rp 50.000" → "50000"). */
export function digitsOnly(value: string) {
  return value.replace(/\D/g, "").replace(/^0+(?=\d)/, "").slice(0, 12);
}

/** Nilai rupiah bulat dari string angka; null bila kosong. */
export function amountFromDigits(digits: string): number | null {
  return digits === "" ? null : Number(digits);
}

/** "50000" → "50.000" (pemisah ribuan id-ID). */
export function groupThousands(digits: string) {
  return digits === "" ? "" : Number(digits).toLocaleString("id-ID");
}

/** Label ringkas tombol nominal: 50.000 → "50rb", 1.500.000 → "1,5jt". */
export function shortRupiah(amount: number) {
  if (amount >= 1_000_000) return `${(amount / 1_000_000).toLocaleString("id-ID", { maximumFractionDigits: 1 })}jt`;
  if (amount >= 1_000 && amount % 1_000 === 0) return `${amount / 1_000}rb`;
  return amount.toLocaleString("id-ID");
}

/**
 * Saran uang yang biasa diterima kasir untuk total tertentu: pembulatan ke atas ke pecahan
 * 5rb, 10rb, 20rb, 50rb, dan 100rb (di luar "uang pas"). Hanya pintasan input.
 */
export function tenderSuggestions(total: number, max = 3) {
  if (total <= 0) return [];
  const steps = [5_000, 10_000, 20_000, 50_000, 100_000];
  const values = steps.map((step) => Math.ceil(total / step) * step).filter((v) => v > total);
  return [...new Set(values)].sort((a, b) => a - b).slice(0, max);
}
