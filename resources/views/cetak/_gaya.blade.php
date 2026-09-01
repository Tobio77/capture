{{-- Gaya bersama seluruh lembar cetak PDF. --}}
<style>
    @page { margin: 14mm 12mm; }

    * { box-sizing: border-box; }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 9.5px;
        color: #1e293b;
        margin: 0;
    }

    .kop { border-bottom: 2px solid #0F2A43; padding-bottom: 8px; margin-bottom: 12px; }
    .kop .lembaga { font-size: 8px; letter-spacing: .08em; text-transform: uppercase; color: #64748b; margin: 0; }
    .kop h1 { font-size: 15px; margin: 3px 0 2px; color: #0F2A43; }
    .kop .rincian { font-size: 9px; color: #475569; margin: 0; }

    .ringkas { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .ringkas td { border: 1px solid #e2e8f0; padding: 6px 8px; width: 25%; }
    .ringkas .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
    .ringkas .angka { font-size: 14px; font-weight: 700; color: #0F2A43; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th {
        background: #f1f5f9;
        border-bottom: 1px solid #cbd5e1;
        padding: 5px 6px;
        text-align: left;
        font-size: 8px;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #475569;
    }
    table.data td { border-bottom: 1px solid #eef2f7; padding: 5px 6px; }
    table.data tr:nth-child(even) td { background: #fafbfc; }

    .kanan { text-align: right; }
    .angka-kolom { text-align: right; }
    .redup { color: #94a3b8; }
    .tepat { color: #059669; }
    .telat { color: #B45309; }

    .kaki {
        margin-top: 14px;
        padding-top: 6px;
        border-top: 1px solid #e2e8f0;
        font-size: 7.5px;
        color: #94a3b8;
    }
</style>
