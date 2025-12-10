<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingFacility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BookingConflictResolutionService
{
    protected $availabilityService;

    public function __construct(FacilityAvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

    /**
     * Cancel pending bookings that conflict with a confirmed booking
     *
     * This runs when a booking is confirmed and automatically cancels
     * other pending bookings that would exceed facility capacity.
     *
     * @param Booking $confirmedBooking The booking that was just confirmed
     * @return array Summary of cancelled bookings
     */
    public function cancelConflictingPendingBookings(Booking $confirmedBooking): array
    {
        // Only process if booking is confirmed or checked in
        if (!in_array($confirmedBooking->booking_status, ['Confirmed', 'Checked_In'])) {
            return [
                'cancelled_count' => 0,
                'cancelled_bookings' => [],
                'message' => 'Booking is not confirmed, no action taken',
            ];
        }

        $cancelledBookings = [];
        $cancelledCount = 0;

        // Get facilities for the confirmed booking
        $confirmedFacilities = $confirmedBooking->facilities;

        foreach ($confirmedFacilities as $bookingFacility) {
            $facilityId = $bookingFacility->facility_id;
            $facility = $bookingFacility->facility;

            Log::info("🔍 Checking for conflicting pending bookings", [
                'confirmed_booking_id' => $confirmedBooking->id,
                'confirmed_booking_reference' => $confirmedBooking->booking_reference,
                'facility_id' => $facilityId,
                'facility_name' => $facility->name ?? 'Unknown',
                'check_in' => $confirmedBooking->check_in_datetime,
                'check_out' => $confirmedBooking->check_out_datetime,
            ]);

            // Find pending bookings that overlap with this confirmed booking
            $pendingBookings = Booking::where('booking_status', 'Pending')
                ->where('id', '!=', $confirmedBooking->id) // Exclude current booking
                ->where(function($q) use ($confirmedBooking) {
                    // Booking overlaps if it starts before confirmed end AND ends after confirmed start
                    $q->where('check_in_datetime', '<', $confirmedBooking->check_out_datetime)
                      ->where('check_out_datetime', '>', $confirmedBooking->check_in_datetime);
                })
                ->whereHas('facilities', function($q) use ($facilityId) {
                    $q->where('facility_id', $facilityId);
                })
                ->with(['facilities' => function($q) use ($facilityId) {
                    $q->where('facility_id', $facilityId);
                }])
                ->get();

            Log::info("📋 Found {$pendingBookings->count()} pending bookings with overlapping time", [
                'facility_id' => $facilityId,
            ]);

            // Check each pending booking to see if it would now exceed capacity
            foreach ($pendingBookings as $pendingBooking) {
                $pendingFacility = $pendingBooking->facilities->first();
                $pendingQuantity = $pendingFacility->quantity ?? 0;

                // Check if this pending booking would exceed available capacity
                // We check availability EXCLUDING the pending booking itself
                $availableQuantity = $this->availabilityService->getAvailableQuantity(
                    $facilityId,
                    $pendingBooking->check_in_datetime,
                    $pendingBooking->check_out_datetime,
                    $pendingBooking->id // Exclude the pending booking to see what's available
                );

                Log::info("🔢 Availability check for pending booking", [
                    'pending_booking_id' => $pendingBooking->id,
                    'pending_booking_reference' => $pendingBooking->booking_reference,
                    'requested_quantity' => $pendingQuantity,
                    'available_quantity' => $availableQuantity,
                    'would_exceed' => $pendingQuantity > $availableQuantity,
                ]);

                // If the pending booking's requested quantity exceeds what's now available, cancel it
                if ($pendingQuantity > $availableQuantity) {
                    $this->cancelPendingBooking(
                        $pendingBooking,
                        $confirmedBooking,
                        $facility->name ?? "Facility #{$facilityId}",
                        $availableQuantity,
                        $pendingQuantity
                    );

                    $cancelledBookings[] = [
                        'id' => $pendingBooking->id,
                        'reference' => $pendingBooking->booking_reference,
                        'guest_name' => $pendingBooking->guest_name,
                        'facility' => $facility->name ?? 'Unknown',
                        'requested_quantity' => $pendingQuantity,
                        'available_quantity' => $availableQuantity,
                    ];

                    $cancelledCount++;
                }
            }
        }

        Log::info("✅ Conflict resolution completed", [
            'confirmed_booking_id' => $confirmedBooking->id,
            'confirmed_booking_reference' => $confirmedBooking->booking_reference,
            'cancelled_count' => $cancelledCount,
        ]);

        return [
            'cancelled_count' => $cancelledCount,
            'cancelled_bookings' => $cancelledBookings,
            'message' => $cancelledCount > 0
                ? "{$cancelledCount} conflicting pending booking(s) auto-cancelled"
                : 'No conflicting pending bookings found',
        ];
    }

    /**
     * Cancel a pending booking due to conflict
     *
     * @param Booking $pendingBooking The booking to cancel
     * @param Booking $confirmedBooking The booking that caused the conflict
     * @param string $facilityName Name of the facility
     * @param int $available Available quantity
     * @param int $requested Requested quantity
     */
    protected function cancelPendingBooking(
        Booking $pendingBooking,
        Booking $confirmedBooking,
        string $facilityName,
        int $available,
        int $requested
    ): void {
        $cancellationReason = sprintf(
            "Auto-cancelled: Facility '%s' confirmed by another booking (%s). " .
            "Requested quantity (%d) exceeds available quantity (%d).",
            $facilityName,
            $confirmedBooking->booking_reference,
            $requested,
            $available
        );

        // Update booking status
        $pendingBooking->update([
            'booking_status' => 'Cancelled',
            'cancellation_reason' => $cancellationReason,
            'notes' => sprintf(
                '%s | %s',
                $pendingBooking->notes ?? '',
                $cancellationReason
            ),
        ]);

        // Update billing status if exists
        $billing = $pendingBooking->billing;
        if ($billing && $billing->payment_status !== 'refunded') {
            $billing->update([
                'payment_status' => 'cancelled',
            ]);
        }

        Log::warning("❌ Auto-cancelled pending booking due to conflict", [
            'cancelled_booking_id' => $pendingBooking->id,
            'cancelled_booking_reference' => $pendingBooking->booking_reference,
            'confirmed_by_booking_id' => $confirmedBooking->id,
            'confirmed_by_booking_reference' => $confirmedBooking->booking_reference,
            'facility' => $facilityName,
            'reason' => $cancellationReason,
        ]);

        // Log with Spatie Activity Log if available
        if (function_exists('activity')) {
            activity()
                ->performedOn($pendingBooking)
                ->causedBy($confirmedBooking->user ?? null)
                ->withProperties([
                    'cancelled_booking_reference' => $pendingBooking->booking_reference,
                    'confirmed_booking_reference' => $confirmedBooking->booking_reference,
                    'facility' => $facilityName,
                    'reason' => $cancellationReason,
                    'auto_cancelled' => true,
                ])
                ->log('booking_auto_cancelled_due_to_conflict');
        }
    }

    /**
     * Get pending bookings that would conflict with a potential confirmation
     *
     * This is useful for previewing which bookings would be cancelled
     * before actually confirming a booking.
     *
     * @param Booking $booking The booking to check
     * @return array List of pending bookings that would be cancelled
     */
    public function getConflictingPendingBookings(Booking $booking): array
    {
        $conflictingBookings = [];

        $facilities = $booking->facilities;

        foreach ($facilities as $bookingFacility) {
            $facilityId = $bookingFacility->facility_id;

            // Find pending bookings that overlap
            $pendingBookings = Booking::where('booking_status', 'Pending')
                ->where('id', '!=', $booking->id)
                ->where(function($q) use ($booking) {
                    $q->where('check_in_datetime', '<', $booking->check_out_datetime)
                      ->where('check_out_datetime', '>', $booking->check_in_datetime);
                })
                ->whereHas('facilities', function($q) use ($facilityId) {
                    $q->where('facility_id', $facilityId);
                })
                ->with(['facilities' => function($q) use ($facilityId) {
                    $q->where('facility_id', $facilityId);
                }])
                ->get();

            foreach ($pendingBookings as $pendingBooking) {
                $pendingFacility = $pendingBooking->facilities->first();
                $pendingQuantity = $pendingFacility->quantity ?? 0;

                // Check if confirming would cause this pending booking to exceed capacity
                $availableIfConfirmed = $this->availabilityService->getAvailableQuantity(
                    $facilityId,
                    $pendingBooking->check_in_datetime,
                    $pendingBooking->check_out_datetime,
                    $pendingBooking->id
                );

                if ($pendingQuantity > $availableIfConfirmed) {
                    $conflictingBookings[] = [
                        'id' => $pendingBooking->id,
                        'reference' => $pendingBooking->booking_reference,
                        'guest_name' => $pendingBooking->guest_name,
                        'check_in' => $pendingBooking->check_in_datetime->toDateTimeString(),
                        'check_out' => $pendingBooking->check_out_datetime->toDateTimeString(),
                        'facility_id' => $facilityId,
                        'facility_name' => $bookingFacility->facility->name ?? 'Unknown',
                        'requested_quantity' => $pendingQuantity,
                        'available_if_confirmed' => $availableIfConfirmed,
                    ];
                }
            }
        }

        return $conflictingBookings;
    }
}
