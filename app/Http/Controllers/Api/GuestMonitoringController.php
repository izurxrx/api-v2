<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestEntry\StoreGuestEntryRequest;
use App\Http\Requests\GuestEntry\UpdateGuestEntryRequest;
use App\Http\Requests\GuestEntry\CheckoutGuestEntryRequest;
use App\Http\Resources\GuestEntryResource;
use App\Models\Billing;
use App\Models\Discount;
use App\Models\Facility;
use App\Models\GuestEntry;
use App\Models\GuestEntryDetail;
use App\Models\GuestEntryFacility;
use App\Models\ThirdPartyService;
use App\Models\Rate;
use App\Services\BillingService; // ✅ NEW
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GuestMonitoringController extends Controller
{
    protected $billingService; // ✅ NEW

    public function __construct(BillingService $billingService) // ✅ NEW
    {
        $this->billingService = $billingService;
    }

    public function index(Request $request)
    {
        $query = GuestEntry::with([
            'details.rate',
            'details.discount',
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'createdBy',
            'billing.payments' // ✅ UPDATED: Load billing instead of payments
        ]);

        if ($request->has('date')) {
            $query->whereDate('entry_date', $request->date);
        }

        if ($request->has('status')) {
            if ($request->status === 'checked_out') {
                $query->where('is_checked_out', true);
            } else {
                $query->where('is_checked_out', false);
            }
        }

        // ✅ UPDATED: Filter by payment status via billing relationship
        if ($request->has('payment_status')) {
            $query->whereHas('billing', function($q) use ($request) {
                $q->where('payment_status', $request->payment_status);
            });
        }

        if ($request->filled('search')) {
            $search = strip_tags($request->search);
            $search = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $search);
            $search = substr($search, 0, 100);
            
            $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('entry_reference', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 5);
        $entries = $query->latest()->paginate($perPage);

        return GuestEntryResource::collection($entries)->additional([
            'status' => 'success',
            'message' => 'Guest entries retrieved successfully',
        ]);
    }

    public function store(StoreGuestEntryRequest $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();
            $reference = $this->generateReferenceNumber();
            
            $totalGuests = collect($validated['details'])->sum('guest_count');
            
            $guestEntry = GuestEntry::create([
                'entry_reference' => $reference,
                'entry_date' => $validated['entry_date'],
                'entry_time' => $validated['entry_time'] ?? now()->format('H:i:s'),
                'check_in_datetime' => now(),
                'discount_mode' => $validated['discount_mode'], // Direct, Seasonal, or None
                'discount_id' => $validated['discount_id'] ?? null,
                'manual_discount_amount' => $validated['manual_discount_amount'] ?? 0.00, // ✅ Optional
                'guest_name' => $validated['guest_name'],
                'contact_number' => $validated['contact_number'] ?? null,
                'total_guests' => $totalGuests,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Track amounts and free children
            $entranceOriginalTotal = 0;
            $entranceAfterDirectDiscount = 0;
            $totalDirectDiscounts = 0;
            $freeChildrenCount = 0;
            
            foreach ($validated['details'] as $detail) {
                $rate = Rate::findOrFail($detail['rate_id']);
                $baseRate = $rate->base_price;
                $guestCount = $detail['guest_count'];
                $guestTypeName = $detail['guest_type_name'];
                
                // ✅ Check if this is children below 2 (always free)
                $isChildrenBelow2 = $this->isChildrenBelow2($guestTypeName, $rate);
                
                if ($isChildrenBelow2) {
                    // Children below 2 are FREE
                    $freeChildrenCount += $guestCount;
                    
                    GuestEntryDetail::create([
                        'guest_entry_id' => $guestEntry->id,
                        'guest_type_name' => $guestTypeName,
                        'rate_id' => $rate->id,
                        'guest_count' => $guestCount,
                        'base_rate' => $baseRate,
                        'discount_mode' => 'None',
                        'discount_id' => null,
                        'discount_amount' => $baseRate, // Full discount
                        'final_rate' => 0.00,
                        'total_amount' => 0.00,
                    ]);
                    
                    continue;
                }
                
                // Calculate original amount
                $originalAmount = $baseRate * $guestCount;
                $entranceOriginalTotal += $originalAmount;
                
                // Apply direct discount if applicable
                $discountAmount = 0;
                if ($detail['discount_mode'] === 'Direct' && isset($detail['discount_id'])) {
                    $discount = Discount::findOrFail($detail['discount_id']);
                    $discountAmount = $this->calculateDiscountAmount($discount, $baseRate);
                    $totalDirectDiscounts += ($discountAmount * $guestCount);
                }
                
                $finalRate = $baseRate - $discountAmount;
                $totalAmount = $finalRate * $guestCount;
                $entranceAfterDirectDiscount += $totalAmount;
                
                // Create detail record
                GuestEntryDetail::create([
                    'guest_entry_id' => $guestEntry->id,
                    'guest_type_name' => $guestTypeName,
                    'rate_id' => $rate->id,
                    'guest_count' => $guestCount,
                    'base_rate' => $baseRate,
                    'discount_mode' => $detail['discount_mode'],
                    'discount_id' => $detail['discount_id'] ?? null,
                    'discount_amount' => $discountAmount,
                    'final_rate' => max(0, $finalRate),
                    'total_amount' => max(0, $totalAmount),
                ]);
            }

            // Process facility rentals
            $facilitySubtotal = 0;
            if (isset($validated['facilities']) && count($validated['facilities']) > 0) {
                foreach ($validated['facilities'] as $facility) {
                    $rental = $this->createFacilityRental($guestEntry->id, $facility);
                    $facilitySubtotal += $rental->subtotal;
                }
            }

            // Process third-party services
            $thirdPartyAmount = 0;
            if (isset($validated['third_party_services']) && count($validated['third_party_services']) > 0) {
                foreach ($validated['third_party_services'] as $service) {
                    ThirdPartyService::create([
                        'guest_entry_id' => $guestEntry->id,
                        'service_name' => $service['service_name'],
                        'amount' => $service['amount'],
                    ]);
                    $thirdPartyAmount += $service['amount'];
                }
            }

            // ✅ UPDATED: Calculate discounts (Seasonal + Manual)
            $seasonalDiscountAmount = 0;
            $manualDiscountAmount = 0;
            
            // Apply seasonal discount if selected
            if ($validated['discount_mode'] === 'Seasonal' && $validated['discount_id']) {
                $discount = Discount::findOrFail($validated['discount_id']);
                $seasonalDiscountAmount = $this->calculateDiscountAmount($discount, $entranceOriginalTotal);
            }
            
            // ✅ Apply manual discount (optional, on top of any other discount)
            if (isset($validated['manual_discount_amount']) && $validated['manual_discount_amount'] > 0) {
                $manualDiscountAmount = $validated['manual_discount_amount'];
            }
            
            // Calculate final amounts
            $subtotal = $entranceOriginalTotal + $facilitySubtotal;
            $totalDiscountAmount = $totalDirectDiscounts + $seasonalDiscountAmount + $manualDiscountAmount;
            $totalAmount = $subtotal - $totalDiscountAmount + $thirdPartyAmount;

            // Update guest entry
            $guestEntry->update([
                'entrance_subtotal' => $entranceAfterDirectDiscount,
                'facility_subtotal' => $facilitySubtotal,
                'third_party_service_amount' => $thirdPartyAmount,
                'subtotal' => $subtotal,
                'discount_amount' => $totalDiscountAmount,
                'total_amount' => $totalAmount,
            ]);

            // ✅ Handle free entry (only free children, no charges)
            if ($totalAmount <= 0) {
                $billing = Billing::create([
                    'billable_type' => GuestEntry::class,
                    'billable_id' => $guestEntry->id,
                    'billing_number' => Billing::generateBillingNumber(),
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'total_amount' => 0,
                    'amount_paid' => 0,
                    'balance' => 0,
                    'payment_status' => 'paid',
                    'billing_status' => 'completed',
                    'billed_at' => now(),
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                    'notes' => "Free entry: {$freeChildrenCount} children below 2 years old",
                ]);

                DB::commit();

                Log::info('Guest entry created (FREE)', [
                    'entry_id' => $guestEntry->id,
                    'reference' => $reference,
                    'billing_id' => $billing->id,
                    'free_children_count' => $freeChildrenCount,
                    'created_by' => auth()->id(),
                ]);

                $guestEntry->load([
                    'details.rate',
                    'details.discount',
                    'facilities.facility.facilityType',
                    'billing',
                    'createdBy',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Guest entry created successfully. {$freeChildrenCount} children below 2 years entered free of charge.",
                    'data' => new GuestEntryResource($guestEntry),
                ], 201);
            }

            // Validate payment
            if (!isset($validated['payment'])) {
                DB::rollBack();
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment information is required for check-in',
                    'errors' => [
                        'payment' => [
                            sprintf('Payment is required. Guest must pay ₱%.2f to check in.', $totalAmount)
                        ]
                    ],
                    'required_amount' => $totalAmount,
                    'free_children_count' => $freeChildrenCount,
                    'discount_breakdown' => [
                        'direct_discounts' => number_format($totalDirectDiscounts, 2),
                        'seasonal_discount' => number_format($seasonalDiscountAmount, 2),
                        'manual_discount' => number_format($manualDiscountAmount, 2),
                        'total_discount' => number_format($totalDiscountAmount, 2),
                    ],
                ], 422);
            }

            $paymentAmount = $validated['payment']['amount_paid'] ?? 0;

            if ($paymentAmount < $totalAmount) {
                DB::rollBack();
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Full payment is required for check-in',
                    'errors' => [
                        'payment.amount' => [
                            sprintf(
                                'Payment amount (₱%.2f) is less than total amount (₱%.2f).',
                                $paymentAmount,
                                $totalAmount
                            )
                        ]
                    ],
                    'total_amount' => $totalAmount,
                    'payment_amount' => $paymentAmount,
                    'balance' => $totalAmount - $paymentAmount,
                    'free_children_count' => $freeChildrenCount,
                    'discount_breakdown' => [
                        'direct_discounts' => number_format($totalDirectDiscounts, 2),
                        'seasonal_discount' => number_format($seasonalDiscountAmount, 2),
                        'manual_discount' => number_format($manualDiscountAmount, 2),
                        'total_discount' => number_format($totalDiscountAmount, 2),
                    ],
                ], 422);
            }

            // Create billing with payment
            $paymentData = [
                'amount_paid' => $paymentAmount,
                'payment_method' => $validated['payment']['method'] ?? 'Cash',
                'change_amount' => $validated['payment']['change_amount'] ?? 0,
                'reference_number' => $validated['payment']['reference_number'] ?? null,
                'notes' => $validated['payment']['notes'] ?? null,
            ];

            $billing = $this->billingService->createBillingForGuestEntry($guestEntry, $paymentData);

            DB::commit();

            Log::info('Guest entry created', [
                'entry_id' => $guestEntry->id,
                'reference' => $reference,
                'billing_id' => $billing->id,
                'total_amount' => $totalAmount,
                'free_children_count' => $freeChildrenCount,
                'discount_breakdown' => [
                    'direct' => $totalDirectDiscounts,
                    'seasonal' => $seasonalDiscountAmount,
                    'manual' => $manualDiscountAmount,
                    'total' => $totalDiscountAmount,
                ],
                'created_by' => auth()->id(),
            ]);

            $guestEntry->load([
                'details.rate',
                'details.discount',
                'facilities.facility.facilityType',
                'facilities.rate',
                'thirdPartyServices',
                'billing.payments',
                'createdBy',
            ]);

            $successMessage = 'Guest entry created and payment recorded successfully';
            if ($freeChildrenCount > 0) {
                $successMessage .= ". {$freeChildrenCount} children below 2 years entered free of charge.";
            }

            return response()->json([
                'status' => 'success',
                'message' => $successMessage,
                'data' => new GuestEntryResource($guestEntry),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Guest entry creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create guest entry',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * ✅ Check if guest type is children below 2 years (always free)
     */
    private function isChildrenBelow2(string $guestTypeName, Rate $rate): bool
    {
        $rateName = strtolower($rate->rate_name);
        if (str_contains($rateName, 'children below 2 years old') || 
            str_contains($rateName, 'children below 2')) {
            return true;
        }
        
        $guestTypeNameLower = strtolower($guestTypeName);
        if (str_contains($guestTypeNameLower, 'children below 2 years old') || 
            str_contains($guestTypeNameLower, 'children below 2')) {
            return true;
        }
        
        $childrenKeywords = ['0-2 years', '0-2 year', 'below 2', 'under 2', '0 - 2'];
        foreach ($childrenKeywords as $keyword) {
            if (str_contains($guestTypeNameLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    public function show($id)
    {
        $entry = GuestEntry::with([
            'details.rate',
            'details.discount',
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'billing.payments', // ✅ UPDATED
            'createdBy',
        ])->findOrFail($id);

        return response()->json([
            'data' => new GuestEntryResource($entry),
        ]);
    }

    public function update(UpdateGuestEntryRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $entry = GuestEntry::with('billing')->findOrFail($id);
            $validated = $request->validated();

            // Check if checked out
            if ($entry->is_checked_out) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot update a checked-out guest entry'
                ], 400);
            }

            // Update basic info
            $entry->update([
                'guest_name' => $validated['guest_name'] ?? $entry->guest_name,
                'contact_number' => $validated['contact_number'] ?? $entry->contact_number,
                'notes' => $validated['notes'] ?? $entry->notes,
            ]);

            // Handle facility extensions if provided
            if (isset($validated['facility_extensions'])) {
                foreach ($validated['facility_extensions'] as $extension) {
                    $this->processFacilityExtension($extension);
                }
            }

            // Handle third-party service additions
            if (isset($validated['additional_services'])) {
                foreach ($validated['additional_services'] as $service) {
                    ThirdPartyService::create([
                        'guest_entry_id' => $entry->id,
                        'service_name' => $service['service_name'],
                        'amount' => $service['amount'],
                    ]);
                }
            }

            // Recalculate totals
            $entry->recalculateTotals();

            DB::commit();

            Log::info('Guest entry updated', [
                'entry_id' => $entry->id,
                'updated_by' => auth()->id(),
            ]);

            $entry->load([
                'details.rate',
                'details.discount',
                'facilities.facility.facilityType',
                'facilities.rate',
                'thirdPartyServices',
                'billing.payments', // ✅ UPDATED
                'createdBy',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Guest entry updated successfully',
                'data' => new GuestEntryResource($entry),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Guest entry update failed', [
                'entry_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update guest entry',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function checkout(CheckoutGuestEntryRequest $request, $id)
    {
        $validated = $request->validated();
        $entry = GuestEntry::with('billing.payments')->findOrFail($id);

        if ($entry->is_checked_out) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest already checked out',
            ], 400);
        }

        return DB::transaction(function () use ($entry, $validated) {
            try {
                // ✅ UPDATED: Check payment via billing
                $billing = $entry->billing;
                
                if (!$billing || $billing->balance > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            'Cannot checkout. Outstanding balance of ₱%.2f must be paid first.',
                            $billing?->balance ?? $entry->total_amount
                        ),
                        'balance' => $billing?->balance ?? $entry->total_amount,
                    ], 400);
                }

                // Update checkout info
                $entry->update([
                    'is_checked_out' => true,
                    'checkout_datetime' => now(),
                    'exit_date' => $validated['exit_date'],
                    'exit_time' => $validated['exit_time'],
                    'notes' => $validated['notes'] ?? $entry->notes,
                ]);

                Log::info('Guest checked out', [
                    'entry_id' => $entry->id,
                    'reference' => $entry->entry_reference,
                    'checked_out_by' => auth()->id(),
                    'exit_datetime' => "{$validated['exit_date']} {$validated['exit_time']}",
                ]);

                $entry->load([
                    'details.rate',
                    'details.discount',
                    'facilities.facility.facilityType',
                    'facilities.rate',
                    'thirdPartyServices',
                    'billing.payments', // ✅ UPDATED
                    'createdBy',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Guest checked out successfully',
                    'data' => new GuestEntryResource($entry),
                ]);

            } catch (\Exception $e) {
                Log::error('Checkout failed', [
                    'entry_id' => $entry->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        });
    }

    public function destroy($id)
    {
        $entry = GuestEntry::findOrFail($id);
        
        // Prevent deletion of checked-out entries
        if ($entry->is_checked_out) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete a checked-out guest entry'
            ], 400);
        }

        $entry->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Guest entry deleted successfully',
        ]);
    }

    public function archived()
    {
        $entries = GuestEntry::onlyTrashed()->with([
            'details.rate',
            'details.discount',
            'facilities.facility.facilityType',
            'thirdPartyServices',
            'createdBy',
            'billing.payments', // ✅ UPDATED
        ])->paginate(5);

        return GuestEntryResource::collection($entries)->additional([
            'status' => 'success',
            'message' => 'Archived guest entries retrieved successfully',
        ]);
    }

    public function restore($id)
    {
        $entry = GuestEntry::onlyTrashed()->findOrFail($id);
        $entry->restore();

        return response()->json([
            'status' => 'success',
            'message' => 'Guest entry restored successfully',
            'data' => new GuestEntryResource($entry->load('billing.payments')),
        ]);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function generateReferenceNumber()
    {
        $date = now()->format('Ymd');
        $count = GuestEntry::whereDate('created_at', today())->count() + 1;
        return 'EN' . $date . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    private function createGuestDetail($guestEntryId, array $guestData)
    {
        $rate = Rate::findOrFail($guestData['rate_id']);
        $baseRate = $rate->base_price;
        $guestTypeName = $guestData['guest_type_name'];
        
        // ✅ NEW: Check if this is children below 2 (always free)
        $isChildrenBelow2 = $this->isChildrenBelow2($guestTypeName, $rate);
        
        if ($isChildrenBelow2) {
            // Children below 2 are ALWAYS FREE - ignore any discount selections
            return GuestEntryDetail::create([
                'guest_entry_id' => $guestEntryId,
                'guest_type_name' => $guestTypeName,
                'rate_id' => $rate->id,
                'guest_count' => $guestData['guest_count'],
                'base_rate' => $baseRate,
                'discount_mode' => 'None',
                'discount_id' => null,
                'discount_amount' => $baseRate, // Full amount as discount
                'final_rate' => 0.00,
                'total_amount' => 0.00,
            ]);
        }
        
        // For other guest types, apply user-selected discounts
        $discountId = $guestData['discount_id'] ?? null;
        $discountAmount = 0;

        // Apply Direct discount if provided (PWD, Senior, etc.)
        if ($guestData['discount_mode'] === 'Direct' && $discountId) {
            $discount = Discount::findOrFail($discountId);
            $discountAmount = $this->calculateDiscountAmount($discount, $baseRate);
        }

        $finalRate = $baseRate - $discountAmount;
        $totalAmount = $finalRate * $guestData['guest_count'];

        return GuestEntryDetail::create([
            'guest_entry_id' => $guestEntryId,
            'guest_type_name' => $guestTypeName,
            'rate_id' => $rate->id,
            'guest_count' => $guestData['guest_count'],
            'base_rate' => $baseRate,
            'discount_mode' => $guestData['discount_mode'],
            'discount_id' => $discountId,
            'discount_amount' => $discountAmount,
            'final_rate' => max(0, $finalRate),
            'total_amount' => max(0, $totalAmount),
        ]);
    }

    private function createFacilityRental($guestEntryId, array $facilityData)
    {
        if (!isset($facilityData['start_datetime']) || !isset($facilityData['end_datetime'])) {
            throw new \Exception('Missing start_datetime or end_datetime for a facility.');
        }

        $rate = Rate::findOrFail($facilityData['rate_id']);
        $facility = Facility::findOrFail($facilityData['facility_id']);
        
        $start = new \DateTime($facilityData['start_datetime']);
        $end = new \DateTime($facilityData['end_datetime']);
        
        $duration = $start->diff($end);
        $durationHours = $duration->h + ($duration->days * 24) + ($duration->i / 60);
        
        $includedHours = $rate->duration ?? 1;
        $baseAmount = $rate->base_price;
        
        $extensionHours = max(0, $durationHours - $includedHours);
        $extensionAmount = $extensionHours * ($rate->extension_fee ?? 0);
        
        $subtotal = $baseAmount + $extensionAmount;

        return GuestEntryFacility::create([
            'guest_entry_id' => $guestEntryId,
            'facility_id' => $facility->id,
            'rate_id' => $rate->id,
            'quantity' => $facilityData['quantity'] ?? 1,
            'start_datetime' => $facilityData['start_datetime'],
            'end_datetime' => $facilityData['end_datetime'],
            'duration_hours' => round($durationHours, 2),
            'base_amount' => $baseAmount,
            'extension_hours' => round($extensionHours, 2),
            'extension_amount' => $extensionAmount,
            'subtotal' => $subtotal,
        ]);
    }

    private function processFacilityExtension(array $extension)
    {
        $facility = GuestEntryFacility::findOrFail($extension['id']);
        $additionalHours = $extension['additional_hours'];
        $extensionFee = $facility->rate->extension_fee ?? 0;
        $additionalAmount = $additionalHours * $extensionFee;

        $facility->update([
            'extension_hours' => $facility->extension_hours + $additionalHours,
            'extension_amount' => $facility->extension_amount + $additionalAmount,
            'subtotal' => $facility->base_amount + $facility->extension_amount + $additionalAmount,
        ]);
    }

    private function calculateDiscountAmount($discount, $amount)
    {
        if ($discount->type === 'Percentage') {
            return ($amount * $discount->value) / 100;
        }
        return min($discount->value, $amount);
    }
}