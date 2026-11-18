<?php

namespace App\Filament\Shop\Widgets;

use App\Filament\Shop\Resources\OtherShopsStock\OtherShopsStockResource;
use App\Models\ShopStock;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** One card per shop the admin lets this login view; clicking a card opens that shop's searchable stock. */
class OtherShopsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return OtherShopsStockResource::allowedShopIds() !== [];
    }

    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $shops = $user->visibleOtherShops();
        $units = ShopStock::query()->whereIn('shop_id', $shops->pluck('id'))
            ->groupBy('shop_id')->select('shop_id', DB::raw('SUM(qty_main + qty_local) AS units'))
            ->pluck('units', 'shop_id');

        return $shops->map(fn ($shop) => Stat::make("{$shop->name} — Total units", number_format((int) ($units[$shop->id] ?? 0)))
            ->description('Click to search this shop\'s stock')
            ->descriptionIcon('heroicon-m-magnifying-glass')
            ->color('primary')
            ->url(OtherShopsStockResource::getUrl('index', ['shop' => $shop->id])))
            ->all();
    }
}
