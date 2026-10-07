<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    /**
     * Auto-generate a clean slug when saving new dynamic tags.
     */
    protected static function booted()
    {
        static::creating(function ($tag) {
            $tag->slug = Str::slug($tag->name);
        });
    }

    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class);
    }
}