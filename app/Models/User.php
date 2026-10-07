<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'active',
        'reporter_kind',
        'preferences',
        'email_verified_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'active'            => 'boolean',
        'password'          => 'hashed',
        'preferences'       => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function roles()
    {
        return $this->hasMany(\App\Models\UserRole::class);
    }

    public function stories()
    {
        return $this->hasMany(Story::class, 'user_id');
    }

    /**
     * Platforms this user writes for (e.g. NTV, KFM).
     */
    public function assignedStories()
    {
        return $this->hasMany(Assignment::class, 'assignee_id');
    }

    public function platforms()
    {
        return $this->belongsToMany(Platform::class)->withTimestamps();
    }

    // ── Role helpers ──────────────────────────────────────────────────────────

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('role', $roles)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Admins and editors can approve / publish stories (editors only on their own desks).
     */
    public function isEditorial(): bool
    {
        return $this->hasAnyRole(['admin', 'editor']);
    }

    /**
     * Admins cover every platform; editors and reporters only the platforms (desks) assigned to them.
     */
    public function canWriteFor(int $platformId): bool
    {
        return $this->isAdmin()
            || $this->platforms()->where('platforms.id', $platformId)->exists();
    }

    /**
     * Can review (approve / send back / publish) stories on this platform.
     */
    public function canReview(int $platformId): bool
    {
        return $this->isEditorial() && $this->canWriteFor($platformId);
    }

    /**
     * IDs of the platforms this user can see, or null for "all" (admins).
     */
    public function visiblePlatformIds(): ?array
    {
        return $this->isAdmin() ? null : $this->platforms()->pluck('platforms.id')->all();
    }

    /**
     * Replace all roles for this user with the given list.
     * Mirrors Spatie's syncRoles() so the controller stays the same.
     */
    public function syncRoles(array $roles): void
    {
        $this->roles()->delete();

        foreach ($roles as $role) {
            $this->roles()->create(['role' => $role]);
        }
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Filter by a single role: User::query()->filterRole('reporter')
     * Named filterRole to avoid clashing with HasRoles if Spatie is ever added.
     */
    public function scopeFilterRole($query, ?string $role)
    {
        if (! $role) {
            return $query;
        }
        return $query->whereHas('roles', function ($q) use ($role) {
            $q->where('role', $role);
        });
    }

    /**
     * Search name or email.
     */
    public function scopeSearch($query, ?string $term)
    {
        if (! $term) return $query;

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%");
        });
    }
}