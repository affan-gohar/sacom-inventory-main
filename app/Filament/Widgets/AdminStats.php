<?php

namespace App\Filament\Widgets;

use App\Domain\Money;
use App\Domain\ShopFigures;
use App\Enums\ShopType;
use App\Enums\TransactionType;
use App\Models\Shop;
use App\Models\StockTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected function getStats(): array
    {
        $rm = fn (int $sen) => 'RM '.number_format($sen / 100, 2);
        $main = Shop::main();
        $branches = Shop::query()->branches()->where('is_active', true)->get();

        $branchUnits = $branches->sum(fn (Shop $s) => ShopFigures::units($s));
        $branchValue = $branches->sum(fn (Shop $s) => Money::toSen(ShopFigures::stockValue($s)));
        $balances = $branches->map(fn (Shop $s) => ['type' => $s->type] + ShopFigures::balance($s));
        $t2Due = $balances->where('type', ShopType::Tier2)->sum(fn ($b) => Money::toSen($b['sold_unpaid']));
        $t2InShop = $balances->where('type', ShopType::Tier2)->sum(fn ($b) => Money::toSen($b['in_shop']));
        $t3Unpaid = $balances->where('type', ShopType::Tier3)->sum(fn ($b) => Money::toSen($b['unpaid']));
        $alerts = Shop::query()->where('is_active', true)->get()->sum(fn (Shop $s) => ShopFigures::lowStockCount($s));

        $monthStart = now()->startOfMonth();
        $sum = fn (TransactionType $t) => Money::toSen((string) StockTransaction::query()->where('type', $t)->where('occurred_at', '>=', $monthStart)->sum('amount'));
        $collected = $sum(TransactionType::Payment);
        $counter = $sum(TransactionType::CounterSale);

        return [
            Stat::make('Main Shop stock', number_format(ShopFigures::units($main)).' units')
                ->description('Value '.$rm(Money::toSen(ShopFigures::stockValue($main))))->color('primary')
                ->icon('heroicon-o-building-storefront'),
            Stat::make('Stock at branches', number_format($branchUnits).' units')
                ->description('Value '.$rm($branchValue).' · '.$branches->count().' shops')->color('info')
                ->icon('heroicon-o-truck'),
            Stat::make('Tier 2 due now', $rm($t2Due))
                ->description('Sold, not yet paid · '.$rm($t2InShop).' still in shops')->color('warning')
                ->icon('heroicon-o-clock'),
            Stat::make('Tier 3 unpaid credit', $rm($t3Unpaid))
                ->description('Cleared at month-end')->color('danger')
                ->icon('heroicon-o-credit-card'),
            Stat::make('Low-stock alerts', (string) $alerts)
                ->description($alerts ? 'Main Shop & Tier 2 — see list below' : 'All stock above thresholds')
                ->color($alerts ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make('This month', $rm($collected + $counter))
                ->description('Collected '.$rm($collected).' · Counter '.$rm($counter))->color('success')
                ->icon('heroicon-o-banknotes'),
        ];
    }
}
