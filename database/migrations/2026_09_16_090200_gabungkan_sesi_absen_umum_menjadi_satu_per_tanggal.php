<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sesi Absen Umum menjadi SATU per tanggal untuk seluruh dinas.
 *
 * Sampai S48 sesi harian dipecah per unit kerja: `kunci_sesi` berbentuk
 * `umum:<unit_kerja_id>:<tanggal>`, satu baris per UPT/bidang per hari.
 * Pemecahan itu tidak pernah menjawab pertanyaan siapa pun — absen umum
 * terbuka bagi setiap pegawai dinas tanpa kecuali — dan justru melahirkan
 * pekerjaan yang harus diulang belasan kali: membuka sesi, menutupnya, dan
 * memasang override satu-satu per unit.
 *
 * Kunci sesi karena itu menyusut menjadi `umum:<tanggal>`, dan seluruh sesi
 * lama pada tanggal yang sama DILEBUR menjadi satu. Peleburan ini menulis
 * ulang data historis, dan itu memang yang dikehendaki: rekap lama harus
 * terbaca dengan aturan yang sama dengan rekap baru, bukan menyisakan dua
 * bentuk data yang menuntut dua jalur pembacaan selamanya.
 *
 * Yang dilebur:
 *
 *   - `absensi` dipindahkan ke sesi induk. Kunci unik (event, pegawai, jenis)
 *     dapat berbenturan ketika seorang pegawai sempat mengabsen pada dua sesi
 *     unit berbeda di hari yang sama — mungkin terjadi pada pegawai yang
 *     pindah unit, atau pada perangkat yang salah unit. Yang dipertahankan
 *     adalah tap yang LEBIH AWAL; kehadiran dinilai dari jam datang pertama,
 *     dan yang belakangan tidak pernah menjadi bukti apa pun.
 *   - `event_kiosk` dipindahkan, membuang duplikat pasangan event x perangkat.
 *   - `event_unit_kerja` dibuang: sesi harian tidak lagi menaut unit mana pun.
 *   - Override yang paling belakangan dipasang menang, sebab itulah keputusan
 *     admin yang terakhir berlaku pada hari itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tanggalSemua = DB::table('event_absen')
            ->where('jenis', 'umum')
            ->distinct()
            ->orderBy('tanggal')
            ->pluck('tanggal');

        foreach ($tanggalSemua as $tanggal) {
            DB::transaction(fn () => $this->leburTanggal($tanggal));
        }
    }

    protected function leburTanggal(string $tanggal): void
    {
        $hari = Carbon::parse($tanggal);
        $kunciBaru = 'umum:'.$hari->toDateString();

        $sesi = DB::table('event_absen')
            ->where('jenis', 'umum')
            ->whereDate('tanggal', $hari->toDateString())
            ->orderBy('id')
            ->get();

        /*
         * Induk peleburan.
         *
         * Yang lahir dari KODE BARU selalu menang, walau bukan yang tertua.
         * Keadaan itu nyata: begitu kode dirilis sebelum migration ini
         * dijalankan — beda menit saja sudah cukup — sesi hari itu lahir
         * langsung berkunci `umum:<tanggal>` dan berdampingan dengan belasan
         * sesi per-unit yang lama. Memilih yang tertua sebagai induk lalu
         * menulis kunci itu padanya menabrak indeks unik milik sesi baru, dan
         * migration berhenti di tengah jalan — persis kegagalan yang
         * memunculkan pagar ini.
         *
         * Selebihnya sesi tertua yang menjadi induk: id terkecil berarti sesi
         * yang pertama dibuka hari itu, dan mempertahankannya membuat urutan
         * riwayat tetap masuk akal bila kelak ditelusuri.
         */
        $induk = $sesi->firstWhere('kunci_sesi', $kunciBaru) ?? $sesi->first();
        $lain = $sesi->reject(fn ($satu) => (int) $satu->id === (int) $induk->id);

        foreach ($lain as $satu) {
            $this->pindahkanAbsensi((int) $satu->id, (int) $induk->id);
            $this->pindahkanKiosk((int) $satu->id, (int) $induk->id);
        }

        DB::table('event_unit_kerja')->whereIn('event_absen_id', $sesi->pluck('id'))->delete();

        $override = $this->overrideTerakhir($sesi);

        DB::table('event_absen')->where('id', $induk->id)->update([
            'kunci_sesi' => $kunciBaru,
            'nama' => 'Absen Umum — '.$hari->translatedFormat('d F Y'),
            'cakupan' => 'semua_unit',
            'override_absen' => $override['override_absen'],
            'override_oleh' => $override['override_oleh'],
            'override_pada' => $override['override_pada'],
        ]);

        if ($lain->isNotEmpty()) {
            DB::table('event_absen')->whereIn('id', $lain->pluck('id'))->delete();
        }
    }

    /**
     * Pindahkan absensi sebuah sesi ke sesi induk, membuang tap yang lebih
     * belakangan bila pegawai dan jenisnya sudah terisi di sana.
     */
    protected function pindahkanAbsensi(int $dari, int $ke): void
    {
        $baris = DB::table('absensi')->where('event_absen_id', $dari)->get();

        foreach ($baris as $satu) {
            $tandingan = DB::table('absensi')
                ->where('event_absen_id', $ke)
                ->where('pegawai_id', $satu->pegawai_id)
                ->where('jenis', $satu->jenis)
                ->first();

            if ($tandingan === null) {
                DB::table('absensi')->where('id', $satu->id)->update(['event_absen_id' => $ke]);

                continue;
            }

            // Yang lebih awal bertahan; yang belakangan dibuang seluruhnya.
            if (Carbon::parse($satu->waktu)->lt(Carbon::parse($tandingan->waktu))) {
                DB::table('absensi')->where('id', $tandingan->id)->delete();
                DB::table('absensi')->where('id', $satu->id)->update(['event_absen_id' => $ke]);

                continue;
            }

            DB::table('absensi')->where('id', $satu->id)->delete();
        }
    }

    protected function pindahkanKiosk(int $dari, int $ke): void
    {
        $baris = DB::table('event_kiosk')->where('event_absen_id', $dari)->get();

        foreach ($baris as $satu) {
            $sudahAda = DB::table('event_kiosk')
                ->where('event_absen_id', $ke)
                ->where('kiosk_id', $satu->kiosk_id)
                ->exists();

            $sudahAda
                ? DB::table('event_kiosk')->where('id', $satu->id)->delete()
                : DB::table('event_kiosk')->where('id', $satu->id)->update(['event_absen_id' => $ke]);
        }
    }

    /**
     * Override yang paling belakangan dipasang di antara sesi sehari itu.
     *
     * @param  Collection<int, object>  $sesi
     * @return array{override_absen: ?string, override_oleh: ?int, override_pada: ?string}
     */
    protected function overrideTerakhir($sesi): array
    {
        $terakhir = $sesi
            ->filter(fn ($satu) => $satu->override_absen !== null)
            ->sortByDesc(fn ($satu) => $satu->override_pada ?? '')
            ->first();

        return [
            'override_absen' => $terakhir->override_absen ?? null,
            'override_oleh' => $terakhir->override_oleh ?? null,
            'override_pada' => $terakhir->override_pada ?? null,
        ];
    }

    /**
     * Peleburan tidak dapat dibatalkan: sesi per unit yang sudah menyatu tidak
     * menyimpan jejak unit asalnya, dan absensi yang terbuang tidak disalin ke
     * mana pun. Mengembalikan bentuk lama berarti menebak — dan tebakan pada
     * catatan kehadiran lebih buruk daripada tidak ada jalan mundur sama
     * sekali. Pulihkan dari backup bila bentuk lama benar-benar dibutuhkan.
     */
    public function down(): void
    {
        // Sengaja tidak melakukan apa pun; lihat catatan di atas.
    }
};
