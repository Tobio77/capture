<?php

namespace Tests\Feature\Kiosk;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penjaga kesegaran prop tiruan uji asap sisi klien.
 *
 * Uji asap Vitest (`tests/js/layar-kiosk.test.js`) merender tiap layar titik
 * absen memakai prop tiruan pada `tests/js/prop-layar.json`. Nilai uji itu
 * bergantung sepenuhnya pada prop tiruannya menyerupai keluaran controller
 * sungguhan — dan berkas tiruan adalah hal yang paling mudah menjadi usang:
 * ia tidak pernah gagal sendiri, hanya diam-diam menguji dunia yang sudah
 * tidak ada.
 *
 * Uji ini membandingkan KUNCI keduanya. Menambah prop di controller tanpa
 * memasukkannya ke berkas tiruan akan memerahkan uji ini, bukan menghasilkan
 * uji asap yang lulus untuk layar yang sudah berbeda.
 *
 * Nilainya sengaja tidak dibandingkan: prop tiruan memang perlu bebas memilih
 * keadaan yang hendak digambar (perangkat ad-hoc, jendela tertutup, absen
 * umum dimatikan), dan menyamakan nilainya akan mengunci kebebasan itu tanpa
 * menambah jaminan apa pun.
 */
class PropLayarKioskTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-prop';

    protected UnitKerja $upt;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);
        $this->travelTo('2026-09-07 07:35:00');

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        $this->admin = User::factory()->superadmin()->create();

        app(SettingAbsenService::class)->simpan(['metode_wajah_aktif' => false], $this->admin);
    }

    /**
     * @return array<string, mixed>
     */
    protected function tiruan(): array
    {
        $berkas = base_path('tests/js/prop-layar.json');

        $this->assertFileExists($berkas, 'Prop tiruan uji asap sisi klien tidak ditemukan.');

        return json_decode(file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Bandingkan kunci prop sebuah layar dengan kunci prop tiruannya.
     *
     * @param  array<string, mixed>  $tiruan
     */
    protected function bandingkan(
        TestResponse $jawaban,
        array $tiruan,
        string $layar,
        ?string $sarang = null,
    ): void {
        $props = $jawaban->viewData('page')['props'];
        $isi = $sarang === null ? $props : $props[$sarang];
        $diminta = array_keys($tiruan);

        /*
         * Prop bersama ikut pada setiap halaman, dan yang dibandingkan hanya
         * prop halamannya sendiri. Daftarnya dibaca dari middleware-nya, bukan
         * ditulis ulang di sini: daftar salinan akan menua diam-diam, persis
         * seperti berkas tiruan yang hendak dijaga uji ini. ('errors'
         * ditambahkan Inertia sendiri, di luar middleware.)
         *
         * Nama yang MEMANG diminta layar itu tidak ikut disingkirkan. Halaman
         * aktivasi contohnya: `mode_terbuka` dikirim controller sebagai prop
         * halaman sekaligus ada sebagai prop bersama, dan menyingkirkannya
         * menurut nama saja akan membuat uji ini menuntut berkas tiruan
         * menghilangkan prop yang sungguh-sungguh dipakai.
         */
        $bersama = array_diff(
            array_merge(array_keys(app(HandleInertiaRequests::class)->share(request())), ['errors']),
            $diminta,
        );

        $nyata = array_values(array_diff(array_keys($isi), $sarang === null ? $bersama : []));

        sort($nyata);
        sort($diminta);

        $this->assertSame(
            $nyata,
            $diminta,
            "Prop {$layar} berubah; perbarui tests/js/prop-layar.json agar uji asap "
            .'sisi klien tetap merender layar yang sama dengan yang dikirim server.',
        );
    }

    #[Test]
    public function prop_halaman_depan_sama_dengan_tiruannya(): void
    {
        $jawaban = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)->get('/');

        $this->bandingkan($jawaban, $this->tiruan()['beranda'], 'Beranda');
    }

    #[Test]
    public function prop_layar_aktivasi_sama_dengan_tiruannya(): void
    {
        $jawaban = $this->get('/kiosk/aktivasi');

        $this->bandingkan($jawaban, $this->tiruan()['aktivasi'], 'Kiosk/Aktivasi');
    }

    #[Test]
    public function prop_layar_tap_perangkat_sama_dengan_tiruannya(): void
    {
        $jawaban = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)->get('/kiosk/umum');

        $this->bandingkan($jawaban, $this->tiruan()['kiosk_utama'], 'Kiosk/Utama');
    }

    #[Test]
    public function prop_layar_absen_umum_admin_sama_dengan_tiruannya(): void
    {
        $jawaban = $this->actingAs($this->admin)
            ->get("/admin/kelola-absen/absen-umum/layar?unit_kerja_id={$this->upt->id}");

        $this->bandingkan($jawaban, $this->tiruan()['absen_umum_layar'], 'AbsenUmum/Layar');
    }

    #[Test]
    public function prop_bersama_kiosk_sama_dengan_tiruannya(): void
    {
        /*
         * Justru di sinilah cacat layar putih itu hidup: `kiosk` bukan prop
         * halaman melainkan prop bersama, dan uji asap hanya dapat menjaringnya
         * bila prop bersama tiruannya berbentuk sama dengan yang sungguhan.
         */
        $jawaban = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)->get('/kiosk/umum');

        $this->bandingkan(
            $jawaban,
            $this->tiruan()['bersama']['kiosk_terdaftar'],
            'bersama kiosk',
            sarang: 'kiosk',
        );
    }

    #[Test]
    public function kegiatan_memakai_prop_yang_sama_dengan_absen_umum(): void
    {
        /*
         * Satu halaman melayani dua mode, dan uji asap merendernya dengan
         * berkas tiruan yang sama. Kalau kelak keduanya berbeda prop, tiruan
         * tunggal itu berhenti mewakili salah satunya.
         */
        $kegiatan = EventAbsen::factory()->create(['nama' => 'Apel Pagi Senin']);
        $kegiatan->unitKerja()->attach($this->upt);
        $this->gabungkanKeEvent($kegiatan, Kiosk::query()->sole());

        $umum = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)->get('/kiosk/umum');
        $event = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)->get('/kiosk/event');

        $this->assertSame(
            array_keys($umum->viewData('page')['props']),
            array_keys($event->viewData('page')['props']),
        );
    }
}
