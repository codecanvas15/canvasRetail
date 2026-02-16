<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faktur</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin-top: -50px; margin-bottom: -20px;}
        .container { width: 100%; margin-top: 10px; }
        .header-table {border-collapse: collapse; border: none; width: 100%; }
        .header-table td {vertical-align: top; border: none; word-wrap: break-word; white-space: normal; }
        .header-table td:nth-child(1) { width: 25%; }
        .header-table td:nth-child(2) { width: 50%; }
        .header-table td:nth-child(3) { width: 25%; }
        .center { text-align: center; font-weight: bold; font-size: 15px; width: 40%}
        table {border-collapse: collapse; margin-top: 5px; }
        th, td { border: 1px solid black; text-align: left; }
        tbody td { border: none; }
        .data-table td:nth-child(1) { width: 5%; }
        .data-table td:nth-child(2) { width: 30%; }
        .data-table td:nth-child(3) { width: 15%; }
        .data-table td:nth-child(4) { width: 10%; }
        .data-table td:nth-child(5) { width: 10%; }
        .data-table td:nth-child(6) { width: 15%; }
        .data-table td:nth-child(7) { width: 15%; }
        .footer { margin-top: -15px; text-align: right; width: 100%; border: none; }
        .footer td { border: none; padding: 0px !important;}
        p { margin: 5px !important; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    @foreach ($data['details'] as $index => $items)
    <div class="container">
        <table class="header-table" style="width: 100%;">
            <tr>
                <td>
                    <table>
                        <tr>
                            <td>Tgl. Faktur</td>
                            <td>: {{ substr($data['sales_date'],0,10) }}</td>
                        </tr>
                        <tr>
                            <td>Jatuh Tempo</td>
                            <td>: {{ substr($data['due_date'],0,10) }}</td>
                        </tr>
                        <tr>
                            <td>Lokasi</td>
                            <td>: {{$data['location_name']}}</td>
                        </tr>
                    </table>
                </td>
                <td class="center">
                    <p>
                        <b>PT. Purnama Jaya Teknik</b>
                    </p>
                    <p>
                        <b><u>FAKTUR</u></b>
                    </p>
                    <p style="margin-top: -20px;">
                        {{ $data['doc_number'] }}
                    </p>
                </td>
                <td>
                    <table>
                        <tr>
                            <td>Nama</td>
                            <td>: {{ $data['contact_name'] }}</td>
                        </tr>
                        <tr>
                            <td>Alamat</td>
                            <td>: {{ $data['contact_address'] }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        
        <table class="data-table" style="width: 100%; min-height: 255px;">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama Barang</th>
                    <th>Serial Number</th>
                    <th>Jumlah</th>
                    <th>Disc</th>
                    <th>Harga Satuan</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody style="vertical-align: top;">
                @php $total_bruto = 0; @endphp
                @foreach ($items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->item_name }}</td>
                    <td>{{ $item->item_code }}</td>
                    <td>{{ $item->qty }}</td>
                    <td>Rp.{{ number_format($item->discount, 2, ',', '.') }}</td>
                    <td>Rp.{{ number_format($item->price, 2, ',', '.') }}</td>
                    <td>Rp.{{ number_format($item->total, 2, ',', '.') }}</td>
                    @php $total_bruto += $item->total; @endphp
                </tr>
                @endforeach
            </tbody>
        </table>
        <hr>
        <table class="footer">
            <tr>
                <td colspan="3" style="vertical-align: top;">
                    <p><strong>Perhatian:</strong> Barang-barang yang sudah dibeli tidak dapat dikembalikan atau ditukar.</p>
                    <p style="word-wrap: break-word"><strong>Ket:</strong> {{$data->reason}}</p>
                </td>
                <td style="vertical-align: top;">
                    <p><strong>Total Bruto</strong></p>
                </td>
                <td style="vertical-align: top; width: 20%; text-align: right;">
                    <p>Rp.{{number_format($total_bruto, 2, ',', '.') }}</p>
                </td>
            </tr>
            <tr style="text-align: center;">
                <td style="text-align: center;">
                    <p>Penerima</p>
                </td>
                <td style="text-align: center;">
                    <p>Checker</p>
                </td>
                <td style="text-align: center;">
                    <p>Hormat Kami</p>
                </td>
            </tr>
            <tr>
                <td colspan="3">
                </td>
                <td>
                    <p>Ppn</p>
                </td>
                <td style="vertical-align: top; width: 20%; text-align: right;">
                    <p>Rp.{{ number_format($data['ppn'], 2, ',', '.') }}</p>
                </td>
            </tr>
            <tr>
                <td colspan="3">
                </td>
                <td>
                    <p>Total Netto</p>
                </td>
                <td style="vertical-align: top; width: 20%; text-align: right;">
                    <p>Rp.{{ number_format($data['grand_total'], 2, ',', '.') }}</p>
                </td>
            </tr>
            <tr>
                <td style="text-align: center;">
                    <p>____________________</p>
                </td>
                <td style="text-align: center;">
                    <p>____________________</p>
                </td>
                <td style="text-align: center;">
                    <p>____________________</p>
                </td>
            </tr>
        </table>        
    </div>
    @if (!$loop->last)
        <div class="page-break"></div>
    @endif
    @endforeach
</body>
</html>
