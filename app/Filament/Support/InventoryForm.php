<?php

namespace App\Filament\Support;

use App\Domain\InventoryException;
use App\Domain\Money;
use App\Models\Product;
use App\Models\ShopStock;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** Form pieces shared by every stock action. */
final class InventoryForm
{
    /**
     * Repeater of product lines.
     *
     * @param  'price'|'cost'|null  $money  extra money column to show
     * @param  Closure(): ?int|null  $stockShopId  shop whose stock to show as a hint
     */
    public static function lines(?string $money = null, ?Closure $stockShopId = null, bool $fixedProduct = false): Repeater
    {
        $fields = [
            Select::make('product_id')
                ->label('Product')
                ->options(fn () => Product::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->disabled($fixedProduct)
                ->dehydrated()
                ->afterStateUpdated(function (?string $state, Set $set) use ($money) {
                    if ($money === 'price' && $state) {
                        $set('unit_price', Product::query()->find($state)?->selling_price);
                    }
                    if ($money === 'cost' && $state) {
                        $set('unit_cost', Product::query()->find($state)?->purchase_cost);
                    }
                })
                ->helperText(function (Get $get) use ($stockShopId) {
                    $shopId = $stockShopId ? $stockShopId() : null;
                    if (! $shopId || ! $get('product_id')) {
                        return null;
                    }
                    $row = ShopStock::query()->where('shop_id', $shopId)->where('product_id', $get('product_id'))->first();

                    return 'In stock: '.($row ? $row->qty_main + $row->qty_local : 0)
                        .($row && $row->qty_local > 0 ? " ({$row->qty_main} from Main, {$row->qty_local} local)" : '');
                })
                ->columnSpan(['default' => 2, 'md' => $money ? 2 : 3]),
            TextInput::make('qty')
                ->label('Qty')
                ->integer()
                ->minValue(1)
                ->required()
                ->live(onBlur: true)
                ->columnSpan(1),
        ];

        if ($money === 'price') {
            $fields[] = TextInput::make('unit_price')->label('Unit price (RM)')->numeric()->minValue(0)->required()->live(onBlur: true)->columnSpan(1);
        }
        if ($money === 'cost') {
            $fields[] = TextInput::make('unit_cost')->label('Unit cost (RM, optional)')->numeric()->minValue(0)->live(onBlur: true)->columnSpan(1);
        }

        return Repeater::make('lines')
            ->label('Products')
            ->schema($fields)
            ->columns(['default' => 3, 'md' => 4])
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Add another product')
            ->reorderable(false)
            ->addable(! $fixedProduct)
            ->deletable(! $fixedProduct);
    }

    /** Live total of qty × price/cost for the lines repeater. */
    public static function total(string $field): TextInput
    {
        return TextInput::make('total_preview')
            ->label('Auto-calculated total')
            ->disabled()
            ->dehydrated(false)
            ->prefix('RM')
            ->placeholder(fn (Get $get) => number_format(collect($get('lines') ?? [])
                ->sum(fn ($l) => ((int) ($l['qty'] ?? 0)) * Money::toSen($l[$field] ?? 0)) / 100, 2));
    }

    public static function note(string $label = 'Note (optional)'): Textarea
    {
        return Textarea::make('note')->label($label)->rows(2)->maxLength(1000);
    }

    public static function date(): DateTimePicker
    {
        return DateTimePicker::make('occurred_at')
            ->label('Date')
            ->default(now())
            ->maxDate(now()->endOfDay())
            ->seconds(false)
            ->required();
    }

    /**
     * Run a domain action from a Filament action. Business-rule refusals become a red
     * notification and the modal stays open with the admin's input intact.
     */
    public static function run(Action $action, Closure $callback, string $success): void
    {
        try {
            $result = $callback();
        } catch (InventoryException $e) {
            Notification::make()->title('Not saved')->body($e->getMessage())->danger()->persistent()->send();
            $action->halt();

            return;
        }

        Notification::make()->title(is_string($result) ? $result : $success)->success()->send();
    }
}
