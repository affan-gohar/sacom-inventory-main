<?php

namespace App\Domain;

use App\Enums\StockSource;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStock;
use App\Models\StockMovement;
use App\Models\StockTransaction;

/**
 * The only code that changes shop_stock. Every change writes a stock_movements row in the same
 * DB transaction, so the ledger always sums to the running totals. Callers must already be inside
 * DB::transaction().
 */
class Ledger
{
    /** Lock and return the stock row for a shop/product, creating it at zero if missing. */
    public static function lock(int $shopId, int $productId): ShopStock
    {
        ShopStock::query()->insertOrIgnore([
            'shop_id' => $shopId,
            'product_id' => $productId,
            'qty_main' => 0,
            'qty_local' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ShopStock::query()
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Apply a signed quantity change to one bucket, refusing to go below zero. */
    public static function move(
        StockTransaction $txn,
        int $shopId,
        int $productId,
        StockSource $source,
        int $qty,
        ?string $unitPrice = null,
        ?string $unitCost = null,
    ): StockMovement {
        if ($qty === 0) {
            throw new InventoryException('Quantity must not be zero.');
        }

        $row = self::lock($shopId, $productId);
        $column = $source === StockSource::Main ? 'qty_main' : 'qty_local';
        $new = $row->{$column} + $qty;

        if ($new < 0) {
            $product = Product::query()->find($productId)?->name ?? "#{$productId}";
            $shop = Shop::query()->find($shopId)?->name ?? "#{$shopId}";
            throw new InventoryException("Only {$row->{$column}} of {$product} available at {$shop}.");
        }

        $row->{$column} = $new;
        $row->save();

        return $txn->movements()->create([
            'shop_id' => $shopId,
            'product_id' => $productId,
            'qty' => $qty,
            'source' => $source,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'occurred_at' => $txn->occurred_at,
        ]);
    }
}
