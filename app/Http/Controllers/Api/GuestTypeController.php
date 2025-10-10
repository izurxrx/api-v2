<?php

namespace App\Http\Controllers\Api;

use App\Models\GuestType;
use App\Http\Resources\GuestTypeResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class GuestTypeController extends Controller
{
    public function archived(Request $request)
    {
        $query = GuestType::onlyTrashed()->with('defaultDiscount');

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $guestTypes = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            GuestTypeResource::collection($guestTypes),
            'Archived guest types retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = GuestType::with('defaultDiscount');

        // Filter by has auto discount
        if ($request->has('has_auto_discount')) {
            if ($request->boolean('has_auto_discount')) {
                $query->whereNotNull('default_discount_id');
            } else {
                $query->whereNull('default_discount_id');
            }
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $guestTypes = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return GuestTypeResource::collection($guestTypes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:guest_types',
            'description' => 'nullable|string|max:500',
            'default_discount_id' => 'nullable|exists:discounts,id',
        ]);

        // Validate that the discount is a guest type discount
        if ($validated['default_discount_id']) {
            $discount = \App\Models\Discount::find($validated['default_discount_id']);
            if (!$discount->is_guest_type_discount) {
                return $this->errorResponse(
                    'Selected discount is not applicable for guest types',
                    422
                );
            }
        }

        $guestType = GuestType::create($validated);

        return $this->successResponse(
            new GuestTypeResource($guestType->load('defaultDiscount')),
            'Guest type created successfully',
            201
        );
    }

    public function show(GuestType $guestType)
    {
        $guestType->load('defaultDiscount');
        return new GuestTypeResource($guestType);
    }

    public function update(Request $request, GuestType $guestType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:guest_types,name,' . $guestType->id,
            'description' => 'nullable|string|max:500',
            'default_discount_id' => 'nullable|exists:discounts,id',
        ]);

        // Validate that the discount is a guest type discount
        if ($validated['default_discount_id']) {
            $discount = \App\Models\Discount::find($validated['default_discount_id']);
            if (!$discount->is_guest_type_discount) {
                return $this->errorResponse(
                    'Selected discount is not applicable for guest types',
                    422
                );
            }
        }

        $guestType->update($validated);

        return $this->successResponse(
            new GuestTypeResource($guestType->fresh()->load('defaultDiscount')),
            'Guest type updated successfully'
        );
    }

    public function destroy(GuestType $guestType)
    {
        // Check if guest type is used in guest entry details
        if ($guestType->guestEntryDetails()->exists()) {
            return $this->errorResponse(
                'Cannot delete guest type. It is being used in guest entries.',
                422
            );
        }

        $guestType->delete();

        return $this->successResponse(
            null,
            'Guest type deleted successfully'
        );
    }
}