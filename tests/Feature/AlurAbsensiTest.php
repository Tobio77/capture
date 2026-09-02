<?php

namespace Tests\Feature;

use App\Enums\StatusKetepatan;
use App\Models\Absensi;
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
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengujian fungsional alur absensi dari ujung ke ujung (S25).
 *
 * Test lain menguji tiap bagian secara terpisah dengan factory. Berkas ini
 * menjahit seluruh perjalanan lewat HTTP — admin membuat event, perangkat
 * diaktifkan dengan kode sungguhan, pegawai men-tap, sampai admin menutup
 * entry — sehingga celah antar bagian ikut tertangkap: nama route yang
 * keliru, kontrak payload yang bergeser, atau service yang lupa dirangkai.
 */
class AlurAbsensiTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $upt;

    protected User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $this->superadmin = User::factory()->superadmin()->create();

        // Verifikasi wajah dimatikan: alur yang diuji di sini adalah rangkaian
        // langkahnya, bukan pencocokan wajah yang hanya berjalan di peramban.
        app(SettingAbsenService::class)->simpan([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => true,
            'metode_wajah_aktif' => false,
            'toleransi_default_menit' => 15,
            'ambang_kecocokan_wajah' => 85,
            'kompresi_foto' => 'sedang',
        ], $this->superadmin);
    }

    /**
     * Daftarkan perangkat lewat panel admin, lalu tukarkan kode aktivasinya
     * dengan device token — persis seperti petugas di lokasi.
     */
    protected function pasangPerangkat(string $namaTitik = 'Aula Utama'): Kiosk
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/perangkat', [
                'nama_titik' => $namaTitik,
                'unit_kerja_id' => $this->upt->id,
            ])
            ->assertSessionHas('kode_aktivasi');

        $kode = session('kode_aktivasi')['kode'];

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => $kode])
            ->assertRedirect('/kiosk');

        return Kiosk::query()->where('nama_titik', $namaTitik)->sole();
    }

    protected function denganPerangkat(Kiosk $perangkat): static
    {
        // Token mentah hanya ada di cookie perangkat; test menirunya dengan
        // menerbitkan token baru lewat service.
        $token = 'token-uji-'.$perangkat->id;

        $perangkat->forceFill(['device_token' => KioskService::hashToken($token)])->save();

        return $this->withCookie(KioskService::NAMA_COOKIE, $token);
    }

    protected function buatEvent(string $jamMulai = '07:30', int $toleransi = 15): EventAbsen
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/kelola-absen/event', [
                'nama' => 'Apel Pagi',
                'tanggal' => '2026-09-07',
                'jam_mulai' => $jamMulai,
                'toleransi_menit' => $toleransi,
                'cakupan' => 'unit',
                'unit_kerja_id' => [$this->upt->id],
                'catatan' => null,
            ])
            ->assertSessionHas('sukses');

        return EventAbsen::query()->latest('id')->sole();
    }

    /**
     * @param  array<string, mixed>  $ubahan
     */
    protected function tap(Kiosk $perangkat, string $idCard, array $ubahan = []): TestResponse
    {
        return $this->denganPerangkat($perangkat)->post('/kiosk/absen', array_merge([
            'id_card' => $idCard,
            'jenis' => 'datang',
            'metode' => 'manual',
        ], $ubahan), ['Accept' => 'application/json']);
    }

    #[Test]
    public function alur_penuh_dari_pendaftaran_perangkat_sampai_rekap(): void
    {
        $this->travelTo('2026-09-07 07:20:00');

        $perangkat = $this->pasangPerangkat();
        $event = $this->buatEvent();

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $this->upt->id,
        ]);

        // Admin menautkan kartu RFID pegawai.
        $this->actingAs($this->superadmin)
            ->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '04:A3:B2:1C'])
            ->assertSessionHas('sukses');

        // Perangkat mengenali kartu yang di-tap.
        $this->travelTo('2026-09-07 07:35:00');

        $this->denganPerangkat($perangkat)
            ->post('/kiosk/tap/identifikasi', ['id_card' => '04a3b21c'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['nama' => 'Ahmad Fauzi', 'metode' => 'rfid']]);

        // Absen tersimpan dan langsung tampil pada Daftar e-Presensi.
        $this->tap($perangkat, '04a3b21c', ['metode' => 'rfid'])
            ->assertOk()
            ->assertJson([
                'data' => [
                    'status_ketepatan' => 'tepat',
                    'daftar_presensi' => [['nama' => 'Ahmad Fauzi', 'jam_masuk' => '07:35']],
                ],
            ]);

        // FR-EVT-03: perangkat tercatat terhubung pada event ini.
        $this->assertDatabaseHas('event_kiosk', [
            'event_absen_id' => $event->id,
            'kiosk_id' => $perangkat->id,
        ]);

        // Admin menutup entry; tap berikutnya ditolak. Absen umum dimatikan
        // lebih dahulu — bila menyala, tap justru dilayani sesi harian, dan
        // yang diuji di sini adalah penolakan oleh event yang sudah ditutup.
        $this->matikanAbsenUmum();

        $this->actingAs($this->superadmin)
            ->post("/admin/kelola-absen/event/{$event->id}/tutup")
            ->assertSessionHas('sukses');

        $this->tap($perangkat, '04a3b21c')
            ->assertStatus(409)
            ->assertJson(['code' => 'EVENT_TIDAK_AKTIF']);

        // Rekap tetap memuat kehadirannya setelah event ditutup.
        $this->actingAs($this->superadmin)
            ->get("/admin/kelola-absen/rekap?event_absen_id={$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ringkasan.hadir', 1)
                ->where('ringkasan.tepat', 1)
                ->where('rekap.0.jam_masuk', '07:35')
                ->etc());
    }

    #[Test]
    public function tap_tepat_pada_batas_toleransi_masih_dihitung_tepat_waktu(): void
    {
        /*
         * FR-TAP-07. Batasnya inklusif: pegawai yang tap persis pada detik
         * terakhir toleransi tidak boleh dinyatakan terlambat.
         */
        $perangkat = $this->pasangPerangkat();
        $this->buatEvent(jamMulai: '07:30', toleransi: 15);

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->travelTo('2026-09-07 07:45:00');

        $this->tap($perangkat, $pegawai->nip)->assertOk();

        $this->assertSame(StatusKetepatan::Tepat, Absensi::sole()->status_ketepatan);
    }

    #[Test]
    public function tap_satu_detik_setelah_batas_dihitung_terlambat(): void
    {
        $perangkat = $this->pasangPerangkat();
        $this->buatEvent(jamMulai: '07:30', toleransi: 15);

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->travelTo('2026-09-07 07:45:01');

        $this->tap($perangkat, $pegawai->nip)->assertOk();

        $this->assertSame(StatusKetepatan::Terlambat, Absensi::sole()->status_ketepatan);
    }

    #[Test]
    public function toleransi_nol_menjadikan_jam_mulai_sebagai_batasnya(): void
    {
        // Event tanpa toleransi: tepat berarti tepat pada jam mulai.
        $perangkat = $this->pasangPerangkat();
        $this->buatEvent(jamMulai: '07:30', toleransi: 0);

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->travelTo('2026-09-07 07:30:00');
        $this->tap($perangkat, $pegawai->nip)->assertOk();
        $this->assertSame(StatusKetepatan::Tepat, Absensi::sole()->status_ketepatan);

        $this->travelTo('2026-09-07 07:30:30');
        $this->tap($perangkat, $pegawai->nip)->assertOk();
        $this->assertSame(StatusKetepatan::Terlambat, Absensi::sole()->refresh()->status_ketepatan);
    }

    #[Test]
    public function dua_perangkat_pada_event_yang_sama_saling_melihat_kehadiran(): void
    {
        // FR-TAP-08 dari ujung ke ujung: tap di satu titik absen muncul pada
        // Daftar e-Presensi titik lainnya.
        $this->travelTo('2026-09-07 07:35:00');

        $aula = $this->pasangPerangkat('Aula Utama');
        $lobi = $this->pasangPerangkat('Lobi Depan');
        $this->buatEvent();

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->tap($aula, $pegawai->nip)->assertOk();

        $this->denganPerangkat($lobi)
            ->get('/kiosk/presensi', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['daftar_presensi' => [['nama' => 'Ahmad Fauzi']]]);
    }

    #[Test]
    public function perangkat_yang_aksesnya_dicabut_admin_berhenti_melayani_tap(): void
    {
        $this->travelTo('2026-09-07 07:35:00');

        $perangkat = $this->pasangPerangkat();
        $this->buatEvent();

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->tap($perangkat, $pegawai->nip)->assertOk();

        // Admin mencabut aksesnya, misalnya karena perangkat dipindahkan.
        $this->actingAs($this->superadmin)
            ->delete("/admin/perangkat/{$perangkat->id}/token")
            ->assertSessionHas('sukses');

        // Token lama tidak lagi berlaku; perangkat harus diaktifkan ulang.
        $this->withCookie(KioskService::NAMA_COOKIE, 'token-uji-'.$perangkat->id)
            ->post('/kiosk/absen', [
                'id_card' => $pegawai->nip,
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function absen_pulang_menutup_baris_yang_sama_pada_rekap(): void
    {
        $perangkat = $this->pasangPerangkat();
        $event = $this->buatEvent();

        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->travelTo('2026-09-07 07:35:00');
        $this->tap($perangkat, $pegawai->nip)->assertOk();

        $this->travelTo('2026-09-07 16:05:00');
        $this->tap($perangkat, $pegawai->nip, ['jenis' => 'pulang'])->assertOk();

        $this->actingAs($this->superadmin)
            ->get("/admin/kelola-absen/rekap?event_absen_id={$event->id}")
            ->assertInertia(fn (Assert $page) => $page
                // Dua baris absensi, satu baris rekap.
                ->has('rekap', 1)
                ->where('rekap.0.jam_masuk', '07:35')
                ->where('rekap.0.jam_pulang', '16:05')
                ->where('ringkasan.sudah_pulang', 1)
                ->etc());

        $this->assertDatabaseCount('absensi', 2);
    }

    #[Test]
    public function event_baru_pada_unit_yang_sama_tertahan_sampai_event_lama_ditutup(): void
    {
        // FR-EVT-06 dari ujung ke ujung.
        $this->pasangPerangkat();
        $event = $this->buatEvent();

        $this->actingAs($this->superadmin)
            ->post('/admin/kelola-absen/event', [
                'nama' => 'Apel Sore',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '16:00',
                'toleransi_menit' => 15,
                'cakupan' => 'unit',
                'unit_kerja_id' => [$this->upt->id],
                'catatan' => null,
            ])
            ->assertSessionHasErrors('cakupan');

        $this->actingAs($this->superadmin)->post("/admin/kelola-absen/event/{$event->id}/tutup");

        $this->actingAs($this->superadmin)
            ->post('/admin/kelola-absen/event', [
                'nama' => 'Apel Sore',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '16:00',
                'toleransi_menit' => 15,
                'cakupan' => 'unit',
                'unit_kerja_id' => [$this->upt->id],
                'catatan' => null,
            ])
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('event_absen', 2);
    }

    #[Test]
    public function laporan_menghitung_kemangkiran_setelah_event_berlalu(): void
    {
        // Dua pegawai, satu event, satu hadir — yang lain tanpa keterangan.
        $perangkat = $this->pasangPerangkat();
        $this->buatEvent();

        $hadir = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);
        Pegawai::factory()->create([
            'nip' => '199001012020011002',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->travelTo('2026-09-07 07:35:00');
        $this->tap($perangkat, $hadir->nip)->assertOk();

        $this->actingAs($this->superadmin)
            ->get('/admin/laporan?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ringkasan.hadir', 1)
                ->where('ringkasan.tanpa_keterangan', 1)
                ->etc());
    }
}
