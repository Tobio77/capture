**DINAS TENAGA KERJA DAN TRANSMIGRASI**

**PROVINSI JAWA TIMUR**

**PRD-ABSEN-2026**

**Product Requirement Document**

Aplikasi Absensi Kegiatan Berbasis Event — SI-ABSEN

Nama Sistem: SI-ABSEN (Sistem Informasi Absensi Kegiatan)

Versi Dokumen: 1.0

Tanggal: 30 Agustus 2026

*Status: Draf untuk implementasi*

# Riwayat Dokumen

| **Versi** | **Tanggal**     | **Perubahan**                                               |
|-----------|-----------------|-------------------------------------------------------------|
| 1.0       | 30 Agustus 2026 | Draf awal berdasarkan diskusi kebutuhan dan prototipe UI/UX |

# Daftar Isi

# 1. Latar Belakang

UPT BLK Surabaya dan unit kerja lain di lingkungan Dinas Tenaga Kerja dan Transmigrasi (Disnakertrans) Provinsi Jawa Timur secara rutin menyelenggarakan kegiatan yang membutuhkan pencatatan kehadiran, seperti apel pagi, senam pagi, upacara, dan kegiatan insidental lainnya. Pencatatan kehadiran yang berjalan saat ini masih bervariasi antar unit dan belum terintegrasi dengan data kepegawaian terpusat (WORKA/SIMPEG), sehingga validasi identitas pegawai saat absen masih rawan diwakilkan (titip absen) dan rekapitulasi laporan memerlukan waktu tambahan.

Sebagai referensi, beberapa OPD telah mengoperasikan sistem presensi berbasis event dengan verifikasi foto (contoh: SI-SDM Dinas Kelautan dan Perikanan), yang menunjukkan bahwa pendekatan tap ID/RFID dikombinasikan dengan capture foto adalah pola yang sudah terbukti berjalan di lingkungan pemerintah daerah Jawa Timur. SI-ABSEN mengadopsi pola serupa dengan penyesuaian pada kebutuhan spesifik Disnakertrans, yaitu dukungan multi-unit kerja, event lintas unit untuk kegiatan besar, dan verifikasi wajah otomatis (bukan verifikasi manual oleh petugas).

# 2. Tujuan

- Menyediakan satu aplikasi absensi berbasis event yang dapat digunakan oleh seluruh unit kerja di lingkungan Disnakertrans Prov. Jatim, termasuk UPT BLK Surabaya.

- Memastikan validitas kehadiran melalui kombinasi tap kartu RFID/input manual ID dengan verifikasi wajah otomatis, sehingga meminimalkan praktik titip absen.

- Memberikan admin unit kerja kendali penuh atas jadwal dan cakupan event absen miliknya, sementara Admin Dinas dapat membuat event lintas unit untuk kegiatan berskala besar.

- Menyediakan data dan laporan kehadiran yang siap cetak untuk kebutuhan administrasi kepegawaian.

- Menjaga data pegawai tetap konsisten dengan sumber data kepegawaian resmi (WORKA/API BKD) tanpa entri ulang manual.

# 3. Ruang Lingkup

## 3.1 Termasuk dalam Ruang Lingkup

- Manajemen event absen (buat, buka, tutup) dengan cakupan per unit kerja atau lintas unit.

- Aplikasi kiosk untuk titik absen: aktivasi perangkat, tap RFID/input manual ID, capture dan verifikasi wajah, pencatatan jam datang dan jam pulang.

- Manajemen data pegawai tersinkronisasi dari API master BKD/WORKA (baca-saja/read-only di sisi SI-ABSEN).

- Manajemen akun admin (Superadmin, Admin Dinas, Admin UPT) dan akun perangkat kiosk.

- Dashboard statistik kehadiran dan laporan tercetak/ekspor.

- Pengaturan sistem: metode absen aktif, toleransi keterlambatan, ambang kecocokan wajah, kompresi ukuran foto.

## 3.2 Tidak Termasuk dalam Ruang Lingkup (Fase 1)

- Pengelolaan data induk pegawai (perekrutan, mutasi, kenaikan pangkat) — tetap menjadi tanggung jawab WORKA/BKD.

- Perhitungan tunjangan kinerja atau payroll berbasis kehadiran.

- Absensi harian rutin masuk-pulang kerja di luar event (dapat menjadi pengembangan lanjutan bila dibutuhkan).

- Aplikasi mobile native untuk pegawai; SI-ABSEN Fase 1 berjalan pada perangkat kiosk (desktop/laptop dengan webcam).

# 4. Definisi dan Istilah

| **Istilah**          | **Definisi**                                                                                                                                                      |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Event Absen          | Sesi absensi yang dibuat admin untuk satu kegiatan (contoh: Apel Pagi, Senam Pagi), memiliki tanggal, jam mulai, toleransi keterlambatan, dan cakupan unit kerja. |
| Kiosk                | Akun perangkat (komputer/laptop) yang login pada titik absen dan digunakan pegawai untuk tap/absen. Kiosk bukan akun milik pegawai.                               |
| Tap                  | Aksi pegawai men-tap kartu RFID atau mengetik ID/NIP secara manual pada kiosk untuk memulai proses absen.                                                         |
| Verifikasi Wajah     | Proses otomatis mencocokkan tangkapan wajah dari webcam kiosk dengan foto referensi pegawai di database, dilakukan di sisi klien (browser kiosk).                 |
| Entry Dibuka/Ditutup | Status suatu event: dibuka berarti pegawai masih dapat melakukan tap/absen; ditutup berarti event telah diakhiri admin dan tap baru tidak lagi dicatat.           |
| WORKA/API BKD        | Sistem informasi kepegawaian existing (SIMPEG/WORKA) yang menjadi sumber data master pegawai bagi SI-ABSEN.                                                       |

# 5. Pengguna Sistem dan Peran

| **Peran**         | **Cakupan Akses**                       | **Deskripsi Singkat**                                                                                                        |
|-------------------|-----------------------------------------|------------------------------------------------------------------------------------------------------------------------------|
| Superadmin        | Semua unit kerja                        | Akses penuh ke seluruh menu, termasuk konfigurasi sistem dan pengelolaan akun admin lain.                                    |
| Admin Dinas       | Semua unit kerja                        | Dapat membuat event lintas unit untuk kegiatan besar (misal upacara HUT RI), mengelola data pegawai dan laporan lintas unit. |
| Admin UPT         | Unit kerja sendiri                      | Mengelola event, pegawai, dan laporan hanya untuk unit kerjanya sendiri (contoh: UPT BLK Surabaya).                          |
| Kiosk (Perangkat) | Titik absen tempat perangkat diaktifkan | Bukan akun pegawai — mewakili komputer/laptop yang dipakai untuk proses tap dan verifikasi wajah di lokasi kegiatan.         |

> *Catatan: Pegawai tidak memiliki akun aplikasi tersendiri; pegawai hanya menjadi data master yang diabsen melalui kiosk.*

# 6. Proses Bisnis Utama

Alur inti SI-ABSEN mengikuti lima langkah berikut, sebagaimana telah disepakati pada tahap eksplorasi kebutuhan:

1.  Admin (Dinas atau UPT sesuai kewenangannya) membuat event absen, misalnya “Senam Pagi”, dan menentukan cakupan unit kerja yang berpartisipasi.

1.  Kiosk yang login untuk event tersebut akan tercatat identitas perangkat dan alamat IP-nya; kiosk tersebut menjadi titik absen yang sah untuk event yang aktif di unit kerjanya.

2.  Pegawai men-tap kartu ID atau mengetik ID secara manual pada kiosk; sistem otomatis mengaktifkan webcam untuk menangkap wajah dan mencocokkannya dengan foto referensi di database. Data absen (waktu, metode, hasil verifikasi) beserta foto tersimpan di database, dengan ukuran foto dibatasi melalui kompresi otomatis.

3.  Apabila pegawai melakukan tap melebihi batas waktu yang ditentukan pada event, kehadiran tercatat berstatus terlambat.

4.  Setelah kegiatan selesai, admin menutup (close) event sehingga pegawai tidak dapat lagi melakukan tap untuk event tersebut.

Admin Dinas dan Admin UPT dapat membuat event masing-masing sesuai kewenangannya; khusus untuk kegiatan besar yang melibatkan seluruh unit kerja, Admin Dinas dapat membuat satu event dengan cakupan “semua unit”.

# 7. Kebutuhan Fitur per Modul

## 7.1 Dashboard

- Sebagai Admin, saya ingin melihat ringkasan statistik kehadiran hari ini (total pegawai, kiosk aktif, event berlangsung, persentase kehadiran) agar dapat memantau kondisi absensi secara sekilas.

- Sebagai Admin, saya ingin melihat tren kehadiran 7 hari terakhir dan aktivitas absen terbaru secara real-time agar dapat mendeteksi anomali lebih cepat.

## 7.2 Kelola Absen

- Sebagai Admin, saya ingin membuat event absen baru dengan menentukan nama, tanggal, jam mulai, toleransi keterlambatan, dan cakupan unit kerja.

- Sebagai Admin, saya ingin menutup event yang sedang berlangsung agar pegawai tidak dapat absen lagi setelah kegiatan selesai.

- Sebagai Admin, saya ingin melihat rekap absen per event secara langsung (live) untuk memantau siapa saja yang sudah tercatat hadir.

- Sebagai Admin, saya ingin mengatur metode absen yang diaktifkan (manual/RFID/verifikasi wajah), ambang kecocokan wajah, dan tingkat kompresi foto melalui Setting Absen.

- Sebagai Admin, saya ingin mengelola daftar unit kerja yang berpartisipasi dalam absensi melalui Setting Unit Kerja.

## 7.3 Kelola Pegawai

- Sebagai Admin, saya ingin melihat data pegawai yang tersinkron dari WORKA/API BKD tanpa perlu entri ulang, termasuk status pendaftaran foto referensi wajah.

- Sebagai Admin, saya ingin menjalankan sinkronisasi data pegawai secara manual saat dibutuhkan.

## 7.4 Kelola User/Role

- Sebagai Superadmin, saya ingin mengelola akun admin (Admin Dinas, Admin UPT) beserta perannya, termasuk mengaktifkan/menonaktifkan akun dan mereset kata sandi.

- Sebagai Superadmin/Admin Dinas, saya ingin mengelola akun kiosk (nama perangkat, unit kerja, status online/offline, IP terakhir).

## 7.5 Laporan

- Sebagai Admin, saya ingin mencetak/mengekspor rekap kehadiran berdasarkan rentang tanggal dan unit kerja untuk kebutuhan administrasi kepegawaian.

# 8. Kebutuhan Non-Fungsional (Ringkasan)

Rincian teknis kebutuhan non-fungsional (performa, keamanan, kompatibilitas perangkat, dsb.) dijabarkan lebih lanjut pada dokumen Software Requirement Specification (SRS-ABSEN-2026). Secara garis besar, sistem harus:

- Responsif digunakan pada perangkat kiosk dengan spesifikasi standar (browser modern, webcam eksternal/internal).

- Membatasi ukuran foto absen agar tidak membebani penyimpanan server (kompresi otomatis sebelum simpan).

- Menjaga proses verifikasi wajah tetap berjalan meski beban kiosk bertambah, dengan menempatkan komputasi pengenalan wajah di sisi klien (browser).

# 9. Asumsi dan Ketergantungan

- API/data pegawai dari WORKA atau BKD tersedia dan dapat diakses oleh SI-ABSEN untuk sinkronisasi data master.

- Setiap kiosk memiliki webcam yang berfungsi baik dan koneksi jaringan lokal yang stabil ke server SI-ABSEN.

- Pegawai telah memiliki foto referensi wajah yang terdaftar di sistem sebelum dapat diverifikasi secara otomatis; pegawai yang belum terdaftar memerlukan proses pendaftaran foto awal (di luar cakupan dokumen ini, akan dirinci pada SRS).

- RFID reader yang digunakan bertipe USB HID (keyboard-emulation), sehingga tidak memerlukan driver tambahan di sisi aplikasi.

# 10. Batasan

- Verifikasi wajah bersifat 1:1 (membandingkan satu wajah dengan satu foto referensi berdasarkan ID yang di-tap), bukan pencarian identitas dari basis data (1:banyak).

- Sistem berjalan pada infrastruktur existing (VPS Jagoan Hosting) sehingga kapasitas pemrosesan foto/wajah di server perlu dijaga tetap ringan — karena itu pengenalan wajah dilakukan di sisi klien.

# 11. Risiko dan Mitigasi

| **Risiko**                                                                       | **Dampak**                                         | **Mitigasi**                                                                                            |
|----------------------------------------------------------------------------------|----------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| Kondisi pencahayaan lokasi kiosk bervariasi memengaruhi akurasi verifikasi wajah | Pegawai gagal absen meski hadir                    | Sediakan opsi fallback verifikasi manual oleh admin di lokasi; ambang kecocokan wajah dapat disesuaikan |
| Foto referensi wajah belum lengkap untuk seluruh pegawai saat peluncuran         | Sebagian pegawai tidak dapat diverifikasi otomatis | Jadwalkan sesi pendaftaran foto referensi sebelum go-live per unit kerja                                |
| Kiosk kehilangan koneksi jaringan saat event berlangsung                         | Data absen tidak tersimpan                         | Terapkan penyimpanan sementara di sisi klien (antrian lokal) yang disinkronkan ulang saat koneksi pulih |
| Data pegawai di WORKA/BKD tidak sinkron tepat waktu                              | Data unit kerja/nama pegawai usang di SI-ABSEN     | Sinkronisasi terjadwal harian ditambah tombol sinkronisasi manual oleh admin                            |

# 12. Metrik Keberhasilan

- Waktu proses tap hingga tercatat berhasil rata-rata di bawah 3 detik.

- Tingkat keberhasilan verifikasi wajah pada percobaan pertama minimal 90% pada kondisi pencahayaan standar ruangan.

- Seluruh event kegiatan besar (lintas unit) dapat direkap dalam satu laporan tanpa proses gabung manual.

# 13. Dokumen Terkait

- SRS-ABSEN-2026 — Software Requirement Specification

- SDD-ABSEN-2026 — System Design Document

- UIUX-ABSEN-2026 — UI/UX Flow

- TASK-ABSEN-2026 — Task Breakdown / Rencana Pengerjaan
