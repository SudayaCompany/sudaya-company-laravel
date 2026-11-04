# Checkpoint M4 — 3 November 2026, 16.40

> **Diperbarui 4 November 2026:** semua langkah di bawah sudah dikerjakan. Hasil final ada di `LARAVEL_FEATURE_PARITY.md` §M4 rinci dan `GO_LIVE_READINESS.md`. Dokumen ini disimpan sebagai catatan pemulihan.

M4 (pembayaran tunai, antrean, KDS, kas, pembatalan, refund, inventory guard) **belum selesai**. Kode sudah ditulis dan tes live inti lolos, tetapi E2E, QA browser, regresi penuh, dan dokumen go-live belum dikerjakan. Pekerjaan diamankan sebagai commit WIP lokal, belum di-push, dan belum disetujui owner.

## Yang sudah dibangun

| Bagian | File utama |
|---|---|
| Sesi kas: buka, kas masuk/keluar, tutup dengan uang fisik, selisih wajib beralasan, satu sesi terbuka per outlet | `app/Services/Cash/CashSessions.php`, `Pos/CashController.php`, `pages/pos/cash.tsx` |
| Bayar tunai: lock order, idempotent, uang diterima/kembalian, terikat sesi kas, nomor antrean dari `order_number_counters`, audit | `app/Services/Orders/OrderWorkflow.php::confirmCash` |
| Bayar langsung dari POS (buat + bayar), bila gagal order tetap tersimpan dengan peringatan | `Pos/PosController.php`, `pages/pos/index.tsx` |
| KDS New/Preparing/Ready/Completed, FIFO waktu bayar, koreksi mundur butuh `kds.manage` | `OrderEngine::kdsBoard`, `OrderWorkflow::updateStatus`, `KdsController.php`, `pages/kds/index.tsx` |
| Pembatalan: belum bayar langsung batal; lunas butuh approval Manager, tanpa self-approval | `OrderWorkflow::cancel`, `decideCancellation` |
| Refund tunai penuh dari sesi kas terbuka, saldo laci harus cukup, tidak bisa dua kali | `OrderWorkflow::refundCash` |
| Inventory guard: pembayaran ditolak bila produk tertaut `outlet_products.inventory_item_id`, sebelum ada pembayaran, kas, atau nomor antrean | `OrderWorkflow::assertNoLinkedStock` |
| Realtime Supabase (sinyal tanpa data) + polling cadangan | `hooks/use-realtime-refresh.ts`, `components/orders/live-refresh.tsx`, `@supabase/realtime-js` 2.118.0 (versi sama dengan Next.js) |
| Struk lunas: metode, diterima, kembali, kasir, waktu bayar, `@page` 80 mm | `pages/pos/orders/receipt.tsx` |
| Daftar pesanan tampilan "Lunas" | `OrderEngine::list`, `pages/pos/orders/index.tsx` |

Tidak ada migration atau perubahan schema. Semua tabel, constraint, dan enum sudah ada.

## Test terakhir

| Suite | Hasil | Catatan |
|---|---|---|
| Live `PaymentFlowTest` (18 kasus, termasuk konkurensi multi-proses) | **18/18 lolos, 220 assertion**, 1184 detik | Selesai tepat saat proses QA dihentikan pukul 16.35. Ringkasan PHPUnit tercetak, tetapi exit code tidak tercatat karena skrip pembungkus ikut dihentikan. Log: scratchpad `m1/m4-payment.txt` |
| Unit/feature | 129/129 | Dijalankan setelah backend M4, sebelum tes live M4 ditulis |
| Pint, TypeScript, build Vite | lolos | Pada perubahan terakhir sebelum tes live |
| Live `OrderEngineTest` (M3) | **belum dijalankan ulang** | Helper konkurensinya dipindah ke `RunsConcurrently` + `bin/order-op.php` |
| E2E `PaymentFlowTest` (3 kasus) | **belum pernah dijalankan** | File sudah ditulis |
| QA browser M4 (`probe-m4.mjs` di scratchpad) | **belum dijalankan** | Skrip sudah ditulis |
| Regresi penuh Live + E2E | **belum** | |

## Defect dan risiko yang tercatat

- Belum ada test M4 yang gagal. Namun E2E, browser, dan regresi penuh belum berjalan, jadi M4 belum boleh disebut selesai.
- Realtime di browser belum dibuktikan: sambungan WebSocket ke Supabase dari halaman Laravel belum diuji.
- Skenario E2E kiosk → kasir bayar → KDS (permintaan owner) belum ditulis.
- Owner punya `approval.approve` tetapi tidak bisa membuka `/pos` (butuh `order.create`), sehingga approval hanya bisa dilakukan Manager dari POS. Perilaku ini sama dengan Next.js; Next.js juga punya halaman `/admin/orders` yang belum dipindahkan.
- Pembatalan dan refund hanya untuk order yang belum `completed`. Komplain setelah pesanan diserahkan memerlukan prosedur manual (catat kas keluar beralasan).
- `resources/js/pages/pending.tsx` tidak lagi dipakai (route `/kds` sudah asli).
- `npm audit`: deepmerge (moderate) dari CLI `shadcn` (alat development), tidak ikut ke browser.

## Data test

Database dev bersih per 16.40: 0 company test, 0 user Auth test, 0 order/payment/sesi kas/refund/approval/counter/inventory item, 0 counter rate limit aktif. Test M4 memakai storage palsu, jadi Storage tidak tersentuh.

## Langkah pertama saat melanjutkan

1. `php artisan test`, `npx tsc --noEmit`, `npm run build`, `vendor/bin/pint --test`.
2. `php artisan test -c phpunit.live.xml tests/E2E/PaymentFlowTest.php`, lalu perbaiki bila gagal.
3. `php artisan test -c phpunit.live.xml tests/Live/OrderEngineTest.php` (verifikasi refactor helper konkurensi).
4. Tambah E2E skenario kiosk.
5. QA browser: `fixture.php create`, seed menu (`m1/seed-m3.php`), server port 8124, `node probe-m4.mjs`.
6. Regresi penuh Live lalu E2E (berurutan, sekitar 50 menit), dengan exit code per suite.
7. Buat `docs/GO_LIVE_READINESS.md`; perbarui `LARAVEL_FEATURE_PARITY.md` dan `LARAVEL_MIGRATION_PLAN.md` untuk M4.
8. Laporan final M4 ke owner. Jangan commit final, push, deploy, atau mulai M5 tanpa persetujuan.
