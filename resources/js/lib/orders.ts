// Label status order (PRD §107–108), padanan lib/orders/state.ts. Status produksi
// (status) terpisah dari status pembayaran (paymentStatus).

export type OrderStatus = "draft" | "pending_payment" | "paid" | "queued" | "preparing" | "ready" | "completed" | "cancelled" | "refunded";
export type PaymentStatus = "pending" | "paid" | "failed" | "expired" | "refunded" | "partial_refund";
export type OrderChannel = "pos" | "qr_table" | "qr_counter" | "website" | "kiosk";
export type OrderType = "dine_in" | "takeaway" | "delivery";

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  draft: "Draft",
  pending_payment: "Menunggu bayar",
  paid: "Dibayar",
  queued: "Antre",
  preparing: "Dibuat",
  ready: "Siap",
  completed: "Selesai",
  cancelled: "Dibatalkan",
  refunded: "Refund",
};

export const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  pending: "Belum dibayar",
  paid: "Lunas",
  failed: "Gagal",
  expired: "Tidak dibayar",
  refunded: "Dikembalikan",
  partial_refund: "Refund sebagian",
};

export const CHANNEL_LABELS: Record<OrderChannel, string> = {
  pos: "POS",
  qr_table: "QR Meja",
  qr_counter: "QR Kasir",
  website: "Web",
  kiosk: "Kiosk",
};

export const ORDER_TYPE_LABELS: Record<OrderType, string> = {
  dine_in: "Dine In",
  takeaway: "Takeaway",
  delivery: "Delivery",
};

/** Label untuk customer (halaman status). */
export function customerStatusLabel(status: OrderStatus) {
  switch (status) {
    case "draft":
    case "pending_payment":
      return "Menunggu pembayaran";
    case "paid":
    case "queued":
      return "Pesanan diterima";
    case "preparing":
      return "Sedang dibuat";
    case "ready":
      return "Siap diambil";
    case "completed":
      return "Selesai";
    case "cancelled":
    case "refunded":
      return "Dibatalkan";
  }
}

/** Idempotency key sudah dipakai order dengan isi berbeda; client wajib membuat key baru. */
export const ORDER_CONFLICT_MESSAGE =
  "Pesanan ini sudah tercatat dengan data berbeda, jadi perubahan tidak disimpan. Cek daftar pesanan atau tanyakan ke kasir dulu. Kirim lagi hanya jika memang perlu pesanan baru.";

/**
 * Metode bayar. Belum ada payment gateway (EXT-001), jadi QRIS tampil tidak aktif dan
 * tidak ada jalur yang bisa menandai order lunas tanpa konfirmasi pembayaran sah.
 */
export const PAYMENT_OPTIONS = [
  { method: "cash", label: "Tunai", available: true, note: "Bayar di kasir" },
  { method: "qris", label: "QRIS", available: false, note: "Belum tersedia" },
] as const;

export type OrderLine = {
  id: string;
  productName: string;
  quantity: number;
  unitPrice: number;
  modifiersTotal: number;
  lineTotal: number;
  notes: string | null;
  modifiers: { groupName: string; name: string; priceDelta: number }[];
};

/** Detail order untuk staf (OrderEngine::detail). */
export type StaffOrder = {
  id: string;
  reference: string;
  orderNumber: string | null;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  type: OrderType;
  channel: OrderChannel;
  tableNumber: string | null;
  customerName: string | null;
  notes: string | null;
  total: number;
  createdAt: string;
  outletId: string;
  customerPhone: string | null;
  locationNote: string | null;
  deliveryAddress: string | null;
  cancelReason: string | null;
  expiresAt: string | null;
  paidAt: string | null;
  pendingApproval: { id: string; reason: string; requestedBy: string; requestedByName: string; createdAt: string } | null;
  outlet: { id: string; name: string; timezone: string };
  lines: OrderLine[];
  payments: {
    id: string;
    method: string;
    status: PaymentStatus;
    amount: number;
    tenderedAmount: number | null;
    changeAmount: number | null;
    paidAt: string | null;
    confirmedByName: string | null;
  }[];
  history: { id: string; action: string; createdAt: string; reason: string | null; actorName: string | null }[];
  createdByName: string | null;
};

function xsrfToken() {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : "";
}

export type JsonResult<T> = ({ ok: true } & T) | { ok: false; error: string; retryAfter?: number };

/** POST JSON ke server dengan token CSRF; error jaringan dan sesi kedaluwarsa jadi pesan yang bisa dibaca. */
export async function postJson<T>(url: string, body: unknown): Promise<JsonResult<T>> {
  try {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
      body: JSON.stringify(body),
      credentials: "same-origin",
      cache: "no-store",
    });
    const data = (await response.json().catch(() => null)) as JsonResult<T> | null;
    if (data && typeof data.ok === "boolean") return data;
    if (response.status === 419) return { ok: false, error: "Halaman kedaluwarsa. Muat ulang lalu coba lagi." };
    if (response.status === 401) return { ok: false, error: "Sesi berakhir. Silakan login ulang." };
    return { ok: false, error: "Pesanan gagal dikirim. Coba lagi." };
  } catch {
    return { ok: false, error: "Koneksi terputus. Periksa internet lalu coba lagi." };
  }
}

export type PlaceOrderResult = JsonResult<{ orderId: string; reference: string; total: number }>;

/** Checkout guest ke server. Idempotency key dipertahankan pemanggil, jadi retry tidak membuat order ganda. */
export function placeOrder(input: { outlet: string; channel: OrderChannel; checkout: Record<string, unknown> }): Promise<PlaceOrderResult> {
  return postJson("/order/checkout", input);
}
