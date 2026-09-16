<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\StatusKiosk;
use App\Enums\SumberKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as CookiePeramban;

/**
 * Masuknya perangkat absen dan autentikasinya (FR-AUTH-01, FR-USR-03, NFR-03).
 *
 * Dua jalur, dan yang berlaku ditentukan Mode Pendaftaran Perangkat
 * ({@see SettingAbsenService::pendaftaranPerangkatAktif()}):
 *
 *   - **Kode unit kerja** — jalur bawaan sejak S49. Sebuah komputer
 *     mengetikkan kode UPT tempat ia berdiri, dan langsung dikenali sebagai
 *     perangkat unit itu ({@see self::masukDenganKodeUnit()}). Tidak ada yang
 *     perlu didaftarkan lebih dahulu, sebab jumlah komputer yang dipakai
 *     sebuah UPT memang berubah dari hari ke hari.
 *   - **Kode aktivasi sekali pakai** — jalur lama, kini opsional. Admin
 *     mendaftarkan tiap perangkat (FR-USR-02), sistem menerbitkan kode sekali
 *     pakai, lalu perangkat menukarkannya dengan device_token miliknya sendiri
 *     ({@see self::aktifkan()}).
 *
 * Apa pun jalurnya, yang dipegang perangkat sesudahnya sama: satu device_token
 * dalam cookie, dan sebuah baris pada Daftar Perangkat yang menyebut unit,
 * alamat IP, serta kapan terakhir ia aktif.
 */
class KioskService
{
    /**
     * Nama cookie penyimpan token perangkat. Cookie dienkripsi oleh Laravel.
     */
    public const string NAMA_COOKIE = 'kiosk_token';

    /**
     * Masa berlaku cookie perangkat dalam menit (satu tahun).
     */
    public const int MASA_COOKIE_MENIT = 525_600;

    /**
     * Masa berlaku kode aktivasi dalam jam.
     */
    public const int MASA_KODE_JAM = 24;

    /**
     * Jeda minimum sebelum jejak IP/waktu perangkat diperbarui lagi.
     */
    public const int JEDA_PEMBARUAN_JEJAK_MENIT = 5;

    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Perangkat memperkenalkan diri dengan kode unit kerjanya (FR-EVT-03,
     * FR-SET-06 revisi S49).
     *
     * Barisnya dibuat di sini, bukan didaftarkan admin lebih dahulu. Jumlah
     * komputer yang dipakai sebuah UPT tidak dibatasi dan memang berubah dari
     * hari ke hari — tiga mesin hari Rabu, empat hari Kamis — sehingga menuntut
     * pendaftaran di muka hanya akan menghasilkan daftar yang selalu
     * ketinggalan keadaan di lapangan. Yang dituntut sistem cukup satu: kode
     * unit yang sah, dan itulah yang menautkan perangkat ke unit yang benar.
     *
     * Semua yang masuk lewat jalur ini ditandai `sumber = ad_hoc`: ia tidak
     * pernah melewati peninjauan seorang admin, dan Daftar Perangkat harus
     * dapat membedakannya dari perangkat yang memang didaftarkan. Selebihnya
     * ia diperlakukan sama persis — device token sendiri, alamat IP tercatat,
     * dan muncul pada rekap sebagai asal sebuah tap.
     *
     * Namanya menyebut unit dan alamat IP-nya, sebab itulah dua keterangan
     * yang dipakai admin mengenali mesin yang tidak pernah dinamainya sendiri.
     *
     * @return array{kiosk: Kiosk, token: string}
     */
    public function masukDenganKodeUnit(UnitKerja $unitKerja, Request $request): array
    {
        $token = Str::random(64);
        $waktu = Carbon::now();

        $kiosk = Kiosk::create([
            'nama_titik' => "Perangkat {$unitKerja->kode} — {$request->ip()}",
            'sumber' => SumberKiosk::AdHoc,
            'unit_kerja_id' => $unitKerja->id,
            'aktif' => true,
        ]);

        $kiosk->forceFill([
            'device_token' => self::hashToken($token),
            'ip_terakhir' => $request->ip(),
            'status' => StatusKiosk::Online,
            'login_terakhir_at' => $waktu,
            'diaktifkan_pada' => $waktu,
        ])->save();

        $this->log->catat(
            AksiLog::AktivasiKiosk,
            "Perangkat masuk dengan kode unit {$unitKerja->kode} — {$unitKerja->nama} dari IP {$request->ip()}.",
            kiosk: $kiosk,
            subjek: $kiosk,
        );

        return ['kiosk' => $kiosk, 'token' => $token];
    }

    /**
     * Terbitkan kode aktivasi sekali pakai untuk kiosk yang sudah didaftarkan admin.
     * Mengembalikan kode dalam bentuk yang ditampilkan ke admin (mis. "7K4M-92XQ").
     */
    public function terbitkanKodeAktivasi(Kiosk $kiosk): string
    {
        $kode = $this->kodeAcak();

        /*
         * Yang disimpan hash-nya (perbaikan L-1); kodenya sendiri hanya ada
         * pada nilai kembalian ini, ditampilkan sekali kepada admin yang
         * menerbitkannya. Daftar Perangkat hanya menyatakan berlaku atau
         * tidak, jadi tidak ada yang perlu membacanya ulang dari basis data.
         */
        $kiosk->forceFill([
            'kode_aktivasi' => self::hashToken($kode),
            'kode_aktivasi_kedaluwarsa_at' => Carbon::now()->addHours(self::MASA_KODE_JAM),
        ])->save();

        return self::formatKode($kode);
    }

    /**
     * Tukarkan kode aktivasi dengan device_token perangkat.
     *
     * @return array{kiosk: Kiosk, token: string} token mentah — hanya dikembalikan sekali
     *
     * @throws ValidationException
     */
    public function aktifkan(string $kode, Request $request): array
    {
        $kode = self::normalkanKode($kode);

        $kiosk = Kiosk::aktif()->where('kode_aktivasi', self::hashToken($kode))->first();

        if (! $kiosk || $kiosk->kode_aktivasi_kedaluwarsa) {
            $this->log->catat(
                AksiLog::AktivasiKioskGagal,
                'Percobaan aktivasi kiosk dengan kode tidak sah atau kedaluwarsa.',
                kiosk: $kiosk,
            );

            throw ValidationException::withMessages([
                'kode_aktivasi' => 'Kode aktivasi tidak dikenal, sudah terpakai, atau telah kedaluwarsa. Mintakan kode baru kepada admin.',
            ]);
        }

        $token = Str::random(64);

        $kiosk->forceFill([
            'device_token' => self::hashToken($token),
            'kode_aktivasi' => null,
            'kode_aktivasi_kedaluwarsa_at' => null,
            'ip_terakhir' => $request->ip(),
            'status' => StatusKiosk::Online,
            'login_terakhir_at' => Carbon::now(),
            'diaktifkan_pada' => Carbon::now(),
        ])->save();

        $this->log->catat(
            AksiLog::AktivasiKiosk,
            "Perangkat kiosk \"{$kiosk->nama_titik}\" diaktifkan dari IP {$request->ip()}.",
            kiosk: $kiosk,
            subjek: $kiosk,
        );

        return ['kiosk' => $kiosk, 'token' => $token];
    }

    /**
     * Lepaskan perangkat: token dicabut sehingga perangkat harus diaktifkan
     * ulang dengan kode baru.
     *
     * `$pelaku` diisi ketika pencabutan datang dari panel admin (FR-USR-02),
     * sehingga audit trail menyebut siapa yang mencabut. Pelepasan dari
     * perangkat itu sendiri tidak punya pelaku berupa akun admin.
     */
    public function lepas(Kiosk $kiosk, ?User $pelaku = null): void
    {
        $kiosk->forceFill([
            'device_token' => null,
            'status' => StatusKiosk::Offline,
        ])->save();

        $this->log->catat(
            AksiLog::LepasKiosk,
            $pelaku === null
                ? "Perangkat absen \"{$kiosk->nama_titik}\" dilepaskan dan device_token dicabut."
                : "Akses perangkat absen \"{$kiosk->nama_titik}\" dicabut dari panel admin.",
            user: $pelaku,
            kiosk: $kiosk,
            subjek: $kiosk,
        );
    }

    /**
     * Cari kiosk aktif pemilik token mentah.
     */
    public function kioskDariToken(?string $token): ?Kiosk
    {
        if ($token === null || $token === '') {
            return null;
        }

        return Kiosk::aktif()
            ->where('device_token', self::hashToken($token))
            ->first();
    }

    /**
     * Perbarui jejak IP dan waktu aktif terakhir perangkat (FR-USR-03),
     * dibatasi agar tidak menulis pada setiap permintaan.
     */
    public function perbaruiJejak(Kiosk $kiosk, Request $request): void
    {
        $ipBerubah = $kiosk->ip_terakhir !== $request->ip();
        $sudahLama = $kiosk->login_terakhir_at === null
            || $kiosk->login_terakhir_at->lt(Carbon::now()->subMinutes(self::JEDA_PEMBARUAN_JEJAK_MENIT));

        if (! $ipBerubah && ! $sudahLama) {
            return;
        }

        $kiosk->forceFill([
            'ip_terakhir' => $request->ip(),
            'status' => StatusKiosk::Online,
            'login_terakhir_at' => Carbon::now(),
        ])->save();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Cookie penyimpan device token (perbaikan M-3).
     *
     * Dirakit di satu tempat, bukan di dua cabang AktivasiController, supaya
     * jalur kode unit kerja dan jalur kode aktivasi tidak dapat berbeda
     * diam-diam.
     *
     * `secure` DISEBUT EKSPLISIT, tidak diwariskan dari config/session.php.
     * Sebelum audit pra-deploy, kedua panggilan `Cookie::make` menyebutkan
     * `httpOnly` dan `sameSite` tetapi membiarkan `secure` jatuh ke bawaan —
     * dan bawaannya `SESSION_SECURE_COOKIE`, yang tidak diatur sama sekali di
     * berkas .env. Token perangkat berumur satu tahun karena itu melintas
     * dalam keadaan terbaca pada setiap permintaan HTTP polos yang tersisa di
     * jaringan kantor.
     *
     * Nilainya mengikuti sambungan yang sedang dipakai, bukan setelan yang
     * dapat terlupa: begitu aplikasi berjalan di HTTPS, cookienya bertanda
     * Secure. Di lingkungan pengembangan yang berjalan di HTTP ia tidak
     * bertanda — sebab cookie Secure tidak akan pernah dikirim balik ke sana,
     * dan perangkat ujinya akan gagal masuk tanpa penjelasan.
     */
    public function cookieToken(string $token, Request $request): CookiePeramban
    {
        return Cookie::make(
            name: self::NAMA_COOKIE,
            value: $token,
            minutes: self::MASA_COOKIE_MENIT,
            secure: $request->secure(),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Kode 8 karakter tanpa huruf/angka yang mudah tertukar (0/O, 1/I).
     */
    protected function kodeAcak(): string
    {
        $abjad = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $kode = '';
            for ($i = 0; $i < 8; $i++) {
                $kode .= $abjad[random_int(0, strlen($abjad) - 1)];
            }
        } while (Kiosk::where('kode_aktivasi', self::hashToken($kode))->exists());

        return $kode;
    }

    public static function formatKode(string $kode): string
    {
        return implode('-', str_split($kode, 4));
    }

    public static function normalkanKode(string $kode): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $kode) ?? '');
    }
}
