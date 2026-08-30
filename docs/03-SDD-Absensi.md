**DINAS TENAGA KERJA DAN TRANSMIGRASI**

**PROVINSI JAWA TIMUR**

**SDD-ABSEN-2026**

**System Design Document**

Aplikasi Absensi Kegiatan Berbasis Event — SI-ABSEN

Nama Sistem: SI-ABSEN (Sistem Informasi Absensi Kegiatan)

Versi Dokumen: 1.0

Tanggal: 30 Agustus 2026

*Status: Draf untuk implementasi*

# Riwayat Dokumen

| **Versi** | **Tanggal**     | **Perubahan**                             |
|-----------|-----------------|-------------------------------------------|
| 1.0       | 30 Agustus 2026 | Draf awal, diturunkan dari SRS-ABSEN-2026 |

# Daftar Isi

# 1. Gambaran Arsitektur

SI-ABSEN dibangun sebagai monolith Laravel dengan Inertia.js + Vue 3, selaras dengan stack WORKA/SIMPEG yang sudah berjalan di lingkungan Disnakertrans, sehingga memudahkan pemeliharaan oleh tim yang sama. Modul verifikasi wajah berjalan di sisi klien (browser kiosk) menggunakan face-api.js (TensorFlow.js) agar beban komputasi tidak dipikul server.

### 1.1 Komponen Utama

- Panel Admin (Vue 3 + Inertia) — dashboard, kelola absen, kelola pegawai, kelola user/role, laporan.

- Layar Kiosk (Vue 3, mode ringan/kios) — aktivasi perangkat, capture kamera, form entry absen, daftar e-presensi live.

- Backend Laravel 13 — autentikasi, otorisasi berbasis peran, REST/Inertia endpoints, penjadwalan sinkronisasi pegawai, penyimpanan foto terkompresi.

- Modul Face Matching (client-side) — face-api.js memuat model ringan (~6 MB) di browser kiosk, menghasilkan embedding wajah, dan membandingkannya dengan embedding referensi yang diambil dari server saat kiosk memuat data event.

- MySQL — penyimpanan data transaksional (event, absensi, pegawai tersinkron, user, kiosk).

- Integrasi API WORKA/BKD — sinkronisasi data pegawai satu arah (read-only) ke SI-ABSEN.

### 1.2 Diagram Alur Data (naratif)

Admin membuat event pada Panel Admin → data event tersimpan di MySQL → Kiosk yang aktif untuk unit terkait mengambil data event & daftar embedding wajah pegawai unit tersebut saat login → pegawai tap ID di Kiosk → browser kiosk menangkap wajah, menghitung embedding, membandingkan dengan embedding milik ID yang di-tap → jika cocok, kiosk mengirim hasil (ID, waktu, jenis datang/pulang, skor kecocokan, foto terkompresi) ke Backend → Backend memvalidasi ulang (event masih aktif, waktu dalam batas) → data absen tersimpan → Panel Admin & layar Kiosk lain menampilkan pembaruan melalui polling berkala.

> *Catatan: Verifikasi akhir tetap divalidasi ulang oleh backend (event aktif, ID valid, tidak duplikat) sebelum data absen dianggap sah, sehingga kiosk yang dimanipulasi di sisi klien tidak dapat memalsukan data tanpa terdeteksi.*

# 2. Rincian Teknologi (Tech Stack)

| **Layer**         | **Teknologi**                                                                               |
|-------------------|---------------------------------------------------------------------------------------------|
| Backend           | Laravel 13 (PHP 8.2+), pola Thin Controller + Fat Service/Action Class                      |
| Frontend          | Inertia.js + Vue 3, Tailwind CSS 4                                                          |
| Verifikasi Wajah  | face-api.js (TensorFlow.js) — dijalankan di browser kiosk                                   |
| Database          | MySQL 8                                                                                     |
| Autentikasi Admin | Laravel session/Sanctum                                                                     |
| Autentikasi Kiosk | Token perangkat (device token) tersimpan lokal di browser kiosk, terikat ke satu unit kerja |
| Penjadwalan       | Laravel Scheduler untuk sinkronisasi berkala data pegawai dari WORKA/BKD                    |
| Deployment        | VPS Jagoan Hosting (mengikuti pola deployment WORKA/SIMPEG)                                 |
| Version Control   | GitHub                                                                                      |

# 3. Skema Database (Rancangan Awal)

Skema berikut adalah rancangan awal tabel inti; penamaan kolom dapat disesuaikan pada tahap implementasi mengikuti konvensi migration Laravel.

## 3.1 unit_kerja

| **Kolom**  | **Tipe**     | **Keterangan**                 |
|------------|--------------|--------------------------------|
| id         | bigint, PK   |                                |
| kode       | varchar(20)  | Kode unit, unik (mis. BLK-SBY) |
| nama       | varchar(150) |                                |
| aktif      | boolean      | default true                   |
| timestamps | \-           | created_at, updated_at         |

## 3.2 pegawai

| **Kolom**               | **Tipe**                | **Keterangan**                             |
|-------------------------|-------------------------|--------------------------------------------|
| id                      | bigint, PK              |                                            |
| nip                     | varchar(20), unik       | sumber: WORKA/BKD                          |
| nama                    | varchar(150)            | sumber: WORKA/BKD                          |
| unit_kerja_id           | bigint, FK → unit_kerja |                                            |
| jabatan                 | varchar(150)            | sumber: WORKA/BKD                          |
| foto_referensi_path     | varchar(255), nullable  | path foto referensi wajah                  |
| wajah_terdaftar         | boolean                 | default false                              |
| sumber_sinkron_terakhir | timestamp               | waktu sinkronisasi terakhir dari WORKA/BKD |
| timestamps              | \-                      | created_at, updated_at                     |

## 3.3 users (akun admin)

| **Kolom**     | **Tipe**             | **Keterangan**                         |
|---------------|----------------------|----------------------------------------|
| id            | bigint, PK           |                                        |
| nama          | varchar(150)         |                                        |
| email         | varchar(150), unik   |                                        |
| password      | varchar(255)         | hashed                                 |
| role          | enum                 | superadmin \| admin_dinas \| admin_upt |
| unit_kerja_id | bigint, FK, nullable | wajib diisi untuk role admin_upt       |
| aktif         | boolean              | default true                           |
| timestamps    | \-                   | created_at, updated_at                 |

## 3.4 kiosk (akun perangkat)

| **Kolom**         | **Tipe**                | **Keterangan**                 |
|-------------------|-------------------------|--------------------------------|
| id                | bigint, PK              |                                |
| nama_titik        | varchar(150)            | mis. “Aula Senam BLK Surabaya” |
| unit_kerja_id     | bigint, FK → unit_kerja |                                |
| device_token      | varchar(100), unik      | token autentikasi perangkat    |
| ip_terakhir       | varchar(45), nullable   |                                |
| status            | enum                    | online \| offline              |
| login_terakhir_at | timestamp, nullable     |                                |
| aktif             | boolean                 | default true                   |
| timestamps        | \-                      | created_at, updated_at         |

## 3.5 event_absen

| **Kolom**       | **Tipe**            | **Keterangan**                               |
|-----------------|---------------------|----------------------------------------------|
| id              | bigint, PK          |                                              |
| nama            | varchar(150)        |                                              |
| tanggal         | date                |                                              |
| jam_mulai       | time                |                                              |
| toleransi_menit | int                 | default dari Setting Absen, dapat dioverride |
| cakupan         | enum                | unit \| semua_unit                           |
| status          | enum                | aktif \| ditutup                             |
| dibuat_oleh     | bigint, FK → users  |                                              |
| ditutup_pada    | timestamp, nullable |                                              |
| catatan         | text, nullable      |                                              |
| timestamps      | \-                  | created_at, updated_at                       |

## 3.6 event_unit_kerja (pivot)

| **Kolom**      | **Tipe**   | **Keterangan** |
|----------------|------------|----------------|
| event_absen_id | bigint, FK |                |
| unit_kerja_id  | bigint, FK |                |

## 3.7 event_kiosk (log kiosk aktif per event)

| **Kolom**      | **Tipe**    | **Keterangan**                           |
|----------------|-------------|------------------------------------------|
| id             | bigint, PK  |                                          |
| event_absen_id | bigint, FK  |                                          |
| kiosk_id       | bigint, FK  |                                          |
| ip_address     | varchar(45) | dicatat saat kiosk aktif untuk event ini |
| aktif_pada     | timestamp   |                                          |

## 3.8 absensi

| **Kolom**            | **Tipe**             | **Keterangan**                                  |
|----------------------|----------------------|-------------------------------------------------|
| id                   | bigint, PK           |                                                 |
| event_absen_id       | bigint, FK           |                                                 |
| pegawai_id           | bigint, FK → pegawai |                                                 |
| kiosk_id             | bigint, FK → kiosk   |                                                 |
| jenis                | enum                 | datang \| pulang                                |
| metode               | enum                 | manual \| rfid                                  |
| waktu                | datetime             |                                                 |
| status_ketepatan     | enum, nullable       | tepat \| terlambat (berlaku untuk jenis datang) |
| skor_kecocokan_wajah | decimal(5,2)         | hasil similarity dari face-api.js               |
| foto_path            | varchar(255)         | path foto hasil capture, terkompresi            |
| timestamps           | \-                   | created_at, updated_at                          |

> *Catatan: Kombinasi (event_absen_id, pegawai_id, jenis) bersifat unik agar tap berulang untuk jenis yang sama memperbarui data, bukan menduplikasi baris — sesuai FR-TAP-05.*

## 3.9 pengaturan_absen (setting, single-row atau key-value)

| **Kolom**               | **Tipe**   | **Keterangan**             |
|-------------------------|------------|----------------------------|
| id                      | bigint, PK |                            |
| metode_manual_aktif     | boolean    | default true               |
| metode_rfid_aktif       | boolean    | default true               |
| metode_wajah_aktif      | boolean    | default true               |
| toleransi_default_menit | int        | default 15                 |
| ambang_kecocokan_wajah  | int        | persentase, default 85     |
| kompresi_foto           | enum       | ringan \| sedang \| tinggi |
| timestamps              | \-         | created_at, updated_at     |

# 4. Desain Endpoint Utama

Ringkasan endpoint inti; daftar lengkap akan dirinci sebagai route Laravel pada tahap implementasi.

| **Method** | **Endpoint**                           | **Keterangan**                                                                  |
|------------|----------------------------------------|---------------------------------------------------------------------------------|
| POST       | /admin/login                           | Login akun admin                                                                |
| POST       | /kiosk/aktivasi                        | Aktivasi perangkat kiosk, menghasilkan device_token                             |
| GET        | /kiosk/event-aktif                     | Kiosk mengambil event aktif untuk unit kerjanya                                 |
| GET        | /kiosk/embedding-wajah/{unit_kerja_id} | Kiosk mengambil daftar embedding wajah pegawai unit terkait (di-cache di klien) |
| POST       | /kiosk/absen                           | Kirim hasil absen (ID pegawai, jenis, metode, skor kecocokan, foto terkompresi) |
| GET        | /admin/event                           | Daftar event (terfilter sesuai peran)                                           |
| POST       | /admin/event                           | Buat event baru                                                                 |
| POST       | /admin/event/{id}/tutup                | Tutup event                                                                     |
| GET        | /admin/event/{id}/rekap                | Rekap absen live per event                                                      |
| GET        | /admin/pegawai                         | Daftar pegawai (terfilter sesuai peran)                                         |
| POST       | /admin/pegawai/sinkron                 | Trigger sinkronisasi manual dari WORKA/BKD                                      |
| GET        | /admin/laporan                         | Laporan kehadiran terfilter tanggal & unit kerja                                |

# 5. Desain Modul Verifikasi Wajah (Client-Side)

1.  Saat kiosk aktif untuk suatu event, browser memuat model face-api.js (satu kali per sesi, di-cache oleh browser).

1.  Browser mengambil daftar embedding wajah referensi pegawai pada unit kerja kiosk (bukan foto mentah) dari server melalui endpoint /kiosk/embedding-wajah.

2.  Saat pegawai tap ID, kamera menangkap satu frame; face-api.js mendeteksi wajah dan menghasilkan embedding 128 dimensi.

3.  Embedding hasil capture dibandingkan (cosine/Euclidean distance) hanya dengan embedding milik ID yang di-tap (verifikasi 1:1, bukan pencarian 1:banyak).

4.  Jika skor kecocokan ≥ ambang pada Setting Absen, verifikasi dinyatakan berhasil; foto hasil capture dikompresi (resize + kualitas JPEG sesuai Setting Absen) sebelum dikirim ke server sebagai bukti/arsip.

> *Catatan: Karena hanya embedding (bukan foto) yang dikirim ke kiosk untuk pencocokan, risiko kebocoran foto referensi pegawai melalui jaringan lokal berkurang.*

# 6. Desain Keamanan

- Autentikasi admin menggunakan mekanisme sesi/Sanctum bawaan Laravel dengan hashing password standar.

- Autentikasi kiosk menggunakan device_token unik per perangkat, dapat dicabut sewaktu-waktu oleh Superadmin melalui menu Kelola User/Role.

- Backend memvalidasi ulang status event (aktif/ditutup) dan keanggotaan unit kerja pada setiap permintaan /kiosk/absen, sehingga manipulasi di sisi klien tidak otomatis lolos ke database.

- Akses ke berkas foto (absen maupun referensi) diserving melalui route terautentikasi, bukan folder publik langsung.

- Endpoint sinkronisasi ke WORKA/BKD menggunakan kredensial/API key tersimpan di environment file (.env), tidak di-commit ke repository.

# 7. Pertimbangan Skalabilitas & Deployment

- Karena pemrosesan wajah berada di klien, penambahan jumlah kiosk tidak menambah beban komputasi berat di server — server hanya menangani penyimpanan data dan foto terkompresi.

- Foto absen disimpan dengan penamaan berbasis tanggal/event untuk memudahkan housekeeping (arsip/purging berkala bila diperlukan).

- Deployment mengikuti pola existing WORKA di VPS Jagoan Hosting; disarankan direktori storage foto dipisah dari direktori aplikasi agar mudah di-backup terpisah.

- CI sederhana (GitHub Actions) untuk menjalankan test suite sebelum deploy, selaras dengan praktik pada proyek WORKA/SIMPEG.
