{{-- Laporan Resmi — dokumen kop surat, bukan tabel mentah (FR-LAP-04). --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kehadiran Pegawai</title>
    <style>
        @page { margin: 20mm 22mm; }

        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            line-height: 1.5;
            color: #1e293b;
            margin: 0;
        }

        /* ------------------------------------------------------------ kop */
        .kop { display: table; width: 100%; border-bottom: 3px double #0F2A43; padding-bottom: 10px; margin-bottom: 18px; }
        .kop .logo { display: table-cell; width: 60px; vertical-align: middle; }
        .kop .logo img { width: 52px; }
        .kop .teks { display: table-cell; vertical-align: middle; padding-left: 12px; }
        .kop .instansi { font-size: 13px; font-weight: 700; margin: 0; color: #0F2A43; }
        .kop .dinas { font-size: 15px; font-weight: 700; margin: 1px 0; color: #0F2A43; text-transform: uppercase; }
        .kop .alamat { font-size: 8.5px; color: #475569; margin: 0; }

        /* --------------------------------------------------------- judul */
        .judul { text-align: center; margin-bottom: 20px; }
        .judul h1 { font-size: 14px; letter-spacing: 0.04em; text-transform: uppercase; margin: 0 0 8px; color: #0F2A43; }
        .judul .rincian { font-size: 10px; color: #334155; }
        .judul .rincian b { color: #0F2A43; }

        /* -------------------------------------------------------- bagian */
        .bagian { margin-bottom: 16px; }
        .bagian h2 {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #0F2A43;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
            margin: 0 0 8px;
        }
        .bagian p { margin: 0 0 6px; text-align: justify; }

        /* ---------------------------------------------------------- tabel */
        table.data { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        table.data th {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #475569;
        }
        table.data td { border: 1px solid #e2e8f0; padding: 5px 6px; }
        table.data tr:nth-child(even) td { background: #fafbfc; }
        .kanan { text-align: right; }
        .redup { color: #94a3b8; }
        .tepat { color: #059669; font-weight: 700; }
        .telat { color: #B45309; font-weight: 700; }
        table.data tfoot td { border: 1px solid #cbd5e1; background: #f1f5f9; font-weight: 700; padding: 6px; }

        /* -------------------------------------------------------- lampiran */
        /*
         * Halaman sendiri: yang ditandatangani adalah ringkasan, dan lampiran
         * tidak boleh menyelinap ke sisa halaman pengesahan. Kepala tabelnya
         * diulang di tiap halaman — lampiran berhalaman-halaman tanpa kepala
         * berhenti dapat dibaca setelah halaman pertama.
         */
        .lampiran { page-break-before: always; }
        .lampiran table.data { font-size: 8.5px; }
        .lampiran table.data thead { display: table-header-group; }
        .lampiran table.data tr { page-break-inside: avoid; }
        .rincian-lampiran { font-size: 9px; color: #475569; margin: 0 0 8px; }

        /* ----------------------------------------------------- rekomendasi */
        .rekomendasi h3 { font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #334155; margin: 10px 0 4px; }
        .rekomendasi ul { margin: 0 0 6px; padding-left: 16px; }
        .rekomendasi li { margin-bottom: 4px; text-align: justify; }
        .positif { padding: 8px 10px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; border-radius: 3px; }

        /* ---------------------------------------------------- pengesahan */
        .pengesahan { margin-top: 36px; width: 100%; }
        .pengesahan .kotak { width: 220px; margin-left: auto; text-align: center; }
        .pengesahan .tanggal { margin-bottom: 48px; }
        .pengesahan .garis-nama { border-bottom: 1px solid #1e293b; margin-bottom: 2px; height: 14px; }
        .pengesahan .garis-jabatan { font-size: 9px; color: #475569; margin-bottom: 2px; }
        .pengesahan .garis-nip { font-size: 9px; color: #475569; }

        .kaki {
            margin-top: 20px;
            padding-top: 6px;
            border-top: 1px solid #e2e8f0;
            font-size: 7.5px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="kop">
        <div class="logo">
            <img src="{{ resource_path('images/logo-pemprov-jatim.png') }}" alt="">
        </div>
        <div class="teks">
            <p class="instansi">PEMERINTAH PROVINSI JAWA TIMUR</p>
            <p class="dinas">Dinas Tenaga Kerja dan Transmigrasi</p>
            <p class="alamat">Jln. Dukuh Menanggal 124-126, Gayungan, Surabaya, Jawa Timur 60234</p>
            <p class="alamat">Tlp (031) 8290005, Laman disnakertrans.jatimprov.go.id, Pos-el disnakertrans@jatimprov.go.id</p>
        </div>
    </div>

    <div class="judul">
        <h1>Laporan Kehadiran Pegawai</h1>
        <p class="rincian">
            Periode: <b>{{ $data['periode_label'] }}</b> &middot;
            Unit Kerja: <b>{{ $data['cakupan'] }}</b>
        </p>
    </div>

    <div class="bagian">
        <h2>Ringkasan Data</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Unit Kerja</th>
                    <th class="kanan">Pegawai</th>
                    <th class="kanan">Hadir</th>
                    <th class="kanan">Tepat Waktu</th>
                    <th class="kanan">Terlambat</th>
                    <th class="kanan">Tanpa Ket.</th>
                    <th class="kanan">Tingkat Kehadiran</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data['per_unit'] as $unit)
                    <tr>
                        <td>{{ $unit['nama'] }}</td>
                        <td class="kanan">{{ $unit['pegawai'] }}</td>
                        <td class="kanan">{{ $unit['hadir'] }}</td>
                        <td class="kanan tepat">{{ $unit['tepat'] }}</td>
                        <td class="kanan {{ $unit['terlambat'] > 0 ? 'telat' : 'redup' }}">{{ $unit['terlambat'] }}</td>
                        <td class="kanan {{ $unit['tanpa_keterangan'] > 0 ? '' : 'redup' }}">{{ $unit['tanpa_keterangan'] }}</td>
                        <td class="kanan">{{ $formatPersen($unit['tingkat_kehadiran']) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="redup" style="padding:14px;text-align:center">
                            Tidak ada data pada cakupan dan periode ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if (count($data['per_unit']) > 0)
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="kanan">{{ $data['total']['pegawai'] }}</td>
                        <td class="kanan">{{ $data['total']['hadir'] }}</td>
                        <td class="kanan">{{ $data['total']['tepat'] }}</td>
                        <td class="kanan">{{ $data['total']['terlambat'] }}</td>
                        <td class="kanan">{{ $data['total']['tanpa_keterangan'] }}</td>
                        <td class="kanan">{{ $formatPersen($data['total']['tingkat_kehadiran']) }}%</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="bagian">
        <h2>Kesimpulan</h2>
        <p>{{ $data['kesimpulan'] }}</p>
    </div>

    <div class="bagian rekomendasi">
        <h2>Rekomendasi</h2>

        @if ($data['rekomendasi']['tidak_ada_masalah'])
            <p class="positif">
                Seluruh unit kerja menunjukkan tingkat kehadiran yang baik pada periode ini.
                Tidak terdapat rekomendasi tindak lanjut khusus.
            </p>
        @else
            @if (count($data['rekomendasi']['kehadiran_rendah']) > 0)
                <h3>a. Tingkat Kehadiran di Bawah Ambang Batas</h3>
                <ul>
                    @foreach ($data['rekomendasi']['kehadiran_rendah'] as $r)
                        <li>{{ $r['kalimat'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($data['rekomendasi']['keterlambatan_tinggi']) > 0)
                <h3>b. Tingkat Keterlambatan di Atas Ambang Batas</h3>
                <ul>
                    @foreach ($data['rekomendasi']['keterlambatan_tinggi'] as $r)
                        <li>{{ $r['kalimat'] }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>

    <div class="pengesahan">
        <div class="kotak">
            <p class="tanggal">Surabaya, {{ $dicetak }}</p>
            <p>&nbsp;</p>
            <div class="garis-nama"></div>
            <p class="garis-jabatan">(&nbsp;)</p>
            <p class="garis-nip">NIP. </p>
        </div>
    </div>

    {{--
        Lampiran rincian kehadiran. Berdiri SETELAH pengesahan, pada halaman
        sendiri: yang ditandatangani adalah ringkasan di atas, dan lampiran
        adalah bukti pendukungnya — bukan sebaliknya.
    --}}
    @if (count($data['rincian']) > 0)
        <div class="bagian lampiran">
            <h2>Lampiran — Rincian Kehadiran per Pegawai</h2>

            <p class="rincian-lampiran">
                Jam masuk dan jam pulang sebagaimana tercatat sistem pada periode
                {{ $data['periode_label'] }}. Pegawai yang tidak memiliki catatan kehadiran
                tidak muncul pada lampiran ini; jumlah ketidakhadirannya terbaca pada
                tabel Ringkasan Data di atas.
            </p>

            <table class="data">
                <thead>
                    <tr>
                        <th style="width:22px">No</th>
                        <th style="width:110px">NIP</th>
                        <th>Nama</th>
                        <th>Unit Kerja</th>
                        <th style="width:58px">Tanggal</th>
                        <th>Kegiatan</th>
                        <th class="kanan" style="width:42px">Masuk</th>
                        <th class="kanan" style="width:42px">Pulang</th>
                        <th style="width:52px">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['rincian'] as $urutan => $isi)
                        <tr>
                            <td class="kanan">{{ $urutan + 1 }}</td>
                            <td>{{ $isi['nip'] }}</td>
                            <td>{{ $isi['nama'] }}</td>
                            <td>{{ $isi['unit_kerja'] ?? '—' }}</td>
                            <td>{{ $isi['tanggal_label'] ?? '—' }}</td>
                            <td>{{ $isi['kegiatan'] ?? '—' }}</td>
                            <td class="kanan">{{ $isi['jam_masuk'] ?? '—' }}</td>
                            <td class="kanan">{{ $isi['jam_pulang'] ?? '—' }}</td>
                            <td>{{ $isi['status_label'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($data['rincian_dipotong'] > 0)
                <p class="rincian-lampiran">
                    {{ number_format($data['rincian_dipotong'], 0, ',', '.') }} baris berikutnya
                    tidak dimuat agar dokumen tetap dapat dirakit. Gunakan “Unduh Data” pada
                    menu Laporan untuk memperoleh seluruh baris sebagai CSV atau Excel.
                </p>
            @endif
        </div>
    @endif

    <p class="kaki">Dicetak {{ $dicetak }} oleh {{ $oleh }} &middot; Capture — Sistem Absensi Kegiatan</p>
</body>
</html>
