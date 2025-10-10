<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditTrailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_name' => $this->table_name,
            'record_id' => $this->record_id,
            'action' => $this->action,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'user_id' => $this->user_id,
            
            // Action badge
            'action_badge' => $this->getActionBadge(),
            
            // Human readable description
            'description' => $this->getDescription(),
            
            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function getActionBadge(): array
    {
        $badges = [
            'INSERT' => ['color' => 'success', 'text' => 'Created'],
            'UPDATE' => ['color' => 'info', 'text' => 'Updated'],
            'DELETE' => ['color' => 'danger', 'text' => 'Deleted'],
        ];

        return $badges[$this->action] ?? ['color' => 'light', 'text' => $this->action];
    }

    private function getDescription(): string
    {
        // Fix: Check if user relationship is actually loaded
        $userName = 'System'; // Default fallback
        
        if ($this->relationLoaded('user') && $this->user) {
            $userName = $this->user->full_name;
        }
        
        $actionText = [
            'INSERT' => 'created',
            'UPDATE' => 'updated',
            'DELETE' => 'deleted',
        ][$this->action] ?? 'modified';

        $table = str_replace('_', ' ', $this->table_name);
        $table = ucwords($table);
        
        return "{$userName} {$actionText} {$table} record #{$this->record_id}";
    }
}