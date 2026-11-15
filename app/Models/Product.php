<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category_id', 'name', 'selling_price', 'purchase_cost', 'is_active'])]
#[Hidden(['purchase_cost'])]
class Product extends Model
{
    use HasFactory;

    /** @var array<string, mixed> Mirrors the DB default so new models are active before reload. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'selling_price' => 'decimal:2',
            'purchase_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<ShopStock, $this> */
    public function stock(): HasMany
    {
        return $this->hasMany(ShopStock::class);
    }
}
