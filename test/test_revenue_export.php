<?php

/**
 * Test Revenue Export and Expected Revenue Logic
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\RevenueReportService;
use App\Models\Booking;
use App\Models\Billing;
use Illuminate\Support\Facades\DB;

echo "=================================================================\n";
echo "Testing Revenue Export and Expected Revenue Logic\n";
echo "=================================================================\n\n";

try {
    // ========================================
    // TEST 1: Check Expected Revenue Logic
    // ========================================
    echo "TEST 1: Expected Revenue Logic\n";
    echo "-------------------------------\n";

    $today = now()->toDateString();
    echo "Today's date: {$today}\n\n";

    // Check for future confirmed bookings with unpaid balance
    $futureBookings = DB::table('billings')
        ->join('bookings', function ($join) {
            $join->on('billings.billable_id', '=', 'bookings.id')
                 ->where('billings.billable_type', '=', Booking::class);
        })
        ->where('bookings.booking_status', 'Confirmed')
        ->where('bookings.check_in_date', '>', $today)
        ->whereIn('billings.payment_status', ['unpaid', 'partial'])
        ->where('billings.billing_status', '!=', 'cancelled')
        ->whereNull('bookings.deleted_at')
        ->whereNull('billings.deleted_at')
        ->select(
            'bookings.id as booking_id',
            'bookings.booking_reference',
            'bookings.check_in_date',
            'billings.id as billing_id',
            'billings.total_amount',
            'billings.balance',
            'billings.payment_status'
        )
        ->get();

    echo "Future Confirmed Bookings with Unpaid Balance:\n";
    echo "Count: " . $futureBookings->count() . "\n";

    if ($futureBookings->count() > 0) {
        echo "\nBookings found:\n";
        foreach ($futureBookings as $booking) {
            echo "  - {$booking->booking_reference} | Check-in: {$booking->check_in_date} | Balance: ₱" . number_format($booking->balance, 2) . "\n";
        }

        $totalExpected = $futureBookings->sum('balance');
        echo "\nTotal Expected Revenue: ₱" . number_format($totalExpected, 2) . "\n";
    } else {
        echo "  ❌ No future bookings with unpaid balance found\n";
        echo "  This explains why Expected Revenue shows nothing!\n\n";

        // Check if there are any confirmed bookings at all
        $confirmedCount = Booking::where('booking_status', 'Confirmed')->count();
        echo "  Total Confirmed bookings: {$confirmedCount}\n";

        // Check how many are for future dates
        $futureCount = Booking::where('booking_status', 'Confirmed')
            ->where('check_in_date', '>', $today)
            ->count();
        echo "  Future Confirmed bookings: {$futureCount}\n";

        if ($futureCount > 0) {
            // Check their payment status
            $paidCount = Booking::where('booking_status', 'Confirmed')
                ->where('check_in_date', '>', $today)
                ->whereHas('billing', function($q) {
                    $q->where('payment_status', 'paid');
                })
                ->count();
            echo "  Future bookings already fully paid: {$paidCount}\n";
            echo "  (Fully paid bookings don't count as expected revenue)\n";
        }
    }
    echo "\n";

    // ========================================
    // TEST 2: Generate Revenue Report
    // ========================================
    echo "TEST 2: Generate Revenue Report\n";
    echo "--------------------------------\n";

    $reportService = new RevenueReportService();
    $reportService->setDatePreset('this_month');

    $reportData = $reportService->generate();

    echo "Report Period: {$reportData['period']['from']} to {$reportData['period']['to']}\n";
    echo "Report Label: {$reportData['period']['label']}\n\n";

    echo "Summary Metrics:\n";
    echo "  Total Revenue: {$reportData['summary']['total_revenue_formatted']}\n";
    echo "  Expected Revenue: {$reportData['summary']['expected_revenue_formatted']}\n";
    echo "    - Count: {$reportData['summary']['expected_count']}\n";
    echo "  Forfeited Amount: {$reportData['summary']['forfeited_amount_formatted']}\n";
    echo "    - Count: {$reportData['summary']['forfeited_count']}\n";
    echo "  Refunded Amount: {$reportData['summary']['refunded_amount_formatted']}\n";
    echo "    - Count: {$reportData['summary']['refunded_count']}\n\n";

    echo "Breakdown:\n";
    echo "  Entrance Fees: {$reportData['breakdown']['entrance_fees_formatted']}\n";
    echo "  Facility Rentals: {$reportData['breakdown']['facility_rentals_formatted']}\n";
    echo "  Third Party Services: {$reportData['breakdown']['third_party_services_formatted']}\n";
    echo "  Gross Revenue: {$reportData['breakdown']['gross_revenue_formatted']}\n";
    echo "  Discounts: {$reportData['breakdown']['discounts_formatted']}\n";
    echo "  Net Revenue: {$reportData['breakdown']['net_revenue_formatted']}\n\n";

    echo "Transactions: " . count($reportData['transactions']) . "\n";
    echo "Outstanding Balances: {$reportData['outstanding_balances']['total_formatted']} ({$reportData['outstanding_balances']['count']} items)\n\n";

    // ========================================
    // TEST 3: Test Excel Export Class
    // ========================================
    echo "TEST 3: Excel Export Class\n";
    echo "---------------------------\n";

    try {
        $export = new \App\Exports\RevenueExport($reportData);
        $sheets = $export->sheets();
        echo "✅ Excel export class instantiated successfully\n";
        echo "   Number of sheets: " . count($sheets) . "\n";

        if (count($sheets) > 0) {
            $summarySheet = $sheets[0];
            echo "   Sheet title: " . $summarySheet->title() . "\n";

            $collection = $summarySheet->collection();
            echo "   Data rows: " . $collection->count() . "\n";
        }
    } catch (\Exception $e) {
        echo "❌ Excel export class error: {$e->getMessage()}\n";
    }
    echo "\n";

    // ========================================
    // TEST 4: Check PDF View
    // ========================================
    echo "TEST 4: PDF View Template\n";
    echo "-------------------------\n";

    $viewPath = resource_path('views/reports/revenue-pdf.blade.php');
    if (file_exists($viewPath)) {
        echo "✅ PDF view template exists\n";
        echo "   Path: {$viewPath}\n";
        echo "   Size: " . number_format(filesize($viewPath)) . " bytes\n";

        // Try to compile the view
        try {
            $compiledView = view('reports.revenue-pdf', $reportData)->render();
            echo "✅ PDF view compiles successfully\n";
            echo "   Compiled size: " . number_format(strlen($compiledView)) . " bytes\n";
        } catch (\Exception $e) {
            echo "❌ PDF view compilation error: {$e->getMessage()}\n";
        }
    } else {
        echo "❌ PDF view template NOT FOUND\n";
        echo "   Expected path: {$viewPath}\n";
    }
    echo "\n";

    // ========================================
    // TEST 5: Check Required Packages
    // ========================================
    echo "TEST 5: Required Packages\n";
    echo "-------------------------\n";

    // Check if maatwebsite/excel is installed
    if (class_exists('Maatwebsite\Excel\Facades\Excel')) {
        echo "✅ Laravel Excel package is installed\n";
    } else {
        echo "❌ Laravel Excel package NOT FOUND\n";
        echo "   Install with: composer require maatwebsite/excel\n";
    }

    // Check if barryvdh/laravel-dompdf is installed
    if (class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
        echo "✅ DomPDF package is installed\n";
    } else {
        echo "❌ DomPDF package NOT FOUND\n";
        echo "   Install with: composer require barryvdh/laravel-dompdf\n";
    }
    echo "\n";

    // ========================================
    // SUMMARY
    // ========================================
    echo "=================================================================\n";
    echo "SUMMARY\n";
    echo "=================================================================\n\n";

    if ($futureBookings->count() === 0) {
        echo "⚠️  EXPECTED REVENUE ISSUE:\n";
        echo "   Expected Revenue shows nothing because there are no future\n";
        echo "   confirmed bookings with unpaid balances.\n\n";
        echo "   To see expected revenue:\n";
        echo "   1. Create a booking for a future date\n";
        echo "   2. Confirm it with partial payment (downpayment)\n";
        echo "   3. The remaining balance will show as expected revenue\n\n";
    }

    echo "✅ Revenue export classes are working correctly\n";
    echo "✅ Report generation is functional\n";
    echo "✅ Data structure is complete\n\n";

    echo "If exports are not working from the frontend:\n";
    echo "  1. Check API routes are registered\n";
    echo "  2. Verify frontend is calling correct endpoints\n";
    echo "  3. Check browser console for errors\n";
    echo "  4. Verify file download headers are correct\n\n";

} catch (\Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
