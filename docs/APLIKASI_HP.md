# Aplikasi Sudaya di HP (absen, kasir, barista, admin)

Web Sudaya bisa dipasang sebagai **aplikasi di HP** tanpa Play Store. Setelah dipasang, ikon **Sudaya** muncul di layar HP seperti aplikasi biasa. Aplikasi terbuka layar penuh tanpa bilah alamat browser dan langsung ke halaman sesuai peran (barista ke layar barista, kasir ke kasir, owner ke dasbor). Aplikasi ini untuk **staf**. Pelanggan tetap memesan lewat scan QR dan tidak ditawari pemasangan.

> Syarat: web sudah online di domain **HTTPS** (server/VPS production). Pemasangan aplikasi, kamera selfie, dan GPS untuk absen hanya berfungsi di HTTPS.

## Memasang di Android (Chrome)

1. Buka Chrome, lalu ketik alamat web Sudaya, mis. `https://<domain>/login`.
2. Ketuk **Pasang** di kartu "Pasang aplikasi Sudaya di HP". Bila kartunya tidak muncul, buka menu **⋮**, lalu pilih **Instal aplikasi** atau **Tambahkan ke layar utama**.
3. Ketuk **Instal**. Ikon **Sudaya** masuk ke daftar aplikasi.
4. **Tekan lama** ikon Sudaya untuk jalan pintas **Absen**, **Barista**, dan **Kasir**.

## Memasang di iPhone (Safari)

1. Buka alamat web Sudaya di **Safari**.
2. Ketuk tombol **Bagikan** (kotak dengan panah ke atas).
3. Pilih **Tambahkan ke Layar Utama**, lalu ketuk **Tambah**.

## Pertama kali dipakai

1. Buka aplikasi **Sudaya**, lalu masuk dengan email dan password yang dibuat admin.
2. Saat absen pertama, izinkan **Kamera** (selfie) dan **Lokasi** (cek area outlet). Bila sempat ditolak, buka pengaturan HP → Aplikasi → Sudaya/Chrome → Izin → Kamera & Lokasi → Izinkan.
3. Absen: buka **Absen** (atau tekan lama ikon → **Absen**). Lalu ketuk **Absen masuk**, ambil selfie, tunggu "Lokasi sesuai", dan ketuk **Kirim absen masuk**. Absen pulang caranya sama. Detail ada di [Panduan Pemakaian §8](PANDUAN_PENGGUNA.md).

## Hal yang perlu diketahui

- **Butuh internet.** Bila koneksi putus, aplikasi menampilkan "Tidak ada koneksi internet" dengan tombol **Coba lagi**. Absen dan pesanan tidak disimpan offline supaya data tetap sah: jam, lokasi, dan foto dicatat server saat dikirim.
- **Pembaruan otomatis.** Setiap web diperbarui, aplikasi ikut terbarui tanpa pasang ulang.
- **HP/tablet bersama** (mis. tablet barista di outlet): ketuk **Keluar** di akhir shift agar akun berikutnya tidak memakai sesi orang lain.
- **Aman.** Aplikasi tidak menyimpan halaman, data pesanan, atau foto di HP. Yang disimpan hanya halaman "tidak ada koneksi".
- **Ikon sementara.** Huruf "S" dipakai sampai logo resmi tersedia. Untuk mengganti, timpa file di `public/icons/` dengan ukuran yang sama: `icon-192.png` (192×192), `icon-512.png` (512×512), `icon-maskable-512.png` (512×512, logo di tengah ±60% area), `apple-touch-icon.png` (180×180, tanpa transparan).

## File APK (opsional, setelah domain production ada)

Pemasangan lewat Chrome di atas sudah membuat aplikasi Android sungguhan. Chrome otomatis membangun paket aplikasi (WebAPK). File **.apk** terpisah hanya perlu bila owner ingin membagikan installer, misalnya lewat WhatsApp atau Drive, atau menerbitkannya di Play Store. APK dibuat dari alamat web production, jadi baru bisa dibuat setelah domain HTTPS aktif.

1. Pastikan `https://<domain>/login` sudah bisa dibuka dan kartu "Pasang aplikasi" muncul di Chrome Android.
2. Buka [PWABuilder](https://www.pwabuilder.com), masukkan alamat `https://<domain>/login`, lalu pilih **Package for stores → Android**.
3. Isi **Package ID**, mis. `id.sudayacoffee.staf`, dan **App name** `Sudaya`, lalu unduh paketnya. Isinya APK (untuk dibagikan/dites), AAB (untuk Play Store), **kunci penandatangan**, dan `assetlinks.json`.
4. **Simpan kunci penandatangan dan password-nya di tempat aman**, misalnya password manager owner. Kunci ini wajib untuk setiap pembaruan APK. Jangan di-commit ke Git.
5. Di server production, isi env berikut, lalu jalankan `php artisan config:cache`:
   - `ANDROID_APP_PACKAGE` = Package ID di atas.
   - `ANDROID_APP_SHA256` = nilai `sha256_cert_fingerprints` dari `assetlinks.json` di paket. Bila lebih dari satu, pisahkan dengan koma.
6. Cek `https://<domain>/.well-known/assetlinks.json`: harus menampilkan JSON paket tadi. Selama env kosong alamat ini 404, dan APK tetap berjalan tetapi menampilkan bilah alamat di atas.
7. Bagikan APK ke staf (Android: izinkan "Instal aplikasi tidak dikenal"), atau terbitkan AAB lewat Google Play Console. Akun developer Play berbayar sekali, sehingga perlu keputusan owner.

## Catatan teknis (untuk yang mengelola server)

- File aplikasi ada di `public/`: `manifest.webmanifest`, `sw.js`, `offline.html`, dan `icons/`. Manifest hanya dipasang di halaman staf (`App\Support\StaffApp::offeredOn`). Halaman pelanggan (`/`, `/order…`, `/kiosk…`) tidak memasangnya.
- Sajikan `sw.js` dengan `Cache-Control: no-cache` (jangan cache panjang) agar pembaruan service worker cepat sampai. Sajikan `.webmanifest` dengan MIME `application/manifest+json`. Konfigurasi Nginx Laravel standar tidak memblokir `/.well-known/`.
- Service worker hanya menangani navigasi halaman: selalu dari jaringan, dan halaman offline saat putus. Request lain (data Inertia, API, foto, unggahan) tidak disentuh dan tidak di-cache.
