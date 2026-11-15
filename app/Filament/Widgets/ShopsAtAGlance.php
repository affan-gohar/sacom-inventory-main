<?php

namespace App\Filament\Widgets;

use App\Domain\ShopFigures;
use App\Enums\ShopType;
use App\Filament\Resources\Shops\ShopResource;
use App\Models\Shop;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ShopsAtAGlance extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Shops at a glance';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Shop::query()->branches()->where('is_active', true))
            ->columns([
                TextColumn::make('name')->label('Shop')->weight('bold')->searchable(),
                TextColumn::make('type')->label('Tier')->badge()
                    ->formatStateUsing(fn (ShopType $state) => $state === ShopType::Tier2 ? 'Tier 2' : 'Tier 3')
                    ->color(fn (ShopType $state) => $state === ShopType::Tier2 ? 'info' : 'warning'),
                TextColumn::make('units')->label('Units')->state(fn (Shop $r) => ShopFigures::units($r)),
                TextColumn::make('value')->label('Stock value')->money('MYR', locale: 'ms_MY')->state(fn (Shop $r) => ShopFigures::stockValue($r)),
                TextColumn::make('due')->label('Due now')->money('MYR', locale: 'ms_MY')->color('warning')
                    ->state(fn (Shop $r) => $r->type === ShopType::Tier2 ? ShopFigures::balance($r)['sold_unpaid'] : ShopFigures::balance($r)['unpaid']),
                TextColumn::make('unpaid')->label('Total unpaid')->money('MYR', locale: 'ms_MY')->color('danger')->weight('bold')
                    ->state(fn (Shop $r) => ShopFigures::balance($r)['unpaid']),
                TextColumn::make('paid')->label('Paid to date')->money('MYR', locale: 'ms_MY')->color('success')
                    ->state(fn (Shop $r) => ShopFigures::balance($r)['paid']),
                TextColumn::make('alerts')->label('Low stock')->badge()->color('danger')
                    ->state(fn (Shop $r) => ShopFigures::lowStockCount($r) ?: null)->placeholder('—'),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Shop $r) => ShopResource::getUrl('view', ['record' => $r]))
            ->paginated([10, 25, 50]);
    }
}
