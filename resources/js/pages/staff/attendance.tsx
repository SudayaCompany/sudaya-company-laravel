import { Link, router } from "@inertiajs/react";
import {
  BriefcaseBusinessIcon,
  CameraIcon,
  CircleCheckIcon,
  HistoryIcon,
  Loader2Icon,
  LogInIcon,
  LogOutIcon,
  MapPinIcon,
  RefreshCwIcon,
  ScanFaceIcon,
  ShieldCheckIcon,
  TriangleAlertIcon,
  UserRoundXIcon,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { InstallApp } from "@/components/install-app";
import { EmptyState } from "@/components/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { checkLocation, currentPosition, formatMinutes, shrinkPhoto, type LocationCheck, type Position } from "@/lib/attendance";
import { useInlineFlashError } from "@/lib/feedback";
import { cn } from "@/lib/utils";
import { StaffShell } from "@/layouts/staff-shell";

type Employee = {
  id: string;
  code: string;
  fullName: string;
  position: string | null;
  outletName: string;
  timezone: string;
  isActive: boolean;
  geofenceReady: boolean;
  radius: number;
};
type Session = { id: string; checkInAt: string | null; checkOutAt: string | null; workingMinutes: number | null; manual: boolean };
type Props = {
  employee: Employee | null;
  settings: { maxAccuracy: number; policyVersion: string };
  businessDate?: string;
  open?: { id: string; checkInAt: string | null; businessDate: string; fromEarlierDay: boolean } | null;
  today?: Session[];
  pendingCorrections?: number;
  outsideDuty?: { location: string; endsAt: string | null } | null;
  /** Foto wajah terdaftar (null = belum daftar; wajib sebelum absen pertama). */
  face?: { registeredAt: string } | null;
};

/** `at` = waktu posisi diterima di perangkat (ms), untuk menghitung umurnya saat dikirim. */
type Located = { position: Position; check: LocationCheck; at: number };

/** Posisi lebih tua dari ini diambil ulang sebelum dikirim (server menolak > 2 menit). */
const REFRESH_AFTER_MS = 60_000;

function timeFormat(timezone: string) {
  try {
    return new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", timeZone: timezone });
  } catch {
    return new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit" });
  }
}

/** Menit sejak waktu ISO, diperbarui tiap 30 detik. */
function useElapsed(since: string | null | undefined) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!since) return;
    const timer = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(timer);
  }, [since]);
  return since ? Math.max(0, Math.floor((now - new Date(since).getTime()) / 60_000)) : null;
}

export default function StaffAttendance({ employee, settings, open = null, today = [], pendingCorrections = 0, outsideDuty = null, face = null }: Props) {
  useInlineFlashError();
  const fileInput = useRef<HTMLInputElement>(null);
  const [photo, setPhoto] = useState<{ file: File; url: string } | null>(null);
  const [located, setLocated] = useState<Located | null>(null);
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const elapsed = useElapsed(open?.checkInAt);

  // Bersihkan object URL pratinjau foto.
  useEffect(() => () => (photo ? URL.revokeObjectURL(photo.url) : undefined), [photo]);

  if (employee === null) {
    return (
      <Shell>
        <EmptyState
          icon={UserRoundXIcon}
          title="Akun belum terhubung ke data karyawan"
          description="Minta admin menautkan akun Anda di menu Karyawan, lalu buka halaman ini lagi."
        />
      </Shell>
    );
  }

  const time = timeFormat(employee.timezone);
  const clock = (iso: string | null) => (iso ? time.format(new Date(iso)) : "—");
  const mode: "in" | "out" = open ? "out" : "in";
  const blocked = !employee.isActive ? "Status karyawan Anda nonaktif. Hubungi admin." : !employee.geofenceReady ? "Lokasi outlet untuk absen belum diatur. Minta admin mengatur di Pengaturan absen." : null;
  const doneToday = !open && today.length > 0;

  // Absen pertama: daftarkan foto wajah dulu (pembanding selfie yang diperiksa admin).
  if (face === null && blocked === null) {
    return (
      <Shell>
        <section className="flex flex-col gap-1 rounded-2xl bg-espresso p-5 text-espresso-foreground">
          <p className="text-sm text-espresso-foreground/70">
            {employee.fullName} · {employee.outletName}
          </p>
          <p className="text-2xl font-semibold">Selamat datang!</p>
          <p className="text-sm text-espresso-foreground/80">Daftarkan foto wajah sekali, lalu Anda bisa absen setiap hari dari sini.</p>
        </section>
        <FaceRegistration />
      </Shell>
    );
  }

  async function locate() {
    setLocating(true);
    setLocationError(null);
    setLocated(null);
    try {
      const position = await currentPosition();
      const result = await checkLocation(position);
      if (!result.ok) setLocationError(result.error);
      else setLocated({ position, check: result.result, at: Date.now() });
    } catch (e) {
      setLocationError(e instanceof Error ? e.message : "Lokasi tidak bisa dibaca. Coba lagi.");
    } finally {
      setLocating(false);
    }
  }

  async function onPhoto(file: File | undefined) {
    if (!file) return;
    setError(null);
    try {
      const blob = await shrinkPhoto(file);
      const selfie = new File([blob], "selfie.jpg", { type: "image/jpeg" });
      setPhoto({ file: selfie, url: URL.createObjectURL(blob) });
      if (!located || Date.now() - located.at > REFRESH_AFTER_MS) void locate();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Foto tidak bisa diproses. Ambil ulang.");
    }
  }

  function reset() {
    setPhoto(null);
    setLocated(null);
    setLocationError(null);
    setError(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  async function send() {
    if (!photo || !located) return;
    setSending(true);
    setError(null);
    // Posisi yang sudah dicek dipakai bila masih segar; umurnya dihitung sampai saat kirim.
    let position: Position = { ...located.position, ageMs: located.position.ageMs + (Date.now() - located.at) };
    if (Date.now() - located.at > REFRESH_AFTER_MS) {
      try {
        position = await currentPosition();
      } catch (e) {
        setSending(false);
        setLocationError(e instanceof Error ? e.message : "Lokasi tidak bisa dibaca. Coba lagi.");
        return;
      }
    }
    router.post(
      mode === "in" ? "/staff/attendance/check-in" : "/staff/attendance/check-out",
      { latitude: position.latitude, longitude: position.longitude, accuracy: position.accuracy, ageMs: position.ageMs, photo: photo.file },
      {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => setSending(false),
        onFlash: (flash) => {
          const f = flash as { success?: string; error?: string };
          if (f.success) reset();
          if (f.error) setError(f.error);
        },
      },
    );
  }

  const ok = located?.check.ok === true;

  return (
    <Shell>
      <section className="flex flex-col gap-1 rounded-2xl bg-espresso p-5 text-espresso-foreground" aria-live="polite">
        <p className="text-sm text-espresso-foreground/70">
          {employee.fullName} · {employee.outletName}
        </p>
        <p className="text-2xl font-semibold" data-testid="attendance-status">
          {open ? "Sedang bekerja" : doneToday ? "Sudah absen pulang" : "Belum absen hari ini"}
        </p>
        <p className="text-sm text-espresso-foreground/80">
          {open
            ? `Masuk ${clock(open.checkInAt)} · ${formatMinutes(elapsed)}`
            : doneToday
              ? `Total hari ini ${formatMinutes(today.reduce((sum, s) => sum + (s.workingMinutes ?? 0), 0))}`
              : "Absen masuk saat mulai bertugas di outlet."}
        </p>
      </section>

      {open?.fromEarlierDay ? (
        <Notice tone="warning">
          Anda belum absen pulang dari tanggal {open.businessDate}. Absen pulang sekarang, atau ajukan koreksi jam pulang di{" "}
          <Link href="/staff/attendance/history" className="font-medium underline">
            Riwayat & koreksi
          </Link>
          .
        </Notice>
      ) : null}
      {outsideDuty ? (
        <Notice tone="info">
          <span className="font-medium">Tugas luar aktif</span> di {outsideDuty.location} sampai {clock(outsideDuty.endsAt)}. Absen boleh dari lokasi tugas;
          GPS dan selfie tetap dicatat.
        </Notice>
      ) : null}
      {blocked ? <Notice tone="warning">{blocked}</Notice> : null}

      <input
        ref={fileInput}
        type="file"
        accept="image/*"
        capture="user"
        className="sr-only"
        tabIndex={-1}
        aria-hidden
        onChange={(e) => void onPhoto(e.target.files?.[0])}
      />

      {photo === null ? (
        <Button
          size="kiosk"
          variant={mode === "in" ? "default" : "success"}
          className="w-full"
          disabled={blocked !== null}
          onClick={() => {
            setError(null);
            fileInput.current?.click();
          }}
        >
          {mode === "in" ? <LogInIcon /> : <LogOutIcon />}
          {mode === "in" ? "Absen masuk" : "Absen pulang"}
        </Button>
      ) : (
        <section className="flex flex-col gap-4 rounded-2xl border bg-card p-4" aria-label="Periksa sebelum kirim">
          <div className="flex items-center gap-4">
            <img src={photo.url} alt="Pratinjau selfie" className="size-24 shrink-0 rounded-2xl object-cover ring-1 ring-foreground/10" />
            <div className="flex min-w-0 flex-col gap-1.5 text-sm">
              <p className="flex items-center gap-1.5 font-medium text-success-strong">
                <CircleCheckIcon className="size-4 shrink-0" aria-hidden /> Selfie siap
              </p>
              <LocationStatus locating={locating} located={located} error={locationError} />
            </div>
          </div>
          {locationError || (located && !ok) ? (
            <Button variant="outline" onClick={() => void locate()} disabled={locating}>
              <RefreshCwIcon className={cn(locating && "animate-spin motion-reduce:animate-none")} aria-hidden /> Cek lokasi lagi
            </Button>
          ) : null}
          {error ? (
            <p role="alert" className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
              <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden /> {error}
            </p>
          ) : null}
          <Button size="xl" variant={mode === "in" ? "default" : "success"} disabled={!ok || sending || locating} onClick={() => void send()}>
            {sending ? <Loader2Icon className="animate-spin" aria-hidden /> : mode === "in" ? <LogInIcon aria-hidden /> : <LogOutIcon aria-hidden />}
            {mode === "in" ? "Kirim absen masuk" : "Kirim absen pulang"}
          </Button>
          <div className="grid grid-cols-2 gap-2">
            <Button variant="outline" disabled={sending} onClick={() => fileInput.current?.click()}>
              <CameraIcon aria-hidden /> Ulangi foto
            </Button>
            <Button variant="ghost" disabled={sending} onClick={reset}>
              Batal
            </Button>
          </div>
        </section>
      )}

      {error && photo === null ? (
        <p role="alert" className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive-strong">
          <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden /> {error}
        </p>
      ) : null}

      <section className="flex flex-col gap-2" aria-labelledby="today-title">
        <h2 id="today-title" className="text-sm font-semibold">
          Absen hari ini
        </h2>
        {today.length === 0 ? (
          <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">Belum ada absen hari ini.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {today.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 rounded-xl border bg-card p-3 text-sm">
                <span>
                  Masuk <strong>{clock(s.checkInAt)}</strong> · Pulang <strong>{clock(s.checkOutAt)}</strong>
                </span>
                <span className="flex items-center gap-2 text-muted-foreground">
                  {s.manual ? <Badge variant="secondary">Koreksi</Badge> : null}
                  {formatMinutes(s.workingMinutes)}
                </span>
              </li>
            ))}
          </ul>
        )}
        {pendingCorrections > 0 ? (
          <p className="text-sm text-muted-foreground">{pendingCorrections} koreksi menunggu persetujuan.</p>
        ) : null}
      </section>

      <Link
        href="/staff/attendance/history"
        className="flex min-h-12 items-center gap-3 rounded-xl border bg-card px-4 text-sm font-medium transition-colors hover:border-brand focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      >
        <HistoryIcon className="size-5 text-brand-strong" aria-hidden />
        <span className="flex-1">Riwayat & koreksi absen</span>
      </Link>
      <Link
        href="/staff/outside-duty"
        className="flex min-h-12 items-center gap-3 rounded-xl border bg-card px-4 text-sm font-medium transition-colors hover:border-brand focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      >
        <BriefcaseBusinessIcon className="size-5 text-brand-strong" aria-hidden />
        <span className="flex-1">Tugas luar outlet</span>
      </Link>

      <p className="flex items-start gap-2 text-xs text-muted-foreground">
        <ShieldCheckIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
        Foto dan lokasi hanya dipakai sebagai bukti kehadiran. Foto dihapus otomatis setelah 90 hari. Lokasi dicek dengan radius {employee.radius} m dari
        outlet (kecuali saat tugas luar disetujui) dan akurasi GPS maksimal ±{settings.maxAccuracy} m.
      </p>
    </Shell>
  );
}

function LocationStatus({ locating, located, error }: { locating: boolean; located: Located | null; error: string | null }) {
  if (locating) {
    return (
      <p className="flex items-center gap-1.5 text-muted-foreground">
        <Loader2Icon className="size-4 shrink-0 animate-spin" aria-hidden /> Mengecek lokasi…
      </p>
    );
  }
  if (error) {
    return (
      <p className="flex items-start gap-1.5 text-destructive-strong" role="alert">
        <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden /> {error}
      </p>
    );
  }
  if (!located) return null;
  const { check } = located;
  if (!check.ok) {
    return (
      <p className="flex items-start gap-1.5 text-destructive-strong" role="alert" data-testid="location-result">
        <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden /> {check.message}
      </p>
    );
  }
  if (check.mode === "outside_duty") {
    return (
      <p className="flex items-start gap-1.5 text-success-strong" data-testid="location-result">
        <BriefcaseBusinessIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
        Lokasi tercatat (tugas luar) · akurasi ±{check.accuracy} m
      </p>
    );
  }
  return (
    <p className="flex items-start gap-1.5 text-success-strong" data-testid="location-result">
      <MapPinIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
      Lokasi sesuai{check.distance !== null ? ` · ${Math.round(check.distance)} m dari outlet` : ""} · akurasi ±{check.accuracy} m
    </p>
  );
}

/** Daftar foto wajah sekali (keputusan owner 12 November 2026). Menggantinya lewat reset admin. */
function FaceRegistration() {
  const input = useRef<HTMLInputElement>(null);
  const [photo, setPhoto] = useState<{ file: File; url: string } | null>(null);
  const [agree, setAgree] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => () => (photo ? URL.revokeObjectURL(photo.url) : undefined), [photo]);

  async function onPhoto(file: File | undefined) {
    if (!file) return;
    setError(null);
    try {
      const blob = await shrinkPhoto(file);
      setPhoto({ file: new File([blob], "wajah.jpg", { type: "image/jpeg" }), url: URL.createObjectURL(blob) });
    } catch (e) {
      setError(e instanceof Error ? e.message : "Foto tidak bisa diproses. Ambil ulang.");
    }
  }

  function save() {
    if (!photo || !agree) return;
    router.post("/staff/attendance/face", { photo: photo.file, consent: agree ? "1" : "0" }, { forceFormData: true, preserveScroll: true, onStart: () => setSending(true), onFinish: () => setSending(false) });
  }

  return (
    <section className="flex flex-col gap-4 rounded-2xl border bg-card p-5" data-testid="face-registration">
      <div className="flex items-start gap-3">
        <ScanFaceIcon className="size-8 shrink-0 text-brand" aria-hidden />
        <div>
          <h2 className="text-lg font-semibold">Daftarkan foto wajah</h2>
          <p className="text-sm text-muted-foreground">
            Sekali saja sebelum absen pertama. Foto ini jadi pembanding selfie absen yang diperiksa admin. Ambil di tempat terang, wajah menghadap kamera, tanpa
            masker atau kacamata hitam.
          </p>
        </div>
      </div>
      <input ref={input} type="file" accept="image/*" capture="user" className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => void onPhoto(e.target.files?.[0])} />
      {photo ? <img src={photo.url} alt="Pratinjau foto wajah" className="mx-auto size-48 rounded-2xl object-cover ring-1 ring-foreground/10" /> : null}
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" className="mt-0.5 size-5 shrink-0 accent-brand" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
        Saya setuju foto wajah saya disimpan Sudaya Coffee untuk pemeriksaan absen dan hanya bisa dilihat admin.
      </label>
      {error ? (
        <p role="alert" className="text-sm font-medium text-destructive-strong">
          {error}
        </p>
      ) : null}
      <div className="flex gap-2">
        <Button size="lg" variant={photo ? "outline" : "default"} className="flex-1" onClick={() => input.current?.click()}>
          <CameraIcon /> {photo ? "Ambil ulang" : "Ambil foto wajah"}
        </Button>
        {photo ? (
          <Button size="lg" className="flex-1" disabled={!agree || sending} onClick={save}>
            {sending ? <Loader2Icon className="animate-spin" /> : <CircleCheckIcon />} Simpan
          </Button>
        ) : null}
      </div>
    </section>
  );
}

function Notice({ tone, children }: { tone: "warning" | "info"; children: React.ReactNode }) {
  const Icon = tone === "info" ? BriefcaseBusinessIcon : TriangleAlertIcon;
  return (
    <p className={cn("flex items-start gap-2 rounded-xl border p-3 text-sm", tone === "warning" ? "border-warning/50 bg-warning/10" : "border-brand/40 bg-brand-soft")}>
      <Icon className={cn("mt-0.5 size-4 shrink-0", tone === "warning" ? "text-warning-strong" : "text-brand-strong")} aria-hidden />
      <span>{children}</span>
    </p>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <StaffShell title="Absen">
      <h1 className="sr-only">Absen</h1>
      <InstallApp />
      {children}
    </StaffShell>
  );
}
