<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'headline' => $this->title, // Bridges Laravel 'title' to Next.js 'headline'
            'content' => $this->content,
            'summary' => $this->summary,
            'platform' => $this->platform ? strtoupper($this->platform->name) : 'NTV', 
            'status' => $this->status, // pending, approved, held, live
            'category' => $this->category,
            'tags' => $this->whenLoaded('tags', fn () =>
                $this->tags->pluck('name')->values()
            ),
            'author' => $this->user ? $this->user->name : 'Unknown Reporter',
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
            'isCommunity' => $this->is_community,
            'mediaUrl' => $this->media_url,
            'mediaType' => $this->media_type,
            
            // Transforms your complex database relational timeline into the linear array your frontend uses
            'timeline' => $this->timeline->map(function ($event) {
                return [
                    'action' => $event->action, // matching your front-end schema actions
                    'by' => $event->actor ? $event->actor->name : 'System',
                    'at' => $event->created_at->toISOString(),
                    'note' => $event->note,
                ];
            }),
        ];
    }
}