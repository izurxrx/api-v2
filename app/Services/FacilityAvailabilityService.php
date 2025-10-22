<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\Booking;
use App\Models\BookingFacility;
use App\Models\GuestEntry;
use App\Models\GuestEntryFacility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FacilityAvailabilityService
{
    /**
     * ✅ Get available quantity for a facility during a time period
     *
     * @param int $facilityId
     * @param string|Carbon $startDateTime
     * @param string|Carbon $endDateTime
     * @param int|null $excludeBookingId
     * @param int|null $excludeGuestEntryId
     * @return int Available quantity
     */
    public function getAvailableQuantity(
        $facilityId, 
        $startDateTime, 
        $endDateTime, 
        $excludeBookingId = null, 
        $excludeGuestEntryId = null
    ) {
        $facility = Facility::find($facilityId);
        
        if (!$facility) {
            Log::warning("❌ Facility {$facilityId} not found");
            return 0;
        }

        // ✅ REMOVED: status check (column doesn't exist)
        // Just check is_maintenance instead
        if ($facility->is_maintenance) {
            Log::info("⚠️ Facility {$facilityId} is under maintenance");
            return 0;
        }

        $start = Carbon::parse($startDateTime);
        $end = Carbon::parse($endDateTime);

        // ✅ Total quantity available
        $totalQuantity = $facility->quantity;

        Log::info("🔍 Checking facility {$facilityId} ({$facility->name})", [
            'total_quantity' => $totalQuantity,
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
        ]);

        // ✅ Count occupied by Bookings
        $occupiedByBookings = BookingFacility::where('facility_id', $facilityId)
            ->whereHas('booking', function($q) use ($start, $end, $excludeBookingId) {
                $q->where(function($query) use ($start, $end) {
                    // Overlapping time periods
                    $query->whereBetween('check_in_datetime', [$start, $end])
                          ->orWhereBetween('check_out_datetime', [$start, $end])
                          ->orWhere(function($q2) use ($start, $end) {
                              $q2->where('check_in_datetime', '<=', $start)
                                 ->where('check_out_datetime', '>=', $end);
                          });
                })
                ->whereIn('booking_status', ['Pending', 'Confirmed', 'Checked_In']);

                // Exclude specific booking (for updates)
                if ($excludeBookingId) {
                    $q->where('id', '!=', $excludeBookingId);
                }
            })
            ->sum('quantity');

        Log::info("📊 Bookings occupying facility {$facilityId}: {$occupiedByBookings}");

        // ✅ FIXED: Count occupied by Guest Entries (walk-ins currently checked in)
        $occupiedByGuestEntries = GuestEntryFacility::where('facility_id', $facilityId)
            ->whereHas('guestEntry', function($q) use ($start, $end, $excludeGuestEntryId) {
                // ✅ CRITICAL FIX: Use is_checked_out instead of entry_status
                $q->where('is_checked_out', 0)  // ✅ NOT checked out yet
                  ->where('check_in_datetime', '<=', $end);  // Checked in before end time

                // Exclude specific guest entry (for updates)
                if ($excludeGuestEntryId) {
                    $q->where('id', '!=', $excludeGuestEntryId);
                }
            })
            ->sum('quantity');

        Log::info("📊 Guest entries occupying facility {$facilityId}: {$occupiedByGuestEntries}");

        // ✅ Calculate available
        $occupied = $occupiedByBookings + $occupiedByGuestEntries;
        $available = $totalQuantity - $occupied;

        Log::info("✅ Facility {$facilityId} availability", [
            'total' => $totalQuantity,
            'occupied_bookings' => $occupiedByBookings,
            'occupied_guest_entries' => $occupiedByGuestEntries,
            'total_occupied' => $occupied,
            'available' => $available,
        ]);

        return max(0, $available);
    }

    /**
     * ✅ Check if requested quantity is available
     *
     * @param int $facilityId
     * @param string|Carbon $startDateTime
     * @param string|Carbon $endDateTime
     * @param int $requestedQuantity
     * @param int|null $excludeBookingId
     * @param int|null $excludeGuestEntryId
     * @return bool
     */
    public function isAvailable(
        $facilityId, 
        $startDateTime, 
        $endDateTime, 
        $requestedQuantity = 1,
        $excludeBookingId = null, 
        $excludeGuestEntryId = null
    ) {
        $available = $this->getAvailableQuantity(
            $facilityId, 
            $startDateTime, 
            $endDateTime, 
            $excludeBookingId, 
            $excludeGuestEntryId
        );

        return $available >= $requestedQuantity;
    }

    /**
     * ✅ Get conflicts for a facility
     */
    public function getConflicts($facilityId, $startDateTime, $endDateTime)
    {
        $start = Carbon::parse($startDateTime);
        $end = Carbon::parse($endDateTime);

        $bookings = Booking::whereHas('facilities', function($q) use ($facilityId) {
            $q->where('facility_id', $facilityId);
        })
        ->where(function($q) use ($start, $end) {
            $q->whereBetween('check_in_datetime', [$start, $end])
              ->orWhereBetween('check_out_datetime', [$start, $end])
              ->orWhere(function($q2) use ($start, $end) {
                  $q2->where('check_in_datetime', '<=', $start)
                     ->where('check_out_datetime', '>=', $end);
              });
        })
        ->whereIn('booking_status', ['Pending', 'Confirmed', 'Checked_In'])
        ->get();

        // ✅ FIXED: Use is_checked_out instead of entry_status
        $guestEntries = GuestEntry::whereHas('facilities', function($q) use ($facilityId) {
            $q->where('facility_id', $facilityId);
        })
        ->where('is_checked_out', 0)  // ✅ NOT checked out yet
        ->get();

        return [
            'bookings' => $bookings,
            'rentals' => $guestEntries,
        ];
    }
}