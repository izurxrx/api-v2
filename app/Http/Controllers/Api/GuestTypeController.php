<?php

// ============================================
// app/Http/Controllers/Api/GuestTypeController.php
// ============================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestTypeResource;
use App\Models\GuestType;
use Illuminate\Http\Request;

class GuestTypeController extends Controller
{
    public function index()
    {
        $guestTypes = GuestType::with('defaultDiscount')->get();

        return response()->json([
            'data' => GuestTypeResource::collection($guestTypes),
        ]);
    }

    public function show($id)
    {
        $guestType = GuestType::with('defaultDiscount')->findOrFail($id);

        return response()->json([
            'data' => new GuestTypeResource($guestType),
        ]);
    }
}