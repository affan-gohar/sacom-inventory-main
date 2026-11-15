<?php

namespace App\Filament\Resources\Shops\RelationManagers;

use App\Domain\Money;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lines'))
            ->columns([
                TextColumn::make('number')->searchable()->weight('bold'),
                TextColumn::make('issued_at')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('amount')->label('Amount')->money('MYR', locale: 'ms_MY')
                    ->state(fn (Invoice $r) => $r->lines->sum(fn ($l) => $l->qty * (float) $l->unit_price)),
                TextColumn::make('unpaid')->label('Unpaid')->money('MYR', locale: 'ms_MY')->color('danger')
                    ->state(fn (Invoice $r) => Money::fromSen($r->lines->sum(fn ($l) => $l->qtyUnpaid() * Money::toSen($l->unit_price)))),
                TextColumn::make('status')->badge()
                    ->state(fn (Invoice $r) => $r->status())
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
}
