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

SI-ABSEN dibangun sebagai monolith Laravel dengan Inertia.js + Vue 3, selaras dengan stack WORKA/SIMPEG yang sudah berjalan di lingkungan Disnakertrans, sehingga memudahkan pemeliharaan oleh tim yang sama. Modul verifikasi wajah berjalan di sisi klien (browser kiosk) menggunakan `@vladmandic/face-api` (TensorFlow.js) agar beban komputasi tidak dipikul server.

> **Catatan pustaka.** Sejak S08 dipakai `@vladmandic/face-api`, fork face-api.js
> yang masih dirawat dan berjalan di atas TensorFlow.js 4.x. Paket asli
> `face-api.js` berhenti dirawat sejak 2020 dan mem-*pin* tfjs 1.7 yang
> menyeret kerentanan `node-fetch` berseverity *high*. API keduanya sama, dan
> bobot pengenalan wajahnya berkas yang sama — hanya digabung dari dua shard
> menjadi satu `.bin` — sehingga deskriptor tetap 128 dimensi dan validasi di
> sisi server tidak berubah.

### 1.1 Komponen Utama

- Panel Admin (Vue 3 + Inertia) — dashboard, kelola absen, kelola pegawai, kelola user/role, laporan.

- Layar Kiosk (Vue 3, mode ringan/kios) — aktivasi perangkat, capture kamera, form entry absen, daftar e-presensi live.

- Backend Laravel 13 — autentikasi, otorisasi berbasis peran, REST/Inertia endpoints, penjadwalan sinkronisasi pegawai, penyimpanan foto terkompresi.

- Modul Face Matching (client-side) — `@vladmandic/face-api` memuat model ringan (~6 MB) di browser kiosk, menghasilkan embedding wajah, dan membandingkannya dengan embedding referensi yang diambil dari server saat kiosk memuat data event.

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
| Verifikasi Wajah  | `@vladmandic/face-api` (fork face-api.js, TensorFlow.js 4.x) — dijalankan di browser         |
| Database          | MySQL 8                                                                                     |
| Autentikasi Admin | Laravel session/Sanctum                                                                     |
| Autentikasi Kiosk | Token perangkat (device token) tersimpan lokal di browser kiosk, terikat ke satu unit kerja |
| Penjadwalan       | Laravel Scheduler untuk sinkronisasi berkala data pegawai dari WORKA/BKD                    |
| Deployment        | VPS Jagoan Hosting (mengikuti pola deployment WORKA/SIMPEG)                                 |
| Version Control   | GitHub                                                                                      |

# 3. Skema Database (Rancangan Awal)

Skema berikut adalah rancangan awal tabel inti; penamaan kolom dapat disesuaikan pada tahap implementasi mengikuti konvensi migration Laravel.

## 3.1 unit_kerja

| **Kolom**  | **Tipe**                          | **Keterangan**                                             |
|------------|-----------------------------------|------------------------------------------------------------|
| id         | bigint, PK                        |                                                            |
| kode       | varchar(20)                       | Kode unit, unik (mis. BLK-SBY)                             |
| nama       | varchar(150)                      |                                                            |
| induk_id   | bigint, FK → unit_kerja, nullable | Unit induk sesuai hirarki WORKA; null pada unit puncak      |
| aktif      | boolean                           | default true                                               |
| timestamps | \-                                | created_at, updated_at                                     |

> **Catatan sumber data.** Seluruh baris `unit_kerja` berasal dari
> sinkronisasi WORKA, **kecuali satu**: `DISNAKER`. Lihat
> "Unit kerja lokal di luar WORKA" di bawah sebelum menulis query, migration,
> atau seeder yang menyentuh tabel ini.

Hirarki unit kerja mengikuti WORKA (provinsi → dinas → bidang/UPT →
subbag/seksi) melalui `induk_id` yang menunjuk ke baris `unit_kerja` lain.
Kolom ini nullable karena unit puncak (Pemerintah Provinsi Jawa Timur) tidak
berinduk, dan karena unit yang dibuat manual oleh admin belum tentu memiliki
induk. FK menggunakan `nullOnDelete` — selaras dengan kebijakan unit kerja
dinonaktifkan, bukan dihapus (FR-UNIT-01).

### Unit kerja level teratas

WORKA memodelkan struktur organisasi sampai tingkat seksi/subbag, sedangkan
SI-ABSEN menyelenggarakan absensi pada tingkat UPT/bidang. Karena itu tidak
seluruh baris `unit_kerja` diekspos ke admin.

**Unit level teratas** adalah anak langsung simpul OPD (`DISNAKERTRANS`, diatur
lewat `services.worka.kode_opd`) — yaitu `DISNAKER`, seluruh UPT, seluruh
bidang, dan sekretariat; 25 unit pada data saat ini. Inilah satuan yang muncul
di Setting Unit Kerja, menjadi cakupan Admin UPT, dan dipilih pada event maupun
kiosk. Perhatikan bahwa ini **bukan** `induk_id IS NULL` — predikat itu hanya
menghasilkan `PROV-JATIM`, karena unit level teratas tetap berinduk ke OPD.

Sebaliknya, setiap query yang menjawab "siapa saja yang bernaung di unit ini"
— daftar pegawai, cakupan Admin UPT, penyaring unit, peserta event — **wajib
mencakup seluruh turunan**, karena pegawai menaut ke seksi/subbag, bukan ke
UPT-nya. Padanan persis (`unit_kerja_id = ?`) akan menampilkan UPT berisi
ratusan pegawai sebagai nol.

Keduanya disediakan pada model `UnitKerja`:

| Kebutuhan                    | Pemakaian                              |
|------------------------------|----------------------------------------|
| Unit yang boleh diekspos     | `UnitKerja::query()->levelTeratas()`   |
| Cakupan sebuah unit          | `UnitKerja::idsDenganTurunan($id)`     |

`idsDenganTurunan()` menelusuri sedalam apa pun dari satu kali baca tabel, dan
membawa penjaga siklus karena FK tidak mencegah A → B → A. Unit yang dibuat
admin lewat Setting Unit Kerja otomatis dijadikan anak simpul OPD, supaya tidak
langsung lenyap dari daftar. Selama WORKA belum pernah disinkronkan, simpul OPD
belum ada dan unit tanpa induk yang dianggap level teratas — agar halaman tidak
tampil kosong pada instalasi baru.

Sinkronisasi unit kerja dari WORKA berjalan **tiga tahap** karena urutan baris
yang dikirim WORKA tidak menjamin induk muncul lebih dulu daripada anaknya:

1. **Tahap simpan** — seluruh unit dibuat/diperbarui (kode, nama, aktif) tanpa
   menyentuh `induk_id`, sekaligus mencatat kode induk tiap unit.
2. **Tahap tautkan** — `induk_id` diisi dengan mencocokkan kode induk terhadap
   kolom `kode` setelah semua unit dipastikan ada.
3. **Tahap unit lokal** — induk unit milik SI-ABSEN sendiri ditegakkan ulang
   dari peta `services.worka.induk_unit_lokal`.

Induk dibaca dari medan `parent` pada jawaban `GET /api/v1/absen/unit-kerja`
(objek `{id, kode, nama}`, bernilai null pada unit puncak); kunci `induk` ikut
dikenali sebagai alias. Bila kode induk tidak ada pada daftar yang dikirim
WORKA, tautan lama dipertahankan dan kejadiannya dicatat di
`storage/logs/worka-api.log` — hirarki tidak diputus berdasarkan data tak
lengkap.

### Unit kerja lokal di luar WORKA

**`DISNAKER` adalah satu-satunya baris `unit_kerja` yang bukan hasil
sinkronisasi WORKA.** Baris ini dibuat `UnitKerjaSeeder` dan berfungsi sebagai
*anchor* untuk kepala dinas beserta perangkat tingkat dinas.

Alasannya: WORKA memodelkan struktur organisasi (`PROV-JATIM` → `DISNAKERTRANS`
→ bidang/UPT → subbag/seksi), sedangkan kepala dinas menduduki jabatan pada
OPD itu sendiri, bukan pada salah satu bidang di bawahnya. Pada WORKA,
`DISNAKERTRANS` adalah simpul struktural dengan `total_pegawai_aktif = 0` —
tidak ada unit yang bisa dipakai SI-ABSEN sebagai tempat absen tingkat dinas.
`DISNAKER` mengisi celah itu.

Yang bergantung padanya:

| Bergantung             | Sumber                 | Akibat bila baris ini hilang        |
|------------------------|------------------------|-------------------------------------|
| Akun Admin Dinas       | `UserSeeder`           | `firstOrFail()` gagal saat seeding  |
| Kiosk kantor dinas     | `KioskSeeder`          | Titik absen tingkat dinas tak punya unit |
| Absensi kepala dinas   | operasional            | Tidak ada unit untuk mengabsen      |

Konsekuensi yang perlu diingat:

- **Jangan berharap sinkronisasi memulihkannya.** WORKA tidak pernah
  mengirimkan `DISNAKER`; `pegawai:sinkron` hanya menegakkan induknya, tidak
  pernah membuat ulang barisnya. Bila terhapus, harus di-seed kembali.
- **Jangan disamakan dengan `DISNAKERTRANS`.** Keduanya bernama mirip
  ("Dinas Tenaga Kerja dan Transmigrasi…") tetapi berbeda peran: yang satu
  simpul struktur dari WORKA, yang satu unit absen milik SI-ABSEN. `DISNAKER`
  bernaung **di bawah** `DISNAKERTRANS`.
- **Pegawai di dalamnya tidak ikut siklus hidup WORKA.** Karena NIP-nya tidak
  ada pada daftar WORKA, pegawai contoh di unit ini akan dinonaktifkan oleh
  `sinkronPenuh` — perilaku yang benar, bukan galat.

Induk `DISNAKER` tidak dapat ditarik dari API, sehingga dinyatakan sebagai peta
`kode unit lokal => kode unit induk` pada `config/services.php`:

```php
'induk_unit_lokal' => [
    'DISNAKER' => 'DISNAKERTRANS',
],
```

Tahap ketiga menegakkan peta ini **setiap kali `pegawai:sinkron` selesai**, dan
bersifat idempoten — dijalankan berkali-kali tidak mengubah apa pun setelah
tautan benar. Konsekuensinya hirarki bersifat *self-healing*: urutan `migrate`,
`db:seed`, dan sinkronisasi tidak lagi menentukan hasil akhir. Bila unit induk
belum ada (WORKA belum pernah disinkronkan), penautan ditunda dengan peringatan
di `storage/logs/worka-api.log` dan pulih sendiri pada sinkronisasi berikutnya.

Dua pengaman berlaku pada tahap ini:

- Unit yang ternyata **ikut dikirim WORKA** dilewati — hirarki dari WORKA yang
  berlaku, peta tidak boleh menimpanya.
- Unit lokal **di luar peta** tidak disentuh sama sekali, termasuk nama, status
  aktif, dan `induk_id`-nya.

## 3.2 pegawai

| **Kolom**               | **Tipe**                | **Keterangan**                             |
|-------------------------|-------------------------|--------------------------------------------|
| id                      | bigint, PK              |                                            |
| nip                     | varchar(20), unik       | sumber: WORKA/BKD                          |
| uid_kartu               | varchar(32), unik, nullable | UID kartu RFID; milik SI-ABSEN         |
| kartu_didaftarkan_at    | timestamp, nullable     | waktu pendaftaran kartu terakhir           |
| nama                    | varchar(150)            | sumber: WORKA/BKD                          |
| unit_kerja_id           | bigint, FK → unit_kerja |                                            |
| jabatan                 | varchar(150)            | sumber: WORKA/BKD                          |
| foto_referensi_path     | varchar(255), nullable  | path foto referensi wajah pada disk privat |
| embedding_wajah         | json, nullable          | deskriptor wajah 128 dimensi (face-api)          |
| wajah_terdaftar         | boolean                 | default false                              |
| wajah_didaftarkan_at    | timestamp, nullable     | waktu pendaftaran/pembaruan wajah terakhir |
| sumber_sinkron_terakhir | timestamp               | waktu sinkronisasi terakhir dari WORKA/BKD |
| timestamps              | \-                      | created_at, updated_at                     |

`foto_referensi_path`, `embedding_wajah`, `wajah_terdaftar`, dan
`wajah_didaftarkan_at` adalah milik SI-ABSEN sendiri: sinkronisasi WORKA tidak
pernah menimpanya (lihat §3.1 dan FR-PEG-02).

### Kartu RFID (FR-TAP-03)

Reader di lokasi adalah **USB/HID 13,56 MHz**, yang mengeluarkan **UID kartu**
sebagai ketikan keyboard — bukan NIP. Karena itu kartu perlu ditautkan lebih
dulu lewat Kelola Pegawai sebelum dapat dipakai men-tap.

Kolom tap di kiosk mengirim apa pun yang masuk sebagai `id_card`, dan
`KartuRfidService::kenali()` mencocokkannya **ke NIP lebih dulu, baru ke
`uid_kartu`**. Urutan itu disengaja: bila kartu di lokasi ternyata sudah berisi
NIP, tap langsung bekerja tanpa perlu mendaftarkan kartu sama sekali.

Seluruh UID dinormalkan (`KartuRfidService::normalkan()`) dengan membuang
pemisah dan menyamakan huruf besar, agar kartu yang sama menghasilkan nilai
yang sama walau dibaca reader bermerek lain yang memakai gaya penulisan
berbeda. Satu UID hanya boleh dimiliki satu pegawai — kartu ganda membuat tap
salah alamat.

Jawaban identifikasi membawa medan `metode` (`rfid` bila nilainya cocok dengan
UID kartu terdaftar, selain itu `manual`) supaya metode absen tercatat benar
saat penyimpanan (S16). Kartu bersifat opsional: pegawai tanpa kartu tetap
dapat absen dengan mengetik NIP.

UID kartu tidak pernah ikut tercatat pada audit trail — kartu adalah kredensial
fisik, dan yang perlu terekam hanyalah siapa mendaftarkan kartu untuk siapa.

### Pendaftaran foto referensi wajah (FR-PEG-05)

Wajah referensi disimpan dalam dua bentuk yang saling melengkapi:

| Bentuk    | Tempat                     | Dipakai untuk                          |
|-----------|----------------------------|----------------------------------------|
| Foto      | disk privat `local`, `foto-referensi/` | rujukan visual admin      |
| Embedding | kolom `embedding_wajah`    | pencocokan wajah di kiosk (S15)        |

**Embedding dihitung di browser, bukan di server.** Saat admin mengunggah foto,
face-api di halaman Kelola Pegawai mendeteksi wajah dan menghasilkan
deskriptor 128 dimensi, lalu foto dan deskriptor dikirim bersama dalam satu
permintaan. Server hanya memeriksa bentuk deskriptor (panjang 128, seluruhnya
angka berhingga) dan menyimpannya — tidak ada pustaka pengenalan wajah di sisi
server, konsisten dengan keputusan arsitektur bahwa verifikasi wajah berjalan
di klien.

Konsekuensi yang disengaja:

- Foto yang tidak berisi wajah, atau berisi lebih dari satu wajah, **ditolak di
  browser sebelum terkirim** — tidak ada foto referensi tanpa embedding yang
  sah, sehingga S15 tidak perlu menangani data setengah jadi.
- Model face-api (~6,8 MB, `public/models/`) dimuat **malas**: hanya ketika
  admin membuka dialog pendaftaran wajah, bukan pada setiap kunjungan halaman.
- Foto tidak pernah diletakkan pada disk publik. Penyajiannya melalui route
  terautentikasi `GET /admin/pegawai/{pegawai}/wajah`, dengan Admin UPT
  terbatas pada pegawai unitnya beserta turunannya (NFR-04, SRS §6).
- Pembaruan mengganti berkas lama setelah baris tersimpan, sehingga kegagalan
  penyimpanan tidak meninggalkan pegawai tanpa foto sama sekali.
- Pencabutan menghapus berkas dan embedding, tetapi **tidak** menghapus baris
  pegawai — datanya milik WORKA (FR-PEG-02).

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
| toleransi_menit | smallint unsigned   | disalin dari Setting Absen saat dibuat, lalu berdiri sendiri |
| cakupan         | enum                | unit \| semua_unit                           |
| status          | enum                | aktif \| ditutup                             |
| dibuat_oleh     | bigint, FK → users  |                                              |
| ditutup_pada    | timestamp, nullable |                                              |
| catatan         | text, nullable      |                                              |
| timestamps      | \-                  | created_at, updated_at                       |

`dibuat_oleh` memakai `nullOnDelete` agar event tidak ikut hilang bila akun
pembuatnya dihapus; `toleransi_menit` sengaja disalin, bukan dirujuk, sehingga
mengubah Setting Absen tidak menggeser event yang sudah berjalan (FR-SET-02).

## 3.6 event_unit_kerja (pivot)

| **Kolom**      | **Tipe**   | **Keterangan**                    |
|----------------|------------|-----------------------------------|
| id             | bigint, PK |                                   |
| event_absen_id | bigint, FK | cascade on delete                 |
| unit_kerja_id  | bigint, FK | cascade on delete                 |
|                |            | unik per pasangan event × unit    |

**Cakupan event memakai unit level teratas.** Unit yang boleh dipilih adalah
unit level teratas yang aktif (lihat §3.1) — UPT, bidang, sekretariat, dan
DISNAKER. Seksi/subbag tidak dapat dipilih karena absensi diselenggarakan pada
tingkat UPT/bidang; pegawai di bawahnya tetap tercakup lewat
`UnitKerja::idsDenganTurunan()`.

**Cakupan "semua unit" tidak menyimpan baris pivot sama sekali.** Menyalin
seluruh unit ke pivot akan basi begitu unit baru masuk dari sinkronisasi WORKA,
sehingga event bercakupan semua unit dikenali dari kolom `cakupan` saja dan
otomatis mencakup unit yang lahir setelahnya.

**Batasan peran (FR-EVT-02).** Admin UPT hanya dapat memilih unit level teratas
yang menaunginya — termasuk bila akunnya menempel pada seksi di bawahnya — dan
tidak dapat memakai cakupan "semua unit". Pada daftar event, Admin UPT melihat
event yang menyentuh unitnya beserta event bercakupan semua unit, tetapi hanya
dapat mengubah event miliknya sendiri. Event yang sudah ditutup tidak dapat
diubah oleh peran mana pun, karena absensi yang terlanjur tercatat menautnya.

### Tumpang tindih event aktif (FR-EVT-06)

**Tidak boleh ada dua event berstatus `aktif` dengan cakupan unit kerja yang
beririsan.** Selama keduanya aktif, kiosk pada unit itu menghadapi lebih dari
satu event dan tidak dapat memutuskan sebuah tap milik yang mana.

Tanggal dan jam **tidak ikut diperiksa sama sekali** — yang menentukan hanyalah
status. Konsekuensinya:

- Apel pagi dan apel sore pada unit yang sama **tidak** dapat dibuat bersamaan;
  apel pagi harus ditutup lebih dulu.
- Event untuk tanggal mendatang pada unit yang sama juga tertahan selama event
  berjalan belum ditutup.
- Menutup event yang lebih dulu berjalan adalah satu-satunya jalan keluar.

*Cakupan* dinilai beririsan bila salah satu pihak bercakupan "semua unit" —
yang menurut definisi mencakup segalanya — atau bila pivot unit keduanya
bersinggungan. Unit yang berbeda tetap boleh punya event aktif masing-masing.

Pemeriksaan berjalan saat pembuatan maupun perubahan event; sebuah event tidak
dihitung bentrok dengan dirinya sendiri. Pesan kesalahannya menyebut nama,
tanggal, dan cakupan event yang bentrok agar admin tahu persis apa yang harus
ditutup.

### Menutup entry (FR-EVT-04)

Menutup event mengubah `status` menjadi `ditutup` dan mengisi `ditutup_pada`;
perubahannya tercatat pada audit trail beserta pelaku dan waktunya (NFR-09).
Penutupan bersifat **satu arah** — tidak ada aksi membuka kembali, karena
membukanya lagi akan menghidupkan penerimaan tap atas event yang sudah
dinyatakan selesai.

**Penolakan tap.** Kiosk tidak menyebutkan event mana yang dimaksud saat
men-tap. Server yang menentukan lewat `EventAbsenService::eventAktifUntukKiosk()`,
dan itu tidak pernah ambigu karena FR-EVT-06 menjamin paling banyak satu event
aktif per unit kerja. Begitu event ditutup, unit itu tidak lagi memiliki event
aktif sehingga `POST /kiosk/tap/validasi-nip` menjawab:

```json
{ "success": false, "code": "EVENT_TIDAK_AKTIF", "message": "…" }
```

dengan status HTTP 409. Jawaban sukses kini menyertakan objek `event` (id,
nama, jam_mulai, toleransi_menit) sebagai penampung tap tersebut.

Cakupan kiosk dihitung dari unit kerjanya beserta seluruh turunannya **dan**
rantai induknya sampai simpul OPD — kiosk dapat terdaftar pada seksi, sedangkan
cakupan event dinyatakan pada unit level teratas. Event bercakupan "semua unit"
melayani kiosk unit mana pun.

### Penghapusan event

Event dapat dihapus permanen **selama belum menautkan satu pun baris
`absensi`**. Statusnya tidak menentukan: event salah-buat yang terlanjur
ditutup pun masih dapat dibersihkan, sedangkan event yang sudah menerima satu
tap terkunci selamanya. Baris `event_unit_kerja` ikut terhapus lewat cascade,
dan penghapusan tercatat pada audit trail.

Pemeriksaannya menoleransi keadaan tabel `absensi` belum ada — tabel itu
dibuat pada S16 — dengan menganggap jumlah absensi nol, sehingga tidak perlu
diubah lagi setelah tabelnya lahir.

## 3.7 event_kiosk (kiosk aktif per event)

| **Kolom**            | **Tipe**              | **Keterangan**                            |
|----------------------|-----------------------|-------------------------------------------|
| id                   | bigint, PK            |                                           |
| event_absen_id       | bigint, FK            | cascade on delete                         |
| kiosk_id             | bigint, FK            | cascade on delete                         |
| ip_address           | varchar(45), nullable | alamat IP terbaru; 45 menampung IPv6      |
| aktif_pada           | timestamp             | pertama kali kiosk melayani event ini     |
| terakhir_aktif_pada  | timestamp             | aktivitas terbaru                         |
|                      |                       | unik per pasangan event × kiosk           |

**Satu baris per pasangan event × kiosk, bukan satu baris per kunjungan.**
Yang dibutuhkan layar detail event adalah daftar kiosk terhubung, bukan riwayat
setiap kali kiosk menyentuh event. Karena itu `aktif_pada` menahan waktu
pertama, sedangkan `ip_address` dan `terakhir_aktif_pada` bergerak mengikuti
aktivitas terbaru — kiosk dapat berpindah alamat IP di tengah satu event.
Kolom `terakhir_aktif_pada` adalah tambahan di luar rancangan awal, diperlukan
agar admin dapat membedakan kiosk yang masih melayani dari yang sudah lama
diam.

**Kapan dicatat.** Kiosk terhitung terhubung sejak **membuka layar kiosk**,
tidak perlu menunggu tap pertama, dan pencatatannya diperbarui pada setiap tap
berikutnya. Event yang sudah ditutup tidak pernah dicatat: tidak ada kiosk yang
sah "terhubung" ke entry yang sudah selesai.

**Detail event (FR-EVT-05).** `GET /admin/kelola-absen/event/{event}/detail`
menjawab JSON berisi daftar kiosk terhubung (titik, unit, alamat IP, waktu
aktif terakhir), jumlah absen masuk, dan status entry. Dijawab sebagai JSON
karena dimuat modal di atas daftar event yang sudah tampil. Hak melihat lebih
longgar daripada hak mengubah: Admin UPT dapat membuka detail event bercakupan
semua unit, walau tidak dapat mengubahnya.

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
| skor_kecocokan_wajah | decimal(5,2)         | hasil similarity dari face-api                         |
| foto_path            | varchar(255)         | path foto hasil capture, terkompresi            |
| timestamps           | \-                   | created_at, updated_at                          |

> *Catatan: Kombinasi (event_absen_id, pegawai_id, jenis) bersifat unik agar tap berulang untuk jenis yang sama memperbarui data, bukan menduplikasi baris — sesuai FR-TAP-05.*

Keunikan itu ditegakkan **di basis data**, bukan hanya di kode, supaya dua
kiosk yang men-tap orang yang sama bersamaan tidak dapat menyelinapkan baris
kembar. `kiosk_id` memakai `nullOnDelete` agar perangkat dapat dilepas tanpa
menghapus riwayat absensinya.

### Validasi ulang di server (FR-TAP-05, FR-TAP-06)

Kiosk sudah memutuskan cocok/tidaknya wajah di sisi klien, tetapi keputusan itu
datang dari peramban yang dapat dimanipulasi. `POST /kiosk/absen` karena itu
memeriksa ulang seluruh syaratnya sebelum menyimpan:

| Yang diperiksa ulang | Jawaban bila gagal |
|----------------------|--------------------|
| Event masih dibuka dan mencakup kiosk | `409 EVENT_TIDAK_AKTIF` |
| Kartu/NIP dikenal | `404 ID_TIDAK_DIKENAL` |
| Pegawai aktif | `403 PEGAWAI_TIDAK_AKTIF` |
| Skor disertakan saat verifikasi wajah menyala | `422 WAJAH_BELUM_DIVERIFIKASI` |
| Skor ≥ ambang Setting Absen | `422 WAJAH_TIDAK_COCOK` |
| Foto berupa data URI JPEG di bawah batas | `422` galat validasi |

**Ambang dibaca ulang dari Setting Absen, bukan dari kiriman kiosk**, sehingga
kiosk tidak dapat menurunkan syaratnya sendiri. Bila verifikasi wajah
dimatikan admin, skor yang telanjur dikirim kiosk tidak ikut disimpan.

### Status ketepatan (FR-TAP-07)

Tepat waktu selama tap terjadi pada atau sebelum `jam_mulai + toleransi_menit`
event; setelahnya terlambat. Hanya berlaku untuk jenis Datang — absen Pulang
menyimpan `null`. Tap berulang menggeser waktu sekaligus menghitung ulang
ketepatannya.

### Pembaruan live Daftar e-Presensi (FR-TAP-08)

Layar kiosk menarik `GET /kiosk/presensi` setiap **10 detik**. Angka itu cukup
terasa langsung bagi pegawai yang menunggu namanya muncul, sementara satu kiosk
hanya membebani server enam permintaan per menit.

Jawaban membawa daftar terkini **beserta keadaan event**, sehingga kiosk
mengetahui entry yang ditutup admin tanpa perlu dimuat ulang: kolom tap
langsung terkunci dan badge berubah menjadi "Entry Ditutup" (FR-EVT-04).
Sebaliknya, event yang baru dibuka membuka kembali kolom tap dengan
sendirinya.

Penarikan dilewati selagi sebuah tap sedang diproses, supaya hasil yang baru
tampil tidak tertimpa di tengah pembacaan pegawai. Karena daftar disusun ulang
dari basis data setiap kali — satu baris per pegawai, bukan per tap — tidak ada
peluang baris ganda muncul dari balapan antar kiosk (FR-TAP-05).

### Foto absen

Disimpan pada disk privat `local` di bawah `foto-absen/{event}/`, tidak pernah
pada disk publik (NFR-04), dan disajikan lewat
`GET /kiosk/absen/{absensi}/foto` yang hanya melayani kiosk yang sedang
menangani event yang sama. Foto dikirim kiosk sebagai data URI JPEG yang sudah
disusutkan sesuai preset Setting Absen; server hanya memeriksa bentuk dan
ukurannya (batas 150 KB, memberi kelonggaran atas selisih encoder peramban
terhadap batas ~90 KB NFR-06) dan tidak menyusutkan ulang. Tap berulang
mengganti foto lama setelah baris tersimpan.

## 3.9 Setting Absen (key-value pada tabel `pengaturan`)

Diimplementasikan sebagai key-value, bukan tabel single-row — memakai tabel
`pengaturan` yang sudah dipakai Integrasi WORKA, sehingga hanya ada satu
mekanisme pengaturan runtime di sistem ini.

| **Kunci**                       | **Tipe**    | **Bawaan** | **Kebutuhan** |
|---------------------------------|-------------|------------|---------------|
| absen.metode_manual_aktif       | `'1'`/`'0'` | aktif      | FR-SET-01     |
| absen.metode_rfid_aktif         | `'1'`/`'0'` | aktif      | FR-SET-01     |
| absen.metode_wajah_aktif        | `'1'`/`'0'` | aktif      | FR-SET-01     |
| absen.toleransi_default_menit   | int         | 15         | FR-SET-02     |
| absen.ambang_kecocokan_wajah    | int (%)     | 85         | FR-SET-03     |
| absen.kompresi_foto             | enum        | sedang     | FR-SET-04     |

Nilai bawaan berlaku selama kuncinya belum pernah disimpan, sehingga instalasi
baru berjalan tanpa perlu seeding pengaturan. Pembacaan terpusat pada
`SettingAbsenService`.

**Preset kompresi.** FR-SET-04 menuntut dimensi maksimum dan kualitas JPEG,
bukan sekadar nama preset. Angkanya dikunci pada enum `KompresiFoto` agar layar
admin dan kiosk membaca sumber yang sama:

| Preset | Dimensi maks | Kualitas JPEG | Ukuran terukur |
|--------|--------------|---------------|----------------|
| Ringan | 480 px       | 70            | 14–24 KB       |
| Sedang | 560 px       | 75            | 21–35 KB       |
| Tinggi | 640 px       | 80            | 30–54 KB       |

Angka ukuran di atas **hasil pengukuran, bukan taksiran**: tiap kombinasi
diterapkan pada 5 foto uji 1280×960 dengan tingkat kerumitan berbeda, lalu
ukuran berkasnya dicatat. Pemandangan berdetail padat berada di ujung atas
rentang; wajah dari jarak dekat dengan latar kantor — kasus sesungguhnya di
kiosk — cenderung di ujung bawah.

**Batas NFR-06.** Satu foto absen tersimpan tidak boleh melebihi ~90 KB. Preset
terbesar (Tinggi) berhenti di 54 KB pada kasus terburuk, menyisakan margin
~40%. Margin itu disengaja: penyusutan sesungguhnya dilakukan `canvas.toBlob()`
di browser kiosk, yang tabel kuantisasi JPEG-nya berbeda dari GD yang dipakai
saat pengukuran, dan selisihnya tertampung di dalam margin. Invarian ini dijaga
test — `KompresiFoto::ukuranTerburukKb()` tidak boleh melampaui
`BATAS_UKURAN_KB`; bila preset diubah, angkanya wajib diukur ulang, bukan
ditaksir.

**Batas nilai.** Ambang kecocokan wajah 70–99% (mengikuti slider UIUX §3.5),
toleransi keterlambatan 0–180 menit.

**Minimal satu metode absen harus aktif.** Mematikan ketiganya membuat absensi
mustahil dilakukan, jadi kombinasi itu ditolak validasi — pengaturan tidak
boleh mengunci sistemnya sendiri.

Setting Absen adalah pengaturan global sistem, sehingga terbatas pada
Superadmin dan Admin Dinas (matriks peran SRS §6); Admin UPT tidak dapat
membuka maupun menyimpannya. Setiap perubahan tercatat pada audit trail beserta
medan yang bergeser (mis. `ambang_kecocokan_wajah 85 → 95`); menyimpan nilai
yang sama persis tidak menambah catatan.

# 4. Desain Endpoint Utama

Ringkasan endpoint inti; daftar lengkap akan dirinci sebagai route Laravel pada tahap implementasi.

| **Method** | **Endpoint**                           | **Keterangan**                                                                  |
|------------|----------------------------------------|---------------------------------------------------------------------------------|
| POST       | /admin/login                           | Login akun admin                                                                |
| POST       | /kiosk/aktivasi                        | Aktivasi perangkat kiosk, menghasilkan device_token                             |
| POST       | /kiosk/tap/identifikasi                | Kenali pegawai dari UID kartu RFID atau NIP yang diketik (FR-TAP-03)            |
| GET        | /kiosk                                 | Layar utama kiosk; membawa event aktif, metode yang menyala, dan daftar presensi |
| POST       | /kiosk/absen                           | Kirim hasil absen; seluruh syarat diperiksa ulang di server (FR-TAP-05)          |
| GET        | /kiosk/presensi                        | Daftar e-Presensi terkini beserta keadaan event, ditarik berkala (FR-TAP-08)     |
| GET        | /kiosk/absen/{absensi}/foto            | Foto absen untuk Daftar e-Presensi, terbatas kiosk pada event yang sama (NFR-04) |
| GET        | /admin/kelola-absen/event              | Daftar event (terfilter sesuai peran)                                           |
| POST       | /admin/kelola-absen/event              | Buat event baru (FR-EVT-01, FR-EVT-02)                                          |
| GET        | /admin/kelola-absen/event/{event}/detail| Detail event: kiosk terhubung, jumlah masuk, status (FR-EVT-05)                 |
| PATCH      | /admin/kelola-absen/event/{event}      | Ubah event yang masih aktif                                                     |
| DELETE     | /admin/kelola-absen/event/{event}      | Hapus permanen event yang belum menautkan absensi                               |
| POST       | /admin/kelola-absen/event/{event}/tutup| Tutup entry event (FR-EVT-04)                                                   |
| GET        | /admin/kelola-absen/event/{event}/rekap| Rekap absen live per event (S21)                                                |
| GET        | /admin/kelola-absen/setting            | Form Setting Absen (Superadmin & Admin Dinas)                                   |
| POST       | /admin/kelola-absen/setting            | Simpan Setting Absen (FR-SET-01 s.d. FR-SET-04)                                 |
| GET        | /admin/pegawai                         | Daftar pegawai (terfilter sesuai peran)                                         |
| POST       | /admin/pegawai/{pegawai}/kartu         | Daftarkan/ganti kartu RFID pegawai (FR-TAP-03)                                  |
| DELETE     | /admin/pegawai/{pegawai}/kartu         | Cabut kartu RFID pegawai                                                        |
| POST       | /admin/pegawai/sinkron                 | Trigger sinkronisasi manual dari WORKA/BKD                                      |
| GET        | /admin/pegawai/{pegawai}/wajah         | Sajikan foto referensi lewat route terautentikasi (NFR-04)                      |
| POST       | /admin/pegawai/{pegawai}/wajah         | Daftarkan/perbarui foto referensi beserta embedding dari browser (FR-PEG-05)    |
| DELETE     | /admin/pegawai/{pegawai}/wajah         | Cabut pendaftaran wajah; foto dan embedding dihapus                             |
| GET        | /admin/laporan                         | Laporan kehadiran terfilter tanggal & unit kerja                                |

# 5. Desain Modul Verifikasi Wajah (Client-Side)

1.  Saat kiosk aktif untuk suatu event, browser memuat model face-api (satu kali per sesi, di-cache oleh browser).

1.  Server mengirimkan embedding wajah referensi (bukan foto mentah) milik pegawai yang di-tap saja, menyertai jawaban `POST /kiosk/tap/identifikasi`.

> **Penyimpangan dari rancangan awal (S15).** Rancangan semula menarik seluruh
> embedding unit kerja lewat `GET /kiosk/embedding-wajah/{unit_kerja_id}` untuk
> di-cache di klien. Yang diterapkan adalah pengiriman satu deskriptor menyertai
> jawaban identifikasi tap, karena pencocokan memang bersifat 1:1 (butir 3) dan
> identifikasi itu sendiri sudah menuntut satu perjalanan ke server — sehingga
> cache massal tidak menghemat perjalanan apa pun, sementara menaruh biometrik
> seluruh pegawai unit di browser kiosk memperbesar paparan tanpa imbalan.
> Endpoint massal tetap masuk akal bila kelak tap luring dikerjakan (NFR-05),
> karena saat itu identitas pegawai pun harus tersedia tanpa jaringan.

2.  Saat pegawai tap ID, kamera menangkap satu frame; face-api mendeteksi wajah dan menghasilkan embedding 128 dimensi.

3.  Embedding hasil capture dibandingkan (cosine/Euclidean distance) hanya dengan embedding milik ID yang di-tap (verifikasi 1:1, bukan pencarian 1:banyak).

4.  Jika skor kecocokan ≥ ambang pada Setting Absen, verifikasi dinyatakan berhasil; foto hasil capture dikompresi (resize + kualitas JPEG sesuai Setting Absen) sebelum dikirim ke server sebagai bukti/arsip.

### Skala skor kecocokan

face-api tidak mengenal "persen": ia menghasilkan **jarak Euclidean**, dengan
0,6 sebagai batas keputusan bawaannya. Setting Absen menyatakan ambang dalam
persen 70–99 (FR-SET-03), sehingga keduanya perlu dijembatani.

Pemetaannya lurus dan dikalibrasi pada dua titik:

| Jarak Euclidean | Persentase | Makna                                   |
|-----------------|------------|-----------------------------------------|
| 0,20            | 99%        | kecocokan sangat kuat                   |
| 0,60            | 70%        | batas keputusan bawaan face-api         |

Ambang bawaan 85% dengan demikian menuntut jarak ≤ ~0,393 — lebih ketat
daripada bawaan face-api. **Angka ini persentase kalibrasi, bukan
probabilitas**, dan disimpan pada `absensi.skor_kecocokan_wajah`.

Model face-api (~6,8 MB) dimuat begitu layar kiosk terbuka, bukan saat tap
pertama, agar pegawai pertama tidak menunggu lebih lama daripada berikutnya
(NFR-01). Pegawai yang belum punya foto referensi ditolak dengan pesan yang
menyebutkan hal itu — pendaftaran wajah adalah prasyarat verifikasi (FR-PEG-05).
Bila admin mematikan metode wajah pada Setting Absen, tahap verifikasi
dilewati seluruhnya dan identitas cukup dipastikan kartu atau NIP.

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
