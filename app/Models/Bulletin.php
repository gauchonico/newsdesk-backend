<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bulletin extends Model
{
    protected $fillable = ['title', 'platform_id', 'format', 'air_at', 'slot_seconds', 'status', 'created_by'];

    protected $casts = ['air_at' => 'datetime', 'slot_seconds' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(BulletinItem::class)->orderBy('position');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Admins: everything. Others: bulletins for their platforms, or ones they made without a platform. */
    public function isAccessibleBy(User $user): bool
    {
        return $this->platform_id ? $user->canWriteFor($this->platform_id) : $this->created_by === $user->id || $user->isAdmin();
    }
}
