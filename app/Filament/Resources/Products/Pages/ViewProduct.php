<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStock;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/** @property Product $record */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function infolist(Schema $schema): Schema
    {
        $qty = fn (bool $main) => (int) ShopStock::query()->where('product_id', $this->getRecord()->id)
            ->whereIn('shop_id', $main ? [Shop::main()->id] : Shop::query()->branches()->pluck('id'))
            ->sum(DB::raw('qty_main + qty_local'));

        return $schema->components([
            Section::make()->columns(['default' => 2, 'md' => 3, 'xl' => 6])->columnSpanFull()->schema([
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('selling_price')->label('Selling price')->money('MYR', locale: 'ms_MY'),
                TextEntry::make('purchase_cost')->label('Purchase cost')->money('MYR', locale: 'ms_MY')->placeholder('—'),
                TextEntry::make('main')->label('At Main Shop')->state(fn () => $qty(true))->weight('bold'),
                TextEntry::make('branches')->label('At branches')->state(fn () => $qty(false))->weight('bold'),
                TextEntry::make('total')->label('Total units')->state(fn () => $qty(true) + $qty(false))->weight('bold')->color('primary'),
            ]),
        ]);
    }
}
