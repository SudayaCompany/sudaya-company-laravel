/**
 * Tautan "Kabari via WhatsApp" (tanpa API berbayar): membuka WhatsApp staf dengan pesan siap kirim.
 * Nomor dari checkout pelanggan (opsional); 08xxx → 628xxx.
 */
export function whatsappReadyLink(
  phone: string | null | undefined,
  order: { orderNumber: string | null; customerName: string | null; deskDelivery: boolean; locationNote?: string | null; cashDue?: string | null },
) {
  if (!phone) return null;
  const digits = phone.replace(/\D/g, "");
  const intl = digits.startsWith("0") ? `62${digits.slice(1)}` : digits;
  if (!/^62\d{8,13}$/.test(intl)) return null;
  const name = order.customerName ? ` ${order.customerName}` : "";
  const where = order.locationNote ? ` ke ${order.locationNote}` : " ke meja Anda";
  // Bayar tunai saat diantar: pelanggan diingatkan menyiapkan uangnya.
  const cash = order.cashDue ? ` Mohon siapkan uang tunai ${order.cashDue} ya.` : "";
  const text = order.deskDelivery
    ? `Halo${name}, pesanan ${order.orderNumber ?? ""} dari Sudaya Coffee sedang diantar${where}.${cash} Terima kasih!`
    : `Halo${name}, pesanan ${order.orderNumber ?? ""} dari Sudaya Coffee sudah siap. Silakan ambil di counter. Terima kasih!`;
  return `https://wa.me/${intl}?text=${encodeURIComponent(text)}`;
}
