<?php

namespace Tests\Feature\Admin;

use App\Enums\OverrideAbsenUmum;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\HariLibur;
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
 * Aturan resolusinya punya EMPAT cabang sejak revisi kalender kerja, dan
 * semuanya diuji terpisah karena beberapa menghasilkan layar yang terlihat
 * sama persis — tertutup — padahal menuntut tindakan admin yang berbeda:
 *
 *   setting absen umum mati        → tertutup, tanpa kecuali (sumber: setting)
 *   ada override                   → override selalu menang, apa pun kata
 *                                     kalender maupun jadwal (sumber: override)
 *   bukan hari kerja (akhir pekan
 *   atau hari libur terdaftar)     → tertutup otomatis (sumber: kalender)
 *   selebihnya, di luar/dalam jam  → mengikuti jadwal (sumber: jadwal)
 *
 * Cabang kalender adalah REVISI dari kebijakan S39 ("hari libur menandai,
 * tidak menutup"): sebelum revisi ini, akhir pekan dan tanggal merah tidak
 * pernah menutup jendela sama sekali. Satu-satunya jalan tetap menerima tap
 * pada hari itu sekarang adalah override manual — sengaja diperiksa LEBIH
 * DAHULU daripada kalender, supaya petugas piket yang memang ditugaskan
 * tetap dapat dibukakan.
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

        // Sejak sakelar fitur, tapnya sudah ditolak sebelum mencapai controller.
        $this->tap()->assertForbidden()->assertJsonPath('code', 'FITUR_NONAKTIF');
    }

    /* ---------------------------------------------------------------------
     * Cabang baru — kalender: bukan hari kerja menutup otomatis.
     * ------------------------------------------------------------------- */

    #[Test]
    public function akhir_pekan_tanpa_override_tertutup_otomatis(): void
    {
        // Sabtu, 5 September 2026, 07.35 — di dalam jendela jam datang.
        $this->travelTo('2026-09-05 07:35:00');

        $jawaban = $this->tap()
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'DI_LUAR_JAM']);

        $this->assertStringContainsString('Sabtu', $jawaban->json('message'));
        $this->assertStringContainsString('tertutup otomatis', $jawaban->json('message'));
        $this->assertSame(0, Absensi::query()->count());
    }

    #[Test]
    public function tanggal_merah_tanpa_override_tertutup_otomatis(): void
    {
        // Rabu, 9 September 2026 — hari kerja biasa, DIJADIKAN tanggal merah.
        HariLibur::query()->create(['tanggal' => '2026-09-09', 'keterangan' => 'Cuti Bersama']);

        $this->travelTo('2026-09-09 07:35:00');

        $jawaban = $this->tap()
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'DI_LUAR_JAM']);

        $this->assertStringContainsString('Cuti Bersama', $jawaban->json('message'));
        $this->assertSame(0, Absensi::query()->count());
    }

    #[Test]
    public function override_buka_mengalahkan_kalender_dan_tap_tetap_ditandai(): void
    {
        /*
         * Penjaga UTAMA revisi ini: petugas piket akhir pekan masih dapat
         * mengabsen, asal admin membukanya lewat override — dan absennya
         * tetap tercatat dengan penanda hari libur, persis seperti sebelum
         * revisi (kebijakan S39 tidak berubah untuk kasus ini, hanya jalannya
         * yang kini lewat override, bukan bawaan).
         */
        $this->travelTo('2026-09-05 07:35:00');

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'buka', 'unit_kerja_id' => $this->upt->id])
            ->assertSessionHas('sukses');

        $this->tap()->assertOk();

        $this->assertSame(1, Absensi::query()->count());
        $this->assertTrue(Absensi::query()->sole()->hari_libur);
    }

    #[Test]
    public function setting_mati_menang_di_atas_kalender_maupun_override(): void
    {
        // Sama seperti absen_umum_yang_dimatikan_admin_tidak_dapat_dibuka_paksa,
        // diulang pada akhir pekan: urutan resolusinya menempatkan Setting
        // Absen di atas SEMUA cabang lain, termasuk cabang kalender yang baru.
        $this->travelTo('2026-09-05 07:35:00');
        $this->matikanAbsenUmum();

        $this->actingAs($this->admin)
            ->post(self::URL.'/override', ['aksi' => 'buka', 'unit_kerja_id' => $this->upt->id])
            ->assertForbidden();

        // Sejak sakelar fitur, tapnya sudah ditolak sebelum mencapai controller.
        $this->tap()->assertForbidden()->assertJsonPath('code', 'FITUR_NONAKTIF');
        $this->assertSame(0, Absensi::query()->count());
    }

    #[Test]
    public function halaman_menyebut_status_tertutup_karena_kalender(): void
    {
        $this->travelTo('2026-09-05 07:35:00');

        $this->actingAs($this->admin)
            ->get(self::URL."?unit_kerja_id={$this->upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('status_jendela.datang.terbuka', false)
                ->where('status_jendela.datang.sumber', 'kalender')
                ->where('status_jendela.datang.alasan_libur', fn ($alasan) => str_contains($alasan, 'Sabtu'))
                ->etc());
    }

    #[Test]
    public function layar_kiosk_menyebut_status_tertutup_karena_kalender(): void
    {
        // Wiring paling rawan pada revisi ini: layar kiosk tidak punya sesi
        // untuk diturunkan unitnya sebelum tap pertama, sehingga ia harus
        // memakai unit KIOSK-nya sendiri, bukan unit dari sesi yang belum ada.
        $this->travelTo('2026-09-05 07:35:00');

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/kiosk/umum')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('status_jendela.datang.sumber', 'kalender')
                ->etc());
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

        $sesiBesok = app(AbsenUmumService::class)->sesi();

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
     * Jadwal jam per hari (Senin–Minggu).
     * ------------------------------------------------------------------- */

    #[Test]
    public function jadwal_mingguan_berbeda_per_hari(): void
    {
        // Rabu (ISO 3) sengaja dibuat jendela datang lebih sempit daripada
        // hari lain — kasus nyata yang mendorong fitur ini: UPT yang Rabu-nya
        // ada senam pagi, sehingga jendela masuknya harus lebih ketat.
        $jadwal = collect(range(1, 7))->map(fn (int $hari) => [
            'hari' => $hari,
            'jam_masuk' => $hari === 3 ? '06:45' : '07:30',
            'jam_buka_datang' => '06:00',
            'jam_tutup_datang' => $hari === 3 ? '06:30' : '09:00',
            'jam_buka_pulang' => '15:00',
            'jam_tutup_pulang' => '18:00',
        ])->all();

        app(SettingAbsenService::class)->simpan(['jadwal_mingguan' => $jadwal], $this->admin);

        // Rabu, 07:00 — di luar jendela Rabu yang sudah dipersempit (06:00–06:30).
        $this->travelTo('2026-09-09 07:00:00');
        $this->tap('datang')->assertStatus(409);

        // Senin, jam yang sama — jendela bawaan (06:00–09:00) masih terbuka.
        $this->travelTo('2026-09-07 07:00:00');
        $this->tap('datang')->assertOk();

        // Sesi Senin merekam jam masuk hari Senin (07:30), bukan jam Rabu.
        $sesi = EventAbsen::query()->umum()->whereDate('tanggal', '2026-09-07')->sole();
        $this->assertSame('07:30:00', (string) $sesi->jam_mulai);
    }

    #[Test]
    public function menyimpan_setting_lain_tanpa_menyentuh_jadwal_mingguan_tidak_membekukan_jadwal_lama(): void
    {
        // Bugfix: sebelum diperbaiki, memanggil simpan() dengan medan LAIN
        // (mis. jam_buka_pulang lewat jalur lama) membekukan jadwal_mingguan
        // dari nilai SEBELUM perubahan itu diterapkan — sehingga perubahan
        // jam_buka_pulang tidak pernah benar-benar berlaku, sebab
        // status()/buatSesi() membaca jadwal_mingguan, bukan kunci jam_*
        // secara langsung. Selama jadwal_mingguan belum pernah disimpan
        // eksplisit, ia harus tetap disintesis LIVE dari kunci jam_* terkini.
        app(SettingAbsenService::class)->simpan([
            'jam_buka_pulang' => '22:00',
            'jam_tutup_pulang' => '02:00',
        ], $this->admin);

        $this->travelTo('2026-09-07 23:00:00');
        $this->tap('pulang')->assertOk();
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
