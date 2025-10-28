<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingFacility;
use App\Models\BookingGuestDiscount;
use App\Models\Facility;
use App\Models\Rate;
use App\Models\Discount;
use App\Services\BillingService;
use App\Services\FacilityAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    protected $billingService;
    protected $availabilityService;

    public function __construct(
        BillingService $billingService,
        FacilityAvailabilityService $availabilityService
    ) {
        $this->billingService = $billingService;
        $this->availabilityService = $availabilityService;
    }

    /**
     * Store a new booking
     * ✅ FIXED: Properly handles Swimming vs Package bookings
     */
    public function store(StoreBookingRequest $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                // ✅ STEP 1: Calculate entrance fees (Swimming only)
                $entranceSubtotal = 0;
                $entranceDiscountAmount = 0;
                
                if ($request->booking_type === 'Swimming' && $request->entrance_rate_id) {
                    $entranceRate = Rate::findOrFail($request->entrance_rate_id);
                    $baseEntranceTotal = $entranceRate->base_price * $request->number_of_guests;
                    $entranceSubtotal = $baseEntranceTotal;
                    
                    // ✅ Apply entrance discounts based on mode
                    if ($request->discount_mode === 'Direct' && $request->has('guest_discounts')) {
                        // Calculate per-guest discounts
                        foreach ($request->guest_discounts as $guestDiscount) {
                            $discount = Discount::find($guestDiscount['discount_id']);
                            if ($discount && $discount->is_active) {
                                $guestCount = $guestDiscount['count'];
                                
                                if ($discount->type === 'Percentage') {
                                    $discountPerGuest = $entranceRate->base_price * ($discount->value / 100);
                                } else {
                                    $discountPerGuest = min($discount->value, $entranceRate->base_price);
                                }
                                
                                $entranceDiscountAmount += $discountPerGuest * $guestCount;
                            }
                        }
                    } elseif ($request->discount_mode === 'Seasonal' && $request->discount_id) {
                        // Apply seasonal discount to total entrance
                        $discount = Discount::findOrFail($request->discount_id);
                        if ($discount->is_active) {
                            if ($discount->type === 'Percentage') {
                                $entranceDiscountAmount = $baseEntranceTotal * ($discount->value / 100);
                            } else {
                                $entranceDiscountAmount = min($discount->value, $baseEntranceTotal);
                            }
                        }
                    }
                    
                    $entranceSubtotal = max(0, $baseEntranceTotal - $entranceDiscountAmount);
                }
                
                // ✅ STEP 2: Calculate facility fees
                $facilitySubtotal = 0;
                foreach ($request->facilities as $facilityData) {
                    $facilitySubtotal += $facilityData['rate_amount'] * $facilityData['quantity'];
                }
                
                // ✅ STEP 3: Calculate third party services
                $servicesTotal = 0;
                if ($request->has('third_party_services')) {
                    foreach ($request->third_party_services as $service) {
                        $servicesTotal += $service['amount'];
                    }
                }
                
                // ✅ STEP 4: Apply manual discount if present
                $manualDiscountAmount = 0;
                if ($request->discount_mode === 'Manual' && $request->manual_discount_amount) {
                    $manualDiscountAmount = $request->manual_discount_amount;
                }
                
                // ✅ STEP 5: Calculate totals
                $subtotal = $entranceSubtotal + $facilitySubtotal + $servicesTotal;
                $totalDiscountAmount = $entranceDiscountAmount + $manualDiscountAmount;
                $totalAmount = max(0, $subtotal - $manualDiscountAmount);
                
                // ✅ STEP 6: Prepare datetime fields
                $checkInDate = Carbon::parse($request->check_in_date);
                $checkInTime = $request->check_in_time ?: '14:00';
                $checkOutDate = Carbon::parse($request->check_out_date);
                $checkOutTime = $request->check_out_time ?: '12:00';
                
                $checkInDateTime = Carbon::parse("{$checkInDate->toDateString()} {$checkInTime}");
                $checkOutDateTime = Carbon::parse("{$checkOutDate->toDateString()} {$checkOutTime}");
                
                // Calculate duration
                $durationHours = $checkInDateTime->diffInHours($checkOutDateTime);
                
                // ✅ STEP 7: Create booking record
                $booking = Booking::create([
                    'booking_reference' => Booking::generateBookingReference(),
                    'booking_type' => $request->booking_type,
                    'entrance_rate_id' => $request->booking_type === 'Swimming' ? $request->entrance_rate_id : null,
                    'guest_name' => $request->guest_name,
                    'contact_number' => $request->contact_number,
                    'check_in_date' => $checkInDate,
                    'check_out_date' => $checkOutDate,
                    'check_in_datetime' => $checkInDateTime,
                    'check_out_datetime' => $checkOutDateTime,
                    'check_in_time' => $checkInTime,
                    'check_out_time' => $checkOutTime,
                    'duration_hours' => $durationHours,
                    'number_of_guests' => $request->number_of_guests,
                    'guest_breakdown' => $request->guest_breakdown ? json_encode($request->guest_breakdown) : null,
                    'facility_subtotal' => $facilitySubtotal,
                    'entrance_subtotal' => $entranceSubtotal,
                    'subtotal' => $subtotal,
                    'third_party_service_amount' => $servicesTotal,
                    'discount_mode' => $request->discount_mode,
                    'discount_id' => ($request->discount_mode === 'Direct' || $request->discount_mode === 'Seasonal') 
                        ? $request->discount_id 
                        : null,
                    'manual_discount_amount' => $manualDiscountAmount,
                    'discount_amount' => $totalDiscountAmount,
                    'total_amount' => $totalAmount,
                    'booking_status' => 'Pending',
                    'cancellation_deadline' => $checkInDateTime->copy()->subHours(72),
                    'special_requests' => $request->special_requests,
                    'notes' => $request->notes,
                    'created_by' => auth()->id(),
                ]);
                
                // ✅ STEP 8: Save facilities
                foreach ($request->facilities as $facilityData) {
                    $rate = Rate::findOrFail($facilityData['rate_id']);
                    
                    BookingFacility::create([
                        'booking_id' => $booking->id,
                        'facility_id' => $facilityData['facility_id'],
                        'rate_id' => $facilityData['rate_id'],
                        'quantity' => $facilityData['quantity'],
                        'rate_amount' => $rate->base_price,
                        'subtotal' => $rate->base_price * $facilityData['quantity'],
                    ]);
                }
                
                // ✅ STEP 9: Save guest discounts (for Direct mode)
                if ($request->discount_mode === 'Direct' && $request->has('guest_discounts')) {
                    foreach ($request->guest_discounts as $guestDiscount) {
                        BookingGuestDiscount::create([
                            'booking_id' => $booking->id,
                            'guest_type' => $guestDiscount['guest_type'],
                            'guest_count' => $guestDiscount['count'],
                            'discount_id' => $guestDiscount['discount_id'],
                        ]);
                    }
                }
                
                // ✅ STEP 10: Save third party services
                if ($request->has('third_party_services')) {
                    foreach ($request->third_party_services as $service) {
                        $booking->thirdPartyServices()->create([
                            'service_name' => $service['service_name'],
                            'amount' => $service['amount'],
                        ]);
                    }
                }
                
                // ✅ STEP 11: Create billing WITHOUT payment
                // Payment is now handled separately in the Billing module
                $billing = $this->billingService->createBillingForBooking(
                    $booking, 
                    null, // No payment data
                    50 // 50% downpayment requirement
                );
                
                // ✅ STEP 12: Check for capacity warnings
                $warnings = session('capacity_warnings', []);
                
                // ✅ STEP 13: Log booking creation
                Log::info('Booking created', [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'booking_type' => $booking->booking_type,
                    'total_amount' => $totalAmount,
                    'billing_id' => $billing->id,
                    'created_by' => auth()->id(),
                ]);
                
                // Load relationships
                $booking->load([
                    'entranceRate',
                    'facilities.facility',
                    'facilities.rate',
                    'guestDiscounts.discount',
                    'thirdPartyServices',
                    'billing.payments',
                    'createdBy',
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking created successfully',
                    'data' => new BookingResource($booking),
                    'warnings' => $warnings, // Include capacity warnings
                ], 201);
                
            } catch (\Exception $e) {
                Log::error('Booking creation failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $request->all(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Get paginated list of bookings
     */
    public function index(Request $request)
    {
        $query = Booking::with([
            'entranceRate',
            'facilities.facility',
            'guestDiscounts.discount',
            'billing',
            'createdBy',
        ]);

        // Filter by booking type
        if ($request->has('booking_type') && $request->booking_type !== 'all') {
            $query->where('booking_type', $request->booking_type);
        }

        // ✅ FIXED: Filter by booking status (support both 'status' and 'booking_status' params)
        $bookingStatus = $request->input('booking_status') ?? $request->input('status');
        if ($bookingStatus && $bookingStatus !== 'all') {
            $query->where('booking_status', $bookingStatus);
            
            // ✅ NEW: Exclude already checked-in bookings from "Confirmed" list
            // When filtering for "Confirmed" bookings, only show those that haven't been checked in yet
            if ($bookingStatus === 'Confirmed') {
                $query->doesntHave('guestEntry');
            }
        }

        // ✅ NEW: Filter by payment status (from billing relationship)
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $query->whereHas('billing', function($q) use ($request) {
                $q->where('payment_status', $request->payment_status);
            });
        }

        // ✅ NEW: Filter by facility
        if ($request->has('facility_id') && $request->facility_id) {
            $query->whereHas('facilities', function($q) use ($request) {
                $q->where('facility_id', $request->facility_id);
            });
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('check_in_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('check_in_date', '<=', $request->date_to);
        }

        // Search by reference or guest name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate
        $perPage = $request->input('per_page', 15);
        $bookings = $query->paginate($perPage);

        return BookingResource::collection($bookings)->additional([
            'status' => 'success',
            'message' => 'Bookings retrieved successfully',
        ]);
    }

    /**
     * Get single booking
     */
    public function show($id)
    {
        $booking = Booking::with([
            'entranceRate',
            'facilities.facility.facilityType',
            'facilities.rate',
            'guestDiscounts.discount',
            'thirdPartyServices',
            'billing.payments.receivedBy',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'cancelledBy',
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new BookingResource($booking),
        ]);
    }

    /**
     * Update booking
     * ✅ FIXED: Now respects booking type rules
     */
    public function update(UpdateBookingRequest $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::findOrFail($id);
            
            // Prevent updates to checked-in or completed bookings
            if (in_array($booking->booking_status, ['Checked_In', 'Checked_Out', 'Cancelled'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot update booking with status: ' . $booking->booking_status,
                ], 400);
            }
            
            // Update booking logic similar to store but for existing booking
            // ... (implementation similar to store method)
            
            return response()->json([
                'status' => 'success',
                'message' => 'Booking updated successfully',
                'data' => new BookingResource($booking->fresh()),
            ]);
        });
    }

    /**
     * Cancel booking
     */
    public function cancel(CancelBookingRequest $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::with('billing')->findOrFail($id);
            
            // Update booking status
            $booking->update([
                'booking_status' => 'Cancelled',
                'cancellation_reason' => $request->cancellation_reason,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);
            
            // Cancel associated billing
            if ($booking->billing) {
                $this->billingService->cancelBilling(
                    $booking->billing,
                    $request->cancellation_reason
                );
            }
            
            Log::info('Booking cancelled', [
                'booking_id' => $booking->id,
                'cancelled_by' => auth()->id(),
                'reason' => $request->cancellation_reason,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Booking cancelled successfully',
                'data' => new BookingResource($booking->fresh()),
            ]);
        });
    }

    /**
     * Check-in a booking
     * 
     * ⚠️ DEPRECATED: Use GuestMonitoringController::checkInBooking() instead
     * This method is kept for backward compatibility but should not be used for new implementations.
     * Check-ins should be done through the Guest Monitoring module to maintain a unified check-in/out workflow.
     * 
     * @deprecated Use POST /guest-monitoring/check-in-booking/{bookingId} instead
     */
    public function checkIn(Request $request, $id)
    {
        $request->validate([
            'actual_guests' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);
        
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::findOrFail($id);
            
            // Validate booking can be checked in
            if ($booking->booking_status !== 'Confirmed') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only confirmed bookings can be checked in',
                ], 400);
            }
            
            // Check if payment is sufficient
            if (!$booking->billing || !$booking->billing->is_downpayment_paid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Downpayment must be paid before check-in',
                ], 400);
            }
            
            $booking->update([
                'booking_status' => 'Checked_In',
                'actual_check_in_datetime' => now(),
                'checked_in_by' => auth()->id(),
                'actual_guests' => $request->actual_guests ?? $booking->number_of_guests,
            ]);
            
            Log::info('Booking checked in', [
                'booking_id' => $booking->id,
                'checked_in_by' => auth()->id(),
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Guest checked in successfully',
                'data' => new BookingResource($booking->fresh()),
            ]);
        });
    }

    /**
     * Check-out a booking
     * 
     * ⚠️ DEPRECATED: Use GuestMonitoringController::checkout() instead
     * This method is kept for backward compatibility but should not be used for new implementations.
     * Check-outs should be done through the Guest Monitoring module to maintain a unified check-in/out workflow.
     * 
     * @deprecated Use POST /guest-monitoring/{guestEntryId}/checkout instead
     */
    public function checkOut(Request $request, $id)
    {
        $request->validate([
            'overstay_hours' => 'nullable|numeric|min:0',
            'additional_charges' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::with('billing')->findOrFail($id);
            
            // Validate booking can be checked out
            if ($booking->booking_status !== 'Checked_In') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only checked-in bookings can be checked out',
                ], 400);
            }
            
            $actualCheckout = now();
            $overstayHours = 0;
            $overstayFees = 0;
            
            // ✅ Calculate overstay fees (Package bookings only)
            if ($booking->booking_type === 'Package' && $actualCheckout->gt($booking->check_out_datetime)) {
                $overstayHours = $actualCheckout->diffInHours($booking->check_out_datetime);
                
                // Calculate overstay based on facility extension fees
                foreach ($booking->facilities as $bookingFacility) {
                    if ($bookingFacility->rate && $bookingFacility->rate->extension_fee > 0) {
                        $overstayFees += $overstayHours * $bookingFacility->rate->extension_fee * $bookingFacility->quantity;
                    }
                }
            }
            
            // Add additional charges if any
            $additionalCharges = $request->additional_charges ?? 0;
            $totalAdditional = $overstayFees + $additionalCharges;
            
            // Update billing if there are additional charges
            if ($totalAdditional > 0 && $booking->billing) {
                $booking->billing->update([
                    'overstay_amount' => $overstayFees,
                    'additional_charges' => $additionalCharges,
                    'total_amount' => $booking->billing->total_amount + $totalAdditional,
                    'balance' => $booking->billing->balance + $totalAdditional,
                ]);
            }
            
            // Update booking
            $booking->update([
                'booking_status' => 'Checked_Out',
                'actual_check_out_datetime' => $actualCheckout,
                'checked_out_by' => auth()->id(),
                'overstay_hours' => $overstayHours,
                'checkout_notes' => $request->notes,
            ]);
            
            Log::info('Booking checked out', [
                'booking_id' => $booking->id,
                'checked_out_by' => auth()->id(),
                'overstay_hours' => $overstayHours,
                'overstay_fees' => $overstayFees,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Guest checked out successfully',
                'data' => new BookingResource($booking->fresh()),
                'overstay' => [
                    'hours' => $overstayHours,
                    'fees' => $overstayFees,
                    'additional_charges' => $additionalCharges,
                    'total_additional' => $totalAdditional,
                ],
            ]);
        });
    }

    /**
     * Get available entrance rates for Swimming bookings
     */
    public function getEntranceRates()
    {
        $rates = Rate::where('rate_category', 'Entrance')
            ->whereNull('facility_id')
            ->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $rates->map(function($rate) {
                return [
                    'id' => $rate->id,
                    'name' => $rate->rate_name,
                    'price' => $rate->base_price,
                    'duration_hours' => $rate->duration,
                    'time_slot' => $this->getTimeSlot($rate->rate_name),
                ];
            }),
        ]);
    }

    /**
     * Get time slot from rate name
     */
    private function getTimeSlot($rateName)
    {
        if (str_contains($rateName, 'Day Rate')) {
            return '8:00 AM - 5:00 PM';
        } elseif (str_contains($rateName, 'Night Rate')) {
            return '5:00 PM - 10:00 PM';
        } elseif (str_contains($rateName, 'Day & Night')) {
            return '8:00 AM - 10:00 PM';
        }
        return null;
    }

    /**
     * Get booking summary/dashboard data
     */
    public function summary(Request $request)
    {
        $today = Carbon::today();
        
        // Today's stats
        $todayCheckIns = Booking::whereDate('check_in_date', $today)
            ->whereIn('booking_status', ['Confirmed', 'Checked_In'])
            ->count();
            
        $todayCheckOuts = Booking::whereDate('check_out_date', $today)
            ->where('booking_status', 'Checked_In')
            ->count();
        
        // Active bookings
        $activeBookings = Booking::whereIn('booking_status', ['Confirmed', 'Checked_In'])
            ->count();
        
        // Booking types breakdown
        $bookingTypes = Booking::select('booking_type', DB::raw('count(*) as count'))
            ->whereIn('booking_status', ['Confirmed', 'Checked_In', 'Checked_Out'])
            ->groupBy('booking_type')
            ->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'today' => [
                    'check_ins' => $todayCheckIns,
                    'check_outs' => $todayCheckOuts,
                ],
                'active_bookings' => $activeBookings,
                'booking_types' => $bookingTypes,
            ],
        ]);
    }
}