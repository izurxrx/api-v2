<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facility\StoreFacilityRequest;
use App\Http\Requests\Facility\UpdateFacilityRequest;
use App\Http\Resources\FacilityResource;
use App\Models\Facility;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    public function index(Request $request)
    {
        $query = Facility::with('facilityType');

        if ($request->has('facility_type_id')) {
            $query->where('facility_type_id', $request->facility_type_id);
        }

        if ($request->has('requires_entrance')) {
            $query->where('requires_entrance', $request->requires_entrance);
        }

        // ADD THIS: Get only walk-in cottages
        if ($request->has('walk_in_only')) {
            $query->availableForWalkIn();
        }

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $perPage = $request->input('per_page', 5);

        if ($perPage == 'all' || (is_numeric($perPage) && (int)$perPage <= 0)) {
            $facilities = $query->get();
            return response()->json([
                'data' => FacilityResource::collection($facilities),
            ]);
        }
        
        $facilities = $query->paginate($perPage);

        return FacilityResource::collection($facilities)->additional([
            'status' => 'success',
            'message' => 'Facilities retrieved successfully',
        ]);
    }

    public function store(StoreFacilityRequest $request)
    {
        $validated = $request->validated();
        $facility = Facility::create($validated);
        $facility->load('facilityType');

        return response()->json([
            'message' => 'Facility created successfully',
            'data' => new FacilityResource($facility),
        ], 201);
    }

    public function show($id)
    {
        $facility = Facility::with('facilityType', 'rates')->findOrFail($id);

        return response()->json([
            'data' => new FacilityResource($facility),
        ]);
    }

    public function update(UpdateFacilityRequest $request, $id)
    {
        $facility = Facility::findOrFail($id);
        $validated = $request->validated();

        $facility->update($validated);
        $facility->load('facilityType');

        return response()->json([
            'message' => 'Facility updated successfully',
            'data' => new FacilityResource($facility),
        ]);
    }

    public function destroy($id)
    {
        $facility = Facility::findOrFail($id);
        $facility->delete();

        return response()->json([
            'message' => 'Facility deleted successfully',
        ]);
    }

    public function archived()
    {
        $facilities = Facility::onlyTrashed()->with('facilityType')->paginate(5);

        return FacilityResource::collection($facilities)->additional([
            'status' => 'success',
            'message' => 'Archived facilities retrieved successfully',
        ]);
    }

    public function restore($id)
    {
        $facility = Facility::onlyTrashed()->findOrFail($id);
        $facility->restore();
        
        return response()->json([
            'message' => 'Facility restored successfully',
            'data' => new FacilityResource($facility),
        ]);
    }

    // ADD THIS NEW METHOD
    public function getWalkInFacilities()
    {
        $facilities = Facility::with('facilityType', 'rates')
            ->availableForWalkIn()
            ->get();

        return response()->json([
            'data' => FacilityResource::collection($facilities),
        ]);
    }
}
