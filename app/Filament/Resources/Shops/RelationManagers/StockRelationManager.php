<?php

namespace App\Filament\Resources\Shops\RelationManagers;

use App\Models\ShopStock;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockRelationManager extends RelationManager
{
    protected static string $relationship = 'stock';

    protected static ?string $title = 'Stock';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $alerts = $this->getOwnerRecord()->type->hasStockAlerts();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product.category')->whereRaw('qty_main + qty_local > 0'))
            ->columns([
                TextColumn::make('product.name')->searchable()->sortable(),
                TextColumn::make('product.category.name')->label('Category'),
                TextColumn::make('total')->label('In stock')->weight('bold')
                    ->state(fn (ShopStock $r) => $r->total())
                    ->color(fn (ShopStock $r) => $alerts && $r->total() <= $r->product->category->low_stock_threshold ? 'danger' : null),
                TextColumn::make('qty_main')->label('From Main'),
                TextColumn::make('qty_local')->label('Local'),
                TextColumn::make('product.selling_price')->label('Unit price')->money('MYR', locale: 'ms_MY'),
                TextColumn::make('value')->label('Value')->money('MYR', locale: 'ms_MY')
                    ->state(fn (ShopStock $r) => $r->total() * (float) $r->product->selling_price),
            ])
            ->defaultSort('product_id');
    }
}
