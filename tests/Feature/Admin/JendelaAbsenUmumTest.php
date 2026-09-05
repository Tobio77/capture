<?php

namespace Tests\Feature\Admin;

use App\Enums\OverrideAbsenUmum;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jendela buka/tutup Absen Umum beserta override manualnya (FR-SET-07).
 *
 * Aturan resolusinya punya tiga cabang, dan ketiganya diuji terpisah karena
 * dua di antaranya menghasilkan layar yang terlihat sama persis — tertutup —
 * padahal menuntut tindakan admin yang berbeda:
 *
 *   di luar jam, tanpa override   → tertutup   (sumber: jadwal)
 *   di dalam jam, tanpa override  → terbuka    (sumber: jadwal)
 *   ada override                  → override selalu menang (sumber: override)
 *
 * Ditambah satu jaminan yang tidak dapat dilihat dari layar mana pun: override
 * TIDAK terbawa ke hari berikutnya.
 */
class JendelaAbsenUmumTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected const NIP = '199001012020011001';

    protected const URL = '/admin/kelola-absen/absen-umum';

    protected UnitKerja $upt;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);

        Pegawai::factory()->create([
            'nip' => self::NIP,
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->admin = User::factory()->superadmin()->create();

        app(SettingAbsenService::class)->simpan(['metode_wajah_aktif' => false], $this->admin);
    }

    protected function tap(string $jenis = 'datang'): TestResponse
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/umum/absen', [
                'id_card' => self::NIP,
                'jenis' => $jenis,
                'metode' => 'manual',
            ], ['Accept' => 'application/json']);
    }

    /* ---------------------------------------------------------------------
     * Cabang 1 & 2 — jadwal bawaan.
     * ------------------------------------------------------------------- */

    #[Test]
    public function di_dalam_jendela_tanpa_override_tap_diterima(): void
    {
        // 07.35 berada di dalam jendela datang bawaan, 06.00–09.00.
        $this->travelTo('2026-09-07 07:35:00');

        $this->tap()->assertOk();

        $this->assertSame(1, Absensi::query()->count());
    }

    #[Test]
    public function di_luar_jendela_tanpa_override_tap_ditolak(): void
    {
        // 11.00 sudah lewat dari jendela datang.
        $this->travelTo('2026-09-07 11:00:00');

        $this->tap()
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'DI_LUAR_JAM']);

        $this->assertSame(0, Absensi::query()->count());
    }

    #[Test]
    public function jendela_datang_dan_pulang_berdiri_sendiri(): void
    {
        /*
         * Inti keputusan "dua jendela, bukan satu": pada pukul 16.00 absen
         * PULANG terbuka sementara absen DATANG tertutup. Dengan satu jendela
         * besar 06.00–18.00, keduanya sama-sama sah dan sistem tidak punya
         * dasar menolak orang yang menekan tombol keliru.
         */
        $this->travelTo('2026-09-07 16:00:00');

        $this->tap('datang')
            ->assertStatus(409)
            ->assertJson(['code' => 'DI_LUAR_JAM']);

        $this->tap('pulang')->assertOk();
    }

    /* ---------------------------------------------------------------------
     * Cabang 3 — override selalu menang.
     * ------------------------------------------------------------------- */

    #[Test]
    public function override_buka_mengalahkan_jadwal_yang_menutup(): void
    {
        $this->travelTo('2026-09-07 11:00:00');

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'buka', 'unit_kerja_id' => $this->upt->id])
            ->assertSessionHas('sukses');

        $this->tap()->assertOk();

        $this->assertSame(1, Absensi::query()->count());
    }

    #[Test]
    public function override_tutup_mengalahkan_jadwal_yang_membuka(): void
    {
        $this->travelTo('2026-09-07 07:35:00');

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'tutup', 'unit_kerja_id' => $this->upt->id])
            ->assertSessionHas('sukses');

        $this->tap()
            ->assertStatus(409)
            ->assertJson(['code' => 'DI_LUAR_JAM']);

        $this->assertSame(0, Absensi::query()->count());
    }

    #[Test]
    public function mencabut_override_mengembalikan_jadwal(): void
    {
        $this->travelTo('2026-09-07 07:35:00');

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'tutup', 'unit_kerja_id' => $this->upt->id]);

        $this->tap()->assertStatus(409);

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'cabut', 'unit_kerja_id' => $this->upt->id])
            ->assertSessionHas('sukses');

        $this->tap()->assertOk();
    }

    #[Test]
    public function absen_umum_yang_dimatikan_admin_tidak_dapat_dibuka_paksa(): void
    {
        /*
         * Urutan resolusinya menempatkan Setting Absen di atas override:
         * mematikan absen umum berarti fiturnya tidak dipakai sama sekali,
         * bukan sekadar tertutup hari ini.
         */
        $this->travelTo('2026-09-07 07:35:00');
        $this->matikanAbsenUmum();

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'buka', 'unit_kerja_id' => $this->upt->id])
            ->assertForbidden();

        $this->tap()->assertStatus(409);
    }

    /* ---------------------------------------------------------------------
     * Override tidak terbawa ke hari berikutnya.
     * ------------------------------------------------------------------- */

    #[Test]
    public function override_tidak_terbawa_ke_hari_berikutnya(): void
    {
        /*
         * Syarat yang paling mudah rusak, dan paling sukar terlihat: admin
         * membuka paksa Jumat sore, lalu Sabtu pagi seluruh absen diterima di
         * luar jam tanpa seorang pun tahu sebabnya.
         *
         * Dijamin oleh strukturnya, bukan oleh tugas terjadwal: override
         * menempel pada sesi harian, dan besok adalah baris yang berbeda.
         */
        $this->travelTo('2026-09-07 11:00:00');

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'buka', 'unit_kerja_id' => $this->upt->id]);

        $this->tap()->assertOk();

        // Hari berganti; jam yang sama, di luar jendela.
        $this->travelTo('2026-09-08 11:00:00');

        $this->tap()
            ->assertStatus(409)
            ->assertJson(['code' => 'DI_LUAR_JAM']);

        $sesiBesok = app(AbsenUmumService::class)->sesi($this->upt->id);

        $this->assertNull($sesiBesok?->override_absen, 'Sesi hari baru harus lahir tanpa override.');
    }

    /* ---------------------------------------------------------------------
     * Admin dapat membedakan sumber statusnya.
     * ------------------------------------------------------------------- */

    #[Test]
    public function halaman_menyebut_status_berasal_dari_jadwal_atau_override(): void
    {
        $this->travelTo('2026-09-07 11:00:00');

        $this->actingAs($this->admin)
            ->get(self::URL."?unit_kerja_id={$this->upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('status_jendela.datang.terbuka', false)
                ->where('status_jendela.datang.sumber', 'jadwal')
                ->where('status_jendela.pulang.terbuka', false)
                ->etc());

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'buka', 'unit_kerja_id' => $this->upt->id]);

        $this->actingAs($this->admin)
            ->get(self::URL."?unit_kerja_id={$this->upt->id}")
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('status_jendela.datang.terbuka', true)
                ->where('status_jendela.datang.sumber', 'override')
                ->where('status_jendela.datang.override', OverrideAbsenUmum::Buka->value)
                ->where('status_jendela.datang.oleh', $this->admin->nama)
                ->etc());
    }

    #[Test]
    public function jendela_yang_melewati_tengah_malam_tetap_terbaca(): void
    {
        /*
         * UPT yang menyelenggarakan pelatihan menginap memulangkan peserta
         * lewat tengah malam. Jam tutup yang lebih kecil daripada jam buka
         * berarti jendelanya melewati pergantian hari, bukan jendela kosong.
         */
        app(SettingAbsenService::class)->simpan([
            'jam_buka_pulang' => '22:00',
            'jam_tutup_pulang' => '02:00',
        ], $this->admin);

        $this->travelTo('2026-09-07 23:30:00');
        $this->tap('pulang')->assertOk();

        $this->travelTo('2026-09-08 01:30:00');
        $this->tap('pulang')->assertOk();

        $this->travelTo('2026-09-08 12:00:00');
        $this->tap('pulang')->assertStatus(409);
    }

    /* ---------------------------------------------------------------------
     * Kegiatan tidak ikut terkena jendela.
     * ------------------------------------------------------------------- */

    #[Test]
    public function event_kegiatan_tidak_mengenal_jendela_jam(): void
    {
        /*
         * Yang membuka dan menutup kegiatan adalah status entry (FR-EVT-04),
         * bukan jam operasional harian. Rapat sore hari harus tetap dapat
         * mencatat kehadiran.
         */
        $this->travelTo('2026-09-07 16:00:00');

        $kegiatan = EventAbsen::factory()->create(['nama' => 'Rapat Sore']);
        $kegiatan->unitKerja()->attach($this->upt);
        $this->gabungkanKeEvent($kegiatan, Kiosk::query()->sole());

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/event/absen', [
                'id_card' => self::NIP,
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }
}
