<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Domain\Actions\ChangeShopTier;
use App\Domain\Actions\RecordBranchSale;
use App\Domain\Actions\RecordLocalStockIn;
use App\Domain\Actions\RecordPayment;
use App\Domain\Actions\ReturnToMain;
use App\Domain\Actions\TransferStock;
use App\Enums\ShopType;
use App\Filament\Resources\Shops\ShopResource;
use App\Filament\Support\InventoryForm;
use App\Filament\Support\PaymentForm;
use App\Models\Shop;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/** @property Shop $record */
class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        $shop = fn (): Shop => $this->getRecord();
        $after = fn () => $this->refreshFormData([]);

        return [
            Action::make('payment')->label('Record payment')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->modalHeading(fn () => "Record payment — {$shop()->name}")->modalWidth(Width::SevenExtraLarge)
                ->modalDescription(fn () => $shop()->type === ShopType::Tier2
                    ? 'Enter how many units of each line were paid for. Only units already recorded as sold can be paid.'
                    : 'Enter how many units of each line were paid for. Paid units are recorded as sold; the rest stay unpaid in the shop.')
                ->modalSubmitActionLabel('Confirm payment')
                ->schema(fn () => PaymentForm::lines($shop())->isEmpty()
                    ? [Placeholder::make('none')->hiddenLabel()->content('Nothing is waiting to be paid.')]
                    : [
                        PaymentForm::repeater($shop()),
                        PaymentForm::total(),
                        InventoryForm::note('Note (optional — e.g. cash, bank transfer ref)'),
                        InventoryForm::date(),
                    ])
                ->action(function (array $data, Action $action) use ($shop) {
                    if (! isset($data['payments'])) {
                        return;
                    }
                    InventoryForm::run($action,
                        fn () => 'Payment recorded — RM '.number_format((float) app(RecordPayment::class)->handle($shop(), PaymentForm::toQtyByLine($data['payments']), $data['note'] ?? null, Carbon::parse($data['occurred_at']))->amount, 2),
                        'Payment recorded');
                })
                ->after($after),

            Action::make('transfer')->label('Transfer stock here')->icon(Heroicon::OutlinedArrowsRightLeft)->color('info')
                ->modalHeading(fn () => "Transfer to {$shop()->name}")->modalWidth(Width::FourExtraLarge)
                ->schema([
                    InventoryForm::lines('price', fn () => Shop::main()->id),
                    InventoryForm::total('unit_price'),
                    InventoryForm::note(),
                    InventoryForm::date(),
                ])
                ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                    fn () => 'Transferred — invoice '.app(TransferStock::class)->handle($shop(), $data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at']))->number,
                    'Transferred'))
                ->after($after),

            Action::make('branchSale')->label('Record sale')->icon(Heroicon::OutlinedShoppingCart)->color('danger')
                ->visible(fn () => $shop()->type === ShopType::Tier2)
                ->modalHeading(fn () => "Items sold at {$shop()->name}")->modalWidth(Width::FourExtraLarge)
                ->modalDescription('Stock from the Main Shop is used first (oldest invoice first) and becomes due. Any extra comes from local stock.')
                ->schema([
                    InventoryForm::lines(null, fn () => $shop()->id),
                    InventoryForm::note(),
                    InventoryForm::date(),
                ])
                ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                    fn () => app(RecordBranchSale::class)->handle($shop(), $data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at'])),
                    'Sale recorded'))
                ->after($after),

            ActionGroup::make([
                Action::make('localStockIn')->label('Local stock-in (skip Main)')->icon(Heroicon::OutlinedPlus)->color('success')
                    ->modalHeading(fn () => "Local stock-in — {$shop()->name}")->modalWidth(Width::FourExtraLarge)
                    ->modalDescription('Bought by the branch from a local supplier. Not invoiced and not owed to the Main Shop.')
                    ->schema([
                        InventoryForm::lines('cost', fn () => $shop()->id, freeText: true),
                        InventoryForm::total('unit_cost'),
                        InventoryForm::note('Dealer name / note (optional)'),
                        InventoryForm::date(),
                    ])
                    ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                        fn () => app(RecordLocalStockIn::class)->handle($shop(), $data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at'])),
                        'Local stock added'))
                    ->after($after),

                Action::make('return')->label('Return to Main Shop')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                    ->modalHeading(fn () => "Return from {$shop()->name} to Main Shop")->modalWidth(Width::FourExtraLarge)
                    ->modalDescription('Only stock supplied by the Main Shop can be returned. The oldest invoice lines are credited first.')
                    ->schema([
                        InventoryForm::lines(null, fn () => $shop()->id),
                        InventoryForm::note('Reason (unsold, defective, overstock…)'),
                        InventoryForm::date(),
                    ])
                    ->action(fn (array $data, Action $action) => InventoryForm::run($action,
                        fn () => 'Returned — RM '.number_format((float) app(ReturnToMain::class)->handle($shop(), $data['lines'], $data['note'] ?? null, Carbon::parse($data['occurred_at']))->amount, 2).' credited',
                        'Returned'))
                    ->after($after),

                Action::make('changeTier')->icon(Heroicon::OutlinedArrowPath)
                    ->label(fn () => $shop()->type === ShopType::Tier2 ? 'Convert to Tier 3' : 'Convert to Tier 2')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => $shop()->type === ShopType::Tier2
                        ? 'Switches to monthly credit with itemised clearance and no stock alerts. Units already sold stay due.'
                        : 'Enables stock alerts. Unsold units become "in shop, not yet due" — only future sales become due.')
                    ->action(fn (Action $action) => InventoryForm::run($action,
                        fn () => 'Now '.app(ChangeShopTier::class)->handle($shop(), $shop()->type === ShopType::Tier2 ? ShopType::Tier3 : ShopType::Tier2)->type->label(),
                        'Tier changed'))
                    ->after($after),

                EditAction::make(),
            ])->label('More')->button()->icon(Heroicon::OutlinedEllipsisVertical),
        ];
    }
}
