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
            new RevenueBreakdownSheet($this->reportData),
            new RevenueByFacilitySheet($this->reportData),
            new RevenueByPaymentMethodSheet($this->reportData),
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
        
        // Company Info
        $data->push(['REVENUE REPORT']);
        $data->push([config('reports.company.name')]);
        $data->push(['Period: ' . $this->reportData['period']['label']]);
        $data->push(['Generated: ' . now()->format('F d, Y h:i A')]);
        $data->push(['']); // Empty row

        // Summary
        $data->push(['SUMMARY']);
        $data->push(['Total Revenue', $this->reportData['summary']['total_revenue_formatted']]);
        $data->push(['  From Bookings', $this->reportData['summary']['booking_revenue_formatted'], number_format($this->reportData['summary']['booking_percentage'], 1) . '%']);
        $data->push(['  From Walk-ins', $this->reportData['summary']['guest_entry_revenue_formatted'], number_format($this->reportData['summary']['guest_entry_percentage'], 1) . '%']);
        $data->push(['']); // Empty row

        // Comparison
        $comparison = $this->reportData['comparison'];
        $data->push(['COMPARISON']);
        $data->push(['Current Period', $comparison['current_period_formatted']]);
        $data->push(['Previous Period', $comparison['previous_period_formatted']]);
        $data->push(['Change', $comparison['change_amount_formatted'], $comparison['change_percentage_formatted'] . ' ' . $this->getArrow($comparison['direction'])]);
        $data->push(['']); // Empty row

        // Outstanding Balances
        $outstanding = $this->reportData['outstanding_balances'];
        $data->push(['OUTSTANDING BALANCES']);
        $data->push(['Number of Accounts', $outstanding['count']]);
        $data->push(['Total Amount Due', $outstanding['total_formatted']]);

        // Forfeited Downpayments
        if (config('reports.revenue.include_forfeited_downpayments')) {
            $forfeited = $this->reportData['forfeited_downpayments'];
            $data->push(['']); // Empty row
            $data->push(['FORFEITED DOWNPAYMENTS (NON-REFUNDABLE)']);
            $data->push(['Number of Cancellations', $forfeited['count']]);
            $data->push(['Total Forfeited', $forfeited['total_formatted']]);
        }

        return $data;
    }

    public function headings(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 16],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            2 => [
                'font' => ['size' => 12],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            6 => ['font' => ['bold' => true, 'size' => 12]],
            11 => ['font' => ['bold' => true, 'size' => 12]],
            16 => ['font' => ['bold' => true, 'size' => 12]],
        ];
    }

    protected function getArrow($direction)
    {
        return match($direction) {
            'up' => '↑',
            'down' => '↓',
            default => '→',
        };
    }
}

/**
 * Breakdown Sheet
 */
class RevenueBreakdownSheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function collection()
    {
        $breakdown = $this->reportData['breakdown'];
        
        return collect([
            ['Category', 'Amount'],
            ['Entrance Fees', $breakdown['entrance_fees_formatted']],
            ['Facility Rentals', $breakdown['facility_rentals_formatted']],
            ['Third-Party Services', $breakdown['third_party_services_formatted']],
            ['Gross Revenue', $breakdown['gross_revenue_formatted']],
            ['Less: Discounts', '(' . $breakdown['discounts_formatted'] . ')'],
            ['Net Revenue', $breakdown['net_revenue_formatted']],
        ]);
    }

    public function headings(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Breakdown';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
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
            5 => ['font' => ['bold' => true]],
            7 => [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DBEAFE'],
                ],
            ],
        ];
    }
}

class RevenueByFacilitySheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function collection()
    {
        $data = collect([
            ['Facility Name', 'Facility Type', 'Revenue', 'Percentage'],
        ]);

        foreach ($this->reportData['by_facility'] as $item) {
            $data->push([
                $item['facility_name'],
                $item['facility_type'],
                $item['revenue_formatted'],
                number_format($item['percentage'], 1) . '%',
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
        return 'By Facility';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
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


/**
 * Revenue by Payment Method Sheet
 */
class RevenueByPaymentMethodSheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function collection()
    {
        $data = collect([
            ['Payment Method', 'Count', 'Revenue', 'Average', 'Percentage'],
        ]);

        foreach ($this->reportData['by_payment_method'] as $item) {
            $data->push([
                $item['method'],
                $item['count'],
                $item['revenue_formatted'],
                '₱' . number_format($item['average'], 2),
                number_format($item['percentage'], 1) . '%',
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
        return 'By Payment Method';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
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