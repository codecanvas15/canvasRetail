<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanda Bukti Pembayaran Hutang</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .container { margin-top: -40px; }
        .no-bukti { text-align: right; font-weight: bold; font-size: 12px; }
        .title { text-align: center; font-weight: bold; font-size: 16px; text-decoration: underline; margin-top: 15px; margin-bottom: 15px; }
        .intro { margin-bottom: 10px; font-weight: bold; }
        .detail-table { margin-left: 20px; margin-bottom: 15px; }
        .detail-table td { padding: 3px 5px; vertical-align: top; }
        .detail-table .label { font-weight: bold; width: 120px; }
        .detail-table .separator { width: 10px; text-align: center; }
        .bukti-text { font-weight: bold; margin-top: 20px; margin-bottom: 30px; }
        .signature-table { width: 100%; margin-top: 10px; }
        .signature-table td { vertical-align: top; }
        .signature-left { text-align: center; font-weight: bold; }
        .signature-right { text-align: left; font-weight: bold; padding-left: 50px;}
        .signature-space { display: inline-block; width: 120px; border-bottom: 1px solid black; margin-top: 50px; }
    </style>
</head>
<body>
    <div class="container">

        <div class="title">TANDA BUKTI PEMBAYARAN HUTANG</div>

        <div class="intro">Kami yang bertanda tangan dibawah menyatakan telah menerima pembayaran atas :</div>

        <table class="detail-table">
            <tr>
                <td class="label">{{ $data['type'] }}</td>
                <td class="separator">:</td>
                <td>{{ $data['name'] }}</td>
            </tr>
            <tr>
                <td class="label">Faktur</td>
                <td class="separator">:</td>
                <td>{{ $data['doc_number'] }}</td>
            </tr>
            <tr>
                <td class="label">Nilai Faktur</td>
                <td class="separator">:</td>
                <td>{{ number_format($data['amount'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Pembayaran</td>
                <td class="separator">:</td>
                <td>{{ number_format($data['payment'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Sisa</td>
                <td class="separator">:</td>
                <td>{{ number_format($data['outstanding'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Keterangan</td>
                <td class="separator">:</td>
                <td>{{ $data['notes'] ?? '-' }}</td>
            </tr>
        </table>

        <div class="bukti-text">Sebagai Tanda Bukti Atas Pembayaran Piutang {{ $data['type'] }}</div>

        <table class="signature-table">
            <tr>
                <td style="width: 50%;">
                    <div class="signature-left">DIBUAT OLEH</div>
                </td>
                <td style="width: 50%;">
                    <div class="signature-right">
                        Samarinda, {{ $data['date'] }}<br>
                        KASIR
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="signature-left" style="margin-top: 50px;">
                        <span class="signature-space"></span>
                    </div>
                </td>
                <td>
                    <div class="signature-right" style="margin-top: 50px;">
                        <span class="signature-space"></span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
