<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryRevision extends Model
{
    protected $fillable = ['story_id', 'user_id', 'title', 'summary', 'content'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
