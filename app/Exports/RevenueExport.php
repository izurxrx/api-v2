<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class RevenueExport implements WithMultipleSheets
{
    protected $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    /**
     * Create multiple sheets
     */
    public function sheets(): array
    {
        return [
            new RevenueSummarySheet($this->reportData),
        ];
    }
}

/**
 * Summary Sheet
 */
class RevenueSummarySheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function collection()
    {
        $data = collect();
        $summary = $this->reportData['summary'];
        $period = $this->reportData['period'];
        $transactions = $this->reportData['transactions'] ?? [];
        $outstanding = $this->reportData['outstanding_balances'] ?? [];

        // Header
        $data->push(['REVENUE REPORT']);
        $data->push([config('reports.company.name')]);
        $data->push(['Period: ' . ($period['label'] ?? '')]);
        $data->push(['Generated: ' . now()->format('F d, Y h:i A')]);
        $data->push(['']);

        // 6 Dashboard Metrics
        $data->push(['DASHBOARD METRICS']);
        $data->push(['Revenue', $summary['total_revenue_formatted'] ?? '']);
        $data->push(['Unpaid Balance', $outstanding['total_formatted'] ?? '']);
        $data->push(['Expected Revenue', $summary['expected_revenue_formatted'] ?? '']);
        $data->push(['Non-refundable Cancellations', $summary['forfeited_amount_formatted'] ?? '']);
        $data->push(['Refunds', $summary['refunded_amount_formatted'] ?? '']);
        $data->push(['Total Transactions', count($transactions)]);
        $data->push(['']);

        // Transactions Table Header
        $data->push(['Date & Time', 'Reference', 'Type', 'Guest Name', 'Amount Paid', 'Payment Method']);
        foreach ($transactions as $t) {
            $data->push([
                $t['transaction_date_formatted'] ?? '',
                $t['reference'] ?? '',
                $t['transaction_type'] ?? '',
                $t['guest_name'] ?? '',
                $t['amount_paid_formatted'] ?? '',
                $t['payment_methods'] ?? '',
            ]);
        }
        return $data;
    }

    public function headings(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Revenue Report';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Header rows
            1 => [
                'font' => ['bold' => true, 'size' => 16],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            2 => [
                'font' => ['size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            // Dashboard Metrics section header
            6 => ['font' => ['bold' => true, 'size' => 12]],
            // Transactions table header
            13 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ],
        ];
    }
}