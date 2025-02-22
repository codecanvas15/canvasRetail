<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanda Terima Piutang</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .container {margin-top: -40px; }
        .header { text-align: center; font-weight: bold; font-size: 16px; }
        .sub-header { text-align: left; font-weight: bold; margin-top: 10px; }
        .recipient {font-weight: bold; margin-top: 10px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table-header { width: 100%; border-collapse: collapse;}
        .table .border th, .table .border td { border: 1px solid black; padding: 5px; }
        .signature-table { width: 100%; text-align: center; page-break-before: auto;}
        .signature-table td { width: 33%; }
        .signature-space { padding-top: 30px; display: inline-block; width: 80px; text-align: center;}
        .no-border td { border-top: none !important; border-left: none !important; border-right: none !important; border-bottom: none !important; }
        p { margin: 10px !important; }
        .bank tr td{padding : 1px !important; font-weight: bold; word-wrap: break-word;}
    </style>
</head>
<body>
    <div class="container">        
        <table class="table-header">
            <tr>
                <td style="width: 30%"></td>
                <td class="header">TANDA TERIMA PIUTANG</td>
                <td class="recipient">KEPADA YTH,</td>
            </tr>
            <tr>
                <td colspan="2"></td>
                <td class="sub-header">{{ $data->contact_name}}</td>
            </tr>
            <tr>
                <td colspan="2"></td>
                <td class="sub-header" style="word-wrap: break-word;">{{ $data->contact_address}}</td>
            </tr>
        </table>
        <div class="sub-header">REKAP FAKTUR YANG DITAGIHKAN</div>
        
        <table class="table">
            <tr class="border">
                <th>No Faktur</th>
                <th>Tgl Faktur</th>
                <th>Total Faktur</th>
                <th>Sisa Faktur</th>
            </tr>
            @php $outstanding = $data['amount']; @endphp
            @php $total = 0; @endphp
            @foreach ($data['payments'] as $index => $payment)
                <tr class="border">
                    <td>{{ $payment->doc_number }}</td>
                    <td>{{ $payment->sales_date }}</td>
                    <td>Rp.{{ number_format($payment->amount, 2, ',', '.') }}</td>
                    <td>Rp.{{ number_format($payment->outstanding, 2, ',', '.') }}</td>
                </tr>
                @php $total += $payment->outstanding; @endphp
            @endforeach
            <tr class="no-border">
                <td colspan="2"></td>
                <td>Grand Total:</td>
                <td>Rp.{{ number_format($total, 2, ',', '.') }}</td>
            </tr>
            <tr class="no-border">
                <td colspan="3"></td>
                <td>Samarinda, {{ $data->date }}</td>
            </tr>
        </table>
        
        <table class="signature-table">
            <tr>
                <td>
                    <table class="bank">
                        <tr>
                            <td>Bank</td>
                            <td>: {{ $data['bank']->bank_name }}</td>
                        </tr>
                        <tr>
                            <td>A/n</td>
                            <td>: {{ $data['bank']->account_name }}</td>
                        </tr>
                        <tr>
                            <td>AC</td>
                            <td>: {{ $data['bank']->account_number }}</td>
                        </tr>
                    </table>
                </td>
                <td>Penerima</td>
                <td>Hormat Kami</td>
            </tr>
            <tr>
                <td></td>
                <td><span class="signature-space">(_________________)</span></td>
                <td><span class="signature-space">(_________________)</span></td>
            </tr>
        </table>
    </div>
</body>
</html>
