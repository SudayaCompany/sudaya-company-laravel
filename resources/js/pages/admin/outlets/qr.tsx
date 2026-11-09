import { Head, Link } from "@inertiajs/react";
import { ArrowLeftIcon, PrinterIcon, TableIcon } from "lucide-react";
import qrcode from "qrcode-generator";
import { useMemo } from "react";

import { Brand } from "@/components/brand";
import { EmptyState } from "@/components/empty-state";
import { Button } from "@/components/ui/button";

type Props = {
  outlet: { id: string; code: string; name: string };
  tables: { id: string; number: string }[];
};

/** Alamat QR meja: halaman pesan customer untuk outlet + meja ini (domain web yang sedang dipakai). */
function tableUrl(code: string, number: string) {
  return `${window.location.origin}/order?outlet=${encodeURIComponent(code)}&table=${encodeURIComponent(number)}`;
}

/** QR sebagai SVG (hitam di atas putih, tajam saat dicetak), tanpa layanan pihak ketiga. */
function QrCode({ value, label }: { value: string; label: string }) {
  const { size, path } = useMemo(() => {
    const qr = qrcode(0, "M");
    qr.addData(value);
    qr.make();
    const count = qr.getModuleCount();
    let d = "";
    for (let row = 0; row < count; row++) {
      for (let col = 0; col < count; col++) if (qr.isDark(row, col)) d += `M${col},${row}h1v1h-1z`;
    }
    return { size: count, path: d };
  }, [value]);
  const quiet = 3;
  const box = size + quiet * 2;
  return (
    <svg viewBox={`${-quiet} ${-quiet} ${box} ${box}`} role="img" aria-label={label} shapeRendering="crispEdges" className="size-44 print:size-[5.5cm]">
      <rect x={-quiet} y={-quiet} width={box} height={box} fill="white" />
      <path d={path} fill="black" />
    </svg>
  );
}

/** Lembar QR meja untuk dicetak (A4, 2 kolom). Hanya meja aktif yang diterima checkout QR. */
export default function OutletQr({ outlet, tables }: Props) {
  return (
    <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 sm:p-6 print:max-w-none print:p-0">
      <Head title={`QR meja ${outlet.name}`} />
      <header className="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <Link
          href={`/admin/outlets/${outlet.id}`}
          className="inline-flex min-h-10 items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground pointer-coarse:min-h-11"
        >
          <ArrowLeftIcon className="size-4" aria-hidden /> {outlet.name}
        </Link>
        {tables.length > 0 ? (
          <Button onClick={() => window.print()}>
            <PrinterIcon aria-hidden /> Cetak {tables.length} QR
          </Button>
        ) : null}
      </header>
      <div className="print:hidden">
        <h1 className="text-2xl font-semibold tracking-tight">QR meja</h1>
        <p className="text-sm text-muted-foreground">
          Tempel di meja. Pelanggan memindai, memilih menu, lalu membayar di kasir. Tes satu QR dengan kamera HP sebelum mencetak semua.
        </p>
      </div>

      {tables.length === 0 ? (
        <EmptyState icon={TableIcon} title="Belum ada meja aktif" description="Tambahkan nomor meja di pengaturan outlet terlebih dahulu." />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 print:grid-cols-2 print:gap-6">
          {tables.map((table) => (
            <li
              key={table.id}
              data-url={tableUrl(outlet.code, table.number)}
              className="flex break-inside-avoid flex-col items-center gap-3 rounded-2xl border bg-white p-6 text-center text-black print:rounded-none print:border-black"
            >
              <Brand className="text-xl" />
              <p className="text-xs tracking-wide uppercase">{outlet.name}</p>
              <p className="text-4xl font-bold">Meja {table.number}</p>
              <QrCode value={tableUrl(outlet.code, table.number)} label={`QR pesan meja ${table.number}`} />
              <p className="text-sm font-medium">Pindai untuk lihat menu & pesan</p>
              <p className="text-xs">Pembayaran di kasir</p>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
