import { Head, Link, router } from "@inertiajs/react";
import { BriefcaseBusinessIcon, ClockIcon, ImageOffIcon, LogOutIcon, SheetIcon, UserRoundCheckIcon, UsersIcon, UserRoundXIcon } from "lucide-react";
import { useState } from "react";

import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { AdminLayout } from "@/layouts/admin-layout";
import { formatMinutes } from "@/lib/attendance";
import { cn } from "@/lib/utils";

type Session = {
  id: string;
  checkInAt: string | null;
  checkOutAt: string | null;
  workingMinutes: number | null;
  manual: boolean;
  checkInDistance: number | null;
  checkOutDistance: number | null;
  hasCheckInPhoto: boolean;
  hasCheckOutPhoto: boolean;
};
type Row = { id: string; code: string; fullName: string; hasFace: boolean; position: string | null; status: "working" | "done" | "absent"; minutes: number; sessions: Session[] };
type Props = {
  outlets: { id: string; name: string }[];
  outlet: { id: string; name: string; timezone: string };
  board: { date: string; isToday: boolean; rows: Row[]; counts: { working: number; done: number; absent: number } };
  outsideDuties?: { id: string; employeeName: string; startsAt: string | null; endsAt: string | null; location: string; purpose: string }[];
};

const STATUS = {
  working: { label: "Bekerja", icon: UserRoundCheckIcon, className: "bg-success/15 text-success-strong" },
  done: { label: "Sudah pulang", icon: LogOutIcon, className: "bg-secondary text-secondary-foreground" },
  absent: { label: "Belum absen", icon: UserRoundXIcon, className: "border border-warning/50 bg-warning/10 text-foreground" },
} as const;

function formatter(options: Intl.DateTimeFormatOptions, timeZone?: string) {
  try {
    return new Intl.DateTimeFormat("id-ID", { ...options, timeZone });
  } catch {
    return new Intl.DateTimeFormat("id-ID", options);
  }
}

export default function AttendanceBoard({ outlets, outlet, board, outsideDuties = [] }: Props) {
  const time = formatter({ hour: "2-digit", minute: "2-digit" }, outlet.timezone);
  const clock = (iso: string | null) => (iso ? time.format(new Date(iso)) : "—");
  const dayLabel = formatter({ weekday: "long", day: "numeric", month: "long", year: "numeric" }, "UTC").format(new Date(`${board.date}T00:00:00Z`));
  // Selfie absen dibandingkan berdampingan dengan foto wajah terdaftar (keputusan owner 12 November 2026).
  const [photo, setPhoto] = useState<{ src: string; title: string; face: string | null } | null>(null);
  const faceUrl = (row: Row) => (row.hasFace ? `/admin/attendance/face/${row.id}` : null);
  const go = (params: Record<string, string>) => router.get("/admin/attendance", { outlet: outlet.id, date: board.date, ...params }, { preserveScroll: true, replace: true });

  return (
    <AdminLayout>
      <Head title="Kehadiran" />
      <PageHeader title="Kehadiran" description={`${outlet.name} · ${board.isToday ? "Hari ini, " : ""}${dayLabel}`}>
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="board-date">Tanggal</Label>
            <Input id="board-date" type="date" value={board.date} className="w-44" onChange={(e) => e.target.value && go({ date: e.target.value })} />
          </div>
          {outlets.length > 1 ? (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="board-outlet">Outlet</Label>
              <NativeSelect id="board-outlet" value={outlet.id} onChange={(e) => go({ outlet: e.target.value })}>
                {outlets.map((o) => (
                  <NativeSelectOption key={o.id} value={o.id}>
                    {o.name}
                  </NativeSelectOption>
                ))}
              </NativeSelect>
            </div>
          ) : null}
          <Link href={`/admin/attendance/reports?outlet=${outlet.id}`} className={buttonVariants({ variant: "outline" })}>
            <SheetIcon /> Rekap & ekspor
          </Link>
        </div>
      </PageHeader>

      <div className="grid grid-cols-3 gap-3">
        <Count label="Bekerja" value={board.counts.working} icon={UserRoundCheckIcon} tone="text-success-strong" />
        <Count label="Sudah pulang" value={board.counts.done} icon={LogOutIcon} tone="text-muted-foreground" />
        <Count label="Belum absen" value={board.counts.absent} icon={UserRoundXIcon} tone="text-warning-strong" />
      </div>

      {outsideDuties.length > 0 ? (
        <section aria-labelledby="duty-title" className="flex flex-col gap-2 rounded-xl border border-brand/40 bg-brand-soft p-4">
          <h2 id="duty-title" className="flex items-center gap-2 text-sm font-semibold">
            <BriefcaseBusinessIcon className="size-4 text-brand-strong" aria-hidden /> Tugas luar disetujui ({outsideDuties.length})
          </h2>
          <ul className="flex flex-col gap-1 text-sm">
            {outsideDuties.map((d) => (
              <li key={d.id} className="break-words">
                <span className="font-medium">{d.employeeName}</span> · {clock(d.startsAt)}–{clock(d.endsAt)} · {d.purpose} di {d.location}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {board.rows.length === 0 ? (
        <EmptyState
          icon={UsersIcon}
          title="Belum ada karyawan aktif di outlet ini"
          description="Tambahkan karyawan di menu Karyawan dan tautkan ke akun staf agar bisa absen dari HP."
        />
      ) : (
        <ul className="flex flex-col gap-2" aria-label="Daftar kehadiran">
          {board.rows.map((row) => {
            const status = STATUS[row.status];
            return (
              <li key={row.id} className="flex flex-col gap-3 rounded-xl border bg-card p-4 md:flex-row md:items-center md:gap-6">
                <div className="flex min-w-0 flex-1 items-center gap-3">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold" aria-hidden>
                    {row.fullName.slice(0, 1).toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <p className="flex items-center gap-2 truncate font-medium">
                      {row.fullName}
                      {row.hasFace ? (
                        <button
                          type="button"
                          className="shrink-0 text-xs font-medium text-brand-strong underline-offset-4 hover:underline"
                          onClick={() => setPhoto({ src: `/admin/attendance/face/${row.id}`, title: `${row.fullName} · wajah terdaftar`, face: null })}
                        >
                          Wajah terdaftar
                        </button>
                      ) : (
                        <Badge variant="outline" className="shrink-0">
                          Wajah belum terdaftar
                        </Badge>
                      )}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {row.code}
                      {row.position ? ` · ${row.position}` : ""}
                    </p>
                  </div>
                  <span className={cn("ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium md:ml-0", status.className)}>
                    <status.icon className="size-3.5" aria-hidden /> {status.label}
                  </span>
                </div>
                {row.sessions.length === 0 ? (
                  <p className="text-sm text-muted-foreground md:w-96">Belum ada absen.</p>
                ) : (
                  <div className="flex flex-col gap-2 md:w-96">
                    {row.sessions.map((s) => (
                      <div key={s.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                        <Stamp
                          label="Masuk"
                          value={clock(s.checkInAt)}
                          distance={s.checkInDistance}
                          photo={s.hasCheckInPhoto ? `/admin/attendance/${s.id}/photo/in` : null}
                          onPhoto={(src) => setPhoto({ src, title: `${row.fullName} · masuk ${clock(s.checkInAt)}`, face: faceUrl(row) })}
                        />
                        <Stamp
                          label="Pulang"
                          value={clock(s.checkOutAt)}
                          distance={s.checkOutDistance}
                          photo={s.hasCheckOutPhoto ? `/admin/attendance/${s.id}/photo/out` : null}
                          onPhoto={(src) => setPhoto({ src, title: `${row.fullName} · pulang ${clock(s.checkOutAt)}`, face: faceUrl(row) })}
                        />
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                          <ClockIcon className="size-4" aria-hidden /> {formatMinutes(s.workingMinutes)}
                        </span>
                        {s.manual ? <Badge variant="secondary">Koreksi</Badge> : null}
                      </div>
                    ))}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}

      <p className="text-xs text-muted-foreground">
        Foto selfie hanya bisa dibuka pengguna berizin dan dihapus otomatis setelah 90 hari. Jarak dihitung server dari lokasi GPS HP terhadap titik outlet.
      </p>

      <Dialog open={photo !== null} onOpenChange={(next) => !next && setPhoto(null)}>
        <DialogContent className={photo?.face ? "sm:max-w-2xl" : "sm:max-w-md"}>
          <DialogHeader>
            <DialogTitle>{photo?.face ? "Bandingkan dengan wajah terdaftar" : photo?.src.includes("/attendance/face/") ? "Foto wajah terdaftar" : "Foto absen"}</DialogTitle>
            <DialogDescription>{photo?.title}</DialogDescription>
          </DialogHeader>
          {photo ? (
            <div className={cn("grid gap-3", photo.face && "sm:grid-cols-2")}>
              <figure className="flex flex-col gap-1">
                <img src={photo.src} alt={photo.src.includes("/attendance/face/") ? `Foto wajah terdaftar ${photo.title}` : `Selfie ${photo.title}`} className="w-full rounded-xl object-contain" />
                {photo.face ? <figcaption className="text-center text-sm text-muted-foreground">Selfie absen</figcaption> : null}
              </figure>
              {photo.face ? (
                <figure className="flex flex-col gap-1">
                  <img src={photo.face} alt="Foto wajah terdaftar" className="w-full rounded-xl object-contain" />
                  <figcaption className="text-center text-sm text-muted-foreground">Wajah terdaftar</figcaption>
                </figure>
              ) : null}
            </div>
          ) : null}
          {photo && photo.face === null && photo.title.endsWith("wajah terdaftar") ? (
            <button
              type="button"
              className="text-sm font-medium text-destructive-strong underline-offset-4 hover:underline"
              onClick={() => {
                const id = photo.src.split("/").pop();
                if (id && window.confirm("Reset foto wajah? Karyawan harus mendaftarkan ulang foto wajahnya sebelum absen.")) {
                  router.post(`/admin/attendance/face/${id}/reset`, {}, { preserveScroll: true, onSuccess: () => setPhoto(null) });
                }
              }}
            >
              Reset foto wajah (daftar ulang)
            </button>
          ) : null}
        </DialogContent>
      </Dialog>
    </AdminLayout>
  );
}

function Count({ label, value, icon: Icon, tone }: { label: string; value: number; icon: typeof ClockIcon; tone: string }) {
  return (
    <div className="flex flex-col gap-1 rounded-xl bg-card p-4 ring-1 ring-foreground/10">
      <p className="flex items-center gap-1.5 text-xs text-muted-foreground sm:text-sm">
        <Icon className={cn("size-4 shrink-0", tone)} aria-hidden /> {label}
      </p>
      <p className="text-2xl font-semibold" data-testid={`count-${label}`}>
        {value}
      </p>
    </div>
  );
}

function Stamp({ label, value, distance, photo, onPhoto }: { label: string; value: string; distance: number | null; photo: string | null; onPhoto: (src: string) => void }) {
  return (
    <span className="flex items-center gap-2">
      {photo ? (
        <button
          type="button"
          onClick={() => onPhoto(photo)}
          className="size-11 shrink-0 overflow-hidden rounded-lg ring-1 ring-foreground/10 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
          aria-label={`Lihat foto ${label.toLowerCase()}`}
        >
          <img src={photo} alt="" loading="lazy" className="size-full object-cover" />
        </button>
      ) : (
        <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground" aria-hidden>
          <ImageOffIcon className="size-4" />
        </span>
      )}
      <span className="flex flex-col leading-tight">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="font-medium">
          {value}
          {distance !== null ? <span className="font-normal text-muted-foreground"> · {distance} m</span> : null}
        </span>
      </span>
    </span>
  );
}
