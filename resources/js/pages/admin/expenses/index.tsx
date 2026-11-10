import { Head, router } from "@inertiajs/react";
import { CameraIcon, HandCoinsIcon, ImageIcon, Loader2Icon, SendIcon, XIcon } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { MoneyInput } from "@/components/money-input";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";
import { shrinkPhoto } from "@/lib/attendance";
import { useInlineFlashError } from "@/lib/feedback";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";

type Row = {
  id: string;
  date: string;
  category: string;
  categoryLabel: string;
  amount: number;
  description: string;
  evidenceUrl: string | null;
  status: "pending" | "approved" | "rejected" | "cancelled";
  statusLabel: string;
  createdBy: string | null;
  decidedBy: string | null;
  decisionNote: string | null;
  canCancel: boolean;
};
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  today: string;
  categories: { value: string; label: string }[];
  report: {
    month: string;
    canReview: boolean;
    canSubmit: boolean;
    totals: { approved: number; pending: number; count: number };
    byCategory: { category: string; label: string; amount: number }[];
    rows: Row[];
  };
};

const BADGE: Record<Row["status"], string> = {
  pending: "border-warning/50 bg-warning/10 text-foreground",
  approved: "border-success/40 bg-success/10 text-success-strong",
  rejected: "border-destructive/40 bg-destructive/10 text-destructive-strong",
  cancelled: "text-muted-foreground",
};
const day = new Intl.DateTimeFormat("id-ID", { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" });

export default function ExpensesPage({ outlets, outlet, today, categories, report }: Props) {
  useInlineFlashError();
  const blank = { expenseDate: today, category: "", amount: "", description: "" };
  const [v, setV] = useState(blank);
  const [photo, setPhoto] = useState<{ file: File; url: string } | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  useEffect(() => () => (photo ? URL.revokeObjectURL(photo.url) : undefined), [photo]);
  const go = (params: Record<string, string>) => router.get("/admin/expenses", { outlet: outlet.id, month: report.month, ...params }, { preserveScroll: true, replace: true });

  async function onPhoto(file: File | undefined) {
    if (!file) return;
    try {
      const blob = await shrinkPhoto(file, 1280);
      setPhoto({ file: new File([blob], "nota.jpg", { type: "image/jpeg" }), url: URL.createObjectURL(blob) });
    } catch (e) {
      setError(e instanceof Error ? e.message : "Foto tidak bisa diproses.");
    }
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    router.post(
      "/admin/expenses",
      { ...v, outletId: outlet.id, ...(photo ? { photo: photo.file } : {}) },
      {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => setPending(true),
        onFinish: () => setPending(false),
        onFlash: (flash) => {
          const f = flash as { success?: string; error?: string };
          if (f.success) {
            setV(blank);
            setPhoto(null);
          }
          if (f.error) setError(f.error);
        },
      },
    );
  }

  const ready = v.category !== "" && Number(v.amount) > 0 && v.description.trim().length >= 3;

  return (
    <AdminLayout>
      <Head title="Pengeluaran" />
      <PageHeader title="Pengeluaran" description={`${outlet.name} · parkir, transport, pembelian kecil, perawatan. Disetujui Owner atau Manager.`}>
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="expense-month">Bulan</Label>
            <Input id="expense-month" type="month" value={report.month} className="w-44" onChange={(e) => e.target.value && go({ month: e.target.value })} />
          </div>
          {outlets.length > 1 ? (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="expense-outlet">Outlet</Label>
              <NativeSelect id="expense-outlet" value={outlet.id} onChange={(e) => go({ outlet: e.target.value })}>
                {outlets.map((o) => (
                  <NativeSelectOption key={o.id} value={o.id}>
                    {o.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            </div>
          ) : null}
        </div>
      </PageHeader>

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div className="col-span-2 flex flex-col gap-1 rounded-xl bg-espresso p-5 text-espresso-foreground xl:col-span-1">
          <p className="text-sm text-espresso-foreground/80">Disetujui bulan ini</p>
          <p className="text-3xl font-bold tracking-tight" data-testid="expense-approved">
            {formatRupiah(report.totals.approved)}
          </p>
          <p className="text-xs text-espresso-foreground/70">{report.canReview ? "Semua pengeluaran outlet" : "Pengeluaran yang Anda catat"}</p>
        </div>
        <div className="flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5">
          <p className="text-sm text-muted-foreground">Menunggu persetujuan</p>
          <p className="text-xl font-semibold sm:text-2xl">{formatRupiah(report.totals.pending)}</p>
        </div>
        <div className="flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5 xl:col-span-2">
          <p className="text-sm text-muted-foreground">Per kategori (disetujui)</p>
          {report.byCategory.length === 0 ? (
            <p className="text-sm text-muted-foreground">Belum ada.</p>
          ) : (
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
              {report.byCategory.map((c) => (
                <li key={c.category}>
                  {c.label} <span className="font-semibold tabular-nums">{formatRupiah(c.amount)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="grid gap-6 xl:grid-cols-5">
        {report.canSubmit ? (
          <Card className="xl:col-span-2">
            <CardHeader>
              <CardTitle>Catat pengeluaran</CardTitle>
              <CardDescription>Foto nota/struk membantu persetujuan. Uang dari laci kasir tetap dicatat juga di Kas keluar.</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid grid-cols-2 gap-3">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expense-date">Tanggal</Label>
                    <Input id="expense-date" type="date" max={today} value={v.expenseDate} onChange={(e) => setV((s) => ({ ...s, expenseDate: e.target.value }))} required />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expense-amount">Nominal</Label>
                    <MoneyInput id="expense-amount" value={v.amount} onValueChange={(amount) => setV((s) => ({ ...s, amount }))} placeholder="0" required />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="expense-category">Kategori</Label>
                  <NativeSelect id="expense-category" className="w-full" value={v.category} onChange={(e) => setV((s) => ({ ...s, category: e.target.value }))} required>
                    <NativeSelectOption value="">Pilih kategori</NativeSelectOption>
                    {categories.map((c) => (
                      <NativeSelectOption key={c.value} value={c.value}>
                        {c.label}
                      </NativeSelectOption>
                    ))}
                  </NativeSelect>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="expense-description">Keterangan</Label>
                  <Textarea id="expense-description" rows={2} maxLength={300} value={v.description} onChange={(e) => setV((s) => ({ ...s, description: e.target.value }))} placeholder='mis. "Parkir belanja bahan di pasar"' />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Foto nota (opsional)</Label>
                  <input ref={fileInput} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => void onPhoto(e.target.files?.[0])} />
                  {photo ? (
                    <div className="flex items-center gap-3">
                      <img src={photo.url} alt="Pratinjau foto nota" className="size-20 rounded-lg object-cover ring-1 ring-foreground/10" />
                      <Button type="button" variant="ghost" onClick={() => setPhoto(null)}>
                        <XIcon aria-hidden /> Hapus foto
                      </Button>
                    </div>
                  ) : (
                    <Button type="button" variant="outline" className="w-fit" onClick={() => fileInput.current?.click()}>
                      <CameraIcon aria-hidden /> Ambil foto nota
                    </Button>
                  )}
                </div>
                {error ? (
                  <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
                    {error}
                  </p>
                ) : null}
                <Button type="submit" size="lg" disabled={pending || !ready}>
                  {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SendIcon aria-hidden />} Ajukan pengeluaran
                </Button>
              </form>
            </CardContent>
          </Card>
        ) : null}

        <section className={cn("flex flex-col gap-3", report.canSubmit ? "xl:col-span-3" : "xl:col-span-5")} aria-labelledby="expense-list">
          <h2 id="expense-list" className="text-base font-semibold">
            Daftar {report.rows.length > 0 ? `(${report.rows.length})` : ""}
          </h2>
          {report.rows.length === 0 ? (
            <EmptyState icon={HandCoinsIcon} title="Belum ada pengeluaran bulan ini" description="Pengeluaran yang dicatat tampil di sini beserta statusnya." />
          ) : (
            <ul className="flex flex-col gap-3">
              {report.rows.map((r) => (
                <li key={r.id} className="flex flex-col gap-2 rounded-xl border bg-card p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-semibold tabular-nums">{formatRupiah(r.amount)}</p>
                      <p className="text-sm text-muted-foreground">
                        {day.format(new Date(`${r.date}T00:00:00Z`))} · {r.categoryLabel}
                        {r.createdBy ? ` · ${r.createdBy}` : ""}
                      </p>
                    </div>
                    <Badge variant="outline" className={BADGE[r.status]}>
                      {r.statusLabel}
                    </Badge>
                  </div>
                  <p className="text-sm break-words">{r.description}</p>
                  <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    {r.evidenceUrl ? (
                      <a href={r.evidenceUrl} target="_blank" rel="noopener" className="inline-flex min-h-9 items-center gap-1 font-medium text-brand-strong underline-offset-4 hover:underline pointer-coarse:min-h-11">
                        <ImageIcon className="size-3.5" aria-hidden /> Lihat nota
                      </a>
                    ) : (
                      <span>Tanpa foto nota</span>
                    )}
                    {r.status !== "pending" && r.status !== "cancelled" && r.decidedBy ? (
                      <span>
                        {r.status === "approved" ? "Disetujui" : "Ditolak"} oleh {r.decidedBy}
                        {r.decisionNote ? `: ${r.decisionNote}` : ""}
                      </span>
                    ) : null}
                  </div>
                  {r.canCancel ? (
                    <ConfirmAction
                      trigger={
                        <Button variant="outline" size="sm" className="w-fit">
                          Batalkan
                        </Button>
                      }
                      title="Batalkan pengeluaran ini?"
                      description="Pengeluaran yang dibatalkan tidak diproses lagi; catat ulang bila perlu."
                      confirmLabel="Batalkan pengeluaran"
                      onConfirm={() => router.post(`/admin/expenses/${r.id}/cancel`, {}, { preserveScroll: true })}
                    />
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </AdminLayout>
  );
}
