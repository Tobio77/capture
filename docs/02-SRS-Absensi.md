**DINAS TENAGA KERJA DAN TRANSMIGRASI**

**PROVINSI JAWA TIMUR**

**SRS-ABSEN-2026**

**Software Requirement Specification**

Aplikasi Absensi Kegiatan Berbasis Event — SI-ABSEN

Nama Sistem: SI-ABSEN (Sistem Informasi Absensi Kegiatan)

Versi Dokumen: 1.0

Tanggal: 30 Agustus 2026

*Status: Draf untuk implementasi*

# Riwayat Dokumen

| **Versi** | **Tanggal**     | **Perubahan**                             |
|-----------|-----------------|-------------------------------------------|
| 1.0       | 30 Agustus 2026 | Draf awal, diturunkan dari PRD-ABSEN-2026 |

# Daftar Isi

# 1. Pendahuluan

## 1.1 Tujuan Dokumen

Dokumen ini merinci kebutuhan fungsional dan non-fungsional SI-ABSEN secara teknis, sebagai turunan dari PRD-ABSEN-2026, untuk menjadi acuan tim pengembang (termasuk saat pengerjaan dengan Claude Code) dalam membangun sistem.

## 1.2 Referensi

- PRD-ABSEN-2026 — Product Requirement Document

- SDD-ABSEN-2026 — System Design Document (rincian arsitektur & skema database)

## 1.3 Konvensi Penomoran

Setiap kebutuhan fungsional diberi kode FR-\[modul\]-\[urut\], kebutuhan non-fungsional diberi kode NFR-\[urut\].

# 2. Deskripsi Umum Sistem

SI-ABSEN adalah aplikasi web dengan dua front-end: (1) Panel Admin untuk Superadmin/Admin Dinas/Admin UPT, dan (2) Layar Kiosk yang dijalankan pada komputer/laptop di titik absen. Backend tunggal (Laravel) melayani kedua front-end tersebut serta melakukan sinkronisasi berkala dengan API WORKA/BKD untuk data pegawai.

# 3. Kebutuhan Fungsional

## 3.1 Autentikasi & Otorisasi

| **Kode**   | **Deskripsi**                                                                                                                                                                                                   | **Prioritas** |
|------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-AUTH-01 | Sistem menyediakan login terpisah untuk akun Admin (email/username + password) dan akun Kiosk (aktivasi perangkat tanpa password personal, menggunakan token perangkat).                                        | Tinggi        |
| FR-AUTH-02 | Sistem membatasi akses menu berdasarkan peran: Superadmin (semua menu), Admin Dinas (semua menu kecuali beberapa pengaturan sistem inti), Admin UPT (menu terbatas pada unit kerjanya, tanpa Kelola User/Role). | Tinggi        |
| FR-AUTH-03 | Sistem mencatat log aktivitas login/logout dan perubahan data penting (audit trail minimal: siapa, kapan, aksi apa).                                                                                            | Sedang        |
| FR-AUTH-04 | Superadmin dapat mereset kata sandi akun admin lain dan menonaktifkan akun kiosk yang hilang/rusak.                                                                                                             | Sedang        |

## 3.2 Dashboard

| **Kode**   | **Deskripsi**                                                                                                                                                                         | **Prioritas** |
|------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-DASH-01 | Sistem menampilkan kartu statistik: total pegawai, jumlah kiosk aktif hari ini, jumlah event berlangsung, dan persentase kehadiran hari ini, terfilter sesuai cakupan peran pengguna. | Tinggi        |
| FR-DASH-02 | Sistem menampilkan grafik tren kehadiran 7 hari terakhir.                                                                                                                             | Sedang        |
| FR-DASH-03 | Sistem menampilkan daftar aktivitas absen terbaru (nama, unit, waktu, metode, status tepat/terlambat) yang diperbarui secara berkala.                                                 | Sedang        |

## 3.3 Kelola Absen — Manajemen Event

| **Kode**  | **Deskripsi**                                                                                                                                                                                                                                            | **Prioritas** |
|-----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-EVT-01 | Admin dapat membuat event baru dengan atribut: nama, tanggal, jam mulai, toleransi keterlambatan (menit), cakupan unit kerja (satu/lebih unit, atau “semua unit” — opsi “semua unit” hanya tersedia untuk Superadmin/Admin Dinas), dan catatan opsional. | Tinggi        |
| FR-EVT-02 | Admin UPT hanya dapat memilih unit kerjanya sendiri sebagai cakupan event.                                                                                                                                                                               | Tinggi        |
| FR-EVT-03 | Sistem mencatat setiap kiosk yang login/aktif pada suatu event beserta alamat IP dan waktu aktivasi.                                                                                                                                                     | Tinggi        |
| FR-EVT-04 | Admin dapat menutup (close) event yang sedang aktif; setelah ditutup, tap baru pada kiosk untuk event tersebut ditolak sistem. Bila absen umum (FR-SET-05) menyala, tap sesudahnya dilayani sesi absen harian unit tersebut — bukan dicatat pada event yang sudah ditutup. | Tinggi        |
| FR-EVT-05 | Admin dapat melihat detail event: daftar kiosk terhubung, jumlah absen masuk, dan status entry (dibuka/ditutup).                                                                                                                                         | Tinggi        |
| FR-EVT-06 | Sistem hanya mengizinkan satu event kegiatan berstatus “aktif” per cakupan unit kerja pada satu waktu, untuk mencegah ambiguitas saat tap. Sesi absen umum (FR-SET-05) tidak ikut dihitung: ia justru mengalah ketika ada kegiatan yang berjalan.        | Sedang        |

## 3.4 Kelola Absen — Rekap Absen

| **Kode**  | **Deskripsi**                                                                                                                                                                                                   | **Prioritas** |
|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-REK-01 | Admin dapat memilih event tertentu dan melihat daftar e-presensi (No, NIP, Nama, Unit Kerja, Jam Masuk, Jam Pulang, Metode, Status Tepat/Terlambat, Foto) yang diperbarui secara live selama event berlangsung. | Tinggi        |
| FR-REK-02 | Admin UPT hanya melihat rekap absen untuk unit kerjanya sendiri, walaupun event bercakupan semua unit.                                                                                                          | Tinggi        |
| FR-REK-03 | Admin dapat mencetak rekap absen per event.                                                                                                                                                                     | Sedang        |

## 3.5 Kelola Absen — Setting Absen

| **Kode**  | **Deskripsi**                                                                                                                                      | **Prioritas** |
|-----------|----------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-SET-01 **(revisi S28a)** | Admin dapat mengaktifkan/menonaktifkan metode absen: input manual, tap RFID, dan verifikasi wajah, berlaku sebagai pengaturan global sistem. Mematikan verifikasi wajah hanya melewati langkah PENCOCOKAN embedding — kamera tetap menyala dan foto tetap diambil serta disimpan sebagai bukti kehadiran, dan validasinya menjadi murni pencocokan NIP/UID kartu terhadap data pegawai. | Tinggi        |
| FR-SET-02 | Admin dapat menetapkan toleransi keterlambatan default (menit) yang berlaku untuk event baru, dan dapat dioverride per-event saat pembuatan event. | Tinggi        |
| FR-SET-03 | Admin dapat menetapkan ambang kecocokan wajah (persentase) yang digunakan modul verifikasi wajah di sisi klien.                                    | Tinggi        |
| FR-SET-04 | Admin dapat memilih tingkat kompresi foto absen (dimensi maksimum piksel dan kualitas JPEG) untuk membatasi ukuran berkas yang disimpan.           | Tinggi        |
| FR-SET-05 | Admin dapat menyalakan/mematikan absen umum harian beserta jam masuknya. Saat menyala, sistem membuka sesi absen harian per unit kerja ketika tidak ada event kegiatan yang berjalan, sehingga pegawai dapat mencatat kehadiran rutin tanpa admin membuat event lebih dahulu. | Sedang        |
| FR-SET-06 | Admin dapat mematikan kewajiban kode aktivasi perangkat ("Mode Terbuka"); bawaannya menyala. Saat dimatikan, perangkat yang membuka layar absen tanpa kode dibuatkan entri sendiri bertanda sumber `ad_hoc`, memilih unit kerjanya di layar aktivasi, dan alamat IP-nya dicatat sama seperti perangkat terdaftar — tercermin pada halaman Perangkat Absen maupun daftar perangkat terhubung sebuah event. Selama mode ini menyala, panel admin menampilkan peringatan yang selalu terlihat pada setiap halaman. | Sedang        |

## 3.6 Kelola Absen — Setting Unit Kerja

| **Kode**   | **Deskripsi**                                                                                                     | **Prioritas** |
|------------|-------------------------------------------------------------------------------------------------------------------|---------------|
| FR-UNIT-01 | Superadmin/Admin Dinas dapat menambah, mengubah, dan menonaktifkan unit kerja yang berpartisipasi dalam SI-ABSEN. | Sedang        |
| FR-UNIT-02 | Sistem menampilkan jumlah pegawai dan jumlah kiosk terdaftar per unit kerja.                                      | Rendah        |

## 3.7 Kelola Pegawai

| **Kode**  | **Deskripsi**                                                                                                                                                           | **Prioritas** |
|-----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-PEG-01 | Sistem menyinkronkan data pegawai (NIP, nama, unit kerja, jabatan) dari API WORKA/BKD, baik terjadwal harian maupun dipicu manual oleh admin.                           | Tinggi        |
| FR-PEG-02 | Data pegawai bersifat baca-saja di SI-ABSEN; perubahan data induk dilakukan di WORKA, bukan di SI-ABSEN.                                                                | Tinggi        |
| FR-PEG-03 | Sistem menampilkan status pendaftaran foto referensi wajah per pegawai (terdaftar/belum terdaftar).                                                                     | Tinggi        |
| FR-PEG-04 | Admin dapat mencari pegawai berdasarkan nama atau NIP.                                                                                                                  | Sedang        |
| FR-PEG-05 | Sistem menyediakan alur pendaftaran/pembaruan foto referensi wajah pegawai (capture melalui kiosk atau unggah oleh admin), sebagai prasyarat verifikasi wajah otomatis. | Tinggi        |

## 3.8 Kelola User/Role

| **Kode**  | **Deskripsi**                                                                                                                                    | **Prioritas** |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-USR-01 | Superadmin dapat membuat, mengubah, menonaktifkan akun Admin Dinas dan Admin UPT beserta cakupan unit kerjanya.                                  | Tinggi        |
| FR-USR-02 | Superadmin/Admin Dinas dapat mendaftarkan akun kiosk baru (nama titik absen, unit kerja) dan menonaktifkan akun kiosk yang tidak lagi digunakan. | Tinggi        |
| FR-USR-03 | Sistem mencatat IP address dan waktu login terakhir setiap akun kiosk.                                                                           | Sedang        |

## 3.9 Laporan

| **Kode**  | **Deskripsi**                                                                                                   | **Prioritas** |
|-----------|-----------------------------------------------------------------------------------------------------------------|---------------|
| FR-LAP-01 | Admin dapat memfilter laporan kehadiran berdasarkan rentang tanggal dan unit kerja.                             | Tinggi        |
| FR-LAP-02 | Sistem menampilkan rekap per pegawai: jumlah hadir, terlambat, dan tanpa keterangan dalam rentang yang dipilih. | Tinggi        |
| FR-LAP-03 | Admin dapat mencetak (PDF) atau mengekspor (Excel) laporan kehadiran.                                           | Tinggi        |

## 3.10 Kiosk — Aktivasi Perangkat

| **Kode**  | **Deskripsi**                                                                                                                         | **Prioritas** |
|-----------|---------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-KIO-01 | Perangkat kiosk yang belum aktif menampilkan layar aktivasi: pilih unit kerja dan nama titik absen, kemudian mengaktifkan sesi kiosk. | Tinggi        |
| FR-KIO-02 | Saat aktivasi, sistem mencatat alamat IP perangkat dan menandai kiosk berstatus online.                                               | Tinggi        |
| FR-KIO-03 | Kiosk dapat berpindah/ganti perangkat (deaktivasi sesi saat ini dan kembali ke layar aktivasi).                                       | Sedang        |

## 3.11 Kiosk — Proses Tap dan Verifikasi Wajah

| **Kode**  | **Deskripsi**                                                                                                                                                                                                                                                                                                                                                            | **Prioritas** |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|
| FR-TAP-01 | Kiosk menampilkan status event aktif untuk unit kerjanya; jika tidak ada event aktif, input tap dinonaktifkan dan pesan status ditampilkan.                                                                                                                                                                                                                              | Tinggi        |
| FR-TAP-02 | Pegawai memilih jenis absen (Datang/Pulang) sebelum atau saat melakukan tap.                                                                                                                                                                                                                                                                                             | Tinggi        |
| FR-TAP-03 | Pegawai men-tap kartu RFID (terbaca sebagai input keyboard) atau mengetik ID/NIP secara manual pada kolom yang selalu dalam kondisi fokus (auto-focus).                                                                                                                                                                                                                  | Tinggi        |
| FR-TAP-04 | Setelah ID diterima, sistem otomatis mengaktifkan kamera, menangkap gambar wajah, dan melakukan verifikasi kecocokan dengan foto referensi pegawai bersangkutan di sisi klien (browser), menggunakan ambang kecocokan sesuai Setting Absen.                                                                                                                              | Tinggi        |
| FR-TAP-05 **(revisi S28a)** | Sistem mencatat data absen (NIP, nama, unit kerja, waktu, jenis datang/pulang, metode tap, status tepat/terlambat) beserta foto hasil capture yang telah dikompresi, lalu mengisi kolom Jam Masuk atau Jam Pulang pada baris pegawai yang bersangkutan di Daftar e-Presensi. Satu baris per (event, pegawai, jenis): **tap kedua untuk jenis yang sama pada event/hari yang sama DITOLAK**, bukan menimpa yang pertama, dan layar titik absen menampilkan "Sudah absen datang/pulang pukul HH:MM". Absen datang dan pulang berdiri sendiri — yang satu tidak menghalangi yang lain. | Tinggi        |
| FR-TAP-06 | Jika verifikasi wajah gagal, sistem menampilkan status gagal dan tidak mencatat kehadiran; pegawai dapat mengulang tap.                                                                                                                                                                                                                                                  | Tinggi        |
| FR-TAP-07 | Sistem membandingkan waktu tap terhadap jam mulai dan toleransi keterlambatan event untuk menentukan status tepat waktu/terlambat (berlaku untuk jenis Datang).                                                                                                                                                                                                          | Tinggi        |
| FR-TAP-08 | Sistem menampilkan Daftar e-Presensi yang bertambah/berubah secara langsung pada layar kiosk seiring pegawai lain melakukan tap.                                                                                                                                                                                                                                         | Sedang        |

# 4. Kebutuhan Non-Fungsional

| **Kode**                | **Deskripsi**                                                                                                                                                       |
|-------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| NFR-01 (Performa)       | Proses dari tap hingga hasil verifikasi tampil rata-rata di bawah 3 detik pada kondisi jaringan lokal normal.                                                       |
| NFR-02 (Performa)       | Komputasi deteksi & pencocokan wajah dilakukan di sisi klien (browser kiosk) agar beban server tidak meningkat linear terhadap jumlah kiosk aktif.                  |
| NFR-03 (Keamanan)       | Kata sandi akun admin disimpan terenkripsi (hash); token sesi kiosk unik per perangkat dan dapat dicabut oleh Superadmin.                                           |
| NFR-04 (Keamanan)       | Foto absen dan foto referensi wajah hanya dapat diakses oleh peran yang berwenang; akses langsung ke berkas foto tanpa autentikasi tidak diperbolehkan.             |
| NFR-05 (Ketersediaan)   | Kiosk tetap dapat penyimpanan lokal sementara jika koneksi ke server terputus singkat, dan menyinkronkan ulang data absen begitu koneksi pulih.                     |
| NFR-06 (Penyimpanan)    | Ukuran satu foto absen tersimpan tidak melebihi ~90 KB pada pengaturan kompresi tertinggi, mengikuti Setting Absen.                                                 |
| NFR-07 (Kompatibilitas) | Panel admin dan layar kiosk berjalan pada browser modern (Chrome/Edge versi terbaru) tanpa instalasi tambahan, kecuali driver standar webcam/RFID reader bawaan OS. |
| NFR-08 (Usability)      | Layar kiosk dioptimalkan untuk dioperasikan tanpa mouse (fokus otomatis pada kolom input, alur berbasis keyboard/tap).                                              |
| NFR-09 (Auditabilitas)  | Setiap perubahan status event (buka/tutup) dan aktivasi kiosk tercatat dengan pelaku dan waktu kejadian.                                                            |
| NFR-10 (Bahasa)         | Seluruh antarmuka menggunakan Bahasa Indonesia baku sesuai konvensi dokumen pemerintahan.                                                                           |

# 5. Kebutuhan Antarmuka Eksternal

## 5.1 Integrasi API WORKA/BKD

- SI-ABSEN mengonsumsi API data pegawai (read-only) dari WORKA/BKD untuk sinkronisasi NIP, nama, unit kerja, dan jabatan.

- Format pertukaran data, autentikasi API, dan frekuensi sinkronisasi akan dirinci lebih lanjut pada SDD-ABSEN-2026 setelah spesifikasi API WORKA/BKD dikonfirmasi.

## 5.2 Perangkat Keras Pendukung Kiosk

- RFID reader tipe USB HID (keyboard-emulation) — tidak memerlukan driver khusus.

- Webcam internal/eksternal yang didukung getUserMedia pada browser.

# 6. Matriks Peran vs Hak Akses

| **Menu / Fitur**     | **Superadmin**  | **Admin Dinas**                       | **Admin UPT**     | **Kiosk**                   |
|----------------------|-----------------|---------------------------------------|-------------------|-----------------------------|
| Dashboard            | Ya (semua unit) | Ya (semua unit)                       | Ya (unit sendiri) | Tidak                       |
| Kelola Absen ­– Event | Ya (semua unit) | Ya (semua unit, termasuk lintas unit) | Ya (unit sendiri) | Tidak                       |
| Rekap Absen          | Ya              | Ya                                    | Ya (unit sendiri) | Ya (tampilan live saat tap) |
| Setting Absen        | Ya              | Ya                                    | Tidak             | Tidak                       |
| Setting Unit Kerja   | Ya              | Ya                                    | Lihat saja        | Tidak                       |
| Kelola Pegawai       | Ya              | Ya                                    | Ya (unit sendiri) | Tidak                       |
| Kelola User/Role     | Ya              | Ya (kiosk saja)                       | Tidak             | Tidak                       |
| Laporan              | Ya              | Ya                                    | Ya (unit sendiri) | Tidak                       |
| Proses Tap/Absen     | Tidak           | Tidak                                 | Tidak             | Ya                          |

# 7. Lampiran — Status Absen

| **Status**       | **Kriteria**                                                                                  |
|------------------|-----------------------------------------------------------------------------------------------|
| Tepat Waktu      | Waktu tap ≤ jam mulai event + toleransi keterlambatan                                         |
| Terlambat        | Waktu tap \> jam mulai event + toleransi keterlambatan                                        |
| Tanpa Keterangan | Pegawai terdaftar pada unit kerja cakupan event namun tidak tercatat tap hingga event ditutup |


# 8. Catatan Revisi

Revisi berikut mengubah perilaku yang sudah pernah disepakati. Dicatat di sini
agar perubahannya dapat ditelusuri, bukan tampak sebagai perbedaan yang muncul
begitu saja antara dokumen dan aplikasi.

| **Kode**  | **Sesi** | **Perubahan** | **Alasan** |
|-----------|----------|---------------|------------|
| FR-TAP-05 | S28a | Tap kedua untuk jenis yang sama semula MEMPERBARUI baris yang ada; kini DITOLAK. | Jam kehadiran yang sudah tercatat adalah bukti. Bila tap ulang menggesernya, siapa pun dapat memindahkan jam kehadirannya sendiri — termasuk orang yang datang terlambat lalu mengulang tap sesudah rekan mengabsenkannya lebih dahulu. Layar kini menyatakan pukul berapa kehadirannya sudah tercatat. |
| FR-SET-01 | S28a | Mematikan verifikasi wajah semula ikut mematikan kamera dan penyimpanan foto; kini hanya melewati langkah pencocokan. | Foto absen berfungsi sebagai bukti dan arsip, bukan hanya bahan pencocokan. Instansi yang mematikan pencocokan wajah — misalnya karena pencahayaan lokasi buruk — tetap membutuhkan buktinya. |
