<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Display a listing of audit logs
     */
    public function index(Request $request)
    {
        $this->authorize('view-audit-logs');

        $query = AuditLog::with('user');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        if ($request->has('entity')) {
            $query->where('entity', $request->entity);
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('created_at', [$request->date_from, $request->date_to]);
        }

        $auditLogs = $query->orderBy('created_at', 'desc')->paginate(20);

        return AuditLogResource::collection($auditLogs);
    }

    /**
     * Display the specified audit log
     */
    public function show(AuditLog $auditLog)
    {
        $this->authorize('view-audit-logs');

        return new AuditLogResource($auditLog->load('user'));
    }
}