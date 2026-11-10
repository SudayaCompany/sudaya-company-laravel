/**
 * Tautan "Kabari via WhatsApp" (tanpa API berbayar): membuka WhatsApp staf dengan pesan siap kirim.
 * Nomor dari checkout pelanggan (opsional); 08xxx → 628xxx.
 */
export function whatsappReadyLink(phone: string | null | undefined, order: { orderNumber: string | null; customerName: string | null; deskDelivery: boolean }) {
  if (!phone) return null;
  const digits = phone.replace(/\D/g, "");
  const intl = digits.startsWith("0") ? `62${digits.slice(1)}` : digits;
  if (!/^62\d{8,13}$/.test(intl)) return null;
  const name = order.customerName ? ` ${order.customerName}` : "";
  const text = order.deskDelivery
    ? `Halo${name}, pesanan ${order.orderNumber ?? ""} dari Sudaya Coffee sudah siap dan sedang diantar ke meja Anda. Terima kasih!`
    : `Halo${name}, pesanan ${order.orderNumber ?? ""} dari Sudaya Coffee sudah siap. Silakan ambil di counter. Terima kasih!`;
  return `https://wa.me/${intl}?text=${encodeURIComponent(text)}`;
}
