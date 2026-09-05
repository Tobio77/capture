<?php

namespace App\Http\Requests;

use App\Enums\CakupanEvent;
use App\Models\UnitKerja;
use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pembuatan/pembaruan event absensi (FR-EVT-01, FR-EVT-02).
 */
class SimpanEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:150'],
            'tanggal' => ['required', 'date'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'toleransi_menit' => [
                'required', 'integer', 'min:0', 'max:'.SettingAbsenService::TOLERANSI_MAKS_MENIT,
            ],
            'cakupan' => ['required', Rule::enum(CakupanEvent::class)],
            'unit_kerja_id' => ['array'],
            'unit_kerja_id.*' => ['integer', 'exists:unit_kerja,id'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->periksaCakupan($validator);

            if ($validator->errors()->isEmpty()) {
                $this->periksaBentrok($validator);
            }
        });
    }

    /**
     * FR-EVT-06: tidak boleh ada dua event aktif yang cakupan unit kerjanya
     * beririsan — kiosk pada unit itu tidak akan tahu tap milik event mana.
     *
     * Jadwal tidak ikut menentukan; menutup event yang lebih dulu berjalan
     * adalah satu-satunya jalan keluar.
     */
    protected function periksaBentrok(Validator $validator): void
    {
        $bentrok = app(EventAbsenService::class)->eventBentrok(
            [
                'cakupan' => $this->input('cakupan'),
                'unit_kerja_id' => (array) $this->input('unit_kerja_id', []),
            ],
            kecuali: $this->route('event'),
        );

        if ($bentrok === null) {
            return;
        }

        $cakupan = $bentrok->berlakuUntukSemuaUnit()
            ? 'seluruh unit kerja'
            : $bentrok->unitKerja->pluck('kode')->implode(', ');

        $validator->errors()->add('cakupan', sprintf(
            'Event "%s" (%s, mencakup %s) masih aktif dan cakupannya beririsan. Tutup event tersebut lebih dulu.',
            $bentrok->nama,
            $bentrok->tanggal->format('d-m-Y'),
            $cakupan,
        ));
    }

    /**
     * FR-EVT-02: Admin UPT hanya boleh memilih unit kerjanya sendiri, dan
     * tidak boleh memakai cakupan yang melampaui satu unit.
     */
    protected function periksaCakupan(Validator $validator): void
    {
        $pengguna = $this->user();
        $cakupan = CakupanEvent::from($this->input('cakupan'));

        if ($cakupan->lintasUnit() && ! $pengguna->lintasUnit()) {
            $validator->errors()->add('cakupan', sprintf(
                'Cakupan "%s" hanya tersedia untuk Superadmin dan Admin Dinas.',
                $cakupan->label(),
            ));

            return;
        }

        if ($cakupan === CakupanEvent::SemuaUnit) {
            return;
        }

        /*
         * Cakupan bawaan sistem tidak dicentang admin, sehingga tidak ada yang
         * perlu diperiksa terhadap hak pilihnya. Yang justru harus diperiksa
         * adalah unitnya benar-benar ADA: daftarnya tertanam sebagai kode
         * ({@see CakupanEvent::KODE_WILAYAH_SURABAYA}), dan kode yang tidak
         * cocok dengan hasil sinkronisasi WORKA akan diam-diam menghasilkan
         * event bercakupan lebih sempit daripada yang dikira panitia.
         */
        if ($cakupan->unitTertanam()) {
            $this->periksaCakupanTertanam($validator, $cakupan);

            return;
        }

        $dipilih = array_map('intval', (array) $this->input('unit_kerja_id', []));

        if ($dipilih === []) {
            $validator->errors()->add('unit_kerja_id', 'Pilih minimal satu unit kerja.');

            return;
        }

        $boleh = app(EventAbsenService::class)
            ->unitKerjaTersedia($pengguna)
            ->pluck('id')
            ->all();

        $diluar = array_diff($dipilih, $boleh);

        if ($diluar !== []) {
            $validator->errors()->add(
                'unit_kerja_id',
                $pengguna->lintasUnit()
                    // Unit yang ada tetapi bukan level teratas: event
                    // diselenggarakan pada tingkat UPT/bidang (SDD §3.1).
                    ? 'Cakupan event hanya dapat memakai unit kerja level teratas yang aktif.'
                    : 'Anda hanya dapat memilih unit kerja Anda sendiri sebagai cakupan event.',
            );
        }
    }

    /**
     * Unit penyusun cakupan bawaan harus lengkap.
     *
     * Kode yang tidak ditemukan berarti nama unitnya berubah di WORKA atau
     * belum pernah tersinkron. Menyimpan event dengan cakupan yang bolong
     * lebih berbahaya daripada menolaknya: pegawai unit yang hilang tidak akan
     * dapat mengabsen, dan tidak ada yang menyadarinya sampai hari-H.
     */
    protected function periksaCakupanTertanam(Validator $validator, CakupanEvent $cakupan): void
    {
        $kodeDicari = $cakupan->kodeUnitTertanam();

        $ditemukan = UnitKerja::query()
            ->whereIn('kode', $kodeDicari)
            ->pluck('kode')
            ->all();

        $hilang = array_diff($kodeDicari, $ditemukan);

        if ($hilang !== []) {
            $validator->errors()->add('cakupan', sprintf(
                'Unit kerja %s pada cakupan "%s" tidak ditemukan. Jalankan sinkronisasi pegawai lebih dahulu.',
                implode(', ', $hilang),
                $cakupan->label(),
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama' => 'nama event',
            'tanggal' => 'tanggal',
            'jam_mulai' => 'jam mulai',
            'toleransi_menit' => 'toleransi keterlambatan',
            'cakupan' => 'cakupan unit kerja',
            'unit_kerja_id' => 'unit kerja',
            'catatan' => 'catatan',
        ];
    }

    /**
     * Unit kerja yang tersimpan ditentukan cakupannya, bukan selalu oleh apa
     * yang dikirim formulir.
     *
     * "Semua unit" tidak menyimpan baris pivot sama sekali — menyalin seluruh
     * unit akan basi begitu unit baru disinkronkan dari WORKA — sehingga
     * kiriman apa pun dibersihkan di sini agar tidak tersimpan sebagai pivot
     * yang menyesatkan.
     *
     * Cakupan bawaan sistem justru sebaliknya: unitnya DIISI di sini dari kode
     * yang tertanam pada enum, mengabaikan kiriman peramban. Pivotnya sengaja
     * terisi supaya seluruh mesin yang membaca cakupan lewat pivot — pencocokan
     * perangkat, rekap, kode unit kerja per event — bekerja tanpa perlu
     * mengenali cakupan baru ini sama sekali.
     */
    protected function prepareForValidation(): void
    {
        $cakupan = CakupanEvent::tryFrom((string) $this->input('cakupan'));

        if ($cakupan === CakupanEvent::SemuaUnit) {
            $this->merge(['unit_kerja_id' => []]);

            return;
        }

        if ($cakupan?->unitTertanam()) {
            $this->merge([
                'unit_kerja_id' => UnitKerja::query()
                    ->whereIn('kode', $cakupan->kodeUnitTertanam())
                    ->pluck('id')
                    ->all(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();
        $data['unit_kerja_id'] = array_map('intval', $data['unit_kerja_id'] ?? []);

        return $data;
    }
}
