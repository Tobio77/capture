<?php

namespace Tests\Feature\Admin;

use App\Enums\JenisEvent;
use App\Enums\OverrideAbsenUmum;
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
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Absen Umum — absensi harian tanpa event kegiatan.
 *
 * Sesi umum menumpang seluruh mesin event yang sudah ada, sehingga yang diuji
 * di sini adalah hal-hal yang khas baginya: kapan sesi lahir, siapa yang boleh
 * melihatnya, dan bagaimana ia berdampingan dengan event kegiatan.
 *
 * **Sejak S49 sesinya SATU untuk seluruh dinas**, bukan satu per unit kerja.
 * Absen umum terbuka bagi setiap pegawai Disnakertrans tanpa kecuali, sehingga
 * memecahnya per UPT hanya melahirkan belasan sesi yang harus dibuka, ditutup,
 * dan di-override satu-satu. Unit kerja yang tersisa di layar ini adalah
 * PENYARING TAMPILAN; hak baca yang sesungguhnya tetap datang dari peran
 * (FR-REK-02).
 */
class AbsenUmumTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/absen-umum';

    /**
     * @return array{opd: UnitKerja, upt: UnitKerja, lain: UnitKerja, seksi: UnitKerja}
     */
    protected function hirarki(): array
    {
        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);

        return compact('opd', 'upt', 'lain', 'seksi');
    }

    /* ---------------------------------------------------------------------
     * Pembuatan sesi harian.
     * ------------------------------------------------------------------- */

    #[Test]
    public function sesi_harian_lahir_saat_dibuka_bukan_sebelumnya(): void
    {
        $this->hirarki();
        $admin = User::factory()->superadmin()->create();

        // Memantau saja tidak membuat sesi: perangkat yang menyala pada hari
        // libur tidak boleh meninggalkan hari yang terhitung wajib dihadiri.
        $this->actingAs($admin)
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sesi', null)->etc());

        $this->assertDatabaseCount('event_absen', 0);

        $this->actingAs($admin)->post(self::URL.'/buka')->assertSessionHas('sukses');

        $sesi = EventAbsen::query()->umum()->sole();

        $this->assertSame(StatusEvent::Aktif, $sesi->status);
        $this->assertSame(now()->toDateString(), $sesi->tanggal->toDateString());
        $this->assertSame(JenisEvent::Umum, $sesi->jenis);
    }

    #[Test]
    public function sesi_harian_hanya_satu_untuk_seluruh_dinas_per_tanggal(): void
    {
        $this->hirarki();
        $absenUmum = app(AbsenUmumService::class);

        $pertama = $absenUmum->buka();
        $kedua = $absenUmum->buka();

        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame(1, EventAbsen::query()->umum()->count());
    }

    #[Test]
    public function sesi_tidak_menaut_unit_kerja_mana_pun(): void
    {
        // Sesi harian berlaku bagi seluruh dinas; menautkannya ke sebuah unit
        // akan menghidupkan kembali pembatasan yang justru dihapus.
        $this->hirarki();

        $sesi = app(AbsenUmumService::class)->buka();

        $this->assertTrue($sesi->berlakuUntukSemuaUnit());
        $this->assertCount(0, $sesi->unitKerja);
    }

    #[Test]
    public function sesi_menyalin_jam_masuk_dan_toleransi_dari_setting(): void
    {
        $this->hirarki();

        app(PengaturanRepository::class)->simpanBanyak([
            SettingAbsenService::KUNCI_JAM_MASUK_UMUM => '08:15',
            SettingAbsenService::KUNCI_TOLERANSI => '20',
        ]);

        $sesi = app(AbsenUmumService::class)->buka();

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
        $this->hirarki();
        $this->matikanAbsenUmum();

        $this->assertNull(app(AbsenUmumService::class)->buka());

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL.'/buka')
            ->assertForbidden();
    }

    #[Test]
    public function mematikan_lalu_menyalakan_lagi_melanjutkan_sesi_yang_sama(): void
    {
        /*
         * Sakelar Absen Umum menahan penerimaan tap, bukan sesinya. Admin yang
         * mematikannya sebentar — misalnya saat kegiatan mendadak — lalu
         * menyalakannya kembali harus menemukan kehadiran yang sudah masuk
         * masih ada, bukan sesi kosong yang dimulai dari nol.
         */
        ['upt' => $upt] = $this->hirarki();

        $sesi = app(AbsenUmumService::class)->buka();

        Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => Pegawai::factory()->create(['unit_kerja_id' => $upt->id])->id,
        ]);

        $this->matikanAbsenUmum();

        // Dimatikan: sesinya tetap ditemukan, hanya tidak ada yang lahir baru.
        $this->assertSame($sesi->id, app(AbsenUmumService::class)->sesi()?->id);

        app(PengaturanRepository::class)->simpan(SettingAbsenService::KUNCI_ABSEN_UMUM, '1');

        $lagi = app(AbsenUmumService::class)->buka();

        $this->assertSame($sesi->id, $lagi->id);
        $this->assertSame(1, EventAbsen::query()->umum()->count());
        $this->assertSame(1, Absensi::query()->where('event_absen_id', $sesi->id)->count());
    }

    #[Test]
    public function perangkat_unit_mana_pun_jatuh_ke_sesi_yang_sama(): void
    {
        // Inti perubahan S49: tidak ada lagi sesi per unit yang harus dicari
        // masing-masing perangkat.
        ['upt' => $upt, 'lain' => $lain, 'seksi' => $seksi] = $this->hirarki();

        $absenUmum = app(AbsenUmumService::class);
        $sesi = $absenUmum->buka();

        foreach ([$upt, $lain, $seksi] as $unit) {
            Kiosk::factory()->create(['unit_kerja_id' => $unit->id]);
        }

        $this->assertSame($sesi->id, $absenUmum->sesi()?->id);
        $this->assertSame(1, EventAbsen::query()->umum()->count());
    }

    /* ---------------------------------------------------------------------
     * Buka/tutup paksa (FR-SET-07).
     * ------------------------------------------------------------------- */

    #[Test]
    public function override_berlaku_bagi_seluruh_dinas_sekaligus(): void
    {
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL.'/override', ['aksi' => 'tutup'])
            ->assertSessionHas('sukses');

        $sesi = EventAbsen::query()->umum()->sole();

        $this->assertSame(OverrideAbsenUmum::Tutup, $sesi->override_absen);
        $this->assertNotNull($sesi->override_oleh);
    }

    #[Test]
    public function admin_upt_tidak_dapat_membuka_atau_mengoverride_sesi(): void
    {
        /*
         * Satu sesi melayani seluruh dinas, sehingga membukanya maupun
         * menutupnya paksa berdampak pada semua unit — keputusan Admin Dinas,
         * bukan wewenang satu UPT.
         */
        ['upt' => $upt] = $this->hirarki();
        $adminUpt = User::factory()->adminUpt($upt)->create();

        $this->actingAs($adminUpt)->post(self::URL.'/buka')->assertForbidden();
        $this->actingAs($adminUpt)
            ->post(self::URL.'/override', ['aksi' => 'tutup'])
            ->assertForbidden();

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function pemantauan_menandai_admin_upt_sebagai_pemantau_saja(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page->where('boleh_kelola', false)->etc());

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page->where('boleh_kelola', true)->etc());
    }

    /* ---------------------------------------------------------------------
     * Hubungan dengan event kegiatan.
     * ------------------------------------------------------------------- */

    #[Test]
    public function kegiatan_dan_absen_umum_berdiri_sendiri_pada_perangkat_yang_sama(): void
    {
        /*
         * Keduanya dua layar terpisah yang dipilih petugas, dan tidak ada yang
         * mendahului. Satu perangkat yang sedang melayani apel tetap memiliki
         * sesi harian yang siap dilayani pada alamat sebelahnya.
         */
        $this->hirarki();

        $kegiatan = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

        $event = app(EventAbsenService::class);
        $umum = app(AbsenUmumService::class);

        $this->assertSame($kegiatan->id, $event->eventAktifSekarang()->id);

        $sesi = $umum->sesi(buat: true);

        $this->assertNotNull($sesi);
        $this->assertTrue($sesi->absenUmum());
        $this->assertNotSame($kegiatan->id, $sesi->id);
    }

    #[Test]
    public function sesi_umum_tidak_menghalangi_pembuatan_event_kegiatan(): void
    {
        // FR-EVT-06 berlaku antar kegiatan saja; sesi harian yang selalu aktif
        // tidak boleh membuat admin mustahil membuat apel.
        $this->hirarki();
        app(AbsenUmumService::class)->buka();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/kelola-absen/event', [
                'nama' => 'Apel Pagi',
                'tanggal' => now()->toDateString(),
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
            ])
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function sesi_umum_tidak_muncul_pada_daftar_event(): void
    {
        $this->hirarki();
        app(AbsenUmumService::class)->buka();

        EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

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
        $sesi = app(AbsenUmumService::class)->buka();

        $tepat = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $telat = Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['nama' => 'Belum Datang', 'unit_kerja_id' => $upt->id]);

        Absensi::factory()->create(['event_absen_id' => $sesi->id, 'pegawai_id' => $tepat->id]);
        Absensi::factory()->terlambat()->create(['event_absen_id' => $sesi->id, 'pegawai_id' => $telat->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
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
    public function pegawai_unit_mana_pun_muncul_pada_satu_sesi_yang_sama(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

        foreach ([['Ahmad', $upt], ['Citra', $lain]] as [$nama, $unit]) {
            Absensi::factory()->create([
                'event_absen_id' => $sesi->id,
                'pegawai_id' => Pegawai::factory()->create([
                    'nama' => $nama,
                    'unit_kerja_id' => $unit->id,
                ])->id,
            ]);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page->has('baris', 2)->etc());
    }

    #[Test]
    public function admin_upt_hanya_melihat_pegawainya_sendiri(): void
    {
        // FR-REK-02 berlaku sama pada absen umum: sesinya boleh satu untuk
        // semua, tetapi yang TERBACA tetap dibatasi peran.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

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
    public function penyaring_unit_mempersempit_tabel_tanpa_mengganti_sesi(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

        foreach ([['Ahmad', $upt], ['Citra', $lain]] as [$nama, $unit]) {
            Absensi::factory()->create([
                'event_absen_id' => $sesi->id,
                'pegawai_id' => Pegawai::factory()->create([
                    'nama' => $nama,
                    'unit_kerja_id' => $unit->id,
                ])->id,
            ]);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."?unit_kerja_id={$lain->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris', 1)
                ->where('baris.0.nama', 'Citra')
                ->where('sesi.id', $sesi->id)
                ->etc());
    }

    #[Test]
    public function penyaring_unit_tidak_memperluas_hak_admin_upt(): void
    {
        // Menyaring unit lain menghasilkan irisan kosong, bukan baris milik
        // unit yang bukan haknya.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

        Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Citra',
                'unit_kerja_id' => $lain->id,
            ])->id,
        ]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."?unit_kerja_id={$lain->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('baris', 0)->etc());
    }

    #[Test]
    public function admin_upt_hanya_ditawari_unitnya_pada_penyaring(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('unit_kerja', 1)
                ->where('unit_kerja.0.id', $upt->id)
                ->etc());
    }

    #[Test]
    public function pencarian_menyaring_baris_kehadiran(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

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
            ->get(self::URL.'?cari=dewi')
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
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/layar')
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

        // Di dalam jendela "datang" (FR-SET-07); tanpa ini hasilnya bergantung
        // pada pukul berapa test dijalankan.
        $this->travelTo('2026-09-07 07:35:00');

        $this->actingAs($admin)
            ->post(self::URL.'/tap/identifikasi', [
                'id_card' => '199001012020011001',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['nama' => 'Ahmad Fauzi']]);

        $this->actingAs($admin)
            ->post(self::URL.'/absen', [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Layar admin bukan perangkat terdaftar: absennya tercatat tanpa kiosk,
        // dan karena itu tanpa alamat IP perangkat pula.
        $this->assertDatabaseHas('absensi', [
            'event_absen_id' => EventAbsen::query()->umum()->value('id'),
            'kiosk_id' => null,
            'ip_address' => null,
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
        $sesi = app(AbsenUmumService::class)->buka();

        Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $admin = User::factory()->superadmin()->create();

        $csv = $this->actingAs($admin)
            ->get(self::URL.'/ekspor')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"NIP";"Nama"', $csv);
        $this->assertStringContainsString('Ahmad Fauzi', $csv);

        $pdf = $this->actingAs($admin)
            ->get(self::URL.'/ekspor?format=pdf')
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    #[Test]
    public function admin_upt_mengunduh_pegawainya_sendiri_saja(): void
    {
        /*
         * FR-LAP-02. Berkas unduhan mengikuti apa yang terbaca di layar, dan
         * tidak ada jalan memperoleh baris di luar hak lewat endpoint ini —
         * barisnya sudah tersaring sejak dirakit.
         */
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

        foreach ([['Ahmad', $upt], ['Citra', $lain]] as [$nama, $unit]) {
            Absensi::factory()->create([
                'event_absen_id' => $sesi->id,
                'pegawai_id' => Pegawai::factory()->create([
                    'nama' => $nama,
                    'unit_kerja_id' => $unit->id,
                ])->id,
            ]);
        }

        $csv = $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL.'/ekspor')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ahmad', $csv);
        $this->assertStringNotContainsString('Citra', $csv);
    }

    #[Test]
    public function ekspor_ditolak_bila_belum_ada_sesi(): void
    {
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor')
            ->assertNotFound();
    }

    #[Test]
    public function rekap_absen_umum_dapat_diunduh_sebagai_xlsx_sungguhan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

        Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=xlsx')
            ->assertOk();

        $sementara = tempnam(sys_get_temp_dir(), 'absen-umum-uji').'.xlsx';
        file_put_contents($sementara, $this->berkasUnduhan($jawaban));

        $sheet = IOFactory::load($sementara)->getActiveSheet();

        $this->assertSame('NIP', $sheet->getCell('A1')->getValue());
        $this->assertSame('Ahmad Fauzi', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function checklist_kolom_menyaring_kolom_ekspor_absen_umum(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sesi = app(AbsenUmumService::class)->buka();

        Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $csv = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?'.http_build_query(['kolom' => ['metode']]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"NIP";"Nama";"Metode"', $csv);
        $this->assertStringNotContainsString('Unit Kerja', $csv);
    }

    protected function berkasUnduhan(TestResponse $jawaban): string
    {
        $base = $jawaban->baseResponse;

        return $base instanceof BinaryFileResponse
            ? file_get_contents($base->getFile()->getPathname())
            : $jawaban->streamedContent();
    }
}
