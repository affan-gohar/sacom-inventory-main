<?php

namespace App\Domain;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/** Money and stock totals for a shop, computed in SQL. */
final class ShopFigures
{
    /**
     * @return array{in_shop: string, sold_unpaid: string, paid: string, unpaid: string}
     */
    public static function balance(Shop $shop): array
    {
        $row = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.shop_id', $shop->id)
            ->selectRaw('COALESCE(SUM((qty - qty_sold - qty_returned) * unit_price), 0) AS in_shop')
            ->selectRaw('COALESCE(SUM((qty_sold - qty_paid) * unit_price), 0) AS sold_unpaid')
            ->selectRaw('COALESCE(SUM(qty_paid * unit_price), 0) AS paid')
            ->first();

        $inShop = Money::toSen($row->in_shop);
        $soldUnpaid = Money::toSen($row->sold_unpaid);

        return [
            'in_shop' => Money::fromSen($inShop),
            'sold_unpaid' => Money::fromSen($soldUnpaid),
            'paid' => Money::fromSen(Money::toSen($row->paid)),
            'unpaid' => Money::fromSen($inShop + $soldUnpaid),
        ];
    }

    /** Units in the shop (Main-supplied + local). */
    public static function units(Shop $shop): int
    {
        return (int) DB::table('shop_stock')->where('shop_id', $shop->id)->sum(DB::raw('qty_main + qty_local'));
    }

    /**
     * Stock value. Branches at selling price; the Main Shop at purchase cost, falling back to
     * selling price where no cost is recorded.
     */
    public static function stockValue(Shop $shop): string
    {
        $priceExpr = $shop->type->isBranch() ? 'products.selling_price' : 'COALESCE(products.purchase_cost, products.selling_price)';

        $value = DB::table('shop_stock')
            ->join('products', 'products.id', '=', 'shop_stock.product_id')
            ->where('shop_stock.shop_id', $shop->id)
            ->sum(DB::raw("(shop_stock.qty_main + shop_stock.qty_local) * {$priceExpr}"));

        return Money::fromSen(Money::toSen($value));
    }

    /** Products at or below their category's threshold (Main and Tier 2 only). */
    public static function lowStockCount(Shop $shop): int
    {
        if (! $shop->type->hasStockAlerts()) {
            return 0;
        }

        return Product::query()
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->join('shop_stock', fn ($j) => $j->on('shop_stock.product_id', '=', 'products.id')->where('shop_stock.shop_id', $shop->id))
            ->where('products.is_active', true)
            ->whereRaw('shop_stock.qty_main + shop_stock.qty_local <= categories.low_stock_threshold')
            ->count();
    }
}
