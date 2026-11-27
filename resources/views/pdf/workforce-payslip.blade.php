<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employee Pay Slip — {{ $employeeName }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: rgb(17, 24, 39);
            margin: 24px;
            line-height: 1.45;
        }
        .header {
            border-bottom: 2px solid rgb(209, 213, 219);
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
        }
        .subtitle {
            font-size: 10px;
            text-transform: uppercase;
            color: rgb(107, 114, 128);
            letter-spacing: 1px;
        }
        .meta-box {
            background-color: rgb(249, 250, 251);
            border: 1px solid rgb(229, 231, 235);
            padding: 10px 12px;
            margin-bottom: 18px;
        }
        .meta-table, .lines-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 4px 6px;
            vertical-align: top;
        }
        .label {
            font-size: 9px;
            text-transform: uppercase;
            color: rgb(107, 114, 128);
            display: block;
        }
        .value {
            font-weight: bold;
            font-size: 11px;
        }
        .section-heading {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 1px solid rgb(209, 213, 219);
            padding-bottom: 4px;
            margin: 14px 0 8px 0;
        }
        .lines-table td {
            padding: 5px 4px;
            border-bottom: 1px solid rgb(243, 244, 246);
        }
        .amount {
            text-align: right;
            font-weight: bold;
        }
        .net-box {
            margin-top: 18px;
            padding: 12px;
            background-color: rgb(243, 244, 246);
            border: 1px solid rgb(209, 213, 219);
        }
        .signatures {
            width: 100%;
            margin-top: 32px;
            border-collapse: collapse;
        }
        .signatures td {
            width: 50%;
            text-align: center;
            vertical-align: top;
        }
        .sig-space {
            height: 48px;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <p class="title">{{ $companyName }}</p>
                    <span class="subtitle">Official Employee Pay Slip</span>
                </td>
                <td style="text-align: right;">
                    <span class="label">Pay Period</span>
                    <span class="value">{{ $period }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="meta-box">
        <table class="meta-table">
            <tr>
                <td>
                    <span class="label">Employee Name</span>
                    <span class="value">{{ $employeeName }}</span>
                </td>
                <td>
                    <span class="label">Employee Number</span>
                    <span class="value">{{ $employeeId }}</span>
                </td>
                <td>
                    <span class="label">Department / Role</span>
                    <span class="value">{{ $department }} &bull; {{ $position }}</span>
                </td>
                <td>
                    <span class="label">Tax Status (PTKP)</span>
                    <span class="value">{{ $ptkpStatus }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-heading">Earnings</div>
    <table class="lines-table">
        @foreach ($earnings as $item)
            <tr>
                <td>{{ $item['label'] }}</td>
                <td class="amount">Rp {{ number_format((float) $item['amount'], 0, ',', '.') }}</td>
            </tr>
        @endforeach
        <tr>
            <td><strong>Total Gross Earnings</strong></td>
            <td class="amount">Rp {{ number_format((float) $totalEarnings, 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="section-heading">Deductions</div>
    <table class="lines-table">
        @forelse ($deductions as $item)
            <tr>
                <td>{{ $item['label'] }}</td>
                <td class="amount">Rp {{ number_format((float) $item['amount'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr>
                <td>No deductions</td>
                <td class="amount">Rp 0</td>
            </tr>
        @endforelse
        <tr>
            <td><strong>Total Deductions</strong></td>
            <td class="amount">Rp {{ number_format((float) $totalDeductions, 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="net-box">
        <table class="header-table">
            <tr>
                <td>
                    <span class="label">Take-Home Pay (Net Salary)</span>
                    <span style="font-size: 10px; color: rgb(107, 114, 128);">
                        Bank: {{ $bankName }} &bull; {{ $bankAccount }}
                    </span>
                </td>
                <td class="amount" style="font-size: 16px;">
                    Rp {{ number_format((float) $netPay, 0, ',', '.') }}
                </td>
            </tr>
        </table>
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div>Authorized By,</div>
                <div class="sig-space"></div>
                <div><strong>HR &amp; Payroll Manager</strong></div>
            </td>
            <td>
                <div>Received By,</div>
                <div class="sig-space"></div>
                <div><strong>{{ $employeeName }}</strong></div>
            </td>
        </tr>
    </table>
</body>
</html>

