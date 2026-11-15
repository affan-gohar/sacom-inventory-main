<?php

namespace App\Filament\Support;

use App\Domain\Money;
use App\Enums\ShopType;
use App\Models\InvoiceLine;
use App\Models\Shop;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

/** The clearance form: every payable line across all of a shop's invoices, with a "Pay qty" box. */
final class PaymentForm
{
    /** Tier 2: sold but unpaid. Tier 3: anything unpaid (paying records the sale). */
    public static function payable(Shop $shop, InvoiceLine $line): int
    {
        return $shop->type === ShopType::Tier2 ? $line->qtySoldUnpaid() : $line->qtyUnpaid();
    }

    /** @return Collection<int, InvoiceLine> oldest first */
    public static function lines(Shop $shop): Collection
    {
        return InvoiceLine::query()
            ->select('invoice_lines.*')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.shop_id', $shop->id)
            ->whereRaw('invoice_lines.qty > invoice_lines.qty_paid + invoice_lines.qty_returned')
            ->with(['invoice:id,number,issued_at', 'product:id,name'])
            ->orderBy('invoices.issued_at')->orderBy('invoice_lines.id')
            ->get()
            ->filter(fn (InvoiceLine $l) => self::payable($shop, $l) > 0)
            ->values();
    }

    public static function repeater(Shop $shop): Repeater
    {
        $isTier2 = $shop->type === ShopType::Tier2;

        return Repeater::make('payments')
            ->hiddenLabel()
            ->default(fn () => self::lines($shop)->map(fn (InvoiceLine $l) => [
                'line_id' => $l->id,
                'invoice' => $l->invoice->number.' · '.$l->invoice->issued_at->format('d M Y'),
                'product' => $l->product->name,
                'price' => $l->unit_price,
                'status' => $isTier2
                    ? "{$l->qtySoldUnpaid()} sold & due · {$l->qtyInShop()} in shop · {$l->qty_paid} paid"
                    : "{$l->qtyUnpaid()} unpaid · {$l->qty_paid} paid".($l->qty_returned ? " · {$l->qty_returned} returned" : ''),
                'max' => self::payable($shop, $l),
                'pay_qty' => null,
            ])->all())
            ->schema([
                Hidden::make('line_id'),
                Hidden::make('max'),
                TextInput::make('invoice')->label('Invoice')->disabled()->dehydrated(false)->columnSpan(['default' => 2, 'md' => 2]),
                TextInput::make('product')->label('Product')->disabled()->dehydrated(false)->columnSpan(['default' => 2, 'md' => 2]),
                TextInput::make('price')->label('Price')->prefix('RM')->disabled()->dehydrated(false)->columnSpan(1),
                TextInput::make('status')->label('Status')->disabled()->dehydrated(false)->columnSpan(['default' => 2, 'md' => 2]),
                TextInput::make('pay_qty')->label(fn (Get $get) => 'Pay qty (max '.$get('max').')')
                    ->integer()->minValue(0)->maxValue(fn (Get $get) => (int) $get('max'))
                    ->live(onBlur: true)->columnSpan(1),
            ])
            ->columns(['default' => 2, 'md' => 8])
            ->addable(false)->deletable(false)->reorderable(false);
    }

    public static function total(): TextInput
    {
        return TextInput::make('payment_total')->label('Payment total')->prefix('RM')->disabled()->dehydrated(false)
            ->placeholder(fn (Get $get) => number_format(collect($get('payments') ?? [])
                ->sum(fn ($row) => ((int) ($row['pay_qty'] ?? 0)) * Money::toSen($row['price'] ?? 0)) / 100, 2));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int> invoice_line_id => qty
     */
    public static function toQtyByLine(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($r) => (int) ($r['pay_qty'] ?? 0) > 0)
            ->mapWithKeys(fn ($r) => [(int) $r['line_id'] => (int) $r['pay_qty']])
            ->all();
    }
}
