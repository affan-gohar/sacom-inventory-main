<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Domain\Money;
use App\Enums\ShopType;
use App\Filament\Resources\Shops\ShopResource;
use App\Models\ShopStock;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Where this product is right now, shop by shop. */
class StockByShopRelationManager extends RelationManager
{
    protected static string $relationship = 'stock';

    protected static ?string $title = 'Where it is now';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $productId = $this->getOwnerRecord()->id;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('shop')->whereRaw('qty_main + qty_local > 0'))
            ->columns([
                TextColumn::make('shop.name')->label('Shop')->weight('bold'),
                TextColumn::make('shop.type')->label('Tier')->badge()
                    ->formatStateUsing(fn (ShopType $state) => match ($state) {
                        ShopType::Main => 'Main', ShopType::Tier2 => 'Tier 2', ShopType::Tier3 => 'Tier 3'
                    })
                    ->color(fn (ShopType $state) => match ($state) {
                        ShopType::Main => 'gray', ShopType::Tier2 => 'info', ShopType::Tier3 => 'warning'
                    }),
                TextColumn::make('total')->label('Units now')->weight('bold')->state(fn (ShopStock $r) => $r->total()),
                TextColumn::make('qty_main')->label('From Main'),
                TextColumn::make('qty_local')->label('Local'),
                TextColumn::make('unpaid')->label('Unpaid')->money('MYR', locale: 'ms_MY')->color('danger')->placeholder('—')
                    ->state(fn (ShopStock $r) => $r->shop->type->isBranch() ? Money::fromSen(Money::toSen(DB::table('invoice_lines')
                        ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                        ->whereNull('invoices.deleted_at')->where('invoices.shop_id', $r->shop_id)->where('invoice_lines.product_id', $productId)
                        ->sum(DB::raw('(qty - qty_returned - qty_paid) * unit_price')))) : null),
            ])
            ->recordUrl(fn (ShopStock $r) => $r->shop->type->isBranch() ? ShopResource::getUrl('view', ['record' => $r->shop_id]) : null)
            ->defaultSort('shop_id')
            ->paginated(false);
    }
}
