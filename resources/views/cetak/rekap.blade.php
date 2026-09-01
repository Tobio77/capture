{{-- Rekap absen per event (FR-REK-03). --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Absen</title>
    @include('cetak._gaya')
</head>
<body>
    <div class="kop">
        <p class="lembaga">Dinas Tenaga Kerja dan Transmigrasi — Provinsi Jawa Timur</p>
        <h1>{{ $event['nama'] }}</h1>
        <p class="rincian">
            {{ $event['tanggal'] }} · mulai {{ $event['jam_mulai'] }} ·
            toleransi {{ $event['toleransi_menit'] }} menit ·
            entry {{ $event['status_label'] }} · Cakupan: {{ $cakupan }}
        </p>
    </div>

    <table class="ringkas">
        <tr>
            <td><div class="label">Hadir</div><div class="angka">{{ $ringkasan['hadir'] }}</div></td>
            <td><div class="label">Tepat Waktu</div><div class="angka">{{ $ringkasan['tepat'] }}</div></td>
            <td><div class="label">Terlambat</div><div class="angka">{{ $ringkasan['terlambat'] }}</div></td>
            <td><div class="label">Sudah Pulang</div><div class="angka">{{ $ringkasan['sudah_pulang'] }}</div></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width:26px">No</th>
                <th style="width:112px">NIP</th>
                <th>Nama</th>
                <th>Unit Kerja</th>
                <th style="width:50px">Masuk</th>
                <th style="width:50px">Pulang</th>
                <th style="width:62px">Metode</th>
                <th style="width:72px">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $urutan => $isi)
                <tr>
                    <td class="angka-kolom redup">{{ $urutan + 1 }}</td>
                    <td class="angka-kolom">{{ $isi['nip'] }}</td>
                    <td>{{ $isi['nama'] }}</td>
                    <td>{{ $isi['unit_kerja'] ?? '—' }}</td>
                    <td class="angka-kolom">{{ $isi['jam_masuk'] ?? '—' }}</td>
                    <td class="angka-kolom">{{ $isi['jam_pulang'] ?? '—' }}</td>
                    <td>{{ $isi['metode'] }}</td>
                    <td class="{{ $isi['status_ketepatan'] === 'terlambat' ? 'telat' : 'tepat' }}">
                        {{ $isi['status_label'] ?? '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="redup" style="padding:18px;text-align:center">
                        Belum ada kehadiran tercatat pada event ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="kaki">Dicetak {{ $dicetak }} oleh {{ $oleh }} · Capture — Sistem Absensi Kegiatan</p>
</body>
</html>
