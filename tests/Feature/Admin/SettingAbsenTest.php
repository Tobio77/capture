<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\KompresiFoto;
use App\Enums\PeranPengguna;
use App\Models\LogAktivitas;
use App\Models\User;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setting Absen — pengaturan global sistem (FR-SET-01 s.d. FR-SET-04).
 */
class SettingAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/setting';

    /**
     * @param  array<string, mixed>  $ubahan
     * @return array<string, mixed>
     */
    protected function isian(array $ubahan = []): array
    {
        return array_merge([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => true,
            'metode_wajah_aktif' => true,
            'toleransi_default_menit' => 15,
            'ambang_kecocokan_wajah' => 85,
            'kompresi_foto' => 'sedang',
            'absen_umum_aktif' => true,
            'absen_event_aktif' => true,
            'jam_masuk_umum' => '07:30',

            // FR-SET-07: jendela operasional wajib ikut, sebab formulirnya
            // selalu mengirim keempatnya sekaligus.
            'jam_buka_datang' => '06:00',
            'jam_tutup_datang' => '09:00',
            'jam_buka_pulang' => '15:00',
            'jam_tutup_pulang' => '18:00',

            // Jadwal jam per hari: formulir selalu mengirim ketujuh baris
            // sekaligus (lihat SimpanSettingAbsenRequest — size:7 wajib).
            'jadwal_mingguan' => collect(range(1, 7))->map(fn (int $hari) => [
                'hari' => $hari,
                'jam_masuk' => '07:30',
                'jam_buka_datang' => '06:00',
                'jam_tutup_datang' => '09:00',
                'jam_buka_pulang' => '15:00',
                'jam_tutup_pulang' => '18:00',
            ])->all(),

            'pendaftaran_perangkat_aktif' => false,

            'ambang_kehadiran_minimum' => 80,
            'ambang_keterlambatan_maksimum' => 15,
        ], $ubahan);
    }

    #[Test]
    public function halaman_menampilkan_nilai_bawaan_tanpa_perlu_seeding(): void
    {
        // Instalasi baru: belum ada satu pun baris pengaturan absen.
        $this->assertDatabaseCount('pengaturan', 0);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Setting/Absen')
                ->where('setting.metode_manual_aktif', true)
                ->where('setting.toleransi_default_menit', 15)
                ->where('setting.ambang_kecocokan_wajah', 85)
                ->where('setting.kompresi_foto', 'sedang')
                ->has('preset_kompresi', 3)
                ->where('batas.ambang_min', SettingAbsenService::AMBANG_MIN)
                ->etc());
    }

    #[Test]
    public function admin_dinas_dapat_menyimpan_setting(): void
    {
        $this->actingAs(User::factory()->create(['role' => PeranPengguna::AdminDinas]))
            ->post(self::URL, $this->isian([
                'metode_rfid_aktif' => false,
                'toleransi_default_menit' => 30,
                'ambang_kecocokan_wajah' => 92,
                'kompresi_foto' => 'tinggi',
            ]))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $setting = app(SettingAbsenService::class)->ambil();

        $this->assertTrue($setting['metode_manual_aktif']);
        $this->assertFalse($setting['metode_rfid_aktif']);
        $this->assertSame(30, $setting['toleransi_default_menit']);
        $this->assertSame(92, $setting['ambang_kecocokan_wajah']);
        $this->assertSame('tinggi', $setting['kompresi_foto']);
    }

    #[Test]
    public function metode_yang_dimatikan_tetap_nonaktif_setelah_dibaca_ulang(): void
    {
        // Nilai '0' tidak boleh diperlakukan sebagai "belum diatur" lalu
        // jatuh kembali ke bawaan true.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['metode_wajah_aktif' => false]));

        $this->assertFalse(app(SettingAbsenService::class)->ambil()['metode_wajah_aktif']);
    }

    #[Test]
    public function seluruh_metode_dimatikan_ditolak(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'metode_manual_aktif' => false,
                'metode_rfid_aktif' => false,
                'metode_wajah_aktif' => false,
            ]))
            ->assertSessionHasErrors('metode_manual_aktif');

        // Tidak ada yang tersimpan sama sekali.
        $this->assertDatabaseCount('pengaturan', 0);
    }

    #[Test]
    public function ambang_kecocokan_di_luar_rentang_ditolak(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['ambang_kecocokan_wajah' => 69]))
            ->assertSessionHasErrors('ambang_kecocokan_wajah');

        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['ambang_kecocokan_wajah' => 100]))
            ->assertSessionHasErrors('ambang_kecocokan_wajah');
    }

    #[Test]
    public function ambang_laporan_bawaan_delapan_puluh_dan_lima_belas(): void
    {
        // Instalasi baru, belum pernah diatur admin (FR-LAP-04).
        $setting = app(SettingAbsenService::class)->ambil();

        $this->assertSame(80, $setting['ambang_kehadiran_minimum']);
        $this->assertSame(15, $setting['ambang_keterlambatan_maksimum']);
    }

    #[Test]
    public function ambang_laporan_di_luar_rentang_ditolak(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['ambang_kehadiran_minimum' => 49]))
            ->assertSessionHasErrors('ambang_kehadiran_minimum');

        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['ambang_keterlambatan_maksimum' => 51]))
            ->assertSessionHasErrors('ambang_keterlambatan_maksimum');
    }

    #[Test]
    public function ambang_laporan_dapat_diubah_admin(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian([
                'ambang_kehadiran_minimum' => 75,
                'ambang_keterlambatan_maksimum' => 20,
            ]))
            ->assertSessionHas('sukses');

        $setting = app(SettingAbsenService::class)->ambil();

        $this->assertSame(75, $setting['ambang_kehadiran_minimum']);
        $this->assertSame(20, $setting['ambang_keterlambatan_maksimum']);
    }

    #[Test]
    public function toleransi_di_luar_rentang_ditolak(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->post(self::URL, $this->isian(['toleransi_default_menit' => -1]))
            ->assertSessionHasErrors('toleransi_default_menit');

        $this->actingAs($admin)
            ->post(self::URL, $this->isian([
                'toleransi_default_menit' => SettingAbsenService::TOLERANSI_MAKS_MENIT + 1,
            ]))
            ->assertSessionHasErrors('toleransi_default_menit');
    }

    #[Test]
    public function preset_kompresi_tak_dikenal_ditolak(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['kompresi_foto' => 'sangat-tinggi']))
            ->assertSessionHasErrors('kompresi_foto');
    }

    #[Test]
    public function preset_kompresi_membawa_dimensi_dan_kualitasnya(): void
    {
        // FR-SET-04 menuntut dimensi maksimum dan kualitas JPEG, bukan sekadar
        // nama preset — kiosk membaca angka ini saat menyusutkan foto.
        $this->assertSame(480, KompresiFoto::Ringan->dimensiMaks());
        $this->assertSame(70, KompresiFoto::Ringan->kualitas());
        $this->assertSame(640, KompresiFoto::Tinggi->dimensiMaks());
        $this->assertSame(80, KompresiFoto::Tinggi->kualitas());

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['kompresi_foto' => 'ringan']));

        $this->assertSame(KompresiFoto::Ringan, app(SettingAbsenService::class)->kompresi());
    }

    #[Test]
    public function tidak_ada_preset_yang_melampaui_batas_penyimpanan_nfr06(): void
    {
        // NFR-06: satu foto absen tersimpan tidak melebihi ~90 KB. Angka
        // terburuk berasal dari pengukuran nyata, bukan taksiran; bila preset
        // diubah, ukur ulang dan perbarui ukuranTerburukKb().
        foreach (KompresiFoto::cases() as $preset) {
            $this->assertLessThanOrEqual(
                KompresiFoto::BATAS_UKURAN_KB,
                $preset->ukuranTerburukKb(),
                "Preset {$preset->value} melampaui batas NFR-06.",
            );
        }
    }

    #[Test]
    public function preset_kompresi_menaik_konsisten_dari_ringan_ke_tinggi(): void
    {
        // Penjaga arah: preset yang lebih tinggi harus selalu lebih tajam dan
        // lebih besar, tidak boleh terbalik karena penyuntingan di kemudian hari.
        $urut = [KompresiFoto::Ringan, KompresiFoto::Sedang, KompresiFoto::Tinggi];

        for ($i = 1; $i < count($urut); $i++) {
            $this->assertGreaterThan($urut[$i - 1]->dimensiMaks(), $urut[$i]->dimensiMaks());
            $this->assertGreaterThan($urut[$i - 1]->kualitas(), $urut[$i]->kualitas());
            $this->assertGreaterThan($urut[$i - 1]->ukuranTerburukKb(), $urut[$i]->ukuranTerburukKb());
        }
    }

    #[Test]
    public function admin_upt_tidak_dapat_membuka_maupun_menyimpan_setting_absen(): void
    {
        // Setting Absen bersifat global, bukan milik satu unit (SRS §6).
        $admin = User::factory()->adminUpt()->create();

        $this->actingAs($admin)->get(self::URL)->assertForbidden();
        $this->actingAs($admin)->post(self::URL, $this->isian())->assertForbidden();
    }

    #[Test]
    public function perubahan_tercatat_pada_audit_trail_beserta_medannya(): void
    {
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post(self::URL, $this->isian([
            'ambang_kecocokan_wajah' => 95,
        ]));

        $log = LogAktivitas::aksi(AksiLog::Ubah)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertStringContainsString('ambang_kecocokan_wajah', $log->deskripsi);
        $this->assertStringContainsString('85 → 95', $log->deskripsi);
    }

    #[Test]
    public function penyimpanan_tanpa_perubahan_tidak_menambah_catatan_audit(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->post(self::URL, $this->isian());
        $this->actingAs($admin)->post(self::URL, $this->isian());

        // Menyimpan nilai yang sama persis bukan peristiwa yang perlu dicatat.
        $this->assertSame(0, LogAktivitas::aksi(AksiLog::Ubah)->count());
    }
}
