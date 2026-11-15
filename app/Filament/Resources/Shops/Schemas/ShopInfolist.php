<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Domain\ShopFigures;
use App\Enums\ShopType;
use App\Models\Shop;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShopInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(['default' => 2, 'md' => 3, 'xl' => 6])->columnSpanFull()->schema([
                TextEntry::make('type')->label('Tier')->badge()
                    ->formatStateUsing(fn (ShopType $state) => $state->label())
                    ->color(fn (ShopType $state) => $state === ShopType::Tier2 ? 'info' : 'warning'),
                TextEntry::make('code')->label('Store ID'),
                TextEntry::make('units')->label('Units in stock')->state(fn (Shop $record) => ShopFigures::units($record)),
                TextEntry::make('value')->label('Stock value')->money('MYR', locale: 'ms_MY')->state(fn (Shop $record) => ShopFigures::stockValue($record)),
                TextEntry::make('unpaid')->label('Unpaid balance')->money('MYR', locale: 'ms_MY')->color('danger')->weight('bold')
                    ->state(fn (Shop $record) => ShopFigures::balance($record)['unpaid'])
                    ->helperText(fn (Shop $record) => $record->type === ShopType::Tier2
                        ? 'Due now: RM '.number_format((float) ShopFigures::balance($record)['sold_unpaid'], 2)
                        : null),
                TextEntry::make('paid')->label('Total paid')->money('MYR', locale: 'ms_MY')->color('success')
                    ->state(fn (Shop $record) => ShopFigures::balance($record)['paid']),
            ]),
        ]);
    }
}
