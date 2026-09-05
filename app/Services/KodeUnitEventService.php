<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\KodeUnitEvent;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kode unit kerja per event dan penggabungan perangkat absen (FR-EVT-03,
 * revisi S29).
 *
 * Sebelum sesi ini, sebuah perangkat melayani event semata-mata karena unit
 * tempat ia dipasang termasuk cakupan event. Itu membuat setiap perangkat di
 * unit tersebut ikut terseret ke kegiatan yang tidak ada hubungannya dengan
 * ruangan tempat ia berdiri, dan panitia tidak punya cara menyatakan perangkat
 * mana yang benar-benar melayani kegiatannya.
 *
 * Kini keanggotaan dinyatakan eksplisit: tiap unit kerja dalam cakupan event
 * memperoleh satu kode pendek, dan perangkat bergabung dengan mengetikkannya.
 *
 * Mekanisme ini TERPISAH TOTAL dari Mode Terbuka (FR-SET-06). Mode Terbuka
 * hanya menyangkut cara perangkat memperoleh device_token — ia tidak pernah
 * menjadi jalan pintas melewati kode unit kerja. Perangkat ad-hoc yang masuk
 * lewat Mode Terbuka tetap harus mengetikkan kode untuk membuka Absen Event;
 * yang terbuka baginya tanpa kode hanyalah Absen Umum.
 */
class KodeUnitEventService
{
    /** Panjang kode, mengikuti kode aktivasi perangkat (S04). */
    public const int PANJANG_KODE = 8;

    /**
     * Abjad tanpa karakter yang mudah tertukar saat dibacakan: 0/O dan 1/I
     * dibuang, sama seperti keputusan S04 pada kode aktivasi perangkat.
     */
    protected const string ABJAD = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Selaraskan kode event dengan cakupan unit kerjanya.
     *
     * Dipanggil setiap kali event dibuat maupun cakupannya diubah. Unit yang
     * baru masuk cakupan memperoleh kode baru; unit yang keluar kehilangan
     * kodenya. Unit yang tetap MEMPERTAHANKAN kode lamanya — panitia sudah
     * membacakannya kepada petugas, dan menggantinya diam-diam berarti seluruh
     * perangkat di unit itu gagal bergabung tanpa penjelasan.
     *
     * @return Collection<int, KodeUnitEvent>
     */
    public function selaraskan(EventAbsen $event): Collection
    {
        $unitCakupan = $this->unitCakupan($event);

        DB::transaction(function () use ($event, $unitCakupan) {
            KodeUnitEvent::query()
                ->where('event_absen_id', $event->id)
                ->when(
                    $unitCakupan !== [],
                    fn ($query) => $query->whereNotIn('unit_kerja_id', $unitCakupan),
                )
                ->delete();

            $sudahAda = KodeUnitEvent::query()
                ->where('event_absen_id', $event->id)
                ->pluck('unit_kerja_id')
                ->all();

            foreach (array_diff($unitCakupan, $sudahAda) as $unitId) {
                $this->terbitkan($event, (int) $unitId);
            }
        });

        return $this->kodeEvent($event);
    }

    /**
     * Unit kerja yang berhak atas kode pada sebuah event.
     *
     * Cakupan "semua unit" tidak menyimpan baris pivot — menyalin seluruh unit
     * ke pivot akan basi begitu unit baru disinkronkan dari WORKA — sehingga
     * daftarnya dirakit di sini dari unit level teratas yang aktif.
     *
     * @return array<int, int>
     */
    public function unitCakupan(EventAbsen $event): array
    {
        if ($event->cakupan === CakupanEvent::SemuaUnit) {
            return UnitKerja::query()
                ->levelTeratas()
                ->aktif()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $event->unitKerja()
            ->pluck('unit_kerja.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Seluruh kode sebuah event, terurut nama unit kerjanya.
     *
     * @return Collection<int, KodeUnitEvent>
     */
    public function kodeEvent(EventAbsen $event): Collection
    {
        return KodeUnitEvent::query()
            ->with('unitKerja:id,kode,nama')
            ->where('event_absen_id', $event->id)
            ->get()
            ->sortBy(fn (KodeUnitEvent $baris) => $baris->unitKerja?->nama ?? '')
            ->values();
    }

    /**
     * Terbitkan kode baru untuk satu unit kerja pada sebuah event.
     */
    public function terbitkan(EventAbsen $event, int $unitKerjaId): KodeUnitEvent
    {
        return KodeUnitEvent::create([
            'event_absen_id' => $event->id,
            'unit_kerja_id' => $unitKerjaId,
            'kode' => $this->kodeAcak(),
        ]);
    }

    /**
     * Ganti kode sebuah unit kerja pada event (FR-EVT-03).
     *
     * Perangkat yang SUDAH bergabung tetap melayani event: reset menutup pintu
     * bagi yang belum masuk — kode yang telanjur beredar ke luar ruangan —
     * bukan mengusir titik absen yang sedang melayani antrean pegawai di tengah
     * apel. Untuk memutus perangkat tertentu, cabut aksesnya lewat Kelola
     * Perangkat Absen (FR-USR-03).
     */
    public function reset(KodeUnitEvent $kode, User $pelaku): KodeUnitEvent
    {
        $kode->update([
            'kode' => $this->kodeAcak(),
            'direset_oleh' => $pelaku->id,
            'direset_pada' => Carbon::now(),
        ]);

        $kode->load(['unitKerja:id,kode,nama', 'event:id,nama']);

        $this->log->catat(
            AksiLog::Ubah,
            sprintf(
                'Me-reset kode unit %s pada event %s.',
                $kode->unitKerja?->kode ?? 'tanpa unit',
                $kode->event?->nama ?? '-',
            ),
            user: $pelaku,
            subjek: $kode->event,
        );

        return $kode;
    }

    /**
     * Perangkat bergabung ke sebuah event dengan mengetikkan kode unit kerja.
     *
     * Mengembalikan null bila kodenya tidak dikenal atau eventnya sudah
     * ditutup — keduanya dijawab pesan yang sama kepada perangkat, supaya
     * mengetik kode acak tidak dapat dipakai memetakan event mana yang ada.
     */
    public function gabungkan(string $kode, Kiosk $kiosk, Request $request): ?EventAbsen
    {
        $baris = KodeUnitEvent::query()
            ->with(['event', 'unitKerja:id,kode,nama'])
            ->where('kode', self::normalkan($kode))
            ->first();

        $event = $baris?->event;

        if ($event === null || ! $event->aktif() || $event->absenUmum()) {
            $this->log->catat(
                AksiLog::GabungEventGagal,
                'Percobaan bergabung ke event dengan kode yang tidak dikenal atau sudah ditutup dari IP '
                    .$request->ip().'.',
                kiosk: $kiosk,
            );

            return null;
        }

        $this->catatKeanggotaan($event, $kiosk, $baris->unit_kerja_id, $request->ip());

        $this->log->catat(
            AksiLog::GabungEvent,
            sprintf(
                'Perangkat "%s" bergabung ke event %s sebagai unit %s dari IP %s.',
                $kiosk->nama_titik,
                $event->nama,
                $baris->unitKerja?->kode ?? $baris->unit_kerja_id,
                $request->ip(),
            ),
            kiosk: $kiosk,
            subjek: $event,
        );

        return $event;
    }

    /**
     * Tulis (atau perbarui) keanggotaan perangkat pada sebuah event.
     *
     * `aktif_pada` menahan waktu pertama kali perangkat menyentuh event ini
     * dan tidak pernah bergerak lagi; `bergabung_pada` mengikuti penggabungan
     * terakhir. Alamat IP diperbarui setiap kali — satu perangkat dapat
     * berpindah jaringan di tengah kegiatan, dan yang dicari panitia saat
     * menelusuri absen mencurigakan adalah alamat terkininya.
     */
    public function catatKeanggotaan(EventAbsen $event, Kiosk $kiosk, ?int $unitKerjaId, ?string $ip): void
    {
        $sekarang = Carbon::now();

        $terpengaruh = DB::table('event_kiosk')
            ->where('event_absen_id', $event->id)
            ->where('kiosk_id', $kiosk->id)
            ->update([
                'unit_kerja_id' => $unitKerjaId,
                'ip_address' => $ip,
                'bergabung_pada' => $sekarang,
                'terakhir_aktif_pada' => $sekarang,
            ]);

        if ($terpengaruh > 0) {
            return;
        }

        try {
            DB::table('event_kiosk')->insert([
                'event_absen_id' => $event->id,
                'kiosk_id' => $kiosk->id,
                'unit_kerja_id' => $unitKerjaId,
                'ip_address' => $ip,
                'aktif_pada' => $sekarang,
                'bergabung_pada' => $sekarang,
                'terakhir_aktif_pada' => $sekarang,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dua permintaan dari perangkat yang sama berpapasan; barisnya
            // sudah ada, dan itulah yang diinginkan.
        }
    }

    /**
     * Event kegiatan yang sedang dilayani sebuah perangkat karena perangkat itu
     * BERGABUNG lewat kode — bukan sekadar karena unitnya tercakup.
     */
    public function eventYangDiikuti(Kiosk $kiosk): ?EventAbsen
    {
        return EventAbsen::query()
            ->aktif()
            ->kegiatan()
            ->with('unitKerja:id,kode,nama')
            ->whereHas('kiosk', fn ($query) => $query->whereKey($kiosk->getKey()))
            ->orderByDesc('tanggal')
            ->orderByDesc('jam_mulai')
            ->first();
    }

    /**
     * Lepaskan perangkat dari event yang sedang diikutinya.
     */
    public function keluarkan(EventAbsen $event, Kiosk $kiosk): void
    {
        DB::table('event_kiosk')
            ->where('event_absen_id', $event->id)
            ->where('kiosk_id', $kiosk->id)
            ->delete();

        $this->log->catat(
            AksiLog::KeluarEvent,
            'Perangkat "'.$kiosk->nama_titik."\" keluar dari event {$event->nama}.",
            kiosk: $kiosk,
            subjek: $event,
        );
    }

    /**
     * Kode acak yang belum terpakai event mana pun.
     */
    protected function kodeAcak(): string
    {
        do {
            $kode = '';

            for ($i = 0; $i < self::PANJANG_KODE; $i++) {
                $kode .= self::ABJAD[random_int(0, strlen(self::ABJAD) - 1)];
            }
        } while (KodeUnitEvent::query()->where('kode', $kode)->exists());

        return $kode;
    }

    /** Bentuk yang ditampilkan ke admin dan dibacakan kepada petugas. */
    public static function format(string $kode): string
    {
        return implode('-', str_split($kode, 4));
    }

    /** Terima ketikan apa adanya: spasi, tanda hubung, dan huruf kecil. */
    public static function normalkan(string $kode): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $kode) ?? '');
    }
}
