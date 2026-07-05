<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 30px 35px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #2d2d2d;
        }

        .header {
            border-bottom: 3px solid #1f2937;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }

        .header-brand {
            font-size: 20px;
            font-weight: bold;
            color: #1f2937;
            letter-spacing: 0.5px;
        }

        .header-sub {
            color: #6b7280;
            font-size: 11px;
            margin-top: 2px;
        }

        .header-meta {
            text-align: right;
            font-size: 10px;
            color: #6b7280;
        }

        .header-flex {
            display: flex;
            justify-content: space-between;
        }

        h2.section-title {
            font-size: 13px;
            color: #1f2937;
            margin: 22px 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        table.summary {
            width: 100%;
            border-collapse: collapse;
        }

        table.summary td {
            padding: 7px 10px;
            font-size: 12px;
        }

        table.summary tr {
            border-bottom: 1px solid #f0f0f0;
        }

        table.summary td:first-child {
            color: #6b7280;
            width: 220px;
        }

        table.summary td:last-child {
            font-weight: bold;
            color: #111827;
        }

        .highlight-box {
            background: #f3f4f6;
            border-left: 4px solid #1f2937;
            padding: 12px 16px;
            margin-top: 14px;
            border-radius: 4px;
        }

        .highlight-box .label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .highlight-box .value {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
            margin-top: 2px;
        }

        table.jadwal {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        table.jadwal th {
            background: #1f2937;
            color: #fff;
            padding: 6px 8px;
            font-size: 10px;
            text-align: left;
        }

        table.jadwal td {
            padding: 5px 8px;
            font-size: 10px;
            border-bottom: 1px solid #eee;
        }

        table.jadwal tr:nth-child(even) {
            background: #fafafa;
        }

        .footer-note {
            margin-top: 24px;
            padding: 12px 14px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 4px;
            font-size: 9.5px;
            color: #92400e;
            line-height: 1.5;
        }

        .footer-note strong {
            display: block;
            margin-bottom: 3px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-flex">
            <div>
                <div class="header-brand">BPR Danafast</div>
                <div class="header-sub">Simulasi Angsuran Kredit</div>
            </div>
            <div class="header-meta">
                Dicetak: {{ now()->format('d F Y, H:i') }} WIB<br>
                Ref: SIM-{{ now()->format('Ymd') }}-{{ str_pad($data['tenor_bulan'], 3, '0', STR_PAD_LEFT) }}
            </div>
        </div>
    </div>

    <h2 class="section-title">Ringkasan Simulasi</h2>
    <table class="summary">
        <tr>
            <td>Nominal Pinjaman</td>
            <td>Rp {{ number_format($data['pinjaman'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Tenor</td>
            <td>{{ $data['tenor_bulan'] }} Bulan</td>
        </tr>
        <tr>
            <td>Suku Bunga (flat/tahun)</td>
            <td>{{ $data['suku_bunga'] }}%</td>
        </tr>
        <tr>
            <td>Total Bunga</td>
            <td>Rp {{ number_format($data['total_bunga'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Total Pembayaran</td>
            <td>Rp {{ number_format($data['total_bayar'], 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="highlight-box">
        <div class="label">Cicilan per Bulan</div>
        <div class="value">Rp {{ number_format($data['cicilan_bulanan'], 0, ',', '.') }}</div>
    </div>

    <h2 class="section-title">Jadwal Angsuran Lengkap ({{ $data['tenor_bulan'] }} Bulan)</h2>
    <table class="jadwal">
        <thead>
            <tr>
                <th>Bulan</th>
                <th>Cicilan</th>
                <th>Pokok</th>
                <th>Bunga</th>
                <th>Sisa Pinjaman</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['jadwal_lengkap'] as $row)
                <tr>
                    <td>{{ $row['bulan'] }}</td>
                    <td>Rp {{ number_format($row['cicilan'], 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($row['pokok'], 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($row['bunga'], 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($row['sisa'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-note">
        <b>Catatan Penting</b> Dokumen ini adalah <b>simulasi</b> dan bukan merupakan penawaran resmi atau
        perjanjian kredit. Angka pada perhitungan ini dapat berbeda dengan hasil akhir yang
        disetujui, tergantung hasil analisis kelayakan, kebijakan cabang, biaya administrasi,
        biaya provisi, dan ketentuan lain yang berlaku saat pengajuan kredit diproses secara resmi.
        Untuk informasi lebih lanjut, silakan hubungi kantor cabang atau layanan resmi BPR Danafast.
    </div>
</body>

</html>