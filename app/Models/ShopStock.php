<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopStock extends Model
{
    protected $table = 'shop_stock';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty_main' => 'integer', 'qty_local' => 'integer'];
    }

    public function total(): int
    {
        return $this->qty_main + $this->qty_local;
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
