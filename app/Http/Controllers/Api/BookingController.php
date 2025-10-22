<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Facility;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
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
        // ✅ USE DATABASE TRANSACTION WITH LOCKING
        return DB::transaction(function () use ($request) {
            try {
                $validated = $request->validated();
                
                // ✅ RE-CHECK AVAILABILITY WITH LOCK (PREVENT RACE CONDITION)
                $checkInDateTime = Carbon::parse($validated['check_in_datetime']);
                
                foreach ($validated['facilities'] as $index => $facilityData) {
                    // ✅ LOCK FACILITY ROW FOR UPDATE
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
                    
                    // ✅ RE-CHECK AVAILABILITY INSIDE TRANSACTION
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
                
                // Parse check-in datetime
                $checkInDateTime = Carbon::parse($validated['check_in_datetime']);
                
                // Calculate facility subtotal
                $facilitySubtotal = collect($validated['facilities'])->sum('base_amount');
                
                // Calculate third-party services total
                $thirdPartyServiceAmount = isset($validated['third_party_services']) 
                    ? collect($validated['third_party_services'])->sum('amount')
                    : 0;
                
                // Calculate total amount
                $totalAmount = $facilitySubtotal + $thirdPartyServiceAmount;
                
                // Calculate minimum deposit (50%)
                $minimumDeposit = $totalAmount * 0.5;
                
                // Get payment amount
                $amountPaid = $validated['payment']['amount_paid'] ?? 0;
                
                // Calculate balance
                $balance = $totalAmount - $amountPaid;
                
                // Determine payment status
                if ($amountPaid >= $totalAmount) {
                    $paymentStatus = 'Paid';
                } elseif ($amountPaid >= $minimumDeposit) {
                    $paymentStatus = 'Partial';
                } else {
                    $paymentStatus = 'Unpaid';
                }
                
                // Calculate total duration
                $durationHours = collect($validated['facilities'])->sum('duration_hours');
                
                // Calculate check-out datetime
                $checkOutDateTime = $durationHours > 0 
                    ? $checkInDateTime->copy()->addHours($durationHours)
                    : null;
                
                // Calculate cancellation deadline (72 hours before check-in)
                $cancellationDeadline = $checkInDateTime->copy()->subHours(72);
                
                // Create booking
                $booking = Booking::create([
                    'booking_reference' => $reference,
                    'guest_name' => $validated['guest_name'],
                    'contact_number' => $validated['contact_number'],
                    
                    // NEW datetime fields
                    'check_in_datetime' => $checkInDateTime,
                    'check_out_datetime' => $checkOutDateTime,
                    'duration_hours' => $durationHours,
                    
                    // OLD fields (for backward compatibility)
                    'facility_id' => $validated['facilities'][0]['facility_id'],
                    'check_in_date' => $checkInDateTime->toDateString(),
                    'check_out_date' => $checkOutDateTime ? $checkOutDateTime->toDateString() : null,
                    'check_in_time' => $checkInDateTime->format('H:i:s'),
                    'check_out_time' => $checkOutDateTime ? $checkOutDateTime->format('H:i:s') : null,
                    
                    // Guest information
                    'number_of_guests' => $validated['number_of_guests'],
                    'guest_breakdown' => $validated['guest_breakdown'] ?? null,
                    
                    // Financial information
                    'facility_subtotal' => $facilitySubtotal,
                    'subtotal' => $facilitySubtotal,
                    'third_party_service_amount' => $thirdPartyServiceAmount,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'balance' => $balance,
                    
                    // Status
                    'booking_status' => 'Pending',
                    'payment_status' => $paymentStatus,
                    'cancellation_deadline' => $cancellationDeadline,
                    
                    // Notes
                    'special_requests' => $validated['special_requests'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    
                    // Created by
                    'created_by' => auth()->id(),
                ]);
                
                // Create booking facilities
                foreach ($validated['facilities'] as $facilityData) {
                    $facilityStartTime = $checkInDateTime;
                    $facilityEndTime = $checkInDateTime->copy()->addHours($facilityData['duration_hours']);
                    
                    $booking->facilities()->create([
                        'facility_id' => $facilityData['facility_id'],
                        'rate_id' => $facilityData['rate_id'],
                        'quantity' => $facilityData['quantity'],
                        'start_datetime' => $facilityStartTime,
                        'end_datetime' => $facilityEndTime,
                        'duration_hours' => $facilityData['duration_hours'],
                        'base_amount' => $facilityData['base_amount'],
                    ]);
                }
                
                // Create third-party services (if any)
                if (isset($validated['third_party_services'])) {
                    foreach ($validated['third_party_services'] as $serviceData) {
                        $booking->thirdPartyServices()->create([
                            'service_name' => $serviceData['service_name'],
                            'amount' => $serviceData['amount'],
                        ]);
                    }
                }
                
                // Create payment record
                $paymentReference = $this->generatePaymentReference();
                
                Payment::create([
                    'transaction_type' => 'Booking',
                    'transaction_id' => $booking->id,
                    'transaction_reference' => $paymentReference,
                    'payment_method' => $validated['payment']['payment_method'],
                    'amount_paid' => $amountPaid,
                    'change_amount' => $validated['payment']['change_amount'] ?? 0,
                    'payment_reference' => $validated['payment']['payment_reference'] ?? null,
                    'notes' => $validated['payment']['notes'] ?? null,
                    'payment_date' => now()->toDateString(),
                    'payment_time' => now()->toTimeString(),
                    'received_by' => auth()->id(),
                ]);
                
                // Load relationships
                $booking->load([
                    'facilities.facility.facilityType',
                    'facilities.rate',
                    'thirdPartyServices',
                    'payments',
                    'createdBy',
                ]);
                
                Log::info('Booking created successfully', [
                    'booking_id' => $booking->id,
                    'reference' => $booking->booking_reference,
                    'user_id' => auth()->id(),
                ]);
                
                return response()->json([
                    'message' => 'Booking created successfully',
                    'data' => new BookingResource($booking),
                ], 201);
                
            } catch (\Exception $e) {
                Log::error('Booking creation failed', [
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // ✅ DON'T LEAK INTERNAL ERRORS
                $message = 'Failed to create booking.';
                if (str_contains($e->getMessage(), 'no longer has') || 
                    str_contains($e->getMessage(), 'under maintenance') ||
                    str_contains($e->getMessage(), 'not available')) {
                    $message = $e->getMessage();
                }
                
                throw $e; // Let transaction rollback
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

    public function update(UpdateBookingRequest $request, $id)
    {
        $booking = Booking::findOrFail($id);
        $validated = $request->validated();

        $booking->update($validated);

        if (isset($validated['total_amount']) || isset($validated['amount_paid'])) {
            $booking->update([
                'balance' => $booking->total_amount - $booking->amount_paid,
                'payment_status' => $booking->amount_paid >= $booking->total_amount
                    ? 'Paid'
                    : 'Unpaid',
            ]);
        }

        $booking->load(['facility.facilityType', 'createdBy']);

        return response()->json([
            'message' => 'Booking updated successfully',
            'data' => new BookingResource($booking),
        ]);
    }

    public function checkIn(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        // ✅ CHECK CURRENT STATUS
        if ($booking->booking_status === 'Checked_In') {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest already checked in'
            ], 400);
        }

        // ✅ CHECK IF CANCELLED
        if ($booking->booking_status === 'Cancelled') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot check in a cancelled booking'
            ], 400);
        }

        // ✅ CHECK IF CHECKED OUT
        if ($booking->booking_status === 'Checked_Out') {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking already completed'
            ], 400);
        }

        // ✅ CHECK PAYMENT STATUS (Optional: require minimum deposit)
        if ($booking->payment_status === 'Unpaid' && $booking->amount_paid < $booking->minimum_deposit) {
            return response()->json([
                'status' => 'error',
                'message' => sprintf(
                    'Cannot check in. Minimum deposit of ₱%.2f required. Only ₱%.2f paid.',
                    $booking->minimum_deposit ?? ($booking->total_amount * 0.5),
                    $booking->amount_paid
                ),
                'required_deposit' => $booking->minimum_deposit ?? ($booking->total_amount * 0.5),
                'amount_paid' => $booking->amount_paid,
                'balance' => $booking->balance,
            ], 400);
        }

        // ✅ CHECK CHECK-IN TIME (Optional: not too early)
        $checkInTime = Carbon::parse($booking->check_in_datetime);
        if (now()->lt($checkInTime->subHours(2))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Check-in time has not arrived yet. Please check in closer to your scheduled time.',
                'check_in_time' => $booking->check_in_datetime,
            ], 400);
        }

        // ✅ PERFORM CHECK-IN
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
            'data' => new BookingResource($booking->fresh(['checkedInBy'])),
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

    public function cancel(CancelBookingRequest $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            try {
                $booking = Booking::findOrFail($id);
                $validated = $request->validated();
                
                // ✅ CHECK IF ALREADY CANCELLED
                if ($booking->booking_status === 'Cancelled') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Booking is already cancelled'
                    ], 400);
                }

                // ✅ CHECK IF CHECKED IN
                if ($booking->booking_status === 'Checked_In') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot cancel a booking that is already checked in. Please check out first.'
                    ], 400);
                }

                // ✅ CHECK IF CHECKED OUT
                if ($booking->booking_status === 'Checked_Out') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot cancel a completed booking'
                    ], 400);
                }

                // ✅ CHECK CANCELLATION DEADLINE
                if (now()->gt($booking->cancellation_deadline)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cancellation deadline has passed. No refund will be issued.',
                        'cancellation_deadline' => $booking->cancellation_deadline,
                        'current_time' => now(),
                    ], 400);
                }

                // ✅ CHECK IF USER CAN CANCEL (Optional)
                if (!auth()->user()->can('manage-bookings') && $booking->created_by !== auth()->id()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You do not have permission to cancel this booking'
                    ], 403);
                }
                
                // Update booking status to Cancelled
                $booking->update([
                    'booking_status' => 'Cancelled',
                    'cancellation_reason' => $validated['cancellation_reason'],
                    'cancelled_at' => now(),
                ]);

                // Note: NO REFUND - Deposit is forfeited as per business rules
                // Balance becomes 0 (no longer owed)
                $booking->balance = 0;
                $booking->save();

                Log::info('Booking cancelled', [
                    'booking_id' => $booking->id,
                    'reference' => $booking->booking_reference,
                    'cancelled_by' => auth()->id(),
                    'reason' => $validated['cancellation_reason'],
                ]);

                $booking->load([
                    'facilities.facility.facilityType',
                    'facilities.rate',
                    'thirdPartyServices',
                    'createdBy',
                    'payments'
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking cancelled successfully. No refund will be issued as per policy.',
                    'data' => new BookingResource($booking),
                ]);

            } catch (\Exception $e) {
                Log::error('Booking cancellation failed', [
                    'booking_id' => $id,
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Generate booking reference number
     */
    private function generateReferenceNumber()
    {
        $date = now()->format('Ymd');
        $count = Booking::whereDate('created_at', today())->count() + 1;
        return 'BK' . $date . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate payment reference number
     */
    private function generatePaymentReference(): string
    {
        $date = now()->format('Ymd');
        $lastPayment = Payment::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastPayment ? ((int) substr($lastPayment->transaction_reference, -4)) + 1 : 1;
        
        return 'PAY' . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}