<?php

namespace App\Filament\Shop\Resources\Invoices;

use App\Domain\Money;
use App\Filament\Shop\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Shop\ShopScope;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('shop_id', ShopScope::id())->with('lines');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Invoice')->searchable()->weight('bold'),
                TextColumn::make('issued_at')->label('Date')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('amount')->money('MYR', locale: 'ms_MY')
                    ->state(fn (Invoice $r) => Money::fromSen($r->lines->sum(fn ($l) => $l->qty * Money::toSen($l->unit_price)))),
                TextColumn::make('unpaid')->money('MYR', locale: 'ms_MY')->color('danger')
                    ->state(fn (Invoice $r) => Money::fromSen($r->lines->sum(fn ($l) => $l->qtyUnpaid() * Money::toSen($l->unit_price)))),
                TextColumn::make('status')->badge()->state(fn (Invoice $r) => $r->status())
                    ->color(fn (string $state) => match ($state) {
                        'Paid' => 'success', 'Partially paid' => 'warning', default => 'danger'
                    }),
            ])
            ->defaultSort('issued_at', 'desc')
            ->recordUrl(fn (Invoice $record) => route('invoices.pdf', $record))
            ->openRecordUrlInNewTab()
            ->recordActions([
                Action::make('print')->label('View / Print')->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Invoice $record) => route('invoices.pdf', $record), shouldOpenInNewTab: true),
                Action::make('download')->label('Download PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->url(fn (Invoice $record) => route('invoices.pdf', [$record, 'download' => 1])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
