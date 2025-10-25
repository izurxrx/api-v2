<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Facility;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    protected $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function archived()
    {
        $bookings = Booking::onlyTrashed()->with([
            'facility.facilityType',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'payments',
        ])->latest('deleted_at')->paginate(5);

        return BookingResource::collection($bookings)->additional([
            'status' => 'success',
            'message' => 'Archived bookings retrieved successfully',
        ]);
    }

    public function restore($id)
    {
        $booking = Booking::onlyTrashed()->findOrFail($id);
        $booking->restore();

        return response()->json([
            'message' => 'Booking restored successfully',
            'data' => new BookingResource($booking->load(['facility.facilityType', 'createdBy', 'checkedInBy', 'checkedOutBy', 'payments'])),
        ]);
    }

    public function index(Request $request)
    {
        $query = Booking::with([
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'payments',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
        ]);

        if ($request->has('booking_status')) {
            $query->where('booking_status', $request->booking_status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('check_in_datetime', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('check_in_datetime', '<=', $request->date_to);
        }

        if ($request->has('facility_id')) {
            $query->whereHas('facilities', function ($q) use ($request) {
                $q->where('facility_id', $request->facility_id);
            });
        }

        // ✅ SANITIZE SEARCH INPUT
        if ($request->filled('search')) {
            // Strip HTML tags
            $search = strip_tags($request->search);
            
            // Remove SQL special characters (keep only alphanumeric, spaces, dashes)
            $search = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $search);
            
            // Limit length
            $search = substr($search, 0, 100);
            
            $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('booking_reference', 'like', "%{$search}%"); // or entry_reference
            });
        }

        $perPage = $request->input('per_page', 15);
        $bookings = $query->latest('created_at')->paginate($perPage);

        return BookingResource::collection($bookings)->additional([
            'status' => 'success',
            'message' => 'Bookings retrieved successfully',
        ]);
    }

    public function store(StoreBookingRequest $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                $validated = $request->validated();
                
                // ✅ RE-CHECK AVAILABILITY WITH LOCK
                $checkInDateTime = Carbon::parse($validated['check_in_datetime']);
                
                foreach ($validated['facilities'] as $facilityData) {
                    $facility = Facility::lockForUpdate()->find($facilityData['facility_id']);
                    
                    if (!$facility) {
                        throw new \Exception("Facility not found.");
                    }
                    
                    if ($facility->is_maintenance) {
                        throw new \Exception("Facility '{$facility->name}' is under maintenance.");
                    }
                    
                    if (!$facility->is_available_for_booking) {
                        throw new \Exception("Facility '{$facility->name}' is not available for booking.");
                    }
                    
                    $durationHours = $facilityData['duration_hours'] ?? 24;
                    $checkOut = $checkInDateTime->copy()->addHours($durationHours);
                    
                    $availabilityService = app(\App\Services\FacilityAvailabilityService::class);
                    $available = $availabilityService->getAvailableQuantity(
                        $facility->id,
                        $checkInDateTime,
                        $checkOut
                    );
                    
                    $requestedQuantity = $facilityData['quantity'];
                    
                    if ($available < $requestedQuantity) {
                        throw new \Exception(
                            "Facility '{$facility->name}' no longer has {$requestedQuantity} units available. Only {$available} units left."
                        );
                    }
                }
                
                // Generate booking reference
                $reference = $this->generateReferenceNumber();
                
                // Calculate amounts
                $facilitySubtotal = collect($validated['facilities'])->sum('base_amount');
                $thirdPartyServiceAmount = isset($validated['third_party_services']) 
                    ? collect($validated['third_party_services'])->sum('amount')
                    : 0;
                $subtotal = $facilitySubtotal + $thirdPartyServiceAmount;
                $totalAmount = $subtotal;
                
                // Calculate duration and checkout
                $durationHours = collect($validated['facilities'])->sum('duration_hours');
                $checkOutDateTime = $durationHours > 0 
                    ? $checkInDateTime->copy()->addHours($durationHours)
                    : null;
                $cancellationDeadline = $checkInDateTime->copy()->subHours(72);
                
                // ❌ REMOVED: Payment status, amount_paid, balance calculations
                
                // Create booking (WITHOUT payment fields)
                $booking = Booking::create([
                    'booking_reference' => $reference,
                    'guest_name' => $validated['guest_name'],
                    'contact_number' => $validated['contact_number'],
                    'check_in_date' => $checkInDateTime->format('Y-m-d'),     // ✅ ADD THIS
                    'check_in_datetime' => $checkInDateTime,
                    'check_out_date' => $checkOutDateTime?->format('Y-m-d'),  // ✅ ADD THIS
                    'check_out_datetime' => $checkOutDateTime,
                    'duration_hours' => $durationHours,
                    'number_of_guests' => $validated['number_of_guests'] ?? 0,
                    'guest_breakdown' => $validated['guest_breakdown'] ?? null,
                    'booking_status' => 'Pending',
                    'cancellation_deadline' => $cancellationDeadline,
                    'facility_subtotal' => $facilitySubtotal,
                    'third_party_service_amount' => $thirdPartyServiceAmount,
                    'subtotal' => $subtotal,
                    'total_amount' => $totalAmount,
                    'special_requests' => $validated['special_requests'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                foreach ($validated['facilities'] as $facilityData) {
                    $booking->facilities()->create([
                        'facility_id' => $facilityData['facility_id'],
                        'rate_id' => $facilityData['rate_id'],
                        'quantity' => $facilityData['quantity'],
                        'duration_hours' => $facilityData['duration_hours'],
                        'base_amount' => $facilityData['base_amount'],
                        'start_datetime' => $facilityData['start_datetime'] ?? $checkInDateTime,  // ✅ Use from request or default
                        'end_datetime' => $facilityData['end_datetime'] ?? $checkOutDateTime,     // ✅ Use from request or default
                        'extension_hours' => $facilityData['extension_hours'] ?? 0,
                        'extension_amount' => $facilityData['extension_amount'] ?? 0,
                        'subtotal' => $facilityData['subtotal'],
                    ]);
                }

                // Create third-party services if any
                if (isset($validated['third_party_services'])) {
                    foreach ($validated['third_party_services'] as $service) {
                        $booking->thirdPartyServices()->create([
                            'service_name' => $service['service_name'],
                            'amount' => $service['amount'],
                        ]);
                    }
                }

                // ✅ NEW: Create billing record with optional payment
                $paymentData = $validated['payment'] ?? null;
                
                try {
                    $billing = $this->billingService->createBillingForBooking($booking, $paymentData);
                    
                    // Update booking status if fully paid
                    if ($billing->payment_status === 'paid') {
                        $booking->update(['booking_status' => 'Confirmed']);
                    } elseif ($billing->payment_status === 'partial') {
                        $booking->update(['booking_status' => 'Confirmed']);
                    }
                    
                } catch (\Exception $e) {
                    // If billing creation fails, rollback everything
                    throw new \Exception('Failed to create billing: ' . $e->getMessage());
                }

                Log::info('Booking created', [
                    'booking_id' => $booking->id,
                    'reference' => $reference,
                    'billing_id' => $billing->id,
                    'created_by' => auth()->id(),
                ]);

                // Load relationships
                $booking->load([
                    'facilities.facility.facilityType',
                    'facilities.rate',
                    'thirdPartyServices',
                    'createdBy',
                    'billing.payments' // ✅ NEW
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking created successfully',
                    'data' => new BookingResource($booking),
                ], 201);

            } catch (\Exception $e) {
                Log::error('Booking creation failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => auth()->id(),
                ]);
                
                throw $e;
            }
        });
    }
    
    public function show($id)
    {
        $booking = Booking::with([
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'payments',
        ])->findOrFail($id);

        return response()->json([
            'data' => new BookingResource($booking),
        ]);
    }

    /**
     * ✅ Update booking - Only guest name allowed
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::with('billing')->findOrFail($id);

            // ✅ Check if booking can be modified
            if (in_array($booking->booking_status, ['Checked_Out', 'Completed', 'Cancelled'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot modify a booking that is already ' . $booking->booking_status,
                ], 400);
            }

            // ✅ Update only guest name
            $booking->update([
                'guest_name' => $validated['guest_name'],
            ]);

            DB::commit();

            Log::info('Booking guest name updated', [
                'booking_id' => $booking->id,
                'old_name' => $booking->getOriginal('guest_name'),
                'new_name' => $validated['guest_name'],
                'updated_by' => auth()->id(),
            ]);

            $booking->load([
                'facilities.facility.facilityType',
                'facilities.rate',
                'billing.payments',
                'createdBy',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Guest name updated successfully',
                'data' => new BookingResource($booking),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Booking update failed', [
                'booking_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update booking',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function checkIn(Request $request, $id)
    {
        $booking = Booking::with('billing')->findOrFail($id);

        if ($booking->booking_status === 'Checked_In') {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest already checked in'
            ], 400);
        }

        if ($booking->booking_status === 'Checked_Out') {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking already completed'
            ], 400);
        }

        // ✅ UPDATED: Check payment via billing
        $billing = $booking->billing;
        if (!$billing || $billing->amount_paid < $booking->minimum_deposit) {
            return response()->json([
                'status' => 'error',
                'message' => sprintf(
                    'Cannot check in. Minimum deposit of ₱%.2f required. Only ₱%.2f paid.',
                    $booking->minimum_deposit,
                    $billing?->amount_paid ?? 0
                ),
                'required_deposit' => $booking->minimum_deposit,
                'amount_paid' => $billing?->amount_paid ?? 0,
                'balance' => $billing?->balance ?? $booking->total_amount,
            ], 400);
        }

        $checkInTime = Carbon::parse($booking->check_in_datetime);
        if (now()->lt($checkInTime->subHours(2))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Check-in time has not arrived yet.',
                'check_in_time' => $booking->check_in_datetime,
            ], 400);
        }

        $booking->update([
            'booking_status' => 'Checked_In',
            'actual_check_in_datetime' => now(),
            'checked_in_by' => auth()->id(),
        ]);

        Log::info('Guest checked in', [
            'booking_id' => $booking->id,
            'reference' => $booking->booking_reference,
            'checked_in_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Guest checked in successfully',
            'data' => new BookingResource($booking->fresh(['checkedInBy', 'billing'])),
        ]);
    }

    public function checkOut(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->booking_status === 'Checked_Out') {
            return response()->json(['message' => 'Guest already checked out'], 400);
        }

        $booking->update([
            'booking_status' => 'Checked_Out',
            'actual_check_out_datetime' => now(),
            'checked_out_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Guest checked out successfully',
            'data' => new BookingResource($booking->fresh(['checkedOutBy'])),
        ]);
    }

    public function destroy($id)
    {
        $booking = Booking::findOrFail($id);
        $booking->delete();

        return response()->json([
            'message' => 'Booking deleted successfully',
        ]);
    }

    /**
     * ✅ Cancel booking with payment logic
     */
    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::with(['billing.payments', 'facilities'])->findOrFail($id);

            // ✅ Check if booking can be cancelled
            if (in_array($booking->booking_status, ['Checked_Out', 'Completed', 'Cancelled'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot cancel a booking that is already ' . $booking->booking_status,
                ], 400);
            }

            // ✅ If already checked in, cannot cancel
            if ($booking->booking_status === 'Checked_In') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot cancel a booking that is already checked in. Please process checkout instead.',
                ], 400);
            }

            $billing = $booking->billing;
            $hasDownpayment = $billing && $billing->is_downpayment_paid;

            // ✅ Update booking status
            $booking->update([
                'booking_status' => 'Cancelled',
                'cancellation_reason' => $validated['reason'],
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            // ✅ Update billing
            if ($billing) {
                if ($hasDownpayment) {
                    // ✅ Downpayment is non-refundable
                    $billing->update([
                        'billing_status' => 'cancelled',
                        'cancellation_reason' => 'Booking cancelled. Downpayment is non-refundable.',
                        'cancelled_by' => auth()->id(),
                    ]);

                    $message = 'Booking cancelled. Downpayment of ₱' . number_format($billing->downpayment_paid, 2) . ' is non-refundable.';
                } else {
                    // ✅ No downpayment - free cancellation
                    $billing->update([
                        'billing_status' => 'cancelled',
                        'payment_status' => 'cancelled',
                        'cancellation_reason' => 'Booking cancelled before downpayment.',
                        'cancelled_by' => auth()->id(),
                    ]);

                    $message = 'Booking cancelled successfully. No charges applied.';
                }
            } else {
                $message = 'Booking cancelled successfully.';
            }

            DB::commit();

            Log::info('Booking cancelled', [
                'booking_id' => $booking->id,
                'had_downpayment' => $hasDownpayment,
                'downpayment_amount' => $billing?->downpayment_paid ?? 0,
                'reason' => $validated['reason'],
                'cancelled_by' => auth()->id(),
            ]);

            $booking->load([
                'facilities.facility.facilityType',
                'billing.payments',
                'createdBy',
                'cancelledBy',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => new BookingResource($booking),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Booking cancellation failed', [
                'booking_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel booking',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function generateReferenceNumber()
    {
        $date = now()->format('Ymd');
        $count = Booking::whereDate('created_at', today())->count() + 1;
        return 'BK' . $date . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * ✅ Record downpayment for booking
     */
    public function recordDownpayment(Request $request, $id)
    {
        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            'change_amount' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::with('billing')->findOrFail($id);

            if (!$booking->billing) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Billing not found for this booking',
                ], 404);
            }

            $billing = $booking->billing;

            // ✅ Check if booking is in valid state
            if ($booking->booking_status !== 'Pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Can only record downpayment for pending bookings',
                ], 400);
            }

            // ✅ Check if downpayment already paid
            if ($billing->is_downpayment_paid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Downpayment has already been paid for this booking',
                ], 400);
            }

            $amountPaid = $validated['amount_paid'];

            // ✅ Validate downpayment amount
            if ($amountPaid < $billing->downpayment_amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => sprintf(
                        'Downpayment amount (₱%.2f) is less than required (₱%.2f)',
                        $amountPaid,
                        $billing->downpayment_amount
                    ),
                    'required_downpayment' => $billing->downpayment_amount,
                ], 400);
            }

            // ✅ Record payment
            $paymentData = [
                'amount_paid' => $amountPaid,
                'payment_method' => $validated['payment_method'],
                'change_amount' => $validated['change_amount'] ?? 0,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? 'Downpayment',
            ];

            $payment = $this->billingService->recordPayment($billing, $paymentData, 'downpayment');

            // ✅ Update billing downpayment status
            $billing->update([
                'downpayment_paid' => $billing->downpayment_paid + $amountPaid,
                'is_downpayment_paid' => true,
            ]);

            // ✅ Update booking status to Confirmed
            $booking->update([
                'booking_status' => 'Confirmed',
            ]);

            DB::commit();

            Log::info('Downpayment recorded', [
                'booking_id' => $booking->id,
                'amount' => $amountPaid,
                'billing_id' => $billing->id,
                'received_by' => auth()->id(),
            ]);

            $booking->load([
                'facilities.facility.facilityType',
                'billing.payments',
                'createdBy',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Downpayment recorded successfully. Booking is now confirmed.',
                'data' => new BookingResource($booking),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Downpayment recording failed', [
                'booking_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record downpayment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * ✅ Mark booking as no-show (guest didn't show up)
     */
    public function markNoShow(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::with('billing')->findOrFail($id);

            // ✅ Check if booking is in valid state
            if (!in_array($booking->booking_status, ['Pending', 'Confirmed'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Can only mark Pending or Confirmed bookings as no-show',
                ], 400);
            }

            // ✅ Check if check-in date has passed
            if (now()->lt($booking->check_in_datetime)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot mark as no-show before check-in date',
                ], 400);
            }

            // ✅ Update booking status
            $booking->update([
                'booking_status' => 'No_Show',
                'notes' => $validated['notes'] ?? 'Guest did not show up. Full payment still required.',
            ]);

            // ✅ Guest must still pay full amount (no-show policy)
            $billing = $booking->billing;
            if ($billing && $billing->balance > 0) {
                $billing->update([
                    'notes' => 'No-show: Guest must pay full amount for all reserved days.',
                    'billing_status' => 'active',
                ]);
            }

            DB::commit();

            Log::warning('Booking marked as no-show', [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'guest_name' => $booking->guest_name,
                'balance_due' => $billing?->balance ?? 0,
                'marked_by' => auth()->id(),
            ]);

            $booking->load([
                'facilities.facility.facilityType',
                'billing.payments',
                'createdBy',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Booking marked as no-show. Guest must still pay for all reserved days.',
                'data' => new BookingResource($booking),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('No-show marking failed', [
                'booking_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark booking as no-show',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}