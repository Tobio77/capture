<?php

namespace Tests\Feature;

use App\Enums\SumberKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\KodeUnitService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kode perangkat per unit kerja (FR-EVT-03, revisi S49).
 *
 * Sampai S48 kode yang dipakai perangkat absen lahir dan mati bersama sebuah
 * event: satu baris per unit per kegiatan. Panitia karena itu harus
 * membacakan kode BARU kepada setiap UPT pada setiap kegiatan, dan perangkat
 * yang sudah melayani apel pagi tetap harus mengetik ulang untuk rapat sore
 * harinya.
 *
 * Kode kini menempel pada UNIT KERJA dan tidak berubah dengan sendirinya. Ia
 * tanda pengenal unit itu di mata aplikasi: sebuah komputer mengetikkannya
 * sekali, lalu dikenali sebagai perangkat unit tersebut — pada Absen Umum
 * maupun Absen Event, tanpa mengetik apa pun lagi.
 *
 * Berkas ini mengunci mekanisme itu beserta batas-batasnya.
 */
class KodeUnitKerjaTest extends TestCase
{
    use RefreshDatabase;

    protected const URL_UNIT = '/admin/kelola-absen/unit-kerja';

    protected UnitKerja $opd;

    protected UnitKerja $upt;

    protected UnitKerja $uptLain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $this->opd->id]);
        $this->uptLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK', 'induk_id' => $this->opd->id]);
    }

    /** Kode mentah sebuah unit, dibaca ulang dari basis data. */
    protected function kode(UnitKerja $unit): string
    {
        return $unit->fresh()->kode_perangkat;
    }

    #[Test]
    public function setiap_unit_kerja_memperoleh_kode_yang_berbeda(): void
    {
        $semua = collect([$this->opd, $this->upt, $this->uptLain])
            ->map(fn (UnitKerja $unit) => $this->kode($unit));

        $this->assertCount(3, $semua->filter());
        $this->assertCount(3, $semua->unique(), 'Kode unit kerja harus unik.');
    }

    #[Test]
    public function kode_tidak_memakai_karakter_yang_mudah_tertukar(): void
    {
        // 0/O dan 1/I dibuang supaya kode dapat dibacakan lewat telepon tanpa
        // salah dengar (keputusan S04 pada kode aktivasi perangkat).
        $this->assertMatchesRegularExpression(
            '/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{8}$/',
            $this->kode($this->upt),
        );
    }

    #[Test]
    public function kode_tidak_berubah_dengan_sendirinya(): void
    {
        $sebelum = $this->kode($this->upt);

        // Beberapa tindakan yang dulu menerbitkan ulang kode: mengubah unit,
        // dan membuat unit lain. Keduanya tidak boleh menyentuh kode yang
        // sudah dibacakan kepada petugas.
        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL_UNIT.'/'.$this->upt->id, [
                'kode' => 'BLK-SBY',
                'nama' => 'UPT BLK Surabaya (nama baru)',
            ])
            ->assertSessionHas('sukses');

        UnitKerja::factory()->create(['induk_id' => $this->opd->id]);

        $this->assertSame($sebelum, $this->kode($this->upt));
    }

    #[Test]
    public function unit_baru_langsung_memperoleh_kodenya(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL_UNIT, ['kode' => 'UPT-BARU', 'nama' => 'UPT Baru'])
            ->assertSessionHas('sukses');

        $baru = UnitKerja::query()->where('kode', 'UPT-BARU')->sole();

        $this->assertNotNull(
            $baru->kode_perangkat,
            'Unit tanpa kode tidak dapat menerima perangkat apa pun, dan itu baru ketahuan di lokasi.',
        );
    }

    #[Test]
    public function perangkat_masuk_dengan_kode_unit_dan_ip_nya_tercatat(): void
    {
        $jawaban = $this->withServerVariables(['REMOTE_ADDR' => '10.10.5.7'])
            ->post('/kiosk/aktivasi/unit', ['kode' => $this->kode($this->upt)]);

        $jawaban->assertRedirect('/')->assertSessionHas('sukses');

        $kiosk = Kiosk::query()->sole();

        $this->assertSame($this->upt->id, $kiosk->unit_kerja_id);
        $this->assertSame('10.10.5.7', $kiosk->ip_terakhir);
        $this->assertSame(SumberKiosk::AdHoc, $kiosk->sumber);
        $this->assertNotNull($kiosk->device_token);
    }

    #[Test]
    public function kode_diterima_apa_adanya_walau_ditulis_dengan_tanda_hubung(): void
    {
        $terformat = KodeUnitService::format($this->kode($this->upt));

        $this->assertStringContainsString('-', $terformat);

        $this->post('/kiosk/aktivasi/unit', ['kode' => strtolower($terformat)])
            ->assertSessionHas('sukses');

        $this->assertSame($this->upt->id, Kiosk::query()->sole()->unit_kerja_id);
    }

    #[Test]
    public function jumlah_perangkat_per_unit_tidak_dibatasi(): void
    {
        // Sebuah UPT boleh memakai tiga komputer hari Rabu dan empat hari
        // Kamis; masing-masing memperoleh barisnya sendiri beserta IP-nya.
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->flushSession();

            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/kiosk/aktivasi/unit', ['kode' => $this->kode($this->upt)])
                ->assertSessionHas('sukses');
        }

        $perangkat = Kiosk::query()->where('unit_kerja_id', $this->upt->id)->get();

        $this->assertCount(3, $perangkat);
        $this->assertEqualsCanonicalizing(
            ['10.0.0.1', '10.0.0.2', '10.0.0.3'],
            $perangkat->pluck('ip_terakhir')->all(),
        );
    }

    #[Test]
    public function kode_salah_ditolak(): void
    {
        $this->post('/kiosk/aktivasi/unit', ['kode' => 'ZZZZ9999'])
            ->assertSessionHasErrors('kode');

        $this->assertSame(0, Kiosk::query()->count());
    }

    #[Test]
    public function kode_unit_nonaktif_ditolak(): void
    {
        // Unit yang dinonaktifkan tidak lagi menyelenggarakan absensi, dan
        // perangkat baru atas namanya hanya menghasilkan absensi yang tidak
        // diakui rekap mana pun.
        $this->upt->update(['aktif' => false]);

        $this->post('/kiosk/aktivasi/unit', ['kode' => $this->kode($this->upt)])
            ->assertSessionHasErrors('kode');

        $this->assertSame(0, Kiosk::query()->count());
    }

    #[Test]
    public function reset_kode_menutup_pintu_bagi_yang_belum_masuk(): void
    {
        $lama = $this->kode($this->upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL_UNIT.'/'.$this->upt->id.'/kode')
            ->assertSessionHas('sukses');

        $baru = $this->kode($this->upt);

        $this->assertNotSame($lama, $baru);

        $this->post('/kiosk/aktivasi/unit', ['kode' => $lama])->assertSessionHasErrors('kode');

        $this->flushSession();

        $this->post('/kiosk/aktivasi/unit', ['kode' => $baru])->assertSessionHas('sukses');
    }

    #[Test]
    public function reset_kode_tidak_memutus_perangkat_yang_sudah_dikenali(): void
    {
        // Reset menutup pintu bagi yang belum masuk — bukan mengusir titik
        // absen yang sedang melayani antrean pegawai.
        $kiosk = Kiosk::factory()->diaktifkan('token-lama')->create([
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL_UNIT.'/'.$this->upt->id.'/kode');

        $this->flushSession();

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-lama')
            ->get('/kiosk/umum')
            ->assertOk();

        $this->assertTrue($kiosk->fresh()->aktif);
    }

    #[Test]
    public function reset_kode_meninggalkan_jejak_siapa_dan_kapan(): void
    {
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post(self::URL_UNIT.'/'.$this->upt->id.'/kode');

        $unit = $this->upt->fresh();

        $this->assertSame($pelaku->id, $unit->kode_perangkat_direset_oleh);
        $this->assertNotNull($unit->kode_perangkat_direset_pada);
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengganti_kode(): void
    {
        // Kode adalah kunci masuk seluruh perangkat sebuah unit; menggantinya
        // wewenang Superadmin dan Admin Dinas (matriks peran SRS §6).
        $lama = $this->kode($this->upt);

        $this->actingAs(User::factory()->adminUpt()->create(['unit_kerja_id' => $this->upt->id]))
            ->post(self::URL_UNIT.'/'.$this->upt->id.'/kode')
            ->assertForbidden();

        $this->assertSame($lama, $this->kode($this->upt));
    }

    #[Test]
    public function kode_unit_terbaca_admin_pada_daftar_unit_kerja(): void
    {
        // Berbeda dari device token, kode ini JUSTRU harus dapat dibaca ulang
        // admin — untuk dibacakan kepada petugas di UPT lain.
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL_UNIT)
            ->assertInertia(fn ($halaman) => $halaman
                ->where(
                    'daftar.data.0.kode_perangkat',
                    fn ($kode) => is_string($kode) && str_contains($kode, '-'),
                ),
            );
    }

    #[Test]
    public function mode_pendaftaran_menutup_jalur_kode_unit(): void
    {
        /*
         * Kedua jalur tidak pernah berlaku bersamaan. Membiarkan jalur kode
         * unit tetap terbuka selagi mode pendaftaran menyala akan membuat
         * sakelar itu tidak mengunci apa pun — persis yang hendak dicegah
         * instansi yang menyalakannya.
         */
        app(PengaturanRepository::class)
            ->simpan(SettingAbsenService::KUNCI_PENDAFTARAN_PERANGKAT, '1');

        $this->post('/kiosk/aktivasi/unit', ['kode' => $this->kode($this->upt)])
            ->assertForbidden();

        $this->assertSame(0, Kiosk::query()->count());
    }

    #[Test]
    public function kode_aktivasi_ditolak_selagi_mode_pendaftaran_dimatikan(): void
    {
        // Kebalikannya juga harus berlaku: dengan mode pendaftaran mati, kode
        // aktivasi sekali pakai bukan lagi jalan masuk yang sah.
        Kiosk::factory()->menungguAktivasi('ABCD2345')->create([
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])->assertForbidden();
    }
}
