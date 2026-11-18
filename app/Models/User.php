<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'password', 'role', 'shop_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> Mirrors the DB default so new models are active before reload. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Other shops whose stock this branch login may view (read-only), set by the admin per login.
     *
     * @return BelongsToMany<Shop, $this>
     */
    public function viewableShops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'user_shop_access')->withTimestamps();
    }

    /**
     * Active shops this login may view, never including its own shop.
     *
     * @return Collection<int, Shop>
     */
    public function visibleOtherShops(): Collection
    {
        return $this->viewableShops()
            ->where('shops.is_active', true)
            ->when($this->shop_id, fn ($q) => $q->where('shops.id', '!=', $this->shop_id))
            ->orderByRaw("CASE WHEN shops.type = 'main' THEN 0 ELSE 1 END")
            ->orderBy('shops.name')
            ->get(['shops.id', 'shops.name', 'shops.type']);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Admins use /admin only; viewers use /shop only, and only while they and their shop are active. */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            'shop' => $this->role === UserRole::Viewer
                && $this->shop !== null
                && $this->shop->is_active
                && $this->shop->type->isBranch(),
            default => false,
        };
    }
}
