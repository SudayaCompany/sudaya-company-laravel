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
  // QR berisi alamat web yang sedang dibuka: cetak dari domain HTTPS resmi, bukan dari laptop/localhost.
  const origin = window.location.origin;
  const notPublic = !origin.startsWith("https://");
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
          Tempel di meja. Pelanggan memindai, memilih menu, mengisi nama dan department, lalu pesanan diantar ke meja atau diambil di counter. Tes satu QR dengan
          kamera HP sebelum mencetak semua.
        </p>
        {notPublic ? (
          <p role="alert" className="mt-3 rounded-lg border border-warning/50 bg-warning/10 p-3 text-sm" data-testid="qr-origin-warning">
            QR ini berisi alamat <span className="font-mono">{origin}</span>, bukan domain resmi. Cetak dari alamat <strong>https://</strong> Sudaya setelah web
            online agar QR bisa dipindai pelanggan.
          </p>
        ) : null}
      </div>

      {tables.length === 0 ? (
        <EmptyState icon={TableIcon} title="Belum ada meja aktif" description="Tambahkan nomor meja di pengaturan outlet terlebih dahulu." />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 print:grid-cols-2 print:gap-6">
          {tables.map((table) => (
            <li
              key={table.id}
              data-url={tableUrl(outlet.code, table.number)}
              className="flex break-inside-avoid flex-col items-center gap-3 overflow-hidden rounded-3xl border-2 border-black bg-white pb-5 text-center text-black"
            >
              {/* Stiker meja (keputusan owner 12 November 2026): pindai → pesan → diantar ke meja. */}
              <div className="flex w-full flex-col items-center gap-1 bg-black px-4 py-3 text-white [-webkit-print-color-adjust:exact] [print-color-adjust:exact]">
                <Brand tone="light" className="text-xl" />
                <p className="text-[11px] tracking-[0.2em] uppercase">{outlet.name}</p>
              </div>
              <p className="text-sm font-semibold tracking-wide uppercase">Pesan dari meja</p>
              <p className="-mt-2 text-5xl font-black">Meja {table.number}</p>
              <QrCode value={tableUrl(outlet.code, table.number)} label={`QR pesan meja ${table.number}`} />
              <ol className="grid w-full grid-cols-3 gap-2 px-4 text-[11px] leading-tight font-medium">
                <li className="flex flex-col items-center gap-1">
                  <span className="flex size-6 items-center justify-center rounded-full border-2 border-black font-bold">1</span>Pindai QR
                </li>
                <li className="flex flex-col items-center gap-1">
                  <span className="flex size-6 items-center justify-center rounded-full border-2 border-black font-bold">2</span>Pilih menu & isi data
                </li>
                <li className="flex flex-col items-center gap-1">
                  <span className="flex size-6 items-center justify-center rounded-full border-2 border-black font-bold">3</span>Kopi diantar ke meja
                </li>
              </ol>
              <p className="px-4 text-xs">Bayar tunai saat diantar atau QRIS · bisa juga ambil sendiri di counter</p>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
