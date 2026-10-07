<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    // 1. Explicitly point to your custom table name just in case
    protected $table = 'platforms';

    // 2. Ensure all fields (including the missing description and brand color maps) can be read
    protected $fillable = [
        'name', 
        'type', 
        'hsl_color', 
        'is_active', 
        'description'
    ];

    // 3. Cast values correctly from native database tinyints to pure booleans
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the stories associated with this platform channel.
     */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class, 'platform_id');
    }

    /**
     * Reporters assigned to write for this platform.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}