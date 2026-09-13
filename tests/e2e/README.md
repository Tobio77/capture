# Pengujian End-to-End (Playwright)

Menguji aplikasi **berjalan sungguhan** lewat peramban — `php artisan serve`
di belakang, basis data SQLite khusus, tanpa menyentuh database
pengembangan MySQL `capture` sama sekali.

## Menjalankan

```bash
npm run test:e2e        # sekali jalan, laporan HTML di akhir
npm run test:e2e:ui     # mode --ui interaktif, enak untuk menulis/mem-debug test
npm run test:e2e:ci     # dipakai CI, reporter GitHub Actions
```

Tidak perlu menyalakan apa pun secara manual lebih dulu: `playwright.config.js`
menyalakan servernya sendiri (`webServer`), dan `global-setup.js` menyegarkan
serta mengisi basis data E2E SEBELUM server itu menyala.

### Yang terjadi di balik layar tiap kali dijalankan

1. `tests/e2e/global-setup.js` menghapus `database/e2e.sqlite` (kalau ada)
   lalu membuat berkas baru yang kosong.
2. Memanggil `php artisan e2e:siapkan --env=e2e`, yang men-`migrate:fresh`
   lalu mengisi `UnitKerjaSeeder`, `UserSeeder`, `PegawaiSeeder` — data
   contoh yang SAMA dipakai pengembangan lokal. Lihat `tests/e2e/data.js`
   untuk salinan kredensial dan NIP yang dipakai test — **harus disesuaikan
   bila seeder-nya berubah**.
3. Playwright menyalakan `php artisan serve --env=e2e` di `127.0.0.1:8000`.
4. Proyek `setup-auth` masuk sekali per peran (superadmin, admin dinas,
   admin UPT) dan menyimpan `storageState`-nya di `tests/e2e/.auth/` —
   spec lain memakainya lewat `test.use({ storageState: BERKAS_AUTH.x })`
   supaya tidak mengulang formulir masuk + CAPTCHA di setiap test.
5. Kiosk dan event kegiatan **tidak** ikut di-seed — spec sendiri yang
   mendaftarkan dan mengaktifkannya lewat UI (`Perangkat Absen` →
   `/kiosk/aktivasi`), supaya jalur yang teruji betul jalur yang dipakai
   admin sungguhan.

### Kenapa `.env.e2e`, bukan variabel lingkungan biasa

`Illuminate\Foundation\Console\ServeCommand` hanya meneruskan segelintir
variabel (`APP_ENV`, `PATH`, dan beberapa lainnya — lihat
`ServeCommand::$passthroughVariables`) dari proses `php artisan serve` ke
proses server sungguhan di dalamnya; variabel lain diam-diam diabaikan dan
server itu kembali membaca `.env` biasa. Karena itu konfigurasi E2E
(`DB_CONNECTION`, `SESSION_SECURE_COOKIE`, dst.) hidup di berkas
`.env.e2e` di akar proyek — dimuat otomatis begitu `APP_ENV=e2e` dikenali,
dan `APP_ENV` SATU-SATUNYA yang perlu diteruskan.

### Kenapa satu worker, tidak paralel

Aplikasi ini punya pengaturan GLOBAL bersama satu baris (Setting Absen,
hari libur) yang beberapa journey ubah sebagai bagian dari alurnya sendiri
(mis. mematikan Verifikasi Wajah, melebarkan jendela jam, membuka paksa
Absen Umum). Dua test dari berkas berbeda yang berjalan bersamaan di
worker terpisah — tetapi memakai SATU server yang sama — akan salip-
menyalip pada baris pengaturan itu. `playwright.config.js` karena itu
menetapkan `workers: 1` dan `fullyParallel: false`; jumlah test di suite
ini kecil sehingga biaya menjalankannya berurutan kecil.

## Cakupan

**Diuji:**

- Login admin (berhasil, sandi salah, CAPTCHA salah, surel tak dikenal)
- Kiosk: daftar perangkat → aktivasi → gabung event via kode unit kerja →
  tap → tercatat di daftar e-Presensi → admin menutup event
- Kelola Pegawai: pencarian nama/NIP
- Kelola User/Role: Admin UPT ditolak (403), superadmin dapat masuk
- Laporan: "Unduh Data" (CSV/Excel) dan "Generate Laporan" (Word/Excel/PDF)
- Rekap: kedua tab (kegiatan, umum), unduh CSV/Excel
- Setting Absen: jadwal jam dan ambang batas Laporan tersimpan
- Hari Libur: tambah/hapus, banner Absen Umum menyebut kalender sebagai
  alasan tertutup
- Halaman depan: tiga pilihan (Absen Umum, Absen Event, Masuk Admin)

**SENGAJA di luar cakupan** (tidak dapat diuji andal dengan Playwright):

- **Verifikasi wajah sungguhan.** Tap yang benar-benar dicocokkan
  (`wajah_terdaftar: true`) butuh kamera nyata dan inferensi TensorFlow.js
  atas wajah asli. Kamera palsu Chromium (`--use-fake-device-for-media-
  stream`) hanya menyediakan pola video sintetis — cukup untuk pegawai
  TANPA wajah terdaftar (fotonya cuma bukti kehadiran, tidak dicocokkan),
  tetapi mustahil menghasilkan kecocokan 1:1 yang berarti apa-apa. Semua
  test tap di suite ini karena itu memakai pegawai tanpa wajah terdaftar
  DAN mematikan Verifikasi Wajah di Setting Absen lebih dulu — lihat
  catatan cakupan di `specs/kiosk-dan-event.spec.js`.
- **Kartu RFID.** Tidak ada cara mensimulasikan pembaca RFID fisik, dan
  ketersediaannya di lokasi kiosk sendiri belum terkonfirmasi (lihat
  `CLAUDE.md`).
- **Sinkronisasi WORKA sungguhan.** `.env.e2e` sengaja menunjuk
  `WORKA_API_URL` yang tidak valid — test hanya memastikan UI memanggil
  endpoint lokal dengan benar, tidak pernah memukul WORKA asli.

## Menambah journey baru

1. Kalau butuh kiosk aktif, pakai `daftarkanDanAktifkanKiosk()` dari
   `helpers.js` — jangan menulis ulang alur aktivasi.
2. Kalau test men-tap dari layar kiosk, panggil `siapkanUntukTapKiosk()`
   lebih dulu (mematikan Verifikasi Wajah + melebarkan jendela jam Absen
   Datang). Untuk Absen Umum spesifik, tambahkan `bukaPaksaAbsenUmum()`
   supaya kalender/hari libur tidak ikut menutupnya.
3. Data pegawai/unit kerja/admin sudah ada di `data.js` — jangan
   menuliskan ulang NIP atau email secara manual di spec.
4. Kontrol `Pilihan.vue` (dropdown kustom) dibuka lewat `pilihDariDropdown()`,
   `TanggalIsian.vue` (kalender kustom) lewat `pilihTanggalHariIni()`.
