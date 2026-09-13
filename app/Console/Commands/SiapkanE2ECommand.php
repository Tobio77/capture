<?php

namespace App\Console\Commands;

use Database\Seeders\PegawaiSeeder;
use Database\Seeders\UnitKerjaSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Siapkan basis data untuk pengujian end-to-end (Playwright).
 *
 * Menyegarkan skema lalu mengisinya dengan data contoh yang SAMA dipakai
 * pengembangan lokal (unit kerja, akun admin, pegawai) — lihat
 * `tests/e2e/data.js` untuk salinan kredensial dan NIP yang dipakai test.
 * Kiosk dan event kegiatan SENGAJA tidak ikut di-seed: test sendiri yang
 * mendaftarkan dan mengaktifkannya lewat Panel Admin/layar aktivasi, supaya
 * yang teruji betul jalur yang dipakai admin sungguhan.
 *
 * Perintah ini MENGHAPUS SELURUH ISI basis data yang sedang aktif — pagar di
 * {@see self::basisDataAman()} menolaknya berjalan kecuali koneksinya SQLite
 * dan nama berkasnya memuat "e2e", supaya kesalahan konfigurasi (mis. lupa
 * mengatur `DB_DATABASE` sebelum memanggil ini) tidak berakhir menimpa
 * database pengembangan `capture` — insiden yang pernah benar-benar terjadi
 * lewat `tinker` pada sesi pengembangan Laporan Resmi.
 */
class SiapkanE2ECommand extends Command
{
    protected $signature = 'e2e:siapkan';

    protected $description = 'Segarkan dan isi basis data khusus pengujian E2E (Playwright) — TIDAK boleh diarahkan ke database pengembangan';

    public function handle(): int
    {
        if (! $this->basisDataAman()) {
            $this->error(sprintf(
                'Ditolak: koneksi basis data aktif adalah "%s" (%s). Perintah ini hanya boleh berjalan terhadap berkas SQLite yang namanya memuat "e2e" — atur DB_CONNECTION=sqlite dan DB_DATABASE ke berkas semacam itu sebelum memanggilnya.',
                config('database.default'),
                config('database.connections.'.config('database.default').'.database') ?? '?',
            ));

            return self::FAILURE;
        }

        $this->info('Menyegarkan basis data E2E...');
        Artisan::call('migrate:fresh', ['--force' => true]);

        foreach ([UnitKerjaSeeder::class, UserSeeder::class, PegawaiSeeder::class] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $this->info('Basis data E2E siap: unit kerja, akun admin, dan pegawai contoh sudah terisi.');

        return self::SUCCESS;
    }

    protected function basisDataAman(): bool
    {
        $koneksi = config('database.default');

        if ($koneksi !== 'sqlite') {
            return false;
        }

        $berkas = (string) config('database.connections.sqlite.database');

        return $berkas !== ':memory:' && str_contains(strtolower($berkas), 'e2e');
    }
}
