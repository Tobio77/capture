{{-- Daftar event beserta cakupan dan capaiannya. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Daftar Event</title>
    @include('cetak._gaya')
</head>
<body>
    <div class="kop">
        <p class="lembaga">Dinas Tenaga Kerja dan Transmigrasi — Provinsi Jawa Timur</p>
        <h1>Daftar Event Absensi</h1>
        <p class="rincian">{{ $keterangan }} · Cakupan: {{ $cakupan }}</p>
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width:26px">No</th>
                <th>Nama Event</th>
                <th>Cakupan Unit</th>
                <th style="width:70px">Tanggal</th>
                <th style="width:40px">Jam</th>
                <th class="kanan" style="width:56px">Toleransi</th>
                <th class="kanan" style="width:56px">Perangkat</th>
                <th class="kanan" style="width:44px">Masuk</th>
                <th style="width:52px">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $urutan => $isi)
                <tr>
                    <td class="angka-kolom redup">{{ $urutan + 1 }}</td>
                    <td>{{ $isi['nama'] }}</td>
                    <td>
                        @if ($isi['cakupan'] === 'semua_unit')
                            Semua Unit
                        @else
                            {{ collect($isi['unit_kerja'])->pluck('kode')->join(', ') }}
                        @endif
                    </td>
                    <td class="angka-kolom">{{ $isi['tanggal'] }}</td>
                    <td class="angka-kolom">{{ $isi['jam_mulai'] }}</td>
                    <td class="angka-kolom">{{ $isi['toleransi_menit'] }} mnt</td>
                    <td class="angka-kolom">{{ $isi['jumlah_kiosk'] }}</td>
                    <td class="angka-kolom tepat">{{ $isi['jumlah_absensi'] }}</td>
                    <td class="{{ $isi['status'] === 'aktif' ? 'tepat' : 'redup' }}">{{ $isi['status_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="redup" style="padding:18px;text-align:center">
                        Tidak ada event pada penyaringan ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="kaki">Dicetak {{ $dicetak }} oleh {{ $oleh }} · Capture — Sistem Absensi Kegiatan</p>
</body>
</html>
