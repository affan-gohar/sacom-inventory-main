<?php

namespace App\Filament\Resources\StockTransactions\Pages;

use App\Domain\Actions\RecordCounterSale;
use App\Domain\Actions\RecordStockIn;
use App\Domain\Actions\TransferStock;
use App\Filament\Resources\StockTransactions\StockTransactionResource;
use App\Filament\Support\InventoryForm;
use App\Models\Shop;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ManageStockTransactions extends ManageRecords
{
    protected static string $resource = StockTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('stockIn')->label('Stock In (Supplier)')->icon(Heroicon::OutlinedPlus)->color('success')
                ->modalHeading('Stock In — Supplier to Main Shop')->modalWidth(Width::FourExtraLarge)
                ->schema([
                    InventoryForm::lines('cost', fn () => Shop::main()->id),
                    InventoryForm::total('unit_cost'),
                    InventoryForm::note('Supplier / dealer note (optional)'),
                    InventoryForm::date(),
                ])
                ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                    fn () => app(RecordStockIn::class)->handle($data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at'])),
                    'Stock added to Main Shop')),

            Action::make('transfer')->label('Transfer')->icon(Heroicon::OutlinedArrowsRightLeft)->color('info')
                ->modalHeading('Transfer — Main Shop to branch')->modalWidth(Width::FourExtraLarge)
                ->schema([
                    Select::make('shop_id')->label('Target shop')->required()->searchable()->live()
                        ->options(fn () => Shop::query()->branches()->where('is_active', true)->orderBy('name')->get()
                            ->mapWithKeys(fn (Shop $s) => [$s->id => "{$s->name} ({$s->type->label()})"])),
                    InventoryForm::lines('price', fn () => Shop::main()->id),
                    InventoryForm::total('unit_price'),
                    InventoryForm::note(),
                    InventoryForm::date(),
                ])
                ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                    fn () => 'Transferred — invoice '.app(TransferStock::class)->handle(Shop::query()->findOrFail($data['shop_id']), $data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at']))->number,
                    'Transferred')),

            Action::make('counterSale')->label('Counter Sale')->icon(Heroicon::OutlinedMinus)->color('danger')
                ->modalHeading('Direct counter sale — Main Shop')->modalWidth(Width::FourExtraLarge)
                ->schema([
                    InventoryForm::lines('price', fn () => Shop::main()->id),
                    InventoryForm::total('unit_price'),
                    InventoryForm::note(),
                    InventoryForm::date(),
                ])
                ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                    fn () => app(RecordCounterSale::class)->handle($data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at'])),
                    'Counter sale recorded')),
        ];
    }
}
