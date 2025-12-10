<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BookingOverlapService
{
    /**
     * Check for bookings that are too close to the requested time
     *
     * @param Carbon $checkInDateTime
     * @param Carbon $checkOutDateTime
     * @param int|null $excludeBookingId
     * @param int $proximityHours Hours to check before/after (default: 2)
     * @return array
     */
    public function checkProximity(
        Carbon $checkInDateTime,
        Carbon $checkOutDateTime,
        ?int $excludeBookingId = null,
        int $proximityHours = 2
    ): array {
        // Define the proximity window
        $proximityWindowStart = $checkInDateTime->copy()->subHours($proximityHours);
        $proximityWindowEnd = $checkOutDateTime->copy()->addHours($proximityHours);

        Log::info('🔍 Checking booking proximity', [
            'check_in' => $checkInDateTime->toDateTimeString(),
            'check_out' => $checkOutDateTime->toDateTimeString(),
            'proximity_hours' => $proximityHours,
            'window_start' => $proximityWindowStart->toDateTimeString(),
            'window_end' => $proximityWindowEnd->toDateTimeString(),
        ]);

        // Find bookings that end within proximity window of check-in
        $bookingsEndingNearCheckIn = Booking::where(function($q) use ($checkInDateTime, $proximityHours) {
                // Bookings that end within X hours before the new check-in
                $q->whereBetween('check_out_datetime', [
                    $checkInDateTime->copy()->subHours($proximityHours),
                    $checkInDateTime
                ]);
            })
            ->whereIn('booking_status', ['Pending', 'Confirmed', 'Checked_In'])
            ->when($excludeBookingId, function($q) use ($excludeBookingId) {
                $q->where('id', '!=', $excludeBookingId);
            })
            ->get();

        // Find bookings that start within proximity window of check-out
        $bookingsStartingNearCheckOut = Booking::where(function($q) use ($checkOutDateTime, $proximityHours) {
                // Bookings that start within X hours after the new check-out
                $q->whereBetween('check_in_datetime', [
                    $checkOutDateTime,
                    $checkOutDateTime->copy()->addHours($proximityHours)
                ]);
            })
            ->whereIn('booking_status', ['Pending', 'Confirmed', 'Checked_In'])
            ->when($excludeBookingId, function($q) use ($excludeBookingId) {
                $q->where('id', '!=', $excludeBookingId);
            })
            ->get();

        // Find direct overlaps (bookings that conflict with the requested period)
        $directOverlaps = Booking::where(function($q) use ($checkInDateTime, $checkOutDateTime) {
                $q->where('check_in_datetime', '<', $checkOutDateTime)
                  ->where('check_out_datetime', '>', $checkInDateTime);
            })
            ->whereIn('booking_status', ['Pending', 'Confirmed', 'Checked_In'])
            ->when($excludeBookingId, function($q) use ($excludeBookingId) {
                $q->where('id', '!=', $excludeBookingId);
            })
            ->get();

        $conflicts = [];

        // Process bookings ending near check-in
        foreach ($bookingsEndingNearCheckIn as $booking) {
            $gapMinutes = $booking->check_out_datetime->diffInMinutes($checkInDateTime);
            $gapHours = round($gapMinutes / 60, 1);

            $conflicts[] = [
                'type' => 'proximity_before',
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'guest_name' => $booking->guest_name,
                'booking_ends_at' => $booking->check_out_datetime->toIso8601String(),
                'new_booking_starts_at' => $checkInDateTime->toIso8601String(),
                'gap_minutes' => $gapMinutes,
                'gap_hours' => $gapHours,
                'severity' => $this->calculateSeverity($gapMinutes, $proximityHours),
                'message' => sprintf(
                    'Booking %s (Guest: %s) ends at %s, only %.1f hours before the new booking starts',
                    $booking->booking_reference,
                    $booking->guest_name,
                    $booking->check_out_datetime->format('M d, Y h:i A'),
                    $gapHours
                ),
            ];
        }

        // Process bookings starting near check-out
        foreach ($bookingsStartingNearCheckOut as $booking) {
            $gapMinutes = $checkOutDateTime->diffInMinutes($booking->check_in_datetime);
            $gapHours = round($gapMinutes / 60, 1);

            $conflicts[] = [
                'type' => 'proximity_after',
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'guest_name' => $booking->guest_name,
                'new_booking_ends_at' => $checkOutDateTime->toIso8601String(),
                'booking_starts_at' => $booking->check_in_datetime->toIso8601String(),
                'gap_minutes' => $gapMinutes,
                'gap_hours' => $gapHours,
                'severity' => $this->calculateSeverity($gapMinutes, $proximityHours),
                'message' => sprintf(
                    'Booking %s (Guest: %s) starts at %s, only %.1f hours after the new booking ends',
                    $booking->booking_reference,
                    $booking->guest_name,
                    $booking->check_in_datetime->format('M d, Y h:i A'),
                    $gapHours
                ),
            ];
        }

        // Process direct overlaps
        foreach ($directOverlaps as $booking) {
            $conflicts[] = [
                'type' => 'direct_overlap',
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'guest_name' => $booking->guest_name,
                'booking_starts_at' => $booking->check_in_datetime->toIso8601String(),
                'booking_ends_at' => $booking->check_out_datetime->toIso8601String(),
                'new_booking_starts_at' => $checkInDateTime->toIso8601String(),
                'new_booking_ends_at' => $checkOutDateTime->toIso8601String(),
                'severity' => 'critical',
                'message' => sprintf(
                    'Direct overlap with Booking %s (Guest: %s) from %s to %s',
                    $booking->booking_reference,
                    $booking->guest_name,
                    $booking->check_in_datetime->format('M d, Y h:i A'),
                    $booking->check_out_datetime->format('M d, Y h:i A')
                ),
            ];
        }

        Log::info('✅ Proximity check completed', [
            'conflicts_found' => count($conflicts),
            'before_check_in' => $bookingsEndingNearCheckIn->count(),
            'after_check_out' => $bookingsStartingNearCheckOut->count(),
            'direct_overlaps' => $directOverlaps->count(),
        ]);

        return [
            'has_conflicts' => count($conflicts) > 0,
            'conflict_count' => count($conflicts),
            'conflicts' => $conflicts,
            'proximity_hours' => $proximityHours,
        ];
    }

    /**
     * Calculate severity level based on gap time
     *
     * @param int $gapMinutes
     * @param int $proximityHours
     * @return string
     */
    private function calculateSeverity(int $gapMinutes, int $proximityHours): string
    {
        $gapHours = $gapMinutes / 60;

        if ($gapHours < 1) {
            return 'critical'; // Less than 1 hour
        } elseif ($gapHours < 1.5) {
            return 'high'; // 1-1.5 hours
        } elseif ($gapHours < 2) {
            return 'medium'; // 1.5-2 hours
        } else {
            return 'low'; // 2+ hours
        }
    }

    /**
     * Check if a specific set of facilities have overlapping bookings
     *
     * @param array $facilities Array of facility IDs
     * @param Carbon $checkInDateTime
     * @param Carbon $checkOutDateTime
     * @param int|null $excludeBookingId
     * @return array
     */
    public function checkFacilityOverlaps(
        array $facilities,
        Carbon $checkInDateTime,
        Carbon $checkOutDateTime,
        ?int $excludeBookingId = null
    ): array {
        $overlappingBookings = Booking::where(function($q) use ($checkInDateTime, $checkOutDateTime) {
                $q->where('check_in_datetime', '<', $checkOutDateTime)
                  ->where('check_out_datetime', '>', $checkInDateTime);
            })
            ->whereIn('booking_status', ['Pending', 'Confirmed', 'Checked_In'])
            ->whereHas('facilities', function($q) use ($facilities) {
                $q->whereIn('facility_id', $facilities);
            })
            ->when($excludeBookingId, function($q) use ($excludeBookingId) {
                $q->where('id', '!=', $excludeBookingId);
            })
            ->with('facilities')
            ->get();

        $conflicts = [];
        foreach ($overlappingBookings as $booking) {
            $overlappingFacilities = $booking->facilities
                ->whereIn('facility_id', $facilities)
                ->pluck('facility.name', 'facility_id')
                ->toArray();

            $conflicts[] = [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'guest_name' => $booking->guest_name,
                'overlapping_facilities' => $overlappingFacilities,
                'check_in' => $booking->check_in_datetime->toIso8601String(),
                'check_out' => $booking->check_out_datetime->toIso8601String(),
            ];
        }

        return [
            'has_overlaps' => count($conflicts) > 0,
            'conflicts' => $conflicts,
        ];
    }
}
