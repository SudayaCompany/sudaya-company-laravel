# Paritas fitur Laravel terhadap Next.js

Diperbarui 4 November 2026. Status yang dipakai: NOT STARTED, IN PROGRESS, PARTIAL, PARITY VERIFIED, BLOCKED. Status PARITY VERIFIED hanya diberikan bila test padanan lolos di Laravel, bukan karena halamannya sudah ada. PARTIAL berarti sebagian perilaku sudah setara dan sisanya tercatat.

## Ringkasan per modul

| Modul | Status | Bukti |
|---|---|---|
| M1 Auth, sesi, RBAC, user, audit | **DONE — Development Verified** (disetujui owner 3 November 2026) | Unit/feature 72/72, live 49/49, E2E 18/18, browser 75/75 |
| M2 Katalog dan menu | **DONE — Development Verified** (disetujui owner 3 November 2026). Keranjang kini lengkap dengan checkout M3 | Unit katalog 30, live katalog 21, E2E katalog 4, browser 57/57. Regresi penuh: unit 102/102, live+E2E 92/92 |
| M3 Order engine, POS, QR, kiosk | **DONE — Development Verified** (disetujui owner 3 November 2026, `32ab6d2`) | Unit order 27, live order 19, E2E order 6, browser 137/137. Regresi penuh 3 November: unit/feature 129/129, live 89/89 (exit 0), E2E 28/28 (exit 0), database dev bersih |
| M4 Pembayaran tunai, kas, antrean, KDS, pembatalan, refund | **DONE — Development Verified** (disetujui owner 4 November 2026; residual QA wajib ditutup sebelum staging/UAT dinyatakan lulus, lihat `GO_LIVE_READINESS.md`) | Live `PaymentFlowTest` 18, E2E `PaymentFlowTest` 5, browser M4. Regresi penuh 4 November: unit 129/129, live 107/107, E2E 33/33 (setelah 1 test M3 diperbarui). QRIS BLOCKED (EXT-001) |
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
| Keranjang di browser | `cart.tsx` | `components/storefront/storefront.tsx` | Browser | PARITY VERIFIED (checkout aktif di M3) |
| Halaman admin produk, kategori, modifier | `admin/*` | Inertia, komponen sama | Browser | PARITY VERIFIED |
| Ketersediaan dari stok produk jadi (`inventory_item_id`) | storefront | sama, dibaca dari DB | Belum ada data inventory di Laravel | PARTIAL: logika dan query sudah ada, diuji penuh di M5 |
| Kiosk `/kiosk` | `StorefrontView mode="kiosk"` | `kiosk/index.tsx` | Lihat M3 | PARITY VERIFIED di M3 |

## M3 rinci

Lingkup M3: pembuatan order semua channel, validasi harga di server, idempotensi, POS, QR/web, kiosk, order menunggu pembayaran, dan akses guest. Konfirmasi tunai, ledger pembayaran, nomor antrean, KDS, dan realtime staf ada di M4. Di M3 tidak ada jalur yang menandai order lunas.

| Perilaku | Next.js | Laravel | Test Laravel | Status |
|---|---|---|---|---|
| Satu order engine untuk POS, QR meja, QR kasir, web, kiosk | `engine.ts` `createOrder` | `OrderEngine::create` | Live `OrderEngineTest` | PARITY VERIFIED |
| Validasi input checkout dan pesan error (tipe, HP, meja, catatan, 1–50 baris, jumlah bulat 1–50, delivery, nama untuk channel publik) | `schemas.ts` (Zod) | `CheckoutInput` | Unit `OrdersTest` (19 kasus), live, E2E | PARITY VERIFIED |
| Harga, subtotal, total dari DB; field harga/status dari browser diabaikan; snapshot item dan modifier | `createOrder` | sama | Unit, live, E2E, browser | PARITY VERIFIED |
| Produk sold out, nonaktif, modifier salah grup, opsi wajib kosong ditolak dengan nama produk | `quote.ts` | `Storefront::quote` | Live | PARITY VERIFIED |
| QRIS ditolak ("Pembayaran online belum tersedia"); hanya tunai di kasir | `createOrder` | sama | Live, E2E, browser (opsi QRIS nonaktif) | PARITY VERIFIED |
| Order baru selalu `pending_payment`/`pending`, tagihan `payments` status `pending` tanpa sesi kas, tanpa nomor antrean | `createOrder` | sama | Live, E2E | PARITY VERIFIED |
| Idempotensi `(outlet_id, idempotency_key)`, retry identik = order sama, isi berbeda = konflik 409 dengan pesan sama, harga berubah tetap retry, order lama tanpa `request_hash` tetap replay | `createOrder`, `request-hash.ts` | `insert … on conflict do nothing`, `CheckoutInput::requestHash` | Unit (hash sama persis dengan referensi Next.js), live | PARITY VERIFIED |
| Request bersamaan: 3 proses identik → 1 order, 1 tagihan, 1 audit; 2 proses isi berbeda → 1 menang, 1 konflik | `batch1-pos.test.ts` | sama | Live, proses PHP terpisah yang mulai bersamaan | PARITY VERIFIED |
| Token guest: 32 byte acak, hanya hash di DB, cookie HttpOnly per order di path `/order/status`, SameSite Lax, Secure di production, 3 hari; retry menerbitkan token baru; konflik tidak memutar token | `tokens.ts`, `api/orders` | `GuestTokens`, `CheckoutController` | Unit, live, E2E | PARITY VERIFIED |
| Halaman status hanya dengan cookie pemilik, data publik saja, `no-referrer`, `noindex`, `no-store`, hitung mundur batas bayar, polling saat pending | `order/status/[id]` | `OrderStatusController`, `order/status.tsx` | E2E, browser | PARITY VERIFIED, lihat perbedaan 13 |
| Kiosk: hanya dine in/takeaway, tanpa nomor HP, tanpa cookie token, layar konfirmasi kode lalu kembali ke menu | `kiosk/page.tsx` | `kiosk/index.tsx` | E2E, browser | PARITY VERIFIED |
| Checkout publik: 415 non-JSON, 413 > 64 KB, 400 body/channel salah (channel `pos` tidak bisa dipilih), 403 origin asing, 419 tanpa CSRF, 422 invalid, 409 konflik, 201/200 | `api/orders/route.ts` | `CheckoutController` | E2E | PARITY VERIFIED |
| Rate limit checkout: perangkat 6, kiosk 40, IP 60 (atau 120 per outlet bila IP tidak diketahui), retry 120 per 10 menit; 429 + `Retry-After`; retry order lama tidak memakai kuota | `checkout-limits.ts` | `CheckoutLimits`, `RateLimits` | Unit, E2E | PARITY VERIFIED |
| Kedaluwarsa order belum dibayar memakai `public.expire_pending_orders` dan job pg_cron yang sudah ada (tepat satu job) | `0004_ops_functions.sql` | tidak ada job baru | Live | PARITY VERIFIED |
| POS: hanya staf dengan `order.create` di outlet itu; company lain `not_found`; owner 403; barista/kasir boleh | `posCheckoutAction` | `PosController::store` | Live, E2E, browser | PARITY VERIFIED |
| Terminal POS: cari, kategori, picker modifier, keranjang samping (desktop) atau sheet (HP/tablet), tipe pesanan, idempotency key direset saat isi berubah atau konflik | `pos-terminal.tsx` | `pos/index.tsx` | Browser (HP, tablet, desktop) | PARITY VERIFIED untuk lingkup M3, lihat perbedaan 14 |
| Daftar pesanan hari ini (menunggu bayar, diproses, semua), cari kode/nomor/nama, detail dengan riwayat audit, struk 76 mm "BELUM DIBAYAR" | `pos/orders/*`, `order-detail.tsx` | `PosOrderController`, `pos/orders/*.tsx` | Live, E2E, browser | PARITY VERIFIED |
| Order belum dibayar tidak masuk daftar "Diproses" (antrean produksi) | `listOrders` | `OrderEngine::list` | Live | PARITY VERIFIED |
| Audit `order.created` tanpa token; sinyal realtime tanpa data di `outlet-orders:<id>` di dalam transaksi | `createOrder`, `realtime.ts` | sama | Live | PARITY VERIFIED |
| Isolasi company dan izin outlet untuk detail/daftar/struk (404 tanpa bocor info) | `getOrderDetail` | `OrderEngine::detail` | Live, E2E | PARITY VERIFIED |

Pengukuran (laptop development ke Supabase dev eu-west-1, bukan angka production): checkout membutuhkan 12 query, tetap sama untuk 1, 5, maupun 20 baris item (tidak ada N+1). Retry 9 query, status guest 4, detail staf 6, daftar 2, menu POS 5. Satu round-trip query dari laptop ±0,59 detik, jadi checkout ±7 detik di laptop hampir seluruhnya waktu jaringan. Angka production harus diukur ulang dari server yang satu region dengan database.

## M4 rinci

Lingkup disetujui owner 3 November 2026 untuk soft launch: sesi kas minimum, bayar tunai, antrean, KDS, status pelanggan, struk, pembatalan dengan approval, refund tunai penuh, dan inventory guard. Laporan, ekspor, dan stok penuh tetap di M5/M6.

| Perilaku | Next.js | Laravel | Test Laravel | Status |
|---|---|---|---|---|
| Sesi kas: satu sesi terbuka per outlet (unique index parsial), modal awal, kas masuk/keluar beralasan, kas keluar ≤ saldo | `cash/engine.ts` | `CashSessions` | Live, E2E, browser | PARITY VERIFIED |
| Tutup kas: saldo seharusnya dihitung ulang (modal + penjualan tunai + kas masuk − kas keluar − refund), selisih wajib beralasan, sesi tertutup tidak menerima pembayaran | `closeCashSession` | `CashSessions::close` | Live (angka per komponen + audit), E2E, browser | PARITY VERIFIED |
| Bayar tunai: hanya `payment.confirm_cash`, order harus `pending_payment`, uang diterima ≥ total, kembalian, terikat sesi kas terbuka (lock share) | `confirmCashPayment` | `OrderWorkflow::confirmCash` | Live, E2E, browser | PARITY VERIFIED |
| Idempotensi pembayaran: bayar ulang → nomor sama (`alreadyPaid`), 3 proses bersamaan → 1 pembayaran, 1 nomor, 1 audit | sama | sama, row lock order | Live multi-proses, E2E | PARITY VERIFIED |
| Nomor antrean per outlet per tanggal operasional (zona waktu outlet), upsert counter, `paid_at` diambil setelah lock sehingga urutan KDS = urutan nomor | sama | sama | Live: 4 pembayaran bersamaan → nomor berurutan | PARITY VERIFIED |
| Pembayaran vs tutup kas bersamaan: saldo saat tutup selalu = modal + penjualan sesi itu | lock share/update | sama | Live multi-proses | PARITY VERIFIED |
| Pembayaran order yang sudah lewat `expires_at`: ditolak, pembatalan otomatis ikut ter-commit lewat `expire_pending_orders` | sama | sama | Live | PARITY VERIFIED |
| Buat + bayar sekaligus dari POS; bila pembayaran gagal, order tetap tersimpan dengan peringatan | `posCheckoutAction` | `PosController::store` | E2E, browser | PARITY VERIFIED |
| KDS: hanya order lunas, FIFO waktu bayar, New/Preparing/Ready/Completed, `from` mencegah lompat dua langkah, mundur butuh `kds.manage`, waktu tunggu | `getKdsBoard`, `updateOrderStatus` | `OrderEngine::kdsBoard`, `OrderWorkflow::updateStatus` | Live, E2E, browser | PARITY VERIFIED |
| Realtime: sinyal tanpa data di dalam transaksi ke `outlet-orders:<id>` dan topik guest; browser berlangganan Supabase Realtime, polling bila gagal (KDS 5 detik, lainnya 15 detik) | `use-realtime-refresh.ts` | `useRealtimeRefresh` (`@supabase/realtime-js` 2.118.0) | Live (pesan realtime), browser (mode `live`) | PARITY VERIFIED, lihat catatan latensi |
| Status pelanggan: Menunggu pembayaran → Pesanan diterima → Sedang dibuat → Siap diambil → Selesai | `order/status` | `order/status.tsx` | Live, E2E, browser | PARITY VERIFIED |
| Pembatalan: belum bayar langsung batal beralasan; lunas → approval `void`; pemohon tidak bisa memutuskan; status bayar tetap `paid` sampai refund | `cancelOrder`, `decideCancellation` | sama | Live, E2E, browser | PARITY VERIFIED |
| Refund tunai penuh: hanya order lunas yang pembatalannya disetujui, dari sesi kas terbuka, saldo laci harus cukup, `refunds` + `cash_movements` + status, tidak bisa dua kali | `refundCashOrder` | `OrderWorkflow::refundCash` | Live (termasuk 2 proses bersamaan dan refund vs tutup kas), E2E, browser | PARITY VERIFIED |
| Struk lunas 80 mm: nomor antrean, kode, waktu pesan dan bayar, kasir, item + modifier, total, metode, diterima, kembali; BELUM DIBAYAR / DIREFUND / DIBATALKAN | `receipt/page.tsx` | `pos/orders/receipt.tsx` | E2E props, browser (mode print, PDF 80 mm satu halaman) | PARITY VERIFIED, lihat perbedaan 18 |
| Daftar pesanan: tampilan Lunas (termasuk yang direfund) | filter `payment` | `view=paid` | Live, E2E, browser | PARITY VERIFIED |
| Pemotongan stok produk jadi saat bayar | `postSaleMovements` | Inventory guard: pembayaran ditolak bila produk tertaut `outlet_products.inventory_item_id` | Live (tanpa perubahan apa pun, termasuk 2 proses bersamaan), E2E | PARTIAL: pengganti aman sampai M5, lihat perbedaan 17 |

Hasil uji M4 (4 November 2026, Supabase dev): Pint, TypeScript, build lolos; unit/feature 129/129; live 107/107 (637 assertion, termasuk `PaymentFlowTest` 18 dan `OrderEngineTest` 19 setelah refactor helper konkurensi); E2E 32/33 pada regresi penuh, lalu `OrdersTest` 6/6 setelah test M3 berpremis "belum ada endpoint bayar" diperbarui untuk M4 (guest kini mendapat 401 di endpoint bayar staf; order tetap belum dibayar); browser M3 137/137, browser M4 56/58 + bagian 2 23/24 + cek desktop 2/2, latensi realtime 12/12. Kegagalan browser yang tersisa adalah artefak server QA satu proses atau cek probe di layar HP. Jumlah query per operasi: bayar tunai 12, bayar ulang 2, ubah status KDS 6, papan KDS 4 (6 order), detail order 8, refund 13, tutup kas 8.

Realtime (browser, Supabase dev, server QA `php -S` satu proses di laptop): KDS dan halaman status pelanggan tersambung mode `live` (bukan polling). Diukur terpisah dari durasi request: sinyal push tiba di halaman pelanggan 227–240 ms setelah aksi kasir/barista, dan di KDS bahkan sebelum kasir menerima respons (sinyal dikirim saat commit). Data tampil setelah satu request reload, ±4,7–8,5 detik di server dev laptop (±7,4 detik per request staf, diukur langsung). Antrean menit-an saat 4 layar aktif bersamaan adalah artefak server satu proses. Latensi production harus diukur di staging dengan PHP-FPM multi-worker di region yang sama dengan database.

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
11. **Checkout bertahap.** Selama M2 checkout belum aktif. Sejak M3 checkout aktif, tetapi pembayaran tunai baru dikonfirmasi di M4. Sampai saat itu order tetap menunggu pembayaran dan tidak masuk antrean.
12. **Error tak terduga pada aksi admin** tampil sebagai pesan "Terjadi kesalahan. Coba lagi.", sama dengan `runAction` Next.js, bukan halaman 500.
13. **Status guest tanpa realtime (M3 saja).** Sejak M4 halaman status berlangganan Supabase Realtime seperti Next.js, dengan polling 15 detik sebagai cadangan.
14. **POS tanpa tombol bayar tunai (M3 saja).** Sejak M4 tombol "Bayar tunai & proses", uang diterima/kembalian, dan peringatan sesi kas sudah ada, sama dengan Next.js.
15. **Kosongkan keranjang POS minta konfirmasi** dan tombol hapus item dijauhkan dari tombol jumlah agar tidak salah tekan. Next.js langsung mengosongkan.
16. **Normalisasi id.** Laravel mengubah UUID dan idempotency key ke huruf kecil sebelum disimpan dan di-hash. Client bawaan selalu mengirim huruf kecil, jadi hasilnya sama dengan Next.js.
17. **Inventory guard menggantikan pemotongan stok.** Next.js memotong stok produk jadi saat bayar dan mengembalikannya saat pembatalan disetujui. Laravel belum punya ledger stok (M5), jadi pembayaran order yang berisi produk tertaut stok ditolak dengan pesan untuk kasir, sebelum ada pembayaran, kas, nomor antrean, atau perubahan status. Tautan stok tidak diubah.
18. **Ukuran halaman struk.** Laravel menetapkan `@page` 80 mm × tinggi isi struk agar print browser tidak memakai kertas A4/Letter. Next.js hanya mengatur lebar elemen.
19. **Navigasi POS menampilkan Kas dan KDS** sesuai izin user. Halaman `/admin/orders` Next.js belum dipindahkan, sehingga Owner (punya `approval.approve`, tanpa `order.create`) belum bisa menyetujui pembatalan dari Laravel; Manager menyetujui dari POS.

## Test Next.js yang tidak dipindahkan

| Test Next.js | Alasan |
|---|---|
| `batch1-security` "cookie session ditulis httpOnly oleh client server" | Diganti: Laravel tidak menulis cookie Supabase. Pemeriksaan cookie sesi Laravel ada di `RoutesTest` |
| `routes.test.ts` 3b dan 4b (memanggil Supabase langsung) | Menguji Supabase, bukan aplikasi. Perilaku yang sama tercakup di test login aplikasi |
| `routes.test.ts` /order "Menu belum tersedia", /kiosk | Tercakup di M3: E2E `OrdersTest` (kiosk tanpa outlet, outlet tak dikenal) dan browser (menu kosong di /order dan /kiosk) |
| `orders.test.ts` bagian pembayaran cash, nomor antrean, KDS, pembatalan, approval, realtime pembayaran | Tercakup di M4 (`PaymentFlowTest` live dan E2E) |
| `batch1-pos.test.ts` "retry identik mengembalikan … nomor yang sama" (bagian pembayaran) | Tercakup: M3 (order) dan M4 (bayar ulang → nomor sama, `alreadyPaid`) |
| Pemotongan stok produk jadi saat bayar dan reversal saat batal (`inventory/ledger.ts`) | Ditunda ke M5. Diganti inventory guard yang menolak pembayaran produk tertaut stok |

Jumlah test Next.js terkait M1: 87 (unit 34, integrasi 36, E2E 17). Semua perilakunya tercakup kecuali yang tercantum di atas.

## Keterbatasan M1 yang tercatat

- Di HP, tombol Keluar admin hanya ada di dashboard. Perilaku ini sama dengan Next.js.
- Rate limit Supabase Auth tetap per IP server karena login dipanggil dari server (SEC-016, EXT-027).
- Batas tunggu link undangan mengikuti pengaturan OTP Supabase.
- Kecepatan halaman di laptop ini tidak mewakili production: database ada di eu-west-1.
