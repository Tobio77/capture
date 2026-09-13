# Keamanan — Daftar Periksa Penerapan

Dokumen ini lahir dari audit keamanan pra-deploy (11 September 2026). Isinya
adalah butir-butir yang **tidak dapat diselesaikan dari dalam kode** karena
bergantung pada lingkungan produksi, beserta jaminan-jaminan yang sudah
ditegakkan aplikasi dan harus tetap berdiri setelah penerapan.

Yang sudah diperbaiki di kode tidak diulang di sini; riwayatnya ada pada
commit audit dan komentar di berkas masing-masing.

---

## 1. Wajib sebelum go-live

### 1.1 Berkas `.env` produksi

| Kunci | Nilai | Alasan |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Bila menyala, halaman galat Laravel menampilkan jejak tumpukan beserta isi konfigurasi — termasuk nama basis data dan potongan kueri. |
| `APP_KEY` | baru, berbeda dari lokal | Kunci enkripsi token WORKA dan cookie. |
| `SESSION_SECURE_COOKIE` | `true` | |
| `PROXY_TEPERCAYA` | alamat proxy, **bukan** `*` | Lihat §1.3. |

> **APP_KEY tidak boleh diganti setelah token WORKA tersimpan.** Token itu
> dienkripsi dengan kunci tersebut; menggantinya membuat token menjadi tidak
> terbaca. Aplikasi menanganinya dengan anggun — dianggap belum diatur — tetapi
> admin harus memasukkannya ulang lewat Setting → Integrasi WORKA.

### 1.2 HTTPS

Aplikasi ini menyalakan kamera dan menyimpan foto wajah. HTTPS bukan pilihan.

- Pasang sertifikat dan paksa pengalihan dari HTTP di tingkat web server.
- Setelah HTTPS berjalan, header `Strict-Transport-Security` terpasang sendiri
  (`App\Http\Middleware\HeaderKeamanan`). Ia sengaja tidak dikirim lewat
  sambungan HTTP.
- `preload` **tidak** disertakan pada HSTS. Mendaftarkan domain ke daftar bawaan
  peramban tidak dapat dibatalkan dengan cepat, dan itu keputusan pemilik
  sistem.

### 1.3 Proxy tepercaya

Bila aplikasi berada di belakang nginx, load balancer, atau Cloudflare, isi
`PROXY_TEPERCAYA` dengan alamat proxy tersebut.

Tanpa itu `$request->ip()` membaca alamat **proxy** sebagai alamat pengunjung —
satu nilai yang sama untuk semua orang — dan tiga hal ikut rusak:

1. **Penguncian login per-IP berubah menjadi senjata.** `AutentikasiService`
   memakai kunci kedua berupa alamat IP saja; bila semua admin berbagi satu
   alamat, 20 percobaan gagal mengunci seluruh admin selama 30 menit.
2. **Audit trail mencatat alamat yang keliru** pada aktivasi kiosk,
   penggabungan event, dan login — padahal itulah nilai yang dicari panitia saat
   menelusuri absen mencurigakan.
3. **Batas laju cadangan** untuk permintaan tanpa perangkat dan tanpa admin
   jatuh ke satu keranjang bersama.

Jangan pernah mengisinya `*`. Mempercayai proxy mana pun berarti mempercayai
header `X-Forwarded-For` yang dikirim siapa pun, dan penyerang tinggal
menuliskan alamat palsu di sana untuk lolos dari penguncian sekaligus mengotori
audit trail.

**Cara memverifikasi:** lakukan satu login gagal dari perangkat lain, lalu
periksa `log_aktivitas.ip_address` pada baris terakhir. Yang tertulis harus
alamat peramban, bukan alamat proxy.

### 1.4 Hak akses basis data

Buat pengguna MySQL khusus aplikasi — jangan `root`.

Lalu **cabut hak `DELETE` dan `UPDATE` atas tabel `log_aktivitas`**:

```sql
REVOKE DELETE, UPDATE ON capture.log_aktivitas FROM 'capture_app'@'%';
FLUSH PRIVILEGES;
```

Model `LogAktivitas` sudah melempar pada `deleting` dan `updating`, tetapi itu
pagar lapis aplikasi: ia menghentikan kode yang kelak ditambahkan tanpa
sengaja, bukan penyerang yang sudah memegang akses SQL langsung. Pencabutan hak
di atas yang membuat jejak audit benar-benar append-only.

> Pertimbangkan juga mengirimkan salinan `log_aktivitas` ke penyimpanan
> terpisah (syslog, bucket append-only) bila jejaknya harus tahan terhadap
> penyusupan server secara keseluruhan.

### 1.5 Perangkat absen

- Terbitkan kode aktivasi **per perangkat** lewat Kelola Perangkat Absen. Kode
  disimpan sebagai hash dan hanya ditampilkan sekali; bila terlewat, terbitkan
  ulang.
- Pastikan **Mode Terbuka mati** sebelum go-live. Ia hanya untuk keadaan
  darurat.
- Pastikan **verifikasi wajah menyala** sebelum go-live.

---

## 2. Dua sakelar yang harus dijaga

Keduanya melonggarkan pengaman, keduanya mudah dinyalakan untuk satu apel pagi
lalu terlupakan. Sejak audit, keduanya memasang spanduk permanen di Panel Admin
dan butir di panel Perhatian dashboard yang menyebut **sudah berapa lama**.

| Sakelar | Akibat bila dimatikan/dinyalakan | Nada peringatan |
|---|---|---|
| **Verifikasi Wajah** (mati) | Kehadiran dicatat tanpa membuktikan wajah — cukup menyebut NIP. | Rose (gagal) |
| **Mode Terbuka** (nyala) | Mesin mana pun yang menjangkau alamat aplikasi dapat menjadi titik absen tanpa kode. | Amber (peringatan) |

Bila keduanya berlaku bersamaan, perangkat ad-hoc **tidak** dapat mempromosikan
foto referensi wajah (pagar H-2) — tetapi kombinasi itu tetap tidak boleh
dibiarkan berjalan lebih dari satu kegiatan.

---

## 3. Batas yang masih ada — sadari, jangan lupakan

### 3.1 Deteksi wajah tetap di klien

Sejak audit, **keputusan** cocok/tidak dilakukan server: peramban mengirimkan
deskriptor hasil capture, server membandingkannya dengan embedding referensi
yang tidak pernah meninggalkan server.

Yang masih dipercaya dari peramban adalah bahwa deskriptor itu benar-benar
berasal dari kamera yang menyala saat itu. Penyerang yang memegang perangkat
dan pernah merekam deskriptor seorang pegawai masih dapat memutarnya ulang.
Menutup celah itu menuntut deteksi dan *liveness* di sisi server — keputusan
arsitektur tersendiri, bukan perbaikan tambalan.

**Mitigasi yang berlaku sekarang:** keamanan fisik titik absen, audit trail
yang mencatat perangkat dan alamat IP setiap tap, dan pencabutan device token
lewat Kelola Perangkat Absen.

### 3.2 Reset kode event tidak memutus perangkat yang sudah bergabung

Ini disengaja dan terdokumentasi: reset menutup pintu bagi yang belum masuk,
bukan mengusir titik absen yang sedang melayani antrean. Pesan sukses yang
dibaca admin menyatakannya apa adanya.

Untuk memutus perangkat tertentu, cabut aksesnya lewat Kelola Perangkat Absen.

### 3.3 Content-Security-Policy belum dipasang

`HeaderKeamanan` memasang `X-Frame-Options`, `X-Content-Type-Options`,
`Referrer-Policy`, `Permissions-Policy`, dan HSTS — tetapi belum CSP.

CSP yang keliru tidak gagal dengan suara: ia mematikan layar absen di pintu
masuk kantor, dan baru ketahuan saat antrean pegawai sudah berdiri. Vite dan
Inertia memerlukan penyetelan tersendiri.

**Urutan yang disarankan:** pasang `Content-Security-Policy-Report-Only` lebih
dulu di produksi, amati laporannya selama beberapa hari, baru naikkan menjadi
penegakan.

---

## 4. Yang tidak tercakup audit

Dijadwalkan terpisah:

- Pengujian penetrasi runtime terhadap server yang berjalan.
- Keamanan infrastruktur: konfigurasi web server, TLS, firewall, hardening
  MySQL, hak akses berkas.
- Keamanan sisi WORKA/BKD — hanya klien pemanggilnya yang diperiksa.
- Keamanan fisik titik absen, yang di sistem ini menanggung beban asumsi yang
  besar (lihat §3.1).
