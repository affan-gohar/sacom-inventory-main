<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Domain\ShopFigures;
use App\Enums\ShopType;
use App\Models\Shop;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->branches())
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('code')->label('Store ID')->searchable(),
                TextColumn::make('type')->label('Tier')->badge()->sortable()
                    ->formatStateUsing(fn (ShopType $state) => $state === ShopType::Tier2 ? 'Tier 2' : 'Tier 3')
                    ->color(fn (ShopType $state) => $state === ShopType::Tier2 ? 'info' : 'warning'),
                TextColumn::make('units')->label('Units')->state(fn (Shop $r) => ShopFigures::units($r)),
                TextColumn::make('value')->label('Stock value')->money('MYR', locale: 'ms_MY')->state(fn (Shop $r) => ShopFigures::stockValue($r)),
                TextColumn::make('unpaid')->label('Unpaid')->money('MYR', locale: 'ms_MY')->color('danger')
                    ->state(fn (Shop $r) => ShopFigures::balance($r)['unpaid']),
                TextColumn::make('alerts')->label('Low stock')->badge()->color('danger')
                    ->state(fn (Shop $r) => ShopFigures::lowStockCount($r) ?: null)->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')->label('Tier')->options([
                    ShopType::Tier2->value => 'Tier 2', ShopType::Tier3->value => 'Tier 3',
                ]),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
