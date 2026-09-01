{{-- Laporan kehadiran per pegawai (FR-LAP-03). --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kehadiran</title>
    @include('cetak._gaya')
</head>
<body>
    <div class="kop">
        <p class="lembaga">Dinas Tenaga Kerja dan Transmigrasi — Provinsi Jawa Timur</p>
        <h1>Laporan Kehadiran Pegawai</h1>
        <p class="rincian">
            Periode {{ $dari }} — {{ $sampai }} ·
            {{ $jumlah_event }} event pada rentang ini ·
            Cakupan: {{ $cakupan }}
        </p>
    </div>

    <table class="ringkas">
        <tr>
            <td><div class="label">Pegawai</div><div class="angka">{{ $ringkasan['pegawai'] }}</div></td>
            <td><div class="label">Total Hadir</div><div class="angka">{{ $ringkasan['hadir'] }}</div></td>
            <td><div class="label">Total Terlambat</div><div class="angka">{{ $ringkasan['terlambat'] }}</div></td>
            <td><div class="label">Tanpa Keterangan</div><div class="angka">{{ $ringkasan['tanpa_keterangan'] }}</div></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width:26px">No</th>
                <th style="width:112px">NIP</th>
                <th>Nama</th>
                <th>Unit Kerja</th>
                <th class="kanan" style="width:42px">Event</th>
                <th class="kanan" style="width:42px">Hadir</th>
                <th class="kanan" style="width:58px">Terlambat</th>
                <th class="kanan" style="width:62px">Tanpa Ket.</th>
                <th class="kanan" style="width:36px">%</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $urutan => $isi)
                <tr>
                    <td class="angka-kolom redup">{{ $urutan + 1 }}</td>
                    <td class="angka-kolom">{{ $isi['nip'] }}</td>
                    <td>{{ $isi['nama'] }}</td>
                    <td>{{ $isi['unit_kerja'] ?? '—' }}</td>
                    <td class="angka-kolom redup">{{ $isi['event_berlaku'] }}</td>
                    <td class="angka-kolom tepat">{{ $isi['hadir'] }}</td>
                    <td class="angka-kolom {{ $isi['terlambat'] > 0 ? 'telat' : 'redup' }}">{{ $isi['terlambat'] }}</td>
                    <td class="angka-kolom {{ $isi['tanpa_keterangan'] > 0 ? '' : 'redup' }}">{{ $isi['tanpa_keterangan'] }}</td>
                    <td class="angka-kolom">
                        @if ($isi['event_berlaku'] === 0)
                            —
                        @else
                            {{ round($isi['hadir'] / $isi['event_berlaku'] * 100) }}%
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="redup" style="padding:18px;text-align:center">
                        Tidak ada pegawai pada cakupan dan rentang ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="kaki">Dicetak {{ $dicetak }} oleh {{ $oleh }} · Capture — Sistem Absensi Kegiatan</p>
</body>
</html>
