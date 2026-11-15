<?php

namespace App\Filament\Shop\Resources\History;

use App\Enums\TransactionType;
use App\Filament\Resources\StockTransactions\StockTransactionResource;
use App\Filament\Shop\Resources\History\Pages\ListHistory;
use App\Filament\Shop\ShopScope;
use App\Models\StockTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Last six months of stock in/out and payments for the viewer's shop. */
class HistoryResource extends Resource
{
    protected static ?string $model = StockTransaction::class;

    protected static ?string $slug = 'history';

    protected static ?string $navigationLabel = 'History';

    protected static ?string $pluralModelLabel = 'History (last 6 months)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('shop_id', ShopScope::id())
            ->where('occurred_at', '>=', now()->subMonthsNoOverflow(6)->startOfDay())
            ->with(['movements.product:id,name', 'invoice:id,stock_transaction_id,number']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (TransactionType $state) => match ($state) {
                    TransactionType::Transfer => 'Received from Main',
                    TransactionType::BranchSale => 'Sold',
                    default => $state->label(),
                }),
                TextColumn::make('items')->wrap()->state(fn (StockTransaction $r) => StockTransactionResource::describeItems($r)),
                TextColumn::make('amount')->money('MYR', locale: 'ms_MY'),
                TextColumn::make('invoice.number')->label('Invoice')->placeholder('—'),
                TextColumn::make('note')->limit(40)->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([
                    TransactionType::Transfer->value => 'Received from Main',
                    TransactionType::LocalStockIn->value => 'Local stock-in',
                    TransactionType::BranchSale->value => 'Sold',
                    TransactionType::Return->value => 'Returned to Main',
                    TransactionType::Payment->value => 'Payment',
                ])->multiple(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListHistory::route('/')];
    }
}
