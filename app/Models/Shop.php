<?php

namespace App\Models;

use App\Domain\ShopFigures;
use App\Enums\ShopType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'type', 'address', 'phone', 'is_active'])]
class Shop extends Model
{
    use HasFactory;

    /** @var array<string, mixed> Mirrors the DB default so new models are active before reload. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['type' => ShopType::class, 'is_active' => 'boolean'];
    }

    public static function main(): self
    {
        return static::query()->where('type', ShopType::Main)->firstOrFail();
    }

    /** @param Builder<Shop> $query */
    #[Scope]
    protected function branches(Builder $query): void
    {
        $query->where('type', '!=', ShopType::Main);
    }

    /** A shop can only be deactivated once it holds no stock and owes nothing. */
    public function canBeDeactivated(): bool
    {
        return ShopFigures::units($this) === 0
            && (float) ShopFigures::balance($this)['unpaid'] === 0.0;
    }

    /** @return HasMany<ShopStock, $this> */
    public function stock(): HasMany
    {
        return $this->hasMany(ShopStock::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<StockTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }
}
