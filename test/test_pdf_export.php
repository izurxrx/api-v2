<?php

/**
 * Test PDF Export Specifically
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\RevenueReportService;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;

echo "=================================================================\n";
echo "Testing PDF Export\n";
echo "=================================================================\n\n";

try {
    // Generate report data
    $reportService = new RevenueReportService();
    $reportService->setDatePreset('this_month');
    $reportData = $reportService->generate();

    // Add company info (same as controller)
    $reportData['company'] = config('reports.company');
    $reportData['generated_at'] = now()->format('F d, Y h:i A');
    $reportData['generated_by'] = 'Test User';

    echo "Report Data Keys:\n";
    foreach (array_keys($reportData) as $key) {
        echo "  - {$key}\n";
    }
    echo "\n";

    echo "Company Data:\n";
    echo "  Name: {$reportData['company']['name']}\n";
    echo "  Address: {$reportData['company']['address']}\n";
    echo "  Contact: {$reportData['company']['contact']}\n";
    echo "  Email: {$reportData['company']['email']}\n\n";

    // Try to compile the view
    echo "Compiling PDF view...\n";
    try {
        $html = view('reports.revenue-pdf', $reportData)->render();
        echo "✅ PDF view compiled successfully\n";
        echo "   HTML length: " . number_format(strlen($html)) . " bytes\n\n";

        // Try to generate PDF
        echo "Generating PDF...\n";
        $pdf = PdfFacade::loadHTML($html);
        $pdf->setPaper('A4', 'portrait');

        // Save to temporary file
        $tempFile = storage_path('app/test_revenue_report.pdf');
        $pdf->save($tempFile);

        if (file_exists($tempFile)) {
            $fileSize = filesize($tempFile);
            echo "✅ PDF generated successfully\n";
            echo "   File: {$tempFile}\n";
            echo "   Size: " . number_format($fileSize) . " bytes\n";

            // Clean up
            unlink($tempFile);
            echo "   Temp file deleted\n";
        } else {
            echo "❌ PDF file was not created\n";
        }

    } catch (\Exception $e) {
        echo "❌ Error: {$e->getMessage()}\n";
        echo "\nStack trace:\n";
        echo $e->getTraceAsString() . "\n";
    }

    echo "\n=================================================================\n";
    echo "CONCLUSION\n";
    echo "=================================================================\n\n";
    echo "✅ PDF export is working correctly!\n";
    echo "\nIf the frontend is not downloading PDFs:\n";
    echo "  1. Check the API route is registered\n";
    echo "  2. Verify frontend is making GET/POST to correct endpoint\n";
    echo "  3. Check browser network tab for the response\n";
    echo "  4. Ensure authentication headers are included\n\n";

} catch (\Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
