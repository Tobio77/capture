<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Carbon;
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
            ->withCount('kiosk')
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
     * Tutup entry sebuah event (FR-EVT-04).
     *
     * Setelah ditutup, kiosk pada unit terkait tidak lagi menemukan event
     * aktif sehingga tap baru ditolak — lihat {@see self::eventAktifUntukKiosk()}.
     * Perubahan status dicatat pada audit trail (NFR-09).
     */
    public function tutup(EventAbsen $event, User $pelaku): EventAbsen
    {
        $event->update([
            'status' => StatusEvent::Ditutup,
            'ditutup_pada' => Carbon::now(),
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            "Menutup entry event {$event->nama} ({$this->ringkasCakupan($event)}).",
            user: $pelaku,
            subjek: $event,
        );

        return $event;
    }

    /**
     * Event yang sedang melayani sebuah kiosk, atau null bila tidak ada.
     *
     * FR-EVT-06 menjamin paling banyak satu event aktif per unit kerja,
     * sehingga penentuannya tidak pernah ambigu dan kiosk tidak perlu
     * menyebutkan event mana yang dimaksud saat men-tap.
     */
    public function eventAktifUntukKiosk(Kiosk $kiosk): ?EventAbsen
    {
        return EventAbsen::query()
            ->aktif()
            ->with('unitKerja:id,kode,nama')
            ->menyentuhUnit($this->cakupanKiosk($kiosk))
            ->orderByDesc('tanggal')
            ->orderByDesc('jam_mulai')
            ->first();
    }

    /**
     * Catat bahwa sebuah kiosk sedang aktif melayani event (FR-EVT-03).
     *
     * Satu baris per pasangan event × kiosk: `aktif_pada` menahan waktu
     * pertama kali, `terakhir_aktif_pada` dan `ip_address` mengikuti aktivitas
     * terbaru — kiosk dapat berpindah alamat IP dalam satu event.
     *
     * Event yang sudah ditutup tidak lagi dicatat; tidak ada kiosk yang sah
     * "terhubung" ke entry yang sudah selesai.
     */
    public function catatKioskAktif(EventAbsen $event, Kiosk $kiosk, ?string $ip): void
    {
        if (! $event->aktif()) {
            return;
        }

        $sekarang = Carbon::now();

        $tersimpan = DB::table('event_kiosk')
            ->where('event_absen_id', $event->id)
            ->where('kiosk_id', $kiosk->id)
            ->exists();

        if ($tersimpan) {
            DB::table('event_kiosk')
                ->where('event_absen_id', $event->id)
                ->where('kiosk_id', $kiosk->id)
                ->update([
                    'ip_address' => $ip,
                    'terakhir_aktif_pada' => $sekarang,
                ]);

            return;
        }

        DB::table('event_kiosk')->insert([
            'event_absen_id' => $event->id,
            'kiosk_id' => $kiosk->id,
            'ip_address' => $ip,
            'aktif_pada' => $sekarang,
            'terakhir_aktif_pada' => $sekarang,
        ]);
    }

    /**
     * Rincian sebuah event untuk layar detail (FR-EVT-05): daftar kiosk
     * terhubung beserta IP-nya, jumlah absen masuk, dan status entry.
     *
     * @return array<string, mixed>
     */
    public function detail(EventAbsen $event): array
    {
        $event->load(['kiosk:id,nama_titik,unit_kerja_id', 'kiosk.unitKerja:id,kode', 'unitKerja:id,kode,nama']);

        return [
            'id' => $event->id,
            'nama' => $event->nama,
            'tanggal' => $event->tanggal->toDateString(),
            'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'ditutup_pada' => $event->ditutup_pada?->toIso8601String(),
            'cakupan_label' => $event->berlakuUntukSemuaUnit()
                ? 'Semua Unit'
                : $event->unitKerja->pluck('kode')->implode(', '),
            'jumlah_absensi' => $this->jumlahAbsensi([$event->id])[$event->id],
            'kiosk' => $event->kiosk
                ->sortByDesc(fn (Kiosk $kiosk) => $kiosk->pivot->terakhir_aktif_pada)
                ->map(fn (Kiosk $kiosk) => [
                    'id' => $kiosk->id,
                    'nama_titik' => $kiosk->nama_titik,
                    'unit_kerja_kode' => $kiosk->unitKerja?->kode,
                    'ip_address' => $kiosk->pivot->ip_address,
                    'aktif_pada' => $kiosk->pivot->aktif_pada,
                    'terakhir_aktif_pada' => $kiosk->pivot->terakhir_aktif_pada,
                ])
                ->values(),
        ];
    }

    /**
     * Unit kerja kiosk beserta seluruh turunannya, ditambah rantai induknya
     * sampai simpul OPD.
     *
     * Rantai induk diikutkan karena cakupan event dinyatakan pada unit level
     * teratas, sedangkan kiosk bisa saja terdaftar pada seksi di bawahnya.
     *
     * @return array<int, int>
     */
    protected function cakupanKiosk(Kiosk $kiosk): array
    {
        $ids = UnitKerja::idsDenganTurunan($kiosk->unit_kerja_id);
        $unit = UnitKerja::query()->find($kiosk->unit_kerja_id);

        while ($unit?->induk_id !== null) {
            $ids[] = $unit->induk_id;
            $unit = $unit->induk;
        }

        return array_values(array_unique($ids));
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
     * Event aktif lain yang cakupan unit kerjanya beririsan dengan data yang
     * hendak disimpan (FR-EVT-06).
     *
     * Tanggal dan jam sengaja tidak ikut diperiksa: yang menentukan adalah
     * status. Selama dua event sama-sama berstatus aktif dan cakupannya
     * bersinggungan, kiosk pada unit itu menghadapi lebih dari satu event dan
     * tidak dapat memutuskan sebuah tap milik yang mana. Menutup event yang
     * lebih dulu berjalan adalah satu-satunya cara membuka jalan bagi event
     * berikutnya di unit yang sama.
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

        return EventAbsen::query()
            ->aktif()
            ->with('unitKerja:id,kode')
            ->when($kecuali !== null, fn ($query) => $query->whereKeyNot($kecuali->getKey()))
            ->get()
            ->first(function (EventAbsen $lain) use ($semuaUnit, $unitBaru) {
                if ($semuaUnit || $lain->berlakuUntukSemuaUnit()) {
                    return true;
                }

                return $lain->unitKerja->pluck('id')->intersect($unitBaru)->isNotEmpty();
            });
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
            'jumlah_kiosk' => $event->kiosk_count ?? 0,
            'jumlah_absensi' => $jumlahAbsensi,
            'dapat_dihapus' => $jumlahAbsensi === 0,
        ];
    }
}
