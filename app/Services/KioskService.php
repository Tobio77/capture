<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\StatusKiosk;
use App\Models\Kiosk;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aktivasi dan autentikasi perangkat kiosk (FR-AUTH-01, FR-USR-03, NFR-03).
 *
 * Kiosk didaftarkan lebih dulu oleh Superadmin/Admin Dinas (FR-USR-02); sistem
 * menerbitkan kode aktivasi sekali pakai, lalu perangkat menukarkan kode itu
 * dengan device_token miliknya sendiri.
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
     * Terbitkan kode aktivasi sekali pakai untuk kiosk yang sudah didaftarkan admin.
     * Mengembalikan kode dalam bentuk yang ditampilkan ke admin (mis. "7K4M-92XQ").
     */
    public function terbitkanKodeAktivasi(Kiosk $kiosk): string
    {
        $kode = $this->kodeAcak();

        $kiosk->forceFill([
            'kode_aktivasi' => $kode,
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

        $kiosk = Kiosk::aktif()->where('kode_aktivasi', $kode)->first();

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
     * Lepaskan perangkat: token dicabut sehingga kiosk harus diaktifkan ulang.
     */
    public function lepas(Kiosk $kiosk): void
    {
        $kiosk->forceFill([
            'device_token' => null,
            'status' => StatusKiosk::Offline,
        ])->save();

        $this->log->catat(
            AksiLog::LepasKiosk,
            "Perangkat kiosk \"{$kiosk->nama_titik}\" dilepaskan dan device_token dicabut.",
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
        } while (Kiosk::where('kode_aktivasi', $kode)->exists());

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
