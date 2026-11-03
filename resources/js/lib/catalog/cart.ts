import { useCallback, useEffect, useState } from "react";

export type CartLine = { productId: string; modifierIds: string[]; quantity: number; notes?: string | null };

export type QuoteLine = CartLine &
  (
    | {
        ok: true;
        name: string;
        basePrice: number;
        modifiers: { modifierId: string; groupName: string; name: string; priceDelta: number }[];
        unitPrice: number;
        lineTotal: number;
      }
    | { ok: false; name: string | null; error: string }
  );

export type CartQuote = { ok: true; lines: QuoteLine[]; total: number; itemCount: number } | { ok: false; error: string };

/** Cek bentuk minimal (localStorage bisa diubah manual). Validasi penuh di server. */
function isCartLine(value: unknown): value is CartLine {
  const line = value as Partial<CartLine> | null;
  return (
    typeof line?.productId === "string" &&
    Array.isArray(line.modifierIds) &&
    line.modifierIds.every((id) => typeof id === "string") &&
    Number.isInteger(line.quantity)
  );
}

/** Keranjang per outlet di localStorage. Hanya id, jumlah, dan catatan; harga selalu dari server. */
export function useCartLines(outlet: string) {
  const key = `sudaya-cart:${outlet}`;
  const [lines, setLines] = useState<CartLine[]>([]);

  useEffect(() => {
    try {
      const parsed: unknown = JSON.parse(window.localStorage.getItem(key) ?? "[]");
      setLines(Array.isArray(parsed) ? parsed.filter(isCartLine) : []);
    } catch {
      setLines([]);
    }
  }, [key]);

  const save = useCallback(
    (next: CartLine[]) => {
      setLines(next);
      try {
        window.localStorage.setItem(key, JSON.stringify(next));
      } catch {
        // Mode privat atau penyimpanan penuh: keranjang tetap jalan di memori.
      }
    },
    [key],
  );

  return { lines, save };
}

function xsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : "";
}

/** Harga dan ketersediaan terbaru dari server. Harga di browser hanya tampilan. */
export async function requestQuote(outlet: string, lines: CartLine[]): Promise<CartQuote> {
  try {
    const response = await fetch("/order/quote", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
      body: JSON.stringify({
        outlet,
        lines: lines.map(({ productId, modifierIds, quantity }) => ({ productId, modifierIds, quantity })),
      }),
    });
    const body = (await response.json()) as CartQuote;
    return body;
  } catch {
    return { ok: false, error: "Gagal menghubungi server. Periksa koneksi lalu coba lagi." };
  }
}
