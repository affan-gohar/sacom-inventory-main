<?php

namespace App\Filament\Shop\Resources\Stock;

use App\Filament\Shop\Resources\Stock\Pages\ListStock;
use App\Filament\Shop\ShopScope;
use App\Models\ShopStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockResource extends Resource
{
    protected static ?string $model = ShopStock::class;

    protected static ?string $slug = 'stock';

    protected static ?string $navigationLabel = 'Current Stock';

    protected static ?string $pluralModelLabel = 'Current Stock';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('shop_id', ShopScope::id())
            ->whereRaw('qty_main + qty_local > 0')
            ->with('product:id,name,category_id,selling_price', 'product.category:id,name');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('product.category.name')->label('Category'),
                TextColumn::make('total')->label('Qty')->weight('bold')->state(fn (ShopStock $r) => $r->total()),
                TextColumn::make('qty_main')->label('From Main Shop'),
                TextColumn::make('qty_local')->label('Local'),
                TextColumn::make('product.selling_price')->label('Unit price')->money('MYR', locale: 'ms_MY'),
                TextColumn::make('value')->label('Value')->money('MYR', locale: 'ms_MY')
                    ->state(fn (ShopStock $r) => $r->total() * (float) $r->product->selling_price),
            ])
            ->defaultSort('product_id');
    }

    public static function getPages(): array
    {
        return ['index' => ListStock::route('/')];
    }
}
