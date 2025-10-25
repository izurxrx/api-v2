<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GuestEntry;
use App\Models\Billing;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevenueReportService extends ReportService
{
    /**
     * Generate revenue report
     */
    public function generate(): array
    {
        Log::info('Generating revenue report', [
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'filters' => $this->filters,
        ]);

        return [
            'summary' => $this->getSummary(),
            'breakdown' => $this->getBreakdown(),
            'by_facility' => $this->getRevenueByFacility(),
            'by_payment_method' => $this->getRevenueByPaymentMethod(),
            'by_source' => $this->getRevenueBySource(),
            'forfeited_downpayments' => $this->getForfeitedDownpayments(),
            'outstanding_balances' => $this->getOutstandingBalances(),
            'comparison' => $this->getComparison(),
            'period' => [
                'from' => $this->dateFrom->format('Y-m-d'),
                'to' => $this->dateTo->format('Y-m-d'),
                'label' => $this->getPeriodLabel(),
            ],
        ];
    }

    /**
     * Get revenue summary
     */
    protected function getSummary(): array
    {
        return $this->getCachedData('revenue_summary', function () {
            $bookingRevenue = $this->getBookingRevenue();
            $guestEntryRevenue = $this->getGuestEntryRevenue();
            
            $totalRevenue = $bookingRevenue + $guestEntryRevenue;

            return [
                'total_revenue' => $totalRevenue,
                'total_revenue_formatted' => $this->formatCurrency($totalRevenue),
                'booking_revenue' => $bookingRevenue,
                'booking_revenue_formatted' => $this->formatCurrency($bookingRevenue),
                'booking_percentage' => $totalRevenue > 0 ? ($bookingRevenue / $totalRevenue) * 100 : 0,
                'guest_entry_revenue' => $guestEntryRevenue,
                'guest_entry_revenue_formatted' => $this->formatCurrency($guestEntryRevenue),
                'guest_entry_percentage' => $totalRevenue > 0 ? ($guestEntryRevenue / $totalRevenue) * 100 : 0,
            ];
        });
    }

    /**
     * Get revenue breakdown
     */
    protected function getBreakdown(): array
    {
        return $this->getCachedData('revenue_breakdown', function () {
            // Get entrance fees (only from guest entries)
            $entranceFees = $this->getEntranceFees();
            
            // Get facility rentals (from both bookings and guest entries)
            $facilityRentals = $this->getFacilityRentals();
            
            // Get third-party services
            $thirdPartyServices = $this->getThirdPartyServices();
            
            // Get discounts given
            $discounts = $this->getDiscountsGiven();
            
            $grossRevenue = $entranceFees + $facilityRentals + $thirdPartyServices;
            $netRevenue = $grossRevenue - $discounts;

            return [
                'entrance_fees' => $entranceFees,
                'entrance_fees_formatted' => $this->formatCurrency($entranceFees),
                'facility_rentals' => $facilityRentals,
                'facility_rentals_formatted' => $this->formatCurrency($facilityRentals),
                'third_party_services' => $thirdPartyServices,
                'third_party_services_formatted' => $this->formatCurrency($thirdPartyServices),
                'gross_revenue' => $grossRevenue,
                'gross_revenue_formatted' => $this->formatCurrency($grossRevenue),
                'discounts' => $discounts,
                'discounts_formatted' => $this->formatCurrency($discounts),
                'net_revenue' => $netRevenue,
                'net_revenue_formatted' => $this->formatCurrency($netRevenue),
            ];
        });
    }

    /**
     * Get booking revenue (amount paid from billings)
     */
    protected function getBookingRevenue(): float
    {
        $bookingIds = Booking::whereIn('booking_status', config('reports.revenue.completed_statuses.bookings'))
            ->whereBetween('check_out_datetime', [$this->dateFrom, $this->dateTo])
            ->pluck('id');

        return Billing::where('billable_type', Booking::class)
            ->whereIn('billable_id', $bookingIds)
            ->whereIn('payment_status', config('reports.revenue.payment_statuses'))
            ->sum('amount_paid');
    }

    /**
     * Get guest entry revenue (amount paid from billings)
     */
    protected function getGuestEntryRevenue(): float
    {
        $guestEntryIds = GuestEntry::where('is_checked_out', true)
            ->whereBetween('checkout_datetime', [$this->dateFrom, $this->dateTo])
            ->pluck('id');

        return Billing::where('billable_type', GuestEntry::class)
            ->whereIn('billable_id', $guestEntryIds)
            ->whereIn('payment_status', config('reports.revenue.payment_statuses'))
            ->sum('amount_paid');
    }

    /**
     * Get entrance fees (from guest entries only)
     */
    protected function getEntranceFees(): float
    {
        return GuestEntry::where('is_checked_out', true)
            ->whereBetween('checkout_datetime', [$this->dateFrom, $this->dateTo])
            ->sum('entrance_subtotal');
    }

    /**
     * Get facility rentals (from both bookings and guest entries)
     */
    protected function getFacilityRentals(): float
    {
        $bookingFacilities = Booking::whereIn('booking_status', config('reports.revenue.completed_statuses.bookings'))
            ->whereBetween('check_out_datetime', [$this->dateFrom, $this->dateTo])
            ->sum('facility_subtotal');

        $guestEntryFacilities = GuestEntry::where('is_checked_out', true)
            ->whereBetween('checkout_datetime', [$this->dateFrom, $this->dateTo])
            ->sum('facility_subtotal');

        return $bookingFacilities + $guestEntryFacilities;
    }

    /**
     * Get third-party services
     */
    protected function getThirdPartyServices(): float
    {
        $bookingServices = Booking::whereIn('booking_status', config('reports.revenue.completed_statuses.bookings'))
            ->whereBetween('check_out_datetime', [$this->dateFrom, $this->dateTo])
            ->sum('third_party_service_amount');

        $guestEntryServices = GuestEntry::where('is_checked_out', true)
            ->whereBetween('checkout_datetime', [$this->dateFrom, $this->dateTo])
            ->sum('third_party_service_amount');

        return $bookingServices + $guestEntryServices;
    }

    /**
     * Get discounts given (from billings for bookings, direct from guest_entries)
     */
    protected function getDiscountsGiven(): float
    {
        // Get completed booking IDs
        $bookingIds = Booking::whereIn('booking_status', config('reports.revenue.completed_statuses.bookings'))
            ->whereBetween('check_out_datetime', [$this->dateFrom, $this->dateTo])
            ->pluck('id');

        // Get discounts from billings for bookings
        $bookingDiscounts = Billing::where('billable_type', Booking::class)
            ->whereIn('billable_id', $bookingIds)
            ->sum('discount_amount');

        // Get discounts from guest entries (has discount_amount column)
        $guestEntryDiscounts = GuestEntry::where('is_checked_out', true)
            ->whereBetween('checkout_datetime', [$this->dateFrom, $this->dateTo])
            ->sum('discount_amount');

        return $bookingDiscounts + $guestEntryDiscounts;
    }

    /**
     * Get revenue by facility (not by type)
     */
    protected function getRevenueByFacility(): array
    {
        return $this->getCachedData('revenue_by_facility', function () {

            // Revenue from completed bookings by facility
            $bookingRevenue = DB::table('bookings')
                ->join('booking_facilities', 'bookings.id', '=', 'booking_facilities.booking_id')
                ->join('facilities', 'booking_facilities.facility_id', '=', 'facilities.id')
                ->join('facility_types', 'facilities.facility_type_id', '=', 'facility_types.id')
                ->whereIn('bookings.booking_status', config('reports.revenue.completed_statuses.bookings'))
                ->whereBetween('bookings.check_out_datetime', [$this->dateFrom, $this->dateTo])
                ->whereNull('bookings.deleted_at')
                ->select(
                    'facilities.name as facility_name',
                    'facility_types.name as facility_type',
                    DB::raw('SUM(booking_facilities.base_amount * booking_facilities.quantity) as total')
                )
                ->groupBy('facilities.id', 'facilities.name', 'facility_types.name')
                ->get();

            // Revenue from completed guest entries by facility
            $guestEntryRevenue = DB::table('guest_entries')
                ->join('guest_entry_facilities', 'guest_entries.id', '=', 'guest_entry_facilities.guest_entry_id')
                ->join('facilities', 'guest_entry_facilities.facility_id', '=', 'facilities.id')
                ->join('facility_types', 'facilities.facility_type_id', '=', 'facility_types.id')
                ->where('guest_entries.is_checked_out', true)
                ->whereBetween('guest_entries.checkout_datetime', [$this->dateFrom, $this->dateTo])
                ->whereNull('guest_entries.deleted_at')
                ->select(
                    'facilities.name as facility_name',
                    'facility_types.name as facility_type',
                    DB::raw('SUM(guest_entry_facilities.subtotal) as total')
                )
                ->groupBy('facilities.id', 'facilities.name', 'facility_types.name')
                ->get();

            // Merge results by facility
            $merged = [];

            foreach ($bookingRevenue as $item) {
                $key = $item->facility_name;
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'facility_name' => $item->facility_name,
                        'facility_type' => $item->facility_type,
                        'revenue' => 0
                    ];
                }
                $merged[$key]['revenue'] += $item->total ?? 0;
            }

            foreach ($guestEntryRevenue as $item) {
                $key = $item->facility_name;
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'facility_name' => $item->facility_name,
                        'facility_type' => $item->facility_type,
                        'revenue' => 0
                    ];
                }
                $merged[$key]['revenue'] += $item->total ?? 0;
            }

            // Calculate total for percentages
            $total = array_sum(array_column($merged, 'revenue'));

            // Format results
            $results = [];
            foreach ($merged as $facilityName => $data) {
                $results[] = [
                    'facility_name' => $data['facility_name'],
                    'facility_type' => $data['facility_type'],
                    'revenue' => $data['revenue'],
                    'revenue_formatted' => $this->formatCurrency($data['revenue']),
                    'percentage' => $total > 0 ? ($data['revenue'] / $total) * 100 : 0,
                ];
            }

            // Sort by revenue descending
            usort($results, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

            return $results;
        });
    }


    /**
     * Get revenue by payment method
     */
    protected function getRevenueByPaymentMethod(): array
    {
        return $this->getCachedData('revenue_by_payment_method', function () {
            
            // Get booking IDs in period
            $bookingIds = Booking::whereIn('booking_status', config('reports.revenue.completed_statuses.bookings'))
                ->whereBetween('check_out_datetime', [$this->dateFrom, $this->dateTo])
                ->pluck('id');

            // Get guest entry IDs in period
            $guestEntryIds = GuestEntry::where('is_checked_out', true)
                ->whereBetween('checkout_datetime', [$this->dateFrom, $this->dateTo])
                ->pluck('id');

            // Get billing IDs
            $billingIds = Billing::where(function($q) use ($bookingIds, $guestEntryIds) {
                $q->where(function($query) use ($bookingIds) {
                    $query->where('billable_type', Booking::class)
                          ->whereIn('billable_id', $bookingIds);
                })
                ->orWhere(function($query) use ($guestEntryIds) {
                    $query->where('billable_type', GuestEntry::class)
                          ->whereIn('billable_id', $guestEntryIds);
                });
            })->pluck('id');

            // Get payments
            $data = DB::table('payments')
                ->whereIn('billing_id', $billingIds)
                ->whereBetween('payment_date', [$this->dateFrom, $this->dateTo])
                ->select(
                    'payment_method',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(amount) as total')
                )
                ->groupBy('payment_method')
                ->get();

            $total = $data->sum('total');

            return $data->map(function ($item) use ($total) {
                return [
                    'method' => ucfirst(str_replace('_', ' ', $item->payment_method)),
                    'count' => $item->count,
                    'revenue' => $item->total,
                    'revenue_formatted' => $this->formatCurrency($item->total),
                    'percentage' => $total > 0 ? ($item->total / $total) * 100 : 0,
                    'average' => $item->count > 0 ? $item->total / $item->count : 0,
                ];
            })->toArray();
        });
    }

    /**
     * Get revenue by source
     */
    protected function getRevenueBySource(): array
    {
        $bookingRevenue = $this->getBookingRevenue();
        $guestEntryRevenue = $this->getGuestEntryRevenue();
        $total = $bookingRevenue + $guestEntryRevenue;

        return [
            [
                'source' => 'Bookings',
                'revenue' => $bookingRevenue,
                'revenue_formatted' => $this->formatCurrency($bookingRevenue),
                'percentage' => $total > 0 ? ($bookingRevenue / $total) * 100 : 0,
            ],
            [
                'source' => 'Walk-ins',
                'revenue' => $guestEntryRevenue,
                'revenue_formatted' => $this->formatCurrency($guestEntryRevenue),
                'percentage' => $total > 0 ? ($guestEntryRevenue / $total) * 100 : 0,
            ],
        ];
    }

    /**
     * Get forfeited downpayments (non-refundable cancellations)
     */
    protected function getForfeitedDownpayments(): array
    {
        if (!config('reports.revenue.include_forfeited_downpayments')) {
            return [
                'total' => 0,
                'total_formatted' => $this->formatCurrency(0),
                'count' => 0,
            ];
        }

        // Only consider cancelled bookings with paid downpayments
        $forfeited = DB::table('billings')
            ->join('bookings', 'billings.billable_id', '=', 'bookings.id')
            ->where('billings.billable_type', Booking::class)
            ->where('bookings.booking_status', 'Cancelled')
            ->whereBetween('bookings.cancelled_at', [$this->dateFrom, $this->dateTo])
            ->where('billings.is_downpayment_paid', true)
            ->select(
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(billings.downpayment_paid) as total')
            )
            ->first();

        return [
            'total' => $forfeited->total ?? 0,
            'total_formatted' => $this->formatCurrency($forfeited->total ?? 0),
            'count' => $forfeited->count ?? 0,
        ];
    }


    /**
     * Get outstanding balances
     */
    protected function getOutstandingBalances(): array
    {
        $outstanding = Billing::whereIn('payment_status', ['unpaid', 'partial'])
            ->where('billing_status', '!=', 'cancelled')
            ->select(DB::raw('COUNT(*) as count'), DB::raw('SUM(balance) as total'))
            ->first();

        return [
            'total' => $outstanding->total ?? 0,
            'total_formatted' => $this->formatCurrency($outstanding->total ?? 0),
            'count' => $outstanding->count ?? 0,
        ];
    }

    /**
     * Get comparison with previous period
     */
    protected function getComparison(): array
    {
        $previousPeriod = $this->getPreviousPeriodDates();
        
        // Create new instance with previous period dates
        $previousReport = new self();
        $previousReport->setDateRange($previousPeriod['from'], $previousPeriod['to']);
        $previousReport->setFilters($this->filters ?? []);
        
        $previousSummary = $previousReport->getSummary();
        $currentSummary = $this->getSummary();
        
        $comparison = $this->calculatePercentageChange(
            $currentSummary['total_revenue'],
            $previousSummary['total_revenue']
        );

        return [
            'current_period' => $currentSummary['total_revenue'],
            'current_period_formatted' => $currentSummary['total_revenue_formatted'],
            'previous_period' => $previousSummary['total_revenue'],
            'previous_period_formatted' => $this->formatCurrency($previousSummary['total_revenue']),
            'change_amount' => $comparison['amount'],
            'change_amount_formatted' => $this->formatCurrency(abs($comparison['amount'])),
            'change_percentage' => $comparison['percentage'],
            'change_percentage_formatted' => $this->formatPercentage(abs($comparison['percentage'])),
            'direction' => $comparison['direction'],
            'period_from' => $previousPeriod['from']->format('Y-m-d'),
            'period_to' => $previousPeriod['to']->format('Y-m-d'),
        ];
    }

    /**
     * Get period label
     */
    protected function getPeriodLabel(): string
    {
        if ($this->dateFrom->isSameDay($this->dateTo)) {
            return $this->dateFrom->format('F d, Y');
        }

        if ($this->dateFrom->isSameMonth($this->dateTo)) {
            return $this->dateFrom->format('F Y');
        }

        return $this->dateFrom->format('M d, Y') . ' - ' . $this->dateTo->format('M d, Y');
    }
}