<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only, append-only business history. */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Audit Log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'shop', 'product']))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y, H:i:s')->sortable(),
                TextColumn::make('action')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => str_starts_with($state, 'deleted') ? 'danger' : 'gray')
                    ->searchable(),
                TextColumn::make('shop.name')->label('Shop')->placeholder('—'),
                TextColumn::make('product.name')->label('Product')->placeholder('—'),
                TextColumn::make('qty')->placeholder('—'),
                TextColumn::make('reference')->label('Ref')
                    ->state(fn (AuditLog $r) => $r->subject_type ? "{$r->subject_type} #{$r->subject_id}" : null)->placeholder('—'),
                TextColumn::make('details')->label('Details')->wrap()->limit(80)
                    ->state(fn (AuditLog $r) => collect($r->after ?? $r->before ?? [])->except(['movements', 'allocations'])
                        ->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) || $v === null ? $v : json_encode($v)))->implode(' · ') ?: null)
                    ->placeholder('—'),
                TextColumn::make('note')->wrap()->limit(60)->placeholder('—'),
                TextColumn::make('user.name')->label('User')->placeholder('System'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('action')->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action'))->multiple(),
                SelectFilter::make('shop')->relationship('shop', 'name')->searchable()->preload(),
                SelectFilter::make('product')->relationship('product', 'name')->searchable()->preload(),
                Filter::make('dates')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('occurred_at', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
