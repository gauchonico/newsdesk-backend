<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of story (Breaking News, Normal, …) with the review-time target editors work against.
 */
class StoryType extends Model
{
    protected $fillable = ['name', 'review_minutes', 'is_default', 'sort_order'];

    protected $casts = [
        'review_minutes' => 'integer',
        'is_default'     => 'boolean',
        'sort_order'     => 'integer',
    ];

    public static function defaultType(): ?self
    {
        return static::where('is_default', true)->first() ?? static::orderBy('sort_order')->first();
    }
}
