<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RateResource;
use App\Models\Rate;
use Illuminate\Http\Request;

class RateController extends Controller
{
    public function index(Request $request)
    {
        $query = Rate::with('facility.facilityType');

        //Filter by facility
        if ($request->has('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        //Filter by rate category
        if ($request->has('rate_category')) {
            $query->where('rate_category', $request->rate_category);
        }

        //Filter by rate type
        if ($request->has('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }
        
        //Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        //Pagination
        $perPage = $request->input('per_page', 5);
        $rates = $query->paginate($perPage);

        return $this->paginatedCollection($rates, RateResource::class);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'facility_id' => 'nullable|exists:facilities,id',
            'rate_name' => 'required|string|max:100',
            'rate_category' => 'required|in:Facility,Entrance,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'base_price' => 'required|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
        ]);

        $rate = Rate::create($validated);
        $rate->load('facility.facilityType');

        return response()->json([
            'message' => 'Rate created successfully',
            'data' => new RateResource($rate),
        ], 201);
    }

    public function show($id)
    {
        $rate = Rate::with('facility.facilityType')->findOrFail($id);

        return response()->json([
            'data' => new RateResource($rate),
        ]);
    }

    public function update(Request $request, $id)
    {
        $rate = Rate::findOrFail($id);

        $validated = $request->validate([
            'facility_id' => 'nullable|exists:facilities,id',
            'rate_name' => 'sometimes|string|max:100',
            'rate_category' => 'sometimes|in:Facility,Entrance,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'base_price' => 'sometimes|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
        ]);

        $rate->update($validated);
        $rate->load('facility.facilityType');

        return response()->json([
            'message' => 'Rate updated successfully',
            'data' => new RateResource($rate),
        ]);
    }

    public function destroy($id)
    {
        $rate = Rate::findOrFail($id);
        $rate->delete();

        return response()->json([
            'message' => 'Rate deleted successfully',
        ]);
    }

    public function archived(Request $request)
    {
        $perPage = $request->input('per_page', 5);
        
        $rates = Rate::onlyTrashed()
            ->with([
                'facility' => function($query) {
                    $query->withTrashed();
                },
                'facility.facilityType' => function($query) {
                    $query->withTrashed();
                }
            ])
            ->paginate($perPage);

        return $this->paginatedCollection($rates, RateResource::class);
    }

    public function restore($id)
    {
        $rate = Rate::onlyTrashed()->findOrFail($id);
        $rate->restore();

        return response()->json([
            'message' => 'Rate restored successfully',
            'data' => new RateResource($rate),
        ]);
    }
}