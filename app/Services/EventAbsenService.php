<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Models\EventAbsen;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengelolaan event absensi (FR-EVT-01, FR-EVT-02).
 *
 * Cakupan unit kerja sebuah event selalu dinyatakan pada tingkat unit level
 * teratas (UPT/bidang/DISNAKER) — sama dengan satuan yang dikelola admin di
 * Setting Unit Kerja (SDD §3.1). Seksi/subbag di bawahnya ikut secara otomatis
 * karena pencocokan pegawai memakai cakupan turunan.
 */
class EventAbsenService
{
    /**
     * Tabel absensi baru dibuat pada S16. Sebelum tabel itu ada, tidak ada
     * event yang terkunci — dan pemeriksaannya tidak perlu diubah lagi
     * setelah tabelnya lahir.
     */
    protected const TABEL_ABSENSI = 'absensi';

    public function __construct(
        protected SettingAbsenService $setting,
        protected LogAktivitasService $log,
    ) {}

    /**
     * Daftar event yang boleh dilihat pengguna.
     *
     * Admin UPT hanya melihat event yang menyentuh unitnya, termasuk event
     * bercakupan "semua unit" yang secara definisi mencakup unitnya juga.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function daftar(User $pelaku): Collection
    {
        $event = EventAbsen::query()
            ->with(['unitKerja:id,kode,nama', 'pembuat:id,nama'])
            ->when(
                ! $pelaku->lintasUnit(),
                fn ($query) => $query->menyentuhUnit(
                    UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id),
                ),
            )
            ->orderByDesc('tanggal')
            ->orderByDesc('jam_mulai')
            ->get();

        $absensi = $this->jumlahAbsensi($event->pluck('id')->all());

        return $event->map(fn (EventAbsen $satu) => $this->untukLayar(
            $satu,
            $absensi[$satu->id] ?? 0,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, User $pelaku): EventAbsen
    {
        $event = DB::transaction(function () use ($data, $pelaku) {
            $event = EventAbsen::create([
                'nama' => $data['nama'],
                'tanggal' => $data['tanggal'],
                'jam_mulai' => $data['jam_mulai'],
                'toleransi_menit' => $data['toleransi_menit'],
                'cakupan' => $data['cakupan'],
                'dibuat_oleh' => $pelaku->id,
                'catatan' => $data['catatan'] ?? null,
            ]);

            $this->pasangCakupan($event, $data);

            return $event;
        });

        $this->log->catat(
            AksiLog::Buat,
            "Membuat event {$event->nama} ({$this->ringkasCakupan($event)}) pada {$event->tanggal->format('d-m-Y')}.",
            user: $pelaku,
            subjek: $event,
        );

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(EventAbsen $event, array $data, User $pelaku): EventAbsen
    {
        DB::transaction(function () use ($event, $data) {
            $event->update([
                'nama' => $data['nama'],
                'tanggal' => $data['tanggal'],
                'jam_mulai' => $data['jam_mulai'],
                'toleransi_menit' => $data['toleransi_menit'],
                'cakupan' => $data['cakupan'],
                'catatan' => $data['catatan'] ?? null,
            ]);

            $this->pasangCakupan($event, $data);
        });

        $this->log->catat(
            AksiLog::Ubah,
            "Mengubah event {$event->nama} ({$this->ringkasCakupan($event)}).",
            user: $pelaku,
            subjek: $event,
        );

        return $event;
    }

    /**
     * Hapus event secara permanen (hanya bila belum menautkan absensi).
     */
    public function hapus(EventAbsen $event, User $pelaku): void
    {
        $nama = $event->nama;
        $cakupan = $this->ringkasCakupan($event);

        // Baris pivot ikut terhapus lewat cascade pada FK.
        $event->delete();

        $this->log->catat(
            AksiLog::Hapus,
            "Menghapus event {$nama} ({$cakupan}) yang belum memiliki absensi.",
            user: $pelaku,
        );
    }

    /**
     * Event hanya boleh dihapus selama belum ada satu pun absensi tertaut.
     *
     * Statusnya sendiri tidak menentukan: event yang sudah ditutup namun tidak
     * pernah dipakai tetap boleh dibuang, sedangkan event yang sudah menerima
     * satu tap terkunci selamanya karena riwayat absensi menautnya.
     */
    public function dapatDihapus(EventAbsen $event): bool
    {
        return $this->jumlahAbsensi([$event->id])[$event->id] === 0;
    }

    /**
     * Jumlah absensi per event, dalam satu kali agregasi.
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, int>
     */
    protected function jumlahAbsensi(array $eventIds): array
    {
        $kosong = array_fill_keys($eventIds, 0);

        if ($eventIds === [] || ! Schema::hasTable(self::TABEL_ABSENSI)) {
            return $kosong;
        }

        $jumlah = DB::table(self::TABEL_ABSENSI)
            ->selectRaw('event_absen_id, count(*) as jumlah')
            ->whereIn('event_absen_id', $eventIds)
            ->groupBy('event_absen_id')
            ->pluck('jumlah', 'event_absen_id')
            ->all();

        return array_replace($kosong, array_map('intval', $jumlah));
    }

    /**
     * Event aktif lain yang cakupan dan rentang waktunya bertumpang tindih
     * dengan data yang hendak disimpan (FR-EVT-06).
     *
     * Rentang waktu sebuah event diambil dari `jam_mulai` sampai
     * `jam_mulai + toleransi_menit` — jendela ketika tap masih dianggap tepat
     * waktu. Skema tidak menyimpan jam selesai, sehingga jendela inilah
     * definisi kerja "rentang waktu yang sama"; dua apel pada pagi dan sore
     * hari yang sama tetap boleh berdampingan.
     *
     * Cakupan dinilai beririsan bila salah satu pihak bercakupan "semua unit"
     * — yang menurut definisi mencakup segalanya — atau bila pivot unitnya
     * bersinggungan.
     *
     * @param  array<string, mixed>  $data
     */
    public function eventBentrok(array $data, ?EventAbsen $kecuali = null): ?EventAbsen
    {
        $semuaUnit = $data['cakupan'] === CakupanEvent::SemuaUnit->value;
        $unitBaru = $semuaUnit ? [] : array_map('intval', $data['unit_kerja_id'] ?? []);
        [$mulaiBaru, $selesaiBaru] = $this->jendela($data['jam_mulai'], (int) $data['toleransi_menit']);

        return EventAbsen::query()
            ->aktif()
            ->with('unitKerja:id,kode')
            ->whereDate('tanggal', $data['tanggal'])
            ->when($kecuali !== null, fn ($query) => $query->whereKeyNot($kecuali->getKey()))
            ->get()
            ->first(function (EventAbsen $lain) use ($semuaUnit, $unitBaru, $mulaiBaru, $selesaiBaru) {
                [$mulaiLain, $selesaiLain] = $this->jendela(
                    (string) $lain->jam_mulai,
                    $lain->toleransi_menit,
                );

                if ($mulaiBaru > $selesaiLain || $mulaiLain > $selesaiBaru) {
                    return false;
                }

                if ($semuaUnit || $lain->berlakuUntukSemuaUnit()) {
                    return true;
                }

                return $lain->unitKerja->pluck('id')->intersect($unitBaru)->isNotEmpty();
            });
    }

    /**
     * Jendela waktu event dalam menit sejak tengah malam.
     *
     * @return array{0: int, 1: int}
     */
    protected function jendela(string $jamMulai, int $toleransiMenit): array
    {
        [$jam, $menit] = array_map('intval', explode(':', substr($jamMulai, 0, 5)));
        $mulai = $jam * 60 + $menit;

        return [$mulai, $mulai + $toleransiMenit];
    }

    /**
     * Nilai awal formulir event baru.
     *
     * Toleransi mengambil bawaan dari Setting Absen (FR-SET-02); begitu event
     * tersimpan, angkanya berdiri sendiri sehingga perubahan setting global
     * tidak menggeser event yang sudah ada.
     *
     * @return array<string, mixed>
     */
    public function nilaiAwal(): array
    {
        return [
            'toleransi_menit' => $this->setting->ambil()['toleransi_default_menit'],
        ];
    }

    /**
     * Unit kerja yang boleh dipilih pengguna sebagai cakupan event.
     *
     * Admin UPT terbatas pada unit level teratas yang menaunginya (FR-EVT-02).
     *
     * @return Collection<int, UnitKerja>
     */
    public function unitKerjaTersedia(User $pelaku): Collection
    {
        $teratas = UnitKerja::query()
            ->levelTeratas()
            ->aktif()
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama']);

        if ($pelaku->lintasUnit()) {
            return $teratas;
        }

        return $teratas
            ->filter(fn (UnitKerja $unit) => in_array(
                $pelaku->unit_kerja_id,
                UnitKerja::idsDenganTurunan($unit->id),
                true,
            ))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function pasangCakupan(EventAbsen $event, array $data): void
    {
        // Cakupan "semua unit" tidak menyimpan baris pivot sama sekali —
        // menyalin seluruh unit akan basi begitu unit baru disinkronkan
        // dari WORKA.
        $event->unitKerja()->sync(
            $data['cakupan'] === CakupanEvent::SemuaUnit->value ? [] : $data['unit_kerja_id'],
        );

        $event->load('unitKerja:id,kode,nama');
    }

    protected function ringkasCakupan(EventAbsen $event): string
    {
        if ($event->berlakuUntukSemuaUnit()) {
            return 'semua unit';
        }

        return $event->unitKerja->pluck('kode')->implode(', ') ?: 'tanpa unit';
    }

    /**
     * @return array<string, mixed>
     */
    protected function untukLayar(EventAbsen $event, int $jumlahAbsensi = 0): array
    {
        return [
            'id' => $event->id,
            'nama' => $event->nama,
            'tanggal' => $event->tanggal->toDateString(),
            'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
            'toleransi_menit' => $event->toleransi_menit,
            'cakupan' => $event->cakupan->value,
            'cakupan_label' => $event->cakupan->label(),
            'unit_kerja' => $event->unitKerja
                ->map(fn (UnitKerja $unit) => $unit->only(['id', 'kode', 'nama']))
                ->values(),
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'catatan' => $event->catatan,
            'dibuat_oleh' => $event->pembuat?->nama,
            'jumlah_absensi' => $jumlahAbsensi,
            'dapat_dihapus' => $jumlahAbsensi === 0,
        ];
    }
}
