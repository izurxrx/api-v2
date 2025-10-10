<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'full_name' => $this->full_name,
            'initials' => collect(explode(' ', $this->full_name))
                ->map(fn($word) => strtoupper(substr($word, 0, 1)))
                ->join(''),
            'contact_no' => $this->contact_no,
            'role' => $this->role,
            'role_label' => ucfirst(str_replace('_', ' ', $this->role)),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->diffForHumans(),
        ];
    }
}