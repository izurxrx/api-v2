<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discount\StoreDiscountRequest;
use App\Http\Requests\Discount\UpdateDiscountRequest;
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

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $perPage = $request->input('per_page', 5);
        $discounts = $query->paginate($perPage);

        return DiscountResource::collection($discounts)->additional([
            'status' => 'success',
            'message' => 'Discounts retrieved successfully',
        ]);
    }

    public function store(StoreDiscountRequest $request)
    {
        $validated = $request->validated();
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

    public function update(UpdateDiscountRequest $request, $id)
    {
        $discount = Discount::findOrFail($id);
        $validated = $request->validated();

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

        return DiscountResource::collection($discounts)->additional([
            'status' => 'success',
            'message' => 'Archived discounts retrieved successfully',
        ]);
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