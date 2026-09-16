<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\AktivasiKioskRequest;
use App\Services\KioskService;
use App\Services\KodeUnitService;
use App\Services\SettingAbsenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Masuknya sebuah komputer menjadi titik absen (UIUX §4.1).
 *
 * Satu layar, dua jalur — yang berlaku ditentukan Mode Pendaftaran Perangkat
 * (FR-SET-06):
 *
 *   - Mode mati (bawaan): petugas mengetikkan KODE UNIT KERJA tempat mesin itu
 *     berdiri. Perangkatnya dibuatkan sendiri oleh sistem, tertaut ke unit
 *     tersebut, dengan alamat IP tercatat.
 *   - Mode menyala: perangkat harus sudah didaftarkan admin, dan yang
 *     diketikkan adalah KODE AKTIVASI sekali pakai miliknya.
 *
 * Keduanya berakhir sama: satu device_token dalam cookie, dan perangkat
 * dipulangkan ke halaman depan untuk memilih Absen Umum atau Absen Event.
 */
class AktivasiController extends Controller
{
    public function __construct(
        protected KioskService $kiosk,
        protected KodeUnitService $kodeUnit,
        protected SettingAbsenService $setting,
    ) {}

    /**
     * Layar masuk perangkat.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->kiosk->kioskDariToken($request->cookie(KioskService::NAMA_COOKIE))) {
            return redirect()->route('beranda');
        }

        return Inertia::render('Kiosk/Aktivasi', [
            /*
             * Menentukan kode mana yang diminta layar, dan bunyi penjelasannya.
             * Daftar unit kerja sengaja TIDAK ikut dikirim pada kedua mode:
             * mesin yang belum memegang kode apa pun tidak berkepentingan
             * mengetahui unit kerja mana saja yang ada, dan kodenyalah yang
             * menentukan — bukan pilihan pada sebuah daftar.
             */
            'mode_pendaftaran' => $this->setting->pendaftaranPerangkatAktif(),
            'panjang_kode' => KodeUnitService::PANJANG_KODE,
        ]);
    }

    /**
     * Tukarkan kode unit kerja dengan device_token perangkat (FR-EVT-03).
     *
     * Pemeriksaan modenya diulang di sini, bukan hanya di layar: layar hanyalah
     * tampilan, dan permintaan ini dapat dikirim langsung oleh siapa pun yang
     * tahu alamatnya.
     */
    public function unit(Request $request): RedirectResponse
    {
        abort_if(
            $this->setting->pendaftaranPerangkatAktif(),
            403,
            'Mode Pendaftaran Perangkat sedang menyala. Perangkat harus didaftarkan admin dan memakai kode aktivasi.',
        );

        $data = $request->validate(
            ['kode' => ['required', 'string', 'max:32']],
            ['kode.required' => 'Kode unit kerja wajib diisi.'],
        );

        $unitKerja = $this->kodeUnit->unitDariKode($data['kode']);

        if ($unitKerja === null) {
            /*
             * Kode yang salah dan kode milik unit yang dinonaktifkan dijawab
             * pesan yang sama. Membedakannya mengubah kolom ini menjadi alat
             * menebak: penebak yang diberi tahu "unitnya nonaktif" sudah
             * mengetahui bahwa ia menemukan kode yang benar.
             */
            throw ValidationException::withMessages([
                'kode' => 'Kode unit kerja tidak dikenal. Mintakan kode terbaru kepada admin dinas.',
            ]);
        }

        ['token' => $token] = $this->kiosk->masukDenganKodeUnit($unitKerja, $request);

        return redirect()
            ->route('beranda')
            ->with('sukses', "Perangkat dikenali sebagai titik absen {$unitKerja->nama}.")
            ->withCookie($this->kiosk->cookieToken($token, $request));
    }

    /**
     * Tukarkan kode aktivasi sekali pakai dengan device_token perangkat.
     */
    public function store(AktivasiKioskRequest $request): RedirectResponse
    {
        abort_unless(
            $this->setting->pendaftaranPerangkatAktif(),
            403,
            'Mode Pendaftaran Perangkat sedang dimatikan. Masuk memakai kode unit kerja.',
        );

        ['token' => $token] = $this->kiosk->aktifkan(
            $request->string('kode_aktivasi')->toString(),
            $request,
        );

        return redirect()
            ->route('beranda')
            ->with('sukses', 'Perangkat berhasil diaktifkan.')
            ->withCookie($this->kiosk->cookieToken($token, $request));
    }

    /**
     * Lepaskan perangkat dari titik absen ini dan cabut token-nya.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->kiosk->lepas($request->kiosk());

        return redirect()
            ->route('kiosk.aktivasi')
            ->with('sukses', 'Perangkat telah dilepaskan. Masukkan kode untuk menggunakannya kembali.')
            ->withCookie(Cookie::forget(KioskService::NAMA_COOKIE));
    }
}
