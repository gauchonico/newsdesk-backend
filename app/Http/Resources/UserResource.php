<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'active'            => (bool) $this->active,
            'reporter_kind'     => $this->reporter_kind,
            'preferences'       => (object) ($this->preferences ?? []),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            // Pluck the 'role' column from user_roles table (not Spatie's 'name')
            'roles' => $this->whenLoaded('roles', fn () =>
                $this->roles->pluck('role')->values()
            ),
            'platforms' => $this->whenLoaded('platforms', fn () =>
                $this->platforms->map(fn ($p) => [
                    'id'    => (int) $p->id,
                    'name'  => $p->name,
                    'type'  => $p->type,
                    'color' => $p->hsl_color ?? '142 60% 45%',
                ])->values()
            ),
            'created_at'        => $this->created_at->toISOString(),
            'stories_count' => $this->stories_count ?? 0,
            'updated_at'        => $this->updated_at->toISOString(),
        ];
    }
}