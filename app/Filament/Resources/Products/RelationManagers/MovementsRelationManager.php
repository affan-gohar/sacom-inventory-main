<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\TransactionType;
use App\Models\StockMovement;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every stock movement of this product. "Stock after" is the running balance at that shop, computed
 * over the full ledger before any filter is applied, so it stays correct when filtered.
 */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->fromSub(DB::table('stock_movements')->select('stock_movements.*')
                    ->selectRaw('SUM(qty) OVER (PARTITION BY shop_id, product_id ORDER BY occurred_at, id) AS stock_after'), 'stock_movements')
                ->with(['shop:id,name', 'transaction' => fn ($q) => $q->with('invoice:id,stock_transaction_id,number')]))
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('action')->badge()
                    ->state(fn (StockMovement $r) => self::describe($r))
                    ->color(fn (StockMovement $r) => $r->qty > 0 ? 'success' : 'danger'),
                TextColumn::make('shop.name')->label('Shop'),
                TextColumn::make('qty')->label('In / Out')->weight('bold')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('stock_after')->label('Stock after (at that shop)'),
                TextColumn::make('unit_price')->label('Price')->money('MYR', locale: 'ms_MY')->placeholder('—')->toggleable(),
                TextColumn::make('transaction.invoice.number')->label('Invoice')->placeholder('—'),
                TextColumn::make('transaction.note')->label('Note')->limit(40)->placeholder('—'),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('shop')->relationship('shop', 'name')->searchable()->preload(),
                SelectFilter::make('type')->label('Action')->multiple()
                    ->options(collect(TransactionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? null,
                        fn ($q, $types) => $q->whereHas('transaction', fn ($t) => $t->whereIn('type', $types)))),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->default(now()->subMonthsNoOverflow(6)->toDateString()),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => ($data['from'] ?? null) ? 'From '.$data['from'] : null),
            ]);
    }

    /** Plain-language description of one movement, e.g. "Transferred to Zen Shop". */
    private static function describe(StockMovement $m): string
    {
        $type = $m->transaction?->type;
        $in = $m->qty > 0;

        return match ($type) {
            TransactionType::StockIn => 'Stock in (supplier)',
            TransactionType::LocalStockIn => 'Local stock-in',
            TransactionType::Transfer => $in ? 'Received from Main' : 'Transferred out',
            TransactionType::CounterSale => 'Counter sale',
            TransactionType::BranchSale => 'Sold at branch',
            TransactionType::Return => $in ? 'Returned in' : 'Returned to Main',
            TransactionType::Payment => 'Paid & sold (Tier 3)',
            default => '—',
        };
    }
}
