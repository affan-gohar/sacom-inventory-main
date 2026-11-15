<?php

namespace App\Filament\Resources\StockTransactions;

use App\Domain\Actions\DeleteTransaction;
use App\Enums\TransactionType;
use App\Filament\Resources\StockTransactions\Pages\ManageStockTransactions;
use App\Filament\Support\InventoryForm;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockTransactionResource extends Resource
{
    protected static ?string $model = StockTransaction::class;

    protected static ?string $modelLabel = 'stock activity';

    protected static ?string $pluralModelLabel = 'Stock Activity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shop', 'movements.product', 'invoice', 'creator']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('occurred_at')->label('Date')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (TransactionType $state) => $state->label())
                    ->color(fn (TransactionType $state) => match ($state) {
                        TransactionType::StockIn, TransactionType::LocalStockIn => 'success',
                        TransactionType::Transfer => 'info',
                        TransactionType::CounterSale, TransactionType::BranchSale => 'danger',
                        TransactionType::Return => 'warning',
                        TransactionType::Payment => 'primary',
                    }),
                TextColumn::make('shop.name')->label('Shop')->searchable(),
                TextColumn::make('items')->label('Items')->wrap()
                    ->state(fn (StockTransaction $record) => self::describeItems($record)),
                TextColumn::make('amount')->money('MYR', locale: 'ms_MY')->sortable(),
                TextColumn::make('invoice.number')->label('Invoice')->placeholder('—')->searchable(),
                TextColumn::make('note')->limit(40)->tooltip(fn (StockTransaction $r) => $r->note)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(collect(TransactionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))->multiple(),
                SelectFilter::make('shop')->relationship('shop', 'name')->searchable()->preload(),
                Filter::make('dates')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))),
            ])
            ->recordActions([
                Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading(fn (StockTransaction $r) => "Delete {$r->type->label()} #{$r->id}?")
                    ->modalDescription('Its effect on stock and balances will be undone. The audit log keeps a record.')
                    ->schema([Textarea::make('reason')->label('Reason (optional)')->rows(2)->maxLength(1000)])
                    ->action(fn (StockTransaction $record, array $data, Action $action) => InventoryForm::run($action,
                        fn () => app(DeleteTransaction::class)->handle($record, $data['reason'] ?? null), 'Deleted')),
            ]);
    }

    /** "Wireless Mouse × 10, Privacy Glass × 3" (signed quantities collapsed to the shop acted on). */
    public static function describeItems(StockTransaction $record): string
    {
        return $record->movements
            ->where('shop_id', $record->shop_id)
            ->groupBy('product_id')
            ->map(fn ($group) => $group->first()->product->name.' × '.abs($group->sum(fn (StockMovement $m) => $m->qty)))
            ->implode(', ') ?: '—';
    }

    public static function getPages(): array
    {
        return ['index' => ManageStockTransactions::route('/')];
    }
}
