<?php

namespace App\Http\Controllers\Api;

use App\Models\Rate;
use App\Http\Resources\RateResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class RateController extends Controller
{
    public function archived(Request $request)
    {
        $query = Rate::onlyTrashed()->with('facility');

        // Filter by facility
        if ($request->filled('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        // Filter by rate category
        if ($request->filled('rate_category')) {
            $query->where('rate_category', $request->rate_category);
        }

        // Filter by rate type
        if ($request->filled('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }

        // Scope filters
        if ($request->filled('entrance_only')) {
            $query->entrance();
        }

        if ($request->filled('facility_only')) {
            $query->facility();
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rate_name', 'like', "%{$search}%");
            });
        }

        $rates = $query->orderBy('deleted_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            RateResource::collection($rates),
            'Archived rates retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = Rate::with('facility');

        // Filter by facility
        if ($request->filled('facility_id')) {
            $query->where('facility_id', $request->facility_id);
        }

        // Filter by rate category
        if ($request->filled('rate_category')) {
            $query->where('rate_category', $request->rate_category);
        }

        // Filter by rate type
        if ($request->filled('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }

        // Scope filters
        if ($request->filled('entrance_only')) {
            $query->entrance();
        }

        if ($request->filled('facility_only')) {
            $query->facility();
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rate_name', 'like', "%{$search}%");
            });
        }

        $rates = $query->orderBy('rate_name')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            RateResource::collection($rates),
            'Rates retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rate_name' => 'required|string|max:100',
            'rate_category' => 'required|in:Entrance,Facility,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'facility_id' => 'nullable|exists:facilities,id',
            'base_price' => 'nullable|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($request) {
            $category = $request->input('rate_category');
            $type = $request->input('rate_type');

            // --- Facility Rates ---
            if ($category === 'Facility') {
                if (!$request->filled('facility_id')) {
                    $validator->errors()->add('facility_id', 'Facility is required for facility rates.');
                }

                if (!$type) {
                    $validator->errors()->add('rate_type', 'Rate type is required for facility rates.');
                }

                // Facility + Day_Based
                if ($type === 'Day_Based') {
                    if (!$request->filled('base_price')) {
                        $validator->errors()->add('base_price', 'Base price is required for day-based facility rates.');
                    }
                }

                // Facility + Time_Based
                if ($type === 'Time_Based') {
                    if (!$request->filled('duration')) {
                        $validator->errors()->add('duration', 'Duration is required for time-based facility rates.');
                    }
                    if (!$request->filled('extension_fee')) {
                        $validator->errors()->add('extension_fee', 'Extension fee is required for time-based facility rates.');
                    }
                    if (!$request->filled('base_price')) {
                        $validator->errors()->add('base_price', 'Base price is required for time-based facility rates.');
                    }
                }
            }

            // --- Entrance or Exclusive ---
            if (in_array($category, ['Entrance', 'Exclusive'])) {
                if ($request->filled('facility_id')) {
                    $validator->errors()->add('facility_id', ucfirst($category).' rate must not have a facility.');
                }
                if ($request->filled('rate_type')) {
                    $validator->errors()->add('rate_type', ucfirst($category).' rate must not have a rate type.');
                }
                if (!$request->filled('base_price')) {
                    $validator->errors()->add('base_price', ucfirst($category).' rate requires a base price.');
                }
            }
        });

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // --- Nullify Irrelevant Fields ---
        switch ($validated['rate_category']) {
            case 'Entrance':
            case 'Exclusive':
                $validated['facility_id'] = null;
                $validated['rate_type'] = null;
                $validated['duration'] = null;
                $validated['extension_fee'] = null;
                break;

            case 'Facility':
                if ($validated['rate_type'] === 'Day_Based') {
                    $validated['duration'] = null;
                    $validated['extension_fee'] = null;
                }
                break;
        }

        $rate = Rate::create($validated);

        return $this->successResponse(
            new RateResource($rate->load('facility')),
            'Rate created successfully',
            201
        );
    }

    public function show(Rate $rate)
    {
        $rate->load('facility');
        return $this->successResponse(
            new RateResource($rate),
            'Rate retrieved successfully'
        );
    }

    public function update(Request $request, Rate $rate)
    {
        $validator = Validator::make($request->all(), [
            'rate_name' => 'required|string|max:100',
            'rate_category' => 'required|in:Entrance,Facility,Exclusive',
            'rate_type' => 'nullable|in:Day_Based,Time_Based',
            'facility_id' => 'nullable|exists:facilities,id',
            'base_price' => 'nullable|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'extension_fee' => 'nullable|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($request) {
            $category = $request->input('rate_category');
            $type = $request->input('rate_type');

            // --- Facility Rates ---
            if ($category === 'Facility') {
                if (!$request->filled('facility_id')) {
                    $validator->errors()->add('facility_id', 'Facility is required for facility rates.');
                }

                if (!$type) {
                    $validator->errors()->add('rate_type', 'Rate type is required for facility rates.');
                }

                // Facility + Day_Based
                if ($type === 'Day_Based') {
                    if (!$request->filled('base_price')) {
                        $validator->errors()->add('base_price', 'Base price is required for day-based facility rates.');
                    }
                }

                // Facility + Time_Based
                if ($type === 'Time_Based') {
                    if (!$request->filled('duration')) {
                        $validator->errors()->add('duration', 'Duration is required for time-based facility rates.');
                    }
                    if (!$request->filled('extension_fee')) {
                        $validator->errors()->add('extension_fee', 'Extension fee is required for time-based facility rates.');
                    }
                    if (!$request->filled('base_price')) {
                        $validator->errors()->add('base_price', 'Base price is required for time-based facility rates.');
                    }
                }
            }

            // --- Entrance or Exclusive ---
            if (in_array($category, ['Entrance', 'Exclusive'])) {
                if ($request->filled('facility_id')) {
                    $validator->errors()->add('facility_id', ucfirst($category).' rate must not have a facility.');
                }
                if ($request->filled('rate_type')) {
                    $validator->errors()->add('rate_type', ucfirst($category).' rate must not have a rate type.');
                }
                if (!$request->filled('base_price')) {
                    $validator->errors()->add('base_price', ucfirst($category).' rate requires a base price.');
                }
            }
        });

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // --- Nullify Irrelevant Fields ---
        switch ($validated['rate_category']) {
            case 'Entrance':
            case 'Exclusive':
                $validated['facility_id'] = null;
                $validated['rate_type'] = null;
                $validated['duration'] = null;
                $validated['extension_fee'] = null;
                break;

            case 'Facility':
                if ($validated['rate_type'] === 'Day_Based') {
                    $validated['duration'] = null;
                    $validated['extension_fee'] = null;
                }
                break;
        }

        $rate->update($validated);

        return $this->successResponse(
            new RateResource($rate->fresh()->load('facility')),
            'Rate updated successfully'
        );
    }


    public function destroy(Rate $rate)
    {
        // Check if rate is used in guest entry details
        if ($rate->guestEntryDetails()->exists()) {
            return $this->errorResponse(
                'Cannot delete rate. It is being used in guest entries.',
                422
            );
        }

        $rate->delete();

        return $this->successResponse(
            null,
            'Rate deleted successfully'
        );
    }

    public function calculateBill(Request $request, Rate $rate)
    {
        $validated = $request->validate([
            'actual_hours' => 'required|numeric|min:0',
        ]);

        $bill = $rate->calculateTimeBill($validated['actual_hours']);

        return $this->successResponse([
            'rate_id' => $rate->id,
            'rate_name' => $rate->rate_name,
            'base_price' => $rate->base_price,
            'actual_hours' => $validated['actual_hours'],
            'calculated_bill' => $bill,
        ]);
    }

    public function restore($id)
    {
        $rate = Rate::onlyTrashed()->findOrFail($id);
        $rate->restore();
        
        return $this->successResponse(
            new RateResource($rate->fresh()->load('facility')),
            'Rate restored successfully'
        );
    }
}