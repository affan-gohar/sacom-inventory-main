<?php

namespace App\Filament\Shop\Widgets;

use App\Domain\ShopFigures;
use App\Enums\ShopType;
use App\Filament\Shop\ShopScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShopOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $shop = ShopScope::shop();
        $balance = ShopFigures::balance($shop);
        $rm = fn (string $v) => 'RM '.number_format((float) $v, 2);

        return [
            Stat::make('Pending balance', $rm($balance['unpaid']))
                ->description($shop->type === ShopType::Tier2
                    ? 'Due now (sold): '.$rm($balance['sold_unpaid'])
                    : 'Unpaid credit — cleared at month-end')
                ->color('danger'),
            Stat::make('Total paid', $rm($balance['paid']))->color('success'),
            Stat::make('Units in stock', number_format(ShopFigures::units($shop))),
            Stat::make('Stock value', $rm(ShopFigures::stockValue($shop))),
        ];
    }
}
