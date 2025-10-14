<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $query = Discount::query();

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('is_guest_type_discount')) {
            $query->where('is_guest_type_discount', $request->boolean('is_guest_type_discount'));
        }

        if ($request->has('active_only')) {
            $now = now();
            $query->where(function ($q) use ($now) {
                $q->whereNull('valid_from')
                  ->orWhere('valid_from', '<=', $now);
            })->where(function ($q) use ($now) {
                $q->whereNull('valid_until')
                  ->orWhere('valid_until', '>=', $now);
            });
        }

        $perPage = $request->input('per_page', 5);
        $discounts = $query->paginate($perPage);

        return $this->paginatedCollection($discounts, DiscountResource::class);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'category' => 'required|in:Seasonal_Discount,Direct_Discount',
            'type' => 'required|in:Percentage,Fixed_Amount',
            'value' => 'required|numeric|min:0',
            'is_guest_type_discount' => 'boolean',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ]);

        $discount = Discount::create($validated);

        return response()->json([
            'message' => 'Discount created successfully',
            'data' => new DiscountResource($discount),
        ], 201);
    }

    public function show($id)
    {
        $discount = Discount::findOrFail($id);

        return response()->json([
            'data' => new DiscountResource($discount),
        ]);
    }

    public function update(Request $request, $id)
    {
        $discount = Discount::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:Seasonal_Discount,Direct_Discount',
            'type' => 'sometimes|in:Percentage,Fixed_Amount',
            'value' => 'sometimes|numeric|min:0',
            'is_guest_type_discount' => 'boolean',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ]);

        $discount->update($validated);

        return response()->json([
            'message' => 'Discount updated successfully',
            'data' => new DiscountResource($discount),
        ]);
    }

    public function destroy($id)
    {
        $discount = Discount::findOrFail($id);
        $discount->delete();

        return response()->json([
            'message' => 'Discount deleted successfully',
        ]);
    }

    public function archived()
    {
        $discounts = Discount::onlyTrashed()->paginate(5);

        return $this->paginatedCollection($discounts, DiscountResource::class);
    }

    public function restore($id)
    {
        $discount = Discount::onlyTrashed()->findOrFail($id);
        $discount->restore();
        
        return response()->json([
            'message' => 'Discount restored successfully',
            'data' => new DiscountResource($discount),
        ]);

    }
}