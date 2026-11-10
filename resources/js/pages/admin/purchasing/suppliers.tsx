import { Head, Link, router } from "@inertiajs/react";
import { Loader2Icon, PencilIcon, PlusIcon, ShoppingCartIcon, TruckIcon } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatRupiah } from "@/lib/format";

type Supplier = {
  id: string;
  name: string;
  contactName: string | null;
  phone: string | null;
  email: string | null;
  products: string | null;
  paymentTerm: string | null;
  notes: string | null;
  isActive: boolean;
  orders: number;
  receivedTotal: number;
  lastOrderAt: string | null;
};
type Form = { name: string; contactName: string; phone: string; email: string; products: string; paymentTerm: string; notes: string; isActive: boolean };
const EMPTY: Form = { name: "", contactName: "", phone: "", email: "", products: "", paymentTerm: "", notes: "", isActive: true };
const date = new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", year: "numeric" });

export default function SuppliersPage({ suppliers, canManage }: { suppliers: Supplier[]; canManage: boolean }) {
  const [editing, setEditing] = useState<Supplier | "new" | null>(null);

  return (
    <AdminLayout>
      <Head title="Supplier" />
      <PageHeader title="Supplier" description={`${suppliers.filter((s) => s.isActive).length} supplier aktif · kontak, produk, termin, dan histori pembelian`}>
        <div className="flex flex-wrap gap-2">
          <Link href="/admin/purchase-orders" className={buttonVariants({ variant: "outline" })}>
            <ShoppingCartIcon /> Purchase order
          </Link>
          {canManage ? (
            <Button onClick={() => setEditing("new")}>
              <PlusIcon /> Supplier baru
            </Button>
          ) : null}
        </div>
      </PageHeader>

      {suppliers.length === 0 ? (
        <EmptyState icon={TruckIcon} title="Belum ada supplier" description="Tambahkan pemasok biji kopi, susu, kemasan, dll. agar purchase order bisa dibuat." />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {suppliers.map((s) => (
            <li key={s.id} className="flex flex-col gap-2 rounded-xl border bg-card p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-semibold break-words">{s.name}</p>
                  <p className="text-sm text-muted-foreground break-words">{[s.contactName, s.phone, s.email].filter(Boolean).join(" · ") || "Kontak belum diisi"}</p>
                </div>
                {s.isActive ? null : <Badge variant="outline">Nonaktif</Badge>}
              </div>
              {s.products ? <p className="text-sm break-words">Memasok: {s.products}</p> : null}
              <p className="text-xs text-muted-foreground">
                {s.paymentTerm ? `Termin ${s.paymentTerm} · ` : ""}
                {s.orders} PO · diterima {formatRupiah(s.receivedTotal)}
                {s.lastOrderAt ? ` · terakhir ${date.format(new Date(s.lastOrderAt))}` : ""}
              </p>
              {canManage ? (
                <Button variant="outline" size="sm" className="w-fit" onClick={() => setEditing(s)} aria-label={`Ubah ${s.name}`}>
                  <PencilIcon /> Ubah
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {editing !== null ? <SupplierDialog supplier={editing === "new" ? null : editing} onClose={() => setEditing(null)} /> : null}
    </AdminLayout>
  );
}

function SupplierDialog({ supplier, onClose }: { supplier: Supplier | null; onClose: () => void }) {
  const [v, setV] = useState<Form>(
    supplier
      ? {
          name: supplier.name,
          contactName: supplier.contactName ?? "",
          phone: supplier.phone ?? "",
          email: supplier.email ?? "",
          products: supplier.products ?? "",
          paymentTerm: supplier.paymentTerm ?? "",
          notes: supplier.notes ?? "",
          isActive: supplier.isActive,
        }
      : EMPTY,
  );
  const [pending, setPending] = useState(false);
  const set = (key: keyof Form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setV((s) => ({ ...s, [key]: e.target.value }));

  function submit(e: React.FormEvent) {
    e.preventDefault();
    const options = { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false), onSuccess: () => onClose() };
    if (supplier) router.put(`/admin/suppliers/${supplier.id}`, v, options);
    else router.post("/admin/suppliers", v, options);
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{supplier ? "Ubah supplier" : "Supplier baru"}</DialogTitle>
          <DialogDescription>Nama wajib; data lain membantu saat memesan ulang.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="flex flex-col gap-3">
          <Field id="sup-name" label="Nama supplier">
            <Input id="sup-name" value={v.name} onChange={set("name")} required maxLength={120} placeholder="mis. CV Kopi Nusantara" />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field id="sup-contact" label="Nama kontak">
              <Input id="sup-contact" value={v.contactName} onChange={set("contactName")} maxLength={80} />
            </Field>
            <Field id="sup-phone" label="Telepon / WA">
              <Input id="sup-phone" value={v.phone} onChange={set("phone")} maxLength={30} inputMode="tel" />
            </Field>
          </div>
          <Field id="sup-email" label="Email">
            <Input id="sup-email" type="email" value={v.email} onChange={set("email")} maxLength={120} />
          </Field>
          <Field id="sup-products" label="Produk yang dipasok">
            <Input id="sup-products" value={v.products} onChange={set("products")} maxLength={300} placeholder="mis. biji arabika, susu UHT" />
          </Field>
          <Field id="sup-term" label="Termin pembayaran">
            <Input id="sup-term" value={v.paymentTerm} onChange={set("paymentTerm")} maxLength={60} placeholder="mis. Tunai, Tempo 14 hari" />
          </Field>
          <Field id="sup-notes" label="Catatan">
            <Textarea id="sup-notes" value={v.notes} onChange={set("notes")} maxLength={500} rows={2} />
          </Field>
          <label className="flex min-h-11 items-center justify-between gap-3 text-sm">
            Supplier aktif
            <Switch checked={v.isActive} onCheckedChange={(isActive) => setV((s) => ({ ...s, isActive }))} aria-label="Supplier aktif" />
          </label>
          <Button type="submit" size="lg" disabled={pending || v.name.trim().length < 2}>
            {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : null} {supplier ? "Simpan perubahan" : "Tambah supplier"}
          </Button>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({ id, label, children }: { id: string; label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
    </div>
  );
}
