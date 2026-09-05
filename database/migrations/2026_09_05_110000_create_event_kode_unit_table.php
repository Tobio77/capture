<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode unit kerja per event (FR-EVT-03, revisi S29).
 *
 * Sampai sekarang perangkat absen jatuh ke sebuah event semata-mata karena
 * unit tempat ia dipasang termasuk cakupan event itu. Akibatnya setiap
 * perangkat di unit tersebut — termasuk perangkat ad-hoc yang masuk lewat Mode
 * Terbuka — langsung melayani kegiatan yang tidak ada hubungannya dengan
 * ruangan tempat ia berdiri, dan panitia tidak punya cara menyatakan
 * "perangkat inilah yang melayani apel pagi".
 *
 * Setiap unit kerja dalam cakupan sebuah event karena itu memperoleh satu kode
 * pendek. Perangkat bergabung dengan mengetikkan kode itu; sebelum bergabung,
 * layar Absen Event tidak dapat dibuka sama sekali. Satu kode boleh dipakai
 * lebih dari satu perangkat — satu unit kerap membuka beberapa meja registrasi
 * pada kegiatan yang sama — dan alamat IP setiap perangkat tercatat pada
 * `event_kiosk` beserta jejaknya di audit trail.
 *
 * Kodenya sengaja tidak disimpan sebagai hash. Berbeda dari device_token,
 * kode ini justru harus dapat DIBACA ULANG admin untuk dibacakan kepada
 * petugas di ruangan lain, dan masa hidupnya hanya selama event berlangsung.
 */
return new class extends Migration
{
    /**
     * Abjad tanpa karakter yang mudah tertukar saat dibacakan (0/O, 1/I),
     * mengikuti keputusan S04 pada kode aktivasi perangkat.
     *
     * Disalin ke sini alih-alih dipinjam dari KodeUnitEventService: migration
     * yang sudah berjalan di produksi harus tetap berperilaku sama walau
     * service-nya kelak berubah.
     */
    protected const ABJAD = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    protected const PANJANG = 8;

    public function up(): void
    {
        Schema::create('event_kode_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_absen_id')->constrained('event_absen')->cascadeOnDelete();
            $table->foreignId('unit_kerja_id')->constrained('unit_kerja')->cascadeOnDelete();

            // 8 karakter, ditampilkan berpasangan empat (mis. "7K4M-92XQ")
            // mengikuti kode aktivasi perangkat (S04).
            $table->string('kode', 8)->unique();

            // Jejak reset: kode lama tidak disimpan, tetapi pertanyaan "kapan
            // dan oleh siapa kode ini terakhir diganti" harus terjawab.
            $table->foreignId('direset_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('direset_pada')->nullable();

            $table->timestamps();

            // Satu kode per unit per event.
            $table->unique(['event_absen_id', 'unit_kerja_id']);
        });

        $this->isiEventBerjalan();
    }

    /**
     * Terbitkan kode bagi event kegiatan yang masih aktif saat rilis.
     *
     * Tanpa ini, event yang sedang berjalan pada hari rilis mendadak kehilangan
     * seluruh titik absennya: keanggotaan kini lahir dari penukaran kode, dan
     * event lama tidak punya satu kode pun untuk ditukarkan. Panitia baru akan
     * menyadarinya saat pegawai pertama gagal mengabsen.
     *
     * Cakupan "semua unit" tidak menyimpan baris pivot, sehingga daftar unitnya
     * dirakit dari unit level teratas yang aktif — anak langsung simpul OPD,
     * sama seperti yang dipakai KodeUnitEventService.
     */
    protected function isiEventBerjalan(): void
    {
        $event = DB::table('event_absen')
            ->where('status', 'aktif')
            ->where('jenis', 'kegiatan')
            ->get(['id', 'cakupan']);

        if ($event->isEmpty()) {
            return;
        }

        $unitSemua = $this->unitLevelTeratas();
        $sekarang = Carbon::now();
        $terpakai = [];

        foreach ($event as $satu) {
            $unitIds = $satu->cakupan === 'semua_unit'
                ? $unitSemua
                : DB::table('event_unit_kerja')
                    ->where('event_absen_id', $satu->id)
                    ->pluck('unit_kerja_id')
                    ->all();

            foreach ($unitIds as $unitId) {
                $kode = $this->kodeAcak($terpakai);
                $terpakai[$kode] = true;

                DB::table('event_kode_unit')->insert([
                    'event_absen_id' => $satu->id,
                    'unit_kerja_id' => $unitId,
                    'kode' => $kode,
                    'created_at' => $sekarang,
                    'updated_at' => $sekarang,
                ]);
            }
        }
    }

    /**
     * Unit level teratas yang aktif: anak langsung simpul OPD (lihat SDD §3.1).
     *
     * Selama WORKA belum pernah disinkronkan, simpul OPD belum ada; pada
     * keadaan itu unit tanpa induk yang dianggap level teratas — perlakuan yang
     * sama dengan `UnitKerja::scopeLevelTeratas()`.
     *
     * @return array<int, int>
     */
    protected function unitLevelTeratas(): array
    {
        $opd = DB::table('unit_kerja')
            ->where('kode', config('services.worka.kode_opd'))
            ->value('id');

        return DB::table('unit_kerja')
            ->where('aktif', true)
            ->when(
                $opd === null,
                fn ($query) => $query->whereNull('induk_id'),
                fn ($query) => $query->where('induk_id', $opd),
            )
            ->pluck('id')
            ->all();
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
        Schema::dropIfExists('event_kode_unit');
    }
};
