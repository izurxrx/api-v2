<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rate\StoreRateRequest;
use App\Http\Requests\Rate\UpdateRateRequest;
use App\Http\Resources\RateResource;
use App\Models\Rate;
use Illuminate\Http\Request;

class RateController extends Controller
{
    public function index(Request $request)
    {
        $query = Rate::with('facility.facilityType');

        if ($request->has('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        if ($request->has('rate_category')) {
            $query->where('rate_category', $request->rate_category);
        }

        if ($request->has('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }
        
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->input('per_page', 5);
        $rates = $query->paginate($perPage);

        return RateResource::collection($rates)->additional([
            'status' => 'success',
            'message' => 'Rates retrieved successfully',
        ]);
    }

    public function store(StoreRateRequest $request)
    {
        $validated = $request->validated();
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

    public function update(UpdateRateRequest $request, $id)
    {
        $rate = Rate::findOrFail($id);
        $validated = $request->validated();

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

        return RateResource::collection($rates)->additional([
            'status' => 'success',
            'message' => 'Archived rates retrieved successfully',
        ]);
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
