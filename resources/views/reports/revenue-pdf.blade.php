<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Revenue Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.6;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #000;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .company-info {
            font-size: 9px;
            color: #666;
        }
        
        .report-title {
            font-size: 16px;
            font-weight: bold;
            margin: 20px 0 10px 0;
            text-align: center;
        }
        
        .report-period {
            text-align: center;
            font-size: 12px;
            color: #666;
            margin-bottom: 20px;
        }
        
        .section {
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 13px;
            font-weight: bold;
            background-color: #f3f4f6;
            padding: 8px 10px;
            margin-bottom: 10px;
            border-left: 4px solid #3b82f6;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        table.simple-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        table.simple-table td:first-child {
            font-weight: 500;
            width: 60%;
        }
        
        table.simple-table td:last-child {
            text-align: right;
            width: 40%;
        }
        
        table.data-table {
            border: 1px solid #d1d5db;
        }
        
        table.data-table thead {
            background-color: #f3f4f6;
        }
        
        table.data-table th {
            padding: 8px 10px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #9ca3af;
        }
        
        table.data-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        table.data-table tr:last-child td {
            border-bottom: none;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .font-bold {
            font-weight: bold;
        }
        
        .total-row {
            background-color: #dbeafe;
            font-weight: bold;
            font-size: 12px;
        }
        
        .comparison-up {
            color: #059669;
        }
        
        .comparison-down {
            color: #dc2626;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #999;
            padding: 10px 0;
            border-top: 1px solid #ddd;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .summary-box {
            background-color: #f0f9ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        
        .summary-label {
            font-weight: 500;
        }
        
        .summary-value {
            font-weight: bold;
            font-size: 13px;
        }
        
        .indent {
            padding-left: 20px;
        }
        
        .alert-box {
            background-color: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 4px;
            padding: 10px;
            margin-top: 15px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="company-name">{{ $company['name'] }}</div>
        <div class="company-info">
            {{ $company['address'] }}<br>
            {{ $company['contact'] }} | {{ $company['email'] }}
        </div>
    </div>

    <!-- Report Title -->
    <div class="report-title">REVENUE REPORT</div>
    <div class="report-period">{{ $period['label'] }}</div>

    <!-- Summary Section -->
    <div class="section">
        <div class="section-title">REVENUE SUMMARY</div>
        
        <div class="summary-box">
            <table class="simple-table">
                <tr>
                    <td class="font-bold">Total Revenue</td>
                    <td class="font-bold">{{ $summary['total_revenue_formatted'] }}</td>
                </tr>
                <tr class="indent">
                    <td>From Bookings</td>
                    <td>{{ $summary['booking_revenue_formatted'] }} ({{ number_format($summary['booking_percentage'], 1) }}%)</td>
                </tr>
                <tr class="indent">
                    <td>From Walk-ins</td>
                    <td>{{ $summary['guest_entry_revenue_formatted'] }} ({{ number_format($summary['guest_entry_percentage'], 1) }}%)</td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Revenue Breakdown -->
    <div class="section">
        <div class="section-title">REVENUE BREAKDOWN</div>
        
        <table class="simple-table">
            <tr>
                <td>Entrance Fees</td>
                <td>{{ $breakdown['entrance_fees_formatted'] }}</td>
            </tr>
            <tr>
                <td>Facility Rentals</td>
                <td>{{ $breakdown['facility_rentals_formatted'] }}</td>
            </tr>
            <tr>
                <td>Third-Party Services</td>
                <td>{{ $breakdown['third_party_services_formatted'] }}</td>
            </tr>
            <tr style="border-top: 1px solid #000;">
                <td class="font-bold">Gross Revenue</td>
                <td class="font-bold">{{ $breakdown['gross_revenue_formatted'] }}</td>
            </tr>
            <tr>
                <td>Less: Discounts</td>
                <td>({{ $breakdown['discounts_formatted'] }})</td>
            </tr>
            <tr class="total-row">
                <td>Net Revenue</td>
                <td>{{ $breakdown['net_revenue_formatted'] }}</td>
            </tr>
        </table>
    </div>

    <!-- Revenue by Facility -->
    <div class="section">
        <div class="section-title">REVENUE BY FACILITY</div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Facility</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-center">Percentage</th>
                </tr>
            </thead>
            <tbody>
                @foreach($by_facility as $item)
                <tr>
                    <td>{{ $item['facility_name'] }} ({{ $item['facility_type'] }})</td>
                    <td class="text-right">{{ $item['revenue_formatted'] }}</td>
                    <td class="text-center">{{ number_format($item['percentage'], 1) }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Revenue by Payment Method -->
    <div class="section">
        <div class="section-title">REVENUE BY PAYMENT METHOD</div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th class="text-center">Count</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">Average</th>
                    <th class="text-center">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach($by_payment_method as $item)
                <tr>
                    <td>{{ $item['method'] }}</td>
                    <td class="text-center">{{ $item['count'] }}</td>
                    <td class="text-right">{{ $item['revenue_formatted'] }}</td>
                    <td class="text-right">₱{{ number_format($item['average'], 2) }}</td>
                    <td class="text-center">{{ number_format($item['percentage'], 1) }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Comparison -->
    <div class="section">
        <div class="section-title">COMPARISON WITH PREVIOUS PERIOD</div>
        
        <table class="simple-table">
            <tr>
                <td>Current Period</td>
                <td>{{ $comparison['current_period_formatted'] }}</td>
            </tr>
            <tr>
                <td>Previous Period ({{ $comparison['period_from'] }} to {{ $comparison['period_to'] }})</td>
                <td>{{ $comparison['previous_period_formatted'] }}</td>
            </tr>
            <tr class="{{ $comparison['direction'] == 'up' ? 'comparison-up' : ($comparison['direction'] == 'down' ? 'comparison-down' : '') }}">
                <td class="font-bold">Change</td>
                <td class="font-bold">
                    {{ $comparison['change_amount_formatted'] }} 
                    ({{ $comparison['change_percentage_formatted'] }})
                    @if($comparison['direction'] == 'up') ↑ @elseif($comparison['direction'] == 'down') ↓ @else → @endif
                </td>
            </tr>
        </table>
    </div>

    @if(config('reports.revenue.include_forfeited_downpayments') && $forfeited_downpayments['count'] > 0)
    <!-- Forfeited Downpayments -->
    <div class="section">
        <div class="section-title">FORFEITED DOWNPAYMENTS (NON-REFUNDABLE)</div>
        
        <table class="simple-table">
            <tr>
                <td>Number of Cancellations</td>
                <td>{{ $forfeited_downpayments['count'] }}</td>
            </tr>
            <tr>
                <td class="font-bold">Total Forfeited Amount</td>
                <td class="font-bold">{{ $forfeited_downpayments['total_formatted'] }}</td>
            </tr>
        </table>
        
        <div class="alert-box">
            ⚠️ These amounts are from cancelled bookings where guests forfeited their non-refundable downpayments.
        </div>
    </div>
    @endif

    <!-- Outstanding Balances -->
    <div class="section">
        <div class="section-title">OUTSTANDING BALANCES</div>
        
        <table class="simple-table">
            <tr>
                <td>Number of Accounts with Balance</td>
                <td>{{ $outstanding_balances['count'] }}</td>
            </tr>
            <tr>
                <td class="font-bold">Total Amount Due</td>
                <td class="font-bold">{{ $outstanding_balances['total_formatted'] }}</td>
            </tr>
        </table>
        
        @if($outstanding_balances['count'] > 0)
        <div class="alert-box">
            ⚠️ There are {{ $outstanding_balances['count'] }} account(s) with outstanding balances totaling {{ $outstanding_balances['total_formatted'] }}.
        </div>
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        Generated on {{ $generated_at }} by {{ $generated_by }}<br>
        This is a computer-generated report. No signature required.
    </div>
</body>
</html>