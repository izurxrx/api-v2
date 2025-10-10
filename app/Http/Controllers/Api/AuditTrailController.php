<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditTrail;
use App\Http\Resources\AuditTrailResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditTrail::with('user');

        // Filter by table name
        if ($request->filled('table_name')) {
            $query->where('table_name', $request->table_name);
        }

        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by record id
        if ($request->filled('record_id')) {
            $query->where('record_id', $request->record_id);
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $auditTrails = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            AuditTrailResource::collection($auditTrails),
            'Audit trails retrieved successfully'
        );
    }

    public function show(AuditTrail $auditTrail)
    {
        $auditTrail->load('user');
        return $this->successResponse(
            new AuditTrailResource($auditTrail),
            'Audit trail retrieved successfully'
        );
    }

    public function getTableNames()
    {
        $tables = AuditTrail::select('table_name')
                           ->distinct()
                           ->orderBy('table_name')
                           ->pluck('table_name');

        return $this->successResponse($tables);
    }

    public function getActions()
    {
        return $this->successResponse([
            'INSERT',
            'UPDATE', 
            'DELETE'
        ]);
    }

    public function getRecordHistory(Request $request)
    {
        $validated = $request->validate([
            'table_name' => 'required|string',
            'record_id' => 'required|integer',
        ]);

        $history = AuditTrail::with('user')
                            ->where('table_name', $validated['table_name'])
                            ->where('record_id', $validated['record_id'])
                            ->orderBy('created_at', 'desc')
                            ->get();

        return $this->successResponse(
            AuditTrailResource::collection($history),
            'Record history retrieved successfully'
        );
    }

    /**
     * Create audit trail entry (used internally by model events)
     */
    public static function log($tableName, $recordId, $action, $oldValues = null, $newValues = null, $userId = null)
    {
        AuditTrail::create([
            'table_name' => $tableName,
            'record_id' => $recordId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'user_id' => $userId ?: auth()->id(),
        ]);
    }
}