<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menjadikan "satu sesi absen umum per unit kerja per tanggal" dijamin basis
 * data, bukan sekadar diusahakan kode.
 *
 * Sesi harian dibuka sendiri saat tap pertama. Dua titik absen di unit yang
 * sama yang men-tap pertama kali dalam hitungan milidetik sama-sama melihat
 * "sesi belum ada", dan keduanya membuatnya — lalu tap berikutnya jatuh ke
 * salah satu dari dua sesi itu secara tak tentu. Akibatnya penolakan tap ganda
 * (FR-TAP-05) tidak pernah kena: baris keduanya tercatat pada sesi yang
 * berbeda, dan kehadiran satu orang tampil dua kali.
 *
 * Cakupan sesi umum tersimpan pada tabel pivot, sehingga kunci uniknya tidak
 * dapat dirakit dari kolom yang ada. Kolom ini menyimpan penandanya sebagai
 * satu nilai — `umum:{unit_kerja_id}:{YYYY-MM-DD}` — dan null untuk event
 * kegiatan, yang memang boleh berapa pun jumlahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->string('kunci_sesi', 64)->nullable()->unique()->after('jenis');
        });

        $this->isiSesiLama();
    }

    /**
     * Isi penanda bagi sesi umum yang sudah ada.
     *
     * Tanpa ini, sesi hari ini tidak lagi ditemukan pencariannya yang baru dan
     * sistem akan membuka sesi kedua — kehadiran yang sudah tercatat pagi tadi
     * seolah lenyap dari layar sore harinya.
     *
     * Sesi kembar yang mungkin telanjur lahir sebelum kunci unik ada
     * dipertahankan datanya: hanya yang tertua yang memperoleh penanda,
     * sehingga sisanya tetap dapat dibuka lewat Rekap Absen tetapi tidak lagi
     * menerima tap baru.
     */
    protected function isiSesiLama(): void
    {
        $sesi = DB::table('event_absen')
            ->join('event_unit_kerja', 'event_unit_kerja.event_absen_id', '=', 'event_absen.id')
            ->where('event_absen.jenis', 'umum')
            ->orderBy('event_absen.id')
            ->get([
                'event_absen.id',
                'event_absen.tanggal',
                'event_unit_kerja.unit_kerja_id',
            ]);

        $terpakai = [];

        foreach ($sesi as $baris) {
            $kunci = 'umum:'.$baris->unit_kerja_id.':'.substr((string) $baris->tanggal, 0, 10);

            if (isset($terpakai[$kunci])) {
                continue;
            }

            $terpakai[$kunci] = true;

            DB::table('event_absen')->where('id', $baris->id)->update(['kunci_sesi' => $kunci]);
        }
    }

    public function down(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->dropUnique(['kunci_sesi']);
            $table->dropColumn('kunci_sesi');
        });
    }
};
