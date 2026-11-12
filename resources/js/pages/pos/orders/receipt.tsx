import { Head } from "@inertiajs/react";
import { PrinterIcon } from "lucide-react";
import { useEffect, useLayoutEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/button";
import { formatRupiah } from "@/lib/format";
import { ORDER_TYPE_LABELS, type StaffOrder } from "@/lib/orders";

type Paper = 58 | 80;
const PAPER_KEY = "sudaya-receipt-paper";

function savedPaper(): Paper {
  try {
    return window.localStorage.getItem(PAPER_KEY) === "80" ? 80 : 58;
  } catch {
    return 58;
  }
}

// Struk 58 atau 80 mm dicetak lewat dialog print browser (PRD §46). Printer thermal Bluetooth:
// Android lewat aplikasi print service (mis. RawBT), laptop lewat printer yang sudah dipasangkan.
// Lebar kertas diingat per perangkat; bawaan 58 mm (paling umum untuk printer Bluetooth).
export default function ReceiptPage({ order }: { order: StaffOrder }) {
  const [paper, setPaper] = useState<Paper>(58);
  useEffect(() => setPaper(savedPaper()), []);
  const choosePaper = (next: Paper) => {
    setPaper(next);
    try {
      window.localStorage.setItem(PAPER_KEY, String(next));
    } catch {
      // Penyimpanan perangkat tidak tersedia: pilihan berlaku untuk halaman ini saja.
    }
  };
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
  }, [order, paper]);
  // Dibuka dari tombol "Cetak struk" kasir (?print=1): langsung buka dialog cetak, hemat satu klik.
  useEffect(() => {
    if (new URLSearchParams(window.location.search).get("print") !== "1") return;
    const timer = setTimeout(() => window.print(), 300);
    return () => clearTimeout(timer);
  }, []);

  return (
    <div className="flex flex-col items-center gap-4 p-4 print:p-0">
      <Head title="Struk">
        <meta name="robots" content="noindex, nofollow" />
      </Head>
      {/* Kertas thermal 58/80 mm; area cetak = lebar − margin 2 mm kiri-kanan. */}
      <style>{`@page { size: ${paper}mm ${pageHeight}mm; margin: 2mm; } @media print { html, body { background: #fff; } }`}</style>
      <div className="flex flex-wrap items-center justify-center gap-2 print:hidden">
        <div role="radiogroup" aria-label="Lebar kertas" className="flex rounded-lg bg-muted p-1">
          {([58, 80] as const).map((width) => (
            <button
              key={width}
              type="button"
              role="radio"
              aria-checked={paper === width}
              onClick={() => choosePaper(width)}
              className={`min-h-10 rounded-md px-3 text-sm font-semibold ${paper === width ? "bg-background shadow-sm" : "text-muted-foreground"}`}
            >
              {width} mm
            </button>
          ))}
        </div>
        <Button size="lg" onClick={() => window.print()}>
          <PrinterIcon /> Cetak
        </Button>
      </div>
      <article
        ref={article}
        style={{ width: `${paper - 4}mm` }}
        className={`bg-white font-mono leading-snug break-words text-black shadow print:shadow-none ${paper === 58 ? "p-1.5 text-[11px]" : "p-3 text-[12px]"}`}
      >
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
        {order.customerName ? <p>Pelanggan: {order.customerName}</p> : null}
        {order.customerDepartment ? <p>Dept: {order.customerDepartment}</p> : null}
        {order.locationNote ? <p>Antar: {order.locationNote}</p> : null}
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
