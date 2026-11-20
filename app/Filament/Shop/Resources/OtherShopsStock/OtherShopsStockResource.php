<?php

namespace App\Filament\Shop\Resources\OtherShopsStock;

use App\Filament\Shop\Resources\OtherShopsStock\Pages\ListOtherShopsStock;
use App\Models\Product;
use App\Models\ShopStock;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only stock of the other shops the admin allowed this login to see (product name and quantity
 * only — never prices or costs). Always scoped to the allowed shops, whatever filter is applied.
 */
class OtherShopsStockResource extends Resource
{
    protected static ?string $model = ShopStock::class;

    protected static ?string $slug = 'other-shops-stock';

    protected static ?string $navigationLabel = 'Other Shops Stock';

    protected static ?string $pluralModelLabel = 'Other Shops Stock';

    protected static ?string $modelLabel = 'stock line';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 4;

    /** @return list<int> */
    public static function allowedShopIds(): array
    {
        $user = auth()->user();

        return $user instanceof User ? $user->visibleOtherShops()->pluck('id')->all() : [];
    }

    /** Always in the sidebar (below History); what it shows is limited to the shops the admin ticked. */
    public static function canAccess(): bool
    {
        return true;
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('shop_id', self::allowedShopIds())
            ->whereRaw('qty_main + qty_local > 0')
            ->with('product:id,name', 'shop:id,name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable()->sortable()->weight('bold'),
                TextColumn::make('shop.name')->label('Shop'),
                TextColumn::make('available')->label('Available')->weight('bold')->color('primary')
                    ->state(fn (ShopStock $record) => $record->total()),
            ])
            ->filters([
                SelectFilter::make('shop')->label('Shop')
                    ->options(fn () => auth()->user()->visibleOtherShops()->pluck('name', 'id')),
            ])
            ->modifyQueryUsing(fn (Builder $query, $livewire) => $query
                ->when($livewire->shop ?? null, fn (Builder $query, $id) => $query->where('shop_id', (int) $id)))
            ->defaultSort(fn (Builder $query) => $query
                ->orderBy(Product::query()->select('name')->whereColumn('products.id', 'shop_stock.product_id'))
                ->orderBy('shop_id'))
            ->searchPlaceholder('Search product (e.g. Wireless Mouse)')
            ->emptyStateHeading(fn () => self::allowedShopIds() === [] ? 'No shops shared with you yet' : 'No stock found')
            ->emptyStateDescription(fn () => self::allowedShopIds() === []
                ? 'Ask the admin to tick the shops you may view under your login (Shops → your shop → Logins → Edit).'
                : 'Try another product name.')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOtherShopsStock::route('/')];
    }
}
