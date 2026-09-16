<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode perangkat per unit kerja — permanen, menggantikan kode per event.
 *
 * Sampai S48 kode yang dipakai perangkat absen lahir dan mati bersama sebuah
 * event: satu baris `event_kode_unit` per unit per kegiatan, diterbitkan saat
 * event dibuat dan tidak berguna lagi setelah ditutup. Panitia karena itu
 * harus membacakan kode BARU kepada setiap UPT pada setiap kegiatan, dan
 * perangkat yang sudah melayani apel pagi tetap harus mengetik ulang untuk
 * rapat sore harinya.
 *
 * Kode kini menempel pada UNIT KERJA dan tidak berubah dengan sendirinya: ia
 * adalah tanda pengenal unit itu di mata aplikasi, dipakai sebuah perangkat
 * sekali saja untuk memperkenalkan diri ("saya komputer milik BLK Surabaya").
 * Sesudah itu perangkat dikenali pada Absen Umum maupun Absen Event tanpa
 * mengetik apa pun lagi, dan alamat IP-nya tercatat pada tiap absensi yang
 * dilayaninya.
 *
 * Superadmin dan Admin Dinas dapat menggantinya — satu-satunya keadaan yang
 * membutuhkannya adalah kode yang telanjur tersebar ke luar unit — dan
 * penggantian itu meninggalkan jejak siapa serta kapan, sebab ia memutus
 * seluruh perangkat yang BELUM masuk pada unit tersebut.
 */
return new class extends Migration
{
    /**
     * Abjad tanpa karakter yang mudah tertukar saat dibacakan (0/O, 1/I),
     * mengikuti keputusan S04 pada kode aktivasi perangkat.
     *
     * Disalin ke sini alih-alih dipinjam dari KodeUnitService: migration yang
     * sudah berjalan di produksi harus tetap berperilaku sama walau service-
     * nya kelak berubah.
     */
    protected const ABJAD = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    protected const PANJANG = 8;

    public function up(): void
    {
        Schema::table('unit_kerja', function (Blueprint $table) {
            // Nullable: unit nonaktif dan seksi/subbag di bawah UPT tidak
            // pernah menjadi tempat perangkat dipasang, dan menerbitkan kode
            // bagi semuanya hanya memperbesar daftar yang harus dijaga admin.
            $table->string('kode_perangkat', 8)->nullable()->unique()->after('nama');

            $table->foreignId('kode_perangkat_direset_oleh')->nullable()
                ->after('kode_perangkat')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('kode_perangkat_direset_pada')->nullable()
                ->after('kode_perangkat_direset_oleh');
        });

        $this->terbitkanUntukUnitTeratas();
    }

    /**
     * Terbitkan kode bagi setiap unit level teratas yang aktif.
     *
     * Tanpa ini tidak ada satu pun perangkat yang dapat memperkenalkan diri
     * setelah rilis: jalur lama (kode per event) sudah tidak ada, dan jalur
     * barunya belum punya satu kode pun untuk ditukarkan.
     *
     * "Level teratas" = anak langsung simpul OPD (lihat SDD §3.1). Selama
     * WORKA belum pernah disinkronkan, simpul OPD belum ada; pada keadaan itu
     * unit tanpa induk yang dianggap level teratas — perlakuan yang sama
     * dengan `UnitKerja::scopeLevelTeratas()`.
     */
    protected function terbitkanUntukUnitTeratas(): void
    {
        $opd = DB::table('unit_kerja')
            ->where('kode', config('services.worka.kode_opd'))
            ->value('id');

        $unit = DB::table('unit_kerja')
            ->where('aktif', true)
            ->when(
                $opd === null,
                fn ($query) => $query->whereNull('induk_id'),
                fn ($query) => $query->where('induk_id', $opd),
            )
            ->pluck('id');

        $terpakai = [];

        foreach ($unit as $id) {
            $kode = $this->kodeAcak($terpakai);
            $terpakai[$kode] = true;

            DB::table('unit_kerja')->where('id', $id)->update(['kode_perangkat' => $kode]);
        }
    }

    /**
     * @param  array<string, true>  $terpakai
     */
    protected function kodeAcak(array $terpakai): string
    {
        do {
            $kode = '';

            for ($i = 0; $i < self::PANJANG; $i++) {
                $kode .= self::ABJAD[random_int(0, strlen(self::ABJAD) - 1)];
            }
        } while (isset($terpakai[$kode]));

        return $kode;
    }

    public function down(): void
    {
        Schema::table('unit_kerja', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kode_perangkat_direset_oleh');
            $table->dropUnique(['kode_perangkat']);
            $table->dropColumn(['kode_perangkat', 'kode_perangkat_direset_pada']);
        });
    }
};
