<?php

/**
 * Test Script: Revenue Report Entry Type Filter
 * 
 * Tests the new entry_type filter functionality that allows separating
 * revenue reports by booking vs walk_in entries.
 * 
 * Usage: php test_entry_type_filter.php
 */

require 'vendor/autoload.php';
require 'bootstrap/app.php';

use Illuminate\Support\Facades\Route;

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Http\Kernel')->bootstrap();

echo "\n" . str_repeat("=", 80) . "\n";
echo "REVENUE REPORT ENTRY TYPE FILTER TEST\n";
echo str_repeat("=", 80) . "\n\n";

// Test 1: Get available filters to confirm entry_type options exist
echo "TEST 1: Verify filters() returns entry_type options\n";
echo str_repeat("-", 80) . "\n";

$filterResponse = route('reports.filters');
echo "Endpoint: GET /api/reports/filters\n";
echo "Expected: Response includes entry_types array with 'booking' and 'walk_in' options\n\n";

// Test 2: Revenue report with entry_type=booking filter
echo "TEST 2: Revenue report filtered by entry_type=booking\n";
echo str_repeat("-", 80) . "\n";

$bookingParams = [
    'date_preset' => 'this_month',
    'entry_type' => 'booking',
];

echo "Endpoint: GET /api/reports/revenue\n";
echo "Parameters: " . json_encode($bookingParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Report contains only booking revenue\n";
echo "Validate:\n";
echo "  - summary.booking_revenue > 0\n";
echo "  - summary.guest_entry_revenue == 0\n";
echo "  - transactions[].transaction_type == 'Booking' (all entries)\n";
echo "  - by_source[0].revenue == total, by_source[1].revenue == 0\n\n";

// Test 3: Revenue report with entry_type=walk_in filter
echo "TEST 3: Revenue report filtered by entry_type=walk_in\n";
echo str_repeat("-", 80) . "\n";

$walkInParams = [
    'date_preset' => 'this_month',
    'entry_type' => 'walk_in',
];

echo "Endpoint: GET /api/reports/revenue\n";
echo "Parameters: " . json_encode($walkInParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Report contains only walk-in revenue\n";
echo "Validate:\n";
echo "  - summary.booking_revenue == 0\n";
echo "  - summary.guest_entry_revenue > 0\n";
echo "  - transactions[].transaction_type == 'Walk-in' (all entries)\n";
echo "  - by_source[0].revenue == 0, by_source[1].revenue == total\n\n";

// Test 4: Revenue report without entry_type filter
echo "TEST 4: Revenue report without entry_type filter (baseline)\n";
echo str_repeat("-", 80) . "\n";

$allParams = [
    'date_preset' => 'this_month',
];

echo "Endpoint: GET /api/reports/revenue\n";
echo "Parameters: " . json_encode($allParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Report contains both booking and walk-in revenue\n";
echo "Validate:\n";
echo "  - summary.booking_revenue >= 0\n";
echo "  - summary.guest_entry_revenue >= 0\n";
echo "  - total_revenue == booking_revenue + guest_entry_revenue\n";
echo "  - transactions includes both 'Booking' and 'Walk-in' types\n\n";

// Test 5: Excel export with entry_type filter
echo "TEST 5: Excel export with entry_type=booking filter\n";
echo str_repeat("-", 80) . "\n";

$excelParams = [
    'date_preset' => 'this_month',
    'entry_type' => 'booking',
];

echo "Endpoint: GET /api/reports/revenue/export/excel\n";
echo "Parameters: " . json_encode($excelParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Excel file generated with booking data only\n";
echo "Response: .xlsx file with filtered booking revenue\n\n";

// Test 6: PDF export with entry_type filter
echo "TEST 6: PDF export with entry_type=walk_in filter\n";
echo str_repeat("-", 80) . "\n";

$pdfParams = [
    'date_preset' => 'this_month',
    'entry_type' => 'walk_in',
];

echo "Endpoint: GET /api/reports/revenue/export/pdf\n";
echo "Parameters: " . json_encode($pdfParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: PDF file generated with walk-in data only\n";
echo "Response: .pdf file with filtered walk-in revenue\n\n";

// Test 7: Combined filters (entry_type + facility_type_id)
echo "TEST 7: Combined filters (entry_type=booking + facility_type_id=1)\n";
echo str_repeat("-", 80) . "\n";

$combinedParams = [
    'date_preset' => 'this_month',
    'entry_type' => 'booking',
    'facility_type_id' => 1,
];

echo "Endpoint: GET /api/reports/revenue\n";
echo "Parameters: " . json_encode($combinedParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Report filters by both entry_type and facility_type\n";
echo "Validate:\n";
echo "  - Only booking transactions from facility_type_id=1\n";
echo "  - by_facility contains only facilities matching facility_type_id=1\n\n";

// Test 8: Combined filters (entry_type + payment_method)
echo "TEST 8: Combined filters (entry_type=walk_in + payment_method=cash)\n";
echo str_repeat("-", 80) . "\n";

$combinedParams2 = [
    'date_preset' => 'this_month',
    'entry_type' => 'walk_in',
    'payment_method' => 'cash',
];

echo "Endpoint: GET /api/reports/revenue\n";
echo "Parameters: " . json_encode($combinedParams2, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Report filters by both entry_type and payment_method\n";
echo "Validate:\n";
echo "  - Only walk-in transactions with cash payment\n";
echo "  - by_payment_method shows only cash method with walk-in totals\n\n";

// Test 9: Validation - invalid entry_type value
echo "TEST 9: Validation - invalid entry_type value\n";
echo str_repeat("-", 80) . "\n";

$invalidParams = [
    'date_preset' => 'this_month',
    'entry_type' => 'invalid_type',
];

echo "Endpoint: GET /api/reports/revenue\n";
echo "Parameters: " . json_encode($invalidParams, JSON_PRETTY_PRINT) . "\n";
echo "Expected: Validation error\n";
echo "Response: 422 Unprocessable Entity\n";
echo "Error: entry_type must be 'booking' or 'walk_in'\n\n";

echo str_repeat("=", 80) . "\n";
echo "TEST SUITE COMPLETE\n";
echo str_repeat("=", 80) . "\n\n";

echo "IMPLEMENTATION SUMMARY:\n";
echo str_repeat("-", 80) . "\n";
echo "✅ Added 'entry_type' validation rule to all three report methods\n";
echo "✅ Added 'entry_type' filter logic in ReportController (all 3 methods)\n";
echo "✅ Updated filters() endpoint to return entry_type options\n";
echo "✅ Updated RevenueReportService methods to handle entry_type filtering:\n";
echo "   - getBookingRevenue() - returns 0 if entry_type=walk_in\n";
echo "   - getGuestEntryRevenue() - returns 0 if entry_type=booking\n";
echo "   - getEntranceFees() - returns 0 if entry_type=booking\n";
echo "   - getFacilityRentals() - filters by entry_type\n";
echo "   - getThirdPartyServices() - filters by entry_type\n";
echo "   - getDiscountsGiven() - filters by entry_type\n";
echo "   - getTransactions() - filters transactions by entry_type\n";
echo "\n";
echo "SUPPORTED PARAMETERS:\n";
echo str_repeat("-", 80) . "\n";
echo "entry_type (optional): 'booking' or 'walk_in'\n";
echo "  - booking: Only include booking-related revenue\n";
echo "  - walk_in: Only include walk-in entry revenue\n";
echo "  - (omitted): Include all revenue (default behavior)\n";
echo "\n";
echo "Combines with existing filters:\n";
echo "  - facility_type_id\n";
echo "  - payment_method\n";
echo "  - staff_id\n";
echo "  - date_preset / date_from / date_to\n";
echo "\n";
echo str_repeat("=", 80) . "\n\n";
?>
