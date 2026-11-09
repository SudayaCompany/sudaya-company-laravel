import { Head, Link, router } from "@inertiajs/react";
import { ArrowLeftIcon, CalendarClockIcon, ClipboardPenIcon, Loader2Icon } from "lucide-react";
import { useState } from "react";

import { Brand } from "@/components/brand";
import { EmptyState } from "@/components/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect, NativeSelectOption } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { formatMinutes } from "@/lib/attendance";
import { useInlineFlashError } from "@/lib/feedback";

type Row = { id: string; businessDate: string; checkInAt: string | null; checkOutAt: string | null; workingMinutes: number | null; manual: boolean };
type Props = { month: string; timezone: string; rows: Row[]; totals: { days: number; minutes: number } };

function formatter(options: Intl.DateTimeFormatOptions, timeZone?: string) {
  try {
    return new Intl.DateTimeFormat("id-ID", { ...options, timeZone });
  } catch {
    return new Intl.DateTimeFormat("id-ID", options);
  }
}

export default function AttendanceHistory({ month, timezone, rows, totals }: Props) {
  const time = formatter({ hour: "2-digit", minute: "2-digit" }, timezone);
  const day = formatter({ weekday: "long", day: "numeric", month: "long" }, "UTC");
  const clock = (iso: string | null) => (iso ? time.format(new Date(iso)) : "—");
  const openSessions = rows.filter((r) => r.checkOutAt === null);

  return (
    <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-5 px-4 py-5">
      <Head title="Riwayat absen" />
      <header className="flex items-center justify-between gap-3">
        <Link
          href="/staff/attendance"
          className="inline-flex min-h-11 items-center gap-1.5 rounded-lg pr-2 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
          <ArrowLeftIcon className="size-4" aria-hidden /> Absen
        </Link>
        <Brand className="text-lg" />
      </header>

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Riwayat absen</h1>
          <p className="text-sm text-muted-foreground">
            {totals.days} hari hadir · {formatMinutes(totals.minutes)}
          </p>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="history-month">Bulan</Label>
          <Input
            id="history-month"
            type="month"
            value={month}
            className="w-44"
            onChange={(e) => e.target.value && router.get("/staff/attendance/history", { month: e.target.value }, { preserveScroll: true, replace: true })}
          />
        </div>
      </div>

      <CorrectionDialog openSessions={openSessions} clock={clock} day={day} />

      {rows.length === 0 ? (
        <EmptyState icon={CalendarClockIcon} title="Belum ada absen di bulan ini" description="Absen masuk dan pulang akan tercatat di sini." />
      ) : (
        <ul className="flex flex-col gap-2">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-col gap-1 rounded-xl border bg-card p-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <span className="font-medium">{day.format(new Date(`${r.businessDate}T00:00:00Z`))}</span>
                <span className="text-muted-foreground">{formatMinutes(r.workingMinutes)}</span>
              </div>
              <div className="flex items-center justify-between gap-3 text-muted-foreground">
                <span>
                  Masuk {clock(r.checkInAt)} · Pulang {r.checkOutAt ? clock(r.checkOutAt) : <span className="text-warning-strong">belum absen pulang</span>}
                </span>
                {r.manual ? <Badge variant="secondary">Koreksi</Badge> : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Pengajuan koreksi: lupa absen (masuk + pulang) atau lupa absen pulang. Disetujui Manager/Owner. */
function CorrectionDialog({ openSessions, clock, day }: { openSessions: Row[]; clock: (iso: string | null) => string; day: Intl.DateTimeFormat }) {
  useInlineFlashError();
  const [open, setOpen] = useState(false);
  const [kind, setKind] = useState<"missing_attendance" | "missing_check_out">(openSessions.length > 0 ? "missing_check_out" : "missing_attendance");
  const [attendanceId, setAttendanceId] = useState(openSessions[0]?.id ?? "");
  const [checkIn, setCheckIn] = useState("");
  const [checkOut, setCheckOut] = useState("");
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const missingOut = kind === "missing_check_out";
  const valid = reason.trim().length >= 3 && checkOut !== "" && (missingOut ? attendanceId !== "" : checkIn !== "");

  function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    router.post(
      "/staff/attendance/corrections",
      { kind, attendanceId: missingOut ? attendanceId : null, checkIn: missingOut ? null : checkIn, checkOut, reason },
      {
        preserveScroll: true,
        onStart: () => setPending(true),
        onFinish: () => setPending(false),
        onFlash: (flash) => {
          const f = flash as { success?: string; error?: string };
          if (f.error) setError(f.error);
          if (f.success) {
            setOpen(false);
            setReason("");
            setCheckIn("");
            setCheckOut("");
          }
        },
      },
    );
  }

  return (
    <>
      <Button variant="outline" size="lg" className="w-full" onClick={() => setOpen(true)}>
        <ClipboardPenIcon aria-hidden /> Ajukan koreksi absen
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Ajukan koreksi absen</DialogTitle>
            <DialogDescription>Untuk lupa absen atau GPS bermasalah. Berlaku setelah disetujui Manager atau Owner.</DialogDescription>
          </DialogHeader>
          <form id="correction-form" className="flex flex-col gap-4" onSubmit={submit}>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="correction-kind">Jenis koreksi</Label>
              <NativeSelect id="correction-kind" className="w-full" value={kind} onChange={(e) => setKind(e.target.value as typeof kind)}>
                <NativeSelectOption value="missing_attendance">Lupa absen (masuk dan pulang)</NativeSelectOption>
                <NativeSelectOption value="missing_check_out" disabled={openSessions.length === 0}>
                  Lupa absen pulang
                </NativeSelectOption>
              </NativeSelect>
            </div>
            {missingOut ? (
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="correction-session">Sesi yang belum absen pulang</Label>
                <NativeSelect id="correction-session" className="w-full" value={attendanceId} onChange={(e) => setAttendanceId(e.target.value)}>
                  {openSessions.map((s) => (
                    <NativeSelectOption key={s.id} value={s.id}>
                      {day.format(new Date(`${s.businessDate}T00:00:00Z`))}, masuk {clock(s.checkInAt)}
                    </NativeSelectOption>
                  ))}
                </NativeSelect>
              </div>
            ) : (
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="correction-in">Jam masuk</Label>
                <Input id="correction-in" type="datetime-local" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} required />
              </div>
            )}
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="correction-out">Jam pulang</Label>
              <Input id="correction-out" type="datetime-local" value={checkOut} onChange={(e) => setCheckOut(e.target.value)} required />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="correction-reason">Alasan</Label>
              <Textarea id="correction-reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} placeholder="Mis. HP mati saat jam pulang" />
              <p className="text-xs text-muted-foreground">Wajib, minimal 3 karakter.</p>
            </div>
            {error ? (
              <p role="alert" className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
                {error}
              </p>
            ) : null}
          </form>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={pending}>
              Batal
            </Button>
            <Button type="submit" form="correction-form" disabled={!valid || pending}>
              {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : null}
              Kirim koreksi
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
