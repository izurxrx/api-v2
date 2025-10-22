<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestEntry\StoreGuestEntryRequest;
use App\Http\Requests\GuestEntry\UpdateGuestEntryRequest;
use App\Http\Requests\GuestEntry\CheckoutGuestEntryRequest;
use App\Http\Resources\GuestEntryResource;
use App\Models\Discount;
use App\Models\Facility;
use App\Models\GuestEntry;
use App\Models\GuestEntryDetail;
use App\Models\GuestEntryFacility;
use App\Models\Payment;
use App\Models\ThirdPartyService;
use App\Models\Rate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuestMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $query = GuestEntry::with([
            'details.rate',
            'details.discount',
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'createdBy',
            'payments'
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

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
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
            
            // Calculate total guests from details
            $totalGuests = collect($validated['details'])->sum('guest_count');
            
            // Create main entry
            $guestEntry = GuestEntry::create([
                'entry_reference' => $reference,
                'entry_date' => $validated['entry_date'],
                'entry_time' => $validated['entry_time'] ?? now()->format('H:i:s'),
                'check_in_datetime' => now(),
                'discount_mode' => $validated['discount_mode'],
                'discount_id' => $validated['discount_id'] ?? null,
                'manual_discount_amount' => $validated['manual_discount_amount'] ?? 0.00,
                'guest_name' => $validated['guest_name'],
                'contact_number' => $validated['contact_number'] ?? null,
                'total_guests' => $totalGuests,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Process guest details (entrance fees)
            $entranceSubtotal = 0;
            $totalDetailDiscounts = 0;
            
            foreach ($validated['details'] as $detail) {
                $detailRecord = $this->createGuestDetail($guestEntry->id, $detail);
                $entranceSubtotal += $detailRecord->total_amount;
                $totalDetailDiscounts += $detailRecord->discount_amount;
            }

            // Process facility rentals (if any)
            $facilitySubtotal = 0;
            if (isset($validated['facilities']) && count($validated['facilities']) > 0) {
                foreach ($validated['facilities'] as $facility) {
                    $rental = $this->createFacilityRental($guestEntry->id, $facility);
                    $facilitySubtotal += $rental->subtotal;
                }
            }

            // Process third-party services (if any)
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

            // Calculate final amounts
            $subtotal = $entranceSubtotal + $facilitySubtotal;
            $discountAmount = 0;

            // Apply entry-level discount
            if ($validated['discount_mode'] === 'Seasonal' && $validated['discount_id']) {
                $discount = Discount::findOrFail($validated['discount_id']);
                // Apply seasonal discount only to entrance fees
                $discountAmount = $this->calculateDiscountAmount($discount, $entranceSubtotal);
            } 
            elseif ($validated['discount_mode'] === 'Manual' && isset($validated['manual_discount_amount'])) {
                $discountAmount = $validated['manual_discount_amount'];
            } 
            elseif ($validated['discount_mode'] === 'Direct') {
                // Direct discounts already applied in entrance_subtotal
                $discountAmount = 0;
            }

            $totalAmount = $subtotal - $discountAmount + $thirdPartyAmount;

            // ✅ VALIDATE: Payment must be provided
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
                    'required_amount' => $totalAmount
                ], 422);
            }

            $paymentAmount = $validated['payment']['amount_paid'] ?? 0;
            $paymentMethod = $validated['payment']['method'] ?? 'Cash';

            // ✅ VALIDATE: Full payment required
            if ($paymentAmount < $totalAmount) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Full payment is required for check-in',
                    'errors' => [
                        'payment.amount' => [
                            sprintf(
                                'Payment amount (₱%.2f) is less than total amount (₱%.2f). Full payment of ₱%.2f is required.',
                                $paymentAmount,
                                $totalAmount,
                                $totalAmount
                            )
                        ]
                    ],
                    'total_amount' => $totalAmount,
                    'payment_amount' => $paymentAmount,
                    'balance' => $totalAmount - $paymentAmount
                ], 422);
            }

            // ✅ CREATE PAYMENT RECORD
            $paymentReference = $this->generatePaymentReference();
            
            Payment::create([
                'transaction_reference' => $paymentReference,
                'transaction_type' => 'GuestEntry',
                'transaction_id' => $guestEntry->id,
                'payment_date' => now()->toDateString(),
                'payment_time' => now()->toTimeString(),
                'payment_method' => $paymentMethod,
                'amount_paid' => $paymentAmount,
                'change_amount' => max(0, $paymentAmount - $totalAmount),
                'received_by' => auth()->id(),
                'payment_reference' => $validated['payment']['reference'] ?? null,
                'notes' => $validated['payment']['notes'] ?? null,
            ]);

            // ✅ UPDATE GUEST ENTRY WITH TOTALS AND PAYMENT INFO
            $guestEntry->update([
                'entrance_subtotal' => $entranceSubtotal,
                'facility_subtotal' => $facilitySubtotal,
                'third_party_service_amount' => $thirdPartyAmount,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $paymentAmount,
                'balance' => max(0, $totalAmount - $paymentAmount), // Should always be 0
                'payment_status' => $paymentAmount >= $totalAmount ? 'Paid' : 'Partial', // Should always be 'Paid'
                'payment_method' => $paymentMethod,
            ]);

            DB::commit();

            $guestEntry->load([
                'details.rate',
                'details.discount',
                'facilities.facility.facilityType',
                'facilities.rate',
                'thirdPartyServices',
                'createdBy',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Guest entry created successfully. Guest has been checked in and payment has been recorded.',
                'data' => new GuestEntryResource($guestEntry),
                'payment_reference' => $paymentReference,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Failed to create guest entry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user' => auth()->id(),
                'request' => $request->except('password')
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create guest entry',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while processing your request',
            ], 500);
        }
    }

    // Helper method to generate payment reference
    private function generatePaymentReference(): string
    {
        $date = now()->format('Ymd');
        $lastPayment = Payment::whereDate('created_at', today())
            ->where('transaction_reference', 'like', "PAY{$date}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int)substr($lastPayment->transaction_reference, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "PAY{$date}{$newNumber}";
    }

    public function show($id)
    {
        $entry = GuestEntry::with([
            'details.rate',
            'details.discount',
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'payments.receivedBy',
            'createdBy',
        ])->findOrFail($id);

        return response()->json([
            'data' => new GuestEntryResource($entry),
        ]);
    }

    public function update(UpdateGuestEntryRequest $request, $id)
    {
        $entry = GuestEntry::findOrFail($id);
        $validated = $request->validated();

        $entry->update($validated);

        if (isset($validated['discount_amount'])) {
            $entry->recalculateTotals();
        }

        $entry->load([
            'details.rate',
            'details.discount',
            'facilities.facility',
            'thirdPartyServices',
            'createdBy',
        ]);

        return response()->json([
            'message' => 'Guest entry updated successfully',
            'data' => new GuestEntryResource($entry),
        ]);
    }

    public function checkout(Request $request, $id)
    {
        // ✅ Use Form Request for validation
        $validated = app(CheckoutGuestEntryRequest::class)->validated();
        
        $entry = GuestEntry::with('payments')->findOrFail($id);

        // ✅ Double-check (redundant but safe)
        if ($entry->is_checked_out) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest already checked out',
            ], 400);
        }

        return DB::transaction(function () use ($entry, $validated) {
            try {

                // ✅ Update checkout info
                $entry->update([
                    'is_checked_out' => true,
                    'checkout_datetime' => now(),
                    'exit_date' => $validated['exit_date'],
                    'exit_time' => $validated['exit_time'],
                    'notes' => $validated['notes'] ?? $entry->notes,
                ]);

                // ✅ Log checkout action
                \Log::info('Guest checked out', [
                    'entry_id' => $entry->id,
                    'reference' => $entry->entry_reference,
                    'checked_out_by' => auth()->id(),
                    'exit_datetime' => "{$validated['exit_date']} {$validated['exit_time']}",
                    'final_payment' => $validated['payment_amount'] ?? 0,
                    'final_balance' => $entry->balance,
                ]);

                // ✅ Load relationships for response
                $entry->load([
                    'details.rate',
                    'details.discount',
                    'facilities.facility.facilityType',
                    'facilities.rate',
                    'thirdPartyServices',
                    'payments.receivedBy',
                    'createdBy',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Guest checked out successfully',
                    'data' => new GuestEntryResource($entry),
                ]);

            } catch (\Exception $e) {
                \Log::error('Checkout failed', [
                    'entry_id' => $entry->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e; // Let transaction rollback
            }
        });
    }

    public function destroy($id)
    {
        $entry = GuestEntry::findOrFail($id);
        $entry->delete();

        return response()->json([
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
            'message' => 'Guest entry restored successfully',
            'data' => new GuestEntryResource($entry),
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
        
        $discountId = $guestData['discount_id'] ?? null;
        $discountAmount = 0;

        // Apply Direct discount if provided
        if ($guestData['discount_mode'] === 'Direct' && $discountId) {
            $discount = Discount::findOrFail($discountId);
            $discountAmount = $this->calculateDiscountAmount($discount, $baseRate);
        }

        $finalRate = $baseRate - $discountAmount;
        $totalAmount = $finalRate * $guestData['guest_count'];

        return GuestEntryDetail::create([
            'guest_entry_id' => $guestEntryId,
            'guest_type_name' => $guestData['guest_type_name'],
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
        // Validate required keys
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