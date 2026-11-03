# Paritas fitur Laravel terhadap Next.js

Diperbarui 3 November 2026. Status yang dipakai: NOT STARTED, IN PROGRESS, PARTIAL, PARITY VERIFIED, BLOCKED. Status PARITY VERIFIED hanya diberikan bila test padanan lolos di Laravel, bukan karena halamannya sudah ada. PARTIAL berarti sebagian perilaku sudah setara dan sisanya tercatat.

## Ringkasan per modul

| Modul | Status | Bukti |
|---|---|---|
| M1 Auth, sesi, RBAC, user, audit | **DONE — Development Verified** (disetujui owner 3 November 2026) | Unit/feature 72/72, live 49/49, E2E 18/18, browser 75/75 |
| M2 Katalog | NOT STARTED | |
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
