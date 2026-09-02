**DINAS TENAGA KERJA DAN TRANSMIGRASI**

**PROVINSI JAWA TIMUR**

**UIUX-ABSEN-2026**

**UI/UX Flow**

Aplikasi Absensi Kegiatan Berbasis Event — SI-ABSEN

Nama Sistem: SI-ABSEN (Sistem Informasi Absensi Kegiatan)

Versi Dokumen: 1.0

Tanggal: 30 Agustus 2026

*Status: Draf untuk implementasi*

# Riwayat Dokumen

| **Versi** | **Tanggal**     | **Perubahan**                                             |
|-----------|-----------------|-----------------------------------------------------------|
| 1.0       | 30 Agustus 2026 | Draf awal, diselaraskan dengan prototipe clickable mockup |

# Daftar Isi

# 1. Referensi Prototipe

Dokumen ini menjelaskan alur dan struktur layar yang telah divalidasi melalui prototipe UI/UX clickable (berkas AbsensiApp.jsx) yang mencakup keempat sudut pandang: Superadmin, Admin Dinas, Admin UPT, dan layar Kiosk. Deskripsi pada dokumen ini menjadi acuan implementasi tampilan sesungguhnya, dengan detail interaksi dapat disesuaikan pada tahap pengembangan.

# 2. Peta Navigasi (Sitemap)

## 2.1 Panel Admin (Superadmin / Admin Dinas / Admin UPT)

- Dashboard

- Kelola Absen

  - Daftar Event

  - Absen Umum

  - Rekap Absen

  - Setting Absen

  - Setting Unit Kerja

- Kelola Pegawai

- Kelola User / Role (tidak tampil untuk Admin UPT)

- Laporan

## 2.2 Layar Kiosk

- Aktivasi Perangkat (layar awal, sebelum kiosk digunakan)

- Layar Utama Kiosk — panel Capture Foto & Entry Absen (kiri) dan Daftar e-Presensi (kanan), tampil bersamaan sepanjang sesi kiosk aktif

# 3. Struktur Layar — Panel Admin

## 3.1 Kerangka Umum

- Sidebar kiri gelap (navy) berisi menu navigasi sesuai peran pengguna, dengan indikator peran dan cakupan unit kerja pada bagian bawah sidebar.

- Area konten utama menggunakan latar terang dengan kartu (card) putih membulat untuk setiap blok informasi (statistik, tabel, formulir).

- Setiap halaman diawali judul halaman dan deskripsi singkat, dengan tombol aksi utama (jika ada) ditempatkan di kanan atas.

## 3.2 Dashboard

| **Elemen**              | **Deskripsi**                                                                                                              |
|-------------------------|----------------------------------------------------------------------------------------------------------------------------|
| Kartu Statistik         | Empat kartu: Total Pegawai, Kiosk Aktif, Event Berlangsung, Kehadiran Hari Ini — menyesuaikan cakupan unit kerja pengguna. |
| Grafik Tren Kehadiran   | Grafik area 7 hari terakhir menunjukkan jumlah hadir per hari.                                                             |
| Aktivitas Absen Terbaru | Daftar ringkas nama pegawai, unit, waktu tap, dan status tepat/terlambat, diperbarui berkala.                              |

## 3.3 Kelola Absen – Daftar Event

| **Elemen**               | **Deskripsi**                                                                                                                                                  |
|--------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Tabel Event              | Kolom: Nama Event, Cakupan (Unit/Semua Unit), Tanggal & Jam, Jumlah Kiosk, Jumlah Masuk, Status (Aktif/Ditutup), Aksi.                                         |
| Tombol “Buat Event Baru” | Membuka formulir modal: nama, tanggal, jam mulai, cakupan unit kerja (opsi “semua unit” hanya untuk Superadmin/Admin Dinas), toleransi keterlambatan, catatan. |
| Aksi “Detail”            | Membuka modal berisi daftar kiosk terhubung (nama, IP) dan jumlah absen masuk untuk event tersebut.                                                            |
| Aksi “Tutup”             | Tersedia hanya pada event berstatus aktif; mengubah status menjadi ditutup setelah konfirmasi.                                                                 |

## 3.4 Kelola Absen – Rekap Absen

| **Elemen**       | **Deskripsi**                                                                                                                                          |
|------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| Filter Event     | Dropdown memilih event untuk ditampilkan rekapnya, menunjukkan status entry dan ringkasan jumlah hadir/terlambat.                                      |
| Tabel e-Presensi | Kolom: No, NIP, Nama, Unit Kerja, Jam Masuk, Jam Pulang, Metode, Status, Foto — mencerminkan data yang sama dengan Daftar e-Presensi pada layar Kiosk. |
| Tombol Cetak     | Mencetak rekap event yang sedang ditampilkan.                                                                                                          |

## 3.5 Kelola Absen – Setting Absen

| **Elemen**                      | **Deskripsi**                                                                          |
|---------------------------------|----------------------------------------------------------------------------------------|
| Metode Absensi Aktif            | Tiga saklar (toggle): Input Manual, Tap RFID, Verifikasi Wajah.                        |
| Toleransi Keterlambatan Default | Input angka (menit), berlaku sebagai nilai awal saat pembuatan event baru.             |
| Ambang Kecocokan Wajah          | Slider persentase (70–99%).                                                            |
| Kompresi Foto Absen             | Pilihan preset: Ringan / Sedang / Tinggi, masing-masing dengan estimasi ukuran berkas. |

## 3.6 Kelola Absen – Setting Unit Kerja

Tabel unit kerja (kode, nama, jumlah pegawai, jumlah kiosk terdaftar) dengan aksi ubah; tombol tambah unit hanya tampil untuk Superadmin/Admin Dinas.

## 3.7 Kelola Pegawai

| **Elemen**                  | **Deskripsi**                                                                              |
|-----------------------------|--------------------------------------------------------------------------------------------|
| Kolom Pencarian             | Mencari berdasarkan nama atau NIP.                                                         |
| Tombol “Sinkron dari WORKA” | Memicu sinkronisasi manual data pegawai.                                                   |
| Tabel Pegawai               | Kolom: Nama, NIP, Unit Kerja, Jabatan, Status Wajah Referensi (Terdaftar/Belum Terdaftar). |

## 3.8 Kelola User / Role

Dua tab: “Akun Admin” (nama, role, cakupan, status, aksi reset password/ubah) dan “Akun Kiosk/Perangkat” (nama perangkat, unit kerja, IP terakhir, login terakhir, status online/offline).

## 3.9 Laporan

Filter rentang tanggal dan unit kerja, diikuti tabel rekap per pegawai (hadir, terlambat, tanpa keterangan, persentase) dan tombol cetak/ekspor.

# 4. Struktur Layar – Kiosk

## 4.1 Aktivasi Perangkat

Layar pertama yang tampil saat aplikasi kiosk dibuka. Admin lokasi memilih unit kerja dan mengisi nama titik absen, lalu menekan “Aktifkan Perangkat”. Alamat IP perangkat tercatat otomatis pada saat ini.

## 4.2 Layar Utama Kiosk

Setelah aktivasi, layar utama menampilkan header status event (nama event aktif, badge Entry Dibuka/Ditutup, info titik absen) dan dua panel yang selalu terlihat sepanjang sesi:

### 4.2.1 Panel Capture Foto & Entry Absen (kiri)

- Kotak pratinjau kamera dengan indikator “LIVE”; menampilkan animasi pemindaian (garis scan + sudut bidik) saat proses verifikasi berlangsung, dan highlight hijau/merah sesuai hasil.

- Pilihan Jenis Absen: Datang / Pulang (radio button).

- Kolom “Scan / Ketik ID Card” yang selalu berada dalam kondisi fokus otomatis, menerima input dari RFID reader (keyboard-emulation) maupun ketikan manual.

- Empat field hasil (NIP, Nama, Unit Kerja, Jam Absen) terisi otomatis setelah verifikasi berhasil.

- Baris status singkat yang berubah sesuai tahap: menunggu event, menunggu tap, memindai wajah, berhasil, atau gagal (wajah tidak cocok).

### 4.2.2 Panel Daftar e-Presensi (kanan)

- Tabel yang bertambah/berubah baris secara langsung: No, NIP, Nama, Jam Masuk, Jam Pulang, Foto.

- Tap dengan jenis “Pulang” pada pegawai yang sudah memiliki baris Jam Masuk akan memperbarui baris yang sama (kolom Jam Pulang terisi), bukan menambah baris baru.

# 5. Alur Interaksi Utama

## 5.1 Admin Membuat dan Menutup Event

1.  Admin membuka Kelola Absen → Daftar Event → tekan “Buat Event Baru”.

1.  Admin mengisi nama, tanggal, jam mulai, cakupan unit kerja, dan toleransi keterlambatan, lalu menyimpan.

2.  Event berstatus “aktif” muncul di Daftar Event dan otomatis dapat diakses oleh kiosk pada unit kerja tercakup.

3.  Setelah kegiatan selesai, admin menekan “Tutup” pada baris event terkait; status berubah menjadi “ditutup” dan kiosk tidak lagi menerima tap baru untuk event tersebut.

## 5.2 Pegawai Melakukan Absen di Kiosk

4.  Pegawai mendekati kiosk yang telah aktif untuk event berjalan pada unit kerjanya.

5.  Pegawai memilih jenis absen (Datang/Pulang), lalu men-tap kartu RFID atau mengetik ID secara manual.

6.  Kamera otomatis menangkap wajah dan sistem memverifikasi kecocokan dengan foto referensi.

7.  Jika cocok, data NIP/Nama/Unit/Jam tampil pada panel kiri, baris pegawai bertambah/terbarui pada Daftar e-Presensi di panel kanan, dan status “berhasil” ditampilkan sesaat sebelum kembali ke kondisi menunggu tap berikutnya.

8.  Jika tidak cocok, status “gagal” ditampilkan dan pegawai dipersilakan mengulang tap.

# 6. Prinsip Desain

- Palet warna: navy (elemen struktural/sidebar), teal (aksi utama), emerald (status berhasil/tepat waktu), amber (status terlambat/peringatan) — tanpa penggunaan ungu/indigo.

- Tipografi: Lexend untuk judul dan angka (keterbacaan tinggi, sesuai konteks lembaga pelatihan kerja), Inter untuk teks isi/antarmuka.

- Kiosk dirancang untuk dioperasikan cepat tanpa mouse: fokus otomatis pada kolom input dan alur berbasis tap/keyboard.

- Kepadatan informasi pada tabel (rekap, e-presensi) mengikuti pola sistem sejenis yang sudah dikenal pengguna di lingkungan Disnakertrans, agar transisi penggunaan lebih mudah.
