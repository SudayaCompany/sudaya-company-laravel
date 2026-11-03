# Paritas fitur Laravel terhadap Next.js

Diperbarui 3 November 2026. Status yang dipakai: NOT STARTED, IN PROGRESS, PARTIAL, PARITY VERIFIED, BLOCKED. Status PARITY VERIFIED hanya diberikan bila test padanan lolos di Laravel, bukan karena halamannya sudah ada. PARTIAL berarti sebagian perilaku sudah setara dan sisanya tercatat.

## Ringkasan per modul

| Modul | Status | Bukti |
|---|---|---|
| M1 Auth, sesi, RBAC, user, audit | **DONE — Development Verified** (disetujui owner 3 November 2026) | Unit/feature 72/72, live 49/49, E2E 18/18, browser 75/75 |
| M2 Katalog dan menu | **DONE — Development Verified** (disetujui owner 3 November 2026); keranjang PARTIAL sampai checkout M3 | Unit katalog 30, live katalog 21, E2E katalog 4, browser 57/57. Regresi penuh: unit 102/102, live+E2E 92/92 |
| M3 Order engine | NOT STARTED | |
| M4 Pembayaran dan KDS | NOT STARTED | QRIS BLOCKED (EXT-001) |
| M5 Inventory | NOT STARTED | |
| M6 Finance | NOT STARTED | |
| M7 Karyawan dan absensi | NOT STARTED | Biometrik BLOCKED (EXT-003) |
| M8 Hardening | NOT STARTED | CSP sudah di M1 |
| M9 UAT dan cutover | NOT STARTED | |

"Development Verified" berarti terbukti di database dan Supabase development. M1 belum diuji di staging atau production.

## M1 rinci

| Perilaku | Next.js | Laravel | Test Laravel | Status |
|---|---|---|---|---|
| Katalog 41 permission, 6 role, default per role | `rbac.ts` | `Rbac.php`, dibandingkan langsung dengan `rbac.ts` | `RbacTest` (10) | PARITY VERIFIED |
| Scope outlet, company-wide, anti eskalasi, peta area, landing, `next` aman | `policy.ts` | `AccessRules`, `RouteAccess` | `AccessRulesTest` (34) | PARITY VERIFIED |
| Login, pesan error, akun bukan staf, nonaktif, tanpa role | `login/actions.ts` | `LoginController` | `LoginSecurityTest`, `RoutesTest` | PARITY VERIFIED |
| Rate limit login (email+IP, IP, email, jaringan dikenal), tanpa enumerasi akun | `login-limits.ts`, `rate-limit.ts` | `LoginLimits`, `RateLimits`, SQL sama | `LoginLimitsTest` (7), `LoginSecurityTest` (14) termasuk burst 12 proses | PARITY VERIFIED |
| IP hanya dari header proxy tepercaya | `client-ip.ts` | `ClientIp` | `LoginLimitsTest` | PARITY VERIFIED, lihat perbedaan 2 |
| Logout mencabut session Supabase, salinan cookie lama tidak berlaku | `auth/actions.ts`, `isSessionActive` | `LogoutController`, `SupabaseSession` | `RoutesTest`, `SupabaseSessionTest` | PARITY VERIFIED |
| Akun nonaktif ditolak walau sesi masih ada | `requireAuth` | `EnsureActiveStaff` | `RoutesTest` | PARITY VERIFIED |
| Undang staf, hubungkan akun lama, tolak email duplikat | `inviteStaff` | `AccessAdmin::inviteStaff` | `AccessControlTest`, `RoutesTest` (alur lengkap sampai login) | PARITY VERIFIED |
| Tetapkan dan cabut role, Super Admin terakhir, akun sendiri | `assignRole`, `revokeRole` | sama | `AccessControlTest` (19) | PARITY VERIFIED |
| Aktif/nonaktif dan ban Supabase | `setUserActive` | sama | `AccessControlTest` | PARITY VERIFIED |
| Matriks permission role, kunci Super Admin, role sendiri | `setRolePermission` | `RolePolicy` | `AccessControlTest`, `RoutesTest` | PARITY VERIFIED |
| Isolasi company (user, role, outlet, audit) | admin-ops | AccessAdmin | `AccessControlTest` | PARITY VERIFIED |
| Audit log append-only, flag maintenance | trigger DB | trigger DB yang sama | `LoginSecurityTest` | PARITY VERIFIED |
| Seeder idempotent, tidak mengembalikan permission yang dicabut | `seed.ts` | `InitialData` | `SeedTest` (4) | PARITY VERIFIED |
| Bootstrap Super Admin pertama | `admin-bootstrap.ts` | `sudaya:admin-bootstrap` | `BootstrapTest` (4) | PARITY VERIFIED |
| Set password dari link undangan | `auth/set-password` | `SetPasswordController` | `RoutesTest` | PARITY VERIFIED |
| Header keamanan, HSTS di production | `next.config.ts` | `SecurityHeaders` | `HttpHardeningTest`, `RoutesTest` | PARITY VERIFIED, ditambah CSP |
| Log error tanpa data pribadi | `log.ts` | handler `QueryException` | `HttpHardeningTest` | PARITY VERIFIED |
| Halaman dashboard, pengguna, role, audit, staff | `admin/*`, `staff` | Inertia, komponen yang sama | browser 75/75 | PARITY VERIFIED untuk M1. Kartu staf menampilkan "Segera hadir" sampai modulnya pindah |

## M2 rinci

| Perilaku | Next.js | Laravel | Test Laravel | Status |
|---|---|---|---|---|
| CRUD kategori, slug otomatis, urutan, aktif, soft delete; kategori berisi produk tidak bisa dihapus | `admin-ops.ts` | `CatalogAdmin` | Live `CatalogTest`, browser | PARITY VERIFIED |
| CRUD produk: SKU dinormalisasi, slug unik per brand (hanya baris aktif), harga 0–10.000.000 rupiah bulat, deskripsi, status aktif | `admin-ops.ts`, `schemas.ts` | `CatalogAdmin`, `CatalogInput` | Unit `CatalogTest`, live, E2E | PARITY VERIFIED |
| Pesan error validasi | Zod | `CatalogInput`, pesan sama | Unit | PARITY VERIFIED, lihat perbedaan 10 |
| Grup modifier: single/multiple, wajib, min/max, default tunggal, template PRD | `modifierGroupInput`, `presets.ts` | `CatalogInput::modifierGroup`, halaman modifier | Unit, live, browser | PARITY VERIFIED |
| Opsi modifier grup lain ditolak, id opsi lama dipertahankan, harga opsi tercatat di audit | `updateModifierGroup` | sama | Live | PARITY VERIFIED |
| Foto menu: tipe dari isi file, maks 2 MB, nama acak di `company/brand/`, foto lama dihapus setelah DB tersimpan, foto gagal dihapus lagi | `images.ts`, `storage.ts` | `MenuImages`, `SupabaseMenuImageStorage` | Unit, live (Storage sungguhan), E2E (SVG/PHP/GIF ditolak) | PARITY VERIFIED |
| Sold out per outlet cukup dengan izin outlet; data master butuh izin company-wide | `setProductAvailability` | sama | Live, E2E | PARITY VERIFIED |
| Audit: `product.created`, `product.updated`, `product.price_changed` terpisah, `product.image_changed`, `product.status_changed`, `product.availability_changed`, `product.deleted`, `category.*`, `modifier_group.*` | admin-ops | sama | Live, E2E | PARITY VERIFIED |
| Pencarian (nama/SKU, karakter LIKE di-escape), filter kategori/status/ketersediaan, sorting, pagination 20 | `listProducts` | sama | Live | PARITY VERIFIED |
| Isolasi company dan brand, anti mass assignment | admin-ops | sama | Live | PARITY VERIFIED |
| Menu customer `/order`: hanya produk dan kategori aktif, sold out ditandai, harga outlet, modifier aktif, tanpa kolom internal, nomor meja, outlet tak dikenal | `storefront.ts`, `order/page.tsx` | `Storefront`, `OrderController` | Live, E2E, browser | PARITY VERIFIED |
| Cache menu dibuang saat admin mengubah menu | `updateTag` | versi cache per brand | Live, E2E | PARITY VERIFIED |
| Quote keranjang di server, harga dari browser diabaikan, batas jumlah 1–50, stok produk jadi, rate limit 300/menit per IP | `quote.ts`, `order/actions.ts` | `Storefront::quote`, `POST /order/quote` | Unit, live, E2E | PARITY VERIFIED |
| Keranjang di browser | `cart.tsx` | `order/index.tsx` | Browser | PARTIAL: tampil, quote, ubah jumlah, hapus. Checkout sengaja belum aktif sampai M3 |
| Halaman admin produk, kategori, modifier | `admin/*` | Inertia, komponen sama | Browser | PARITY VERIFIED |
| Ketersediaan dari stok produk jadi (`inventory_item_id`) | storefront | sama, dibaca dari DB | Belum ada data inventory di Laravel | PARTIAL: logika dan query sudah ada, diuji penuh di M5 |
| Kiosk `/kiosk` | `StorefrontView mode="kiosk"` | Belum | - | NOT STARTED, menunggu M3 |

## Perbedaan yang disengaja

1. **403 sungguhan.** Next.js menjawab halaman terlarang dengan status 200 lalu menampilkan 403 (batasan PPR). Laravel menjawab HTTP 403.
2. **IP tepercaya.** Cabang khusus Vercel (`x-vercel-forwarded-for`) dihapus. IP hanya dibaca dari `TRUSTED_IP_HEADER` yang diisi sesuai reverse proxy hosting.
3. **CSP penuh.** `script-src` kini berbasis nonce. Next.js hanya punya `frame-ancestors`.
4. **Token tidak di browser.** Next.js menyimpan token Supabase di cookie HttpOnly. Laravel menyimpannya di server, dan browser hanya memegang cookie sesi.
5. **Akun di-ban dianggap nonaktif segera.** Next.js baru menolak setelah JWT kedaluwarsa. Laravel memeriksa `banned_until` di setiap request.
6. **Konfirmasi link untuk akun yang bukan staf** langsung menutup session Supabase dan kembali ke `/login?error=link`. Next.js membiarkan session itu lalu menolak di halaman berikutnya.
7. **Riwayat browser dienkripsi** dan dibersihkan saat logout.
8. **Reset password staf oleh admin (GL-5)** ditambahkan dan belum ada di Next.js. Aturannya sama dengan aksi user lain: perlu `user.manage` company-wide, tidak untuk akun sendiri, akun target harus didominasi, link hanya tampil sekali, dan token tidak masuk audit.
9. **Idle timeout sesi** 7 hari (`SESSION_LIFETIME=10080`). Next.js praktis tidak punya idle timeout.
10. **Harga kosong ditolak.** Zod mengubah teks kosong menjadi 0, jadi Next.js menerima harga kosong sebagai Rp 0. Laravel menolaknya dengan pesan "Harga harus berupa angka." Harga Rp 0 tetap bisa diisi secara eksplisit.
11. **Checkout di menu customer belum aktif.** Next.js sudah punya checkout dan pembayaran. Di Laravel, checkout dibangun di M3 bersama order engine. Keranjang menyatakannya terus terang, dan tidak ada order atau pembayaran yang dibuat.
12. **Error tak terduga pada aksi admin** tampil sebagai pesan "Terjadi kesalahan. Coba lagi.", sama dengan `runAction` Next.js, bukan halaman 500.

## Test Next.js yang tidak dipindahkan

| Test Next.js | Alasan |
|---|---|
| `batch1-security` "cookie session ditulis httpOnly oleh client server" | Diganti: Laravel tidak menulis cookie Supabase. Pemeriksaan cookie sesi Laravel ada di `RoutesTest` |
| `routes.test.ts` 3b dan 4b (memanggil Supabase langsung) | Menguji Supabase, bukan aplikasi. Perilaku yang sama tercakup di test login aplikasi |
| `routes.test.ts` /order "Menu belum tersedia", /kiosk | Modul M2 dan M3. Untuk sekarang hanya halaman "belum tersedia" yang diuji |

Jumlah test Next.js terkait M1: 87 (unit 34, integrasi 36, E2E 17). Semua perilakunya tercakup kecuali yang tercantum di atas.

## Keterbatasan M1 yang tercatat

- Di HP, tombol Keluar admin hanya ada di dashboard. Perilaku ini sama dengan Next.js.
- Rate limit Supabase Auth tetap per IP server karena login dipanggil dari server (SEC-016, EXT-027).
- Batas tunggu link undangan mengikuti pengaturan OTP Supabase.
- Kecepatan halaman di laptop ini tidak mewakili production: database ada di eu-west-1.
