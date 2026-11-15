<?php

namespace App\Filament\Resources\Products;

use App\Domain\Actions\RecordCounterSale;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Products\RelationManagers\StockByShopRelationManager;
use App\Filament\Support\InventoryForm;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStock;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('category_id')->relationship('category', 'name')->required()->searchable()->preload()
                ->createOptionForm([TextInput::make('name')->required(), TextInput::make('low_stock_threshold')->integer()->default(10)->required()]),
            TextInput::make('selling_price')->label('Selling price (Tier 2 & 3)')->prefix('RM')->numeric()->minValue(0)->required(),
            TextInput::make('purchase_cost')->label('Purchase cost (admin only)')->prefix('RM')->numeric()->minValue(0)
                ->helperText('Optional. Updated automatically from the latest stock-in.'),
            TextInput::make('opening_qty')->label('Opening quantity (Main Shop)')->integer()->minValue(0)->default(0)
                ->helperText('Recorded as a stock-in at the Main Shop, using the purchase cost above.')
                ->visibleOn('create'),
            TextInput::make('opening_note')->label('Supplier / dealer note (optional)')->maxLength(1000)
                ->visibleOn('create'),
            Toggle::make('is_active')->label('Active')->default(true)
                ->helperText('Inactive products are hidden from new stock actions but kept in history.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $mainId = fn () => Shop::main()->id;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category')
                ->withSum(['stock as main_qty' => fn ($q) => $q->where('shop_id', $mainId())], 'qty_main')
                ->withSum('stock as total_main', 'qty_main')
                ->withSum('stock as total_local', 'qty_local'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('category.name')->sortable(),
                TextColumn::make('selling_price')->label('Selling')->money('MYR', locale: 'ms_MY')->sortable(),
                TextColumn::make('purchase_cost')->label('Cost')->money('MYR', locale: 'ms_MY')->placeholder('—')->toggleable(),
                TextColumn::make('main_qty')->label('Main Shop')->numeric()->default(0)->sortable()
                    ->color(fn (Product $record) => ((int) $record->main_qty) <= $record->category->low_stock_threshold ? 'danger' : null),
                TextColumn::make('all_qty')->label('All shops')
                    ->state(fn (Product $record) => (int) $record->total_main + (int) $record->total_local),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')->relationship('category', 'name'),
                TernaryFilter::make('is_active')->label('Active')->default(true),
            ])
            ->recordUrl(fn (Product $record) => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label('History')->icon(Heroicon::OutlinedClock),
                Action::make('stockIn')->label('Stock In')->icon(Heroicon::OutlinedPlus)->color('success')->button()
                    ->modalHeading(fn (Product $record) => "Stock In — {$record->name}")
                    ->schema(fn (Product $record) => [
                        TextInput::make('qty')->integer()->minValue(1)->required(),
                        TextInput::make('unit_cost')->label('Unit purchase price (optional)')->prefix('RM')->numeric()->minValue(0)->default($record->purchase_cost),
                        InventoryForm::note('Supplier / dealer note'),
                        InventoryForm::date(),
                    ])
                    ->action(fn (Product $record, array $data, Action $action) => InventoryForm::run($action,
                        fn () => app(RecordStockIn::class)->handle([['product_id' => $record->id, 'qty' => $data['qty'], 'unit_cost' => $data['unit_cost'] ?? null]], $data['note'] ?? null, Carbon::parse($data['occurred_at'])),
                        'Stock added to Main Shop')),
                ActionGroup::make([
                    Action::make('transfer')->label('Transfer')->icon(Heroicon::OutlinedArrowsRightLeft)->color('info')
                        ->modalHeading(fn (Product $record) => "Transfer — {$record->name}")
                        ->schema(fn (Product $record) => [
                            Select::make('shop_id')->label('Target shop')->options(Shop::query()->branches()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Shop $s) => [$s->id => "{$s->name} ({$s->type->label()})"]))->required()->searchable(),
                            TextInput::make('qty')->integer()->minValue(1)->required()
                                ->helperText('Main Shop has '.(int) ShopStock::query()->where('shop_id', Shop::main()->id)->where('product_id', $record->id)->value('qty_main')),
                            TextInput::make('unit_price')->label('Unit price')->prefix('RM')->numeric()->minValue(0)->required()->default($record->selling_price),
                            InventoryForm::note(),
                            InventoryForm::date(),
                        ])
                        ->action(fn (Product $record, array $data, Action $action) => InventoryForm::run($action,
                            fn () => 'Transferred — invoice '.app(TransferStock::class)->handle(Shop::query()->findOrFail($data['shop_id']), [['product_id' => $record->id, 'qty' => $data['qty'], 'unit_price' => $data['unit_price']]], $data['note'] ?? null, Carbon::parse($data['occurred_at']))->number,
                            'Transferred')),
                    Action::make('counterSale')->label('Counter Sale')->icon(Heroicon::OutlinedMinus)->color('danger')
                        ->modalHeading(fn (Product $record) => "Counter Sale — {$record->name}")
                        ->schema(fn (Product $record) => [
                            TextInput::make('qty')->integer()->minValue(1)->required(),
                            TextInput::make('unit_price')->label('Unit price')->prefix('RM')->numeric()->minValue(0)->required()->default($record->selling_price),
                            InventoryForm::note(),
                            InventoryForm::date(),
                        ])
                        ->action(fn (Product $record, array $data, Action $action) => InventoryForm::run($action,
                            fn () => app(RecordCounterSale::class)->handle([['product_id' => $record->id, 'qty' => $data['qty'], 'unit_price' => $data['unit_price']]], $data['note'] ?? null, Carbon::parse($data['occurred_at'])),
                            'Counter sale recorded')),
                    EditAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [StockByShopRelationManager::class, MovementsRelationManager::class];
    }
}
