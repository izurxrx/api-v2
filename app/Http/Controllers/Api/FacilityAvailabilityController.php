<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FacilityAvailabilityService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class FacilityAvailabilityController extends Controller
{
    protected $availabilityService;

    public function __construct(FacilityAvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

    /**
     * Check facility availability for a specific time period
     * 
     * GET /api/facilities/{facilityId}/availability
     * 
     * Query params:
     * - start_datetime: YYYY-MM-DD HH:MM:SS
     * - end_datetime: YYYY-MM-DD HH:MM:SS
     * - quantity: Number of units requested (default: 1)
     * - exclude_booking_id: (optional) Booking ID to exclude
     * - exclude_guest_entry_id: (optional) Guest Entry ID to exclude
     */
    public function checkAvailability(Request $request, $facilityId)
    {
        $request->validate([
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'quantity' => 'nullable|integer|min:1',
            'exclude_booking_id' => 'nullable|integer',
            'exclude_guest_entry_id' => 'nullable|integer',
        ]);

        $startDateTime = Carbon::parse($request->start_datetime);
        $endDateTime = Carbon::parse($request->end_datetime);
        $requestedQuantity = $request->quantity ?? 1;
        $excludeBookingId = $request->exclude_booking_id;
        $excludeGuestEntryId = $request->exclude_guest_entry_id;

        // Get available quantity
        $available = $this->availabilityService->getAvailableQuantity(
            $facilityId,
            $startDateTime,
            $endDateTime,
            $excludeBookingId,
            $excludeGuestEntryId
        );

        // Check if requested quantity is available
        $isAvailable = $available >= $requestedQuantity;

        return response()->json([
            'status' => 'success',
            'data' => [
                'facility_id' => (int) $facilityId,
                'start_datetime' => $startDateTime->toIso8601String(),
                'end_datetime' => $endDateTime->toIso8601String(),
                'requested_quantity' => $requestedQuantity,
                'available_quantity' => $available,
                'is_available' => $isAvailable,
                'message' => $isAvailable 
                    ? "Available: {$available} unit(s)" 
                    : "Only {$available} unit(s) available. You requested {$requestedQuantity}.",
            ],
        ]);
    }

    /**
     * Check availability for multiple facilities at once
     * 
     * POST /api/facilities/check-availability
     * 
     * Body:
     * {
     *   "start_datetime": "2025-10-21 14:00:00",
     *   "end_datetime": "2025-10-21 18:00:00",
     *   "facilities": [
     *     {"facility_id": 1, "quantity": 2},
     *     {"facility_id": 2, "quantity": 1}
     *   ]
     * }
     */
    public function checkMultipleFacilities(Request $request)
    {
        $request->validate([
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'facilities' => 'required|array|min:1',
            'facilities.*.facility_id' => 'required|integer|exists:facilities,id',
            'facilities.*.quantity' => 'required|integer|min:1',
            'exclude_booking_id' => 'nullable|integer',
            'exclude_guest_entry_id' => 'nullable|integer',
        ]);

        $startDateTime = Carbon::parse($request->start_datetime);
        $endDateTime = Carbon::parse($request->end_datetime);
        $excludeBookingId = $request->exclude_booking_id;
        $excludeGuestEntryId = $request->exclude_guest_entry_id;

        $results = [];
        $allAvailable = true;

        foreach ($request->facilities as $facility) {
            $facilityId = $facility['facility_id'];
            $requestedQuantity = $facility['quantity'];

            $available = $this->availabilityService->getAvailableQuantity(
                $facilityId,
                $startDateTime,
                $endDateTime,
                $excludeBookingId,
                $excludeGuestEntryId
            );

            $isAvailable = $available >= $requestedQuantity;

            if (!$isAvailable) {
                $allAvailable = false;
            }

            $results[] = [
                'facility_id' => $facilityId,
                'requested_quantity' => $requestedQuantity,
                'available_quantity' => $available,
                'is_available' => $isAvailable,
                'message' => $isAvailable 
                    ? "Available: {$available} unit(s)" 
                    : "Only {$available} unit(s) available. You requested {$requestedQuantity}.",
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'start_datetime' => $startDateTime->toIso8601String(),
                'end_datetime' => $endDateTime->toIso8601String(),
                'all_available' => $allAvailable,
                'facilities' => $results,
            ],
        ]);
    }

    /**
     * Get conflicts for a specific facility and time period
     * 
     * GET /api/facilities/{facilityId}/conflicts
     */
    public function getConflicts(Request $request, $facilityId)
    {
        $request->validate([
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
        ]);

        $startDateTime = Carbon::parse($request->start_datetime);
        $endDateTime = Carbon::parse($request->end_datetime);

        $conflicts = $this->availabilityService->getConflicts(
            $facilityId,
            $startDateTime,
            $endDateTime
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'facility_id' => (int) $facilityId,
                'start_datetime' => $startDateTime->toIso8601String(),
                'end_datetime' => $endDateTime->toIso8601String(),
                'conflicts' => [
                    'bookings' => $conflicts['bookings']->map(function($booking) {
                        return [
                            'id' => $booking->id,
                            'booking_reference' => $booking->booking_reference,
                            'guest_name' => $booking->guest_name,
                            'check_in_datetime' => $booking->check_in_datetime,
                            'check_out_datetime' => $booking->check_out_datetime,
                            'status' => $booking->booking_status,
                        ];
                    }),
                    'guest_entries' => $conflicts['rentals']->map(function($entry) {
                        return [
                            'id' => $entry->id,
                            'entry_reference' => $entry->entry_reference,
                            'guest_name' => $entry->guest_name,
                            'check_in_datetime' => $entry->check_in_datetime,
                            'status' => $entry->entry_status,
                        ];
                    }),
                ],
            ],
        ]);
    }
}