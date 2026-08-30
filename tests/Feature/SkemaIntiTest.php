<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Enums\StatusKiosk;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SkemaIntiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unit_kerja_memiliki_pegawai_kiosk_dan_pengguna(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $unitKerja->id]);
        Kiosk::factory()->count(2)->create(['unit_kerja_id' => $unitKerja->id]);
        User::factory()->adminUpt($unitKerja)->create();

        // FR-UNIT-02: jumlah pegawai dan kiosk terdaftar per unit kerja.
        $unitKerja->loadCount(['pegawai', 'kiosk', 'users']);

        $this->assertSame(3, $unitKerja->pegawai_count);
        $this->assertSame(2, $unitKerja->kiosk_count);
        $this->assertSame(1, $unitKerja->users_count);
    }

    #[Test]
    public function nip_pegawai_bersifat_unik(): void
    {
        Pegawai::factory()->create(['nip' => '198503122010011002']);

        $this->expectException(QueryException::class);

        Pegawai::factory()->create(['nip' => '198503122010011002']);
    }

    #[Test]
    public function pegawai_dapat_dicari_berdasarkan_nama_atau_nip(): void
    {
        $unitKerja = UnitKerja::factory()->create();
        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'nip' => '199002142014031004', 'unit_kerja_id' => $unitKerja->id]);
        Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'nip' => '199206302015022005', 'unit_kerja_id' => $unitKerja->id]);

        // FR-PEG-04
        $this->assertSame('Ahmad Fauzi', Pegawai::cari('ahmad')->sole()->nama);
        $this->assertSame('Dewi Anggraini', Pegawai::cari('19920630')->sole()->nama);
        $this->assertCount(2, Pegawai::cari(null)->get());
    }

    #[Test]
    public function peran_pengguna_menentukan_cakupan_unit_kerja(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $adminDinas = User::factory()->create(['role' => PeranPengguna::AdminDinas]);
        $adminUpt = User::factory()->adminUpt()->create();

        $this->assertTrue($superadmin->lintasUnit());
        $this->assertTrue($adminDinas->lintasUnit());
        $this->assertFalse($adminUpt->lintasUnit());

        $this->assertTrue($adminUpt->berperan(PeranPengguna::AdminUpt));
        $this->assertFalse($adminUpt->berperan(PeranPengguna::Superadmin, PeranPengguna::AdminDinas));

        // Admin UPT wajib terikat pada satu unit kerja.
        $this->assertNotNull($adminUpt->unit_kerja_id);
        $this->assertNull($superadmin->unit_kerja_id);
    }

    #[Test]
    public function kiosk_berstatus_offline_secara_baku_dan_device_token_tidak_terekspos(): void
    {
        $kiosk = Kiosk::factory()->create();

        $this->assertSame(StatusKiosk::Offline, $kiosk->status);
        $this->assertTrue($kiosk->aktif);
        $this->assertArrayNotHasKey('device_token', $kiosk->toArray());
    }

    #[Test]
    public function status_wajah_terdaftar_pegawai_berawal_dari_belum_terdaftar(): void
    {
        // FR-PEG-03
        $belum = Pegawai::factory()->create();
        $sudah = Pegawai::factory()->wajahTerdaftar()->create();

        $this->assertFalse($belum->wajah_terdaftar);
        $this->assertNull($belum->foto_referensi_path);
        $this->assertTrue($sudah->wajah_terdaftar);
        $this->assertNotNull($sudah->foto_referensi_path);
    }
}
