# Sitemap & wireframe Sudaya (usulan UX-0)

Status: **usulan.** Menu hanya menaut ke halaman yang ada atau yang dibangun di program UX ini. Halaman yang dipetakan di `RouteAccess` tetapi belum dibuat (laporan, stok, karyawan, absensi) **tidak** ditampilkan.

Tanda: **(baru)** = halaman baru di program UX, **(ubah)** = halaman yang ada didesain ulang.

## 1. Peta area

```text
Publik (tanpa login)
├─ /                         Halaman depan (ubah; konten dari CMS)
├─ /order?outlet=&table=     Menu & checkout (ubah)
├─ /order/status/{id}        Status pesanan (ubah)
└─ /kiosk?outlet=            Kiosk (ubah; tanpa tautan ke area lain)

Akun
├─ /login                    Masuk staf (ubah copy)
└─ /auth/set-password        Buat password

Operasional (top bar ringkas)
├─ /staff                    Beranda staf (ubah: hanya fitur yang ada)
├─ /pos                      Kasir (ubah)
├─ /pos/orders[/{id}]        Pesanan hari ini & detail (ubah)
├─ /pos/cash                 Sesi kas (ubah)
└─ /kds                      Barista display (ubah; mode layar penuh)

Admin (sidebar bergrup)
├─ Ringkasan
│  └─ /admin                         Dashboard (ubah: data operasional asli)
├─ Operasional
│  ├─ /admin/orders                  Pesanan, hanya-baca + approval (baru; izin order.view yang sudah ada)
│  ├─ /pos  →  Kasir                 tautan ke area operasional (bila order.create)
│  ├─ /kds  →  Barista display       tautan (bila kds.view)
│  └─ /pos/cash  →  Kas              tautan (bila cash.view + order.create); ringkasan kas juga di dashboard
├─ Produk
│  ├─ /admin/products                Menu (ubah)
│  ├─ /admin/categories              Kategori (ubah)
│  └─ /admin/modifiers               Modifier (ubah)
├─ Website                           (baru; izin settings.manage, lihat ADMIN_CMS_SPEC)
│  ├─ /admin/website                 Ringkasan & terbitkan
│  ├─ /admin/website/hero            Hero
│  ├─ /admin/website/about           Tentang Sudaya
│  ├─ /admin/website/featured        Menu unggulan
│  ├─ /admin/website/gallery         Galeri
│  └─ /admin/website/settings        Pengaturan situs (logo, meta, sosial, kontak)
├─ Organisasi
│  ├─ /admin/outlets[/{id}]          Outlet, meja & QR, jam buka & kontak (ubah)
│  ├─ /admin/users                   Staf (ubah)
│  └─ /admin/roles                   Role & izin (ubah)
└─ Administrasi
   └─ /admin/audit                   Log aktivitas (ubah)
```

Tautan silang:
- Admin → Operasional: tombol "Buka Kasir/KDS" di sidebar bila punya izin.
- Operasional → Admin: tombol "Admin" di top bar bila `auth.navigation` memuat `/admin`.
- Keluar selalu ada di menu profil, di semua ukuran layar.

### Menu per role (default `Rbac::DEFAULT_ROLE_PERMISSIONS`)

| Menu | Super Admin | Owner | Manager | Admin | Kasir | Barista |
|---|---|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | ✓ | — | — |
| Pesanan (admin, baca + approval) | ✓ | ✓ | ✓ | — | — | — |
| Kasir / Pesanan hari ini (`/pos`) | ✓ | — | ✓ | — | ✓ | ✓ |
| Kas | ✓ | ringkasan di dashboard | ✓ | — | ✓ | — |
| KDS | ✓ | ✓ | ✓ | — | — | ✓ |
| Menu, Kategori, Modifier | ✓ | ✓ | ✓ (edit) | ✓ | — | — |
| Website | ✓ | — | — | — | — | — |
| Outlet & Meja | ✓ | — | — | — | — | — |
| Staf, Role | ✓ | — | — | — | — | — |
| Log aktivitas | ✓ | ✓ | — | — | — | — |

Kolom ini mengikuti izin yang sudah ada. Memberi akses Website ke Manager atau Owner butuh permission baru (`content.manage`) dan persetujuan owner (perubahan RBAC).

## 2. Wireframe

Kasar, untuk menyepakati struktur. Detail visual mengikuti [UI_UX_DESIGN_SYSTEM.md](UI_UX_DESIGN_SYSTEM.md).

### 2.1 Halaman depan (HP 375 px)

```text
┌───────────────────────────┐
│ [logo]            [Pesan] │  header transparan di atas foto
│                           │
│   FOTO HERO (CMS)         │  overlay gelap 40–60% agar teks terbaca
│   Kopi enak,              │
│   tanpa antre lama.       │  headline CMS (Fraunces)
│   subheadline CMS         │
│ [Lihat menu & pesan  →]   │  CTA utama oranye 48 px
│ [Tentang Sudaya]          │  CTA sekunder outline
├───────────────────────────┤
│ Tentang Sudaya            │  teks CMS + 1 foto
├───────────────────────────┤
│ Menu unggulan             │  2 kolom kartu, harga dari DB,
│ [kartu][kartu]            │  produk habis tidak ditampilkan
│ [Lihat semua menu]        │
├───────────────────────────┤
│ Galeri                    │  grid 2 kolom, ketuk = lightbox
├───────────────────────────┤
│ Cara pesan 1-2-3-4        │  copy sesuai metode bayar aktif
├───────────────────────────┤
│ Kunjungi kami             │  nama, alamat, jam buka, telepon,
│ [Buka di Google Maps]     │  tautan peta dari pengaturan situs
├───────────────────────────┤
│ footer: logo · menu ·     │
│ sosial · Masuk staf       │
└───────────────────────────┘
```

Desktop: hero 2 kolom (teks kiri, foto kanan atau foto penuh dengan teks kiri), menu unggulan 3 kolom, galeri masonry 3–4 kolom.

### 2.2 Menu customer (HP)

```text
┌───────────────────────────┐
│ Sudaya   [Meja T03]       │
│ Mau ngopi apa hari ini?   │
├───────────────────────────┤ sticky
│ [cari] Cari menu…             │
│ (Kopi)(Non kopi)(Camilan) │ chip aktif mengikuti scroll
├───────────────────────────┤
│ Kopi                      │
│ [foto][foto]              │ kartu: nama, deskripsi 2 baris,
│ nama   nama               │ harga, tombol + 44 px
│ Rp…  (+) Rp…  (+)             │ produk tanpa modifier: + langsung
├───────────────────────────┤
│ ███ 2 item · Rp 48.000 ▸  │ bar keranjang mengambang
└───────────────────────────┘
```

### 2.3 POS (laptop 1280 px)

```text
┌────────────────────────────────────────────────────────────┐
│ Sudaya POS  Kasir Pesanan Kas KDS │ ● Kas terbuka ● Online │ Admin  Profil │
├──────────────────────────────────────┬─────────────────────┤
│ [cari] Cari produk            [Semua][Kopi]… │ Pesanan baru  Kosongkan │
│ ┌────┐┌────┐┌────┐┌────┐             │ 2× Es Kopi Susu  44.000 │
│ │foto││    ││    ││    │ kartu ringkas│   Large, Less sugar     │
│ │nama││    ││    ││    │ (foto kecil) │   [−] 2 [+]      [hapus]  │  48 px
│ └────┘└────┘└────┘└────┘             │ ...                     │
│                                      ├─────────────────────────┤
│                                      │ Bawa pulang│Makan│Antar │
│                                      │ Nama (ops.)  Catatan    │
│                                      │ TOTAL        Rp 44.000  │  32 px
│                                      │ Uang [ 50.000 ] Pas 50rb 100rb │
│                                      │ KEMBALIAN    Rp 6.000   │  32 px
│                                      │ [ Bayar tunai & proses ] │  52 px
│                                      │                          │
│                                      │  Simpan, bayar nanti     │  sekunder
└──────────────────────────────────────┴─────────────────────────┘
```

### 2.4 KDS (tablet landscape 1024 × 768)

```text
┌──────────────────────────────────────────────────────────────┐
│ Outlet 01 · 4 antre · 2 dibuat     ● Terhubung · 14.02   [layar penuh] │  bar tipis
├───────────────────┬───────────────────┬──────────────────────┤
│ ANTRE (4)         │ DIBUAT (2)        │ SIAP (1)             │
│ ┌───────────────┐ │ ┌───────────────┐ │ ┌──────────────────┐ │
│ │ SDY-014 06:12 │ │ │ SDY-012 08:40 │ │ │ SDY-011  11:05   │ │  nomor 40 px
│ │ QR Meja T03   │ │ │ ! Terlambat   │ │ │ Kiosk · Andi     │ │
│ │ 2× Es Kopi    │ │ │ 1× Latte      │ │ │ 1× Matcha        │ │  item 18–20 px
│ │   Large, Less │ │ │   Oat milk    │ │ │                  │ │  modifier 16 px
│ │ "tanpa es"    │ │ │               │ │ │                  │ │
│ │ [ Mulai buat ]│ │ │ [   Siap    ] │ │ │ [  Selesai     ] │ │  56 px
│ └───────────────┘ │ └───────────────┘ │ └──────────────────┘ │
├───────────────────┴───────────────────┴──────────────────────┤
│ Selesai terakhir: SDY-010 · SDY-009 · SDY-008    [lihat]     │  strip ringkas
└──────────────────────────────────────────────────────────────┘
```

### 2.5 Shell admin + dashboard (laptop)

```text
┌──────────────┬─────────────────────────────────────────────┐
│ Sudaya Admin │ Dashboard › Hari ini        [Outlet 01 ▾]   │
│              ├─────────────────────────────────────────────┤
│ RINGKASAN    │ ┌────────┐┌────────┐┌────────┐┌────────┐    │
│  Dashboard   │ │Penjualan││Transaksi││Rata-rata││Menunggu│   │  data asli;
│ OPERASIONAL  │ │Rp …     ││ …       ││ Rp …    ││ bayar … │   │  empty state
│  Pesanan     │ └────────┘└────────┘└────────┘└────────┘    │  bila 0
│  Buka Kasir ↗│ ┌──────────────────────┐┌──────────────────┐ │
│  Buka KDS ↗  │ │ Sesi kas: terbuka    ││ Perlu tindakan   │ │
│ PRODUK       │ │ dibuka 08.00 · Rina  ││ 1 pembatalan     │ │
│  Menu        │ │ saldo seharusnya …   ││ 2 produk habis   │ │
│  Kategori    │ └──────────────────────┘└──────────────────┘ │
│  Modifier    │ ┌───────────────────────────────────────────┐│
│ WEBSITE      │ │ Terlaris hari ini (bila ada transaksi)    ││
│  …           │ └───────────────────────────────────────────┘│
│ ORGANISASI   │                                             │
│  …           │                                             │
│ [Profil ▾]   │                                             │
└──────────────┴─────────────────────────────────────────────┘
HP: sidebar → drawer dari tombol ☰; profil & Keluar di dalam drawer.
```

### 2.6 CMS: editor hero

```text
┌──────────────┬─────────────────────────────────────────────┐
│ sidebar      │ Website › Hero         Draft (belum terbit) │
│              ├──────────────────────┬──────────────────────┤
│              │ Foto hero            │ Pratinjau [HP][Desktop]│
│              │ [ seret foto / pilih]│ ┌──────────────────┐  │
│              │ Alt text *           │ │ render React     │  │
│              │ Headline *           │ │ seksi versi draft│  │
│              │ Subheadline          │ └──────────────────┘  │
│              │ CTA utama (teks)     │                      │
│              │ [x] Tampilkan foto   │                      │
│              ├──────────────────────┴──────────────────────┤
│              │ [Simpan draft]  [Batalkan perubahan]  [Terbitkan] │
└──────────────┴─────────────────────────────────────────────┘
```
