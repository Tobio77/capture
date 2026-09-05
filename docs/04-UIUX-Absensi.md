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

## 2.0 Halaman Depan (S30)

Satu pintu masuk di `/`, terbuka tanpa autentikasi apa pun:

- Sambutan singkat

- Dua pilihan besar bersebelahan: **Absen Umum** dan **Absen Event**

- Tombol **Masuk Admin** terpisah, dikecilkan ke sudut kanan atas

Sampai S29 aplikasi tidak punya halaman depan: `/` melempar ke dashboard admin,
sehingga pegawai yang membuka alamatnya mendarat di layar login yang bukan
untuknya, sementara petugas titik absen harus tahu alamat `/kiosk`.

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

## 2.2 Layar Perangkat Absen

- Aktivasi Perangkat (sebelum perangkat dipakai)

- Layar Absen Umum (`/kiosk/umum`) — selalu dapat dibuka

- Layar Absen Event (`/kiosk/event`) — hanya setelah perangkat bergabung lewat kode unit kerja

Keduanya memakai satu layar yang sama: panel Capture Foto & Entry Absen (kiri)
dan Daftar e-Presensi (kanan), tampil bersamaan sepanjang sesi.

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

# 4. Struktur Layar – Perangkat Absen

## 4.0 Halaman Depan (S30)

Bilah atas ringan — bukan sidebar navy seperti Panel Admin, karena layar ini
dilihat pegawai, bukan pengelola — berisi nama aplikasi, pemilih tema, dan
tombol Masuk Admin.

Isinya sebuah sambutan lalu dua kartu besar bersebelahan. Keduanya dibuat besar
dan sejajar karena layar ini dibaca dari jarak berdiri, kerap di aula yang
ramai; Masuk Admin justru dikecilkan ke sudut karena ia jarang dipakai dan bukan
untuk orang yang sedang mengantre.

Apa yang terjadi saat kartunya ditekan bergantung pada keadaan perangkat:

| Keadaan perangkat             | Absen Umum        | Absen Event                    |
|-------------------------------|-------------------|--------------------------------|
| Belum diaktifkan              | ke layar aktivasi | ke layar aktivasi              |
| Sudah aktif, belum ikut event | langsung masuk    | daftar event + kolom kode      |
| Sudah aktif dan ikut event    | langsung masuk    | langsung masuk                 |

Memilih **Absen Event** pada perangkat yang belum bergabung membuka bagian kedua
di halaman yang sama: daftar event yang sedang dibuka (nama, tanggal, jam mulai,
unit penyelenggara) diikuti satu kolom kode unit kerja. Daftar itu hanya dikirim
kepada perangkat yang **sudah diaktifkan** — nama kegiatan beserta unit
penyelenggaranya adalah keterangan internal, dan kodenya sendiri tidak pernah
ikut ditampilkan.

Memilih **Absen Umum** langsung menuju layarnya, mengikuti aturan Mode Terbuka:
perangkat yang belum aktif diarahkan ke layar aktivasi, yang di bawah Mode
Terbuka cukup meminta unit kerjanya saja.

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

- Palet warna: navy (elemen struktural/sidebar), teal (aksi utama), emerald (status berhasil/tepat waktu), amber (status terlambat/peringatan) — tanpa penggunaan ungu/indigo. Nuansanya dilunakkan menjadi pastel: latar halaman memakai tint pucat dari warna-warna itu dengan kartu putih bersih di atasnya, sementara kejenuhan penuh disimpan untuk elemen interaktif (tombol, lencana status, tautan) supaya mata langsung menemukannya.

- **Yang membuat kesan pastel bukan warnanya sendirian**, melainkan tiga hal bersamaan: tint "lembut" yang dinaikkan terangnya dan diturunkan kejenuhannya, bayangan yang lebih lebar dan lebih tipis, serta sudut membulat yang dinaikkan satu tingkat lewat `--radius-*` pada `@theme`. Menaikkan radius di satu tempat itu membuat seluruh `rounded-md/lg/xl` yang sudah tersebar di kiosk maupun Panel Admin ikut melunak sekaligus — sudut kecil terbaca sebagai "formulir kantor", sudut besar sebagai "kartu".

- **Layar masuk dan aktivasi mengikuti tema pastel**, bukan lagi bidang navy rata dengan satu kotak putih di tengahnya. Keduanya kesan pertama atas aplikasi — bagi pengelola dan bagi petugas titik absen — dan bidang navy polos tidak menjanjikan apa pun tentang halaman di baliknya. Navy tetap ada sebagai aksen pada lencana merek dan sidebar, bukan sebagai seluruh layar.

- **Token warna, bukan warna hardcode.** Seluruh warna aplikasi berasal dari token peran pada `resources/css/tema.css` — `kertas`, `permukaan`, `garis`, `utama`, `sekunder`, `redup`, `aksen`, `berhasil`, `peringatan`, `sidebar`. Komponen tidak pernah menyebut `bg-white` atau `text-slate-600` langsung. Itulah yang membuat mode gelap cukup mendefinisikan ulang tokennya, bukan menambah varian `dark:` pada setiap elemen di setiap halaman.

- **Tiga mode tampilan.** Terang, Gelap, dan Sistem (mengikuti `prefers-color-scheme`). Pilihan disimpan di `localStorage` perangkat — bukan di akun, karena satu admin dapat memakai laptop terang di kantor dan tablet gelap di lapangan. Mode "Sistem" tidak dibekukan saat dipilih: ia tetap mendengarkan perubahan tema OS selagi aplikasi terbuka. Temanya dipasang oleh skrip kecil di `app.blade.php` sebelum CSS dimuat, supaya tidak ada kilatan putih pada setiap perpindahan halaman.

- **Bawaannya Terang, bukan Sistem** (ditegakkan S30). Sampai S29 aplikasi mengikuti OS, sehingga perangkat yang OS-nya gelap membuka layar titik absen dalam mode gelap — persis keadaan yang paling tidak dikehendaki (lihat butir "Layar absen bertema terang"). Bawaan ini harus sama di dua tempat: `TEMA_BAWAAN` pada `useTema.js` dan skrip anti-kedip pada `app.blade.php`. Bila keduanya berbeda, halaman sempat tergambar dengan tema yang salah sebelum Vue hidup.

- **Kosakata visual bersama** (S30). Kartu, kolom isian, tombol, dan ubin ikon tidak lagi dirangkai ulang dari kelas Tailwind di setiap halaman, melainkan berasal dari kelas bersama pada `tema.css`: `.panel`, `.kolom-isian`, `.tombol` + `.tombol-utama`/`.tombol-garis`, `.ubin-ikon` (varian `info`, `berhasil`, `peringatan`), dan `.ubin-merek`. Perbedaan kecil yang menumpuk — radius tidak sama, bayangan tidak sama, tinggi kolom tidak sama — itulah yang sebelumnya membuat antarmuka terasa datar meski setiap halamannya rapi sendiri-sendiri.

  `.panel` bukan sekadar kotak: ia membawa garis rambut, bayangan lembut, dan satu sorotan setipis piksel di tepi atas (token `--tema-sorot`). Sorotan itu yang membuat kartu terbaca sebagai bidang yang terangkat alih-alih kotak yang digambar di atas latar.

- **Kelas bersama ditulis di `@layer components`.** CSS di luar lapisan mana pun mengalahkan seluruh utility Tailwind, berapa pun spesifisitasnya — sehingga `class="panel rounded-full"` akan tetap bersudut kartu dan `class="kolom-isian py-3"` akan mengabaikan tingginya. Di dalam `components`, utility yang menyusul tetap menang, sehingga kelas ini menjadi titik awal yang dapat disesuaikan per tempat, bukan aturan yang memaksa. Pengecualiannya cincin fokus papan ketik, yang sengaja tetap di luar lapisan supaya tidak dapat dimatikan tanpa sengaja.

- **Gerak menghormati `prefers-reduced-motion`.** Aturannya ditulis sekali secara global di `tema.css` — animasi dan transisi dipangkas menjadi 0,01 ms bagi pengguna yang memintanya di OS — sehingga tidak bergantung pada setiap komponen mengingatnya sendiri.

- Tipografi: Lexend untuk judul dan angka (keterbacaan tinggi, sesuai konteks lembaga pelatihan kerja), Inter untuk teks isi/antarmuka.

- **Layar absen bertema terang.** Titik absen berdiri di aula dan lorong yang terang benderang saat apel atau senam pagi; layar gelap di sana memantul dan sulit dibaca dari jarak berdiri. Berlaku sama untuk layar perangkat absen maupun layar Absen Umum di peramban admin — keduanya memakai satu komponen, sehingga identitasnya tidak terbelah.

- **Pratinjau kamera kecil dan tidak dicerminkan** (revisi S30). Berasio 4:3 dengan lebar maksimum 17rem, dipusatkan di kolom kiri — bukan lagi selebar kolom. Ini **membalik keputusan S13c** yang justru membesarkannya; alasan lamanya (orang perlu melihat wajahnya untuk memposisikan diri) ternyata kalah oleh keluhan yang lebih sering di lapangan: pratinjau selebar kolom mendorong kolom tap dan tombol jenis absen ke bawah lipatan pada layar titik absen yang umumnya kecil, sehingga petugas harus menggulir di tengah antrean. Rasio 4:3 memuat kepala dan bahu lebih rapat daripada 16:9 yang separuhnya terisi dinding.

  Pratinjaunya **tidak pernah dicerminkan**. Foto absen adalah dokumen: nama pada tanda pengenal, arah rambut, dan sisi tubuh harus sama dengan kenyataan — dan foto yang kelak dipromosikan menjadi foto referensi (FR-PEG-05) harus menghadap arah yang sama dengan foto pembandingnya. Sudut bidik dan garis pemindaian tetap ada, ukurannya menyesuaikan bingkai yang lebih kecil.

- Kiosk dirancang untuk dioperasikan cepat tanpa mouse: fokus otomatis pada kolom input dan alur berbasis tap/keyboard.

- Kepadatan informasi pada tabel (rekap, e-presensi) mengikuti pola sistem sejenis yang sudah dikenal pengguna di lingkungan Disnakertrans, agar transisi penggunaan lebih mudah.

- **Dropdown dan pemilih tanggal digambar sendiri**, bukan `<select>` dan `<input type="date"` bawaan peramban. Keduanya tidak dapat ditata: pada mode gelap, daftar opsi `<select>` muncul sebagai kotak putih asing di tengah halaman, dan di luar Chrome kolom tanggal tampil sebagai "dd/mm/yyyy" polos tanpa kalender. Basisnya Headless UI (Vue 3), yang menyediakan perilaku papan ketik dan ARIA tanpa gaya bawaan yang perlu dilawan.

- **Tabel lebar digulir, bukan diubah menjadi kartu bertumpuk.** Tabel di aplikasi ini dibaca untuk *membandingkan* baris — jam datang antar pegawai, capaian antar unit; tampilan kartu menghilangkan gulir mendatar tetapi sekaligus menghancurkan perbandingan itu. Sebagai gantinya tabel digulir di dalam wadahnya sendiri (tidak pernah meluber keluar viewport), dengan bayangan tepi yang menandakan masih ada isi tersembunyi, kolom pertama lengket di layar sempit, dan kolom aksi lengket di tepi kanan supaya tombolnya selalu terjangkau.

- **Target sentuh ~44px** pada perangkat tanpa penunjuk halus (`pointer: coarse`), tanpa membesarkan tampilan di layar desktop yang memakai tetikus.

- **Navigasi di layar sempit** memakai laci yang meluncur dari kiri di atas isi halaman, dipicu tombol menu pada bilah atas. Menu proyek ini punya sebelas butir; menumpuknya di atas konten akan mendorong isi halaman jauh ke bawah lipatan.
