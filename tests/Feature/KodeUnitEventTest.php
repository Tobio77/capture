<?php

namespace Tests\Feature;

use App\Enums\CakupanEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\KodeUnitEvent;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\KodeUnitEventService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kode unit kerja per event (FR-EVT-03, revisi S29).
 *
 * Sampai S28b, sebuah perangkat melayani event semata-mata karena unit tempat
 * ia dipasang termasuk cakupan event. Akibatnya setiap perangkat di unit itu
 * ikut terseret ke kegiatan yang tidak ada hubungannya dengan ruangan tempat
 * ia berdiri, dan panitia tidak punya cara menyatakan perangkat mana yang
 * benar-benar melayani kegiatannya.
 *
 * Berkas ini mengunci mekanisme penggantinya beserta batas-batasnya — termasuk
 * satu yang paling mudah tergerus tanpa disadari: Mode Terbuka TIDAK boleh
 * menjadi jalan pintas melewati kode ini.
 */
class KodeUnitEventTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/event';

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

    /**
     * Buat event lewat panel admin, sehingga kodenya terbit seperti biasa.
     *
     * @param  array<int, int>|null  $unitKerjaId
     */
    protected function buatEvent(string $cakupan = 'unit', ?array $unitKerjaId = null): EventAbsen
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, [
                'nama' => 'Apel Pagi',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => $cakupan,
                'unit_kerja_id' => $unitKerjaId ?? [$this->upt->id],
                'catatan' => null,
            ])
            ->assertSessionHas('sukses');

        return EventAbsen::query()->latest('id')->sole();
    }

    protected function perangkat(string $token, ?UnitKerja $unit = null): Kiosk
    {
        return Kiosk::factory()->diaktifkan($token)->create([
            'unit_kerja_id' => ($unit ?? $this->upt)->id,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Penerbitan kode.
     * ------------------------------------------------------------------- */

    #[Test]
    public function kode_terbit_satu_per_unit_kerja_dalam_cakupan_event(): void
    {
        $event = $this->buatEvent(unitKerjaId: [$this->upt->id, $this->uptLain->id]);

        $this->assertSame(
            ['BLK-MJK', 'BLK-SBY'],
            $event->kodeUnit()->with('unitKerja')->get()
                ->pluck('unitKerja.kode')->sort()->values()->all(),
        );
    }

    #[Test]
    public function kode_tidak_memakai_karakter_yang_mudah_tertukar(): void
    {
        /*
         * Keputusan S04 pada kode aktivasi perangkat, berlaku sama di sini:
         * kode ini dibacakan lewat telepon dan ditulis di kertas, sehingga
         * 0/O dan 1/I tidak boleh muncul sama sekali.
         */
        $kode = $this->buatEvent()->kodeUnit()->sole()->kode;

        $this->assertSame(KodeUnitEventService::PANJANG_KODE, strlen($kode));
        $this->assertDoesNotMatchRegularExpression('/[01OI]/', $kode);
    }

    #[Test]
    public function event_semua_unit_memperoleh_kode_untuk_tiap_unit_level_teratas(): void
    {
        // Cakupan "semua unit" tidak menyimpan baris pivot, sehingga daftar
        // unitnya harus dirakit dari unit level teratas yang aktif.
        $event = $this->buatEvent(cakupan: CakupanEvent::SemuaUnit->value, unitKerjaId: []);

        $this->assertSame(2, $event->kodeUnit()->count());
    }

    #[Test]
    public function mengubah_cakupan_menambah_dan_membuang_kode_tanpa_mengganggu_yang_bertahan(): void
    {
        $event = $this->buatEvent();
        $kodeSemula = $event->kodeUnit()->sole()->kode;

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$event->id}", [
                'nama' => 'Apel Pagi',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => 'unit',
                'unit_kerja_id' => [$this->upt->id, $this->uptLain->id],
                'catatan' => null,
            ])
            ->assertSessionHas('sukses');

        /*
         * Unit yang tetap dalam cakupan MEMPERTAHANKAN kodenya. Panitia sudah
         * membacakannya kepada petugas; menggantinya diam-diam hanya karena
         * admin menambah unit lain akan membuat seluruh perangkat di unit itu
         * gagal bergabung tanpa penjelasan.
         */
        $this->assertSame(
            $kodeSemula,
            $event->kodeUnit()->where('unit_kerja_id', $this->upt->id)->sole()->kode,
        );

        $this->assertSame(2, $event->kodeUnit()->count());
    }

    /* ---------------------------------------------------------------------
     * Penggabungan perangkat.
     * ------------------------------------------------------------------- */

    #[Test]
    public function perangkat_bergabung_dengan_menukarkan_kode_dan_ip_nya_tercatat(): void
    {
        $event = $this->buatEvent();
        $perangkat = $this->perangkat('token-a');
        $kode = $event->kodeUnit()->sole();

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->post('/kiosk/event/gabung', ['kode' => KodeUnitEventService::format($kode->kode)])
            ->assertRedirect('/kiosk/event');

        $this->assertDatabaseHas('event_kiosk', [
            'event_absen_id' => $event->id,
            'kiosk_id' => $perangkat->id,
            'unit_kerja_id' => $this->upt->id,
            'ip_address' => '127.0.0.1',
        ]);
    }

    #[Test]
    public function satu_kode_boleh_dipakai_beberapa_perangkat_pada_unit_yang_sama(): void
    {
        // Satu unit kerap membuka beberapa meja registrasi pada kegiatan yang
        // sama; kode ini bukan kode sekali pakai seperti kode aktivasi.
        $event = $this->buatEvent();
        $kode = KodeUnitEventService::format($event->kodeUnit()->sole()->kode);

        foreach (['token-a', 'token-b'] as $token) {
            $this->perangkat($token);

            $this->withCookie(KioskService::NAMA_COOKIE, $token)
                ->post('/kiosk/event/gabung', ['kode' => $kode])
                ->assertRedirect('/kiosk/event');
        }

        $this->assertSame(2, $event->kiosk()->count());
    }

    #[Test]
    public function kode_salah_ditolak_tanpa_membocorkan_event_mana_yang_ada(): void
    {
        $this->buatEvent();
        $this->perangkat('token-a');

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->post('/kiosk/event/gabung', ['kode' => 'ZZZZ-ZZZZ'])
            ->assertSessionHasErrors('kode');

        $this->assertDatabaseCount('event_kiosk', 0);
    }

    #[Test]
    public function kode_event_yang_sudah_ditutup_ditolak_dengan_pesan_yang_sama(): void
    {
        /*
         * Membedakan "kode salah" dari "event ditutup" akan mengubah kolom ini
         * menjadi alat menebak: penebak langsung tahu ia sudah menemukan kode
         * yang benar.
         */
        $event = $this->buatEvent();
        $kode = $event->kodeUnit()->sole()->kode;
        $this->perangkat('token-a');

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$event->id}/tutup")
            ->assertSessionHas('sukses');

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->post('/kiosk/event/gabung', ['kode' => $kode])
            ->assertSessionHasErrors('kode');

        $this->assertDatabaseCount('event_kiosk', 0);
    }

    #[Test]
    public function perangkat_dapat_keluar_dari_event_tanpa_kehilangan_device_token(): void
    {
        /*
         * Satu perangkat kerap melayani beberapa kegiatan berturut-turut, dan
         * kerap dipindahkan antar ruangan. Tanpa jalan keluar ini, satu-satunya
         * cara melepaskannya adalah mencabut device token — yang berarti
         * mengaktivasi ulang perangkatnya.
         */
        $event = $this->buatEvent();
        $perangkat = $this->perangkat('token-a');
        $this->gabungkanKeEvent($event, $perangkat);

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->post('/kiosk/event/keluar')
            ->assertRedirect('/');

        $this->assertDatabaseCount('event_kiosk', 0);
        $this->assertNotNull($perangkat->fresh()->device_token);
    }

    /* ---------------------------------------------------------------------
     * Mode Terbuka bukan jalan pintas.
     * ------------------------------------------------------------------- */

    #[Test]
    public function mode_terbuka_tidak_membuka_absen_event_tanpa_kode(): void
    {
        /*
         * Dua mekanisme yang tujuannya mirip — memudahkan akses darurat —
         * tetapi cakupannya terpisah total. Mode Terbuka (FR-SET-06)
         * melonggarkan KODE AKTIVASI: ia menentukan boleh-tidaknya sebuah mesin
         * menjadi titik absen sama sekali. Kode unit kerja menentukan event
         * mana yang dilayani titik absen itu.
         *
         * Membiarkan yang pertama melonggarkan yang kedua berarti, selama Mode
         * Terbuka menyala, mesin mana pun yang dapat menjangkau alamat server
         * langsung menjadi titik absen sebuah kegiatan.
         */
        app(PengaturanRepository::class)->simpan(SettingAbsenService::KUNCI_WAJIB_KODE_AKTIVASI, '0');

        $this->buatEvent();

        // Perangkat ad-hoc masuk tanpa kode aktivasi — itu memang yang
        // dijanjikan Mode Terbuka.
        $respons = $this->post('/kiosk/aktivasi/terbuka', ['unit_kerja_id' => $this->upt->id])
            ->assertRedirect('/');

        $token = $respons->getCookie(KioskService::NAMA_COOKIE)->getValue();

        // Absen Umum terbuka baginya.
        $this->withCookie(KioskService::NAMA_COOKIE, $token)
            ->get('/kiosk/umum')
            ->assertOk();

        // Absen Event tidak: ia belum menukarkan kode unit kerja.
        $this->withCookie(KioskService::NAMA_COOKIE, $token)
            ->get('/kiosk/event')
            ->assertRedirect('/')
            ->assertSessionHas('gagal');

        $this->assertDatabaseCount('event_kiosk', 0);
    }

    /* ---------------------------------------------------------------------
     * Reset kode — siapa yang berwenang.
     * ------------------------------------------------------------------- */

    #[Test]
    public function admin_upt_dapat_mereset_kode_event_unitnya_sendiri(): void
    {
        $event = $this->buatEvent();
        $kode = $event->kodeUnit()->sole();

        $this->actingAs(User::factory()->adminUpt($this->upt)->create())
            ->post(self::URL."/{$event->id}/kode/{$kode->id}/reset")
            ->assertSessionHas('sukses');

        $this->assertNotSame($kode->kode, $kode->fresh()->kode);
        $this->assertNotNull($kode->fresh()->direset_pada);
    }

    #[Test]
    public function admin_upt_tidak_dapat_mereset_kode_event_unit_lain(): void
    {
        $event = $this->buatEvent(unitKerjaId: [$this->uptLain->id]);
        $kode = $event->kodeUnit()->sole();

        $this->actingAs(User::factory()->adminUpt($this->upt)->create())
            ->post(self::URL."/{$event->id}/kode/{$kode->id}/reset")
            ->assertForbidden();

        $this->assertSame($kode->kode, $kode->fresh()->kode);
    }

    #[Test]
    public function admin_upt_tidak_dapat_mereset_kode_event_semua_unit(): void
    {
        /*
         * Event bercakupan semua unit dibuat dan dikelola tingkat dinas, meski
         * unit Admin UPT ikut tercakup — pagar yang sama dengan mengubah dan
         * menutup event (matriks peran SRS §6).
         */
        $event = $this->buatEvent(cakupan: CakupanEvent::SemuaUnit->value, unitKerjaId: []);
        $kode = $event->kodeUnit()->where('unit_kerja_id', $this->upt->id)->sole();

        $this->actingAs(User::factory()->adminUpt($this->upt)->create())
            ->post(self::URL."/{$event->id}/kode/{$kode->id}/reset")
            ->assertForbidden();
    }

    #[Test]
    public function reset_tidak_memutus_perangkat_yang_sudah_bergabung(): void
    {
        /*
         * Reset menutup pintu bagi yang belum masuk — kode yang telanjur
         * beredar ke luar ruangan — bukan mengusir titik absen yang sedang
         * melayani antrean pegawai di tengah apel.
         */
        $event = $this->buatEvent();
        $perangkat = $this->perangkat('token-a');
        $kode = $event->kodeUnit()->sole();

        $this->gabungkanKeEvent($event, $perangkat);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$event->id}/kode/{$kode->id}/reset")
            ->assertSessionHas('sukses');

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->get('/kiosk/event')
            ->assertOk();
    }

    #[Test]
    public function kode_lama_tidak_lagi_dapat_ditukarkan_setelah_direset(): void
    {
        $event = $this->buatEvent();
        $kode = $event->kodeUnit()->sole();
        $kodeLama = $kode->kode;

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$event->id}/kode/{$kode->id}/reset")
            ->assertSessionHas('sukses');

        $this->perangkat('token-a');

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->post('/kiosk/event/gabung', ['kode' => $kodeLama])
            ->assertSessionHasErrors('kode');
    }

    #[Test]
    public function kode_satu_event_tidak_dapat_direset_lewat_event_lain(): void
    {
        // Id kode datang dari URL; tanpa pemeriksaan ini, admin yang berwenang
        // atas satu event dapat mengganti kode event yang bukan haknya.
        $milikUpt = $this->buatEvent();
        $kodeUptLain = KodeUnitEvent::factory()->create([
            'event_absen_id' => EventAbsen::factory()->create()->id,
            'unit_kerja_id' => $this->uptLain->id,
        ]);

        $this->actingAs(User::factory()->adminUpt($this->upt)->create())
            ->post(self::URL."/{$milikUpt->id}/kode/{$kodeUptLain->id}/reset")
            ->assertNotFound();
    }
}
