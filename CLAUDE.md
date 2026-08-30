# SI-ABSEN — Konteks Proyek untuk Claude Code

Aplikasi absensi kegiatan berbasis event untuk Dinas Tenaga Kerja dan
Transmigrasi (Disnakertrans) Provinsi Jawa Timur, termasuk UPT BLK Surabaya.
Dokumen acuan lengkap ada di folder `docs/` — baca dokumen yang relevan
sebelum mengerjakan setiap sesi (lihat `docs/05-TASK-Absensi.md`).

## Dokumen Acuan (baca sesuai kebutuhan sesi)

- `docs/01-PRD-Absensi.md` — latar belakang, ruang lingkup, peran pengguna, alur bisnis
- `docs/02-SRS-Absensi.md` — kebutuhan fungsional (kode FR-xxx) & non-fungsional (NFR-xxx), matriks peran vs akses
- `docs/03-SDD-Absensi.md` — arsitektur, skema database, daftar endpoint, desain modul verifikasi wajah
- `docs/04-UIUX-Absensi.md` — sitemap, struktur layar, alur interaksi
- `docs/05-TASK-Absensi.md` — rencana sesi pengerjaan (S01–S29) per fase, jadikan panduan urutan kerja

Saat mengerjakan sebuah sesi, rujuk kode FR-xxx/NFR-xxx yang tertulis pada
sesi tersebut di `05-TASK-Absensi.md`, lalu baca detail kebutuhannya di
`02-SRS-Absensi.md` dan skema terkait di `03-SDD-Absensi.md`.

## Stack Teknis

- **Backend:** Laravel 13 (PHP 8.2+)
- **Frontend:** Inertia.js + Vue 3, Tailwind CSS 4
- **Verifikasi wajah:** face-api.js (TensorFlow.js) — berjalan di sisi klien (browser kiosk), bukan di server
- **Database:** MySQL 8 (lokal via DBngin)
- **Auth admin:** Laravel session/Sanctum
- **Auth kiosk:** device token per perangkat (bukan akun personal pegawai)

## Konvensi Kode

- Pola **Thin Controller + Fat Service** — logika bisnis di Service/Action Class, controller hanya orkestrasi.
- Ikuti gaya proyek WORKA/SIMPEG yang sudah berjalan di lingkungan Disnakertrans (Laravel 13 + Inertia + Vue 3 modular).
- Seluruh teks UI, pesan error, dan label menggunakan **Bahasa Indonesia baku**, mengikuti konvensi dokumen pemerintahan.
- Commit message singkat dan jelas; commit per unit kerja yang dapat didemonstrasikan (selaras dengan Definition of Done di `05-TASK-Absensi.md`).

## Palet & Gaya Visual (mengikuti prototipe UI/UX yang sudah disepakati)

- Warna: navy (`#0F2A43`, elemen struktural/sidebar), teal (`#0D9488`, aksi utama), emerald (`#059669`, status berhasil/tepat waktu), amber (`#B45309`, status terlambat/peringatan).
- **Tidak menggunakan ungu/indigo/violet** di palet manapun.
- Tipografi: Lexend untuk judul & angka, Inter untuk teks isi/antarmuka.
- Referensi tampilan: prototipe React `AbsensiApp.jsx` (dua panel kiosk — Capture Foto/Entry Absen di kiri, Daftar e-Presensi live di kanan) dan struktur menu Panel Admin (Dashboard, Kelola Absen, Kelola Pegawai, Kelola User/Role, Laporan).

## Peran Pengguna (ringkas — detail di PRD & SRS)

| Peran | Cakupan |
|---|---|
| Superadmin | Semua unit kerja, semua menu |
| Admin Dinas | Semua unit kerja, dapat buat event lintas unit |
| Admin UPT | Hanya unit kerjanya sendiri, tanpa menu Kelola User/Role |
| Kiosk (perangkat) | Bukan akun pegawai — mewakili komputer/laptop di titik absen |

## Lingkungan Development Lokal

- Editor: VS Code (window terpisah dari project WORKA)
- Local server: Laravel Herd — site ini di `capture.test` (folder proyek: `C:/Users/aset/Herd/capture`)
- Database: DBngin (MySQL, port 3306, instance sama dengan WORKA) — nama database `capture`
- DB GUI: TablePlus
- Version control: Git + GitHub (repo terpisah dari `worka`)

## Hal yang Masih Perlu Dikonfirmasi (jangan diasumsikan, tanyakan ke pengguna bila menyentuh bagian ini)

- Spesifikasi teknis API WORKA/BKD (autentikasi, format respons) — dibutuhkan sebelum sesi S06 (sinkronisasi pegawai).
- Ketersediaan & tipe RFID reader di lokasi kiosk — dibutuhkan sebelum sesi S14.
- Jadwal pendaftaran foto referensi wajah pegawai per unit kerja — dibutuhkan sebelum go-live (S28).

## Cara Mulai

1. Pastikan `docs/` sudah lengkap (5 file markdown di atas) dan file ini berada di root proyek.
2. Jalankan `claude` dari terminal di dalam folder proyek `capture`.
3. Mulai dari Sesi S01 di `docs/05-TASK-Absensi.md` (setup proyek Laravel 13 + Inertia + Vue 3 + Tailwind 4).
4. Kerjakan sesi secara berurutan sesuai fase; jangan lompat fase kecuali diarahkan.
