<?php

namespace App\Filament\Widgets;

use App\Enums\ShopType;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ShopStock;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Products at or below their category threshold at the Main Shop and Tier 2 shops. */
class LowStockAlerts extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Low-stock alerts';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ShopStock::query()
                ->select('shop_stock.*')
                ->join('shops', 'shops.id', '=', 'shop_stock.shop_id')
                ->join('products', 'products.id', '=', 'shop_stock.product_id')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->where('shops.is_active', true)->where('products.is_active', true)
                ->whereIn('shops.type', [ShopType::Main->value, ShopType::Tier2->value])
                ->whereRaw('shop_stock.qty_main + shop_stock.qty_local <= categories.low_stock_threshold')
                ->with(['shop:id,name', 'product:id,name,category_id', 'product.category:id,low_stock_threshold']))
            ->columns([
                TextColumn::make('product.name')->label('Product')->weight('bold'),
                TextColumn::make('shop.name')->label('Shop'),
                TextColumn::make('total')->label('Left')->badge()->color('danger')->state(fn (ShopStock $r) => $r->total()),
                TextColumn::make('product.category.low_stock_threshold')->label('Alert at ≤'),
            ])
            ->defaultSort(fn ($query) => $query->orderByRaw('shop_stock.qty_main + shop_stock.qty_local'))
            ->recordUrl(fn (ShopStock $r) => ProductResource::getUrl('view', ['record' => $r->product_id]))
            ->emptyStateHeading('No low stock')->emptyStateDescription('Everything is above its category threshold.')
            ->paginated([5, 10, 25]);
    }
}
