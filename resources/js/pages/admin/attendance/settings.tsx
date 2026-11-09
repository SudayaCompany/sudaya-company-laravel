import { Head, router } from "@inertiajs/react";
import { CrosshairIcon, Loader2Icon, MapPinIcon, SaveIcon } from "lucide-react";
import { useState } from "react";

import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { AdminLayout } from "@/layouts/admin-layout";
import { currentPosition } from "@/lib/attendance";

type Outlet = { id: string; name: string; latitude: number | null; longitude: number | null; radius: number; configured: boolean };
type Props = { outlets: Outlet[]; maxAccuracy: number; canEditCompany: boolean };

export default function AttendanceSettings({ outlets, maxAccuracy, canEditCompany }: Props) {
  return (
    <AdminLayout>
      <Head title="Pengaturan absen" />
      <PageHeader
        title="Pengaturan absen"
        description="Titik lokasi dan radius tiap outlet untuk absen GPS + selfie. Paling akurat diatur sambil berdiri di dalam outlet."
      />
      <div className="grid max-w-4xl gap-4">
        {outlets.map((outlet) => (
          <GeofenceCard key={outlet.id} outlet={outlet} />
        ))}
        <AccuracyCard value={maxAccuracy} editable={canEditCompany} />
      </div>
    </AdminLayout>
  );
}

function GeofenceCard({ outlet }: { outlet: Outlet }) {
  const [latitude, setLatitude] = useState(outlet.latitude?.toString() ?? "");
  const [longitude, setLongitude] = useState(outlet.longitude?.toString() ?? "");
  const [radius, setRadius] = useState(String(outlet.radius));
  const [locating, setLocating] = useState(false);
  const [hint, setHint] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function useHere() {
    setLocating(true);
    setHint(null);
    try {
      const p = await currentPosition();
      setLatitude(p.latitude.toFixed(7));
      setLongitude(p.longitude.toFixed(7));
      setHint(`Lokasi perangkat terbaca (akurasi ±${Math.round(p.accuracy)} m). Periksa lalu simpan.`);
    } catch (e) {
      setHint(e instanceof Error ? e.message : "Lokasi tidak bisa dibaca.");
    } finally {
      setLocating(false);
    }
  }

  function save(e: React.FormEvent) {
    e.preventDefault();
    router.post(
      `/admin/attendance/settings/outlets/${outlet.id}`,
      { latitude, longitude, radius },
      { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) },
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          <MapPinIcon className="size-4" aria-hidden /> {outlet.name}
          {outlet.configured ? <Badge className="bg-success/15 text-success-strong">Siap dipakai</Badge> : <Badge variant="outline">Belum diatur</Badge>}
        </CardTitle>
        <CardDescription>Staf hanya bisa absen bila GPS HP-nya berada di dalam radius titik ini.</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-4" onSubmit={save}>
          <div className="grid gap-3 sm:grid-cols-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={`lat-${outlet.id}`}>Latitude</Label>
              <Input id={`lat-${outlet.id}`} inputMode="decimal" value={latitude} onChange={(e) => setLatitude(e.target.value)} placeholder="-6.914744" />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={`lng-${outlet.id}`}>Longitude</Label>
              <Input id={`lng-${outlet.id}`} inputMode="decimal" value={longitude} onChange={(e) => setLongitude(e.target.value)} placeholder="107.609810" />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor={`radius-${outlet.id}`}>Radius (meter)</Label>
              <Input id={`radius-${outlet.id}`} type="number" inputMode="numeric" min={5} max={1000} value={radius} onChange={(e) => setRadius(e.target.value)} />
            </div>
          </div>
          <p className="text-xs text-muted-foreground">
            Disarankan 30 m: GPS HP di dalam ruangan biasanya meleset 10–30 m. Koordinat bisa juga disalin dari Google Maps (klik kanan titik outlet).
          </p>
          {hint ? <p className="text-sm text-muted-foreground" aria-live="polite">{hint}</p> : null}
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => void useHere()} disabled={locating}>
              {locating ? <Loader2Icon className="animate-spin" aria-hidden /> : <CrosshairIcon aria-hidden />}
              Pakai lokasi perangkat ini
            </Button>
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SaveIcon aria-hidden />}
              Simpan lokasi
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function AccuracyCard({ value, editable }: { value: number; editable: boolean }) {
  const [accuracy, setAccuracy] = useState(String(value));
  const [pending, setPending] = useState(false);
  return (
    <Card>
      <CardHeader>
        <CardTitle>Batas akurasi GPS</CardTitle>
        <CardDescription>
          Absen ditolak bila HP melaporkan akurasi lebih buruk dari batas ini (jarak tidak bisa dipercaya). Berlaku untuk semua outlet.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            router.post("/admin/attendance/settings/accuracy", { maxAccuracy: accuracy }, { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) });
          }}
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="max-accuracy">Akurasi maksimal (meter)</Label>
            <Input id="max-accuracy" type="number" inputMode="numeric" min={5} max={1000} className="w-44" value={accuracy} disabled={!editable} onChange={(e) => setAccuracy(e.target.value)} />
          </div>
          {editable ? (
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2Icon className="animate-spin" aria-hidden /> : <SaveIcon aria-hidden />}
              Simpan
            </Button>
          ) : (
            <p className="text-sm text-muted-foreground">Hanya Super Admin yang dapat mengubah batas ini.</p>
          )}
        </form>
      </CardContent>
    </Card>
  );
}
