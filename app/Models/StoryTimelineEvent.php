<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryTimelineEvent extends Model
{
    protected $fillable = ['story_id', 'actor_id', 'action', 'note'];

    public function story() {
        return $this->belongsTo(Story::class);
    }

    public function actor() {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
