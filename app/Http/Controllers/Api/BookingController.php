<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Http\Resources\BookingResource;
use App\Http\Resources\BookingCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class BookingController extends Controller
{
    public function archived(Request $request)
    {
        $query = Booking::onlyTrashed()->with(['facility', 'creator', 'services']);

        // Filter by facility
        if ($request->filled('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        // Filter by booking status
        if ($request->filled('booking_status')) {
            $query->where('booking_status', $request->booking_status);
        }

        // Filter by payment status
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('check_in_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('check_out_date', '<=', $request->end_date);
        }

        // Scopes
        if ($request->boolean('pending_only')) {
            $query->pending();
        }

        if ($request->boolean('confirmed_only')) {
            $query->confirmed();
        }

        if ($request->boolean('unpaid_only')) {
            $query->unpaid();
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $bookings = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            new BookingCollection($bookings),
            'Archived bookings retrieved successfully'
        );
    }
    public function index(Request $request)
    {
        $query = Booking::with(['facility', 'creator', 'services']);

        // Filter by facility
        if ($request->filled('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        // Filter by booking status
        if ($request->filled('booking_status')) {
            $query->where('booking_status', $request->booking_status);
        }

        // Filter by payment status
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('check_in_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('check_out_date', '<=', $request->end_date);
        }

        // Scopes
        if ($request->boolean('pending_only')) {
            $query->pending();
        }

        if ($request->boolean('confirmed_only')) {
            $query->confirmed();
        }

        if ($request->boolean('unpaid_only')) {
            $query->unpaid();
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $bookings = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            new BookingCollection($bookings),
            'Bookings retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'required|string|max:20',
            'facility_id' => 'required|exists:facilities,id',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
            'number_of_guests' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'services' => 'nullable|array',
            'services.*.service_name' => 'required_with:services|string|max:100',
            'services.*.service_provider' => 'nullable|string|max:100',
            'services.*.amount' => 'required_with:services|numeric|min:0',
        ]);

        // Check facility availability
        $facility = \App\Models\Facility::find($validated['facility_id']);
        if (!$facility->is_available_for_booking) {
            return $this->errorResponse('Selected facility is not available for booking', 422);
        }

        // Check guest capacity
        if ($validated['number_of_guests'] > $facility->max_capacity) {
            return $this->errorResponse(
                "Number of guests ({$validated['number_of_guests']}) exceeds facility capacity ({$facility->max_capacity})",
                422
            );
        }

        DB::beginTransaction();
        
        try {
            // Generate booking reference
            $bookingReference = $this->generateBookingReference();

            // Calculate basic totals (facility rate calculation would go here)
            $subtotal = 2500.00; // This should be calculated based on facility rates and dates
            $thirdPartyServiceAmount = 0;

            // Calculate third-party services total
            if (!empty($validated['services'])) {
                $thirdPartyServiceAmount = collect($validated['services'])->sum('amount');
            }

            $totalAmount = $subtotal + $thirdPartyServiceAmount;

            // Create booking
            $booking = Booking::create([
                'booking_reference' => $bookingReference,
                'guest_name' => $validated['guest_name'],
                'contact_number' => $validated['contact_number'],
                'facility_id' => $validated['facility_id'],
                'check_in_date' => $validated['check_in_date'],
                'check_out_date' => $validated['check_out_date'],
                'check_in_time' => $validated['check_in_time'] ?? '14:00:00',
                'check_out_time' => $validated['check_out_time'] ?? '12:00:00',
                'number_of_guests' => $validated['number_of_guests'],
                'booking_status' => 'Pending',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'third_party_service_amount' => $thirdPartyServiceAmount,
                'total_amount' => $totalAmount,
                'payment_status' => 'Unpaid',
                'amount_paid' => 0,
                'balance' => $totalAmount,
                'notes' => $validated['notes'],
                'created_by' => Auth::id(),
            ]);

            // Create booking services
            if (!empty($validated['services'])) {
                foreach ($validated['services'] as $serviceData) {
                    $booking->services()->create([
                        'service_name' => $serviceData['service_name'],
                        'service_provider' => $serviceData['service_provider'] ?? null,
                        'amount' => $serviceData['amount'],
                    ]);
                }
            }

            DB::commit();

            return $this->successResponse(
                new BookingResource($booking->load(['facility', 'creator', 'services'])),
                'Booking created successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to create booking: ' . $e->getMessage(), 500);
        }
    }

    public function show(Booking $booking)
    {
        $booking->load(['facility', 'creator', 'services', 'payments']);
        return $this->successResponse(
            new BookingResource($booking),
            'Booking retrieved successfully'
        );
    }

    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'required|string|max:20',
            'facility_id' => 'required|exists:facilities,id',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
            'number_of_guests' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check if booking can be updated
        if ($booking->booking_status === 'Cancelled') {
            return $this->errorResponse('Cannot update cancelled booking', 422);
        }

        $booking->update($validated);

        return $this->successResponse(
            new BookingResource($booking->fresh()->load(['facility', 'creator', 'services'])),
            'Booking updated successfully'
        );
    }

    public function destroy(Booking $booking)
    {
        // Check if booking has payments
        if ($booking->payments()->exists()) {
            return $this->errorResponse(
                'Cannot delete booking. It has associated payments.',
                422
            );
        }

        $booking->delete();

        return $this->successResponse(
            null,
            'Booking deleted successfully'
        );
    }

    public function confirm(Booking $booking)
    {
        if ($booking->booking_status !== 'Pending') {
            return $this->errorResponse('Only pending bookings can be confirmed', 422);
        }

        $booking->update(['booking_status' => 'Confirmed']);

        return $this->successResponse(
            new BookingResource($booking->fresh()),
            'Booking confirmed successfully'
        );
    }

    public function cancel(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string|max:500',
        ]);

        if ($booking->booking_status === 'Cancelled') {
            return $this->errorResponse('Booking is already cancelled', 422);
        }

        $booking->update([
            'booking_status' => 'Cancelled',
            'notes' => ($booking->notes ? $booking->notes . '\n\n' : '') . 
                      'Cancelled: ' . ($validated['cancellation_reason'] ?? 'No reason provided'),
        ]);

        return $this->successResponse(
            new BookingResource($booking->fresh()),
            'Booking cancelled successfully'
        );
    }

    private function generateBookingReference()
    {
        $date = now()->format('Ymd');
        $lastBooking = Booking::whereDate('created_at', today())
                             ->orderBy('id', 'desc')
                             ->first();

        $sequence = $lastBooking ? (int) substr($lastBooking->booking_reference, -3) + 1 : 1;

        return 'BK' . $date . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}