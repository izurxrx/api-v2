<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FacilityType\StoreFacilityTypeRequest;
use App\Http\Requests\FacilityType\UpdateFacilityTypeRequest;
use App\Http\Resources\FacilityTypeResource;
use App\Models\FacilityType;
use Illuminate\Http\Request;

class FacilityTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = FacilityType::withCount('facilities');

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $perPage = $request->input('per_page', 5);
        $facilityTypes = $query->paginate($perPage);

        return FacilityTypeResource::collection($facilityTypes)->additional([
            'status' => 'success',
            'message' => 'Facility types retrieved successfully',
        ]);
    }

    public function store(StoreFacilityTypeRequest $request)
    {
        $validated = $request->validated();
        $facilityType = FacilityType::create($validated);

        return response()->json([
            'message' => 'Facility type created successfully',
            'data' => new FacilityTypeResource($facilityType),
        ], 201);
    }

    public function show($id)
    {
        $facilityType = FacilityType::with('facilities')->findOrFail($id);

        return response()->json([
            'data' => new FacilityTypeResource($facilityType),
        ]);
    }

    public function update(UpdateFacilityTypeRequest $request, $id)
    {
        $facilityType = FacilityType::findOrFail($id);
        $validated = $request->validated();

        $facilityType->update($validated);

        return response()->json([
            'message' => 'Facility type updated successfully',
            'data' => new FacilityTypeResource($facilityType),
        ]);
    }

    public function destroy($id)
    {
        $facilityType = FacilityType::findOrFail($id);
        
        if ($facilityType->facilities()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete facility type with existing facilities',
            ], 400);
        }

        $facilityType->delete();

        return response()->json([
            'message' => 'Facility type deleted successfully',
        ]);
    }

    public function archived()
    {
        $facilityTypes = FacilityType::onlyTrashed()->withCount('facilities')->paginate(5);

        return FacilityTypeResource::collection($facilityTypes)->additional([
            'status' => 'success',
            'message' => 'Archived facility types retrieved successfully',
        ]);
    }

    public function restore($id)
    {
        $facilityType = FacilityType::onlyTrashed()->findOrFail($id);
        $facilityType->restore();

        return response()->json([
            'message' => 'Facility type restored successfully',
            'data' => new FacilityTypeResource($facilityType),
        ]);
    }
}