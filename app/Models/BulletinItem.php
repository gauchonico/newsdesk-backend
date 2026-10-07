<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulletinItem extends Model
{
    protected $fillable = [
        'bulletin_id', 'position', 'kind', 'story_id', 'story_version_id',
        'title', 'script', 'duration_seconds', 'auto_duration', 'notes',
    ];

    protected $casts = ['auto_duration' => 'boolean', 'duration_seconds' => 'integer', 'position' => 'integer'];

    public function bulletin(): BelongsTo
    {
        return $this->belongsTo(Bulletin::class);
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StoryVersion::class, 'story_version_id');
    }

    /** Read time of the script at broadcast pace */
    public static function secondsFor(?string $script): int
    {
        return (int) ceil(str_word_count((string) $script) / StoryVersion::READ_WPS);
    }
}
