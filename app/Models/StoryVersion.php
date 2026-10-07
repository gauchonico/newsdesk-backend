<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform/format adaptation of a story (e.g. the KFM radio reader of an NTV story).
 */
class StoryVersion extends Model
{
    /** Words per second used for broadcast read times (150 words per minute) */
    public const READ_WPS = 2.5;

    public const FORMATS = ['web', 'tv', 'radio', 'social'];

    protected $fillable = [
        'story_id', 'platform_id', 'format', 'headline', 'intro', 'body',
        'status', 'review_note', 'created_by', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Text an anchor or presenter reads aloud */
    public function spokenText(): string
    {
        return trim(($this->intro ? $this->intro . "\n\n" : '') . self::plain($this->body));
    }

    public function wordCount(): int
    {
        return str_word_count(self::plain(($this->intro ?? '') . ' ' . $this->body));
    }

    public function readSeconds(): int
    {
        return (int) ceil($this->wordCount() / self::READ_WPS);
    }

    public static function plain(string $html): string
    {
        $text = preg_replace('/<\/(p|h\d|li|blockquote)>/i', "\n\n", $html);
        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($text))));
    }
}
