import { Head, Link, router } from "@inertiajs/react";
import { ArrowLeftIcon, BriefcaseBusinessIcon, CameraIcon, Loader2Icon, SendIcon, UserRoundXIcon, XIcon } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { Brand } from "@/components/brand";
import { ConfirmAction } from "@/components/confirm-action";
import { EmptyState } from "@/components/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { shrinkPhoto } from "@/lib/attendance";
import { useInlineFlashError } from "@/lib/feedback";
import { cn } from "@/lib/utils";

type Request = {
  id: string;
  dutyDate: string;
  startsAt: string | null;
  endsAt: string | null;
  location: string;
  purpose: string;
  notes: string | null;
  hasEvidence: boolean;
  status: "pending" | "approved" | "rejected" | "cancelled";
  statusLabel: string;
  decisionNote: string | null;
  decidedBy: string | null;
  active: boolean;
};
type Props = {
  employee: { fullName: string; outletName: string; timezone: string; isActive: boolean } | null;
  today: string;
  maxDate: string;
  requests: Request[];
};

const BADGE: Record<Request["status"], string> = {
  pending: "border-warning/50 bg-warning/10 text-foreground",
  approved: "border-success/40 bg-success/10 text-success-strong",
  rejected: "border-destructive/40 bg-destructive/10 text-destructive-strong",
  cancelled: "text-muted-foreground",
};

const empty = { dutyDate: "", startTime: "08:00", endTime: "17:00", location: "", purpose: "", notes: "" };

export default function OutsideDutyPage({ employee, today, maxDate, requests }: Props) {
  useInlineFlashError();
  const [v, setV] = useState({ ...empty, dutyDate: today });
  const [photo, setPhoto] = useState<{ file: File; url: string } | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  useEffect(() => () => (photo ? URL.revokeObjectURL(photo.url) : undefined), [photo]);

  if (employee === null) {
    return (
      <Shell>
        <EmptyState
          icon={UserRoundXIcon}
          title="Akun belum terhubung ke data karyawan"
          description="Minta admin menautkan akun Anda di menu Data karyawan, lalu buka halaman ini lagi."
        />
      </Shell>
    );
  }

  const date = new Intl.DateTimeFormat("id-ID", { weekday: "short", day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });
  const time = new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", timeZone: employee.timezone });
  const clock = (iso: string | null) => (iso ? time.format(new Date(iso)) : "—");
  const set = (key: keyof typeof empty) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setV((s) => ({ ...s, [key]: e.target.value }));

  async function onPhoto(file: File | undefined) {
    if (!file) return;
    setError(null);
    try {
      const blob = await shrinkPhoto(file, 1280);
      setPhoto({ file: new File([blob], "bukti.jpg", { type: "image/jpeg" }), url: URL.createObjectURL(blob) });
    } catch (e) {
      setError(e instanceof Error ? e.message : "Foto tidak bisa diproses. Ambil ulang.");
    }
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    router.post(
      "/staff/outside-duty",
      { ...v, ...(photo ? { photo: photo.file } : {}) },
      {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => setPending(true),
        onFinish: () => setPending(false),
        onFlash: (flash) => {
          const f = flash as { success?: string; error?: string };
          if (f.success) {
            setV({ ...empty, dutyDate: today });
            setPhoto(null);
          }
          if (f.error) setError(f.error);
        },
      },
    );
  }

  const ready = v.location.trim().length >= 3 && v.purpose.trim().length >= 3 && v.notes.trim().length >= 5 && v.endTime > v.startTime;

  return (
    <Shell>
      <section className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold">Tugas luar outlet</h1>
        <p className="text-sm text-muted-foreground">
          Untuk tugas di luar {employee.outletName} (mis. belanja bahan, event, kantor pusat). Setelah disetujui Manager/Owner, absen pada jam tugas boleh dari
          lokasi tugas. GPS dan selfie tetap dicatat.
        </p>
      </section>

      {!employee.isActive ? (
        <p className="rounded-xl border border-warning/50 bg-warning/10 p-3 text-sm">Status karyawan atau outlet Anda nonaktif. Hubungi admin.</p>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-4 rounded-2xl border bg-card p-4" aria-labelledby="form-title">
          <h2 id="form-title" className="text-base font-semibold">
            Ajukan tugas luar
          </h2>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2 flex flex-col gap-1.5">
              <Label htmlFor="duty-date">Tanggal</Label>
              <Input id="duty-date" type="date" required min={today} max={maxDate} value={v.dutyDate} onChange={set("dutyDate")} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="duty-start">Jam mulai</Label>
              <Input id="duty-start" type="time" required value={v.startTime} onChange={set("startTime")} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="duty-end">Jam selesai</Label>
              <Input id="duty-end" type="time" required value={v.endTime} onChange={set("endTime")} aria-invalid={v.endTime <= v.startTime} />
            </div>
          </div>
          {v.endTime <= v.startTime ? <p className="-mt-2 text-xs text-destructive-strong">Jam selesai harus setelah jam mulai.</p> : null}
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="duty-location">Lokasi tugas</Label>
            <Input id="duty-location" required maxLength={200} value={v.location} onChange={set("location")} placeholder="mis. Pasar Induk Kramat Jati" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="duty-purpose">Tujuan</Label>
            <Input id="duty-purpose" required maxLength={200} value={v.purpose} onChange={set("purpose")} placeholder="mis. Belanja bahan baku" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="duty-notes">Keterangan</Label>
            <Textarea id="duty-notes" required maxLength={500} rows={2} value={v.notes} onChange={set("notes")} placeholder="Minimal 5 karakter, mis. ditugaskan oleh siapa" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Foto bukti (opsional)</Label>
            <input ref={fileInput} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => void onPhoto(e.target.files?.[0])} />
            {photo ? (
              <div className="flex items-center gap-3">
                <img src={photo.url} alt="Pratinjau foto bukti" className="size-20 rounded-lg object-cover ring-1 ring-foreground/10" />
                <Button type="button" variant="ghost" onClick={() => setPhoto(null)}>
                  <XIcon aria-hidden /> Hapus foto
                </Button>
              </div>
            ) : (
              <Button type="button" variant="outline" className="w-fit" onClick={() => fileInput.current?.click()}>
                <CameraIcon aria-hidden /> Ambil foto surat tugas / lokasi
              </Button>
            )}
          </div>
          {error ? (
            <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
              {error}
            </p>
          ) : null}
          <Button type="submit" size="xl" disabled={pending || !ready}>
            {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SendIcon aria-hidden />} Ajukan tugas luar
          </Button>
        </form>
      )}

      <section className="flex flex-col gap-3" aria-labelledby="list-title">
        <h2 id="list-title" className="text-base font-semibold">
          Pengajuan saya
        </h2>
        {requests.length === 0 ? (
          <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">Belum ada pengajuan tugas luar dalam 30 hari terakhir.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {requests.map((r) => (
              <li key={r.id} className={cn("flex flex-col gap-2 rounded-xl border bg-card p-4", r.active && "border-success/50 ring-1 ring-success/30")}>
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-medium">{r.purpose}</p>
                    <p className="text-sm text-muted-foreground">
                      {date.format(new Date(`${r.dutyDate}T00:00:00Z`))} · {clock(r.startsAt)}–{clock(r.endsAt)}
                    </p>
                  </div>
                  <Badge variant="outline" className={BADGE[r.status]}>
                    {r.active ? "Sedang berlangsung" : r.statusLabel}
                  </Badge>
                </div>
                <p className="flex items-start gap-1.5 text-sm">
                  <BriefcaseBusinessIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                  <span className="min-w-0 break-words">{r.location}</span>
                </p>
                {r.notes ? <p className="text-sm text-muted-foreground">{r.notes}</p> : null}
                {r.hasEvidence ? <p className="text-xs text-muted-foreground">Foto bukti terlampir.</p> : null}
                {r.status === "rejected" || (r.status === "approved" && r.decisionNote) ? (
                  <p className="text-xs text-muted-foreground">
                    {r.status === "rejected" ? "Ditolak" : "Disetujui"}
                    {r.decidedBy ? ` oleh ${r.decidedBy}` : ""}
                    {r.decisionNote ? `: ${r.decisionNote}` : ""}
                  </p>
                ) : null}
                {r.status === "pending" ? (
                  <ConfirmAction
                    trigger={
                      <Button variant="outline" size="sm" className="w-fit">
                        Batalkan pengajuan
                      </Button>
                    }
                    title="Batalkan pengajuan tugas luar?"
                    description="Pengajuan yang dibatalkan tidak bisa diaktifkan lagi; ajukan ulang bila perlu."
                    confirmLabel="Batalkan pengajuan"
                    destructive
                    onConfirm={() => router.post(`/staff/outside-duty/${r.id}/cancel`, {}, { preserveScroll: true })}
                  />
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </section>
    </Shell>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-5 px-4 py-5">
      <Head title="Tugas luar" />
      <header className="flex items-center justify-between gap-3">
        <Link
          href="/staff/attendance"
          className="inline-flex min-h-11 items-center gap-1.5 rounded-lg pr-2 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
          <ArrowLeftIcon className="size-4" aria-hidden /> Absen
        </Link>
        <Brand className="text-lg" />
      </header>
      {children}
    </div>
  );
}
