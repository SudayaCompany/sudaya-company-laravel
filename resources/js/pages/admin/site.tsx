import { Head, router } from "@inertiajs/react";
import { ArrowUpRightIcon, Loader2Icon, PlusIcon, SaveIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { PageHeader } from "@/components/page-header";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { AdminLayout } from "@/layouts/admin-layout";

type Training = { title: string; schedule: string | null; location: string | null; description: string | null; url: string | null };
type Content = {
  about: string;
  programs: { title: string; body: string }[];
  donationUrl: string | null;
  whatsapp: string | null;
  instagram: string | null;
  email: string | null;
  trainings: Training[];
};

const MAX_TRAININGS = 12;
const EMPTY_TRAINING: Training = { title: "", schedule: "", location: "", description: "", url: "" };

/** Admin → Konten situs: isi halaman depan publik (tanpa login). */
export default function SiteContentPage({ brand, content }: { brand: { id: string; name: string }; content: Content }) {
  const [about, setAbout] = useState(content.about);
  const [programs, setPrograms] = useState(content.programs);
  const [donationUrl, setDonationUrl] = useState(content.donationUrl ?? "");
  const [whatsapp, setWhatsapp] = useState(content.whatsapp ? `0${content.whatsapp.replace(/^62/, "")}` : "");
  const [instagram, setInstagram] = useState(content.instagram ? `@${content.instagram}` : "");
  const [email, setEmail] = useState(content.email ?? "");
  const [trainings, setTrainings] = useState<Training[]>(content.trainings);
  const [pending, setPending] = useState(false);

  const setProgram = (index: number, key: "title" | "body", value: string) => setPrograms(programs.map((p, i) => (i === index ? { ...p, [key]: value } : p)));
  const setTraining = (index: number, key: keyof Training, value: string) => setTrainings(trainings.map((t, i) => (i === index ? { ...t, [key]: value } : t)));

  const save = () =>
    router.post(
      "/admin/site",
      { about, programs, donationUrl, whatsapp, instagram, email, trainings },
      { preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false) },
    );

  return (
    <AdminLayout breadcrumbs={[{ label: "Konten situs" }]}>
      <Head title="Konten situs" />
      <PageHeader title="Konten situs" description={`Isi halaman depan ${brand.name} yang bisa dilihat siapa pun tanpa login.`}>
        <a href="/" target="_blank" rel="noopener" className={buttonVariants({ variant: "outline" })}>
          Lihat halaman depan <ArrowUpRightIcon />
        </a>
      </PageHeader>
      <form
        className="flex flex-col gap-6"
        onSubmit={(e) => {
          e.preventDefault();
          save();
        }}
      >
        <Card>
          <CardHeader>
            <CardTitle>Tentang Sudaya Coffee</CardTitle>
            <CardDescription>Penjelasan singkat tentang Sudaya. Pisahkan paragraf dengan baris kosong.</CardDescription>
          </CardHeader>
          <CardContent>
            <Label htmlFor="about" className="sr-only">
              Tentang Sudaya Coffee
            </Label>
            <Textarea id="about" value={about} onChange={(e) => setAbout(e.target.value)} maxLength={1200} rows={5} required />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Program</CardTitle>
            <CardDescription>Dua program yang tampil di halaman depan.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-6 md:grid-cols-2">
            {programs.map((program, index) => (
              <div key={index} className="flex flex-col gap-3">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`program-title-${index}`}>Judul program {index + 1}</Label>
                  <Input id={`program-title-${index}`} value={program.title} onChange={(e) => setProgram(index, "title", e.target.value)} maxLength={60} required />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`program-body-${index}`}>Penjelasan</Label>
                  <Textarea id={`program-body-${index}`} value={program.body} onChange={(e) => setProgram(index, "body", e.target.value)} maxLength={500} rows={4} required />
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Info pelatihan</CardTitle>
            <CardDescription>Jadwal pelatihan dari Sudaya. Kosong = halaman depan menulis "jadwal segera diumumkan".</CardDescription>
            <CardAction>
              <Button type="button" variant="outline" disabled={trainings.length >= MAX_TRAININGS} onClick={() => setTrainings([...trainings, { ...EMPTY_TRAINING }])}>
                <PlusIcon /> Tambah pelatihan
              </Button>
            </CardAction>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {trainings.length === 0 ? <p className="text-sm text-muted-foreground">Belum ada info pelatihan.</p> : null}
            {trainings.map((training, index) => (
              <fieldset key={index} className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                <legend className="px-1 text-sm font-medium">Pelatihan {index + 1}</legend>
                <div className="flex flex-col gap-1.5 sm:col-span-2">
                  <Label htmlFor={`training-title-${index}`}>Judul</Label>
                  <Input id={`training-title-${index}`} value={training.title} onChange={(e) => setTraining(index, "title", e.target.value)} maxLength={100} placeholder="mis. Kelas Dasar Barista" required />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`training-schedule-${index}`}>Jadwal</Label>
                  <Input id={`training-schedule-${index}`} value={training.schedule ?? ""} onChange={(e) => setTraining(index, "schedule", e.target.value)} maxLength={100} placeholder="mis. Sabtu, 09.00–12.00" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor={`training-location-${index}`}>Lokasi</Label>
                  <Input id={`training-location-${index}`} value={training.location ?? ""} onChange={(e) => setTraining(index, "location", e.target.value)} maxLength={120} />
                </div>
                <div className="flex flex-col gap-1.5 sm:col-span-2">
                  <Label htmlFor={`training-description-${index}`}>Keterangan</Label>
                  <Textarea id={`training-description-${index}`} value={training.description ?? ""} onChange={(e) => setTraining(index, "description", e.target.value)} maxLength={500} rows={2} />
                </div>
                <div className="flex flex-col gap-1.5 sm:col-span-2">
                  <Label htmlFor={`training-url-${index}`}>
                    Tautan daftar <span className="text-xs font-normal text-muted-foreground">(opsional, https://)</span>
                  </Label>
                  <Input id={`training-url-${index}`} type="url" inputMode="url" value={training.url ?? ""} onChange={(e) => setTraining(index, "url", e.target.value)} maxLength={300} placeholder="https://" />
                </div>
                <div className="sm:col-span-2">
                  <Button type="button" variant="ghost" onClick={() => setTrainings(trainings.filter((_, i) => i !== index))}>
                    <Trash2Icon /> Hapus pelatihan ini
                  </Button>
                </div>
              </fieldset>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Sedekah & kontak</CardTitle>
            <CardDescription>Tautan sedekah membuka situs Sahabat Berbagi. Kontak tampil di footer bila diisi.</CardDescription>
          </CardHeader>
          <CardContent className="grid max-w-3xl gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5 sm:col-span-2">
              <Label htmlFor="donation-url">Tautan sedekah (Sahabat Berbagi, program petani kopi)</Label>
              <Input id="donation-url" type="url" inputMode="url" value={donationUrl} onChange={(e) => setDonationUrl(e.target.value)} maxLength={300} placeholder="https://sahabatberbagi.org/..." />
              <p className="text-xs text-muted-foreground">Tempel tautan halaman program petani kopi di Sahabat Berbagi. Kosongkan untuk menyembunyikan ajakan sedekah.</p>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="whatsapp">WhatsApp</Label>
              <Input id="whatsapp" type="tel" inputMode="tel" value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} maxLength={30} placeholder="0812 3456 7890" />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="instagram">Instagram</Label>
              <Input id="instagram" value={instagram} onChange={(e) => setInstagram(e.target.value)} maxLength={120} placeholder="@sudayacoffee" />
            </div>
            <div className="flex flex-col gap-1.5 sm:col-span-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={120} />
            </div>
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button type="submit" size="lg" disabled={pending}>
            {pending ? <Loader2Icon className="animate-spin" /> : <SaveIcon />} Simpan konten situs
          </Button>
        </div>
      </form>
    </AdminLayout>
  );
}
