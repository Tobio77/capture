<?php

namespace App\Console\Commands;

use App\Enums\CakupanEvent;
use App\Enums\JenisEvent;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Uji beban ringan: beberapa perangkat absen menembak satu event bersamaan.
 *
 * Bukan uji beban skala besar — Disnakertrans memasang paling banyak beberapa
 * titik absen per unit kerja, dan yang perlu dibuktikan bukan ribuan
 * permintaan per detik melainkan dua hal yang benar-benar terjadi di lapangan:
 *
 *   1. Waktu tanggap tetap di bawah ambang NFR-01 (rata-rata < 3 detik) ketika
 *      beberapa perangkat melayani antrean apel pada saat yang sama.
 *   2. Tidak ada tap yang hilang atau menghasilkan galat ketika dua perangkat
 *      kebetulan melayani orang yang sama dalam hitungan milidetik — pegawai
 *      yang mengira tapnya tidak terbaca lalu mengulang di perangkat sebelah.
 *
 * Permintaannya sungguhan lewat HTTP, bukan panggilan internal, supaya seluruh
 * lapisan ikut terukur: middleware, autentikasi device token, dan basis data.
 */
class UjiBebanAbsenCommand extends Command
{
    protected $signature = 'absen:uji-beban
        {--perangkat=4 : Jumlah perangkat absen yang menembak bersamaan}
        {--putaran=10 : Jumlah putaran tap per perangkat}
        {--url= : Alamat dasar aplikasi; bawaan APP_URL}
        {--rebutan : Seluruh perangkat men-tap pegawai yang sama tiap putaran}
        {--simpan : Jangan hapus data uji setelah selesai}
        {--abai-sertifikat : Lewati verifikasi sertifikat TLS, untuk sertifikat lokal Herd}
        {--paksa : Izinkan berjalan di lingkungan produksi}';

    protected $description = 'Simulasi beberapa perangkat absen aktif bersamaan pada satu event (S27)';

    /**
     * Opsi klien HTTP yang sama untuk seluruh permintaan.
     *
     * @return array<string, mixed>
     */
    protected function opsiKlien(CookieJar $toples): array
    {
        return ['cookies' => $toples, 'verify' => ! $this->option('abai-sertifikat')];
    }

    /** Ambang NFR-01: tap hingga hasil rata-rata di bawah 3 detik. */
    protected const AMBANG_RATA_MS = 3000;

    public function __construct(protected SettingAbsenService $setting)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('paksa')) {
            $this->components->error(
                'Perintah ini membuat event, perangkat, dan absensi uji. Jalankan di lingkungan '.
                'pengembangan, atau tambahkan --paksa bila memang disengaja.',
            );

            return self::FAILURE;
        }

        $dasar = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $jumlahPerangkat = max(1, (int) $this->option('perangkat'));
        $putaran = max(1, (int) $this->option('putaran'));

        $this->components->info("Menyiapkan skenario uji pada {$dasar}");

        $skenario = $this->siapkan($jumlahPerangkat);

        if ($skenario === null) {
            return self::FAILURE;
        }

        ['event' => $event, 'perangkat' => $perangkat, 'pegawai' => $pegawai] = $skenario;

        $this->components->twoColumnDetail('Event', $event->nama);
        $this->components->twoColumnDetail('Perangkat serentak', (string) $jumlahPerangkat);
        $this->components->twoColumnDetail('Putaran per perangkat', (string) $putaran);
        $this->components->twoColumnDetail('Pegawai tersedia', (string) $pegawai->count());
        $this->components->twoColumnDetail(
            'Pola tap',
            $this->option('rebutan')
                ? 'rebutan — semua perangkat men-tap orang yang sama'
                : 'tersebar — tiap perangkat men-tap orang berbeda',
        );

        $this->newLine();

        if (! $this->bukaSesi($dasar, $perangkat)) {
            $this->bersihkan($event, $perangkat);

            return self::FAILURE;
        }

        $ukuran = $this->jalankan($dasar, $perangkat, $pegawai, $putaran);

        $this->laporkan($ukuran, $event);

        $lolos = $this->lolosAmbang($ukuran);

        if (! $this->option('simpan')) {
            $this->bersihkan($event, $perangkat);
            $this->newLine();
            $this->components->info('Data uji dibersihkan. Tambahkan --simpan untuk menahannya.');
        }

        return $lolos ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Buat event uji beserta perangkat yang sudah teraktivasi.
     *
     * Perangkat diaktifkan langsung di sini, bukan lewat alur kode aktivasi,
     * karena yang sedang diuji adalah jalur tap — bukan aktivasinya.
     *
     * @return array{event: EventAbsen, perangkat: array<int, array{kiosk: Kiosk, toples: CookieJar}>, pegawai: Collection<int, Pegawai>}|null
     */
    protected function siapkan(int $jumlahPerangkat): ?array
    {
        $unit = UnitKerja::query()->levelTeratas()->aktif()->first();

        if ($unit === null) {
            $this->components->error(
                'Belum ada unit kerja level teratas. Jalankan `php artisan db:seed` lebih dahulu.',
            );

            return null;
        }

        $pegawai = Pegawai::query()
            ->where('aktif', true)
            ->whereIn('unit_kerja_id', UnitKerja::idsDenganTurunan($unit->id))
            ->get();

        if ($pegawai->isEmpty()) {
            $this->components->error(
                "Belum ada pegawai aktif pada {$unit->nama}. Jalankan sinkronisasi atau seeder lebih dahulu.",
            );

            return null;
        }

        $event = EventAbsen::create([
            'nama' => 'Uji Beban '.Carbon::now()->format('d M Y H:i:s'),
            'jenis' => JenisEvent::Kegiatan,
            'tanggal' => Carbon::today()->toDateString(),

            // Jam mulai jauh di depan supaya seluruh tap terhitung tepat waktu
            // dan hasilnya tidak bergantung pada jam berapa perintah dijalankan.
            'jam_mulai' => '00:01:00',
            'toleransi_menit' => 1439,
            'cakupan' => CakupanEvent::Unit,
            'status' => StatusEvent::Aktif,
            'catatan' => 'Dibuat otomatis oleh perintah absen:uji-beban.',
        ]);

        $event->unitKerja()->attach($unit->id);

        $perangkat = [];

        foreach (range(1, $jumlahPerangkat) as $nomor) {
            $token = Str::random(64);

            $kiosk = Kiosk::create([
                'nama_titik' => "Uji Beban #{$nomor}",
                'unit_kerja_id' => $unit->id,
                'aktif' => true,
            ]);

            $kiosk->forceFill([
                'device_token' => KioskService::hashToken($token),
                'diaktifkan_pada' => Carbon::now(),
            ])->save();

            $perangkat[] = ['kiosk' => $kiosk, 'toples' => $this->toples($token)];
        }

        return ['event' => $event, 'perangkat' => $perangkat, 'pegawai' => $pegawai];
    }

    /**
     * Bungkus device token sebagaimana middleware EncryptCookies membacanya.
     *
     * Cookie Laravel bukan sekadar nilai terenkripsi: di depannya ada awalan
     * yang mengikat cookie pada namanya, supaya nilai satu cookie tidak dapat
     * dipindahkan ke cookie lain. Tanpa awalan itu, middleware membuang
     * nilainya dan setiap permintaan berakhir sebagai pengalihan ke aktivasi.
     */
    protected function cookieTerenkripsi(string $token): string
    {
        $nama = KioskService::NAMA_COOKIE;

        return Crypt::encrypt(
            CookieValuePrefix::create($nama, Crypt::getKey()).$token,
            serialize: false,
        );
    }

    /**
     * Toples cookie milik satu perangkat simulasi.
     *
     * Perangkat sungguhan adalah peramban: ia menyimpan cookie sesi dan token
     * CSRF antar permintaan. Tanpa toples yang sama, setiap POST ditolak 419
     * karena tokennya tidak pernah cocok dengan sesi mana pun.
     */
    protected function toples(string $token): CookieJar
    {
        $toples = new CookieJar;

        $toples->setCookie(new SetCookie([
            'Name' => KioskService::NAMA_COOKIE,
            'Value' => $this->cookieTerenkripsi($token),
            'Domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            'Path' => '/',
        ]));

        return $toples;
    }

    /**
     * Buka sesi tiap perangkat: satu kunjungan ke layar absen, persis seperti
     * peramban yang baru dinyalakan, sehingga cookie sesi dan XSRF-TOKEN
     * masuk ke toplesnya.
     *
     * @param  array<int, array{kiosk: Kiosk, toples: CookieJar}>  $perangkat
     */
    protected function bukaSesi(string $dasar, array $perangkat): bool
    {
        foreach ($perangkat as $satu) {
            $jawaban = Http::withOptions($this->opsiKlien($satu['toples']))
                ->timeout(30)
                ->get("{$dasar}/kiosk");

            if ($jawaban->failed()) {
                $this->components->error(
                    "Perangkat simulasi tidak dapat membuka layar absen (HTTP {$jawaban->status()}). ".
                    'Pastikan aplikasi berjalan pada alamat yang diberikan.',
                );

                return false;
            }
        }

        return true;
    }

    /**
     * Token CSRF milik satu perangkat, dibaca dari toplesnya.
     *
     * Nilainya dikirim apa adanya pada header X-XSRF-TOKEN; Laravel yang
     * mendekripsinya, sama seperti yang dilakukan layar absen di peramban.
     */
    protected function tokenCsrf(CookieJar $toples): string
    {
        foreach ($toples as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                return urldecode($cookie->getValue());
            }
        }

        return '';
    }

    /**
     * Jalankan seluruh putaran dan kumpulkan waktu tanggapnya.
     *
     * Satu putaran = seluruh perangkat menembak bersamaan lewat satu kolam
     * permintaan. Itulah bentuk beban yang sebenarnya terjadi: bukan satu
     * perangkat menembak beruntun, melainkan beberapa perangkat berbarengan.
     *
     * @param  array<int, array{kiosk: Kiosk, toples: CookieJar}>  $perangkat
     * @param  Collection<int, Pegawai>  $pegawai
     * @return array<string, array<int, array{ms: float, status: int}>>
     */
    protected function jalankan(string $dasar, array $perangkat, $pegawai, int $putaran): array
    {
        $ukuran = ['identifikasi' => [], 'absen' => [], 'presensi' => []];
        $bilah = $this->output->createProgressBar($putaran * 3);

        $bilah->start();

        for ($ronde = 0; $ronde < $putaran; $ronde++) {
            foreach (['identifikasi', 'absen', 'presensi'] as $tahap) {
                $hasil = $this->tembakSerentak($dasar, $perangkat, $pegawai, $ronde, $tahap);

                $ukuran[$tahap] = array_merge($ukuran[$tahap], $hasil);
                $bilah->advance();
            }
        }

        $bilah->finish();
        $this->newLine(2);

        return $ukuran;
    }

    /**
     * Satu tahap, seluruh perangkat serentak.
     *
     * @param  array<int, array{kiosk: Kiosk, toples: CookieJar}>  $perangkat
     * @param  Collection<int, Pegawai>  $pegawai
     * @return array<int, array{ms: float, status: int}>
     */
    protected function tembakSerentak(
        string $dasar,
        array $perangkat,
        $pegawai,
        int $ronde,
        string $tahap,
    ): array {
        $mulai = microtime(true);

        $jawaban = Http::pool(function (Pool $kolam) use ($dasar, $perangkat, $pegawai, $ronde, $tahap) {
            $permintaan = [];

            foreach ($perangkat as $nomor => $satu) {
                $orang = $this->pegawaiUntuk($pegawai, $nomor, $ronde);

                $klien = $kolam
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'X-XSRF-TOKEN' => $this->tokenCsrf($satu['toples']),
                    ])
                    ->withOptions($this->opsiKlien($satu['toples']))
                    ->timeout(30);

                $permintaan[] = match ($tahap) {
                    'identifikasi' => $klien->post("{$dasar}/kiosk/tap/identifikasi", [
                        'id_card' => $orang->nip,
                    ]),
                    'absen' => $klien->post("{$dasar}/kiosk/absen", array_filter([
                        'id_card' => $orang->nip,
                        'jenis' => 'datang',
                        'metode' => 'manual',

                        /*
                         * Server memeriksa ulang skor terhadap ambang Setting
                         * Absen (FR-TAP-06); tanpa skor, seluruh tap ditolak
                         * 422 dan yang terukur cuma jalur penolakan.
                         */
                        'skor' => $this->skorWajah(),
                    ], fn ($nilai) => $nilai !== null)),
                    'presensi' => $klien->get("{$dasar}/kiosk/presensi"),
                };
            }

            return $permintaan;
        });

        // Kolam berjalan paralel, sehingga waktu satu putaran dibagi rata —
        // yang diukur adalah biaya per permintaan pada beban serentak itu.
        $selisih = (microtime(true) - $mulai) * 1000;

        $hasil = [];

        foreach ($jawaban as $satu) {
            $hasil[] = [
                'ms' => $selisih,
                'status' => method_exists($satu, 'status') ? $satu->status() : 0,
            ];
        }

        return $hasil;
    }

    /**
     * Skor kecocokan wajah yang disertakan tiap tap, atau null bila admin
     * mematikan verifikasi wajah.
     *
     * Diambil dari Setting Absen dan dinaikkan satu poin di atas ambang:
     * yang diuji beban, bukan ketatnya pencocokan.
     */
    protected function skorWajah(): ?float
    {
        $setting = $this->setting->ambil();

        return $setting['metode_wajah_aktif']
            ? min(100, $setting['ambang_kecocokan_wajah'] + 1)
            : null;
    }

    /**
     * Pegawai yang di-tap perangkat tertentu pada putaran tertentu.
     *
     * Mode rebutan sengaja mengarahkan seluruh perangkat ke orang yang sama:
     * itulah kondisi yang menguji kunci unik (event, pegawai, jenis) dan
     * penanganan tabrakannya.
     *
     * @param  Collection<int, Pegawai>  $pegawai
     */
    protected function pegawaiUntuk($pegawai, int $nomorPerangkat, int $ronde): Pegawai
    {
        $indeks = $this->option('rebutan')
            ? $ronde % $pegawai->count()
            : ($ronde * 7 + $nomorPerangkat) % $pegawai->count();

        return $pegawai[$indeks];
    }

    /**
     * @param  array<string, array<int, array{ms: float, status: int}>>  $ukuran
     */
    protected function laporkan(array $ukuran, EventAbsen $event): void
    {
        $baris = [];

        foreach ($ukuran as $tahap => $hasil) {
            $ms = array_column($hasil, 'ms');
            sort($ms);

            $gagal = count(array_filter($hasil, fn (array $s) => $s['status'] >= 400 || $s['status'] === 0));

            $baris[] = [
                $tahap,
                count($hasil),
                $this->angka($this->rata($ms)),
                $this->angka($this->persentil($ms, 50)),
                $this->angka($this->persentil($ms, 95)),
                $this->angka(end($ms) ?: 0),
                $gagal === 0 ? '0' : "<fg=red>{$gagal}</>",
                $this->rinciStatus($hasil),
            ];
        }

        $this->table(
            ['Tahap', 'Permintaan', 'Rata (ms)', 'p50 (ms)', 'p95 (ms)', 'Maks (ms)', 'Gagal', 'Status'],
            $baris,
        );

        $tercatat = DB::table('absensi')->where('event_absen_id', $event->id)->count();
        $unik = DB::table('absensi')
            ->where('event_absen_id', $event->id)
            ->distinct()
            ->count(DB::raw('CONCAT(pegawai_id, "-", jenis)'));

        $this->components->twoColumnDetail('Baris absensi tercatat', (string) $tercatat);
        $this->components->twoColumnDetail(
            'Baris duplikat (event × pegawai × jenis)',
            $tercatat === $unik ? '0' : '<fg=red>'.($tercatat - $unik).'</>',
        );
    }

    /**
     * Sebaran kode status, supaya kegagalan dapat ditelusuri tanpa membuka
     * log — 429 berarti throttle, 422 berarti muatan ditolak, 302 berarti
     * autentikasi perangkat tidak terbaca.
     *
     * @param  array<int, array{ms: float, status: int}>  $hasil
     */
    protected function rinciStatus(array $hasil): string
    {
        $sebaran = array_count_values(array_column($hasil, 'status'));

        ksort($sebaran);

        return implode(' ', array_map(
            fn (int $kode, int $jumlah) => $kode >= 400 || $kode === 0
                ? "<fg=red>{$kode}×{$jumlah}</>"
                : "{$kode}×{$jumlah}",
            array_keys($sebaran),
            $sebaran,
        ));
    }

    /**
     * @param  array<string, array<int, array{ms: float, status: int}>>  $ukuran
     */
    protected function lolosAmbang(array $ukuran): bool
    {
        $semua = array_merge(...array_values($ukuran));
        $gagal = count(array_filter($semua, fn (array $s) => $s['status'] >= 400 || $s['status'] === 0));
        $rata = $this->rata(array_column($semua, 'ms'));

        $this->newLine();

        if ($gagal > 0) {
            $this->components->error("{$gagal} permintaan gagal. Periksa log aplikasi.");

            return false;
        }

        if ($rata > self::AMBANG_RATA_MS) {
            $this->components->error(sprintf(
                'Rata-rata %s ms melampaui ambang NFR-01 (%d ms).',
                $this->angka($rata),
                self::AMBANG_RATA_MS,
            ));

            return false;
        }

        $this->components->info(sprintf(
            'Lolos: rata-rata %s ms, di bawah ambang NFR-01 (%d ms), tanpa permintaan gagal.',
            $this->angka($rata),
            self::AMBANG_RATA_MS,
        ));

        return true;
    }

    /**
     * @param  array<int, array{kiosk: Kiosk, toples: CookieJar}>  $perangkat
     */
    protected function bersihkan(EventAbsen $event, array $perangkat): void
    {
        // Absensi ikut terhapus lewat cascade pada event.
        $event->delete();

        foreach ($perangkat as $satu) {
            $satu['kiosk']->delete();
        }
    }

    /** @param  array<int, float>  $nilai */
    protected function rata(array $nilai): float
    {
        return $nilai === [] ? 0.0 : array_sum($nilai) / count($nilai);
    }

    /** @param  array<int, float>  $terurut */
    protected function persentil(array $terurut, int $persen): float
    {
        if ($terurut === []) {
            return 0.0;
        }

        $indeks = (int) ceil(($persen / 100) * count($terurut)) - 1;

        return $terurut[max(0, $indeks)];
    }

    protected function angka(float $ms): string
    {
        return number_format($ms, 1, ',', '.');
    }
}
