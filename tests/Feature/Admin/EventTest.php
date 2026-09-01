<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Enums\PeranPengguna;
use App\Enums\StatusEvent;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CRUD event absensi (FR-EVT-01, FR-EVT-02).
 */
class EventTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/event';

    /**
     * Hirarki ringkas: OPD → dua UPT level teratas → satu seksi.
     *
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

    /**
     * @param  array<string, mixed>  $ubahan
     * @return array<string, mixed>
     */
    protected function isian(array $ubahan = []): array
    {
        return array_merge([
            'nama' => 'Apel Pagi Senin',
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
            'cakupan' => 'unit',
            'unit_kerja_id' => [],
            'catatan' => null,
        ], $ubahan);
    }

    #[Test]
    public function superadmin_dapat_membuat_event_dengan_cakupan_unit_terpilih(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['unit_kerja_id' => [$upt->id, $lain->id]]))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $event = EventAbsen::sole();

        $this->assertSame('Apel Pagi Senin', $event->nama);
        $this->assertSame(CakupanEvent::Unit, $event->cakupan);
        $this->assertSame(15, $event->toleransi_menit);
        $this->assertEqualsCanonicalizing(
            [$upt->id, $lain->id],
            $event->unitKerja->pluck('id')->all(),
        );
    }

    #[Test]
    public function cakupan_semua_unit_tidak_menyimpan_baris_pivot(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'cakupan' => 'semua_unit',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHas('sukses');

        // Menyalin seluruh unit akan basi begitu unit baru masuk dari WORKA;
        // cakupan "semua unit" sengaja tidak menyimpan pivot sama sekali.
        $this->assertDatabaseCount('event_unit_kerja', 0);
        $this->assertTrue(EventAbsen::sole()->berlakuUntukSemuaUnit());
    }

    #[Test]
    public function toleransi_awal_mengikuti_setting_absen(): void
    {
        $this->hirarki();

        app(SettingAbsenService::class)->simpan([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => true,
            'metode_wajah_aktif' => true,
            'toleransi_default_menit' => 25,
            'ambang_kecocokan_wajah' => 85,
            'kompresi_foto' => 'sedang',
        ], User::factory()->superadmin()->create());

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Event/Index')
                ->where('nilai_awal.toleransi_menit', 25)
                ->etc());
    }

    #[Test]
    public function toleransi_event_berdiri_sendiri_setelah_setting_berubah(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->post(self::URL, $this->isian([
            'unit_kerja_id' => [$upt->id],
            'toleransi_menit' => 10,
        ]));

        app(SettingAbsenService::class)->simpan([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => true,
            'metode_wajah_aktif' => true,
            'toleransi_default_menit' => 45,
            'ambang_kecocokan_wajah' => 85,
            'kompresi_foto' => 'sedang',
        ], $admin);

        // FR-SET-02: setting hanya jadi nilai awal, bukan penggerak event lama.
        $this->assertSame(10, EventAbsen::sole()->toleransi_menit);
    }

    #[Test]
    public function admin_upt_hanya_dapat_memilih_unitnya_sendiri(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        // FR-EVT-02
        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->post(self::URL, $this->isian(['unit_kerja_id' => [$lain->id]]))
            ->assertSessionHasErrors('unit_kerja_id');

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function admin_upt_dapat_membuat_event_untuk_unitnya(): void
    {
        ['upt' => $upt, 'seksi' => $seksi] = $this->hirarki();

        // Akunnya menempel pada seksi, tetapi cakupan yang boleh dipilih
        // adalah unit level teratas yang menaunginya.
        $this->actingAs(User::factory()->adminUpt($seksi)->create())
            ->post(self::URL, $this->isian(['unit_kerja_id' => [$upt->id]]))
            ->assertSessionHas('sukses');

        $this->assertSame($upt->id, EventAbsen::sole()->unitKerja->sole()->id);
    }

    #[Test]
    public function admin_upt_tidak_dapat_memakai_cakupan_semua_unit(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->post(self::URL, $this->isian(['cakupan' => 'semua_unit']))
            ->assertSessionHasErrors('cakupan');
    }

    #[Test]
    public function admin_upt_hanya_ditawari_unit_kerjanya_pada_formulir(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('unit_kerja', 1)
                ->where('unit_kerja.0.id', $upt->id)
                ->where('boleh_semua_unit', false)
                ->etc());
    }

    #[Test]
    public function unit_kerja_yang_dipilih_harus_level_teratas(): void
    {
        ['seksi' => $seksi] = $this->hirarki();

        // Event diselenggarakan pada tingkat UPT/bidang, bukan seksi.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['unit_kerja_id' => [$seksi->id]]))
            ->assertSessionHasErrors('unit_kerja_id');
    }

    #[Test]
    public function cakupan_unit_tanpa_pilihan_ditolak(): void
    {
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['unit_kerja_id' => []]))
            ->assertSessionHasErrors('unit_kerja_id');
    }

    #[Test]
    public function admin_upt_hanya_melihat_event_yang_menyentuh_unitnya(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        $milikSendiri = EventAbsen::factory()->create(['nama' => 'Apel BLK Singosari']);
        $milikSendiri->unitKerja()->attach($upt);

        $milikLain = EventAbsen::factory()->create(['nama' => 'Apel BLK Surabaya']);
        $milikLain->unitKerja()->attach($lain);

        EventAbsen::factory()->semuaUnit()->create(['nama' => 'Apel Gabungan Dinas']);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                // Event "semua unit" ikut karena mencakup unitnya juga.
                ->has('daftar', 2)
                ->etc());
    }

    #[Test]
    public function admin_dinas_dapat_mengubah_event(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Nama Lama']);
        $event->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->create(['role' => PeranPengguna::AdminDinas]))
            ->patch(self::URL."/{$event->id}", $this->isian([
                'nama' => 'Nama Baru',
                'unit_kerja_id' => [$lain->id],
            ]))
            ->assertSessionHas('sukses');

        $event->refresh()->load('unitKerja');

        $this->assertSame('Nama Baru', $event->nama);
        $this->assertSame($lain->id, $event->unitKerja->sole()->id);
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengubah_event_unit_lain(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->patch(self::URL."/{$event->id}", $this->isian(['unit_kerja_id' => [$upt->id]]))
            ->assertForbidden();
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengubah_event_semua_unit(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->semuaUnit()->create();

        // Boleh melihat, tetapi event lintas unit bukan miliknya untuk diubah.
        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->patch(self::URL."/{$event->id}", $this->isian(['unit_kerja_id' => [$upt->id]]))
            ->assertForbidden();
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_dapat_diubah(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create(['nama' => 'Apel Selesai']);
        $event->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$event->id}", $this->isian([
                'nama' => 'Diubah',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertForbidden();

        $this->assertSame('Apel Selesai', $event->refresh()->nama);
    }

    #[Test]
    public function jam_mulai_harus_berformat_jam_menit(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'unit_kerja_id' => [$upt->id],
                'jam_mulai' => 'pagi',
            ]))
            ->assertSessionHasErrors('jam_mulai');
    }

    /* ---------------------------------------------------------------------
     * FR-EVT-06 — tidak boleh ada dua event aktif yang cakupan dan rentang
     * waktunya bertumpang tindih.
     * ------------------------------------------------------------------- */

    #[Test]
    public function event_unit_bentrok_dengan_event_unit_lain_yang_beririsan(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        $sudahAda = EventAbsen::factory()->create([
            'nama' => 'Apel Pagi Gabungan',
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
        ]);
        $sudahAda->unitKerja()->attach([$upt->id, $lain->id]);

        // Irisan pivot pada BLK-SGS; pesan menyebut event yang bentrok.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'nama' => 'Apel Susulan',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHasErrors(['cakupan' => 'Event "Apel Pagi Gabungan" (07-09-2026, mencakup BLK-SGS, BLK-SBY) masih aktif dan cakupannya beririsan. Tutup event tersebut lebih dulu.']);

        $this->assertDatabaseCount('event_absen', 1);
    }

    #[Test]
    public function event_unit_bentrok_dengan_event_semua_unit_yang_aktif(): void
    {
        ['upt' => $upt] = $this->hirarki();

        // "Semua unit" mencakup segalanya, jadi unit mana pun ikut bentrok.
        EventAbsen::factory()->semuaUnit()->create([
            'nama' => 'Apel Gabungan Dinas',
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'jam_mulai' => '07:40',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHasErrors('cakupan');

        $this->assertDatabaseCount('event_absen', 1);
    }

    #[Test]
    public function event_semua_unit_bentrok_dengan_event_unit_yang_aktif(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $sudahAda = EventAbsen::factory()->create([
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
        ]);
        $sudahAda->unitKerja()->attach($upt);

        // Arah sebaliknya: yang baru bercakupan semua unit.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'cakupan' => 'semua_unit',
                'jam_mulai' => '07:35',
            ]))
            ->assertSessionHasErrors('cakupan');
    }

    #[Test]
    public function unit_sama_ditolak_selama_event_lama_belum_ditutup(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $admin = User::factory()->superadmin()->create();

        $sudahAda = EventAbsen::factory()->create([
            'nama' => 'Apel Pagi',
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
        ]);
        $sudahAda->unitKerja()->attach($upt);

        // Jadwal berbeda tidak menolong: selama keduanya aktif, kiosk pada
        // unit itu tetap menghadapi dua event sekaligus.
        $this->actingAs($admin)
            ->post(self::URL, $this->isian([
                'nama' => 'Apel Sore',
                'jam_mulai' => '16:00',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHasErrors('cakupan');

        $this->assertDatabaseCount('event_absen', 1);

        // Menutup event yang lebih dulu berjalan membuka jalan.
        $sudahAda->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $this->actingAs($admin)
            ->post(self::URL, $this->isian([
                'nama' => 'Apel Sore',
                'jam_mulai' => '16:00',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 2);
    }

    #[Test]
    public function unit_berbeda_tidak_dianggap_bentrok(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        $sudahAda = EventAbsen::factory()->create(['tanggal' => '2026-09-07', 'jam_mulai' => '07:30']);
        $sudahAda->unitKerja()->attach($upt);

        // Cakupan tidak beririsan, jadi dua event aktif berdampingan tetap sah.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'jam_mulai' => '07:30',
                'unit_kerja_id' => [$lain->id],
            ]))
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 2);
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_menghalangi_event_baru(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $ditutup = EventAbsen::factory()->ditutup()->create([
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
        ]);
        $ditutup->unitKerja()->attach($upt);

        // Hanya event aktif yang menimbulkan ambiguitas saat tap.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'jam_mulai' => '07:30',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function event_tidak_bentrok_dengan_dirinya_sendiri_saat_diubah(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $event = EventAbsen::factory()->create(['tanggal' => '2026-09-07', 'jam_mulai' => '07:30']);
        $event->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$event->id}", $this->isian([
                'nama' => 'Nama Diperbarui',
                'jam_mulai' => '07:30',
                'unit_kerja_id' => [$upt->id],
            ]))
            ->assertSessionHas('sukses');

        $this->assertSame('Nama Diperbarui', $event->refresh()->nama);
    }

    /* ---------------------------------------------------------------------
     * Kiosk terhubung & detail event (FR-EVT-03, FR-EVT-05).
     * ------------------------------------------------------------------- */

    #[Test]
    public function detail_event_memuat_kiosk_terhubung_beserta_ip_dan_jumlah_masuk(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $event->unitKerja()->attach($upt);

        $kiosk = Kiosk::factory()->create([
            'nama_titik' => 'Aula BLK Singosari',
            'unit_kerja_id' => $upt->id,
        ]);

        app(EventAbsenService::class)->catatKioskAktif($event, $kiosk, '10.10.4.21');

        $this->actingAs(User::factory()->superadmin()->create())
            ->getJson(self::URL."/{$event->id}/detail")
            ->assertOk()
            ->assertJson([
                'nama' => 'Apel Pagi',
                'status' => 'aktif',
                'jumlah_absensi' => 0,
                'kiosk' => [
                    [
                        'nama_titik' => 'Aula BLK Singosari',
                        'unit_kerja_kode' => 'BLK-SGS',
                        'ip_address' => '10.10.4.21',
                    ],
                ],
            ]);
    }

    #[Test]
    public function kiosk_yang_kembali_aktif_tidak_menambah_baris_baru(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);
        $kiosk = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);
        $layanan = app(EventAbsenService::class);

        $layanan->catatKioskAktif($event, $kiosk, '10.10.4.21');
        $pertama = DB::table('event_kiosk')->sole();

        $this->travel(5)->minutes();

        // Kiosk berpindah alamat IP dalam event yang sama.
        $layanan->catatKioskAktif($event, $kiosk, '10.10.4.99');

        $this->assertDatabaseCount('event_kiosk', 1);

        $sesudah = DB::table('event_kiosk')->sole();

        $this->assertSame('10.10.4.99', $sesudah->ip_address);
        $this->assertSame($pertama->aktif_pada, $sesudah->aktif_pada);
        $this->assertNotSame($pertama->terakhir_aktif_pada, $sesudah->terakhir_aktif_pada);
    }

    #[Test]
    public function kiosk_tidak_dicatat_pada_event_yang_sudah_ditutup(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create();
        $event->unitKerja()->attach($upt);
        $kiosk = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        app(EventAbsenService::class)->catatKioskAktif($event, $kiosk, '10.10.4.21');

        // Tidak ada kiosk yang sah "terhubung" ke entry yang sudah selesai.
        $this->assertDatabaseCount('event_kiosk', 0);
    }

    #[Test]
    public function daftar_event_menampilkan_jumlah_kiosk_terhubung(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);
        $layanan = app(EventAbsenService::class);

        foreach (Kiosk::factory()->count(2)->create(['unit_kerja_id' => $upt->id]) as $kiosk) {
            $layanan->catatKioskAktif($event, $kiosk, '10.10.4.21');
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('daftar.0.jumlah_kiosk', 2)
                ->etc());
    }

    #[Test]
    public function admin_upt_dapat_membuka_detail_event_semua_unit(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->semuaUnit()->create();

        // Boleh melihat walau tidak boleh mengubah.
        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->getJson(self::URL."/{$event->id}/detail")
            ->assertOk();
    }

    #[Test]
    public function admin_upt_tidak_dapat_membuka_detail_event_unit_lain(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->getJson(self::URL."/{$event->id}/detail")
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     * Tutup entry (FR-EVT-04).
     * ------------------------------------------------------------------- */

    #[Test]
    public function admin_dapat_menutup_entry_event_aktif(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $event->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$event->id}/tutup")
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $event->refresh();

        $this->assertSame(StatusEvent::Ditutup, $event->status);
        $this->assertNotNull($event->ditutup_pada);
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_dapat_ditutup_lagi(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create();
        $event->unitKerja()->attach($upt);

        $waktuTutup = $event->ditutup_pada;

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$event->id}/tutup")
            ->assertForbidden();

        // Waktu penutupan aslinya tidak boleh tergeser.
        $this->assertEquals($waktuTutup, $event->refresh()->ditutup_pada);
    }

    #[Test]
    public function admin_upt_tidak_dapat_menutup_event_unit_lain(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->post(self::URL."/{$event->id}/tutup")
            ->assertForbidden();

        $this->assertTrue($event->refresh()->aktif());
    }

    #[Test]
    public function penutupan_event_tercatat_pada_audit_trail(): void
    {
        // NFR-09: setiap perubahan status event tercatat dengan pelaku dan waktu.
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $event->unitKerja()->attach($upt);
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post(self::URL."/{$event->id}/tutup");

        $log = LogAktivitas::aksi(AksiLog::Ubah)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is($event));
        $this->assertStringContainsString('Menutup entry event Apel Pagi', $log->deskripsi);
    }

    #[Test]
    public function menutup_event_membuka_jalan_bagi_event_berikutnya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $admin = User::factory()->superadmin()->create();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);

        $this->actingAs($admin)->post(self::URL."/{$event->id}/tutup");

        // FR-EVT-06 tidak lagi menghalangi begitu event lama ditutup.
        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['nama' => 'Apel Berikutnya', 'unit_kerja_id' => [$upt->id]]))
            ->assertSessionHas('sukses');
    }

    /* ---------------------------------------------------------------------
     * Hapus keras — hanya selama belum ada absensi tertaut.
     * ------------------------------------------------------------------- */

    #[Test]
    public function event_tanpa_absensi_dapat_dihapus_permanen(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Salah Buat']);
        $event->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$event->id}")
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 0);

        // Baris pivot ikut terhapus lewat cascade.
        $this->assertDatabaseCount('event_unit_kerja', 0);
    }

    #[Test]
    public function event_yang_sudah_ditutup_tetap_dapat_dihapus_bila_belum_ada_absensi(): void
    {
        ['upt' => $upt] = $this->hirarki();

        // Yang mengunci adalah adanya absensi, bukan statusnya.
        $event = EventAbsen::factory()->ditutup()->create();
        $event->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$event->id}")
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function event_yang_sudah_punya_absensi_tidak_dapat_dihapus(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);

        Absensi::factory()->create(['event_absen_id' => $event->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$event->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('event_absen', 1);
    }

    #[Test]
    public function daftar_menandai_event_yang_terkunci_karena_absensi(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $terkunci = EventAbsen::factory()->create(['tanggal' => '2026-09-07', 'jam_mulai' => '07:30']);
        $terkunci->unitKerja()->attach($upt);

        Absensi::factory()->create(['event_absen_id' => $terkunci->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar', 1)
                ->where('daftar.0.dapat_dihapus', false)
                ->where('daftar.0.jumlah_absensi', 1)
                ->etc());
    }

    #[Test]
    public function admin_upt_tidak_dapat_menghapus_event_unit_lain(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->delete(self::URL."/{$event->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('event_absen', 1);
    }

    #[Test]
    public function penghapusan_event_tercatat_pada_audit_trail(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Salah Buat']);
        $event->unitKerja()->attach($upt);
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->delete(self::URL."/{$event->id}");

        $log = LogAktivitas::aksi(AksiLog::Hapus)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertStringContainsString('Apel Salah Buat', $log->deskripsi);
    }

    #[Test]
    public function pembuatan_event_tercatat_pada_audit_trail(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post(self::URL, $this->isian(['unit_kerja_id' => [$upt->id]]));

        $log = LogAktivitas::aksi(AksiLog::Buat)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is(EventAbsen::sole()));
        $this->assertStringContainsString('BLK-SGS', $log->deskripsi);
    }
}
