<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
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
 *
 * **Sejak S49 setiap event berlaku bagi SELURUH dinas.** Tidak ada lagi medan
 * cakupan, tidak ada daftar unit yang dicentang, dan tidak ada event yang
 * "bukan urusan" sebuah UPT — pegawainya justru berhak hadir di dalamnya.
 *
 * Dua akibatnya diuji di sini:
 *
 *   - FR-EVT-06 menyusut menjadi satu kalimat: karena setiap event mencakup
 *     segalanya, dua event aktif selalu beririsan, sehingga hanya boleh ada
 *     SATU kegiatan yang menerima tap pada satu waktu.
 *   - Hak mengubah berpindah ke peran lintas unit. Membuat, menutup, atau
 *     menghapus sebuah event kini berdampak pada seluruh dinas sekaligus, dan
 *     itu keputusan Admin Dinas — bukan wewenang satu UPT (matriks peran
 *     SRS §6). Membacanya tetap terbuka bagi seluruh peran admin.
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
            'catatan' => null,
        ], $ubahan);
    }

    /* ---------------------------------------------------------------------
     * Pembuatan — selalu berlaku bagi seluruh dinas.
     * ------------------------------------------------------------------- */

    #[Test]
    public function event_baru_selalu_berlaku_bagi_seluruh_unit_kerja(): void
    {
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian())
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $event = EventAbsen::sole();

        $this->assertSame(CakupanEvent::SemuaUnit, $event->cakupan);
        $this->assertTrue($event->berlakuUntukSemuaUnit());
    }

    #[Test]
    public function event_tidak_menyimpan_baris_pivot_unit_kerja(): void
    {
        /*
         * Menyalin seluruh unit ke pivot akan basi begitu unit baru
         * disinkronkan dari WORKA — dan lebih buruk lagi, ia menghidupkan
         * kembali gagasan "event punya daftar unit" yang justru dihapus.
         */
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())->post(self::URL, $this->isian());

        $this->assertDatabaseCount('event_unit_kerja', 0);
    }

    #[Test]
    public function unit_yang_lahir_setelah_event_dibuat_ikut_tercakup(): void
    {
        ['opd' => $opd] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())->post(self::URL, $this->isian());

        $event = EventAbsen::sole();
        $baru = UnitKerja::factory()->create(['kode' => 'UPT-BARU', 'induk_id' => $opd->id]);

        $this->assertContains($baru->id, app(EventAbsenService::class)->unitTercakup($event));
    }

    #[Test]
    public function formulir_tidak_lagi_menawarkan_pilihan_cakupan(): void
    {
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Event/Index')
                ->missing('unit_kerja')
                ->missing('cakupan_tertanam')
                ->missing('boleh_semua_unit')
                ->etc());
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
        $this->hirarki();
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->post(self::URL, $this->isian(['toleransi_menit' => 10]));

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
    public function jam_mulai_harus_berformat_jam_menit(): void
    {
        $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['jam_mulai' => 'pagi']))
            ->assertSessionHasErrors('jam_mulai');
    }

    /* ---------------------------------------------------------------------
     * Hak per peran (FR-EVT-02).
     * ------------------------------------------------------------------- */

    #[Test]
    public function admin_upt_tidak_dapat_membuat_event(): void
    {
        // Event berdampak pada seluruh dinas; membuatnya bukan wewenang satu
        // UPT (matriks peran SRS §6).
        $this->hirarki();

        $this->actingAs(User::factory()->adminUpt()->create())
            ->post(self::URL, $this->isian())
            ->assertForbidden();

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengubah_menutup_atau_menghapus(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $adminUpt = User::factory()->adminUpt()->create();

        $this->actingAs($adminUpt)
            ->patch(self::URL."/{$event->id}", $this->isian(['nama' => 'Diubah']))
            ->assertForbidden();

        $this->actingAs($adminUpt)->post(self::URL."/{$event->id}/tutup")->assertForbidden();
        $this->actingAs($adminUpt)->delete(self::URL."/{$event->id}")->assertForbidden();

        $this->assertSame('Apel Pagi', $event->refresh()->nama);
        $this->assertTrue($event->aktif());
    }

    #[Test]
    public function admin_upt_tetap_melihat_seluruh_event(): void
    {
        /*
         * Tidak ada lagi penyaringan per unit pada daftar: setiap event
         * berlaku bagi seluruh dinas, sehingga menyembunyikannya dari sebuah
         * UPT berarti menyembunyikan kegiatan yang pegawainya justru wajib
         * hadiri.
         */
        $this->hirarki();

        EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        EventAbsen::factory()->ditutup()->create(['nama' => 'Rapat Koordinasi']);

        $this->actingAs(User::factory()->adminUpt()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar.data', 2)
                ->where('boleh_kelola', false)
                ->etc());
    }

    #[Test]
    public function admin_upt_dapat_membuka_detail_event(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create();

        // Boleh melihat walau tidak boleh mengubah.
        $this->actingAs(User::factory()->adminUpt()->create())
            ->getJson(self::URL."/{$event->id}/detail")
            ->assertOk();
    }

    #[Test]
    public function admin_dinas_dapat_mengubah_event(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

        $this->actingAs(User::factory()->adminDinas()->create())
            ->patch(self::URL."/{$event->id}", $this->isian(['nama' => 'Apel Pagi Direvisi']))
            ->assertSessionHas('sukses');

        $this->assertSame('Apel Pagi Direvisi', $event->refresh()->nama);
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_dapat_diubah(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create(['nama' => 'Apel Selesai']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$event->id}", $this->isian(['nama' => 'Diubah']))
            ->assertForbidden();

        $this->assertSame('Apel Selesai', $event->refresh()->nama);
    }

    /* ---------------------------------------------------------------------
     * FR-EVT-06 — hanya satu kegiatan aktif pada satu waktu.
     * ------------------------------------------------------------------- */

    #[Test]
    public function event_kedua_ditolak_selama_yang_pertama_masih_aktif(): void
    {
        $this->hirarki();
        EventAbsen::factory()->create(['nama' => 'Apel Pagi', 'tanggal' => '2026-09-07']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['nama' => 'Rapat Koordinasi']))
            ->assertSessionHasErrors('nama');

        $this->assertSame(1, EventAbsen::query()->kegiatan()->count());
    }

    #[Test]
    public function pesan_bentrok_menyebut_event_yang_menghalangi(): void
    {
        // Admin harus tahu MANA yang harus ditutup lebih dulu, bukan sekadar
        // bahwa ada yang menghalangi.
        $this->hirarki();
        EventAbsen::factory()->create(['nama' => 'Apel Pagi', 'tanggal' => '2026-09-07']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['nama' => 'Rapat Koordinasi']))
            ->assertSessionHasErrors(['nama' => 'Event "Apel Pagi" (07-09-2026) masih dibuka dan berlaku bagi seluruh unit kerja. Tutup event tersebut lebih dulu.']);
    }

    #[Test]
    public function tanggal_berbeda_tidak_membuka_jalan_bagi_event_kedua(): void
    {
        /*
         * Yang menentukan adalah STATUS, bukan jadwal: perangkat yang
         * menghadapi dua event aktif tidak tahu tap yang diterimanya milik
         * kegiatan yang mana, betapapun jauh jarak tanggalnya.
         */
        $this->hirarki();
        EventAbsen::factory()->create(['nama' => 'Apel Pagi', 'tanggal' => '2026-09-07']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['nama' => 'Apel Bulan Depan', 'tanggal' => '2026-10-07']))
            ->assertSessionHasErrors('nama');
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_menghalangi_event_baru(): void
    {
        $this->hirarki();
        EventAbsen::factory()->ditutup()->create(['nama' => 'Apel Kemarin']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['nama' => 'Apel Hari Ini']))
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function sesi_absen_umum_tidak_dihitung_bentrok(): void
    {
        // FR-EVT-06 berlaku antar kegiatan saja; sesi harian yang selalu aktif
        // tidak boleh membuat admin mustahil membuat apel.
        $this->hirarki();
        EventAbsen::factory()->umum()->create(['kunci_sesi' => 'umum:2026-09-07']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian())
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function event_tidak_bentrok_dengan_dirinya_sendiri_saat_diubah(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$event->id}", $this->isian(['nama' => 'Apel Pagi Direvisi']))
            ->assertSessionHas('sukses');

        $this->assertSame('Apel Pagi Direvisi', $event->refresh()->nama);
    }

    /* ---------------------------------------------------------------------
     * Perangkat yang melayani & detail event (FR-EVT-03, FR-EVT-05).
     * ------------------------------------------------------------------- */

    #[Test]
    public function detail_event_memuat_perangkat_beserta_unit_ip_dan_jumlah_masuk(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

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
    public function perangkat_tercatat_saat_pertama_melayani_tanpa_menukar_kode(): void
    {
        /*
         * Inti perubahan S49: keanggotaan tidak lagi lahir dari penukaran kode
         * per event. Perangkat yang sudah dikenali langsung melayani kegiatan
         * yang sedang dibuka, dan barisnya lahir saat itu juga.
         */
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $kiosk = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->assertDatabaseCount('event_kiosk', 0);

        app(EventAbsenService::class)->catatKioskAktif($event, $kiosk, '10.10.4.21');

        $this->assertDatabaseHas('event_kiosk', [
            'event_absen_id' => $event->id,
            'kiosk_id' => $kiosk->id,
            'unit_kerja_id' => $upt->id,
            'ip_address' => '10.10.4.21',
        ]);
    }

    #[Test]
    public function perangkat_yang_kembali_aktif_tidak_menambah_baris_baru(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $kiosk = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);
        $layanan = app(EventAbsenService::class);

        $layanan->catatKioskAktif($event, $kiosk, '10.10.4.21');
        $pertama = DB::table('event_kiosk')->sole();

        $this->travel(5)->minutes();

        // Perangkat berpindah alamat IP dalam event yang sama.
        $layanan->catatKioskAktif($event, $kiosk, '10.10.4.99');

        $this->assertDatabaseCount('event_kiosk', 1);

        $sesudah = DB::table('event_kiosk')->sole();

        $this->assertSame('10.10.4.99', $sesudah->ip_address);
        $this->assertSame($pertama->aktif_pada, $sesudah->aktif_pada);
        $this->assertNotSame($pertama->terakhir_aktif_pada, $sesudah->terakhir_aktif_pada);
    }

    #[Test]
    public function perangkat_dari_beberapa_unit_tercatat_terpisah(): void
    {
        // Poin 6: jumlah perangkat tidak dibatasi, tetapi semuanya tercatat —
        // beserta unit asal dan alamat IP masing-masing.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $layanan = app(EventAbsenService::class);

        foreach ([[$upt, '10.0.1.5'], [$lain, '10.0.2.7']] as [$unit, $ip]) {
            $layanan->catatKioskAktif(
                $event,
                Kiosk::factory()->create(['unit_kerja_id' => $unit->id]),
                $ip,
            );
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->getJson(self::URL."/{$event->id}/detail")
            ->assertOk()
            ->assertJsonCount(2, 'kiosk');

        $this->assertEqualsCanonicalizing(
            [$upt->id, $lain->id],
            DB::table('event_kiosk')->pluck('unit_kerja_id')->all(),
        );
    }

    #[Test]
    public function perangkat_tidak_dicatat_pada_event_yang_sudah_ditutup(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create();
        $kiosk = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        app(EventAbsenService::class)->catatKioskAktif($event, $kiosk, '10.10.4.21');

        // Tidak ada perangkat yang sah "terhubung" ke entry yang sudah selesai.
        $this->assertDatabaseCount('event_kiosk', 0);
    }

    #[Test]
    public function daftar_event_menampilkan_jumlah_perangkat_terhubung(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $layanan = app(EventAbsenService::class);

        foreach (Kiosk::factory()->count(2)->create(['unit_kerja_id' => $upt->id]) as $kiosk) {
            $layanan->catatKioskAktif($event, $kiosk, '10.10.4.21');
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('daftar.data.0.jumlah_kiosk', 2)
                ->etc());
    }

    /* ---------------------------------------------------------------------
     * Tutup entry (FR-EVT-04).
     * ------------------------------------------------------------------- */

    #[Test]
    public function admin_dapat_menutup_entry_event_aktif(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

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
        $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create();

        $waktuTutup = $event->ditutup_pada;

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$event->id}/tutup")
            ->assertForbidden();

        // Waktu penutupan aslinya tidak boleh tergeser.
        $this->assertEquals($waktuTutup, $event->refresh()->ditutup_pada);
    }

    #[Test]
    public function penutupan_event_tercatat_pada_audit_trail(): void
    {
        // NFR-09: setiap perubahan status event tercatat dengan pelaku dan waktu.
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
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
        $this->hirarki();
        $admin = User::factory()->superadmin()->create();
        $event = EventAbsen::factory()->create();

        $this->actingAs($admin)->post(self::URL."/{$event->id}/tutup");

        // FR-EVT-06 tidak lagi menghalangi begitu event lama ditutup.
        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['nama' => 'Apel Berikutnya']))
            ->assertSessionHas('sukses');
    }

    /* ---------------------------------------------------------------------
     * Hapus keras — hanya selama belum ada absensi tertaut.
     * ------------------------------------------------------------------- */

    #[Test]
    public function event_tanpa_absensi_dapat_dihapus_permanen(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Salah Buat']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$event->id}")
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function event_yang_sudah_ditutup_tetap_dapat_dihapus_bila_belum_ada_absensi(): void
    {
        // Yang mengunci adalah adanya absensi, bukan statusnya.
        $this->hirarki();
        $event = EventAbsen::factory()->ditutup()->create();

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$event->id}")
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function event_yang_sudah_punya_absensi_tidak_dapat_dihapus(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create();

        Absensi::factory()->create(['event_absen_id' => $event->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$event->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('event_absen', 1);
    }

    #[Test]
    public function daftar_menandai_event_yang_terkunci_karena_absensi(): void
    {
        $this->hirarki();
        $terkunci = EventAbsen::factory()->create(['tanggal' => '2026-09-07', 'jam_mulai' => '07:30']);

        Absensi::factory()->create(['event_absen_id' => $terkunci->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar.data', 1)
                ->where('daftar.data.0.dapat_dihapus', false)
                ->where('daftar.data.0.jumlah_absensi', 1)
                ->etc());
    }

    #[Test]
    public function penghapusan_event_tercatat_pada_audit_trail(): void
    {
        $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Salah Buat']);
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->delete(self::URL."/{$event->id}");

        $log = LogAktivitas::aksi(AksiLog::Hapus)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertStringContainsString('Apel Salah Buat', $log->deskripsi);
    }

    /* ---------------------------------------------------------------------
     * Penyaringan, paginasi, dan ekspor daftar event.
     * ------------------------------------------------------------------- */

    #[Test]
    public function daftar_event_terpaginasi(): void
    {
        $this->hirarki();

        foreach (range(1, 20) as $urutan) {
            EventAbsen::factory()->ditutup()->create(['nama' => "Apel {$urutan}"]);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar.data', EventAbsenService::PER_HALAMAN)
                ->where('daftar.total', 20)
                ->etc());
    }

    #[Test]
    public function pencarian_menyaring_nama_event(): void
    {
        $this->hirarki();

        foreach (['Apel Pagi Senin', 'Rapat Koordinasi'] as $nama) {
            EventAbsen::factory()->ditutup()->create(['nama' => $nama]);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?cari=rapat')
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar.data', 1)
                ->where('daftar.data.0.nama', 'Rapat Koordinasi')
                ->etc());
    }

    #[Test]
    public function penyaring_status_dan_rentang_tanggal_bekerja(): void
    {
        $this->hirarki();

        EventAbsen::factory()->create(['nama' => 'Masih Aktif', 'tanggal' => '2026-09-07']);
        EventAbsen::factory()->ditutup()->create(['nama' => 'Sudah Lewat', 'tanggal' => '2026-08-01']);

        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->get(self::URL.'?status=aktif')
            ->assertInertia(fn (Assert $page) => $page->has('daftar.data', 1)->etc());

        $this->actingAs($admin)
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->has('daftar.data', 1)
                ->where('daftar.data.0.nama', 'Masih Aktif')
                ->etc());
    }

    #[Test]
    public function ekspor_event_memuat_seluruh_hasil_penyaringan(): void
    {
        // Bukan hanya halaman yang sedang dibuka.
        $this->hirarki();

        foreach (range(1, 20) as $urutan) {
            EventAbsen::factory()->ditutup()->create(['nama' => "Apel {$urutan}"]);
        }

        $isi = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor')
            ->assertOk()
            ->streamedContent();

        $this->assertSame(21, substr_count(trim($isi), "\r\n") + 1);
    }

    #[Test]
    public function ekspor_event_pdf_menghasilkan_berkas_pdf(): void
    {
        $this->hirarki();
        EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

        $isi = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=pdf')
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $isi);
    }

    #[Test]
    public function pembuatan_event_tercatat_pada_audit_trail(): void
    {
        $this->hirarki();
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post(self::URL, $this->isian());

        $log = LogAktivitas::aksi(AksiLog::Buat)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is(EventAbsen::sole()));
        $this->assertStringContainsString('seluruh unit kerja', $log->deskripsi);
    }
}
