<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengelolaan event absensi (FR-EVT-01, FR-EVT-02).
 *
 * **Sejak S49 sebuah event selalu berlaku bagi SELURUH dinas.** Tidak ada lagi
 * event yang dibuka hanya untuk UPT A atau bidang B: absensi diselenggarakan
 * Dinas Tenaga Kerja dan Transmigrasi, dan setiap pegawai dari unit mana pun
 * berhak mencatat kehadirannya pada kegiatan yang sedang berjalan.
 *
 * Unit kerja tidak hilang dari sistem — ia berpindah peran. Ia tidak lagi
 * menentukan SIAPA YANG BOLEH mengabsen, melainkan menjadi dimensi pembacaan:
 * penyaring dan pengelompokan pada Rekap dan Laporan, serta penanda asal
 * perangkat yang melayani sebuah tap (lihat {@see KodeUnitService}).
 *
 * Akibatnya FR-EVT-06 menyederhana: karena setiap event mencakup segalanya,
 * dua event aktif SELALU beririsan, sehingga hanya boleh ada satu kegiatan
 * yang menerima tap pada satu waktu. Yang berikutnya menunggu giliran.
 */
class EventAbsenService
{
    /**
     * Tabel absensi baru dibuat pada S16. Sebelum tabel itu ada, tidak ada
     * event yang terkunci — dan pemeriksaannya tidak perlu diubah lagi
     * setelah tabelnya lahir.
     */
    protected const TABEL_ABSENSI = 'absensi';

    /** Jumlah baris per halaman pada Daftar Event. */
    public const int PER_HALAMAN = 15;

    public function __construct(
        protected SettingAbsenService $setting,
        protected LogAktivitasService $log,
    ) {}

    /**
     * Daftar event yang boleh dilihat pengguna.
     *
     * Tidak ada lagi penyaringan per peran di sini: event berlaku bagi seluruh
     * dinas, sehingga setiap admin melihat daftar yang sama. Yang masih
     * dibedakan peran adalah hak MENGUBAHNYA (lihat EventController) dan
     * cakupan pegawai pada rekapnya (FR-REK-02).
     *
     * @param  array<string, mixed>  $filter  cari, status, dari, sampai
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function daftar(User $pelaku, array $filter = []): LengthAwarePaginator
    {
        $halaman = $this->kueriDaftar($filter)
            ->paginate(self::PER_HALAMAN)
            ->withQueryString();

        $absensi = $this->jumlahAbsensi($halaman->getCollection()->pluck('id')->all());

        return $halaman->through(fn (EventAbsen $satu) => $this->untukLayar(
            $satu,
            $absensi[$satu->id] ?? 0,
        ));
    }

    /**
     * Seluruh event hasil penyaringan, tanpa paginasi — dipakai ekspor, yang
     * harus memuat semuanya, bukan halaman yang sedang dibuka.
     *
     * @param  array<string, mixed>  $filter
     * @return Collection<int, array<string, mixed>>
     */
    public function semua(User $pelaku, array $filter = []): Collection
    {
        $event = $this->kueriDaftar($filter)->get();
        $absensi = $this->jumlahAbsensi($event->pluck('id')->all());

        return $event->map(fn (EventAbsen $satu) => $this->untukLayar(
            $satu,
            $absensi[$satu->id] ?? 0,
        ));
    }

    /**
     * Seluruh event sebagai pilihan ringkas — dipakai pemilih Rekap Event,
     * yang membutuhkan daftar utuh, bukan satu halaman.
     *
     * @param  array<string, mixed>  $filter  hanya dari/sampai yang berarti di sini —
     *                                        dipakai pemilih event Rekap Event (Bagian 4)
     *                                        untuk mempersempit isi comboboxnya.
     * @return Collection<int, array<string, mixed>>
     */
    public function opsiEvent(User $pelaku, array $filter = []): Collection
    {
        return $this->kueriDaftar($filter)
            ->get()
            ->map(fn (EventAbsen $event) => [
                'id' => $event->id,
                'nama' => $event->nama,
                'tanggal' => $event->tanggal->toDateString(),
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return Builder<EventAbsen>
     */
    protected function kueriDaftar(array $filter = []): Builder
    {
        return EventAbsen::query()
            /*
             * Sesi absen umum harian tidak ikut: ia dibuka sistem, bukan
             * admin, dan punya menunya sendiri. Membiarkannya masuk akan
             * memenuhi Daftar Event dengan satu baris per hari.
             */
            ->kegiatan()
            ->with('pembuat:id,nama')
            ->withCount('kiosk')
            ->when(
                filled($filter['cari'] ?? null),
                fn ($query) => $query->where(function ($q) use ($filter) {
                    $q->where('nama', 'like', '%'.$filter['cari'].'%')
                        ->orWhere('catatan', 'like', '%'.$filter['cari'].'%');
                }),
            )
            ->when(
                filled($filter['status'] ?? null),
                fn ($query) => $query->where('status', $filter['status']),
            )
            ->when(
                filled($filter['dari'] ?? null),
                fn ($query) => $query->whereDate('tanggal', '>=', $filter['dari']),
            )
            ->when(
                filled($filter['sampai'] ?? null),
                fn ($query) => $query->whereDate('tanggal', '<=', $filter['sampai']),
            )
            ->orderByDesc('tanggal')
            ->orderByDesc('jam_mulai');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, User $pelaku): EventAbsen
    {
        $event = EventAbsen::create([
            'nama' => $data['nama'],
            'tanggal' => $data['tanggal'],
            'jam_mulai' => $data['jam_mulai'],
            'toleransi_menit' => $data['toleransi_menit'],
            'cakupan' => CakupanEvent::SemuaUnit,
            'dibuat_oleh' => $pelaku->id,
            'catatan' => $data['catatan'] ?? null,
        ]);

        $this->log->catat(
            AksiLog::Buat,
            "Membuat event {$event->nama} pada {$event->tanggal->format('d-m-Y')}, berlaku bagi seluruh unit kerja.",
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
        $event->update([
            'nama' => $data['nama'],
            'tanggal' => $data['tanggal'],
            'jam_mulai' => $data['jam_mulai'],
            'toleransi_menit' => $data['toleransi_menit'],
            'catatan' => $data['catatan'] ?? null,
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            "Mengubah event {$event->nama}.",
            user: $pelaku,
            subjek: $event,
        );

        return $event;
    }

    /**
     * Tutup entry sebuah event (FR-EVT-04).
     *
     * Setelah ditutup, perangkat absen tidak lagi menemukan event aktif
     * sehingga tap baru ditolak — lihat {@see self::eventAktifSekarang()}.
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
            "Menutup entry event {$event->nama}.",
            user: $pelaku,
            subjek: $event,
        );

        return $event;
    }

    /**
     * Event kegiatan yang sedang menerima tap, atau null bila tidak ada.
     *
     * Satu jawaban untuk seluruh dinas — bukan lagi per perangkat. Sampai S48
     * pertanyaannya adalah "event mana yang diikuti perangkat ini", dijawab
     * dari kode unit kerja yang pernah diketikkannya; sejak event selalu
     * mencakup seluruh dinas, pertanyaan itu tidak punya jawaban yang berbeda
     * antar perangkat. Yang tersisa adalah "apakah ada kegiatan yang sedang
     * dibuka", dan {@see self::eventBentrok()} menjamin jawabannya paling
     * banyak satu.
     */
    public function eventAktifSekarang(): ?EventAbsen
    {
        return EventAbsen::query()
            ->aktif()
            ->kegiatan()
            ->orderByDesc('tanggal')
            ->orderByDesc('jam_mulai')
            ->first();
    }

    /**
     * Catat perangkat yang sedang melayani sebuah event (FR-EVT-03, FR-EVT-05).
     *
     * Berbeda dari sebelum S49, fungsi ini MENYISIPKAN baris bila belum ada:
     * keanggotaan tidak lagi lahir dari penukaran kode per event — kode kini
     * menempel pada unit kerja dan hanya dipakai sekali untuk memperkenalkan
     * perangkat ({@see KodeUnitService}). Perangkat yang sudah dikenali
     * otomatis melayani kegiatan yang sedang berjalan, dan barisnya lahir saat
     * ia membuka layar Absen Event.
     *
     * Jumlah perangkat per unit tidak dibatasi; masing-masing memperoleh
     * barisnya sendiri beserta alamat IP terkininya — satu perangkat dapat
     * berpindah jaringan di tengah kegiatan, dan yang dicari panitia saat
     * menelusuri absen mencurigakan adalah alamat terakhirnya.
     *
     * Event yang sudah ditutup tidak lagi dicatat; tidak ada perangkat yang sah
     * "terhubung" ke entry yang sudah selesai.
     */
    public function catatKioskAktif(EventAbsen $event, Kiosk $kiosk, ?string $ip): void
    {
        if (! $event->aktif()) {
            return;
        }

        $sekarang = Carbon::now();

        $terpengaruh = DB::table('event_kiosk')
            ->where('event_absen_id', $event->id)
            ->where('kiosk_id', $kiosk->id)
            ->update([
                'unit_kerja_id' => $kiosk->unit_kerja_id,
                'ip_address' => $ip,
                'terakhir_aktif_pada' => $sekarang,
            ]);

        if ($terpengaruh > 0) {
            return;
        }

        try {
            DB::table('event_kiosk')->insert([
                'event_absen_id' => $event->id,
                'kiosk_id' => $kiosk->id,
                'unit_kerja_id' => $kiosk->unit_kerja_id,
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
     * Rincian sebuah event untuk layar detail (FR-EVT-05): daftar perangkat
     * yang melayaninya beserta unit dan IP-nya, jumlah absen masuk, dan
     * status entry.
     *
     * Daftar perangkat inilah jawaban atas "komputer mana saja yang dipakai
     * pada kegiatan ini, dari unit mana, dan dari alamat berapa" — pertanyaan
     * yang sebelumnya dijawab setengah-setengah oleh daftar kode per unit.
     *
     * @return array<string, mixed>
     */
    public function detail(EventAbsen $event): array
    {
        $event->load(['kiosk:id,nama_titik,unit_kerja_id', 'kiosk.unitKerja:id,kode,nama']);

        return [
            'id' => $event->id,
            'nama' => $event->nama,
            'tanggal' => $event->tanggal->toDateString(),
            'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'ditutup_pada' => $event->ditutup_pada?->toIso8601String(),
            'jumlah_absensi' => $this->jumlahAbsensi([$event->id])[$event->id],
            'kiosk' => $event->kiosk
                ->sortByDesc(fn (Kiosk $kiosk) => $kiosk->pivot->terakhir_aktif_pada)
                ->map(fn (Kiosk $kiosk) => [
                    'id' => $kiosk->id,
                    'nama_titik' => $kiosk->nama_titik,
                    'unit_kerja_kode' => $kiosk->unitKerja?->kode,
                    'unit_kerja_nama' => $kiosk->unitKerja?->nama,
                    'ip_address' => $kiosk->pivot->ip_address,
                    'aktif_pada' => $kiosk->pivot->aktif_pada,
                    'bergabung_pada' => $kiosk->pivot->bergabung_pada,
                    'terakhir_aktif_pada' => $kiosk->pivot->terakhir_aktif_pada,
                ])
                ->values(),
        ];
    }

    /**
     * Unit kerja yang tercakup sebuah event — sejak S49 selalu seluruhnya.
     *
     * Dipertahankan sebagai fungsi tersendiri, bukan diganti pemanggilan
     * langsung ke seluruh unit pada tiap pemakainya: event lama masih membawa
     * nilai cakupan yang lebih sempit pada kolomnya, dan di sinilah satu-
     * satunya tempat keputusan "cakupan lama tidak lagi membatasi siapa pun"
     * perlu diterangkan.
     *
     * @return array<int, int>
     */
    public function unitTercakup(EventAbsen $event): array
    {
        return UnitKerja::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Hapus event secara permanen (hanya bila belum menautkan absensi).
     */
    public function hapus(EventAbsen $event, User $pelaku): void
    {
        $nama = $event->nama;

        // Baris pivot ikut terhapus lewat cascade pada FK.
        $event->delete();

        $this->log->catat(
            AksiLog::Hapus,
            "Menghapus event {$nama} yang belum memiliki absensi.",
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
     * Event kegiatan lain yang masih aktif (FR-EVT-06).
     *
     * Tanggal dan jam sengaja tidak ikut diperiksa: yang menentukan adalah
     * status. Karena setiap event kini mencakup seluruh dinas, dua event aktif
     * selalu beririsan — sebuah perangkat tidak akan tahu tap yang diterimanya
     * milik kegiatan yang mana. Menutup event yang lebih dulu berjalan adalah
     * satu-satunya jalan membuka giliran berikutnya.
     */
    public function eventBentrok(?EventAbsen $kecuali = null): ?EventAbsen
    {
        return EventAbsen::query()
            ->aktif()

            // Sesi absen umum tidak pernah menghalangi kegiatan: keduanya dua
            // layar terpisah yang berjalan berdampingan (lihat AbsenUmumService).
            ->kegiatan()
            ->when($kecuali !== null, fn ($query) => $query->whereKeyNot($kecuali->getKey()))
            ->first();
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
