**DINAS TENAGA KERJA DAN TRANSMIGRASI**

**PROVINSI JAWA TIMUR**

**TASK-ABSEN-2026**

**Task Breakdown**

Aplikasi Absensi Kegiatan Berbasis Event — SI-ABSEN

Nama Sistem: SI-ABSEN (Sistem Informasi Absensi Kegiatan)

Versi Dokumen: 1.0

Tanggal: 30 Agustus 2026

*Status: Draf untuk implementasi*

# Riwayat Dokumen

| **Versi** | **Tanggal**     | **Perubahan**                                                           |
|-----------|-----------------|-------------------------------------------------------------------------|
| 1.0       | 30 Agustus 2026 | Draf awal rencana sesi pengerjaan untuk implementasi dengan Claude Code |

# Daftar Isi

# 1. Cara Membaca Dokumen Ini

Rencana pengerjaan dibagi menjadi fase dan sesi, mengikuti pola yang telah terbukti berjalan pada proyek WORKA/SIMPEG. Setiap sesi diharapkan menghasilkan deliverable yang dapat diuji secara mandiri sebelum berlanjut ke sesi berikutnya. Estimasi sesi bersifat indikatif dan dapat disesuaikan saat pengerjaan berlangsung.

# 2. Ringkasan Fase

| **Fase** | **Nama**               | **Fokus Utama**                                                                |
|----------|------------------------|--------------------------------------------------------------------------------|
| Fase 1   | Fondasi & Autentikasi  | Setup proyek, struktur database inti, login admin & kiosk                      |
| Fase 2   | Master Data            | Unit kerja, sinkronisasi pegawai dari WORKA/BKD, pengaturan sistem             |
| Fase 3   | Manajemen Event        | CRUD event, cakupan unit kerja, buka/tutup entry                               |
| Fase 4   | Modul Kiosk & Absensi  | Aktivasi kiosk, tap RFID/manual, capture & verifikasi wajah, penyimpanan absen |
| Fase 5   | Dashboard & Rekap      | Statistik dashboard, rekap absen live, tabel e-presensi                        |
| Fase 6   | Laporan & Pengguna     | Laporan cetak/ekspor, kelola user/role                                         |
| Fase 7   | Pengujian & Deployment | Uji end-to-end, hardening keamanan, deployment ke VPS                          |

# 3. Fase 1 — Fondasi & Autentikasi

| **Sesi** | **Fokus**         | **Deliverable**                                                                                                 |
|----------|-------------------|-----------------------------------------------------------------------------------------------------------------|
| S01      | Setup proyek      | Instalasi Laravel 13 + Inertia + Vue 3 + Tailwind 4, struktur folder, koneksi database, konfigurasi environment |
| S02      | Migration inti    | Tabel unit_kerja, pegawai, users, kiosk beserta relasi dan seeder data contoh                                   |
| S03      | Autentikasi admin | Login/logout admin, middleware peran (Superadmin/Admin Dinas/Admin UPT), proteksi route per peran               |
| S04      | Autentikasi kiosk | Alur aktivasi perangkat, penerbitan device_token, pencatatan IP saat aktivasi                                   |

# 4. Fase 2 — Master Data

| **Sesi** | **Fokus**                        | **Deliverable**                                                                                                 |
|----------|----------------------------------|-----------------------------------------------------------------------------------------------------------------|
| S05      | Setting Unit Kerja               | CRUD unit kerja beserta hak akses sesuai peran (FR-UNIT-01, FR-UNIT-02)                                         |
| S06      | Integrasi API WORKA/BKD          | Client konsumsi API pegawai, mapping field NIP/nama/unit/jabatan                                                |
| S07      | Sinkronisasi pegawai             | Job terjadwal + tombol sinkronisasi manual (FR-PEG-01, FR-PEG-02)                                               |
| S08      | Pendaftaran foto referensi wajah | Alur unggah/capture foto referensi dan status wajah_terdaftar (FR-PEG-05)                                       |
| S09      | Setting Absen                    | Toggle metode absen, toleransi default, ambang kecocokan wajah, preset kompresi foto (FR-SET-01 s.d. FR-SET-04) |

# 5. Fase 3 — Manajemen Event

| **Sesi** | **Fokus**                 | **Deliverable**                                                                     |
|----------|---------------------------|-------------------------------------------------------------------------------------|
| S10      | CRUD event                | Form buat event dengan cakupan unit kerja sesuai peran (FR-EVT-01, FR-EVT-02)       |
| S11      | Buka/tutup entry          | Aksi tutup event dan penolakan tap pada event tertutup (FR-EVT-04)                  |
| S12      | Kiosk terhubung per event | Pencatatan kiosk aktif & IP per event, tampilan detail event (FR-EVT-03, FR-EVT-05) |

# 6. Fase 4 — Modul Kiosk & Absensi

| **Sesi** | **Fokus**                             | **Deliverable**                                                                                       |
|----------|---------------------------------------|-------------------------------------------------------------------------------------------------------|
| S13      | Layar kiosk — kerangka                | Implementasi tampilan dua panel (Capture Foto/Entry Absen + Daftar e-Presensi) sesuai UIUX-ABSEN-2026 |
| S13c     | Refresh desain & tema                 | Sistem token warna tiga mode (terang/gelap/sistem), dropdown & date picker kustom, animasi yang menghormati `prefers-reduced-motion`, tata letak mobile, layar absen bertema terang |
| S14      | Input tap (manual & RFID)             | Kolom auto-focus, penerimaan input HID, pemilihan jenis Datang/Pulang (FR-TAP-02, FR-TAP-03)          |
| S15      | Integrasi face-api.js                 | Muat model di klien, ambil embedding referensi dari server, deteksi & hitung embedding capture        |
| S16      | Logika verifikasi & penyimpanan absen | Pencocokan skor terhadap ambang, kompresi foto, kirim & simpan hasil absen (FR-TAP-04 s.d. FR-TAP-07) |
| S17      | Update live Daftar e-Presensi         | Pembaruan tabel tanpa duplikasi baris per (event, pegawai, jenis) (FR-TAP-05, FR-TAP-08)              |
| S18      | Penanganan offline sementara          | Antrian lokal di klien saat koneksi terputus dan sinkronisasi ulang (NFR-05)                          |

# 7. Fase 5 — Dashboard & Rekap

| **Sesi** | **Fokus**               | **Deliverable**                                                                 |
|----------|-------------------------|---------------------------------------------------------------------------------|
| S19      | Dashboard statistik     | Kartu statistik dan grafik tren 7 hari terfilter peran (FR-DASH-01, FR-DASH-02) |
| S20      | Aktivitas absen terbaru | Feed aktivitas terbaru pada dashboard (FR-DASH-03)                              |
| S21      | Rekap Absen per event   | Halaman rekap live dengan filter event dan cetak (FR-REK-01 s.d. FR-REK-03)     |

# 8. Fase 6 — Laporan & Pengguna

| **Sesi** | **Fokus**         | **Deliverable**                                                                           |
|----------|-------------------|-------------------------------------------------------------------------------------------|
| S22      | Laporan kehadiran | Filter tanggal/unit kerja, rekap per pegawai, ekspor PDF/Excel (FR-LAP-01 s.d. FR-LAP-03) |
| S23      | Kelola akun admin | CRUD akun Admin Dinas/Admin UPT, reset password (FR-USR-01)                               |
| S24      | Kelola akun kiosk | CRUD akun kiosk, cabut device_token, riwayat login (FR-USR-02, FR-USR-03)                 |

# 9. Fase 7 — Pengujian & Deployment

| **Sesi** | **Fokus**                  | **Deliverable**                                                                               |
|----------|----------------------------|-----------------------------------------------------------------------------------------------|
| S25      | Pengujian fungsional       | Test case untuk alur tap, buka/tutup event, dan batas keterlambatan                           |
| S26      | Hardening keamanan         | Review autentikasi kiosk, validasi ulang backend, proteksi akses foto (NFR-03, NFR-04)        |
| S27      | Uji beban ringan kiosk     | Perintah `absen:uji-beban` — beberapa perangkat menembak satu event bersamaan lewat HTTP sungguhan; batas laju dipindah dari per-IP ke per-perangkat |
| S28      | Persiapan data & pelatihan | Sinkronisasi awal data pegawai, pendaftaran foto referensi massal, pelatihan admin unit kerja |
| S29      | Deployment                 | Rilis ke VPS Jagoan Hosting, konfigurasi CI (GitHub Actions), pemantauan awal pasca-rilis     |

# 10. Ketergantungan Eksternal yang Perlu Dikonfirmasi Sebelum Mulai

- Spesifikasi teknis API WORKA/BKD (autentikasi, format respons, endpoint pegawai) — diperlukan sebelum Sesi S06.

- Ketersediaan RFID reader USB HID di lokasi-lokasi kiosk yang akan diaktifkan — diperlukan sebelum Sesi S14.

- Jadwal sesi pendaftaran foto referensi wajah pegawai per unit kerja — diperlukan sebelum go-live (Sesi S28).

# 11. Definition of Done per Sesi

- Kode tersimpan di repository GitHub dengan pesan commit yang jelas.

- Fitur pada sesi terkait dapat didemonstrasikan berjalan sesuai kebutuhan fungsional (FR) yang dirujuk.

- Tidak ada regresi pada fitur dari sesi-sesi sebelumnya (diverifikasi manual atau melalui test otomatis bila tersedia).
