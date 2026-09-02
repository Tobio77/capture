<?php

namespace Tests\Feature\Admin;

use App\Enums\JenisEvent;
use App\Enums\StatusEvent;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsenUmumService;
use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Absen Umum — absensi harian tanpa event kegiatan.
 *
 * Sesi umum menumpang seluruh mesin event yang sudah ada, sehingga yang diuji
 * di sini adalah hal-hal yang khas baginya: kapan sesi lahir, siapa yang
 * boleh melihatnya, dan bagaimana ia mengalah pada event kegiatan.
 */
class AbsenUmumTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/absen-umum';

    /**
     * @return array{upt: UnitKerja, lain: UnitKerja, seksi: UnitKerja}
     */
    protected function hirarki(): array
    {
        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);

        return compact('upt', 'lain', 'seksi');
    }

    /* ---------------------------------------------------------------------
     * Pembuatan sesi harian.
     * ------------------------------------------------------------------- */

    #[Test]
    public function sesi_harian_lahir_saat_dibuka_bukan_sebelumnya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $admin = User::factory()->superadmin()->create();

        // Memantau saja tidak membuat sesi: perangkat yang menyala pada hari
        // libur tidak boleh meninggalkan hari yang terhitung wajib dihadiri.
        $this->actingAs($admin)
            ->get(self::URL."?unit_kerja_id={$upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sesi', null)->etc());

        $this->assertDatabaseCount('event_absen', 0);

        $this->actingAs($admin)
            ->post(self::URL.'/buka', ['unit_kerja_id' => $upt->id])
            ->assertSessionHas('sukses');

        $sesi = EventAbsen::query()->umum()->sole();

        $this->assertSame(StatusEvent::Aktif, $sesi->status);
        $this->assertSame(now()->toDateString(), $sesi->tanggal->toDateString());
        $this->assertSame(JenisEvent::Umum, $sesi->jenis);
    }

    #[Test]
    public function sesi_harian_hanya_satu_per_unit_per_tanggal(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $absenUmum = app(AbsenUmumService::class);

        $pertama = $absenUmum->buka($upt->id);
        $kedua = $absenUmum->buka($upt->id);

        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame(1, EventAbsen::query()->umum()->count());
    }

    #[Test]
    public function sesi_menyalin_jam_masuk_dan_toleransi_dari_setting(): void
    {
        ['upt' => $upt] = $this->hirarki();

        app(PengaturanRepository::class)->simpanBanyak([
            SettingAbsenService::KUNCI_JAM_MASUK_UMUM => '08:15',
            SettingAbsenService::KUNCI_TOLERANSI => '20',
        ]);

        $sesi = app(AbsenUmumService::class)->buka($upt->id);

        $this->assertSame('08:15', substr((string) $sesi->jam_mulai, 0, 5));
        $this->assertSame(20, $sesi->toleransi_menit);

        // Setelah tersimpan, sesi berdiri sendiri: menggeser setting global
        // tidak boleh mengubah penilaian tepat/terlambat sesi berjalan.
        app(PengaturanRepository::class)->simpan(SettingAbsenService::KUNCI_JAM_MASUK_UMUM, '06:00');

        $this->assertSame('08:15', substr((string) $sesi->fresh()->jam_mulai, 0, 5));
    }

    #[Test]
    public function sesi_tidak_dibuka_ketika_absen_umum_dimatikan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $this->matikanAbsenUmum();

        $this->assertNull(app(AbsenUmumService::class)->buka($upt->id));

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL.'/buka', ['unit_kerja_id' => $upt->id])
            ->assertForbidden();
    }

    #[Test]
    public function sesi_dibuka_untuk_unit_teratas_walau_perangkat_menaut_ke_seksi(): void
    {
        // Pegawai dan perangkat menaut ke seksi/subbag; sesi harian adalah
        // milik UPT di atasnya, bukan milik tiap seksi.
        ['upt' => $upt, 'seksi' => $seksi] = $this->hirarki();

        $sesi = app(AbsenUmumService::class)->buka($seksi->id);

        $this->assertSame([$upt->id], $sesi->unitKerja->pluck('id')->all());
    }

    /* ---------------------------------------------------------------------
     * Hubungan dengan event kegiatan.
     * ------------------------------------------------------------------- */

    #[Test]
    public function perangkat_mendahulukan_kegiatan_lalu_jatuh_ke_absen_umum(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        $kegiatan = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $kegiatan->unitKerja()->attach($upt);

        $event = app(EventAbsenService::class);

        $this->assertSame($kegiatan->id, $event->eventAktifUntukKiosk($perangkat)->id);

        // Entry ditutup: perangkat di pintu masuk beralih melayani absen rutin
        // alih-alih berhenti melayani sama sekali.
        $kegiatan->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $sesi = $event->eventAktifUntukKiosk($perangkat, bukaAbsenUmum: true);

        $this->assertNotNull($sesi);
        $this->assertTrue($sesi->absenUmum());
    }

    #[Test]
    public function sesi_umum_tidak_menghalangi_pembuatan_event_kegiatan(): void
    {
        // FR-EVT-06 berlaku antar kegiatan saja; sesi harian yang selalu aktif
        // tidak boleh membuat admin mustahil membuat apel.
        ['upt' => $upt] = $this->hirarki();
        app(AbsenUmumService::class)->buka($upt->id);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/kelola-absen/event', [
                'nama' => 'Apel Pagi',
                'tanggal' => now()->toDateString(),
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => 'unit',
                'unit_kerja_id' => [$upt->id],
            ])
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function sesi_umum_tidak_muncul_pada_daftar_event(): void
    {
        ['upt' => $upt] = $this->hirarki();
        app(AbsenUmumService::class)->buka($upt->id);

        $kegiatan = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $kegiatan->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/kelola-absen/event')
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar.data', 1)
                ->where('daftar.data.0.nama', 'Apel Pagi')
                ->etc());
    }

    /* ---------------------------------------------------------------------
     * Pemantauan dan cakupan peran.
     * ------------------------------------------------------------------- */

    #[Test]
    public function pemantauan_menampilkan_kehadiran_beserta_ringkasannya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka($upt->id);

        $tepat = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $telat = Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['nama' => 'Belum Datang', 'unit_kerja_id' => $upt->id]);

        Absensi::factory()->create(['event_absen_id' => $sesi->id, 'pegawai_id' => $tepat->id]);
        Absensi::factory()->terlambat()->create(['event_absen_id' => $sesi->id, 'pegawai_id' => $telat->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."?unit_kerja_id={$upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AbsenUmum/Index')
                ->has('baris', 2)
                ->where('ringkasan.hadir', 2)
                ->where('ringkasan.terlambat', 1)
                ->where('ringkasan.pegawai', 3)
                ->where('ringkasan.belum_absen', 1)
                ->etc());
    }

    #[Test]
    public function admin_upt_hanya_melihat_pegawainya_sendiri(): void
    {
        // FR-REK-02 berlaku sama pada absen umum.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka($upt->id);

        foreach ([['Ahmad', $upt], ['Citra', $lain]] as [$nama, $unit]) {
            Absensi::factory()->create([
                'event_absen_id' => $sesi->id,
                'pegawai_id' => Pegawai::factory()->create([
                    'nama' => $nama,
                    'unit_kerja_id' => $unit->id,
                ])->id,
            ]);
        }

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris', 1)
                ->where('baris.0.nama', 'Ahmad')
                ->etc());
    }

    #[Test]
    public function admin_upt_tidak_dapat_memilih_unit_lain(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesiLain = app(AbsenUmumService::class)->buka($lain->id);

        Absensi::factory()->create([
            'event_absen_id' => $sesiLain->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Citra',
                'unit_kerja_id' => $lain->id,
            ])->id,
        ]);

        // Pilihan unit diabaikan: cakupan Admin UPT ditentukan akunnya.
        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."?unit_kerja_id={$lain->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.unit_kerja_id', $upt->id)
                ->has('baris', 0)
                ->etc());
    }

    #[Test]
    public function pencarian_menyaring_baris_kehadiran(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka($upt->id);

        foreach (['Ahmad Fauzi', 'Dewi Anggraini'] as $nama) {
            Absensi::factory()->create([
                'event_absen_id' => $sesi->id,
                'pegawai_id' => Pegawai::factory()->create([
                    'nama' => $nama,
                    'unit_kerja_id' => $upt->id,
                ])->id,
            ]);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."?unit_kerja_id={$upt->id}&cari=dewi")
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris', 1)
                ->where('baris.0.nama', 'Dewi Anggraini')
                ->etc());
    }

    /* ---------------------------------------------------------------------
     * Layar absen di peramban admin.
     * ------------------------------------------------------------------- */

    #[Test]
    public function membuka_layar_absen_langsung_membuka_sesi_hari_ini(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."/layar?unit_kerja_id={$upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AbsenUmum/Layar')
                ->where('event.tanggal', now()->toDateString())
                ->etc());

        $this->assertSame(1, EventAbsen::query()->umum()->count());
    }

    #[Test]
    public function tap_dari_layar_admin_tercatat_tanpa_perangkat(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $admin = User::factory()->superadmin()->create();

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $upt->id,
        ]);

        // Verifikasi wajah dimatikan: yang diuji di sini jalur penyimpanannya,
        // bukan pencocokan wajah yang berjalan di sisi peramban.
        app(PengaturanRepository::class)->simpan(SettingAbsenService::KUNCI_WAJAH, '0');

        $this->actingAs($admin)
            ->post(self::URL."/tap/identifikasi?unit_kerja_id={$upt->id}", [
                'id_card' => '199001012020011001',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['nama' => 'Ahmad Fauzi']]);

        $this->actingAs($admin)
            ->post(self::URL."/absen?unit_kerja_id={$upt->id}", [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Layar admin bukan perangkat terdaftar: absennya tercatat tanpa kiosk.
        $this->assertDatabaseHas('absensi', [
            'event_absen_id' => EventAbsen::query()->umum()->value('id'),
            'kiosk_id' => null,
            'jenis' => 'datang',
        ]);
    }

    #[Test]
    public function layar_absen_tertutup_bagi_tamu(): void
    {
        $this->get(self::URL.'/layar')->assertRedirect('/masuk');
        $this->get(self::URL)->assertRedirect('/masuk');
    }

    /* ---------------------------------------------------------------------
     * Ekspor.
     * ------------------------------------------------------------------- */

    #[Test]
    public function rekap_absen_umum_dapat_diunduh_sebagai_csv_dan_pdf(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka($upt->id);

        Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $admin = User::factory()->superadmin()->create();

        $csv = $this->actingAs($admin)
            ->get(self::URL."/ekspor?unit_kerja_id={$upt->id}")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"NIP";"Nama"', $csv);
        $this->assertStringContainsString('Ahmad Fauzi', $csv);

        $pdf = $this->actingAs($admin)
            ->get(self::URL."/ekspor?unit_kerja_id={$upt->id}&format=pdf")
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    #[Test]
    public function ekspor_ditolak_bila_belum_ada_sesi(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."/ekspor?unit_kerja_id={$upt->id}")
            ->assertNotFound();
    }
}
