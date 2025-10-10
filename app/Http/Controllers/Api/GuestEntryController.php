<?php

namespace App\Http\Controllers\Api;

use App\Models\GuestEntry;
use App\Models\GuestEntryDetail;
use App\Http\Resources\GuestEntryResource;
use App\Http\Resources\GuestEntryCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class GuestEntryController extends Controller
{
    public function archived(Request $request)
    {
        $query = GuestEntry::onlyTrashed()->with(['creator', 'details.rate', 'details.guestType']);

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $guestEntries = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            new GuestEntryCollection($guestEntries),
            'Archived guest entries retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = GuestEntry::with(['creator', 'details.rate', 'details.guestType']);

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        // Filter by today
        if ($request->boolean('today_only')) {
            $query->today();
        }

        // Filter by active entries
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filter by payment status
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by checked out status
        if ($request->has('is_checked_out')) {
            $query->where('is_checked_out', $request->boolean('is_checked_out'));
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $guestEntries = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            new GuestEntryCollection($guestEntries),
            'Guest entries retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'entry_date' => 'required|date',
            'entry_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:1000',
            'details' => 'required|array|min:1',
            'details.*.rate_id' => 'required|exists:rates,id',
            'details.*.guest_type_id' => 'required|exists:guest_types,id',
            'details.*.guest_count' => 'required|integer|min:1',
            'details.*.auto_discount_id' => 'nullable|exists:discounts,id',
        ]);

        DB::beginTransaction();
        
        try {
            // Generate entry reference
            $entryReference = $this->generateEntryReference();

            // Calculate totals
            $totalGuests = collect($validated['details'])->sum('guest_count');

            // Create guest entry
            $guestEntry = GuestEntry::create([
                'entry_reference' => $entryReference,
                'entry_date' => $validated['entry_date'],
                'entry_time' => $validated['entry_time'] ?? now()->format('H:i:s'),
                'guest_name' => $validated['guest_name'],
                'contact_number' => $validated['contact_number'],
                'total_guests' => $totalGuests,
                'subtotal' => 0,
                'discount_amount' => 0,
                'total_amount' => 0,
                'payment_status' => 'Unpaid',
                'amount_paid' => 0,
                'balance' => 0,
                'is_checked_out' => false,
                'notes' => $validated['notes'],
                'created_by' => Auth::id(),
            ]);

            // Create guest entry details and calculate totals
            $subtotal = 0;
            $totalDiscountAmount = 0;

            foreach ($validated['details'] as $detailData) {
                $rate = \App\Models\Rate::find($detailData['rate_id']);
                $guestType = \App\Models\GuestType::find($detailData['guest_type_id']);
                
                $baseRate = $rate->base_price;
                $lineSubtotal = $baseRate * $detailData['guest_count'];
                $lineDiscountAmount = 0;

                // Apply auto discount from guest type
                if ($guestType->default_discount_id) {
                    $discount = $guestType->defaultDiscount;
                    $lineDiscountAmount += $discount->calculateDiscount($lineSubtotal);
                }

                // Apply additional auto discount if specified
                if (!empty($detailData['auto_discount_id'])) {
                    $autoDiscount = \App\Models\Discount::find($detailData['auto_discount_id']);
                    $lineDiscountAmount += $autoDiscount->calculateDiscount($lineSubtotal);
                }

                GuestEntryDetail::create([
                    'guest_entry_id' => $guestEntry->id,
                    'rate_id' => $detailData['rate_id'],
                    'guest_type_id' => $detailData['guest_type_id'],
                    'guest_count' => $detailData['guest_count'],
                    'base_rate' => $baseRate,
                    'auto_discount_id' => $detailData['auto_discount_id'] ?? null,
                ]);

                $subtotal += $lineSubtotal;
                $totalDiscountAmount += $lineDiscountAmount;
            }

            $totalAmount = $subtotal - $totalDiscountAmount;

            // Update guest entry totals
            $guestEntry->update([
                'subtotal' => $subtotal,
                'discount_amount' => $totalDiscountAmount,
                'total_amount' => $totalAmount,
                'balance' => $totalAmount,
            ]);

            DB::commit();

            return $this->successResponse(
                new GuestEntryResource($guestEntry->load(['creator', 'details.rate', 'details.guestType'])),
                'Guest entry created successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to create guest entry: ' . $e->getMessage(), 500);
        }
    }

    public function show(GuestEntry $guestEntry)
    {
        $guestEntry->load(['creator', 'details.rate', 'details.guestType', 'details.autoDiscount', 'payments']);
        return $this->successResponse(
            new GuestEntryResource($guestEntry),
            'Guest entry retrieved successfully'
        );
    }

    public function update(Request $request, GuestEntry $guestEntry)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'entry_date' => 'required|date',
            'entry_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:1000',
        ]);

        $guestEntry->update($validated);

        return $this->successResponse(
            new GuestEntryResource($guestEntry->fresh()->load(['creator', 'details.rate', 'details.guestType'])),
            'Guest entry updated successfully'
        );
    }

    public function destroy(GuestEntry $guestEntry)
    {
        // Check if entry has payments
        if ($guestEntry->payments()->exists()) {
            return $this->errorResponse(
                'Cannot delete guest entry. It has associated payments.',
                422
            );
        }

        $guestEntry->delete();

        return $this->successResponse(
            null,
            'Guest entry deleted successfully'
        );
    }

    public function checkout(GuestEntry $guestEntry)
    {
        if ($guestEntry->is_checked_out) {
            return $this->errorResponse('Guest entry is already checked out', 422);
        }

        $guestEntry->update(['is_checked_out' => true]);

        return $this->successResponse(
            new GuestEntryResource($guestEntry->fresh()),
            'Guest checked out successfully'
        );
    }

    public function addManualDiscount(Request $request, GuestEntry $guestEntry)
    {
        $validated = $request->validate([
            'detail_id' => 'required|exists:guest_entry_details,id',
            'discount_id' => 'required|exists:discounts,id',
        ]);

        $detail = GuestEntryDetail::where('id', $validated['detail_id'])
                                ->where('guest_entry_id', $guestEntry->id)
                                ->first();

        if (!$detail) {
            return $this->errorResponse('Guest entry detail not found', 404);
        }

        $detail->update(['manual_discount_id' => $validated['discount_id']]);

        // Recalculate totals
        $this->recalculateGuestEntryTotals($guestEntry);

        return $this->successResponse(
            new GuestEntryResource($guestEntry->fresh()->load(['details.rate', 'details.guestType'])),
            'Manual discount applied successfully'
        );
    }

    private function generateEntryReference()
    {
        $date = now()->format('Ymd');
        $lastEntry = GuestEntry::whereDate('created_at', today())
                              ->orderBy('id', 'desc')
                              ->first();

        $sequence = $lastEntry ? (int) substr($lastEntry->entry_reference, -3) + 1 : 1;

        return 'EN' . $date . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    private function recalculateGuestEntryTotals(GuestEntry $guestEntry)
    {
        $details = $guestEntry->details()->with(['rate', 'guestType.defaultDiscount', 'autoDiscount', 'manualDiscount'])->get();
        
        $subtotal = 0;
        $totalDiscountAmount = 0;

        foreach ($details as $detail) {
            $lineSubtotal = $detail->base_rate * $detail->guest_count;
            $lineDiscountAmount = 0;

            // Auto discount from guest type
            if ($detail->guestType->defaultDiscount) {
                $lineDiscountAmount += $detail->guestType->defaultDiscount->calculateDiscount($lineSubtotal);
            }

            // Additional auto discount
            if ($detail->autoDiscount) {
                $lineDiscountAmount += $detail->autoDiscount->calculateDiscount($lineSubtotal);
            }

            // Manual discount
            if ($detail->manualDiscount) {
                $lineDiscountAmount += $detail->manualDiscount->calculateDiscount($lineSubtotal);
            }

            $subtotal += $lineSubtotal;
            $totalDiscountAmount += $lineDiscountAmount;
        }

        $totalAmount = $subtotal - $totalDiscountAmount;
        $balance = $totalAmount - $guestEntry->amount_paid;

        $guestEntry->update([
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscountAmount,
            'total_amount' => $totalAmount,
            'balance' => $balance,
        ]);
    }
}