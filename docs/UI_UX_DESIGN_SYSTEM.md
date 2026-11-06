# Sudaya Design System (usulan UX-0)

Status: **usulan, belum diimplementasikan.** Disusun 6 November 2026 dari kode aktual (`resources/css/app.css`, `resources/js/components/ui/*`) dan audit UX ([UI_UX_AUDIT.md](UI_UX_AUDIT.md)). Implementasi dilakukan di milestone UX-1 setelah disetujui owner.

Arah visual: **Sudaya Coffee — Modern Specialty Coffee & Lifestyle Brand.** Hangat, premium, minimal, mudah dibaca. Identitas PRD §86 dipertahankan: hitam (espresso), putih, aksen oranye, pendukung hijau, ditambah krem sebagai permukaan.

## 1. Prinsip

1. **Satu pola untuk satu tugas.** Daftar, form, konfirmasi, dan status memakai komponen yang sama di semua halaman.
2. **Kecepatan operasional lebih penting dari dekorasi** di POS, KDS, dan kiosk. Hiasan hanya di halaman customer.
3. **Data asli saja.** Tidak ada angka, testimoni, foto, atau harga contoh di tampilan production. Bila data belum ada, tampilkan empty state.
4. **Bisa dipakai dengan jari.** Target sentuh minimal 44 px di semua layar; 48–56 px di POS dan KDS; 64 px di kiosk.
5. **Terbaca dari jauh** di KDS dan layar pelanggan: nomor antrean dan status adalah elemen terbesar.
6. **Aksesibel:** kontras WCAG AA, fokus terlihat, status tidak hanya lewat warna, mendukung reduced motion.

## 2. Token

Semua token di `resources/css/app.css` (`:root` dan `.dark`), dipakai lewat kelas Tailwind (`bg-brand`, `text-espresso`, dst.). Komponen tidak boleh memakai warna mentah seperti `bg-neutral-950`; itu diganti token.

### 2.1 Warna

| Token | Kondisi sekarang | Usulan | Pemakaian |
|---|---|---|---|
| `--background` | putih | tetap putih | Latar utama (PRD §86) |
| `--foreground` | `oklch(0.145 0 0)` abu dingin | `oklch(0.17 0.01 55)` hitam hangat | Teks utama |
| `--espresso` | **belum ada**; halaman memakai `neutral-950` mentah | `oklch(0.18 0.015 50)` | Hero, header POS, panel gelap, tombol primer customer |
| `--espresso-foreground` | — | `oklch(0.97 0.01 75)` | Teks di atas espresso |
| `--cream` | `oklch(0.975 0.012 75)` | tetap | Selang-seling seksi, placeholder foto |
| `--cream-strong` | — | `oklch(0.94 0.02 75)` | Kartu di atas cream, chip terpilih customer |
| `--brand` (oranye) | `oklch(0.7 0.18 50)` | tetap | Isi tombol CTA, aksen garis, badge, ikon. **Bukan untuk teks kecil di latar terang** (kontras ±2,6:1) |
| `--brand-strong` | — | `oklch(0.55 0.16 45)` | Teks/tautan oranye di latar terang (target ≥ 4,5:1) |
| `--brand-soft` | — | `oklch(0.95 0.04 60)` | Latar notifikasi oranye, highlight |
| `--success` (hijau) | ada | tetap | Lunas, siap, realtime aktif |
| `--warning` | ada | tetap | Hampir terlambat, perhatian |
| `--destructive` | ada | tetap | Hapus, dibatalkan, gagal |
| `--info` | — | `oklch(0.55 0.12 240)` | Sedang dibuat (KDS), informasi netral |
| Netral (`--muted`, `--border`, `--input`, `--secondary`) | chroma 0 (abu dingin) | chroma 0,005–0,01, hue 60–75 (abu hangat) | Konsisten dengan nuansa kopi |
| `--chart-1..5` | abu | turunan brand/success/info | Hanya bila grafik memakai data asli |

Aturan kontras (diuji saat UX-1, angka di atas adalah titik awal):
- Teks normal ≥ 4,5:1; teks besar (≥ 24 px, atau ≥ 19 px tebal) ≥ 3:1.
- `text-muted-foreground` tidak dipakai di atas `bg-muted` untuk teks kecil.
- Status selalu punya label teks dan/atau ikon, bukan warna saja.

### 2.2 Peta warna status (satu sumber untuk POS, KDS, customer, admin)

| Status order (`lib/orders.ts`) | Label staf sekarang | Label customer | Warna | Ikon |
|---|---|---|---|---|
| `pending_payment` | Menunggu bayar | Menunggu pembayaran | brand-soft / brand-strong | Clock |
| `queued` / `paid` | Antre | Pesanan diterima | secondary | Inbox |
| `preparing` | Dibuat | Sedang dibuat | info | Coffee |
| `ready` | Siap | Siap diambil | success | BellRing |
| `completed` | Selesai | Selesai | muted | CheckCheck |
| `cancelled` | Dibatalkan | Dibatalkan | destructive | XCircle |
| `refunded` | Refund | Dikembalikan | destructive (outline) | Undo |

Status pembayaran (`Belum dibayar`, `Lunas`, `Tidak dibayar`, `Dikembalikan`) hanya ditampilkan bila **berbeda maknanya** dari status order. Contoh yang sekarang dobel di daftar pesanan: "Belum dibayar" + "Menunggu bayar" cukup satu badge.

### 2.3 Tipografi

| Peran | Font | Ukuran (mobile → desktop) | Pemakaian |
|---|---|---|---|
| Display XL | Fraunces | 48 → 72 px, `leading-[1.05]` | Hero landing |
| Display L | Fraunces | 36 → 48 px | Judul seksi landing, judul menu |
| Display M | Fraunces | 28 → 36 px | Judul kategori menu customer, nama produk di sheet |
| Heading | Geist 600 | 20 → 24 px | Judul halaman staf/admin |
| Title | Geist 600 | 16 → 18 px | Judul kartu, nama produk di kartu |
| Body | Geist 400 | 16 px (customer), 14 px (admin padat) | Teks |
| Caption | Geist 400 | 12–13 px | Metadata. Minimum 12 px |
| Angka operasional | Geist Mono 700, `tabular-nums` | Nomor antrean KDS 40–48 px; total/kembalian POS 28–36 px; nomor antrean pelanggan 48 px | POS, KDS, struk, status |

Fraunces hanya untuk wordmark dan judul customer-facing; admin, POS, dan KDS memakai Geist agar padat dan netral.

### 2.4 Spasi, radius, elevasi

- **Grid 4 px.** Jarak antar-elemen form 16 px; antar-seksi halaman 24–32 px (admin) dan 64–96 px (landing).
- **Lebar konten:** landing `max-w-6xl`; menu customer `max-w-5xl`; admin penuh dengan isi `max-w-7xl`; form `max-w-3xl`.
- **Radius** (skala sudah ada, `--radius` 10 px): kontrol `rounded-lg` (10 px); kartu admin `rounded-xl`; kartu customer `rounded-3xl`; sheet dan dialog `rounded-3xl` di atas, `rounded-2xl` dialog tengah; chip dan CTA customer `rounded-full`.
- **Elevasi:** `shadow-sm` kartu interaktif saat hover; `shadow-lg` sheet/dialog; `shadow-xl` bar keranjang mengambang. Tidak ada bayangan di tabel.

### 2.5 Ukuran kontrol (perubahan terpenting)

Ukuran tombol bawaan sekarang di bawah standar sentuh: `default` `h-8` (32 px), `sm` `h-7`, `lg` `h-9`, `icon` `size-8`. Halaman menimpanya satu per satu (`h-11`, `h-12`, `h-14`), sehingga tinggi tombol tidak konsisten.

| Ukuran usulan | Tinggi | Dipakai |
|---|---|---|
| `sm` | 36 px | Aksi sekunder di tabel admin (desktop) |
| `default` | 40 px | Admin |
| `lg` | 44 px | Customer, form staf, dialog |
| `xl` | 52 px | POS (bayar, tambah), KDS (aksi status) |
| `kiosk` | 64 px | Kiosk |
| `icon` / `icon-lg` | 40 / 44 px | Tombol ikon; ikon tanpa teks wajib `aria-label` |

Input dan select mengikuti tinggi yang sama (`default` 40, `lg` 44, `kiosk` 56–64).

### 2.6 Gerak

- Durasi 150 ms (hover, tekan), 200 ms (chip, toggle), 300 ms (sheet, dialog). Easing `ease-out` masuk, `ease-in` keluar.
- Hanya `transform` dan `opacity`. Tidak ada animasi berulang dekoratif.
- `prefers-reduced-motion` sudah dihormati global di `app.css`; dipertahankan.
- Umpan balik tekan: `active:scale-[0.98]` di kartu sentuh POS, kiosk, dan menu.

### 2.7 Breakpoint dan perangkat sasaran

| Perangkat | Lebar uji | Layar utama |
|---|---|---|
| HP kecil | 360 px | Menu customer, status, admin darurat |
| HP besar | 430 px | Menu customer, status |
| Tablet portrait | 768 px | POS cadangan, admin |
| Tablet landscape | 1024 × 768 | **KDS**, **kiosk**, POS |
| Laptop | 1280 px | POS kasir, admin |
| Desktop | 1440 px+ | Admin, landing |

## 3. Komponen

Status: **Ada** (di `components/ui` atau `components`), **Perlu dirapikan**, **Baru**.

| Komponen | Status | Catatan/usulan |
|---|---|---|
| Button | Perlu dirapikan | Skala ukuran §2.5; varian `brand` (CTA oranye) dan `espresso` agar halaman tidak menulis kelas warna sendiri |
| Input, Textarea | Ada | Tinggi mengikuti §2.5; pesan error di bawah field (`aria-describedby`) |
| Select | Ada (`native-select`) | Tetap native (paling ramah HP); gaya disamakan |
| Search input | **Baru** | Ikon, tombol hapus, debounce; dipakai menu customer, POS, daftar admin. Sekarang ada 3 implementasi berbeda |
| Tabs | **Baru** | Filter status pesanan, seksi CMS. Sekarang chip buatan per halaman |
| Segmented control | **Baru** | Tipe pesanan (Takeaway/Dine in/Delivery), uang pas/50rb/100rb |
| Dialog, Alert dialog | Ada | Satu `ConfirmDialog` untuk semua aksi destruktif (sudah ada `confirm-action.tsx`, dipakai konsisten) |
| Sheet / Drawer | Ada (`sheet`) | Bottom sheet di HP, side sheet di desktop untuk form singkat admin |
| Toast | Ada (`sonner`) | Sukses singkat. Error validasi tetap di dekat field |
| Dropdown menu | **Baru** | Aksi baris tabel (Edit, Nonaktifkan, Hapus) dengan label teks, bukan ikon saja |
| Data table | **Baru** (dari `ui/table`) | Header sticky, sort, kolom prioritas, berubah jadi kartu di HP |
| Pagination | **Baru** | Daftar admin dan audit; server-side bila data banyak |
| Toolbar list | **Baru** | Search + filter + tombol utama dalam satu baris; filter berlaku langsung, tanpa tombol "Terapkan" |
| Badge | Ada | Dipakai untuk label netral |
| Status badge | **Baru** | Satu komponen dari peta §2.2 |
| Stat card | **Baru** | Dashboard: angka besar, label, perbandingan opsional, empty state bila data tidak ada |
| Money / Amount display | **Baru** | `formatRupiah` + ukuran tetap untuk total, kembalian, saldo kas |
| Card | Ada | Varian `interactive`, `muted` |
| Sidebar admin | Perlu dirapikan | Grup menu, ikon, mode drawer di HP, profil + keluar selalu tersedia |
| Top bar staf (POS/KDS) | Perlu dirapikan | Status kas, outlet, koneksi realtime, pindah ke admin bila punya izin |
| Breadcrumb | **Baru** | Halaman bertingkat admin (Produk › Edit, Outlet › Meja) |
| Page header | Ada | Judul, deskripsi, aksi utama di kanan; di HP aksi turun ke bawah |
| Empty state | Ada | Ikon, judul, penjelasan, satu aksi |
| Skeleton | Ada | Untuk deferred props dan daftar |
| Upload image | **Baru** (pola sudah ada di form produk) | Pratinjau, drag/drop, validasi tipe/ukuran di browser, kompres di browser, alt text wajib, ganti/hapus dengan konfirmasi |
| Image gallery (admin) | **Baru** | Grid dengan urutan drag atau tombol naik/turun, kategori, aktif/nonaktif |
| Connection indicator | Ada sebagian (`live-refresh`) | Seragam: "Realtime" hijau, "Polling" kuning, "Terputus" merah + waktu update terakhir |
| Numeric keypad | **Baru** (opsional) | Input uang tunai POS di tablet |
| Product card | Ada (customer, POS terpisah) | Satu dasar, dua ukuran (customer dan POS) |
| Quantity stepper | Ada | Target sentuh 44 px |

Aturan pemakaian ulang: komponen baru masuk ke `resources/js/components/ui` (primitif) atau `resources/js/components/<domain>` (pola bisnis seperti `status-badge`, `money`). Halaman tidak membuat versi sendiri untuk fungsi yang sama.

## 4. Pola halaman

| Pola | Struktur | Dipakai |
|---|---|---|
| **Daftar** | Page header (judul + tombol utama) → toolbar (search, filter langsung) → data table / kartu di HP → pagination → empty state | Produk, kategori, modifier, pengguna, audit, pesanan, galeri |
| **Form** | Header + breadcrumb → field berkelompok dalam kartu → panel samping (foto, status) di desktop → aksi Simpan/Batal tetap terlihat (sticky di HP) | Produk, outlet, CMS |
| **Detail** | Ringkasan atas (status, total, waktu) → isi → riwayat → aksi kontekstual | Detail pesanan, sesi kas |
| **Operasional layar penuh** | Top bar ringkas → area kerja tanpa scroll halaman (scroll di dalam panel) | POS, KDS, kiosk |
| **Konfirmasi destruktif** | Alert dialog: apa yang terjadi, dampaknya, tombol aksi berlabel jelas ("Hapus foto", bukan "OK") | Semua hapus/nonaktif/batal |
| **Umpan balik** | Toast untuk sukses; inline untuk error validasi; banner untuk kondisi halaman (kas belum dibuka, koneksi putus) | Semua |

## 5. Bahasa dan istilah

Bahasa Indonesia yang singkat dan konsisten. Istilah teknis (slug, UUID, kode role, kode permission) tidak ditampilkan ke staf kecuali di halaman teknis Super Admin.

| Pakai | Hindari |
|---|---|
| Pesanan | Order (di teks staf) |
| Mulai buat / Siap / Selesai | Start Preparing / Mark Ready / Complete |
| Bayar tunai | Cash |
| Makan di tempat / Bawa pulang / Antar | Dine In / Takeaway / Delivery (nama tampilan; kode internal tetap) |
| Habis | Sold out (customer boleh tetap "Habis") |
| Kas masuk / Kas keluar | Cash in / Cash out |

Perubahan label yang dipakai test E2E dan probe browser dilakukan bersama pembaruan test di milestone yang sama, bukan dengan melemahkan assertion.

## 6. Checklist sebelum komponen dianggap selesai

- [ ] Tidak memakai warna mentah; semua lewat token.
- [ ] Target sentuh sesuai §2.5.
- [ ] Fokus terlihat dan urutan Tab benar; dialog menahan fokus.
- [ ] Label/`aria-label` lengkap; error terhubung ke field.
- [ ] Kontras AA diukur.
- [ ] State: default, hover, fokus, tekan, disabled, loading, error, kosong.
- [ ] Diuji di 360 px, 768 px, 1024 px, 1280 px.
- [ ] Reduced motion tidak memutus fungsi.
