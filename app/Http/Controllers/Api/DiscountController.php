<?php

namespace App\Http\Controllers\Api;

use App\Models\Discount;
use App\Http\Resources\DiscountResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DiscountController extends Controller
{
    public function archived(Request $request)
    {
        $query = Discount::onlyTrashed();

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by guest type discount
        if ($request->has('is_guest_type_discount')) {
            $query->where('is_guest_type_discount', $request->boolean('is_guest_type_discount'));
        }

        if ($request->boolean('active_only')) {
            $today = now()->toDateString();
            $query->where(function ($q) use ($today) {
                $q->whereNull('valid_from')
                ->orWhere(function ($q2) use ($today) {
                    $q2->where('valid_from', '<=', $today)
                        ->where('valid_until', '>=', $today);
                });
            });
        }

        $discounts = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            DiscountResource::collection($discounts),
            'Archived discounts retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = Discount::query();

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by guest type discount
        if ($request->has('is_guest_type_discount')) {
            $query->where('is_guest_type_discount', $request->boolean('is_guest_type_discount'));
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $discounts = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            DiscountResource::collection($discounts),
            'Discounts retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:100|unique:discounts',
            'description' => 'nullable|string|max:500',
            'category' => 'required|in:Seasonal_Discount,Direct_Discount',
            'type' => 'required|in:Percentage,Fixed_Amount',
            'value' => 'required|numeric|min:0',
            'is_guest_type_discount' => 'boolean',
            'valid_from' => 'nullable|date|after_or_equal:today',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ];

        if ($request->category === 'Seasonal_Discount') {
            $rules['valid_from'] = 'required|date|after_or_equal:today';
            $rules['valid_until'] = 'required|date|after_or_equal:valid_from';
        }

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first()
            ], 422);
        }

        if ($validated['type'] === 'Percentage' && $validated['value'] > 100) {
            return $this->errorResponse('Percentage value cannot exceed 100%', 422);
        }

        if ($validated['category'] === 'Direct_Discount') {
            $validated['valid_from'] = null;
            $validated['valid_until'] = null;
        }

        $discount = Discount::create($validated);

        return $this->successResponse(
            new DiscountResource($discount),
            'Discount created successfully',
            201
        );
    }


    public function show(Discount $discount)
    {
        return $this->successResponse(
            new DiscountResource($discount),
            'Discount retrieved successfully'
        );
    }

    public function update(Request $request, Discount $discount)
    {
        $rules = [
            'name' => 'required|string|max:100|unique:discounts,name,' . $discount->id,
            'description' => 'nullable|string|max:500',
            'category' => 'required|in:Seasonal_Discount,Direct_Discount',
            'type' => 'required|in:Percentage,Fixed_Amount',
            'value' => 'required|numeric|min:0',
            'is_guest_type_discount' => 'boolean',
            'valid_from' => 'nullable|date|after_or_equal:today',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ];

        if ($request->category === 'Seasonal_Discount') {
            $rules['valid_from'] = 'required|date|after_or_equal:today';
            $rules['valid_until'] = 'required|date|after:valid_from';
        }

        $validated = $request->validate($rules);

        if ($validated['category'] === 'Seasonal_Discount' && Carbon::parse($validated['valid_from'])->lt(Carbon::today())) {
            return $this->errorResponse('valid_from cannot be in the past.', 422);
        }

        if ($validated['category'] === 'Seasonal_Discount' && Carbon::parse($validated['valid_until'])->lt(Carbon::today())) {
            return $this->errorResponse('valid_until cannot be in the past.', 422);
        }

        if ($validated['type'] === 'Percentage' && $validated['value'] > 100) {
            return $this->errorResponse('Percentage value cannot exceed 100%', 422);
        }

        if ($validated['category'] === 'Direct_Discount') {
            $validated['valid_from'] = null;
            $validated['valid_until'] = null;
        }

        $discount->update($validated);

        return $this->successResponse(
            new DiscountResource($discount->fresh()),
            'Discount updated successfully'
        );
    }


    public function destroy(Discount $discount)
    {
        // Check if discount is used
        if ($discount->guestTypes()->exists() || 
            $discount->autoDiscountEntries()->exists() || 
            $discount->manualDiscountEntries()->exists()) {
            return $this->errorResponse(
                'Cannot delete discount. It is being used.',
                422
            );
        }

        $discount->delete();

        return $this->successResponse(
            null,
            'Discount deleted successfully'
        );
    }

    public function calculate(Request $request, Discount $discount)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        $discountAmount = $discount->calculateDiscount($validated['amount']);

        return $this->successResponse([
            'discount_id' => $discount->id,
            'discount_name' => $discount->name,
            'category' => $discount->category,
            'type' => $discount->type,
            'value' => $discount->value,
            'original_amount' => $validated['amount'],
            'discount_amount' => $discountAmount,
            'final_amount' => $validated['amount'] - $discountAmount,
        ]);
    }

    public function restore($id)
    {
        $discount = Discount::onlyTrashed()->findOrFail($id);
        $discount->restore();
        
        return $this->successResponse(
            new DiscountResource($discount->fresh()),
            'Discount restored successfully'
        );
    }
}