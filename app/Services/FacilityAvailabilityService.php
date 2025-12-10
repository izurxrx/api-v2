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
    ): int {
        $facility = Facility::find($facilityId);
        
        if (!$facility) {
            Log::warning("❌ Facility {$facilityId} not found");
            return 0;
        }

        if ($facility->trashed()) {
            Log::info("⚠️ Facility {$facilityId} is deleted/inactive");
            return 0;
        }

        // Check if facility has any units
        if ($facility->quantity <= 0) {
            Log::info("⚠️ Facility {$facilityId} has no units available");
            return 0;
        }

        $start = Carbon::parse($startDateTime);
        $end = Carbon::parse($endDateTime);

        // Validate time range
        if ($end->lte($start)) {
            Log::warning("❌ Invalid time range: end time must be after start time");
            return 0;
        } 

        $totalQuantity = $facility->quantity;

        Log::info("🔍 Checking facility {$facilityId} ({$facility->name})", [
            'total_quantity' => $totalQuantity,
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
        ]);

        // ✅ Count occupied by Bookings - ONLY Confirmed and Checked_In bookings reduce availability
        // Pending bookings do NOT reserve facilities until confirmed
        $occupiedByBookings = BookingFacility::where('facility_id', $facilityId)
            ->whereHas('booking', function($q) use ($start, $end, $excludeBookingId) {
                $q->where(function($query) use ($start, $end) {
                    // ✅ Proper overlap: booking starts before requested end AND ends after requested start
                    $query->where('check_in_datetime', '<', $end)
                          ->where('check_out_datetime', '>', $start);
                })
                ->whereIn('booking_status', ['Confirmed', 'Checked_In']);

                // Exclude specific booking (for updates)
                if ($excludeBookingId) {
                    $q->where('id', '!=', $excludeBookingId);
                }
            })
            ->sum('quantity');

        Log::info("📊 Bookings occupying facility {$facilityId}: {$occupiedByBookings}");

        // ✅ FIXED: Count occupied by Guest Entries (walk-ins)
        // Walk-ins are assumed to occupy the facility for their entire entry date until checkout
        $occupiedByGuestEntries = GuestEntryFacility::where('facility_id', $facilityId)
            ->whereHas('guestEntry', function($q) use ($start, $end, $excludeGuestEntryId) {
                // Guest entry occupies facility if:
                // 1. They're NOT checked out yet
                // 2. Their entry date overlaps with the requested period
                $q->where('is_checked_out', 0)
                  ->where(function($query) use ($start, $end) {
                      // Check if entry date is within the requested period
                      $query->where('check_in_datetime', '<', $end)
                            ->whereRaw('DATE(entry_date) >= ?', [$start->toDateString()]);
                  });
                
                // Exclude specific guest entry (for updates)
                if ($excludeGuestEntryId) {
                    $q->where('id', '!=', $excludeGuestEntryId);
                }
            })
            ->sum('quantity');

        Log::info("📊 Guest entries occupying facility {$facilityId}: {$occupiedByGuestEntries}");

        // Calculate available
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
    ): bool {
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
     * ✅ Get all available facilities for a date range
     */
    public function getAvailableFacilities(
        $startDateTime, 
        $endDateTime, 
        $facilityTypeId = null
    ): array {
        $query = Facility::whereNull('deleted_at')  // Not soft-deleted
           ->where('quantity', '>', 0);

        if ($facilityTypeId) {
            $query->where('facility_type_id', $facilityTypeId);
        }

        $facilities = $query->with('facilityType')->get();
        $availableFacilities = [];

        foreach ($facilities as $facility) {
            $availableQuantity = $this->getAvailableQuantity(
                $facility->id,
                $startDateTime,
                $endDateTime
            );

            if ($availableQuantity > 0) {
                $availableFacilities[] = [
                    'facility' => $facility,
                    'available_quantity' => $availableQuantity,
                    'total_quantity' => $facility->quantity,
                    'occupied_quantity' => $facility->quantity - $availableQuantity,
                ];
            }
        }

        return $availableFacilities;
    }

    /**
     * ✅ FIXED: Get conflicts for a facility
     */
    public function getConflicts($facilityId, $startDateTime, $endDateTime): array
    {
        $start = Carbon::parse($startDateTime);
        $end = Carbon::parse($endDateTime);

        // ✅ Get conflicts - ONLY Confirmed and Checked_In bookings are considered conflicts
        // Pending bookings are not shown as conflicts since they don't block availability
        $bookings = Booking::whereHas('facilities', function($q) use ($facilityId) {
            $q->where('facility_id', $facilityId);
        })
        ->where(function($q) use ($start, $end) {
            // Booking overlaps if it starts before requested end AND ends after requested start
            $q->where('check_in_datetime', '<', $end)
              ->where('check_out_datetime', '>', $start);
        })
        ->whereIn('booking_status', ['Confirmed', 'Checked_In'])
        ->with(['facilities' => function($q) use ($facilityId) {
            $q->where('facility_id', $facilityId);
        }])
        ->get();

        // ✅ FIXED: Get guest entries that overlap
        $guestEntries = GuestEntry::whereHas('facilities', function($q) use ($facilityId) {
            $q->where('facility_id', $facilityId);
        })
        ->where('is_checked_out', 0)
        ->where('check_in_datetime', '<', $end)
        ->whereRaw('DATE(entry_date) >= ?', [$start->toDateString()])
        ->with(['facilities' => function($q) use ($facilityId) {
            $q->where('facility_id', $facilityId);
        }])
        ->get();

        return [
            'bookings' => $bookings->map(function($booking) use ($facilityId) {
                $facilityBooking = $booking->facilities->first();
                return [
                    'id' => $booking->id,
                    'reference' => $booking->booking_reference,
                    'guest_name' => $booking->guest_name,
                    'check_in' => $booking->check_in_datetime->toDateTimeString(),
                    'check_out' => $booking->check_out_datetime->toDateTimeString(),
                    'quantity' => $facilityBooking->quantity ?? 0,
                    'status' => $booking->booking_status,
                ];
            }),
            'guest_entries' => $guestEntries->map(function($entry) use ($facilityId) {
                $facilityEntry = $entry->facilities->first();
                return [
                    'id' => $entry->id,
                    'reference' => $entry->entry_reference,
                    'guest_name' => $entry->guest_name,
                    'check_in' => $entry->check_in_datetime->toDateTimeString(),
                    'entry_date' => $entry->entry_date->toDateString(),
                    'quantity' => $facilityEntry->quantity ?? 0,
                    'is_checked_out' => $entry->is_checked_out,
                ];
            }),
        ];
    }

    /**
     * ✅ Check availability for multiple facilities at once
     */
    public function checkMultipleFacilities(
        array $facilities, 
        $excludeBookingId = null
    ): array {
        $conflicts = [];
        $allAvailable = true;

        foreach ($facilities as $facilityData) {
            $available = $this->getAvailableQuantity(
                $facilityData['facility_id'],
                $facilityData['start'],
                $facilityData['end'],
                $excludeBookingId
            );

            $requested = $facilityData['quantity'];

            if ($available < $requested) {
                $allAvailable = false;
                $facility = Facility::find($facilityData['facility_id']);
                
                $conflicts[] = [
                    'facility_id' => $facilityData['facility_id'],
                    'facility_name' => $facility->name ?? 'Unknown',
                    'requested' => $requested,
                    'available' => $available,
                    'shortage' => $requested - $available,
                ];
            }
        }

        return [
            'available' => $allAvailable,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * ✅ Get availability calendar for a facility
     */
    public function getAvailabilityCalendar($facilityId, $startDate, $endDate): array
    {
        $facility = Facility::find($facilityId);
        
        if (!$facility) {
            return [];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        
        $calendar = [];
        $currentDate = $start->copy();

        while ($currentDate <= $end) {
            $dayStart = $currentDate->copy()->startOfDay();
            $dayEnd = $currentDate->copy()->endOfDay();

            $available = $this->getAvailableQuantity(
                $facilityId,
                $dayStart,
                $dayEnd
            );

            $calendar[] = [
                'date' => $currentDate->toDateString(),
                'day_name' => $currentDate->format('l'),
                'total_quantity' => $facility->quantity,
                'available' => $available,
                'occupied' => $facility->quantity - $available,
                'is_fully_booked' => $available === 0,
            ];

            $currentDate->addDay();
        }

        return $calendar;
    }
}