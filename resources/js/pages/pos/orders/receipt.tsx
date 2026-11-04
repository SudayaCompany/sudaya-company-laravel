import { Head } from "@inertiajs/react";
import { PrinterIcon } from "lucide-react";
import { useLayoutEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/format";
import { ORDER_TYPE_LABELS, type StaffOrder } from "@/lib/orders";

// Struk 58–80 mm dicetak lewat dialog print browser (PRD §46).
export default function ReceiptPage({ order }: { order: StaffOrder }) {
  const time = new Intl.DateTimeFormat("id-ID", { dateStyle: "short", timeStyle: "short", timeZone: order.outlet.timezone });
  // Hanya pembayaran yang benar-benar lunas yang dicetak sebagai lunas.
  const payment = order.payments.find((p) => p.status === "paid");
  const refunded = order.payments.some((p) => p.status === "refunded");
  const article = useRef<HTMLElement>(null);
  // Kertas roll thermal: lebar 80 mm, tinggi mengikuti isi struk (1 px CSS = 25,4/96 mm).
  // `size: 80mm auto` tidak valid dan dibuang browser, jadi tinggi dihitung dari isi.
  const [pageHeight, setPageHeight] = useState(200);
  useLayoutEffect(() => {
    if (article.current) setPageHeight(Math.ceil((article.current.getBoundingClientRect().height * 25.4) / 96) + 4);
  }, [order]);

  return (
    <div className="flex flex-col items-center gap-4 p-4 print:p-0">
      <Head title="Struk">
        <meta name="robots" content="noindex, nofollow" />
      </Head>
      {/* Kertas thermal 80 mm; area cetak 76 mm dengan margin 2 mm. */}
      <style>{`@page { size: 80mm ${pageHeight}mm; margin: 2mm; } @media print { html, body { background: #fff; } }`}</style>
      <Button onClick={() => window.print()} className="print:hidden">
        <PrinterIcon /> Cetak
      </Button>
      <article ref={article} className="w-[76mm] bg-white p-3 font-mono text-[12px] leading-snug break-words text-black shadow print:shadow-none">
        <header className="text-center">
          <p className="text-base font-bold">SUDAYA COFFEE</p>
          <p>{order.outlet.name}</p>
        </header>
        <hr className="my-2 border-dashed border-black" />
        <p className="text-center text-2xl font-bold">{order.orderNumber ?? order.reference}</p>
        {order.orderNumber ? <p className="text-center">Kode {order.reference}</p> : null}
        <p>{time.format(new Date(order.createdAt))}</p>
        <p>
          {ORDER_TYPE_LABELS[order.type]}
          {order.tableNumber ? ` · Meja ${order.tableNumber}` : ""}
        </p>
        {order.customerName ? <p>Customer: {order.customerName}</p> : null}
        {payment?.paidAt ? <p>Dibayar: {time.format(new Date(payment.paidAt))}</p> : null}
        {payment?.confirmedByName ? <p>Kasir: {payment.confirmedByName}</p> : null}
        <hr className="my-2 border-dashed border-black" />
        {order.lines.map((line) => (
          <div key={line.id} className="mb-1">
            <div className="flex justify-between gap-2">
              <span className="min-w-0">
                {line.quantity}x {line.productName}
              </span>
              <span className="shrink-0">{formatRupiah(line.lineTotal)}</span>
            </div>
            {line.modifiers.map((m) => (
              <p key={`${m.groupName}:${m.name}`} className="pl-3">
                - {m.name}
                {m.priceDelta ? ` (${formatRupiah(m.priceDelta)})` : ""}
              </p>
            ))}
            {line.notes ? <p className="pl-3">* {line.notes}</p> : null}
          </div>
        ))}
        <hr className="my-2 border-dashed border-black" />
        <div className="flex justify-between text-sm font-bold">
          <span>TOTAL</span>
          <span>{formatRupiah(order.total)}</span>
        </div>
        {payment ? (
          <>
            <div className="flex justify-between">
              <span>Metode</span>
              <span>{payment.method === "cash" ? "Tunai" : payment.method.toUpperCase()}</span>
            </div>
            <div className="flex justify-between">
              <span>Diterima</span>
              <span>{formatRupiah(payment.tenderedAmount ?? payment.amount)}</span>
            </div>
            <div className="flex justify-between">
              <span>Kembali</span>
              <span>{formatRupiah(payment.changeAmount ?? 0)}</span>
            </div>
          </>
        ) : refunded ? (
          <p className="mt-1 text-center font-bold">*** DIREFUND ***</p>
        ) : (
          <p className="mt-1 text-center font-bold">*** BELUM DIBAYAR ***</p>
        )}
        {order.status === "cancelled" ? <p className="mt-1 text-center font-bold">*** DIBATALKAN ***</p> : null}
        <hr className="my-2 border-dashed border-black" />
        <p className="text-center">Terima kasih!</p>
      </article>
    </div>
  );
}
