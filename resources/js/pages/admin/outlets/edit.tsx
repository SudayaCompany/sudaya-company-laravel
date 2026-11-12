import { Head, Link, router } from "@inertiajs/react";
import { ImageUpIcon, Loader2Icon, PlusIcon, PrinterIcon, QrCodeIcon, SaveIcon, TableIcon, Trash2Icon } from "lucide-react";
import { useRef, useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { cn } from "@/lib/utils";

type Outlet = {
  id: string;
  code: string;
  name: string;
  address: string | null;
  orderNumberPrefix: string;
  pendingPaymentTtlMinutes: number;
  isActive: boolean;
  qrisImageUrl?: string | null;
};
type Table = { id: string; number: string; isActive: boolean };

function useMutation() {
  const [pending, setPending] = useState(false);
  const options = { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) };
  return { pending, options };
}

export default function OutletEdit({ outlet, tables }: { outlet: Outlet; tables: Table[] }) {
  return (
    <AdminLayout breadcrumbs={[{ label: "Outlet & meja", href: "/admin/outlets" }, { label: outlet.name }]}>
      <Head title={`Outlet ${outlet.name}`} />
      <PageHeader title={outlet.name} description={`Kode ${outlet.code} (tercetak di QR, tidak bisa diubah).`} />
      <SettingsForm key={JSON.stringify(outlet)} outlet={outlet} />
      <QrisSettings key={outlet.qrisImageUrl ?? 'none'} outlet={outlet} />
      <Tables outlet={outlet} tables={tables} />
    </AdminLayout>
  );
}

function SettingsForm({ outlet }: { outlet: Outlet }) {
  const [name, setName] = useState(outlet.name);
  const [address, setAddress] = useState(outlet.address ?? "");
  const [prefix, setPrefix] = useState(outlet.orderNumberPrefix);
  const [ttl, setTtl] = useState(String(outlet.pendingPaymentTtlMinutes));
  const [isActive, setIsActive] = useState(outlet.isActive);
  const { pending, options } = useMutation();
  const save = () =>
    router.put(`/admin/outlets/${outlet.id}`, { name, address, orderNumberPrefix: prefix, pendingPaymentTtlMinutes: ttl, isActive }, options);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Pengaturan</CardTitle>
        <CardDescription>Berlaku untuk pesanan berikutnya. Nomor antrean yang sudah terbit tidak berubah.</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid max-w-2xl gap-4 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (!isActive && outlet.isActive) return;
            save();
          }}
        >
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <Label htmlFor="outlet-name">Nama outlet</Label>
            <Input id="outlet-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={80} required />
          </div>
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <Label htmlFor="outlet-address">
              Alamat <span className="text-xs font-normal text-muted-foreground">(opsional)</span>
            </Label>
            <Textarea id="outlet-address" value={address} onChange={(e) => setAddress(e.target.value)} maxLength={200} rows={2} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="outlet-prefix">Prefix antrean</Label>
            <Input
              id="outlet-prefix"
              value={prefix}
              onChange={(e) => setPrefix(e.target.value.toUpperCase())}
              maxLength={6}
              className="font-mono uppercase"
              required
            />
            <p className="text-xs text-muted-foreground">Contoh nomor: {prefix || "SDY"}-001</p>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="outlet-ttl">Batas waktu bayar (menit)</Label>
            <Input id="outlet-ttl" type="number" inputMode="numeric" min={5} max={180} value={ttl} onChange={(e) => setTtl(e.target.value)} required />
            <p className="text-xs text-muted-foreground">Pesanan QR/kiosk yang belum dibayar batal otomatis setelahnya.</p>
          </div>
          <label className="flex items-center gap-3 text-sm sm:col-span-2">
            <Switch checked={isActive} onCheckedChange={setIsActive} aria-label="Outlet aktif" />
            <span>
              {isActive ? "Outlet aktif menerima pesanan" : "Outlet nonaktif"}
              {!isActive ? <span className="block text-xs text-destructive">Menu customer dan checkout outlet ini ditutup.</span> : null}
            </span>
          </label>
          <div className="sm:col-span-2">
            {!isActive && outlet.isActive ? (
              <ConfirmAction
                trigger={
                  <Button type="button" variant="destructive" disabled={pending}>
                    {pending ? <Loader2Icon className="animate-spin" /> : <SaveIcon />} Simpan & nonaktifkan
                  </Button>
                }
                title="Nonaktifkan outlet?"
                description="Customer tidak bisa melihat menu dan memesan di outlet ini sampai diaktifkan lagi. Pesanan yang sudah ada tidak berubah."
                confirmLabel="Nonaktifkan"
                onConfirm={save}
              />
            ) : (
              <Button type="submit" disabled={pending}>
                {pending ? <Loader2Icon className="animate-spin" /> : <SaveIcon />} Simpan pengaturan
              </Button>
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function Tables({ outlet, tables }: { outlet: Outlet; tables: Table[] }) {
  const [numbers, setNumbers] = useState("");
  const { pending, options } = useMutation();
  const active = tables.filter((t) => t.isActive).length;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Daftar meja</CardTitle>
        <CardDescription>
          Pesanan dari QR meja hanya diterima untuk meja aktif di daftar ini. {active} dari {tables.length} meja aktif.
        </CardDescription>
        {active > 0 ? (
          <CardAction>
            <Link href={`/admin/outlets/${outlet.id}/qr`} className={buttonVariants({ variant: "outline" })}>
              <PrinterIcon /> Cetak QR meja
            </Link>
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <form
          className="flex flex-col gap-2 sm:flex-row"
          onSubmit={(e) => {
            e.preventDefault();
            router.post(`/admin/outlets/${outlet.id}/tables`, { numbers }, { ...options, onFlash: (flash) => (flash as { success?: string }).success && setNumbers("") });
          }}
        >
          <Input
            value={numbers}
            onChange={(e) => setNumbers(e.target.value.toUpperCase())}
            placeholder="Nomor meja, pisahkan dengan koma: T01, T02, T03"
            aria-label="Nomor meja baru"
            className="font-mono uppercase"
          />
          <Button type="submit" disabled={pending || numbers.trim() === ""}>
            {pending ? <Loader2Icon className="animate-spin" /> : <PlusIcon />} Tambah meja
          </Button>
        </form>

        {tables.length === 0 ? (
          <EmptyState icon={TableIcon} title="Belum ada meja" description="Tambahkan nomor meja sesuai QR yang akan dicetak." />
        ) : (
          <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            {tables.map((table) => (
              <TableRow key={`${table.id}:${table.isActive}`} table={table} outletCode={outlet.code} />
            ))}
          </ul>
        )}
        <p className="text-xs text-muted-foreground">
          Isi QR meja: <span className="font-mono break-all">/order?outlet={outlet.code}&amp;table=&lt;nomor&gt;</span>
        </p>
      </CardContent>
    </Card>
  );
}

function TableRow({ table, outletCode }: { table: Table; outletCode: string }) {
  const { pending, options } = useMutation();
  const [confirming, setConfirming] = useState(false);
  const setActive = (isActive: boolean) => router.patch(`/admin/outlet-tables/${table.id}`, { isActive }, options);
  return (
    <li className={cn("flex items-center justify-between gap-2 rounded-lg border p-2", !table.isActive && "bg-muted/50 text-muted-foreground")}>
      <a href={`/order?outlet=${outletCode}&table=${table.number}`} target="_blank" rel="noopener" className="font-mono font-medium hover:underline">
        {table.number}
      </a>
      {/* Menonaktifkan meja langsung menolak pesanan dari QR meja itu, jadi dikonfirmasi; mengaktifkan tidak. */}
      <Switch
        checked={table.isActive}
        disabled={pending}
        onCheckedChange={(isActive) => (isActive ? setActive(true) : setConfirming(true))}
        aria-label={`${table.isActive ? "Nonaktifkan" : "Aktifkan"} meja ${table.number}`}
      />
      <ConfirmAction
        open={confirming}
        onOpenChange={setConfirming}
        title={`Nonaktifkan meja ${table.number}?`}
        description="Pesanan dari QR meja ini akan ditolak sampai meja diaktifkan lagi. Pesanan yang sudah masuk tidak berubah."
        confirmLabel="Nonaktifkan meja"
        onConfirm={() => setActive(false)}
      />
    </li>
  );
}

/** QRIS statis outlet (dikonfirmasi manual kasir). Biaya antar dihapus 11 November 2026. */
function QrisSettings({ outlet }: { outlet: Outlet }) {
  const { pending, options } = useMutation();
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const save = (removeQris = false) =>
    router.post(`/admin/outlets/${outlet.id}/ordering`, { removeQris, ...(file ? { qris: file } : {}) }, { ...options, forceFormData: true });

  return (
    <Card>
      <CardHeader>
        <CardTitle>Pembayaran QRIS</CardTitle>
        <CardDescription>
          Pelanggan scan QR di meja → isi nama, department, lantai, bagian meja → pilih Antar ke meja (gratis, bayar tunai saat diantar) atau Ambil di counter → atau bayar QRIS.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-5">
        <div className="flex flex-col gap-2">
          <Label>QRIS outlet (statis)</Label>
          <p className="text-xs text-muted-foreground">
            Unggah foto/gambar QRIS resmi Sudaya (dari bank/penyedia QRIS). Pelanggan yang memilih QRIS melihat gambar ini beserta nominal; kasir menekan "Konfirmasi QRIS
            diterima" setelah dana terlihat masuk. Tanpa gambar, pilihan QRIS tidak muncul.
          </p>
          <input
            ref={input}
            type="file"
            accept="image/png,image/jpeg,image/webp"
            className="sr-only"
            tabIndex={-1}
            aria-hidden
            onChange={(e) => {
              const picked = e.target.files?.[0] ?? null;
              setFile(picked);
              setPreview(picked ? URL.createObjectURL(picked) : null);
            }}
          />
          <div className="flex flex-wrap items-center gap-3">
            {preview || outlet.qrisImageUrl ? (
              <img src={preview ?? outlet.qrisImageUrl ?? undefined} alt="QRIS outlet" className="size-32 rounded-lg border bg-white object-contain p-1" />
            ) : (
              <div className="flex size-32 flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-xs text-muted-foreground">
                <QrCodeIcon className="size-6" aria-hidden /> Belum ada QRIS
              </div>
            )}
            <div className="flex flex-col gap-2">
              <Button type="button" variant="outline" onClick={() => input.current?.click()}>
                <ImageUpIcon /> {outlet.qrisImageUrl ? "Ganti gambar QRIS" : "Pilih gambar QRIS"}
              </Button>
              {outlet.qrisImageUrl && !file ? (
                <ConfirmAction
                  trigger={
                    <Button type="button" variant="ghost">
                      <Trash2Icon /> Hapus QRIS
                    </Button>
                  }
                  title="Hapus QRIS outlet?"
                  description="Pilihan bayar QRIS hilang dari halaman pesanan pelanggan sampai gambar baru diunggah."
                  confirmLabel="Hapus QRIS"
                  onConfirm={() => save(true)}
                />
              ) : null}
            </div>
          </div>
        </div>
        <Button className="w-fit" onClick={() => save()} disabled={pending}>
          {pending ? <Loader2Icon className="animate-spin" /> : <SaveIcon />} Simpan QRIS
        </Button>
      </CardContent>
    </Card>
  );
}
