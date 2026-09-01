<?php

namespace App\Http\Requests;

use App\Enums\CakupanEvent;
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
     * FR-EVT-06: tidak boleh ada dua event aktif yang cakupan dan rentang
     * waktunya bertumpang tindih — kiosk tidak akan tahu tap milik event mana.
     */
    protected function periksaBentrok(Validator $validator): void
    {
        $bentrok = app(EventAbsenService::class)->eventBentrok(
            [
                'tanggal' => $this->input('tanggal'),
                'jam_mulai' => $this->input('jam_mulai'),
                'toleransi_menit' => $this->input('toleransi_menit'),
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

        $validator->errors()->add('tanggal', sprintf(
            'Bentrok dengan event aktif "%s" (%s, mencakup %s). Tutup event tersebut lebih dulu atau geser jadwalnya.',
            $bentrok->nama,
            substr((string) $bentrok->jam_mulai, 0, 5),
            $cakupan,
        ));
    }

    /**
     * FR-EVT-02: Admin UPT hanya boleh memilih unit kerjanya sendiri, dan
     * tidak boleh memakai cakupan "semua unit".
     */
    protected function periksaCakupan(Validator $validator): void
    {
        $pengguna = $this->user();
        $semuaUnit = $this->input('cakupan') === CakupanEvent::SemuaUnit->value;

        if ($semuaUnit) {
            if (! $pengguna->lintasUnit()) {
                $validator->errors()->add(
                    'cakupan',
                    'Cakupan "semua unit" hanya tersedia untuk Superadmin dan Admin Dinas.',
                );
            }

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
     * Unit kerja tidak lagi relevan bila cakupannya "semua unit"; dibersihkan
     * di sini supaya tidak ikut tersimpan sebagai baris pivot yang menyesatkan.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('cakupan') === CakupanEvent::SemuaUnit->value) {
            $this->merge(['unit_kerja_id' => []]);
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
