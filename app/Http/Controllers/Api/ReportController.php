<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RevenueReportService;
use App\Exports\RevenueExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Generate revenue report
     */
    public function revenue(Request $request)
    {
        $validated = $request->validate([
            'date_preset' => 'nullable|string|in:today,yesterday,this_week,last_week,this_month,last_month,this_quarter,last_quarter,this_year,last_year',
            'date_from' => 'nullable|required_without:date_preset|date',
            'date_to' => 'nullable|required_without:date_preset|date|after_or_equal:date_from',
            'facility_type_id' => 'nullable|integer|exists:facility_types,id',
            'payment_method' => 'nullable|string|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            'staff_id' => 'nullable|integer|exists:users,id',
            'entry_type' => 'nullable|string|in:booking,walk_in',
        ]);

        try {
            $reportService = new RevenueReportService();

            // Set date range
            if (!empty($validated['date_preset'])) {
                $reportService->setDatePreset($validated['date_preset']);
            } else {
                $reportService->setDateRange(
                    $validated['date_from'] ?? now()->startOfMonth(),
                    $validated['date_to'] ?? now()->endOfMonth()
                );
            }

            // Set filters
            $filters = [];
            if (!empty($validated['facility_type_id'])) {
                $filters['facility_type_id'] = $validated['facility_type_id'];
            }
            if (!empty($validated['payment_method'])) {
                $filters['payment_method'] = $validated['payment_method'];
            }
            if (!empty($validated['staff_id'])) {
                $filters['staff_id'] = $validated['staff_id'];
            }
            if (!empty($validated['entry_type'])) {
                $filters['entry_type'] = $validated['entry_type'];
            }
            
            if (!empty($filters)) {
                $reportService->setFilters($filters);
            }

            // Generate report
            $reportData = $reportService->generate();

            Log::info('Revenue report generated', [
                'period' => $reportData['period'],
                'total_revenue' => $reportData['summary']['total_revenue'],
                'generated_by' => auth()->id(),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            Log::error('Revenue report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate revenue report',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Export revenue report to Excel
     */
    public function exportRevenueExcel(Request $request)
    {
        $validated = $request->validate([
            'date_preset' => 'nullable|string|in:today,yesterday,this_week,last_week,this_month,last_month,this_quarter,last_quarter,this_year,last_year',
            'date_from' => 'nullable|required_without:date_preset|date',
            'date_to' => 'nullable|required_without:date_preset|date|after_or_equal:date_from',
            'facility_type_id' => 'nullable|integer|exists:facility_types,id',
            'payment_method' => 'nullable|string|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            'staff_id' => 'nullable|integer|exists:users,id',
            'entry_type' => 'nullable|string|in:booking,walk_in',
        ]);

        try {
            $reportService = new RevenueReportService();

            // Set date range
            if (!empty($validated['date_preset'])) {
                $reportService->setDatePreset($validated['date_preset']);
            } else {
                $reportService->setDateRange(
                    $validated['date_from'] ?? now()->startOfMonth(),
                    $validated['date_to'] ?? now()->endOfMonth()
                );
            }

            // Set filters
            $filters = [];
            if (!empty($validated['facility_type_id'])) {
                $filters['facility_type_id'] = $validated['facility_type_id'];
            }
            if (!empty($validated['payment_method'])) {
                $filters['payment_method'] = $validated['payment_method'];
            }
            if (!empty($validated['staff_id'])) {
                $filters['staff_id'] = $validated['staff_id'];
            }
            if (!empty($validated['entry_type'])) {
                $filters['entry_type'] = $validated['entry_type'];
            }
            
            if (!empty($filters)) {
                $reportService->setFilters($filters);
            }

            // Generate report data
            $reportData = $reportService->generate();

            // Generate filename
            $filename = sprintf(
                'revenue_report_%s_%s.xlsx',
                $reportData['period']['from'],
                $reportData['period']['to']
            );

            Log::info('Revenue report exported to Excel', [
                'filename' => $filename,
                'period' => $reportData['period'],
                'exported_by' => auth()->id(),
            ]);

            return Excel::download(
                new RevenueExport($reportData),
                $filename
            );

        } catch (\Exception $e) {
            Log::error('Revenue report Excel export failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export revenue report to Excel',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Export revenue report to PDF
     */
    public function exportRevenuePdf(Request $request)
    {
        $validated = $request->validate([
            'date_preset' => 'nullable|string|in:today,yesterday,this_week,last_week,this_month,last_month,this_quarter,last_quarter,this_year,last_year',
            'date_from' => 'nullable|required_without:date_preset|date',
            'date_to' => 'nullable|required_without:date_preset|date|after_or_equal:date_from',
            'facility_type_id' => 'nullable|integer|exists:facility_types,id',
            'payment_method' => 'nullable|string|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            'staff_id' => 'nullable|integer|exists:users,id',
            'entry_type' => 'nullable|string|in:booking,walk_in',
        ]);

        try {
            $reportService = new RevenueReportService();

            // Set date range
            if (!empty($validated['date_preset'])) {
                $reportService->setDatePreset($validated['date_preset']);
            } else {
                $reportService->setDateRange(
                    $validated['date_from'] ?? now()->startOfMonth(),
                    $validated['date_to'] ?? now()->endOfMonth()
                );
            }

            // Set filters
            $filters = [];
            if (!empty($validated['facility_type_id'])) {
                $filters['facility_type_id'] = $validated['facility_type_id'];
            }
            if (!empty($validated['payment_method'])) {
                $filters['payment_method'] = $validated['payment_method'];
            }
            if (!empty($validated['staff_id'])) {
                $filters['staff_id'] = $validated['staff_id'];
            }
            if (!empty($validated['entry_type'])) {
                $filters['entry_type'] = $validated['entry_type'];
            }
            
            if (!empty($filters)) {
                $reportService->setFilters($filters);
            }

            // Generate report data
            $reportData = $reportService->generate();

            // Add company info
            $reportData['company'] = config('reports.company');
            $reportData['generated_at'] = now()->format('F d, Y h:i A');
            $reportData['generated_by'] = auth()->user()->full_name ?? 'System';

            // Generate filename
            $filename = sprintf(
                'revenue_report_%s_%s.pdf',
                $reportData['period']['from'],
                $reportData['period']['to']
            );

            Log::info('Revenue report exported to PDF', [
                'filename' => $filename,
                'period' => $reportData['period'],
                'exported_by' => auth()->id(),
            ]);

            // Generate PDF
            $pdf = Pdf::loadView('reports.revenue-pdf', $reportData);
            
            // Set paper size and orientation from config
            $pdf->setPaper(
                config('reports.export.pdf.page_size', 'A4'),
                config('reports.export.pdf.orientation', 'portrait')
            );

            return $pdf->download($filename);

        } catch (\Exception $e) {
            Log::error('Revenue report PDF export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export revenue report to PDF',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get available date presets
     */
    public function datePresets()
    {
        return response()->json([
            'status' => 'success',
            'data' => config('reports.date_presets'),
        ]);
    }

    /**
     * Get available filters
     */
    public function filters()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'facility_types' => \App\Models\FacilityType::select('id', 'type_name')->get(),
                'payment_methods' => [
                    ['value' => 'cash', 'label' => 'Cash'],
                    ['value' => 'gcash', 'label' => 'GCash'],
                    ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
                    ['value' => 'credit_card', 'label' => 'Credit Card'],
                    ['value' => 'debit_card', 'label' => 'Debit Card'],
                    ['value' => 'other', 'label' => 'Other'],
                ],
                'entry_types' => [
                    ['value' => 'booking', 'label' => 'Bookings'],
                    ['value' => 'walk_in', 'label' => 'Walk-in Entries'],
                ],
                'staff' => \App\Models\User::select('id', 'first_name', 'last_name')
                    ->get()
                    ->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->full_name,
                        ];
                    }),
            ],
        ]);
    }
}